<?php
/**
 * Custom question packs: a host writes their own Trivia questions and plays them in any room.
 * Packs are private to the host who wrote them (sharing may come later).
 *
 * A question: level (easy, medium, hard), category (optional, the pack name is used when blank),
 * the question, the right answer and three wrong ones.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Packs {

	const MAX_PACKS     = 25;  // Per host.
	const MAX_QUESTIONS = 200; // Per pack.
	const LEVELS        = array( 'easy', 'medium', 'hard' );

	public static function packs() {
		global $wpdb;
		return $wpdb->prefix . 'gc_packs';
	}

	public static function questions() {
		global $wpdb;
		return $wpdb->prefix . 'gc_pack_questions';
	}

	public static function install( $c ) {
		$p = self::packs();
		$q = self::questions();
		dbDelta(
			"CREATE TABLE $p (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			game varchar(32) NOT NULL DEFAULT 'trivia',
			name varchar(60) NOT NULL DEFAULT '',
			count smallint(5) unsigned NOT NULL DEFAULT 0,
			created int(10) unsigned NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE $q (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pack_id bigint(20) unsigned NOT NULL,
			level varchar(8) NOT NULL DEFAULT 'medium',
			cat varchar(40) NOT NULL DEFAULT '',
			q varchar(300) NOT NULL DEFAULT '',
			a1 varchar(120) NOT NULL DEFAULT '',
			a2 varchar(120) NOT NULL DEFAULT '',
			a3 varchar(120) NOT NULL DEFAULT '',
			a4 varchar(120) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY pack_id (pack_id)
			) $c;"
		);
	}

	private static function text( $v, $max ) {
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) ), 0, $max );
	}

	/** The host's packs, newest first. */
	public static function list_for( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, game, name, count, updated FROM ' . self::packs() . ' WHERE user_id = %d ORDER BY updated DESC', (int) $uid ), ARRAY_A ); // phpcs:ignore
		return array_map(
			function ( $r ) {
				return array( 'id' => (int) $r['id'], 'game' => $r['game'], 'name' => $r['name'], 'count' => (int) $r['count'], 'updated' => (int) $r['updated'] );
			},
			(array) $rows
		);
	}

	/** @return array|null The pack row, only when it belongs to this host. */
	public static function owned( $uid, $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::packs() . ' WHERE id = %d AND user_id = %d', (int) $id, (int) $uid ), ARRAY_A ); // phpcs:ignore
		return $r ? $r : null;
	}

	public static function create( $uid, $name ) {
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::packs() . ' WHERE user_id = %d', (int) $uid ) ); // phpcs:ignore
		if ( $n >= self::MAX_PACKS ) {
			return new WP_Error( 'toomany', 'You can have up to ' . self::MAX_PACKS . ' packs. Delete one to make another.', array( 'status' => 409 ) );
		}
		$name = self::text( $name, 60 );
		if ( '' === $name ) {
			return new WP_Error( 'name', 'Give your pack a name.', array( 'status' => 400 ) );
		}
		$wpdb->insert( self::packs(), array( 'user_id' => (int) $uid, 'game' => 'trivia', 'name' => $name, 'count' => 0, 'created' => time(), 'updated' => time() ) ); // phpcs:ignore
		return self::get( $uid, (int) $wpdb->insert_id );
	}

	/** The pack with its questions, for editing. */
	public static function get( $uid, $id ) {
		global $wpdb;
		$p = self::owned( $uid, $id );
		if ( ! $p ) {
			return new WP_Error( 'nopack', 'That pack is gone.', array( 'status' => 404 ) );
		}
		$qs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::questions() . ' WHERE pack_id = %d ORDER BY id', (int) $id ), ARRAY_A ); // phpcs:ignore
		return array(
			'id'        => (int) $p['id'],
			'game'      => $p['game'],
			'name'      => $p['name'],
			'count'     => count( $qs ),
			'max'       => self::MAX_QUESTIONS,
			'questions' => array_map( array( __CLASS__, 'out' ), (array) $qs ),
		);
	}

	private static function out( $r ) {
		return array(
			'level' => $r['level'],
			'cat'   => $r['cat'],
			'q'     => $r['q'],
			'right' => $r['a1'],
			'wrong' => array( $r['a2'], $r['a3'], $r['a4'] ),
		);
	}

	/**
	 * Check one question from the app or the account page.
	 *
	 * @return array|string The clean question, or what's wrong with it.
	 */
	public static function clean( $q ) {
		$q     = (array) $q;
		$level = strtolower( (string) ( $q['level'] ?? 'medium' ) );
		$wrong = array_values( array_map( function ( $w ) {
			return self::text( $w, 120 );
		}, array_slice( (array) ( $q['wrong'] ?? array() ), 0, 3 ) ) );
		$out   = array(
			'level' => in_array( $level, self::LEVELS, true ) ? $level : 'medium',
			'cat'   => self::text( $q['cat'] ?? '', 40 ),
			'q'     => self::text( $q['q'] ?? '', 300 ),
			'right' => self::text( $q['right'] ?? '', 120 ),
			'wrong' => $wrong,
		);
		if ( '' === $out['q'] ) {
			return 'a question is missing its words';
		}
		if ( '' === $out['right'] || count( array_filter( $wrong, 'strlen' ) ) < 3 ) {
			return '"' . mb_substr( $out['q'], 0, 40 ) . '" needs a right answer and three wrong ones';
		}
		$all = array_map( 'mb_strtolower', array_merge( array( $out['right'] ), $wrong ) );
		if ( count( array_unique( $all ) ) < 4 ) {
			return '"' . mb_substr( $out['q'], 0, 40 ) . '" has the same answer twice';
		}
		return $out;
	}

	/** Save a pack: its name and, when given, the whole list of questions (replacing the old list). */
	public static function save( $uid, $id, $name, $questions ) {
		global $wpdb;
		$p = self::owned( $uid, $id );
		if ( ! $p ) {
			return new WP_Error( 'nopack', 'That pack is gone.', array( 'status' => 404 ) );
		}
		$upd = array( 'updated' => time() );
		if ( null !== $name ) {
			$name = self::text( $name, 60 );
			if ( '' === $name ) {
				return new WP_Error( 'name', 'Give your pack a name.', array( 'status' => 400 ) );
			}
			$upd['name'] = $name;
		}
		if ( null !== $questions ) {
			$list = array_values( (array) $questions );
			if ( count( $list ) > self::MAX_QUESTIONS ) {
				return new WP_Error( 'toobig', 'A pack holds up to ' . self::MAX_QUESTIONS . ' questions.', array( 'status' => 400 ) );
			}
			$clean = array();
			foreach ( $list as $i => $q ) {
				$c = self::clean( $q );
				if ( is_string( $c ) ) {
					return new WP_Error( 'question', 'Question ' . ( $i + 1 ) . ': ' . $c . '.', array( 'status' => 400 ) );
				}
				$clean[] = $c;
			}
			$wpdb->delete( self::questions(), array( 'pack_id' => (int) $id ) ); // phpcs:ignore
			foreach ( $clean as $c ) {
				$wpdb->insert( // phpcs:ignore
					self::questions(),
					array(
						'pack_id' => (int) $id,
						'level'   => $c['level'],
						'cat'     => $c['cat'],
						'q'       => $c['q'],
						'a1'      => $c['right'],
						'a2'      => $c['wrong'][0],
						'a3'      => $c['wrong'][1],
						'a4'      => $c['wrong'][2],
					)
				);
			}
			$upd['count'] = count( $clean );
		}
		$wpdb->update( self::packs(), $upd, array( 'id' => (int) $id ) ); // phpcs:ignore
		return self::get( $uid, $id );
	}

	public static function delete( $uid, $id ) {
		global $wpdb;
		if ( ! self::owned( $uid, $id ) ) {
			return new WP_Error( 'nopack', 'That pack is gone.', array( 'status' => 404 ) );
		}
		$wpdb->delete( self::questions(), array( 'pack_id' => (int) $id ) ); // phpcs:ignore
		$wpdb->delete( self::packs(), array( 'id' => (int) $id ) ); // phpcs:ignore
		return true;
	}

	public static function delete_all( $uid ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::packs() . ' WHERE user_id = %d', (int) $uid ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			$wpdb->delete( self::questions(), array( 'pack_id' => (int) $id ) ); // phpcs:ignore
		}
		$wpdb->delete( self::packs(), array( 'user_id' => (int) $uid ) ); // phpcs:ignore
	}

	/**
	 * Questions for a game, in the question bank's shape: id => [level, cat, q, right, w1, w2, w3].
	 * Ids are negative so they never clash with the main bank's.
	 */
	public static function for_game( $pack_id, $fallback_cat ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::questions() . ' WHERE pack_id = %d ORDER BY id', (int) $pack_id ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ -(int) $r['id'] ] = array( $r['level'], '' !== $r['cat'] ? $r['cat'] : $fallback_cat, $r['q'], $r['a1'], $r['a2'], $r['a3'], $r['a4'] );
		}
		return $out;
	}
}
