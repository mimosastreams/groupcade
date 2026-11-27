<?php
/**
 * Trivia: a category card, then a question with 4 answers, everyone answers on their phone.
 *
 * Phases: lobby -> cat (category card) -> q (question) -> rev (answer reveal) -> cat ... -> end.
 *
 * Survival: wrong or no answer = out; if everyone still standing misses, nobody is out; Easy,
 * then Medium, then Hard; last one standing wins; if several survive the last round, they all
 * win. A game started with one player is practice: one miss ends it.
 *
 * Points: nobody is knocked out. 5 seconds to read, then a right answer scores 500 plus up to
 * 500 for speed. Most points after the last round wins.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Trivia extends GC_Game {

	const CAT_MS      = 3500;
	const REV_MS      = 7000;
	const GRACE_MS    = 1200; // Answers sent right as the clock hits 0 still count.
	const READ_MS     = 5000; // Points mode: 5 s to read before the scoring clock starts; locking in early = full speed points.
	const ROUND_SET   = array( 10, 15, 20, 0 ); // 0 = until one is left (capped at 50).
	const SECS_SET    = array( 15, 20, 30 );
	const MAX_ENDLESS = 50;

	public function slug() {
		return 'trivia';
	}

	public function name() {
		return 'Trivia';
	}

	public function tagline() {
		return 'Answer on your phone. Survival or Points.';
	}

	public function blurb() {
		return '13 categories and questions that get harder as you go. Last one standing wins, or play for points.';
	}

	public function tags() {
		return array( 'Up to ' . (int) $this->max_players() . ' players', 'Survival or Points' );
	}

	public function client_config() {
		return array(
			'cats'     => GC_Trivia_Bank::categories(),
			'specCats' => GC_Trivia_Bank::specials(),
			'rounds'   => self::ROUND_SET,
			'secs'     => self::SECS_SET,
			'modes'    => array( 'survive', 'points' ),
		);
	}

	public static function points( $s ) {
		return isset( $s['mode'] ) && 'points' === $s['mode'];
	}

	public function stats_mode( $s ) {
		return self::points( $s ) ? 'points' : 'survive';
	}

	public function join_alive( $s ) {
		return 'lobby' === $s['phase'] || self::points( $s );
	}

	// ------------------------------------------------------------------ create

	public function new_state( $params ) {
		$rounds = isset( $params['rounds'] ) ? (int) $params['rounds'] : 15;
		$secs   = isset( $params['secs'] ) ? (int) $params['secs'] : 20;
		if ( ! in_array( $rounds, self::ROUND_SET, true ) ) {
			$rounds = 15;
		}
		if ( ! in_array( $secs, self::SECS_SET, true ) ) {
			$secs = 20;
		}
		$s = array(
			'phase'  => 'lobby',
			'round'  => 0,
			'rounds' => $rounds,
			'secs'   => $secs,
			'used'   => array(),
			'cats'   => array(),
			'only'   => array(),
			'mode'   => ( isset( $params['mode'] ) && 'points' === $params['mode'] ) ? 'points' : 'survive',
		);
		if ( self::points( $s ) && ! $s['rounds'] ) {
			$s['rounds'] = 15; // Points games always have a set number of rounds.
		}
		if ( isset( $params['cats'] ) && is_array( $params['cats'] ) ) {
			$pick = GC_Trivia_Bank::only_from( $params['cats'] );
			if ( $pick ) {
				$s['only'] = $pick;
			}
		}
		return $s;
	}

	// ------------------------------------------------------------------ game flow

	public function due( $s, $now ) {
		if ( 'cat' === $s['phase'] || 'rev' === $s['phase'] ) {
			return $now >= (int) $s['t1'];
		}
		if ( 'q' === $s['phase'] ) {
			return $now >= (int) $s['t1'] + self::GRACE_MS;
		}
		return false;
	}

	public function step( &$room ) {
		$s = &$room['s'];
		if ( 'cat' === $s['phase'] ) {
			$s['phase'] = 'q';
			$s['t0']    = (int) $s['t1'] + ( self::points( $s ) ? self::READ_MS : 0 );
			$s['t1']    = $s['t0'] + (int) $s['secs'] * 1000;
		} elseif ( 'q' === $s['phase'] ) {
			$this->resolve( $room );
		} elseif ( 'rev' === $s['phase'] ) {
			if ( ! empty( $s['over'] ) ) {
				$s['phase'] = 'end';
				$s['t0']    = GC_Room::now_ms();
				$s['t1']    = 0;
				GC_DB::match_end( $s, 1, (int) $s['round'], $s['winners'] ?? array() );
			} else {
				$this->next_round( $room, (int) $s['t1'] );
			}
		}
	}

	private function start( &$room ) {
		global $wpdb;
		$p = GC_DB::players();
		if ( 'lobby' !== $room['s']['phase'] ) {
			return new WP_Error( 'started', 'The game already started.', array( 'status' => 409 ) );
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d AND alive = 1", (int) $room['id'] ) ); // phpcs:ignore
		if ( $n < 1 ) {
			return new WP_Error( 'empty', 'Nobody has joined yet.', array( 'status' => 409 ) );
		}
		$s             = &$room['s'];
		$s['played']   = (int) ( $s['played'] ?? 0 ) + 1;
		$s['starters'] = $n;
		$s['round']    = 0;
		$s['over']     = false;
		$s['winners']  = array();
		$s['last']     = null;
		$s['cats']     = array();
		GC_DB::match_start(
			$room,
			$s,
			array(
				'players'    => $n,
				'rounds_set' => (int) $s['rounds'],
				'secs'       => (int) $s['secs'],
				'mode'       => $this->stats_mode( $s ),
			)
		);
		$this->next_round( $room, GC_Room::now_ms() );
		GC_Room::save( $room );
		return true;
	}

	private function next_round( &$room, $at ) {
		$s          = &$room['s'];
		$s['round'] = (int) $s['round'] + 1;
		$level      = GC_Trivia_Bank::level_for( $s['round'], (int) $s['rounds'] );
		$only       = isset( $s['only'] ) ? (array) $s['only'] : array();
		$ctx        = array(
			'used'        => $s['used'],
			'recent_cats' => array_slice( (array) $s['cats'], -3 ),
			'allowed'     => $only,
			'rights'      => isset( $s['rights'] ) ? (array) $s['rights'] : array(),
			'shown'       => isset( $s['shown'] ) ? (array) $s['shown'] : array(),
		);
		$qid        = GC_Trivia_Bank::pick( $level, $ctx );
		if ( ! $qid ) {
			// Bank used up (very long session): forget what was used and go again.
			$s['used'] = array();
			$qid       = GC_Trivia_Bank::pick( $level, array( 'allowed' => $only ) );
		}
		if ( ! $qid ) {
			$qid = GC_Trivia_Bank::pick( $level );
		}
		$q     = GC_Trivia_Bank::get( $qid );
		$order = array( 0, 1, 2, 3 );
		shuffle( $order );
		$s['qid']    = $qid;
		$s['level']  = $q[0];
		$s['cat']    = $q[1];
		$s['order']  = $order; // Shown slot i holds answer $order[i]; answer 0 is the right one.
		$s['right']  = (int) array_search( 0, $order, true );
		$s['used'][] = $qid;
		// Answers seen in this room, so later questions don't give away or repeat them.
		$s['rights'] = array_slice( array_merge( isset( $s['rights'] ) ? (array) $s['rights'] : array(), array( GC_Trivia_Bank::norm( $q[3] ) ) ), -200 );
		$s['shown']  = array_slice( array_merge( isset( $s['shown'] ) ? (array) $s['shown'] : array(), array_map( array( 'GC_Trivia_Bank', 'norm' ), array( $q[3], $q[4], $q[5], $q[6] ) ) ), -800 );
		$s['used']   = array_slice( $s['used'], -600 );
		$s['cats'][] = $q[1];
		$s['cats']   = array_slice( $s['cats'], -6 );
		$s['phase']  = 'cat';
		$s['t0']     = $at;
		$s['t1']     = $at + self::CAT_MS;
	}

	private function resolve( &$room ) {
		global $wpdb;
		$p     = GC_DB::players();
		$s     = &$room['s'];
		$rid   = (int) $room['id'];
		$r     = (int) $s['round'];
		$right = (int) $s['right'] + 1; // Stored 1-4; 0 = no answer.
		if ( self::points( $s ) ) {
			$this->resolve_points( $room, $right );
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

		$left      = $standing - count( $out );
		$rounds    = (int) $s['rounds'] > 0 ? (int) $s['rounds'] : self::MAX_ENDLESS;
		$solo      = (int) $s['starters'] < 2;
		$over      = $r >= $rounds || 0 === $left || ( ! $solo && $left <= 1 );
		$names     = wp_list_pluck( $out, 'name' );
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
		$s['over'] = $over;
		if ( $over ) {
			$s['winners'] = $left > 0 ? $this->standing_names( $rid ) : array();
		}
		$s['phase'] = 'rev';
		$s['t0']    = GC_Room::now_ms();
		$s['t1']    = $s['t0'] + self::REV_MS;
	}

	private function standing_names( $rid ) {
		global $wpdb;
		$p = GC_DB::players();
		return array_values( $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $p WHERE room_id = %d AND alive = 1 ORDER BY id", (int) $rid ) ) ); // phpcs:ignore
	}

	private function resolve_points( &$room, $right ) {
		global $wpdb;
		$p      = GC_DB::players();
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
		$s['t0']    = GC_Room::now_ms();
		$s['t1']    = $s['t0'] + self::REV_MS;
	}

	/** Names with the top score (ties share the win). Empty if nobody scored. */
	public static function points_leaders( $rid ) {
		global $wpdb;
		$p   = GC_DB::players();
		$top = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(score) FROM $p WHERE room_id = %d", (int) $rid ) ); // phpcs:ignore
		if ( $top <= 0 ) {
			return array();
		}
		return array_values( $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $p WHERE room_id = %d AND score = %d ORDER BY id", (int) $rid, $top ) ) ); // phpcs:ignore
	}

	// ------------------------------------------------------------------ player actions

	public function player_action( &$room, $me, $type, $params ) {
		if ( 'answer' !== $type ) {
			return parent::player_action( $room, $me, $type, $params );
		}
		return $this->answer( $room, $me, (int) ( $params['round'] ?? 0 ), (int) ( $params['choice'] ?? 0 ) );
	}

	/** Lock in an answer (1-4) for the current round. */
	private function answer( $room, $player, $round, $choice ) {
		global $wpdb;
		$s   = $room['s'];
		$now = GC_Room::now_ms();
		if ( 'q' !== $s['phase'] || (int) $round !== (int) $s['round'] || $now > (int) $s['t1'] + self::GRACE_MS ) {
			return new WP_Error( 'closed', 'Too late for this question.', array( 'status' => 409 ) );
		}
		if ( ! (int) $player['alive'] ) {
			return new WP_Error( 'out', 'You are out this game.', array( 'status' => 409 ) );
		}
		if ( $choice < 1 || $choice > 4 ) {
			return new WP_Error( 'bad', 'Pick A, B, C or D.', array( 'status' => 400 ) );
		}
		$p  = GC_DB::players();
		$ms = max( 0, $now - (int) $s['t0'] );
		// First answer counts; no changing it.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $p SET ans_round = %d, ans = %d, ans_ms = %d WHERE id = %d AND alive = 1 AND ans_round < %d", (int) $s['round'], $choice, $ms, (int) $player['id'], (int) $s['round'] ) ); // phpcs:ignore
		if ( ! $n ) {
			return new WP_Error( 'locked', 'You already locked in.', array( 'status' => 409 ) );
		}
		$this->maybe_all_in( $room );
		return true;
	}

	/** Everyone still standing has locked in: stop the clock now instead of waiting it out. */
	private function maybe_all_in( $room ) {
		global $wpdb;
		$p       = GC_DB::players();
		$r       = (int) $room['s']['round'];
		$waiting = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $p WHERE room_id = %d AND alive = 1 AND ans_round < %d", (int) $room['id'], $r ) ); // phpcs:ignore
		if ( $waiting > 0 || ! GC_Room::lock( $room['id'] ) ) {
			return;
		}
		$fresh = GC_Room::load( $room['code'] );
		if ( $fresh && 'q' === $fresh['s']['phase'] && (int) $fresh['s']['round'] === $r ) {
			$now = GC_Room::now_ms();
			if ( (int) $fresh['s']['t1'] > $now ) {
				$fresh['s']['t1']  = $now - self::GRACE_MS; // Due right away; tick() resolves it.
				$fresh['s']['all'] = true;
				GC_Room::save( $fresh, false );
			}
		}
		GC_Room::unlock( $room['id'] );
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
					$pick = GC_Trivia_Bank::only_from( $args['cats'] );
					if ( null === $pick ) {
						return new WP_Error( 'nocats', 'Keep at least one category on.', array( 'status' => 400 ) );
					}
					$s['only'] = $pick;
				}
				GC_Room::save( $room );
				return true;
			case 'next':
				// Skip the rest of the reveal.
				if ( 'rev' === $s['phase'] ) {
					$s['t1'] = GC_Room::now_ms();
					GC_Room::save( $room );
				}
				return true;
			case 'end':
				if ( 'lobby' === $s['phase'] || 'end' === $s['phase'] ) {
					return true;
				}
				$s['phase']   = 'end';
				$s['over']    = true;
				$s['t0']      = GC_Room::now_ms();
				$s['t1']      = 0;
				$s['winners'] = self::points( $s ) ? self::points_leaders( (int) $room['id'] ) : $this->standing_names( (int) $room['id'] );
				$s['stopped'] = true;
				GC_DB::match_end( $s, 2, (int) $s['round'], $s['winners'] );
				GC_Room::save( $room );
				return true;
			case 'again':
				$wpdb->query( $wpdb->prepare( "UPDATE $p SET alive = 1, ans_round = 0, ans = 0, ans_ms = 0, ko_round = 0, best = 0, score = 0, gain = 0 WHERE room_id = %d", (int) $room['id'] ) ); // phpcs:ignore
				$s['phase']   = 'lobby';
				$s['round']   = 0;
				$s['over']    = false;
				$s['stopped'] = false;
				$s['winners'] = array();
				$s['last']    = null;
				$s['t0']      = GC_Room::now_ms();
				$s['t1']      = 0;
				GC_Room::save( $room );
				return true;
		}
		return parent::host_action( $room, $action, $args );
	}

	// ------------------------------------------------------------------ view

	/** What a phone, the host or the TV sees. The right answer only appears in the reveal. */
	public function view( $room, $rows, $me, $host ) {
		$s      = $room['s'];
		$list   = array();
		$alive  = 0;
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
			'phase'   => $s['phase'],
			'round'   => (int) $s['round'],
			'rounds'  => (int) $s['rounds'],
			'secs'    => (int) $s['secs'],
			't0'      => (int) $s['t0'],
			't1'      => (int) $s['t1'],
			'grace'   => self::GRACE_MS,
			'played'  => (int) ( $s['played'] ?? 0 ),
			'players' => $list,
			'alive'   => $alive,
			'locked'  => $locked,
			'mode'    => self::points( $s ) ? 'points' : 'survive',
			'only'    => isset( $s['only'] ) ? array_values( (array) $s['only'] ) : array(),
		);
		if ( $host && in_array( $s['phase'], array( 'lobby', 'end' ), true ) ) {
			$out['allCats']  = GC_Trivia_Bank::categories();
			$out['specCats'] = GC_Trivia_Bank::specials();
		}
		if ( in_array( $s['phase'], array( 'cat', 'q', 'rev' ), true ) || ( 'end' === $s['phase'] && ! empty( $s['qid'] ) ) ) {
			$out['level'] = $s['level'];
			$out['cat']   = $s['cat'];
			$out['cats']  = ! empty( $s['only'] ) ? array_values( $s['only'] ) : GC_Trivia_Bank::categories();
		}
		if ( in_array( $s['phase'], array( 'q', 'rev' ), true ) || ( 'end' === $s['phase'] && ! empty( $s['last'] ) ) ) {
			$q = GC_Trivia_Bank::get( (int) $s['qid'] );
			if ( $q ) {
				$ans            = array( $q[3], $q[4], $q[5], $q[6] );
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
