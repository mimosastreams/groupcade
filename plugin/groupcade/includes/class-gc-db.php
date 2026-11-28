<?php
/**
 * Tables for rooms, players and chat (deleted 2 hours after a room's last activity), plus two
 * small stats tables that are kept (no player names except winners).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_DB {

	const IDLE_SECONDS = 7200; // A room expires 2 h after its last activity.

	public static function rooms() {
		global $wpdb;
		return $wpdb->prefix . 'gc_rooms';
	}

	public static function players() {
		global $wpdb;
		return $wpdb->prefix . 'gc_players';
	}

	public static function chat() {
		global $wpdb;
		return $wpdb->prefix . 'gc_chat';
	}

	/** Stats: one row per game played. */
	public static function matches() {
		global $wpdb;
		return $wpdb->prefix . 'gc_matches';
	}

	/** Stats: one row per room made. */
	public static function room_log() {
		global $wpdb;
		return $wpdb->prefix . 'gc_room_log';
	}

	public static function activate() {
		self::install();
		GC_Page::rewrite();
		flush_rewrite_rules();
		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'gc_cleanup' );
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'gc_db_version' ) !== GC_DB_VERSION . ':' . implode( ',', array_keys( GC_Games::all() ) ) ) {
			self::install();
			GC_Page::rewrite();
			flush_rewrite_rules();
		}
		self::schedule();
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( 'gc_cleanup' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'gc_cleanup' );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$r = self::rooms();
		$p = self::players();
		$h = self::chat();
		$m = self::matches();
		$l = self::room_log();
		dbDelta(
			"CREATE TABLE $r (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code char(4) NOT NULL,
			game varchar(32) NOT NULL DEFAULT '',
			host_hash char(64) NOT NULL,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash char(16) NOT NULL DEFAULT '',
			ver int(10) unsigned NOT NULL DEFAULT 0,
			state longtext NOT NULL,
			created int(10) unsigned NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY updated (updated)
			) $c;"
		);
		// The answer/score columns cover round-based games like Trivia. A game that needs more
		// per-player state can keep JSON in `data`.
		dbDelta(
			"CREATE TABLE $p (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room_id bigint(20) unsigned NOT NULL,
			tok_hash char(64) NOT NULL,
			name varchar(40) NOT NULL,
			alive tinyint(1) NOT NULL DEFAULT 1,
			ans_round smallint(5) unsigned NOT NULL DEFAULT 0,
			ans tinyint(3) unsigned NOT NULL DEFAULT 0,
			ans_ms int(10) unsigned NOT NULL DEFAULT 0,
			ko_round smallint(5) unsigned NOT NULL DEFAULT 0,
			best smallint(5) unsigned NOT NULL DEFAULT 0,
			score int(10) unsigned NOT NULL DEFAULT 0,
			gain int(10) unsigned NOT NULL DEFAULT 0,
			data text NULL,
			joined int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY room_id (room_id),
			KEY tok_hash (tok_hash)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE $h (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room_id bigint(20) unsigned NOT NULL,
			pid bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(40) NOT NULL DEFAULT '',
			msg varchar(400) NOT NULL DEFAULT '',
			t int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY room_id (room_id)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE $m (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game varchar(32) NOT NULL DEFAULT '',
			room_code char(4) NOT NULL DEFAULT '',
			ip_hash char(16) NOT NULL DEFAULT '',
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			started int(10) unsigned NOT NULL DEFAULT 0,
			ended int(10) unsigned NOT NULL DEFAULT 0,
			players smallint(5) unsigned NOT NULL DEFAULT 0,
			rounds_set smallint(5) unsigned NOT NULL DEFAULT 0,
			secs smallint(5) unsigned NOT NULL DEFAULT 0,
			rounds_played smallint(5) unsigned NOT NULL DEFAULT 0,
			winners smallint(5) unsigned NOT NULL DEFAULT 0,
			winner_names varchar(120) NOT NULL DEFAULT '',
			status tinyint(3) unsigned NOT NULL DEFAULT 0,
			mode varchar(16) NOT NULL DEFAULT '',
			standings text NULL,
			PRIMARY KEY  (id),
			KEY started (started),
			KEY game (game),
			KEY owner_id (owner_id)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE $l (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game varchar(32) NOT NULL DEFAULT '',
			room_code char(4) NOT NULL DEFAULT '',
			ip_hash char(16) NOT NULL DEFAULT '',
			created int(10) unsigned NOT NULL DEFAULT 0,
			joins smallint(5) unsigned NOT NULL DEFAULT 0,
			games smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created (created)
			) $c;"
		);
		// Host accounts and their question packs.
		GC_Accounts::install( $c );
		GC_Packs::install( $c );
		update_option( 'gc_db_version', GC_DB_VERSION . ':' . implode( ',', array_keys( GC_Games::all() ) ), false );
	}

	/** Delete rooms idle for 2 hours, with their players and chat. */
	public static function cleanup() {
		global $wpdb;
		$r   = self::rooms();
		$cut = time() - self::IDLE_SECONDS;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $r WHERE updated < %d LIMIT 500", $cut ) ); // phpcs:ignore
		GC_Accounts::cleanup();
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( 'DELETE FROM ' . self::players() . " WHERE room_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . self::chat() . " WHERE room_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM $r WHERE id IN ($in)" ); // phpcs:ignore
		return count( $ids );
	}

	// ------------------------------------------------------------------ stats helpers for games

	/**
	 * Log a game starting. Stores the row id in $s['gid'] so match_end() can close it.
	 *
	 * @param array $room  Room row.
	 * @param array $s     Room state (by reference).
	 * @param array $extra players, rounds_set, secs, mode.
	 */
	public static function match_start( $room, &$s, $extra ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore
			self::matches(),
			array(
				'game'       => (string) $room['game'],
				'room_code'  => (string) $room['code'],
				'ip_hash'    => (string) $room['ip_hash'],
				'owner_id'   => (int) ( $room['owner_id'] ?? 0 ),
				'started'    => time(),
				'players'    => (int) ( $extra['players'] ?? 0 ),
				'rounds_set' => (int) ( $extra['rounds_set'] ?? 0 ),
				'secs'       => (int) ( $extra['secs'] ?? 0 ),
				'mode'       => substr( (string) ( $extra['mode'] ?? '' ), 0, 16 ),
			)
		);
		$s['gid'] = (int) $wpdb->insert_id;
		if ( ! empty( $s['lid'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::room_log() . ' SET games = games + 1 WHERE id = %d', (int) $s['lid'] ) ); // phpcs:ignore
		}
	}

	/**
	 * Close a game's stats row. Status 1 = played to the end, 2 = host ended it.
	 *
	 * @param array $standings Final places for the host's game history: [ [ 'n' => name, 's' => score or
	 *                         rounds survived, 'w' => 1 for a winner ], ... ], best first. Only kept
	 *                         when the room has a signed-in host.
	 */
	public static function match_end( $s, $status, $rounds_played, $winners, $standings = array() ) {
		global $wpdb;
		if ( empty( $s['gid'] ) ) {
			return;
		}
		$w = (array) $winners;
		$wpdb->update( // phpcs:ignore
			self::matches(),
			array(
				'ended'         => time(),
				'rounds_played' => (int) $rounds_played,
				'winners'       => count( $w ),
				'winner_names'  => mb_substr( implode( ', ', $w ), 0, 120 ),
				'status'        => (int) $status,
				'standings'     => $standings ? wp_json_encode( array_slice( (array) $standings, 0, 50 ) ) : null,
			),
			array( 'id' => (int) $s['gid'] )
		);
	}
}
