<?php
/**
 * Scribble Squad: every player gets a secret prompt and draws it on their phone. Then the TV replays
 * the sketches one at a time, stroke by stroke, and everyone races to type what it is.
 *
 * Phases: lobby -> draw -> show (guess sketch 1) -> srev (its answer) -> show (sketch 2) ... ->
 * board (scores) -> draw (next round) ... After the last round: best (vote for Best in show) -> end.
 *
 * Points: a right guess scores 300 plus up to 600 for speed. The artist gets +200 for each player who
 * gets it. Best in show (most votes at the end) gets +500.
 *
 * Sketches are kept in transients (gc_sk_<room>_<round>_<player>), not in the room state or the
 * players table, so a poll only reads the one sketch it shows. Per-player extras in the players
 * table's `data` JSON: w (this round's prompt), wr (its round), dr (round of the last sketch sent),
 * bv (Best in show vote, a gallery index + 1), and totals right (guesses) and drew.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Scribble extends GC_Game {

	const SHOW_MS    = 45000;
	const SREV_MS    = 6500;
	const BOARD_MS   = 7000;
	const BEST_MS    = 25000;
	const GRACE_MS   = 2500;  // Sketches sent right as the clock hits 0 still count.
	const ROUND_SET  = array( 1, 2, 3 );
	const SECS_SET   = array( 60, 75, 90 );
	const PER_ROUND  = 8;     // Big rooms: at most this many sketches are shown each round.
	const MAX_POINTS = 1500;
	const MAX_STROKE = 400;
	const FEED_MAX   = 30;
	const GALLERY    = 8;
	const COLORS     = array( '#1B1F3B', '#FF6B4A', '#FFC93C', '#3DD68C', '#5BB8F5', '#FF7FB6', '#8C7CFF', '#8B5A2B', '#FFFFFF' );
	const WIDTHS     = array( 4, 10, 20, 40 );

	public function slug() {
		return 'scribble';
	}

	public function name() {
		return 'Scribble Squad';
	}

	public function tagline() {
		return 'Draw your secret prompt. Guess everyone else\'s.';
	}

	public function blurb() {
		return 'Everyone sketches a silly secret prompt on their phone. The TV plays each drawing back and the room races to guess it.';
	}

	public function tags() {
		return array( '3 to ' . (int) $this->max_players() . ' players', 'About 15 min' );
	}

	public function client_config() {
		return array(
			'rounds'    => self::ROUND_SET,
			'secs'      => self::SECS_SET,
			'colors'    => self::COLORS,
			'widths'    => self::WIDTHS,
			'maxPoints' => self::MAX_POINTS,
		);
	}

	public function join_alive( $s ) {
		return true; // Late arrivals guess straight away and draw from the next round.
	}

	public function stats_mode( $s ) {
		return 'points';
	}

	// ------------------------------------------------------------------ create

	public function new_state( $params ) {
		$s = array(
			'phase'  => 'lobby',
			'round'  => 0,
			'rounds' => 2,
			'secs'   => 75,
			'used'   => array(),
			't0'     => GC_Room::now_ms(),
			't1'     => 0,
		);
		self::apply_settings( $s, (array) $params );
		return $s;
	}

	private static function apply_settings( &$s, $args ) {
		if ( isset( $args['rounds'] ) && in_array( (int) $args['rounds'], self::ROUND_SET, true ) ) {
			$s['rounds'] = (int) $args['rounds'];
		}
		if ( isset( $args['secs'] ) && in_array( (int) $args['secs'], self::SECS_SET, true ) ) {
			$s['secs'] = (int) $args['secs'];
		}
	}

	// ------------------------------------------------------------------ helpers

	public static function data( $row ) {
		$d = json_decode( (string) ( $row['data'] ?? '' ), true );
		return is_array( $d ) ? $d : array();
	}

	private static function set_data( $id, $d ) {
		global $wpdb;
		$wpdb->update( GC_DB::players(), array( 'data' => wp_json_encode( $d ) ), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	private static function sketch_key( $room_id, $round, $pid ) {
		return 'gc_sk_' . (int) $room_id . '_' . (int) $round . '_' . (int) $pid;
	}

	/** A sketch as sent by the phone: [ [ 'c' => color, 'w' => width, 'p' => "x,y,x,y..." ], ... ]. */
	public static function sketch( $room_id, $round, $pid ) {
		$v = get_transient( self::sketch_key( $room_id, $round, $pid ) );
		return is_array( $v ) ? $v : array();
	}

	/** Check and tidy a sketch from a phone: known colors and widths, points on the 0-999 grid, size caps. */
	public static function clean_sketch( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out   = array();
		$total = 0;
		foreach ( array_slice( (array) $raw, 0, self::MAX_STROKE ) as $st ) {
			if ( ! is_array( $st ) || ! isset( $st['p'] ) ) {
				continue;
			}
			$c = strtoupper( (string) ( $st['c'] ?? '' ) );
			$w = (int) ( $st['w'] ?? 0 );
			if ( ! in_array( $c, self::COLORS, true ) ) {
				$c = self::COLORS[0];
			}
			if ( ! in_array( $w, self::WIDTHS, true ) ) {
				$w = self::WIDTHS[1];
			}
			$nums = array_map( 'intval', explode( ',', preg_replace( '/[^0-9,\-]/', '', (string) $st['p'] ) ) );
			$pts  = array();
			for ( $i = 0; $i + 1 < count( $nums ); $i += 2 ) {
				if ( $total >= self::MAX_POINTS ) {
					break;
				}
				$pts[] = max( 0, min( 999, $nums[ $i ] ) );
				$pts[] = max( 0, min( 749, $nums[ $i + 1 ] ) );
				$total++;
			}
			if ( $pts ) {
				$out[] = array( 'c' => $c, 'w' => $w, 'p' => implode( ',', $pts ) );
			}
			if ( $total >= self::MAX_POINTS ) {
				break;
			}
		}
		return $out;
	}

	/** Lowercase letters and digits, no leading a/an/the, a trailing s dropped. For comparing guesses. */
	public static function norm( $t ) {
		$t = remove_accents( mb_strtolower( (string) $t ) );
		$t = str_replace( '&', ' and ', $t );
		$t = preg_replace( '/[^a-z0-9 ]+/', ' ', $t );
		$t = trim( preg_replace( '/\s+/', ' ', $t ) );
		$t = preg_replace( '/^(a|an|the|some) /', '', $t );
		$t = preg_replace( '/(?<=[a-z]{3})s\b/', '', $t );
		return str_replace( ' ', '', $t );
	}

	/** 'right', 'close' or 'wrong'. */
	public static function judge( $guess, $answers ) {
		$g = self::norm( $guess );
		if ( '' === $g ) {
			return 'wrong';
		}
		$close = false;
		foreach ( $answers as $a ) {
			$a = self::norm( $a );
			if ( '' === $a ) {
				continue;
			}
			if ( $a === $g ) {
				return 'right';
			}
			$len = max( strlen( $a ), strlen( $g ) );
			if ( $len <= 255 && strlen( $a ) >= 4 ) {
				$d = levenshtein( $a, $g );
				if ( $d <= 1 && strlen( $a ) >= 6 ) {
					return 'right'; // One typo in a longer word still counts.
				}
				if ( $d <= max( 2, (int) floor( strlen( $a ) / 4 ) ) ) {
					$close = true;
				}
			}
			if ( strlen( $g ) >= 3 && ( false !== strpos( $a, $g ) || false !== strpos( $g, $a ) ) ) {
				$close = true;
			}
		}
		return $close ? 'close' : 'wrong';
	}

	/** "a rocket ship" -> "_ ______ ____" style mask; with $hint, each word's first letter shows. */
	public static function mask( $w, $hint ) {
		$out   = '';
		$start = true;
		foreach ( preg_split( '//u', (string) $w, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( preg_match( '/[\p{L}\p{N}]/u', $ch ) ) {
				$out  .= ( $hint && $start ) ? mb_strtoupper( $ch ) : '_';
				$start = false;
			} else {
				$out  .= $ch;
				$start = ' ' === $ch || '-' === $ch;
			}
		}
		return $out;
	}

	private function alive_rows( $room ) {
		global $wpdb;
		$p = GC_DB::players();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $p WHERE room_id = %d AND alive = 1 ORDER BY id", (int) $room['id'] ), ARRAY_A ); // phpcs:ignore
	}

	/** Run $fn on a fresh, locked copy of the room and save it. Returns $fn's result. */
	private static function locked( $room, $fn ) {
		if ( ! GC_Room::lock( $room['id'] ) ) {
			return new WP_Error( 'busy', 'Try that again.', array( 'status' => 409 ) );
		}
		$fresh = GC_Room::load( $room['code'] );
		$res   = $fresh ? $fn( $fresh ) : new WP_Error( 'gone', 'This room has closed.', array( 'status' => 404 ) );
		if ( $fresh && ! is_wp_error( $res ) && ! empty( $res['save'] ) ) {
			GC_Room::save( $fresh, false );
		}
		GC_Room::unlock( $room['id'] );
		return $res;
	}

	private function hurry( $room, $phase, $key ) {
		self::locked(
			$room,
			function ( &$fresh ) use ( $phase, $key ) {
				$s = &$fresh['s'];
				if ( $phase !== $s['phase'] || self::phase_key( $s ) !== $key ) {
					return array();
				}
				$now = GC_Room::now_ms();
				$end = $now - ( 'draw' === $phase || 'best' === $phase ? self::GRACE_MS : 0 );
				if ( (int) $s['t1'] > $end ) {
					$s['t1'] = $end;
					return array( 'save' => true );
				}
				return array();
			}
		);
	}

	/** Which round and sketch a phase belongs to, so a late request can't end the wrong one. */
	private static function phase_key( $s ) {
		return (int) $s['round'] * 100 + (int) ( $s['k'] ?? 0 );
	}

	// ------------------------------------------------------------------ game flow

	public function due( $s, $now ) {
		switch ( $s['phase'] ) {
			case 'draw':
			case 'best':
				return $now >= (int) $s['t1'] + self::GRACE_MS;
			case 'show':
			case 'srev':
			case 'board':
				return $now >= (int) $s['t1'];
		}
		return false;
	}

	public function step( &$room ) {
		$s = &$room['s'];
		switch ( $s['phase'] ) {
			case 'draw':
				$this->to_show( $room );
				break;
			case 'show':
				$this->to_srev( $room );
				break;
			case 'srev':
				if ( (int) $s['k'] + 1 < count( (array) $s['queue'] ) ) {
					$this->show( $room, (int) $s['k'] + 1, (int) $s['t1'] );
				} else {
					$this->after_round( $room, (int) $s['t1'] );
				}
				break;
			case 'board':
				$this->next_round( $room, (int) $s['t1'] );
				break;
			case 'best':
				$this->finish_best( $room );
				break;
		}
	}

	private function start( &$room ) {
		global $wpdb;
		$p = GC_DB::players();
		if ( 'lobby' !== $room['s']['phase'] && 'end' !== $room['s']['phase'] ) {
			return new WP_Error( 'started', 'The game already started.', array( 'status' => 409 ) );
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
		if ( $n < 1 ) {
			return new WP_Error( 'empty', 'Nobody has joined yet.', array( 'status' => 409 ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $p SET alive = 1, ans_round = 0, ans = 0, ans_ms = 0, ko_round = 0, best = 0, score = 0, gain = 0, data = NULL WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
		$s             = &$room['s'];
		$s['played']   = (int) ( $s['played'] ?? 0 ) + 1;
		$s['starters'] = $n;
		$s['round']    = 0;
		$s['over']     = false;
		$s['stopped']  = false;
		$s['winners']  = array();
		$s['shown']    = array();
		$s['bestOf']   = array();
		GC_DB::match_start(
			$room,
			$s,
			array(
				'players'    => $n,
				'rounds_set' => (int) $s['rounds'],
				'secs'       => (int) $s['secs'],
				'mode'       => 'points',
			)
		);
		$this->next_round( $room, GC_Room::now_ms() );
		GC_Room::save( $room );
		return true;
	}

	/** New round: every player gets their own secret prompt, then the drawing clock starts. */
	private function next_round( &$room, $at ) {
		$s          = &$room['s'];
		$s['round'] = (int) $s['round'] + 1;
		$bank       = GC_Scribble_Prompts::load();
		if ( ! $bank ) {
			$bank = array( array( 'a cat', array() ), array( 'a house', array() ), array( 'a tree', array() ) );
		}
		$used = array_flip( (array) $s['used'] );
		$free = array();
		foreach ( $bank as $i => $b ) {
			if ( ! isset( $used[ mb_strtolower( $b[0] ) ] ) ) {
				$free[] = $i;
			}
		}
		shuffle( $free );
		$asg = array();
		foreach ( $this->alive_rows( $room ) as $row ) {
			if ( ! $free ) {
				$free = array_keys( $bank ); // Used them all: start over.
				shuffle( $free );
				$s['used'] = array();
			}
			$b     = $bank[ array_pop( $free ) ];
			$d     = self::data( $row );
			$d['w']  = $b[0];
			$d['wr'] = $s['round'];
			self::set_data( $row['id'], $d );
			$asg[ (int) $row['id'] ] = array( $b[0], $b[1] );
			$s['used'][]             = mb_strtolower( $b[0] );
		}
		$s['used']  = array_slice( (array) $s['used'], -400 );
		$s['asg']   = $asg;
		$s['queue'] = array();
		$s['k']     = 0;
		$s['feed']  = array();
		$s['got']   = array();
		$s['phase'] = 'draw';
		$s['t0']    = $at;
		$s['t1']    = $at + (int) $s['secs'] * 1000;
	}

	/** Drawing time is over: line up the sketches that came in (up to 8, in a random order). */
	private function to_show( &$room ) {
		$s     = &$room['s'];
		$r     = (int) $s['round'];
		$queue = array();
		foreach ( $this->alive_rows( $room ) as $row ) {
			$id = (int) $row['id'];
			$d  = self::data( $row );
			if ( ! isset( $s['asg'][ $id ] ) || (int) ( $d['dr'] ?? 0 ) !== $r ) {
				continue;
			}
			$queue[] = array( 'id' => $id, 'n' => (string) $row['name'], 'w' => $s['asg'][ $id ][0], 'acc' => $s['asg'][ $id ][1] );
		}
		shuffle( $queue );
		$s['queue'] = array_slice( $queue, 0, self::PER_ROUND );
		if ( ! $s['queue'] ) {
			$this->after_round( $room, GC_Room::now_ms() );
			return;
		}
		$this->show( $room, 0, GC_Room::now_ms() );
	}

	private function show( &$room, $k, $at ) {
		global $wpdb;
		$s          = &$room['s'];
		$s['k']     = $k;
		$s['feed']  = array();
		$s['got']   = array();
		$s['phase'] = 'show';
		$s['t0']    = $at;
		$s['t1']    = $at + self::SHOW_MS;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . GC_DB::players() . ' SET gain = 0 WHERE room_id = %d', (int) $room['id'] ) ); // phpcs:ignore
	}

	private function to_srev( &$room ) {
		$s          = &$room['s'];
		$q          = $s['queue'][ (int) $s['k'] ];
		$s['shown'][] = array( 'r' => (int) $s['round'], 'id' => (int) $q['id'], 'n' => $q['n'], 'w' => $q['w'], 'g' => count( (array) $s['got'] ) );
		$s['shown'] = array_slice( $s['shown'], -40 );
		$s['phase'] = 'srev';
		$s['t0']    = GC_Room::now_ms();
		$s['t1']    = $s['t0'] + self::SREV_MS;
	}

	private function after_round( &$room, $at ) {
		$s = &$room['s'];
		if ( (int) $s['round'] >= (int) $s['rounds'] ) {
			$this->to_best( $room, $at );
			return;
		}
		$s['phase'] = 'board';
		$s['t0']    = $at;
		$s['t1']    = $at + self::BOARD_MS;
	}

	/** The gallery for Best in show: the sketches most people guessed, up to 8. */
	private function to_best( &$room, $at ) {
		global $wpdb;
		$s     = &$room['s'];
		$shown = (array) ( $s['shown'] ?? array() );
		if ( count( $shown ) < 2 ) {
			$this->to_end( $room, false );
			return;
		}
		shuffle( $shown );
		usort(
			$shown,
			function ( $a, $b ) {
				return (int) $b['g'] <=> (int) $a['g'];
			}
		);
		$gal = array_slice( $shown, 0, self::GALLERY );
		shuffle( $gal );
		$s['gallery'] = $gal;
		$s['phase']   = 'best';
		$s['t0']      = $at;
		$s['t1']      = $at + self::BEST_MS;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . GC_DB::players() . ' SET gain = 0 WHERE room_id = %d', (int) $room['id'] ) ); // phpcs:ignore
	}

	private function finish_best( &$room ) {
		global $wpdb;
		$s     = &$room['s'];
		$gal   = (array) ( $s['gallery'] ?? array() );
		$votes = array_fill( 0, count( $gal ), 0 );
		foreach ( $this->alive_rows( $room ) as $row ) {
			$v = (int) ( self::data( $row )['bv'] ?? 0 ) - 1;
			if ( isset( $votes[ $v ] ) ) {
				$votes[ $v ]++;
			}
		}
		$top  = $votes ? max( $votes ) : 0;
		$best = array();
		if ( $top > 0 ) {
			$p = GC_DB::players();
			foreach ( $votes as $i => $n ) {
				if ( $n === $top ) {
					$best[] = array_merge( $gal[ $i ], array( 'v' => $n ) );
					$wpdb->query( $wpdb->prepare( "UPDATE $p SET score = score + 500, gain = gain + 500, best = best + 1 WHERE id = %d AND room_id = %d", (int) $gal[ $i ]['id'], (int) $room['id'] ) ); // phpcs:ignore
				}
			}
		}
		$s['bestOf'] = array_slice( $best, 0, 3 );
		$s['votes']  = $votes;
		$this->to_end( $room, false );
	}

	private function to_end( &$room, $stopped ) {
		$s            = &$room['s'];
		$s['phase']   = 'end';
		$s['over']    = true;
		$s['stopped'] = (bool) $stopped;
		$s['t0']      = GC_Room::now_ms();
		$s['t1']      = 0;
		$s['winners'] = self::leaders( (int) $room['id'] );
		GC_DB::match_end( $s, $stopped ? 2 : 1, (int) $s['round'], $s['winners'], ! empty( $room['owner_id'] ) ? $this->standings( $room ) : array() );
	}

	public static function leaders( $rid ) {
		global $wpdb;
		$p   = GC_DB::players();
		$top = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(score) FROM $p WHERE room_id = %d", (int) $rid ) ); // phpcs:ignore
		if ( $top <= 0 ) {
			return array();
		}
		return array_values( $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $p WHERE room_id = %d AND score = %d ORDER BY id", (int) $rid, $top ) ) ); // phpcs:ignore
	}

	public function standings( $room ) {
		$rows = GC_Room::players( $room );
		usort(
			$rows,
			function ( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);
		$win = array_flip( array_map( 'mb_strtolower', (array) ( $room['s']['winners'] ?? array() ) ) );
		$out = array();
		foreach ( array_slice( $rows, 0, 50 ) as $r ) {
			$out[] = array(
				'n' => (string) $r['name'],
				's' => (int) $r['score'],
				'w' => isset( $win[ mb_strtolower( (string) $r['name'] ) ] ) ? 1 : 0,
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------ player actions

	public function player_action( &$room, $me, $type, $params ) {
		$s     = $room['s'];
		$round = (int) ( $params['round'] ?? 0 );
		$now   = GC_Room::now_ms();
		$id    = (int) $me['id'];
		if ( 'draw' === $type ) {
			if ( 'draw' !== $s['phase'] || $round !== (int) $s['round'] || $now > (int) $s['t1'] + self::GRACE_MS ) {
				return new WP_Error( 'closed', 'Drawing time is over.', array( 'status' => 409 ) );
			}
			if ( ! isset( $s['asg'][ $id ] ) ) {
				return new WP_Error( 'noprompt', 'You\'ll draw next round. Guess this one!', array( 'status' => 409 ) );
			}
			$sk = self::clean_sketch( $params['strokes'] ?? array() );
			if ( ! $sk ) {
				return new WP_Error( 'empty', 'Draw something first!', array( 'status' => 400 ) );
			}
			set_transient( self::sketch_key( $room['id'], $s['round'], $id ), $sk, 3 * HOUR_IN_SECONDS );
			$d     = self::data( $me );
			$first = (int) ( $d['dr'] ?? 0 ) !== (int) $s['round'];
			$d['dr']   = (int) $s['round'];
			$d['drew'] = (int) ( $d['drew'] ?? 0 ) + ( $first ? 1 : 0 );
			self::set_data( $id, $d );
			if ( ! empty( $params['done'] ) ) {
				$this->maybe_all_drawn( $room );
			}
			return true;
		}
		if ( 'guess' === $type ) {
			return $this->guess( $room, $me, $round, (int) ( $params['k'] ?? -1 ), (string) ( $params['text'] ?? '' ) );
		}
		if ( 'best' === $type ) {
			$c = (int) ( $params['choice'] ?? 0 );
			if ( 'best' !== $s['phase'] || $now > (int) $s['t1'] + self::GRACE_MS ) {
				return new WP_Error( 'closed', 'Voting is over.', array( 'status' => 409 ) );
			}
			if ( ! isset( $s['gallery'][ $c - 1 ] ) ) {
				return new WP_Error( 'bad', 'Pick a drawing.', array( 'status' => 400 ) );
			}
			if ( (int) $s['gallery'][ $c - 1 ]['id'] === $id ) {
				return new WP_Error( 'yours', 'That one is yours! Pick someone else\'s.', array( 'status' => 400 ) );
			}
			$d     = self::data( $me );
			$first = empty( $d['bv'] );
			$d['bv'] = $c;
			self::set_data( $id, $d );
			if ( $first ) {
				$this->maybe_all_best( $room );
			}
			return true;
		}
		return parent::player_action( $room, $me, $type, $params );
	}

	/**
	 * A guess at the sketch on screen. Right: points now, and the room sees "Ben got it!". Close: only the
	 * guesser hears "So close!". Wrong: it goes in the feed on the TV. Runs under the room lock, since it
	 * changes the room's state.
	 */
	private function guess( $room, $me, $round, $k, $raw ) {
		global $wpdb;
		$text = GC_Chat::clean( mb_substr( $raw, 0, 60 ) );
		$text = mb_substr( $text, 0, 40 );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'blocked', 'Try a different guess.', array( 'status' => 400 ) );
		}
		$id  = (int) $me['id'];
		$res = self::locked(
			$room,
			function ( &$fresh ) use ( $id, $me, $round, $k, $text, $wpdb ) {
				$s   = &$fresh['s'];
				$now = GC_Room::now_ms();
				if ( 'show' !== $s['phase'] || (int) $s['round'] !== $round || (int) $s['k'] !== $k || $now > (int) $s['t1'] ) {
					return new WP_Error( 'closed', 'Too late for that one.', array( 'status' => 409 ) );
				}
				$q = $s['queue'][ $k ];
				if ( (int) $q['id'] === $id ) {
					return new WP_Error( 'yours', 'That\'s your drawing! Watch them guess.', array( 'status' => 409 ) );
				}
				foreach ( (array) $s['got'] as $g ) {
					if ( (int) $g['id'] === $id ) {
						return new WP_Error( 'got', 'You already got it!', array( 'status' => 409 ) );
					}
				}
				$verdict = self::judge( $text, array_merge( array( $q['w'] ), (array) $q['acc'] ) );
				if ( 'right' === $verdict ) {
					$left  = max( 0, (int) $s['t1'] - $now );
					$total = max( 1, (int) $s['t1'] - (int) $s['t0'] );
					$pts   = 300 + (int) round( 600 * $left / $total );
					$p     = GC_DB::players();
					$wpdb->query( $wpdb->prepare( "UPDATE $p SET score = score + %d, gain = gain + %d WHERE id = %d", $pts, $pts, $id ) ); // phpcs:ignore
					$wpdb->query( $wpdb->prepare( "UPDATE $p SET score = score + 200, gain = gain + 200 WHERE id = %d AND room_id = %d", (int) $q['id'], (int) $fresh['id'] ) ); // phpcs:ignore
					$d          = self::data( $me );
					$d['right'] = (int) ( $d['right'] ?? 0 ) + 1;
					self::set_data( $id, $d );
					$s['got'][]  = array( 'id' => $id, 'n' => (string) $me['name'], 'p' => $pts );
					$s['feed'][] = array( 'n' => (string) $me['name'], 'ok' => 1 );
				} elseif ( 'wrong' === $verdict ) {
					$s['feed'][] = array( 'n' => (string) $me['name'], 't' => $text );
				}
				$s['feed'] = array_slice( (array) $s['feed'], -self::FEED_MAX );
				return array( 'save' => 'close' !== $verdict, 'v' => $verdict );
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 'right' === $res['v'] ) {
			$this->maybe_all_guessed( $room, $round, $k );
		}
		if ( 'close' === $res['v'] ) {
			return new WP_Error( 'close', 'So close! Keep trying.', array( 'status' => 400 ) );
		}
		return true;
	}

	private function maybe_all_drawn( $room ) {
		$r = (int) $room['s']['round'];
		foreach ( $this->alive_rows( $room ) as $row ) {
			if ( isset( $room['s']['asg'][ (int) $row['id'] ] ) && (int) ( self::data( $row )['dr'] ?? 0 ) !== $r ) {
				return;
			}
		}
		$this->hurry( $room, 'draw', self::phase_key( $room['s'] ) );
	}

	private function maybe_all_guessed( $room, $round, $k ) {
		$fresh = GC_Room::load( $room['code'] );
		if ( ! $fresh || 'show' !== $fresh['s']['phase'] ) {
			return;
		}
		$s      = $fresh['s'];
		$artist = (int) $s['queue'][ $k ]['id'];
		$got    = array_flip( array_map( 'intval', wp_list_pluck( (array) $s['got'], 'id' ) ) );
		foreach ( $this->alive_rows( $fresh ) as $row ) {
			$id = (int) $row['id'];
			if ( $id !== $artist && ! isset( $got[ $id ] ) ) {
				return;
			}
		}
		$this->hurry( $fresh, 'show', $round * 100 + $k );
	}

	private function maybe_all_best( $room ) {
		$gal = (array) ( $room['s']['gallery'] ?? array() );
		$ids = array_flip( array_map( 'intval', wp_list_pluck( $gal, 'id' ) ) );
		foreach ( $this->alive_rows( $room ) as $row ) {
			// Someone whose only choice would be their own drawing can't vote, so don't wait for them.
			if ( empty( self::data( $row )['bv'] ) && ! ( 1 === count( $gal ) && isset( $ids[ (int) $row['id'] ] ) ) ) {
				return;
			}
		}
		$this->hurry( $room, 'best', self::phase_key( $room['s'] ) );
	}

	// ------------------------------------------------------------------ host actions

	public function host_action( &$room, $action, $args ) {
		global $wpdb;
		$p = GC_DB::players();
		$s = &$room['s'];
		switch ( $action ) {
			case 'start':
				return $this->start( $room );
			case 'settings':
				if ( 'lobby' !== $s['phase'] && 'end' !== $s['phase'] ) {
					return new WP_Error( 'playing', 'Change settings between games.', array( 'status' => 409 ) );
				}
				self::apply_settings( $s, (array) $args );
				GC_Room::save( $room );
				return true;
			case 'next':
				// Skip ahead: end the drawing clock, the guessing, the answer or the scores.
				if ( in_array( $s['phase'], array( 'show', 'srev', 'board' ), true ) ) {
					$s['t1'] = GC_Room::now_ms();
					GC_Room::save( $room );
				} elseif ( in_array( $s['phase'], array( 'draw', 'best' ), true ) ) {
					$s['t1'] = GC_Room::now_ms() - self::GRACE_MS;
					GC_Room::save( $room );
				}
				return true;
			case 'end':
				if ( 'lobby' === $s['phase'] || 'end' === $s['phase'] ) {
					return true;
				}
				$this->to_end( $room, true );
				GC_Room::save( $room );
				return true;
			case 'again':
				$wpdb->query( $wpdb->prepare( "UPDATE $p SET alive = 1, ans_round = 0, ans = 0, ans_ms = 0, ko_round = 0, best = 0, score = 0, gain = 0, data = NULL WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
				$s['phase']   = 'lobby';
				$s['round']   = 0;
				$s['over']    = false;
				$s['stopped'] = false;
				$s['winners'] = array();
				$s['queue']   = array();
				$s['shown']   = array();
				$s['gallery'] = array();
				$s['bestOf']  = array();
				$s['asg']     = array();
				$s['t0']      = GC_Room::now_ms();
				$s['t1']      = 0;
				GC_Room::save( $room );
				return true;
		}
		return parent::host_action( $room, $action, $args );
	}

	// ------------------------------------------------------------------ view

	public function view( $room, $rows, $me, $host ) {
		$s     = $room['s'];
		$phase = $s['phase'];
		$r     = (int) $s['round'];
		$rid   = (int) $room['id'];
		$got   = array();
		foreach ( (array) ( $s['got'] ?? array() ) as $g ) {
			$got[ (int) $g['id'] ] = $g;
		}
		$cur    = ( 'show' === $phase || 'srev' === $phase ) && isset( $s['queue'][ (int) $s['k'] ] ) ? $s['queue'][ (int) $s['k'] ] : null;
		$list   = array();
		$in     = 0;
		$alive  = 0;
		$awards = array( 'right' => array( 0, '' ) );
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$d  = self::data( $row );
			if ( 'draw' === $phase ) {
				$done = isset( $s['asg'][ $id ] ) && (int) ( $d['dr'] ?? 0 ) === $r;
			} elseif ( 'show' === $phase ) {
				$done = isset( $got[ $id ] ) || ( $cur && (int) $cur['id'] === $id );
			} elseif ( 'best' === $phase ) {
				$done = ! empty( $d['bv'] );
			} else {
				$done = false;
			}
			$alive += 1;
			$in    += $done ? 1 : 0;
			$item   = array(
				'name'  => (string) $row['name'],
				'in'    => $done,
				'score' => (int) $row['score'],
				'gain'  => (int) $row['gain'],
			);
			if ( $host ) {
				$item['id'] = $id;
			}
			$list[] = $item;
			if ( 'end' === $phase && (int) ( $d['right'] ?? 0 ) > $awards['right'][0] ) {
				$awards['right'] = array( (int) $d['right'], (string) $row['name'] );
			}
		}
		$out = array(
			'phase'   => $phase,
			'round'   => $r,
			'rounds'  => (int) $s['rounds'],
			'secs'    => (int) $s['secs'],
			't0'      => (int) $s['t0'],
			't1'      => (int) $s['t1'],
			'grace'   => self::GRACE_MS,
			'played'  => (int) ( $s['played'] ?? 0 ),
			'players' => $list,
			'alive'   => $alive,
			'locked'  => $in,
			'owned'   => ! empty( $room['owner_id'] ),
			'mode'    => 'points',
		);
		if ( 'draw' === $phase ) {
			$out['drawers'] = count( (array) ( $s['asg'] ?? array() ) );
		}
		if ( $cur ) {
			$hint       = 'srev' === $phase || GC_Room::now_ms() >= (int) $s['t0'] + ( (int) $s['t1'] - (int) $s['t0'] ) / 2;
			$out['cur'] = array(
				'k'      => (int) $s['k'],
				'n'      => count( (array) $s['queue'] ),
				'artist' => $cur['n'],
				'sketch' => self::sketch( $rid, $r, (int) $cur['id'] ),
				'mask'   => self::mask( $cur['w'], false ),
				'got'    => array_values( array_map( function ( $g ) { return array( 'n' => $g['n'], 'p' => (int) $g['p'] ); }, (array) $s['got'] ) ),
			);
			if ( $hint ) {
				$out['cur']['mask'] = self::mask( $cur['w'], true ); // First letters show halfway through.
			}
			$out['feed'] = array_values( array_slice( (array) $s['feed'], -12 ) );
			if ( 'srev' === $phase ) {
				$out['cur']['word'] = $cur['w'];
			}
		}
		if ( 'best' === $phase || 'end' === $phase ) {
			$gal = 'best' === $phase ? (array) ( $s['gallery'] ?? array() ) : (array) ( $s['bestOf'] ?? array() );
			$out['gallery'] = array();
			foreach ( $gal as $i => $g ) {
				$out['gallery'][] = array(
					'artist' => $g['n'],
					'word'   => $g['w'],
					'sketch' => self::sketch( $rid, (int) $g['r'], (int) $g['id'] ),
					'v'      => (int) ( $g['v'] ?? 0 ),
				);
			}
		}
		if ( 'end' === $phase ) {
			$out['winners'] = (array) ( $s['winners'] ?? array() );
			$out['stopped'] = ! empty( $s['stopped'] );
			$out['awards']  = array();
			if ( $awards['right'][0] ) {
				$out['awards'][] = array( 'k' => 'eye', 'name' => $awards['right'][1], 'n' => $awards['right'][0] );
			}
		}
		if ( $me ) {
			$id   = (int) $me['id'];
			$d    = self::data( $me );
			$mine = array(
				'name'  => (string) $me['name'],
				'score' => (int) $me['score'],
				'gain'  => (int) $me['gain'],
				'best'  => (int) $me['best'],
				'right' => (int) ( $d['right'] ?? 0 ),
				'drew'  => (int) ( $d['drew'] ?? 0 ),
			);
			if ( 'draw' === $phase ) {
				$mine['word'] = isset( $s['asg'][ $id ] ) ? (string) $s['asg'][ $id ][0] : '';
				$mine['done'] = (int) ( $d['dr'] ?? 0 ) === $r;
			}
			if ( $cur ) {
				$mine['artist'] = (int) $cur['id'] === $id;
				$mine['got']    = isset( $got[ $id ] ) ? (int) $got[ $id ]['p'] : 0;
				if ( $mine['artist'] || $mine['got'] ) {
					$out['cur']['word'] = $cur['w'];
				}
			}
			if ( 'best' === $phase ) {
				$mine['bv']  = (int) ( $d['bv'] ?? 0 );
				$mine['own'] = array();
				foreach ( (array) $s['gallery'] as $i => $g ) {
					if ( (int) $g['id'] === $id ) {
						$mine['own'][] = $i + 1;
					}
				}
			}
			$out['me'] = $mine;
		}
		return $out;
	}
}
