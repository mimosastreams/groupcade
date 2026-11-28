<?php
/**
 * Small JWT helpers for host sign-in: checking Apple and Google ID tokens (RS256, keys from the
 * provider's JWKS URL, cached for 6 hours) and signing Apple's client secret (ES256).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_JWT {

	public static function b64url_decode( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		$p = strlen( $s ) % 4;
		if ( $p ) {
			$s .= str_repeat( '=', 4 - $p );
		}
		return (string) base64_decode( $s, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function b64url_encode( $s ) {
		return rtrim( strtr( base64_encode( (string) $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Check a provider's ID token.
	 *
	 * @param string   $jwt       The token.
	 * @param string   $jwks_url  Where the provider publishes its keys.
	 * @param string[] $issuers   Accepted 'iss' values.
	 * @param string[] $audiences Accepted 'aud' values (our client IDs).
	 * @return array|WP_Error The token's claims.
	 */
	public static function verify( $jwt, $jwks_url, $issuers, $audiences ) {
		$bad   = new WP_Error( 'badtoken', 'That sign-in did not work. Try again.', array( 'status' => 401 ) );
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return $bad;
		}
		$head   = json_decode( self::b64url_decode( $parts[0] ), true );
		$claims = json_decode( self::b64url_decode( $parts[1] ), true );
		$sig    = self::b64url_decode( $parts[2] );
		if ( ! is_array( $head ) || ! is_array( $claims ) || 'RS256' !== ( $head['alg'] ?? '' ) || '' === $sig ) {
			return $bad;
		}
		$key = self::key_for( $jwks_url, (string) ( $head['kid'] ?? '' ) );
		if ( ! $key ) {
			return new WP_Error( 'nokey', 'Could not check that sign-in right now. Try again in a minute.', array( 'status' => 503 ) );
		}
		if ( 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			return $bad;
		}
		$now = time();
		if ( ! in_array( (string) ( $claims['iss'] ?? '' ), (array) $issuers, true ) ) {
			return $bad;
		}
		$aud = (array) ( $claims['aud'] ?? array() );
		if ( ! array_intersect( array_map( 'strval', $aud ), array_filter( (array) $audiences ) ) ) {
			return new WP_Error( 'badaud', 'This sign-in is not set up for Groupcade yet.', array( 'status' => 401 ) );
		}
		if ( (int) ( $claims['exp'] ?? 0 ) < $now - 60 || (int) ( $claims['iat'] ?? 0 ) > $now + 300 || empty( $claims['sub'] ) ) {
			return $bad;
		}
		return $claims;
	}

	/** The PEM public key with this kid, from the JWKS (cached; refetched once when a kid is new). */
	private static function key_for( $url, $kid ) {
		$filtered = apply_filters( 'gc_jwks', null, $url );
		if ( is_array( $filtered ) ) {
			return self::pick( $filtered, $kid );
		}
		$cache = 'gc_jwks_' . md5( $url );
		$keys  = get_transient( $cache );
		$pem   = is_array( $keys ) ? self::pick( $keys, $kid ) : null;
		if ( $pem ) {
			return $pem;
		}
		$res = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$keys = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $keys ) || empty( $keys['keys'] ) ) {
			return null;
		}
		set_transient( $cache, $keys, 6 * HOUR_IN_SECONDS );
		return self::pick( $keys, $kid );
	}

	private static function pick( $jwks, $kid ) {
		foreach ( (array) ( $jwks['keys'] ?? array() ) as $k ) {
			if ( ( $k['kid'] ?? '' ) === $kid && 'RSA' === ( $k['kty'] ?? '' ) ) {
				return self::rsa_pem( self::b64url_decode( $k['n'] ), self::b64url_decode( $k['e'] ) );
			}
		}
		return null;
	}

	// ------------------------------------------------------------------ DER helpers

	private static function der_len( $n ) {
		if ( $n < 128 ) {
			return chr( $n );
		}
		$b = '';
		while ( $n > 0 ) {
			$b = chr( $n & 0xff ) . $b;
			$n >>= 8;
		}
		return chr( 0x80 | strlen( $b ) ) . $b;
	}

	private static function der( $tag, $body ) {
		return chr( $tag ) . self::der_len( strlen( $body ) ) . $body;
	}

	private static function der_int( $bytes ) {
		$bytes = ltrim( $bytes, "\0" );
		if ( '' === $bytes || ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\0" . $bytes;
		}
		return self::der( 0x02, $bytes );
	}

	/** RSA modulus and exponent to a PEM SubjectPublicKeyInfo. */
	public static function rsa_pem( $n, $e ) {
		$rsa  = self::der( 0x30, self::der_int( $n ) . self::der_int( $e ) );
		$alg  = self::der( 0x30, self::der( 0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" ) . "\x05\x00" );
		$spki = self::der( 0x30, $alg . self::der( 0x03, "\0" . $rsa ) );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Sign an ES256 JWT (Apple's client secret).
	 *
	 * @return string|null Null when the key can't be read.
	 */
	public static function sign_es256( $claims, $kid, $pem ) {
		$key = openssl_pkey_get_private( (string) $pem );
		if ( ! $key ) {
			return null;
		}
		$h    = self::b64url_encode( wp_json_encode( array( 'alg' => 'ES256', 'kid' => $kid ) ) );
		$c    = self::b64url_encode( wp_json_encode( $claims ) );
		$der  = '';
		if ( ! openssl_sign( $h . '.' . $c, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		// DER SEQUENCE { INTEGER r, INTEGER s } to the raw 64-byte r||s that JWS wants.
		$pos = 2;
		if ( ord( $der[1] ) & 0x80 ) {
			$pos += ord( $der[1] ) & 0x7f;
		}
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			$len  = ord( $der[ $pos + 1 ] );
			$int  = substr( $der, $pos + 2, $len );
			$out .= str_pad( ltrim( $int, "\0" ), 32, "\0", STR_PAD_LEFT );
			$pos += 2 + $len;
		}
		return $h . '.' . $c . '.' . self::b64url_encode( $out );
	}
}
