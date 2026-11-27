<?php
/**
 * Question bank: data/questions.txt (a copy of the stream bank without the MIMOSA category).
 * Format: Easy|Medium|Hard | Category | Question | Right | Wrong | Wrong | Wrong
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MPT_Bank {

	private static $all = null;

	/** @return array id => [level, cat, q, right, w1, w2, w3] */
	public static function all() {
		if ( null !== self::$all ) {
			return self::$all;
		}
		self::$all = array();
		$lines     = @file( MPT_DIR . 'data/questions.txt', FILE_IGNORE_NEW_LINES ); // phpcs:ignore
		if ( ! $lines ) {
			return self::$all;
		}
		$id = 0;
		foreach ( $lines as $line ) {
			$id++;
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$f = array_map( 'trim', explode( '|', $line ) );
			if ( count( $f ) !== 7 ) {
				continue;
			}
			$lvl = strtolower( $f[0] );
			if ( ! in_array( $lvl, array( 'easy', 'medium', 'hard' ), true ) ) {
				continue;
			}
			if ( in_array( strtolower( $f[1] ), array( 'mimosa', 'call of duty' ), true ) ) {
				continue; // Left out of party mode (Dylan 2026-11-26). Lines stay in the file so question ids don't shift.
			}
			self::$all[ $id ] = array( $lvl, $f[1], $f[2], $f[3], $f[4], $f[5], $f[6] );
		}
		return self::$all;
	}

	public static function get( $id ) {
		$a = self::all();
		return isset( $a[ $id ] ) ? $a[ $id ] : null;
	}

	/** Level for a round: first ~30% easy, next ~35% medium, rest hard. Endless games: 1-3 easy, 4-7 medium. */
	public static function level_for( $round, $rounds ) {
		if ( $rounds <= 0 ) {
			return $round <= 3 ? 'easy' : ( $round <= 7 ? 'medium' : 'hard' );
		}
		if ( $round <= (int) ceil( $rounds * 0.3 ) ) {
			return 'easy';
		}
		if ( $round <= (int) ceil( $rounds * 0.65 ) ) {
			return 'medium';
		}
		return 'hard';
	}

	/**
	 * Pick a question: right level, a category not used in the last 3 rounds, never repeated in this room.
	 * Falls back to the nearest level, then any category.
	 */
	public static function norm( $a ) {
		return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $a ) ) );
	}

	/**
	 * Pick a question. Hard rules: right level band, allowed categories, never repeated in this room.
	 * Soft rules, dropped one at a time when nothing fits: skip questions asked in any room lately
	 * (site-wide), skip any whose answers repeat an earlier right answer in this room (or whose right
	 * answer was already shown as a choice), and avoid the last 3 categories.
	 *
	 * @param array $ctx used (ids), recent_cats, allowed, rights (normalized right answers so far),
	 *                   shown (normalized answers shown so far).
	 */
	public static function pick( $level, $ctx = array() ) {
		$all     = self::all();
		$used    = array_flip( array_map( 'intval', (array) ( $ctx['used'] ?? array() ) ) );
		$allow   = ! empty( $ctx['allowed'] ) ? array_flip( (array) $ctx['allowed'] ) : null;
		$rcats   = (array) ( $ctx['recent_cats'] ?? array() );
		$rights  = array_flip( (array) ( $ctx['rights'] ?? array() ) );
		$shown   = array_flip( (array) ( $ctx['shown'] ?? array() ) );
		$global  = array_flip( array_map( 'intval', (array) get_option( 'mpt_recent', array() ) ) );
		$order   = array(
			'easy'   => array( 'easy', 'medium', 'hard' ),
			'medium' => array( 'medium', 'easy', 'hard' ),
			'hard'   => array( 'hard', 'medium', 'easy' ),
		);
		// [skip global recent, skip repeated answers, avoid recent categories]
		$passes = array( array( 1, 1, 1 ), array( 0, 1, 1 ), array( 0, 1, 0 ), array( 0, 0, 1 ), array( 0, 0, 0 ) );
		foreach ( $passes as $pass ) {
			foreach ( $order[ $level ] as $lvl ) {
				$by_cat = array();
				foreach ( $all as $id => $q ) {
					if ( $q[0] !== $lvl || isset( $used[ $id ] ) || ( $allow && ! isset( $allow[ $q[1] ] ) ) || ( ! $allow && in_array( $q[1], self::SPECIALTY, true ) ) ) {
						continue;
					}
					if ( $pass[0] && isset( $global[ $id ] ) ) {
						continue;
					}
					if ( $pass[1] ) {
						if ( isset( $shown[ self::norm( $q[3] ) ] ) ) {
							continue;
						}
						$clash = false;
						foreach ( array( 3, 4, 5, 6 ) as $k ) {
							if ( isset( $rights[ self::norm( $q[ $k ] ) ] ) ) {
								$clash = true;
								break;
							}
						}
						if ( $clash ) {
							continue;
						}
					}
					if ( $pass[2] && in_array( $q[1], $rcats, true ) ) {
						continue;
					}
					$by_cat[ $q[1] ][] = $id;
				}
				if ( $by_cat ) {
					$cats = array_keys( $by_cat );
					$cat  = $cats[ random_int( 0, count( $cats ) - 1 ) ];
					$ids  = $by_cat[ $cat ];
					$pick = $ids[ random_int( 0, count( $ids ) - 1 ) ];
					self::remember( $pick );
					return $pick;
				}
			}
		}
		return 0;
	}

	/** Site-wide memory of the last 350 questions asked, so back-to-back rooms don't repeat. */
	private static function remember( $id ) {
		$r   = (array) get_option( 'mpt_recent', array() );
		$r[] = (int) $id;
		update_option( 'mpt_recent', array_slice( $r, -350 ), false );
	}

	/** Specialty categories: off unless the host turns them on (Dylan 2026-11-26). */
	const SPECIALTY = array( 'Christmas Movies' );

	/** Regular categories (what "all" means). */
	public static function categories() {
		$c = array();
		foreach ( self::all() as $q ) {
			if ( ! in_array( $q[1], self::SPECIALTY, true ) ) {
				$c[ $q[1] ] = true;
			}
		}
		return array_keys( $c );
	}

	/** Specialty categories that have questions. */
	public static function specials() {
		$c = array();
		foreach ( self::all() as $q ) {
			if ( in_array( $q[1], self::SPECIALTY, true ) ) {
				$c[ $q[1] ] = true;
			}
		}
		return array_keys( $c );
	}

	/** Turn a requested list into the room's "only" list: empty means every regular category. */
	public static function only_from( $cats ) {
		$reg  = self::categories();
		$pick = array_values( array_intersect( array_merge( $reg, self::specials() ), array_map( 'strval', (array) $cats ) ) );
		if ( ! $pick ) {
			return null;
		}
		$same = count( $pick ) === count( $reg ) && ! array_diff( $reg, $pick );
		return $same ? array() : $pick;
	}
}
