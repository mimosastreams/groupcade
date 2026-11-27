<?php
/**
 * Rooms: codes, the host token, players joining, storage and locking. Game rules live in the
 * room's GC_Game. The game moves forward lazily: every request (host screen, TV or phone poll)
 * calls tick(), which steps any phase whose time is up. No cron or websocket needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Room {

	const LETTERS = 'BCDFGHJKLMNPQRSTVWXZ'; // No vowels, so codes never spell words.

	public static function now_ms() {
		return (int) floor( microtime( true ) * 1000 );
	}

	public static function hash( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'nonce' ) );
	}

	public static function token() {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function norm_code( $code ) {
		return substr( preg_replace( '/[^A-Z]/', '', strtoupper( (string) $code ) ), 0, 4 );
	}

	/** @return GC_Game|null */
	public static function game( $room ) {
		return GC_Games::get( $room['game'] );
	}

	// ------------------------------------------------------------------ storage

	public static function load( $code ) {
		global $wpdb;
		$code = self::norm_code( $code );
		if ( strlen( $code ) !== 4 ) {
			return null;
		}
		$t   = GC_DB::rooms();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore
		if ( ! $row || (int) $row['updated'] < time() - GC_DB::IDLE_SECONDS ) {
			return null;
		}
		if ( ! GC_Games::get( $row['game'] ) ) {
			return null; // The game was removed from the site.
		}
		$row['s'] = json_decode( $row['state'], true );
		if ( ! is_array( $row['s'] ) ) {
			return null;
		}
		return $row;
	}

	public static function save( &$room, $touch = true ) {
		global $wpdb;
		$data = array(
			'state' => wp_json_encode( $room['s'] ),
			'ver'   => (int) $room['ver'] + 1,
		);
		if ( $touch ) {
			$data['updated'] = time();
		}
		$wpdb->update( GC_DB::rooms(), $data, array( 'id' => (int) $room['id'] ) ); // phpcs:ignore
		$room['ver'] = $data['ver'];
		if ( $touch ) {
			$room['updated'] = $data['updated'];
		}
	}

	/** Keep the room alive while people are playing, without writing on every poll. */
	public static function touch( $room ) {
		global $wpdb;
		if ( time() - (int) $room['updated'] > 60 ) {
			$wpdb->update( GC_DB::rooms(), array( 'updated' => time() ), array( 'id' => (int) $room['id'] ) ); // phpcs:ignore
		}
	}

	/**
	 * Per-room mutex. MySQL GET_LOCK on the real site; an option row as a fallback for databases
	 * without it (the SQLite driver used for local testing).
	 */
	public static function lock( $id ) {
		global $wpdb;
		$name = 'gc_room_' . (int) $id;
		$wpdb->suppress_errors( true );
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $name ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
		if ( null !== $got && '' === $wpdb->last_error ) {
			return (int) $got;
		}
		for ( $i = 0; $i < 30; $i++ ) {
			if ( add_option( '_' . $name . '_lock', time(), '', false ) ) {
				return 1;
			}
			if ( (int) get_option( '_' . $name . '_lock' ) < time() - 5 ) {
				delete_option( '_' . $name . '_lock' ); // Stale.
				continue;
			}
			usleep( 100000 );
		}
		return 0;
	}

	public static function unlock( $id ) {
		global $wpdb;
		$name = 'gc_room_' . (int) $id;
		$wpdb->suppress_errors( true );
		$rel = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
		if ( null === $rel ) {
			delete_option( '_' . $name . '_lock' );
		}
	}

	// ------------------------------------------------------------------ create / join

	/**
	 * @return array|WP_Error code, host (token), game
	 */
	public static function create( $slug, $params ) {
		global $wpdb;
		$game = GC_Games::get( $slug ? $slug : GC_Games::default_slug() );
		if ( ! $game ) {
			return new WP_Error( 'nogame', 'That game is not available.', array( 'status' => 400 ) );
		}
		$t      = GC_DB::rooms();
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE updated >= %d", time() - GC_DB::IDLE_SECONDS ) ); // phpcs:ignore
		if ( $active >= GC_Limits::ACTIVE_ROOMS_MAX ) {
			GC_DB::cleanup();
			return new WP_Error( 'busy', 'Lots of games are running right now. Try again in a few minutes.', array( 'status' => 503 ) );
		}
		$s = $game->new_state( (array) $params );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$s['phase'] = 'lobby';
		$s['t0']    = self::now_ms();
		$s['t1']    = 0;
		$s['played'] = 0; // Games played in this room.
		$host       = self::token();
		for ( $i = 0; $i < 25; $i++ ) {
			$code = '';
			for ( $j = 0; $j < 4; $j++ ) {
				$code .= self::LETTERS[ random_int( 0, strlen( self::LETTERS ) - 1 ) ];
			}
			// A stale room may still hold the code; drop it if it expired.
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE code = %s AND updated < %d", $code, time() - GC_DB::IDLE_SECONDS ) ); // phpcs:ignore
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( // phpcs:ignore
				$t,
				array(
					'code'      => $code,
					'game'      => $game->slug(),
					'host_hash' => self::hash( $host ),
					'ip_hash'   => GC_Limits::ip_hash(),
					'ver'       => 1,
					'state'     => wp_json_encode( $s ),
					'created'   => time(),
					'updated'   => time(),
				)
			);
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$rid = (int) $wpdb->insert_id;
				$wpdb->insert( GC_DB::room_log(), array( 'game' => $game->slug(), 'room_code' => $code, 'ip_hash' => GC_Limits::ip_hash(), 'created' => time() ) ); // phpcs:ignore
				$lid = (int) $wpdb->insert_id;
				if ( $lid ) {
					$s['lid'] = $lid;
					$wpdb->update( $t, array( 'state' => wp_json_encode( $s ) ), array( 'id' => $rid ) ); // phpcs:ignore
				}
				return array(
					'code' => $code,
					'host' => $host,
					'game' => $game->slug(),
				);
			}
		}
		return new WP_Error( 'nocode', 'Could not make a room code. Try again.', array( 'status' => 500 ) );
	}

	public static function player_by_token( $room, $token ) {
		global $wpdb;
		if ( ! $token ) {
			return null;
		}
		$p = GC_DB::players();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $p WHERE room_id = %d AND tok_hash = %s", (int) $room['id'], self::hash( $token ) ), ARRAY_A ); // phpcs:ignore
	}

	public static function is_host( $room, $host ) {
		return $host && hash_equals( (string) $room['host_hash'], self::hash( $host ) );
	}

	public static function players( $room ) {
		global $wpdb;
		$p = GC_DB::players();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $p WHERE room_id = %d ORDER BY id", (int) $room['id'] ), ARRAY_A ); // phpcs:ignore
	}

	public static function join( $room, $name ) {
		global $wpdb;
		$game  = self::game( $room );
		$p     = GC_DB::players();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
		$max   = $game->max_players();
		if ( $count >= $max ) {
			return new WP_Error( 'full', 'This room is full (' . $max . ' players).', array( 'status' => 409 ) );
		}
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $p WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
		$lower = array_map( 'mb_strtolower', $names );
		$base  = $name;
		$n     = 2;
		while ( in_array( mb_strtolower( $name ), $lower, true ) ) {
			$name = mb_substr( $base, 0, 13 ) . ' ' . $n;
			$n++;
		}
		$token = self::token();
		$alive = $game->join_alive( $room['s'] ) ? 1 : 0; // Joining mid-game: watch this one, play the next.
		$wpdb->insert( // phpcs:ignore
			$p,
			array(
				'room_id'  => (int) $room['id'],
				'tok_hash' => self::hash( $token ),
				'name'     => $name,
				'alive'    => $alive,
				'joined'   => time(),
			)
		);
		self::touch( $room );
		if ( ! empty( $room['s']['lid'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . GC_DB::room_log() . ' SET joins = joins + 1 WHERE id = %d', (int) $room['s']['lid'] ) ); // phpcs:ignore
		}
		return array(
			'token' => $token,
			'name'  => $name,
			'late'  => ! $alive,
		);
	}

	public static function kick( &$room, $player_id ) {
		global $wpdb;
		$wpdb->delete( GC_DB::players(), array( 'id' => (int) $player_id, 'room_id' => (int) $room['id'] ) ); // phpcs:ignore
		self::save( $room );
		return true;
	}

	public static function leave( $room, $me ) {
		global $wpdb;
		$wpdb->delete( GC_DB::players(), array( 'id' => (int) $me['id'] ) ); // phpcs:ignore
	}

	// ------------------------------------------------------------------ game flow

	/** Advance any phase whose time is up. Returns the fresh room. */
	public static function tick( $room ) {
		$game = self::game( $room );
		if ( ! $game->due( $room['s'], self::now_ms() ) ) {
			return $room;
		}
		if ( ! self::lock( $room['id'] ) ) {
			return $room;
		}
		$fresh = self::load( $room['code'] );
		if ( $fresh ) {
			$room = $fresh;
			for ( $i = 0; $i < 6 && $game->due( $room['s'], self::now_ms() ); $i++ ) {
				$game->step( $room );
				self::save( $room, false );
			}
		}
		self::unlock( $room['id'] );
		return $room;
	}

	/** The JSON a phone, the host or the TV gets. */
	public static function view( $room, $me = null, $host = false ) {
		$game = self::game( $room );
		$out  = $game->view( $room, self::players( $room ), $me, $host );
		return array_merge(
			array(
				'code' => $room['code'],
				'game' => $room['game'],
				'ver'  => (int) $room['ver'],
				'now'  => self::now_ms(),
				'host' => (bool) $host,
			),
			$out
		);
	}
}
