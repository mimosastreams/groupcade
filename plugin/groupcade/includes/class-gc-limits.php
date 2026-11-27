<?php
/**
 * Abuse limits: per-IP rate limits (transients) and a name filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Limits {

	const ROOMS_PER_HOUR   = 10;  // New rooms per IP per hour.
	const JOINS_PER_10MIN  = 80;  // Joins per IP per 10 min (a whole party can share one Wi-Fi IP).
	const ACTIVE_ROOMS_MAX = 300; // Rooms alive at once, site wide.
	const PLAYERS_MAX      = 50;  // Players per room.

	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore
		if ( ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ); // phpcs:ignore
			$cand  = trim( $parts[0] );
			if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
				$ip = $cand;
			}
		}
		return $ip;
	}

	public static function ip_hash() {
		return substr( hash_hmac( 'sha256', self::ip(), wp_salt( 'auth' ) ), 0, 16 );
	}

	/** Count one hit; false when over the limit. */
	public static function hit( $kind, $max, $window ) {
		$key = 'gc_rl_' . $kind . '_' . self::ip_hash();
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}

	private static function words() {
		// Kept short on purpose: blocks the obvious stuff, the host can kick anything else.
		return array( 'fuck', 'shit', 'cunt', 'nigg', 'faggot', 'bitch', 'whore', 'slut', 'rapist', 'nazi', 'hitler', 'kike', 'chink', 'retard', 'cocksuck', 'dickhead', 'pussy', 'penis', 'vagina', 'porn', 'twat', 'wank', 'jizz', 'kkk' );
	}

	/** Clean a display name. Returns '' when it is empty or blocked. */
	public static function name( $raw ) {
		$n = wp_strip_all_tags( (string) $raw );
		$n = preg_replace( '/[^\p{L}\p{N}\p{M}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF} _\.\'\-!?]/u', '', $n );
		$n = trim( preg_replace( '/\s+/u', ' ', (string) $n ) );
		if ( function_exists( 'mb_substr' ) ) {
			$n = mb_substr( $n, 0, 16 );
		} else {
			$n = substr( $n, 0, 16 );
		}
		$n = trim( $n );
		if ( '' === $n ) {
			return '';
		}
		$flat = strtolower( preg_replace( '/[^a-z0-9]/i', '', strtr( $n, array( '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's' ) ) ) );
		foreach ( self::words() as $w ) {
			if ( false !== strpos( $flat, $w ) ) {
				return '';
			}
		}
		return $n;
	}
}
