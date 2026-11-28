<?php
/**
 * Phony Baloney: the TV shows a weird-but-true prompt (a fact with a blank, an odd word, an
 * acronym, a real person, a movie, a strange law). Everyone writes a fake answer that sounds real,
 * then everyone votes for the one they think is true.
 *
 * Phases: lobby -> cat (category card) -> write -> vote -> rev (reveal) -> board (scores) -> cat ...
 * The last round goes rev -> end.
 *
 * Points: +1000 for voting for the truth, +500 to a fake's writer for every player it fools, +125
 * for each LOL sticker other players give a fake during the reveal. The last round counts double
 * when the game has 3 or more rounds.
 *
 * Per-player extras live in the players table's `data` JSON: f (this round's fake), fr (its round),
 * lr (the round this player last gave a LOL), fooled and truths (totals for the end screen).
 * `best` counts LOL stickers received; ans_round/ans hold the vote.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Baloney extends GC_Game {

	const CAT_MS    = 3500;
	const VOTE_MS   = 25000;
	const BOARD_MS  = 6500;
	const GRACE_MS  = 1500;
	const ROUND_SET = array( 5, 7, 10 );
	const SECS_SET  = array( 45, 60, 90 );
	const MAX_FAKES = 8;
	const MIN_FAKES = 3;
	const FAKE_LEN  = 60;
	const TRUTH_PTS = 1000;
	const FOOL_PTS  = 500;
	const LOL_PTS   = 125;

	public function slug() {
		return 'baloney';
	}

	public function name() {
		return 'Phony Baloney';
	}

	public function tagline() {
		return 'Write a fake that sounds real. Find the truth.';
	}

	public function blurb() {
		return 'Weird words, strange laws, odd facts and real people. Everyone writes a believable fake, then the room hunts for the truth.';
	}

	public function tags() {
		return array( '3 to ' . (int) $this->max_players() . ' players', 'About 15 min' );
	}

	public function client_config() {
		$cats = array();
		foreach ( GC_Baloney_Prompts::cats() as $k => $c ) {
			$cats[] = array( 'id' => $k, 'name' => $c[0] );
		}
		return array(
			'cats'   => $cats,
			'rounds' => self::ROUND_SET,
			'secs'   => self::SECS_SET,
			'maxLen' => self::FAKE_LEN,
		);
	}

	/** Everyone plays, even when they join part way through. */
	public function join_alive( $s ) {
		return true;
	}

	public function stats_mode( $s ) {
		return 'points';
	}

	// ------------------------------------------------------------------ create

	public function new_state( $params ) {
		$s = array(
			'phase'  => 'lobby',
			'round'  => 0,
			'rounds' => 5,
			'secs'   => 60,
			'only'   => array(),
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
		if ( isset( $args['cats'] ) && is_array( $args['cats'] ) ) {
			$all  = array_keys( GC_Baloney_Prompts::cats() );
			$pick = array_values( array_intersect( $all, array_map( 'sanitize_key', $args['cats'] ) ) );
			if ( ! $pick ) {
				return new WP_Error( 'nocats', 'Keep at least one category on.', array( 'status' => 400 ) );
			}
			$s['only'] = count( $pick ) === count( $all ) ? array() : $pick;
		}
		return true;
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

	/** Lowercase, letters and digits only, no leading a/an/the/to. For comparing answers. */
	public static function norm( $t ) {
		$t = remove_accents( mb_strtolower( (string) $t ) );
		$t = str_replace( '&', ' and ', $t );
		$t = preg_replace( '/[^a-z0-9 ]+/', ' ', $t );
		$t = trim( preg_replace( '/\s+/', ' ', $t ) );
		$t = preg_replace( '/^(a|an|the|to) /', '', $t );
		return $t;
	}

	/** Every choice looks alike on the TV: capital first letter, no full stop at the end. Case or a period must not give the truth away. */
	public static function tidy( $t ) {
		$t = rtrim( trim( (string) $t ), '.' );
		return mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
	}

	/** Is this fake really the true answer (or close enough to give it away)? */
	public static function too_true( $fake, $answers ) {
		$f = self::norm( $fake );
		if ( '' === $f ) {
			return false;
		}
		foreach ( $answers as $a ) {
			$a = self::norm( $a );
			if ( '' === $a ) {
				continue;
			}
			if ( $a === $f ) {
				return true;
			}
			$len = max( strlen( $a ), strlen( $f ) );
			if ( strlen( $a ) >= 4 && $len <= 255 && levenshtein( $a, $f ) <= max( 1, (int) floor( $len / 7 ) ) ) {
				return true;
			}
			// A short answer said inside the fake ("three hearts" for "three") gives it away too.
			if ( strlen( $a ) >= 4 && substr_count( $a, ' ' ) <= 2 && preg_match( '/(^| )' . preg_quote( $a, '/' ) . '( |$)/', $f ) ) {
				return true;
			}
		}
		return false;
	}

	private static function round_mult( $s ) {
		return ( (int) $s['rounds'] >= 3 && (int) $s['round'] === (int) $s['rounds'] ) ? 2 : 1;
	}

	private function alive_rows( $room ) {
		global $wpdb;
		$p = GC_DB::players();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $p WHERE room_id = %d AND alive = 1 ORDER BY id", (int) $room['id'] ), ARRAY_A ); // phpcs:ignore
	}

	/** Everyone is in: end the phase now. tick() moves it on. */
	private function hurry( $room, $phase ) {
		$r = (int) $room['s']['round'];
		if ( ! GC_Room::lock( $room['id'] ) ) {
			return;
		}
		$fresh = GC_Room::load( $room['code'] );
		if ( $fresh && $phase === $fresh['s']['phase'] && (int) $fresh['s']['round'] === $r ) {
			$now = GC_Room::now_ms();
			if ( (int) $fresh['s']['t1'] > $now - self::GRACE_MS ) {
				$fresh['s']['t1']  = $now - self::GRACE_MS;
				$fresh['s']['all'] = true;
				GC_Room::save( $fresh, false );
			}
		}
		GC_Room::unlock( $room['id'] );
	}

	// ------------------------------------------------------------------ game flow

	public function due( $s, $now ) {
		if ( in_array( $s['phase'], array( 'cat', 'rev', 'board' ), true ) ) {
			return $now >= (int) $s['t1'];
		}
		if ( 'write' === $s['phase'] || 'vote' === $s['phase'] ) {
			return $now >= (int) $s['t1'] + self::GRACE_MS;
		}
		return false;
	}

	public function step( &$room ) {
		$s = &$room['s'];
		switch ( $s['phase'] ) {
			case 'cat':
				$s['phase'] = 'write';
				$s['all']   = false;
				$s['t0']    = (int) $s['t1'];
				$s['t1']    = $s['t0'] + (int) $s['secs'] * 1000;
				break;
			case 'write':
				$this->to_vote( $room );
				break;
			case 'vote':
				$this->resolve( $room );
				break;
			case 'rev':
				if ( ! empty( $s['over'] ) ) {
					$this->to_end( $room, false );
				} else {
					$s['phase'] = 'board';
					$s['t0']    = (int) $s['t1'];
					$s['t1']    = $s['t0'] + self::BOARD_MS;
				}
				break;
			case 'board':
				$this->next_round( $room, (int) $s['t1'] );
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
		$s['opts']     = array();
		$s['p']        = null;
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

	/** Pick a prompt: a category other than last round's, a prompt this room hasn't seen. */
	private static function pick( &$s ) {
		$bank = GC_Baloney_Prompts::load();
		$only = ! empty( $s['only'] ) ? array_flip( (array) $s['only'] ) : null;
		$used = array_flip( array_map( 'intval', (array) $s['used'] ) );
		$last = (string) ( $s['p']['cat'] ?? '' );
		$pool = array();
		$any  = array();
		foreach ( $bank as $id => $p ) {
			if ( ( $only && ! isset( $only[ $p['cat'] ] ) ) || isset( $used[ (int) $id ] ) ) {
				continue;
			}
			$any[ $p['cat'] ][] = $id;
		}
		if ( ! $any ) {
			$s['used'] = array(); // Seen them all: start over.
			foreach ( $bank as $id => $p ) {
				if ( ! $only || isset( $only[ $p['cat'] ] ) ) {
					$any[ $p['cat'] ][] = $id;
				}
			}
		}
		if ( ! $any ) {
			foreach ( $bank as $id => $p ) {
				$any[ $p['cat'] ][] = $id;
			}
		}
		if ( ! $any ) {
			return null;
		}
		$cats = array_keys( $any );
		if ( count( $cats ) > 1 ) {
			$cats = array_values( array_diff( $cats, array( $last ) ) );
		}
		$cat  = $cats[ random_int( 0, count( $cats ) - 1 ) ];
		$pool = $any[ $cat ];
		$id   = $pool[ random_int( 0, count( $pool ) - 1 ) ];
		return array( $id, $bank[ $id ] );
	}

	private function next_round( &$room, $at ) {
		$s          = &$room['s'];
		$s['round'] = (int) $s['round'] + 1;
		$got        = self::pick( $s );
		if ( ! $got ) {
			$this->to_end( $room, true );
			return;
		}
		list( $id, $p ) = $got;
		$cats           = GC_Baloney_Prompts::cats();
		$s['used'][]    = (int) $id;
		$s['used']      = array_slice( $s['used'], -300 );
		$s['p']         = array(
			'cat'  => $p['cat'],
			'catN' => $cats[ $p['cat'] ][0],
			'title' => $p['title'],
			'sub'  => $p['sub'],
			'q'    => '' !== $p['q'] ? $p['q'] : $cats[ $p['cat'] ][1],
			'img'  => $p['img'],
		);
		// The answer stays on the server until the reveal.
		$s['pa']    = array(
			'answer' => $p['answer'],
			'accept' => array_slice( (array) $p['accept'], 0, 20 ),
			'decoys' => array_slice( (array) $p['decoys'], 0, 10 ),
		);
		$s['opts']  = array();
		$s['res']   = null;
		$s['all']   = false;
		$s['phase'] = 'cat';
		$s['t0']    = $at;
		$s['t1']    = $at + self::CAT_MS;
	}

	/** Gather the fakes (same fake from two players = one choice they share), add house fakes and the truth. */
	private function to_vote( &$room ) {
		$s      = &$room['s'];
		$r      = (int) $s['round'];
		$groups = array();
		foreach ( $this->alive_rows( $room ) as $row ) {
			$d = self::data( $row );
			if ( (int) ( $d['fr'] ?? 0 ) !== $r || '' === (string) ( $d['f'] ?? '' ) ) {
				continue;
			}
			$k = self::norm( $d['f'] );
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = array( 't' => (string) $d['f'], 'by' => array() );
			}
			$groups[ $k ]['by'][] = (int) $row['id'];
		}
		$groups = array_values( $groups );
		shuffle( $groups );
		$groups = array_slice( $groups, 0, self::MAX_FAKES );
		$truths = array_merge( array( $s['pa']['answer'] ), (array) $s['pa']['accept'] );
		$have   = array_flip( array_map( array( __CLASS__, 'norm' ), wp_list_pluck( $groups, 't' ) ) );
		$decoys = (array) $s['pa']['decoys'];
		shuffle( $decoys );
		$opts = array();
		foreach ( $groups as $g ) {
			$opts[] = array( 't' => self::tidy( $g['t'] ), 'by' => $g['by'], 'truth' => false, 'house' => false );
		}
		foreach ( $decoys as $d ) {
			if ( count( $opts ) >= self::MIN_FAKES ) {
				break;
			}
			$k = self::norm( $d );
			if ( '' === $k || isset( $have[ $k ] ) || self::too_true( $d, $truths ) ) {
				continue;
			}
			$have[ $k ] = 1;
			$opts[]     = array( 't' => self::tidy( $d ), 'by' => array(), 'truth' => false, 'house' => true );
		}
		$opts[] = array( 't' => self::tidy( $s['pa']['answer'] ), 'by' => array(), 'truth' => true, 'house' => false );
		shuffle( $opts );
		$s['opts']  = $opts;
		$s['all']   = false;
		$s['phase'] = 'vote';
		$s['t0']    = GC_Room::now_ms();
		$s['t1']    = $s['t0'] + self::VOTE_MS;
	}

	/** Count the votes, give out points, and set up the reveal. */
	private function resolve( &$room ) {
		global $wpdb;
		$p     = GC_DB::players();
		$s     = &$room['s'];
		$r     = (int) $s['round'];
		$mult  = self::round_mult( $s );
		$rows  = $this->alive_rows( $room );
		$names = array();
		$gain  = array();
		$stats = array();
		foreach ( $rows as $row ) {
			$names[ (int) $row['id'] ] = (string) $row['name'];
			$gain[ (int) $row['id'] ]  = 0;
			$stats[ (int) $row['id'] ] = array( 'fooled' => 0, 'truth' => 0 );
		}
		$votes = array_fill( 0, count( $s['opts'] ), array() );
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$c  = (int) $row['ans'] - 1;
			if ( (int) $row['ans_round'] !== $r || ! isset( $s['opts'][ $c ] ) ) {
				continue;
			}
			$o           = $s['opts'][ $c ];
			$votes[ $c ][] = $id;
			if ( $o['truth'] ) {
				$gain[ $id ]            += self::TRUTH_PTS * $mult;
				$stats[ $id ]['truth'] = 1;
			} else {
				foreach ( $o['by'] as $a ) {
					if ( isset( $gain[ $a ] ) && $a !== $id ) {
						$gain[ $a ] += self::FOOL_PTS * $mult;
						$stats[ $a ]['fooled']++;
					}
				}
			}
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $p SET gain = 0 WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
		$fool = null;
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$d  = self::data( $row );
			$d['fooled'] = (int) ( $d['fooled'] ?? 0 ) + $stats[ $id ]['fooled'];
			$d['truths'] = (int) ( $d['truths'] ?? 0 ) + $stats[ $id ]['truth'];
			$wpdb->update( $p, array( 'gain' => $gain[ $id ], 'score' => (int) $row['score'] + $gain[ $id ], 'data' => wp_json_encode( $d ) ), array( 'id' => $id ) ); // phpcs:ignore
		}
		// The reveal: each choice with who wrote it and who fell for it. Truth goes last.
		$rev = array();
		foreach ( $s['opts'] as $i => $o ) {
			$fooled = count( $votes[ $i ] );
			$rev[]  = array(
				'i'     => $i,
				't'     => $o['t'],
				'truth' => (bool) $o['truth'],
				'house' => (bool) $o['house'],
				'by'    => array_values( array_map( function ( $a ) use ( $names ) { return $names[ $a ] ?? '?'; }, $o['by'] ) ),
				'votes' => array_values( array_map( function ( $a ) use ( $names ) { return $names[ $a ] ?? '?'; }, $votes[ $i ] ) ),
				'pts'   => $o['truth'] || $o['house'] ? 0 : self::FOOL_PTS * $mult * $fooled,
			);
			if ( ! $o['truth'] && ! $o['house'] && $fooled && ( ! $fool || $fooled > $fool[1] ) ) {
				$fool = array( $o['by'], $fooled );
			}
		}
		usort(
			$rev,
			function ( $a, $b ) {
				return ( (int) $a['truth'] <=> (int) $b['truth'] ) ?: ( count( $a['votes'] ) <=> count( $b['votes'] ) );
			}
		);
		// Only choices somebody picked are worth a reveal card. The truth always gets one.
		$shown = array_values( array_filter( $rev, function ( $o ) { return $o['truth'] || $o['votes']; } ) );
		$s['res'] = array(
			'answer' => self::tidy( $s['pa']['answer'] ),
			'rev'    => $rev,
			'shown'  => count( $shown ),
			'mult'   => $mult,
			'truthN' => count( $votes[ (int) array_search( true, wp_list_pluck( $s['opts'], 'truth' ), true ) ] ?? array() ),
		);
		$over       = $r >= (int) $s['rounds'];
		$s['over']  = $over;
		$s['phase'] = 'rev';
		$s['t0']    = GC_Room::now_ms();
		$s['t1']    = $s['t0'] + min( 26000, 6000 + 2600 * count( $shown ) );
		if ( $over ) {
			$s['winners'] = self::leaders( (int) $room['id'] );
		}
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

	/** Names with the top score (ties share the win). */
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
		if ( 'fake' === $type ) {
			if ( 'write' !== $s['phase'] || $round !== (int) $s['round'] || $now > (int) $s['t1'] + self::GRACE_MS ) {
				return new WP_Error( 'closed', 'Time is up for writing.', array( 'status' => 409 ) );
			}
			$raw  = mb_substr( (string) ( $params['text'] ?? '' ), 0, self::FAKE_LEN * 2 );
			$text = GC_Chat::clean( $raw );
			$text = mb_substr( $text, 0, self::FAKE_LEN );
			if ( '' === trim( $text ) ) {
				return new WP_Error( 'blocked', 'Try a different fake.', array( 'status' => 400 ) );
			}
			if ( self::too_true( $text, array_merge( array( $s['pa']['answer'] ), (array) $s['pa']['accept'] ) ) ) {
				return new WP_Error( 'tootrue', 'Too true! That\'s the real answer. Write a fake one.', array( 'status' => 400 ) );
			}
			$d       = self::data( $me );
			$first   = (int) ( $d['fr'] ?? 0 ) !== (int) $s['round'];
			$d['f']  = $text;
			$d['fr'] = (int) $s['round'];
			self::set_data( $me['id'], $d );
			if ( $first ) {
				$this->maybe_all_written( $room );
			}
			return true;
		}
		if ( 'vote' === $type ) {
			global $wpdb;
			$c = (int) ( $params['choice'] ?? 0 );
			if ( 'vote' !== $s['phase'] || $round !== (int) $s['round'] || $now > (int) $s['t1'] + self::GRACE_MS ) {
				return new WP_Error( 'closed', 'Voting is over.', array( 'status' => 409 ) );
			}
			if ( ! isset( $s['opts'][ $c - 1 ] ) ) {
				return new WP_Error( 'bad', 'Pick one of the answers.', array( 'status' => 400 ) );
			}
			if ( in_array( (int) $me['id'], (array) $s['opts'][ $c - 1 ]['by'], true ) ) {
				return new WP_Error( 'yours', 'That one is yours! Vote for the one you think is real.', array( 'status' => 400 ) );
			}
			$first = (int) $me['ans_round'] !== (int) $s['round'];
			$wpdb->update( GC_DB::players(), array( 'ans_round' => (int) $s['round'], 'ans' => $c, 'ans_ms' => max( 0, $now - (int) $s['t0'] ), 'alive' => 1 ), array( 'id' => (int) $me['id'] ) ); // phpcs:ignore
			if ( $first ) {
				$this->maybe_all_voted( $room );
			}
			return true;
		}
		if ( 'lol' === $type ) {
			return $this->lol( $room, $me, $round, (int) ( $params['choice'] ?? -1 ) );
		}
		return parent::player_action( $room, $me, $type, $params );
	}

	/** A LOL sticker for a fake during the reveal: +125 to whoever wrote it. One per player per round. */
	private function lol( $room, $me, $round, $i ) {
		global $wpdb;
		$s = $room['s'];
		if ( ! in_array( $s['phase'], array( 'rev', 'board' ), true ) || $round !== (int) $s['round'] || ! isset( $s['opts'][ $i ] ) ) {
			return new WP_Error( 'closed', 'Stickers are for the reveal.', array( 'status' => 409 ) );
		}
		$o = $s['opts'][ $i ];
		if ( $o['truth'] || $o['house'] || ! $o['by'] ) {
			return new WP_Error( 'bad', 'Pick a fake a player wrote.', array( 'status' => 400 ) );
		}
		if ( in_array( (int) $me['id'], $o['by'], true ) ) {
			return new WP_Error( 'yours', 'You can\'t sticker your own fake.', array( 'status' => 400 ) );
		}
		$d = self::data( $me );
		if ( (int) ( $d['lr'] ?? 0 ) === (int) $s['round'] ) {
			return new WP_Error( 'done', 'You already gave a sticker this round.', array( 'status' => 409 ) );
		}
		$d['lr'] = (int) $s['round'];
		$d['li'] = $i;
		self::set_data( $me['id'], $d );
		$p   = GC_DB::players();
		$ids = implode( ',', array_map( 'intval', $o['by'] ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $p SET score = score + %d, gain = gain + %d, best = best + 1 WHERE room_id = %d AND id IN ($ids)", self::LOL_PTS, self::LOL_PTS, (int) $room['id'] ) ); // phpcs:ignore
		return true;
	}

	private function maybe_all_written( $room ) {
		$r = (int) $room['s']['round'];
		foreach ( $this->alive_rows( $room ) as $row ) {
			if ( (int) ( self::data( $row )['fr'] ?? 0 ) !== $r ) {
				return;
			}
		}
		$this->hurry( $room, 'write' );
	}

	private function maybe_all_voted( $room ) {
		global $wpdb;
		$p       = GC_DB::players();
		$waiting = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d AND alive = 1 AND ans_round < %d", (int) $room['id'], (int) $room['s']['round'] ) ); // phpcs:ignore
		if ( 0 === $waiting ) {
			$this->hurry( $room, 'vote' );
		}
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
				$ok = self::apply_settings( $s, (array) $args );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				GC_Room::save( $room );
				return true;
			case 'next':
				// Skip the rest of the reveal or the scores, or stop the writing clock.
				if ( in_array( $s['phase'], array( 'rev', 'board', 'cat' ), true ) ) {
					$s['t1'] = GC_Room::now_ms();
					GC_Room::save( $room );
				} elseif ( in_array( $s['phase'], array( 'write', 'vote' ), true ) ) {
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
				$s['opts']    = array();
				$s['res']     = null;
				$s['p']       = null;
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
		$list  = array();
		$in    = 0;
		$total = 0;
		$best  = array( 'lol' => array( 0, '' ), 'fool' => array( 0, '' ), 'truth' => array( 0, '' ) );
		foreach ( $rows as $row ) {
			$d    = self::data( $row );
			$done = false;
			if ( 'write' === $phase ) {
				$done = (int) ( $d['fr'] ?? 0 ) === $r;
			} elseif ( 'vote' === $phase ) {
				$done = (int) $row['ans_round'] === $r;
			}
			$total += (int) $row['alive'] ? 1 : 0;
			$in    += $done ? 1 : 0;
			$item   = array(
				'name'  => (string) $row['name'],
				'in'    => $done,
				'score' => (int) $row['score'],
				'gain'  => (int) $row['gain'],
			);
			if ( $host ) {
				$item['id'] = (int) $row['id'];
			}
			$list[] = $item;
			if ( 'end' === $phase ) {
				foreach ( array( 'lol' => (int) $row['best'], 'fool' => (int) ( $d['fooled'] ?? 0 ), 'truth' => (int) ( $d['truths'] ?? 0 ) ) as $k => $v ) {
					if ( $v > $best[ $k ][0] ) {
						$best[ $k ] = array( $v, (string) $row['name'] );
					}
				}
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
			'alive'   => $total,
			'locked'  => $in,
			'only'    => array_values( (array) ( $s['only'] ?? array() ) ),
			'owned'   => ! empty( $room['owner_id'] ),
			'mode'    => 'points',
			'mult'    => in_array( $phase, array( 'lobby', 'end' ), true ) ? 1 : self::round_mult( $s ),
		);
		if ( ! empty( $s['p'] ) && 'lobby' !== $phase ) {
			$out['p'] = $s['p'];
		}
		if ( 'vote' === $phase ) {
			$out['opts'] = array_map(
				function ( $o ) {
					return $o['t'];
				},
				(array) $s['opts']
			);
		}
		if ( in_array( $phase, array( 'rev', 'board', 'end' ), true ) && ! empty( $s['res'] ) ) {
			$out['res'] = $s['res'];
		}
		if ( 'end' === $phase ) {
			$out['winners'] = (array) ( $s['winners'] ?? array() );
			$out['stopped'] = ! empty( $s['stopped'] );
			$out['awards']  = array();
			if ( $best['lol'][0] ) {
				$out['awards'][] = array( 'k' => 'lol', 'name' => $best['lol'][1], 'n' => $best['lol'][0] );
			}
			if ( $best['fool'][0] ) {
				$out['awards'][] = array( 'k' => 'fool', 'name' => $best['fool'][1], 'n' => $best['fool'][0] );
			}
			if ( $best['truth'][0] ) {
				$out['awards'][] = array( 'k' => 'truth', 'name' => $best['truth'][1], 'n' => $best['truth'][0] );
			}
		}
		if ( $me ) {
			$d    = self::data( $me );
			$mine = array(
				'name'   => (string) $me['name'],
				'score'  => (int) $me['score'],
				'gain'   => (int) $me['gain'],
				'fake'   => (int) ( $d['fr'] ?? 0 ) === $r ? (string) ( $d['f'] ?? '' ) : '',
				'vote'   => (int) $me['ans_round'] === $r ? (int) $me['ans'] : 0,
				'lol'    => (int) ( $d['lr'] ?? 0 ) === $r ? (int) ( $d['li'] ?? -1 ) : -1,
				'lols'   => (int) $me['best'],
				'fooled' => (int) ( $d['fooled'] ?? 0 ),
				'truths' => (int) ( $d['truths'] ?? 0 ),
			);
			if ( in_array( $phase, array( 'vote', 'rev', 'board' ), true ) ) {
				// Which choices are this player's own (they can't vote for those).
				$mine['own'] = array();
				foreach ( (array) $s['opts'] as $i => $o ) {
					if ( in_array( (int) $me['id'], (array) $o['by'], true ) ) {
						$mine['own'][] = $i + 1;
					}
				}
			}
			$out['me'] = $mine;
		}
		return $out;
	}
}
