<?php
/**
 * Checking a sign-in: Apple and Google ID tokens, and emailed 6-digit codes.
 * Settings live in Groupcade > Settings > Host sign-in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Auth {

	// ------------------------------------------------------------------ Apple

	/** Our Apple client IDs: the app's bundle ID and the website's Services ID. */
	private static function apple_audiences() {
		return array_values( array_filter( array( GC_Accounts::apple_bundle(), GC_Accounts::opt( 'apple_services' ) ) ) );
	}

	/**
	 * @param string $id_token From Sign in with Apple.
	 * @param string $code     The authorization code that came with it (used to get a token we can revoke on delete).
	 * @param string $nonce    The raw nonce the app sent to Apple, if any.
	 * @return array|WP_Error  [subject, email, data]
	 */
	public static function apple( $id_token, $code = '', $nonce = '' ) {
		$c = GC_JWT::verify( $id_token, GC_Accounts::APPLE_KEYS, array( GC_Accounts::APPLE_ISS ), self::apple_audiences() );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( '' !== $nonce && isset( $c['nonce'] ) && ! hash_equals( (string) $c['nonce'], hash( 'sha256', $nonce ) ) && ! hash_equals( (string) $c['nonce'], $nonce ) ) {
			return new WP_Error( 'badtoken', 'That sign-in did not work. Try again.', array( 'status' => 401 ) );
		}
		$email  = ( ! empty( $c['email'] ) && ( true === $c['email_verified'] || 'true' === ( $c['email_verified'] ?? '' ) ) ) ? (string) $c['email'] : '';
		$client = is_array( $c['aud'] ) ? (string) reset( $c['aud'] ) : (string) $c['aud'];
		$data   = null;
		if ( '' !== $code ) {
			$refresh = self::apple_exchange( $code, $client );
			if ( $refresh ) {
				$data = array( 'refresh' => $refresh, 'client' => $client );
			}
		}
		return array( (string) $c['sub'], $email, $data );
	}

	/** Apple's client secret: a short-lived JWT signed with the Sign in with Apple key. Null when not set up. */
	private static function apple_secret( $client ) {
		$team = GC_Accounts::opt( 'apple_team' );
		$kid  = GC_Accounts::opt( 'apple_key_id' );
		$key  = (string) get_option( 'gc_apple_key', '' );
		if ( '' === $team || '' === $kid || '' === trim( $key ) || '' === $client ) {
			return null;
		}
		return GC_JWT::sign_es256(
			array(
				'iss' => $team,
				'iat' => time(),
				'exp' => time() + 300,
				'aud' => GC_Accounts::APPLE_ISS,
				'sub' => $client,
			),
			$kid,
			$key
		);
	}

	private static function apple_exchange( $code, $client ) {
		$secret = self::apple_secret( $client );
		if ( ! $secret ) {
			return '';
		}
		$res = wp_remote_post(
			'https://appleid.apple.com/auth/token',
			array(
				'timeout' => 8,
				'body'    => array(
					'client_id'     => $client,
					'client_secret' => $secret,
					'code'          => $code,
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return '';
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $j ) && ! empty( $j['refresh_token'] ) ? (string) $j['refresh_token'] : '';
	}

	public static function apple_revoke( $refresh, $client ) {
		$secret = self::apple_secret( $client );
		if ( ! $secret ) {
			return false;
		}
		$res = wp_remote_post(
			'https://appleid.apple.com/auth/revoke',
			array(
				'timeout' => 8,
				'body'    => array(
					'client_id'       => $client,
					'client_secret'   => $secret,
					'token'           => $refresh,
					'token_type_hint' => 'refresh_token',
				),
			)
		);
		return ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
	}

	// ------------------------------------------------------------------ Google

	/** @return array|WP_Error [subject, email, name] */
	public static function google( $id_token ) {
		$aud = array_values( array_filter( array( GC_Accounts::opt( 'google_web' ), GC_Accounts::opt( 'google_ios' ), GC_Accounts::opt( 'google_android' ) ) ) );
		$c   = GC_JWT::verify( $id_token, GC_Accounts::GOOGLE_KEYS, array( 'https://accounts.google.com', 'accounts.google.com' ), $aud );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$email = ( ! empty( $c['email'] ) && ! empty( $c['email_verified'] ) ) ? (string) $c['email'] : '';
		return array( (string) $c['sub'], $email, (string) ( $c['given_name'] ?? ( $c['name'] ?? '' ) ) );
	}

	// ------------------------------------------------------------------ email codes

	private static function email_hash( $email ) {
		return GC_Room::hash( 'email:' . strtolower( $email ) );
	}

	/** Email a 6-digit code. */
	public static function email_start( $raw ) {
		global $wpdb;
		$email = strtolower( sanitize_email( (string) $raw ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email', 'That email address doesn\'t look right.', array( 'status' => 400 ) );
		}
		if ( ! GC_Limits::hit( 'mail', 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'slow', 'Too many codes from this network. Try again in a bit.', array( 'status' => 429 ) );
		}
		$t    = GC_Accounts::codes();
		$eh   = self::email_hash( $email );
		$sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE email_hash = %s AND created > %d", $eh, time() - HOUR_IN_SECONDS ) ); // phpcs:ignore
		if ( $sent >= 5 ) {
			return new WP_Error( 'slow', 'We\'ve sent a few codes to that address. Check your inbox, or try again in an hour.', array( 'status' => 429 ) );
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$wpdb->delete( $t, array( 'email_hash' => $eh ) ); // phpcs:ignore -- one live code per address.
		$wpdb->insert( // phpcs:ignore
			$t,
			array(
				'email_hash' => $eh,
				'code_hash'  => GC_Room::hash( 'code:' . $code ),
				'expires'    => time() + GC_Accounts::CODE_MINUTES * MINUTE_IN_SECONDS,
				'tries'      => 0,
				'created'    => time(),
			)
		);
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = "Your Groupcade sign-in code is:\n\n    $code\n\nIt works for " . GC_Accounts::CODE_MINUTES . " minutes. If you didn't ask for it, you can ignore this email.\n\nGroupcade\n" . home_url( '/' );
		$ok   = wp_mail( $email, $code . ' is your Groupcade code', $body, array( 'From: Groupcade <' . self::from_address() . '>' ) );
		do_action( 'gc_email_code', $email, $code ); // For tests.
		if ( ! $ok ) {
			return new WP_Error( 'mail', 'We couldn\'t send the email. Try again, or sign in with Apple or Google.', array( 'status' => 500 ) );
		}
		return true;
	}

	private static function from_address() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', (string) $host );
		return 'hello@' . ( $host ? $host : 'groupcade.com' );
	}

	/** @return string|WP_Error The verified address. */
	public static function email_verify( $raw, $code ) {
		global $wpdb;
		$email = strtolower( sanitize_email( (string) $raw ) );
		$code  = preg_replace( '/\D/', '', (string) $code );
		$t     = GC_Accounts::codes();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE email_hash = %s ORDER BY id DESC LIMIT 1", self::email_hash( $email ) ), ARRAY_A ); // phpcs:ignore
		if ( ! $row || (int) $row['expires'] < time() ) {
			return new WP_Error( 'expired', 'That code has expired. Ask for a new one.', array( 'status' => 400 ) );
		}
		if ( (int) $row['tries'] >= GC_Accounts::CODE_TRIES ) {
			return new WP_Error( 'tries', 'Too many tries. Ask for a new code.', array( 'status' => 429 ) );
		}
		if ( 6 !== strlen( $code ) || ! hash_equals( (string) $row['code_hash'], GC_Room::hash( 'code:' . $code ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET tries = tries + 1 WHERE id = %d", (int) $row['id'] ) ); // phpcs:ignore
			return new WP_Error( 'badcode', 'That code isn\'t right. Check the email and try again.', array( 'status' => 400 ) );
		}
		$wpdb->delete( $t, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore
		return $email;
	}
}
