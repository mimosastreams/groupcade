<?php
/**
 * Trash talk: short chat messages inside a room. Deleted with the room.
 * Slurs are blocked; everyday swears are starred out.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Chat {

	const MAX_LEN = 80;

	public static function table() {
		return GC_DB::chat();
	}

	private static function hard() {
		return array( 'nigg', 'faggot', 'fagot', 'kike', 'chink', 'spic', 'retard', 'tranny', 'rapist', 'nazi', 'hitler', 'kkk', 'cunt', 'porn', 'jizz', 'kys', 'killyourself' );
	}

	private static function soft() {
		return array( 'motherfucker', 'fucking', 'fucker', 'fuck', 'shit', 'bitch', 'bastard', 'pussy', 'whore', 'slut', 'twat', 'wanker', 'asshole' );
	}

	/** Returns the cleaned message, or '' when it is empty or blocked. $max: longest it may be (chat: 80). */
	public static function clean( $raw, $max = self::MAX_LEN ) {
		$m = wp_strip_all_tags( (string) $raw );
		$m = preg_replace( '/[\x{0000}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $m );
		$m = trim( preg_replace( '/\s+/u', ' ', (string) $m ) );
		$m = function_exists( 'mb_substr' ) ? mb_substr( $m, 0, (int) $max ) : substr( $m, 0, (int) $max );
		$m = trim( $m );
		if ( '' === $m ) {
			return '';
		}
		$flat = strtolower( preg_replace( '/[^a-z0-9]/i', '', strtr( $m, array( '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's' ) ) ) );
		foreach ( self::hard() as $w ) {
			if ( false !== strpos( $flat, $w ) ) {
				return '';
			}
		}
		foreach ( self::soft() as $w ) {
			$m = preg_replace_callback(
				'/' . preg_quote( $w, '/' ) . '/i',
				function ( $x ) {
					$s = $x[0];
					return substr( $s, 0, 1 ) . str_repeat( '*', strlen( $s ) - 1 );
				},
				$m
			);
		}
		return $m;
	}

	public static function add( $room, $me, $raw ) {
		global $wpdb;
		$msg = self::clean( $raw );
		if ( '' === $msg ) {
			return new WP_Error( 'blocked', 'That message can\'t be sent.', array( 'status' => 400 ) );
		}
		$key = 'gc_chat_' . (int) $me['id'];
		if ( get_transient( $key ) ) {
			return new WP_Error( 'slow', 'Easy there. One message every couple of seconds.', array( 'status' => 429 ) );
		}
		set_transient( $key, 1, 2 );
		$wpdb->insert( // phpcs:ignore
			self::table(),
			array(
				'room_id' => (int) $room['id'],
				'pid'     => (int) $me['id'],
				'name'    => (string) $me['name'],
				'msg'     => $msg,
				't'       => time(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Messages after $since (the last 30 at most, from the last 15 minutes). */
	public static function since( $room_id, $since ) {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, msg FROM $t WHERE room_id = %d AND id > %d AND t > %d ORDER BY id DESC LIMIT 30", (int) $room_id, (int) $since, time() - 900 ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( array_reverse( (array) $rows ) as $r ) {
			$out[] = array( 'id' => (int) $r['id'], 'name' => $r['name'], 'msg' => $r['msg'] );
		}
		return $out;
	}

}
