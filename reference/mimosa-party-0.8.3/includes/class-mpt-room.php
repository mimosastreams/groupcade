<?php
/**
 * Room game logic. The game moves forward lazily: every request (host screen or phone poll)
 * calls tick(), which advances phases whose time is up. No cron or websocket needed.
 *
 * Phases: lobby -> cat (category card) -> q (question) -> rev (answer reveal) -> cat ... -> end.
 * Rules (same as stream Survive Trivia): wrong or no answer = out; if everyone still standing misses,
 * nobody is out; Easy, then Medium, then Hard; last one standing wins; if several survive the last
 * round, they all win. A game started with one player is practice: one miss ends it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MPT_Room {

	const CAT_MS    = 3500;
	const REV_MS    = 7000;
	const GRACE_MS  = 1200; // Answers sent right as the clock hits 0 still count.
	const READ_MS   = 5000; // Points mode: 5 s to read before the scoring clock starts; locking in early = full speed points (Dylan 2026-11-26).
	const LETTERS   = 'BCDFGHJKLMNPQRSTVWXZ'; // No vowels, so codes never spell words.
	const ROUND_SET = array( 10, 15, 20, 0 );  // 0 = until one is left (capped at 50).
	const SECS_SET  = array( 15, 20, 30 );
	const MAX_ENDLESS = 50;

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

	// ------------------------------------------------------------------ storage

	public static function load( $code ) {
		global $wpdb;
		$code = self::norm_code( $code );
		if ( strlen( $code ) !== 4 ) {
			return null;
		}
		$t   = MPT_DB::rooms();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		if ( (int) $row['updated'] < time() - MPT_DB::IDLE_SECONDS ) {
			return null;
		}
		$row['s'] = json_decode( $row['state'], true );
		if ( ! is_array( $row['s'] ) ) {
			return null;
		}
		return $row;
	}

	private static function save( &$room, $touch = true ) {
		global $wpdb;
		$t    = MPT_DB::rooms();
		$data = array(
			'state' => wp_json_encode( $room['s'] ),
			'ver'   => (int) $room['ver'] + 1,
		);
		if ( $touch ) {
			$data['updated'] = time();
		}
		$wpdb->update( $t, $data, array( 'id' => (int) $room['id'] ) ); // phpcs:ignore
		$room['ver'] = $data['ver'];
		if ( $touch ) {
			$room['updated'] = $data['updated'];
		}
	}

	/** Keep the room alive while people are playing, without writing on every poll. */
	public static function touch( $room ) {
		global $wpdb;
		if ( time() - (int) $room['updated'] > 60 ) {
			$wpdb->update( MPT_DB::rooms(), array( 'updated' => time() ), array( 'id' => (int) $room['id'] ) ); // phpcs:ignore
		}
	}

	private static function lock( $id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', 'mpt_room_' . (int) $id ) ); // phpcs:ignore
	}

	private static function unlock( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'mpt_room_' . (int) $id ) ); // phpcs:ignore
	}

	// ------------------------------------------------------------------ create / join

	public static function create( $rounds, $secs, $cats = null, $mode = 'survive' ) {
		global $wpdb;
		$t = MPT_DB::rooms();
		if ( ! in_array( (int) $rounds, self::ROUND_SET, true ) ) {
			$rounds = 15;
		}
		if ( ! in_array( (int) $secs, self::SECS_SET, true ) ) {
			$secs = 20;
		}
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE updated >= %d", time() - MPT_DB::IDLE_SECONDS ) ); // phpcs:ignore
		if ( $active >= MPT_Limits::ACTIVE_ROOMS_MAX ) {
			MPT_DB::cleanup();
			return new WP_Error( 'busy', 'Lots of games are running right now. Try again in a few minutes.', array( 'status' => 503 ) );
		}
		$host = self::token();
		$s    = array(
			'phase'  => 'lobby',
			'round'  => 0,
			'rounds' => (int) $rounds,
			'secs'   => (int) $secs,
			't0'     => self::now_ms(),
			't1'     => 0,
			'game'   => 0,
			'used'   => array(),
			'cats'   => array(),
			'only'   => array(),
			'mode'   => 'points' === $mode ? 'points' : 'survive',
		);
		if ( 'points' === $s['mode'] && ! $s['rounds'] ) {
			$s['rounds'] = 15; // Points games always have a set number of rounds.
		}
		if ( is_array( $cats ) ) {
			$pick = MPT_Bank::only_from( $cats );
			if ( $pick ) {
				$s['only'] = $pick;
			}
		}
		for ( $i = 0; $i < 25; $i++ ) {
			$code = '';
			for ( $j = 0; $j < 4; $j++ ) {
				$code .= self::LETTERS[ random_int( 0, strlen( self::LETTERS ) - 1 ) ];
			}
			// A stale room may still hold the code; drop it if it expired.
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE code = %s AND updated < %d", $code, time() - MPT_DB::IDLE_SECONDS ) ); // phpcs:ignore
			$ok = $wpdb->insert( // phpcs:ignore
				$t,
				array(
					'code'      => $code,
					'host_hash' => self::hash( $host ),
					'ip_hash'   => MPT_Limits::ip_hash(),
					'ver'       => 1,
					'state'     => wp_json_encode( $s ),
					'created'   => time(),
					'updated'   => time(),
				)
			);
			if ( $ok ) {
				$rid = (int) $wpdb->insert_id;
				$wpdb->insert( MPT_DB::room_log(), array( 'room_code' => $code, 'ip_hash' => MPT_Limits::ip_hash(), 'created' => time() ) ); // phpcs:ignore
				$lid = (int) $wpdb->insert_id;
				if ( $lid ) {
					$s['lid'] = $lid;
					$wpdb->update( $t, array( 'state' => wp_json_encode( $s ) ), array( 'id' => $rid ) ); // phpcs:ignore
				}
				return array(
					'code' => $code,
					'host' => $host,
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
		$p = MPT_DB::players();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $p WHERE room_id = %d AND tok_hash = %s", (int) $room['id'], self::hash( $token ) ), ARRAY_A ); // phpcs:ignore
	}

	public static function is_host( $room, $host ) {
		return $host && hash_equals( (string) $room['host_hash'], self::hash( $host ) );
	}

	public static function join( $room, $name ) {
		global $wpdb;
		$p     = MPT_DB::players();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
		if ( $count >= MPT_Limits::PLAYERS_MAX ) {
			return new WP_Error( 'full', 'This room is full (' . MPT_Limits::PLAYERS_MAX . ' players).', array( 'status' => 409 ) );
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
		// Joining mid-game: you watch this game and play the next one.
		$alive = ( 'lobby' === $room['s']['phase'] || self::points( $room['s'] ) ) ? 1 : 0;
		$wpdb->insert( // phpcs:ignore
			$p,
			array(
				'room_id'  => (int) $room['id'],
				'tok_hash' => self::hash( $token ),
				'name'     => $name,
				'alive'    => $alive,
				'ko_round' => 0,
				'joined'   => time(),
			)
		);
		self::touch( $room );
		if ( ! empty( $room['s']['lid'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . MPT_DB::room_log() . ' SET joins = joins + 1 WHERE id = %d', (int) $room['s']['lid'] ) ); // phpcs:ignore
		}
		return array(
			'token' => $token,
			'name'  => $name,
			'late'  => ! $alive,
		);
	}

	// ------------------------------------------------------------------ game flow

	/** Advance any phase whose time is up. Returns the fresh room. */
	public static function tick( $room ) {
		$now = self::now_ms();
		if ( ! self::due( $room['s'], $now ) ) {
			return $room;
		}
		if ( ! self::lock( $room['id'] ) ) {
			return $room;
		}
		$fresh = self::load( $room['code'] );
		if ( $fresh ) {
			$room = $fresh;
			for ( $i = 0; $i < 6 && self::due( $room['s'], self::now_ms() ); $i++ ) {
				self::step( $room );
			}
		}
		self::unlock( $room['id'] );
		return $room;
	}

	private static function due( $s, $now ) {
		if ( 'cat' === $s['phase'] || 'rev' === $s['phase'] ) {
			return $now >= (int) $s['t1'];
		}
		if ( 'q' === $s['phase'] ) {
			return $now >= (int) $s['t1'] + self::GRACE_MS;
		}
		return false;
	}

	private static function step( &$room ) {
		$s = &$room['s'];
		if ( 'cat' === $s['phase'] ) {
			$s['phase'] = 'q';
			$s['t0']    = (int) $s['t1'] + ( self::points( $s ) ? self::READ_MS : 0 );
			$s['t1']    = $s['t0'] + (int) $s['secs'] * 1000;
		} elseif ( 'q' === $s['phase'] ) {
			self::resolve( $room );
		} elseif ( 'rev' === $s['phase'] ) {
			if ( ! empty( $s['over'] ) ) {
				$s['phase'] = 'end';
				$s['t0']    = self::now_ms();
				$s['t1']    = 0;
				self::log_end( $s, 1 );
			} else {
				self::next_round( $room, (int) $s['t1'] );
			}
		}
		self::save( $room, false );
	}

	public static function start( &$room ) {
		global $wpdb;
		$p = MPT_DB::players();
		if ( 'lobby' !== $room['s']['phase'] ) {
			return new WP_Error( 'started', 'The game already started.', array( 'status' => 409 ) );
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d AND alive = 1", (int) $room['id'] ) ); // phpcs:ignore
		if ( $n < 1 ) {
			return new WP_Error( 'empty', 'Nobody has joined yet.', array( 'status' => 409 ) );
		}
		$s            = &$room['s'];
		$s['game']    = (int) $s['game'] + 1;
		$s['starters'] = $n;
		$s['round']   = 0;
		$s['over']    = false;
		$s['winners'] = array();
		$s['last']    = null;
		$s['cats']    = array();
		$wpdb->insert( // phpcs:ignore
			MPT_DB::games(),
			array(
				'room_code'  => $room['code'],
				'ip_hash'    => (string) $room['ip_hash'],
				'started'    => time(),
				'players'    => $n,
				'rounds_set' => (int) $s['rounds'],
				'mode'       => self::points( $s ) ? 'points' : 'survive',
				'secs'       => (int) $s['secs'],
			)
		);
		$s['gid'] = (int) $wpdb->insert_id;
		if ( ! empty( $s['lid'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . MPT_DB::room_log() . ' SET games = games + 1 WHERE id = %d', (int) $s['lid'] ) ); // phpcs:ignore
		}
		self::next_round( $room, self::now_ms() );
		self::save( $room );
		return true;
	}

	private static function next_round( &$room, $at ) {
		$s           = &$room['s'];
		$s['round']  = (int) $s['round'] + 1;
		$level       = MPT_Bank::level_for( $s['round'], (int) $s['rounds'] );
		$only        = isset( $s['only'] ) ? (array) $s['only'] : array();
		$ctx         = array(
			'used'        => $s['used'],
			'recent_cats' => array_slice( (array) $s['cats'], -3 ),
			'allowed'     => $only,
			'rights'      => isset( $s['rights'] ) ? (array) $s['rights'] : array(),
			'shown'       => isset( $s['shown'] ) ? (array) $s['shown'] : array(),
		);
		$qid         = MPT_Bank::pick( $level, $ctx );
		if ( ! $qid ) {
			// Bank used up (very long session): forget what was used and go again.
			$s['used'] = array();
			$qid       = MPT_Bank::pick( $level, array( 'allowed' => $only ) );
		}
		if ( ! $qid ) {
			$qid = MPT_Bank::pick( $level );
		}
		$q            = MPT_Bank::get( $qid );
		$order        = array( 0, 1, 2, 3 );
		shuffle( $order );
		$s['qid']     = $qid;
		$s['level']   = $q[0];
		$s['cat']     = $q[1];
		$s['order']   = $order; // Shown slot i holds answer $order[i]; answer 0 is the right one.
		$s['right']   = (int) array_search( 0, $order, true );
		$s['used'][]  = $qid;
		// Answers seen in this room, so later questions don't give away or repeat them.
		$s['rights']   = array_slice( array_merge( isset( $s['rights'] ) ? (array) $s['rights'] : array(), array( MPT_Bank::norm( $q[3] ) ) ), -200 );
		$s['shown']    = array_slice( array_merge( isset( $s['shown'] ) ? (array) $s['shown'] : array(), array_map( array( 'MPT_Bank', 'norm' ), array( $q[3], $q[4], $q[5], $q[6] ) ) ), -800 );
		$s['used']    = array_slice( $s['used'], -600 );
		$s['cats'][]  = $q[1];
		$s['cats']    = array_slice( $s['cats'], -6 );
		$s['phase']   = 'cat';
		$s['t0']      = $at;
		$s['t1']      = $at + self::CAT_MS;
	}

	private static function resolve( &$room ) {
		global $wpdb;
		$p     = MPT_DB::players();
		$s     = &$room['s'];
		$rid   = (int) $room['id'];
		$r     = (int) $s['round'];
		$right = (int) $s['right'] + 1; // Stored 1-4; 0 = no answer.
		if ( self::points( $s ) ) {
			self::resolve_points( $room, $right );
			return;
		}

		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, ans_round, ans, ans_ms FROM $p WHERE room_id = %d AND alive = 1", $rid ), ARRAY_A ); // phpcs:ignore
		$counts = array( 0, 0, 0, 0 );
		$good   = array();
		$bad    = array();
		$fast   = null;
		foreach ( $rows as $row ) {
			$ok = (int) $row['ans_round'] === $r && (int) $row['ans'] >= 1;
			if ( $ok ) {
				$counts[ (int) $row['ans'] - 1 ]++;
			}
			if ( $ok && (int) $row['ans'] === $right ) {
				$good[] = $row;
				if ( ! $fast || (int) $row['ans_ms'] < (int) $fast['ans_ms'] ) {
					$fast = $row;
				}
			} else {
				$bad[] = $row;
			}
		}
		$standing = count( $rows );
		$revived  = false;
		if ( ! $good && $standing >= 2 ) {
			$revived = true; // Everyone missed: nobody is out.
			$out     = array();
		} else {
			$out = $bad;
		}
		if ( $out ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $out, 'id' ) ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $p SET alive = 0, ko_round = %d WHERE id IN ($ids)", $r ) ); // phpcs:ignore
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $p SET best = %d WHERE room_id = %d AND alive = 1", $r, $rid ) ); // phpcs:ignore

		$left    = $standing - count( $out );
		$rounds  = (int) $s['rounds'] > 0 ? (int) $s['rounds'] : self::MAX_ENDLESS;
		$solo    = (int) $s['starters'] < 2;
		$over    = $r >= $rounds || 0 === $left || ( ! $solo && $left <= 1 );
		$names   = wp_list_pluck( $out, 'name' );
		$s['last'] = array(
			'round'   => $r,
			'right'   => (int) $s['right'],
			'counts'  => $counts,
			'out'     => array_slice( $names, 0, 30 ),
			'outN'    => count( $names ),
			'revived' => $revived,
			'left'    => $left,
			'fast'    => $fast ? array( 'name' => $fast['name'], 'ms' => (int) $fast['ans_ms'] ) : null,
		);
		$s['over']  = $over;
		if ( $over ) {
			$s['winners'] = $left > 0 ? array_values( $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $p WHERE room_id = %d AND alive = 1 ORDER BY id", $rid ) ) ) : array(); // phpcs:ignore
		}
		$s['phase'] = 'rev';
		$s['t0']    = self::now_ms();
		$s['t1']    = $s['t0'] + self::REV_MS;
	}

	public static function points( $s ) {
		return isset( $s['mode'] ) && 'points' === $s['mode'];
	}

	/**
	 * Points mode: nobody is knocked out. A right answer scores 500 plus up to 500 for speed
	 * (all 500 if instant, none at the buzzer). After the last round the top score wins.
	 */
	private static function resolve_points( &$room, $right ) {
		global $wpdb;
		$p      = MPT_DB::players();
		$s      = &$room['s'];
		$rid    = (int) $room['id'];
		$r      = (int) $s['round'];
		$total  = max( 1, (int) $s['secs'] * 1000 );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, ans_round, ans, ans_ms FROM $p WHERE room_id = %d AND alive = 1", $rid ), ARRAY_A ); // phpcs:ignore
		$counts = array( 0, 0, 0, 0 );
		$fast   = null;
		$nright = 0;
		$wpdb->query( $wpdb->prepare( "UPDATE $p SET gain = 0, best = %d WHERE room_id = %d", $r, $rid ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			if ( (int) $row['ans_round'] !== $r || (int) $row['ans'] < 1 ) {
				continue;
			}
			$counts[ (int) $row['ans'] - 1 ]++;
			if ( (int) $row['ans'] !== $right ) {
				continue;
			}
			$nright++;
			$left = max( 0, $total - (int) $row['ans_ms'] );
			$gain = 500 + (int) round( 500 * $left / $total );
			$wpdb->query( $wpdb->prepare( "UPDATE $p SET score = score + %d, gain = %d WHERE id = %d", $gain, $gain, (int) $row['id'] ) ); // phpcs:ignore
			if ( ! $fast || (int) $row['ans_ms'] < (int) $fast['ans_ms'] ) {
				$fast = $row;
			}
		}
		$over      = $r >= max( 1, (int) $s['rounds'] );
		$s['last'] = array(
			'round'   => $r,
			'right'   => (int) $s['right'],
			'counts'  => $counts,
			'out'     => array(),
			'outN'    => 0,
			'revived' => false,
			'left'    => count( $rows ),
			'nright'  => $nright,
			'fast'    => $fast ? array( 'name' => $fast['name'], 'ms' => (int) $fast['ans_ms'] ) : null,
		);
		$s['over'] = $over;
		if ( $over ) {
			$s['winners'] = self::points_leaders( $rid );
		}
		$s['phase'] = 'rev';
		$s['t0']    = self::now_ms();
		$s['t1']    = $s['t0'] + self::REV_MS;
	}

	/** Names with the top score (ties share the win). Empty if nobody scored. */
	public static function points_leaders( $rid ) {
		global $wpdb;
		$p   = MPT_DB::players();
		$top = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(score) FROM $p WHERE room_id = %d", (int) $rid ) ); // phpcs:ignore
		if ( $top <= 0 ) {
			return array();
		}
		return array_values( $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $p WHERE room_id = %d AND score = %d ORDER BY id", (int) $rid, $top ) ) ); // phpcs:ignore
	}

	/** Stats: close the game row. Status 1 = played to the end, 2 = host ended it. */
	private static function log_end( $s, $status ) {
		global $wpdb;
		if ( empty( $s['gid'] ) ) {
			return;
		}
		$w = isset( $s['winners'] ) ? (array) $s['winners'] : array();
		$wpdb->update( // phpcs:ignore
			MPT_DB::games(),
			array(
				'ended'         => time(),
				'rounds_played' => (int) $s['round'],
				'winners'       => count( $w ),
				'winner_names'  => mb_substr( implode( ', ', $w ), 0, 120 ),
				'status'        => (int) $status,
			),
			array( 'id' => (int) $s['gid'] )
		);
	}

	/** Lock in an answer (1-4) for the current round. */
	public static function answer( $room, $player, $round, $choice ) {
		global $wpdb;
		$s   = $room['s'];
		$now = self::now_ms();
		if ( 'q' !== $s['phase'] || (int) $round !== (int) $s['round'] || $now > (int) $s['t1'] + self::GRACE_MS ) {
			return new WP_Error( 'closed', 'Too late for this question.', array( 'status' => 409 ) );
		}
		if ( ! (int) $player['alive'] ) {
			return new WP_Error( 'out', 'You are out this game.', array( 'status' => 409 ) );
		}
		$choice = (int) $choice;
		if ( $choice < 1 || $choice > 4 ) {
			return new WP_Error( 'bad', 'Pick A, B, C or D.', array( 'status' => 400 ) );
		}
		$p  = MPT_DB::players();
		$ms = max( 0, $now - (int) $s['t0'] );
		// First answer counts; no changing it.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $p SET ans_round = %d, ans = %d, ans_ms = %d WHERE id = %d AND alive = 1 AND ans_round < %d", (int) $s['round'], $choice, $ms, (int) $player['id'], (int) $s['round'] ) ); // phpcs:ignore
		if ( ! $n ) {
			return new WP_Error( 'locked', 'You already locked in.', array( 'status' => 409 ) );
		}
		self::maybe_all_in( $room );
		return true;
	}

	/** Everyone still standing has locked in: stop the clock now instead of waiting it out. */
	private static function maybe_all_in( $room ) {
		global $wpdb;
		$p       = MPT_DB::players();
		$r       = (int) $room['s']['round'];
		$waiting = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d AND alive = 1 AND ans_round < %d", (int) $room['id'], $r ) ); // phpcs:ignore
		if ( $waiting > 0 || ! self::lock( $room['id'] ) ) {
			return;
		}
		$fresh = self::load( $room['code'] );
		if ( $fresh && 'q' === $fresh['s']['phase'] && (int) $fresh['s']['round'] === $r ) {
			$now = self::now_ms();
			if ( (int) $fresh['s']['t1'] > $now ) {
				$fresh['s']['t1']  = $now - self::GRACE_MS; // Due right away; tick() resolves it.
				$fresh['s']['all'] = true;
				self::save( $fresh, false );
			}
		}
		self::unlock( $room['id'] );
	}

	// ------------------------------------------------------------------ host actions

	public static function host_action( &$room, $action, $args ) {
		global $wpdb;
		$p = MPT_DB::players();
		$s = &$room['s'];
		switch ( $action ) {
			case 'start':
				return self::start( $room );
			case 'settings':
				if ( 'lobby' !== $s['phase'] && 'end' !== $s['phase'] ) {
					return new WP_Error( 'playing', 'Change settings between games.', array( 'status' => 409 ) );
				}
				if ( isset( $args['rounds'] ) && in_array( (int) $args['rounds'], self::ROUND_SET, true ) ) {
					$s['rounds'] = (int) $args['rounds'];
				}
				if ( isset( $args['secs'] ) && in_array( (int) $args['secs'], self::SECS_SET, true ) ) {
					$s['secs'] = (int) $args['secs'];
				}
				if ( isset( $args['mode'] ) && in_array( $args['mode'], array( 'survive', 'points' ), true ) ) {
					$s['mode'] = $args['mode'];
				}
				if ( self::points( $s ) && ! (int) $s['rounds'] ) {
					$s['rounds'] = 15;
				}
				if ( isset( $args['cats'] ) && is_array( $args['cats'] ) ) {
					// Empty or every category = all. At least one must stay on.
					$pick = MPT_Bank::only_from( $args['cats'] );
					if ( null === $pick ) {
						return new WP_Error( 'nocats', 'Keep at least one category on.', array( 'status' => 400 ) );
					}
					$s['only'] = $pick;
				}
				self::save( $room );
				return true;
			case 'kick':
				$wpdb->delete( $p, array( 'id' => (int) $args['player'], 'room_id' => (int) $room['id'] ) ); // phpcs:ignore
				self::save( $room );
				return true;
			case 'next':
				// Skip the rest of the reveal.
				if ( 'rev' === $s['phase'] ) {
					$s['t1'] = self::now_ms();
					self::save( $room );
				}
				return true;
			case 'end':
				if ( 'lobby' === $s['phase'] || 'end' === $s['phase'] ) {
					return true;
				}
				$s['phase']   = 'end';
				$s['over']    = true;
				$s['t0']      = self::now_ms();
				$s['t1']      = 0;
				$s['winners'] = self::points( $s ) ? self::points_leaders( (int) $room['id'] ) : array_values( $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $p WHERE room_id = %d AND alive = 1 ORDER BY id", (int) $room['id'] ) ) ); // phpcs:ignore
				$s['stopped'] = true;
				self::log_end( $s, 2 );
				self::save( $room );
				return true;
			case 'again':
				$wpdb->query( $wpdb->prepare( "UPDATE $p SET alive = 1, ans_round = 0, ans = 0, ans_ms = 0, ko_round = 0, best = 0, score = 0, gain = 0 WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
				$s['phase']   = 'lobby';
				$s['round']   = 0;
				$s['over']    = false;
				$s['stopped'] = false;
				$s['winners'] = array();
				$s['last']    = null;
				$s['t0']      = self::now_ms();
				$s['t1']      = 0;
				self::save( $room );
				return true;
		}
		return new WP_Error( 'bad', 'Unknown action.', array( 'status' => 400 ) );
	}

	// ------------------------------------------------------------------ public view

	/** What a phone or the host screen sees. The right answer only appears in the reveal. */
	public static function view( $room, $me = null, $host = false ) {
		global $wpdb;
		$p    = MPT_DB::players();
		$s    = $room['s'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, alive, ans_round, ko_round, best, score, gain, joined FROM $p WHERE room_id = %d ORDER BY id", (int) $room['id'] ), ARRAY_A ); // phpcs:ignore
		$list = array();
		$alive = 0;
		$locked = 0;
		foreach ( $rows as $r ) {
			$is_alive = (int) $r['alive'] === 1;
			$alive   += $is_alive ? 1 : 0;
			$in       = 'q' === $s['phase'] && $is_alive && (int) $r['ans_round'] === (int) $s['round'];
			$locked  += $in ? 1 : 0;
			$item     = array(
				'name'  => $r['name'],
				'alive' => $is_alive,
				'ko'    => (int) $r['ko_round'],
				'best'  => (int) $r['best'],
				'in'    => $in,
				'score' => (int) $r['score'],
				'gain'  => (int) $r['gain'],
			);
			if ( $host ) {
				$item['id'] = (int) $r['id'];
			}
			$list[] = $item;
		}
		$out = array(
			'code'    => $room['code'],
			'ver'     => (int) $room['ver'],
			'now'     => self::now_ms(),
			'phase'   => $s['phase'],
			'round'   => (int) $s['round'],
			'rounds'  => (int) $s['rounds'],
			'secs'    => (int) $s['secs'],
			't0'      => (int) $s['t0'],
			't1'      => (int) $s['t1'],
			'grace'   => self::GRACE_MS,
			'game'    => (int) $s['game'],
			'players' => $list,
			'alive'   => $alive,
			'locked'  => $locked,
			'host'    => (bool) $host,
			'mode'    => self::points( $s ) ? 'points' : 'survive',
			'only'    => isset( $s['only'] ) ? array_values( (array) $s['only'] ) : array(),
		);
		if ( $host && in_array( $s['phase'], array( 'lobby', 'end' ), true ) ) {
			$out['allCats']  = MPT_Bank::categories();
			$out['specCats'] = MPT_Bank::specials();
		}
		if ( in_array( $s['phase'], array( 'cat', 'q', 'rev' ), true ) || ( 'end' === $s['phase'] && ! empty( $s['qid'] ) ) ) {
			$out['level'] = $s['level'];
			$out['cat']   = $s['cat'];
			$out['cats']  = ! empty( $s['only'] ) ? array_values( $s['only'] ) : MPT_Bank::categories();
		}
		if ( in_array( $s['phase'], array( 'q', 'rev' ), true ) || ( 'end' === $s['phase'] && ! empty( $s['last'] ) ) ) {
			$q = MPT_Bank::get( (int) $s['qid'] );
			if ( $q ) {
				$ans = array( $q[3], $q[4], $q[5], $q[6] );
				$out['q']       = $q[2];
				$out['choices'] = array();
				foreach ( $s['order'] as $i ) {
					$out['choices'][] = $ans[ $i ];
				}
			}
		}
		if ( ( 'rev' === $s['phase'] || 'end' === $s['phase'] ) && ! empty( $s['last'] ) ) {
			$out['last'] = $s['last'];
		}
		if ( 'end' === $s['phase'] ) {
			$out['winners'] = isset( $s['winners'] ) ? $s['winners'] : array();
			$out['stopped'] = ! empty( $s['stopped'] );
		}
		if ( $me ) {
			$mine = array(
				'name'  => $me['name'],
				'alive' => (int) $me['alive'] === 1,
				'ko'    => (int) $me['ko_round'],
				'best'  => (int) $me['best'],
				'ans'   => 0,
				'score' => (int) $me['score'],
				'gain'  => (int) $me['gain'],
			);
			if ( (int) $me['ans_round'] === (int) $s['round'] && in_array( $s['phase'], array( 'q', 'rev', 'end' ), true ) ) {
				$mine['ans'] = (int) $me['ans'];
			}
			$out['me'] = $mine;
		}
		return $out;
	}
}
