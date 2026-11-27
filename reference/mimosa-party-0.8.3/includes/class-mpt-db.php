<?php
/**
 * Tables for party rooms and players. Rooms are deleted 2 hours after their last activity,
 * so nothing is kept long term. Nothing here touches the stream Survive Trivia tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MPT_DB {

	const IDLE_SECONDS = 7200; // Room expires 2 h after the last activity.

	public static function rooms() {
		global $wpdb;
		return $wpdb->prefix . 'mpt_rooms';
	}

	public static function players() {
		global $wpdb;
		return $wpdb->prefix . 'mpt_players';
	}

	public static function games() {
		global $wpdb;
		return $wpdb->prefix . 'mpt_games';
	}

	public static function room_log() {
		global $wpdb;
		return $wpdb->prefix . 'mpt_room_log';
	}

	public static function activate() {
		self::install();
		MPT_Page::rewrite();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( 'mpt_cleanup' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'mpt_cleanup' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'mpt_cleanup' );
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'mpt_db_version' ) !== MPT_DB_VERSION ) {
			self::install();
			MPT_Page::rewrite();
			flush_rewrite_rules();
		}
		if ( ! wp_next_scheduled( 'mpt_cleanup' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'mpt_cleanup' );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$r = self::rooms();
		$p = self::players();
		dbDelta(
			"CREATE TABLE $r (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code char(4) NOT NULL,
			host_hash char(64) NOT NULL,
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
			joined int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY room_id (room_id),
			KEY tok_hash (tok_hash)
			) $c;"
		);
		// Stats (kept after rooms expire; no player names except winners).
		$g = self::games();
		$l = self::room_log();
		dbDelta(
			"CREATE TABLE $g (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room_code char(4) NOT NULL DEFAULT '',
			ip_hash char(16) NOT NULL DEFAULT '',
			started int(10) unsigned NOT NULL DEFAULT 0,
			ended int(10) unsigned NOT NULL DEFAULT 0,
			players smallint(5) unsigned NOT NULL DEFAULT 0,
			rounds_set smallint(5) unsigned NOT NULL DEFAULT 0,
			secs smallint(5) unsigned NOT NULL DEFAULT 0,
			rounds_played smallint(5) unsigned NOT NULL DEFAULT 0,
			winners smallint(5) unsigned NOT NULL DEFAULT 0,
			winner_names varchar(120) NOT NULL DEFAULT '',
			status tinyint(3) unsigned NOT NULL DEFAULT 0,
			mode varchar(8) NOT NULL DEFAULT 'survive',
			PRIMARY KEY  (id),
			KEY started (started)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE $l (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room_code char(4) NOT NULL DEFAULT '',
			ip_hash char(16) NOT NULL DEFAULT '',
			created int(10) unsigned NOT NULL DEFAULT 0,
			joins smallint(5) unsigned NOT NULL DEFAULT 0,
			games smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created (created)
			) $c;"
		);
		$ch = MPT_Chat::table();
		dbDelta(
			"CREATE TABLE $ch (
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
		update_option( 'mpt_db_version', MPT_DB_VERSION, false );
	}

	/** Delete rooms idle for 2 hours, plus their players. */
	public static function cleanup() {
		global $wpdb;
		$r   = self::rooms();
		$p   = self::players();
		$cut = time() - self::IDLE_SECONDS;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $r WHERE updated < %d LIMIT 500", $cut ) ); // phpcs:ignore
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM $p WHERE room_id IN ($in)" ); // phpcs:ignore
		MPT_Chat::delete_rooms( $in );
		$wpdb->query( "DELETE FROM $r WHERE id IN ($in)" ); // phpcs:ignore
		return count( $ids );
	}
}
