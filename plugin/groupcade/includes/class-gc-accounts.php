<?php
/**
 * Optional host accounts. Hosts sign in with Apple, Google or an emailed 6-digit code; players
 * never sign in. A host is a WordPress user with the gc_host role (no wp-admin access, no
 * WordPress password or login cookie). The plugin hands out its own session token instead:
 * the app sends it as the X-Groupcade-Session header, the website keeps it in the gc_session
 * cookie (http-only).
 *
 * Accounts are never looked up on the phones' room polls (GC_Fast). A room learns its owner once,
 * when it is made (or when a signed-in host claims it).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Accounts {

	const ROLE         = 'gc_host';
	const COOKIE       = 'gc_session';
	const SESSION_DAYS = 90;
	const CODE_MINUTES = 10;
	const CODE_TRIES   = 5;

	const APPLE_ISS  = 'https://appleid.apple.com';
	const APPLE_KEYS = 'https://appleid.apple.com/auth/keys';
	const GOOGLE_KEYS = 'https://www.googleapis.com/oauth2/v3/certs';

	/** @var array|null|false The signed-in host for this request (false = not looked up yet). */
	private static $me = false;

	// ------------------------------------------------------------------ tables

	public static function identities() {
		global $wpdb;
		return $wpdb->prefix . 'gc_identities';
	}

	public static function sessions() {
		global $wpdb;
		return $wpdb->prefix . 'gc_sessions';
	}

	public static function codes() {
		global $wpdb;
		return $wpdb->prefix . 'gc_email_codes';
	}

	public static function entitlements() {
		global $wpdb;
		return $wpdb->prefix . 'gc_entitlements';
	}

	public static function install( $c ) {
		$i = self::identities();
		$s = self::sessions();
		$e = self::codes();
		$n = self::entitlements();
		dbDelta(
			"CREATE TABLE $i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			provider varchar(16) NOT NULL,
			subject varchar(191) NOT NULL,
			email varchar(191) NOT NULL DEFAULT '',
			data text NULL,
			created int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_subject (provider,subject),
			KEY user_id (user_id)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE $s (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			tok_hash char(64) NOT NULL,
			device varchar(60) NOT NULL DEFAULT '',
			created int(10) unsigned NOT NULL DEFAULT 0,
			last_seen int(10) unsigned NOT NULL DEFAULT 0,
			expires int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY tok_hash (tok_hash),
			KEY user_id (user_id)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE $e (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email_hash char(64) NOT NULL,
			code_hash char(64) NOT NULL,
			expires int(10) unsigned NOT NULL DEFAULT 0,
			tries tinyint(3) unsigned NOT NULL DEFAULT 0,
			created int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY email_hash (email_hash)
			) $c;"
		);
		// What plan a host is on. Everyone who signs up before paid plans launch gets 'founding'.
		dbDelta(
			"CREATE TABLE $n (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			plan varchar(20) NOT NULL DEFAULT 'free',
			source varchar(20) NOT NULL DEFAULT '',
			starts int(10) unsigned NOT NULL DEFAULT 0,
			ends int(10) unsigned NOT NULL DEFAULT 0,
			ext_id varchar(191) NOT NULL DEFAULT '',
			created int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
			) $c;"
		);
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Groupcade host', array() );
		}
	}

	/** Hourly: drop expired sessions and codes. */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::sessions() . ' WHERE expires < %d', time() ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::codes() . ' WHERE expires < %d', time() - HOUR_IN_SECONDS ) ); // phpcs:ignore
	}

	// ------------------------------------------------------------------ settings

	public static function opt( $name ) {
		return trim( (string) get_option( 'gc_' . $name, '' ) );
	}

	public static function apple_bundle() {
		$b = self::opt( 'apple_bundle' );
		return '' !== $b ? $b : 'com.groupcade.app';
	}

	/** What the app and the account page need to show the sign-in buttons. No secrets. */
	public static function client_config() {
		return array(
			'apple'  => array(
				'web'    => self::opt( 'apple_services' ),
				'bundle' => self::apple_bundle(),
			),
			'google' => array(
				'web' => self::opt( 'google_web' ),
				'ios' => self::opt( 'google_ios' ),
			),
			'email'  => true,
		);
	}

	// ------------------------------------------------------------------ sessions

	/** The session token on this request: the app's header, or the website's cookie. */
	public static function request_token() {
		$h = isset( $_SERVER['HTTP_X_GROUPCADE_SESSION'] ) ? (string) $_SERVER['HTTP_X_GROUPCADE_SESSION'] : ''; // phpcs:ignore
		if ( '' === $h && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$h = (string) $_COOKIE[ self::COOKIE ]; // phpcs:ignore
		}
		return preg_match( '/^[a-f0-9]{64}$/', $h ) ? $h : '';
	}

	/** The signed-in host's user id, or 0. */
	public static function current_id() {
		$me = self::current();
		return $me ? (int) $me['user_id'] : 0;
	}

	/** @return array|null Session row of the signed-in host. */
	public static function current() {
		if ( false !== self::$me ) {
			return self::$me;
		}
		global $wpdb;
		self::$me = null;
		$tok      = self::request_token();
		if ( '' === $tok ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sessions() . ' WHERE tok_hash = %s', GC_Room::hash( $tok ) ), ARRAY_A ); // phpcs:ignore
		if ( ! $row || (int) $row['expires'] < time() || ! get_userdata( (int) $row['user_id'] ) ) {
			return null;
		}
		// Sliding expiry, written at most once a day.
		if ( time() - (int) $row['last_seen'] > DAY_IN_SECONDS ) {
			$wpdb->update( self::sessions(), array( 'last_seen' => time(), 'expires' => time() + self::SESSION_DAYS * DAY_IN_SECONDS ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore
		}
		self::$me = $row;
		return $row;
	}

	/** For tests and after sign-out: forget the cached signed-in host. */
	public static function forget() {
		self::$me = false;
	}

	public static function start_session( $user_id, $device ) {
		global $wpdb;
		$tok = bin2hex( random_bytes( 32 ) );
		$wpdb->insert( // phpcs:ignore
			self::sessions(),
			array(
				'user_id'   => (int) $user_id,
				'tok_hash'  => GC_Room::hash( $tok ),
				'device'    => mb_substr( sanitize_text_field( (string) $device ), 0, 60 ),
				'created'   => time(),
				'last_seen' => time(),
				'expires'   => time() + self::SESSION_DAYS * DAY_IN_SECONDS,
			)
		);
		return $tok;
	}

	public static function end_session( $all = false ) {
		global $wpdb;
		$me = self::current();
		if ( ! $me ) {
			return;
		}
		if ( $all ) {
			$wpdb->delete( self::sessions(), array( 'user_id' => (int) $me['user_id'] ) ); // phpcs:ignore
		} else {
			$wpdb->delete( self::sessions(), array( 'id' => (int) $me['id'] ) ); // phpcs:ignore
		}
		self::forget();
	}

	public static function set_cookie( $tok ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			(string) $tok,
			array(
				'expires'  => $tok ? time() + self::SESSION_DAYS * DAY_IN_SECONDS : time() - YEAR_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	// ------------------------------------------------------------------ users

	public static function is_host( $user_id ) {
		$u = get_userdata( (int) $user_id );
		return $u && in_array( self::ROLE, (array) $u->roles, true );
	}

	/**
	 * Find or make the host for a verified sign-in.
	 *
	 * @param string $provider apple | google | email
	 * @param string $subject  The provider's stable user id (for email: the address).
	 * @param string $email    Verified email, or ''.
	 * @param string $name     A display name to start with, or ''.
	 * @return int|WP_Error User id.
	 */
	public static function sign_in( $provider, $subject, $email, $name, $data = null ) {
		global $wpdb;
		$t     = self::identities();
		$email = strtolower( sanitize_email( (string) $email ) );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE provider = %s AND subject = %s", $provider, (string) $subject ), ARRAY_A ); // phpcs:ignore
		if ( $row && get_userdata( (int) $row['user_id'] ) ) {
			$upd = array();
			if ( $email && $email !== $row['email'] ) {
				$upd['email'] = $email;
			}
			if ( null !== $data ) {
				$upd['data'] = wp_json_encode( $data );
			}
			if ( $upd ) {
				$wpdb->update( $t, $upd, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore
			}
			return (int) $row['user_id'];
		}
		if ( $row ) {
			$wpdb->delete( $t, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore -- its user is gone.
		}
		// Same verified email as an existing host (another sign-in method): add to that account.
		// Apple's private relay addresses never match, which is fine.
		$uid = $email ? self::host_by_email( $email ) : 0;
		if ( ! $uid ) {
			$uid = self::create_user( $email, $name );
			if ( is_wp_error( $uid ) ) {
				return $uid;
			}
		}
		$wpdb->insert( // phpcs:ignore
			$t,
			array(
				'user_id'  => $uid,
				'provider' => $provider,
				'subject'  => (string) $subject,
				'email'    => $email,
				'data'     => null !== $data ? wp_json_encode( $data ) : null,
				'created'  => time(),
			)
		);
		return $uid;
	}

	private static function host_by_email( $email ) {
		global $wpdb;
		$t   = self::identities();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $t WHERE email = %s ORDER BY id", $email ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			if ( self::is_host( $id ) ) {
				return (int) $id;
			}
		}
		$u = get_user_by( 'email', $email );
		return ( $u && self::is_host( $u->ID ) ) ? (int) $u->ID : 0;
	}

	private static function create_user( $email, $name ) {
		$name = self::clean_name( $name );
		if ( '' === $name && $email && false === strpos( $email, 'privaterelay.appleid.com' ) ) {
			$name = self::clean_name( ucfirst( strtok( $email, '@' ) ) );
		}
		if ( '' === $name ) {
			$name = 'Host';
		}
		// The WordPress email must be unique. A staff account may already use it: keep it in meta then.
		$wp_email = ( $email && ! email_exists( $email ) ) ? $email : '';
		$uid      = wp_insert_user(
			array(
				'user_login'   => 'host_' . strtolower( wp_generate_password( 10, false ) ),
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $wp_email,
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => self::ROLE,
			)
		);
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'nouser', 'Could not make your account. Try again.', array( 'status' => 500 ) );
		}
		update_user_meta( $uid, 'gc_email', $email );
		update_user_meta( $uid, 'gc_news', '0' );
		update_user_meta( $uid, 'show_admin_bar_front', 'false' );
		// Founding members: everyone who signs up before paid plans launch (Groupcade > Settings).
		if ( ! get_option( 'gc_paid_launch' ) ) {
			self::grant( $uid, 'founding', 'signup' );
		}
		return (int) $uid;
	}

	public static function grant( $uid, $plan, $source, $ends = 0, $ext = '' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore
			self::entitlements(),
			array(
				'user_id' => (int) $uid,
				'plan'    => $plan,
				'source'  => $source,
				'starts'  => time(),
				'ends'    => (int) $ends,
				'ext_id'  => (string) $ext,
				'created' => time(),
			)
		);
	}

	/** The host's current plan: the newest entitlement that hasn't ended, else 'free'. */
	public static function plan( $uid ) {
		global $wpdb;
		$p = $wpdb->get_var( $wpdb->prepare( 'SELECT plan FROM ' . self::entitlements() . ' WHERE user_id = %d AND starts <= %d AND ( ends = 0 OR ends > %d ) ORDER BY id DESC LIMIT 1', (int) $uid, time(), time() ) ); // phpcs:ignore
		return $p ? (string) $p : 'free';
	}

	public static function clean_name( $raw ) {
		$n = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $raw ) ) );
		return mb_substr( $n, 0, 40 );
	}

	/** What the app and the account page see about the signed-in host. */
	public static function profile( $uid ) {
		global $wpdb;
		$u = get_userdata( (int) $uid );
		if ( ! $u ) {
			return null;
		}
		$ways = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT provider FROM ' . self::identities() . ' WHERE user_id = %d', (int) $uid ) ); // phpcs:ignore
		$mail = (string) get_user_meta( $uid, 'gc_email', true );
		return array(
			'id'       => (int) $uid,
			'name'     => $u->display_name,
			'email'    => $mail,
			'relay'    => false !== strpos( $mail, 'privaterelay.appleid.com' ),
			'news'     => '1' === get_user_meta( $uid, 'gc_news', true ),
			'via'      => array_values( $ways ),
			'plan'     => self::plan( $uid ),
			'founding' => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::entitlements() . " WHERE user_id = %d AND plan = 'founding'", (int) $uid ) ), // phpcs:ignore
			'since'    => (int) strtotime( $u->user_registered . ' UTC' ),
		);
	}

	/**
	 * Delete a host and everything that is theirs. Game records stay for the site's stats but lose
	 * the owner and the player names.
	 */
	public static function delete( $uid ) {
		global $wpdb;
		$uid = (int) $uid;
		if ( ! self::is_host( $uid ) ) {
			return new WP_Error( 'nothost', 'Only host accounts can be deleted here.', array( 'status' => 403 ) );
		}
		// Apple asks apps to revoke their tokens when an account is deleted.
		$apple = $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM ' . self::identities() . " WHERE user_id = %d AND provider = 'apple'", $uid ) ); // phpcs:ignore
		foreach ( $apple as $d ) {
			$d = json_decode( (string) $d, true );
			if ( ! empty( $d['refresh'] ) ) {
				GC_Auth::apple_revoke( (string) $d['refresh'], (string) ( $d['client'] ?? '' ) );
			}
		}
		$wpdb->delete( self::identities(), array( 'user_id' => $uid ) ); // phpcs:ignore
		$wpdb->delete( self::sessions(), array( 'user_id' => $uid ) ); // phpcs:ignore
		$wpdb->delete( self::entitlements(), array( 'user_id' => $uid ) ); // phpcs:ignore
		GC_Packs::delete_all( $uid );
		$wpdb->update( GC_DB::matches(), array( 'owner_id' => 0, 'standings' => null, 'winner_names' => '' ), array( 'owner_id' => $uid ) ); // phpcs:ignore
		$wpdb->update( GC_DB::rooms(), array( 'owner_id' => 0 ), array( 'owner_id' => $uid ) ); // phpcs:ignore
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $uid );
		self::forget();
		return true;
	}

	// ------------------------------------------------------------------ saved settings

	public static function prefs( $uid, $game ) {
		$v = json_decode( (string) get_user_meta( (int) $uid, 'gc_prefs_' . sanitize_key( $game ), true ), true );
		return is_array( $v ) ? $v : null;
	}

	/** Keep only small, plain values: these are the host's settings, the game checks them again. */
	public static function save_prefs( $uid, $game, $settings ) {
		$clean = array();
		foreach ( (array) $settings as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( '' === $k || count( $clean ) >= 20 ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$clean[ $k ] = array_slice( array_map( 'sanitize_text_field', array_filter( $v, 'is_scalar' ) ), 0, 60 );
			} elseif ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
				$clean[ $k ] = $v;
			} elseif ( is_scalar( $v ) ) {
				$clean[ $k ] = mb_substr( sanitize_text_field( (string) $v ), 0, 80 );
			}
		}
		update_user_meta( (int) $uid, 'gc_prefs_' . sanitize_key( $game ), wp_json_encode( $clean ) );
		return $clean;
	}

	// ------------------------------------------------------------------ game history

	/** The host's last games, newest first, and a most-wins board for their regular crowd. */
	public static function history( $uid, $limit = 30 ) {
		global $wpdb;
		$m    = GC_DB::matches();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, game, room_code, started, ended, players, rounds_played, winners, winner_names, status, mode, standings FROM $m WHERE owner_id = %d AND status > 0 ORDER BY started DESC LIMIT 300", (int) $uid ), ARRAY_A ); // phpcs:ignore
		$games = array();
		$board = array();
		foreach ( $rows as $i => $r ) {
			$st = json_decode( (string) $r['standings'], true );
			$st = is_array( $st ) ? $st : array();
			foreach ( $st as $p ) {
				$k = mb_strtolower( (string) ( $p['n'] ?? '' ) );
				if ( '' === $k ) {
					continue;
				}
				if ( ! isset( $board[ $k ] ) ) {
					$board[ $k ] = array( 'name' => (string) $p['n'], 'wins' => 0, 'games' => 0 );
				}
				$board[ $k ]['games']++;
				$board[ $k ]['wins'] += ! empty( $p['w'] ) ? 1 : 0;
			}
			if ( $i < $limit ) {
				$games[] = array(
					'id'       => (int) $r['id'],
					'game'     => $r['game'],
					'name'     => ( $g = GC_Games::get( $r['game'] ) ) ? $g->name() : $r['game'],
					'mode'     => $r['mode'],
					'at'       => (int) $r['started'],
					'mins'     => $r['ended'] ? max( 1, (int) round( ( (int) $r['ended'] - (int) $r['started'] ) / 60 ) ) : 0,
					'players'  => (int) $r['players'],
					'rounds'   => (int) $r['rounds_played'],
					'winners'  => '' !== $r['winner_names'] ? explode( ', ', $r['winner_names'] ) : array(),
					'stopped'  => 2 === (int) $r['status'],
					'top'      => array_slice( $st, 0, 10 ),
				);
			}
		}
		$board = array_values( array_filter( $board, function ( $b ) {
			return $b['wins'] > 0;
		} ) );
		usort( $board, function ( $a, $b ) {
			return $b['wins'] <=> $a['wins'] ?: $b['games'] <=> $a['games'];
		} );
		return array(
			'games' => $games,
			'total' => count( $rows ),
			'board' => array_slice( $board, 0, 10 ),
		);
	}
}
