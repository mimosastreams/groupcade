<?php
/**
 * Base class for a Groupcade game.
 *
 * The core (GC_Room, GC_Rest, GC_Page) handles room codes, the host token, players joining and
 * leaving, kicking, chat, rate limits, cleanup and the TV page. A game only decides what its room
 * state looks like and how it moves forward. Games move forward lazily: every request calls
 * GC_Room::tick(), which asks the game whether a phase is due() and, if so, calls step().
 *
 * Every game's state must hold at least 'phase' ('lobby' and 'end' are the two phases the core
 * knows about: host settings and kicking are allowed there), 't0' and 't1' (ms timestamps).
 *
 * See docs/ADDING-A-GAME.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class GC_Game {

	/** Short id used in URLs, REST calls and the rooms table, e.g. 'trivia'. Lowercase letters only. */
	abstract public function slug();

	/** Display name, e.g. 'Trivia'. */
	abstract public function name();

	/** One line for the game picker. */
	public function tagline() {
		return '';
	}

	/** A sentence or two for the game's card on the home page. */
	public function blurb() {
		return $this->tagline();
	}

	/** Two or three short tags for the home page card, such as "Up to 50 players". */
	public function tags() {
		return array( 'Up to ' . (int) $this->max_players() . ' players' );
	}

	/** Page path on the site: /<path>/ and /<path>/CODE. Defaults to the slug. */
	public function path() {
		return $this->slug();
	}

	public function max_players() {
		return GC_Limits::PLAYERS_MAX;
	}

	/** The self-contained HTML client (player, host and TV views). Placeholders: {{CONFIG}} {{QRLIB}} {{LOGO}}. */
	public function client_file() {
		return GC_DIR . 'games/' . $this->slug() . '/assets/app.html';
	}

	/** Extra values for the client's CONFIG object (and for GET /games, which the iPhone app reads). */
	public function client_config() {
		return array();
	}

	/**
	 * State for a new room, from the host's create request. Must set 'phase' => 'lobby'.
	 *
	 * @param array $params Request params (game specific).
	 * @return array|WP_Error
	 */
	abstract public function new_state( $params );

	/** Does someone joining now play straight away (true) or watch until the next game (false)? */
	public function join_alive( $s ) {
		return 'lobby' === $s['phase'];
	}

	/** Is a timed phase over? */
	public function due( $s, $now_ms ) {
		return false;
	}

	/** Advance one phase. Changes $room['s'] in place; the core saves it. */
	public function step( &$room ) {}

	/**
	 * A player did something (answered, voted, drew...). The core has already checked the token.
	 *
	 * @return true|WP_Error
	 */
	public function player_action( &$room, $me, $type, $params ) {
		return new WP_Error( 'bad', 'Unknown action.', array( 'status' => 400 ) );
	}

	/**
	 * A host action other than 'kick' (the core handles that). The game saves the room when it changes it.
	 *
	 * @return true|WP_Error
	 */
	public function host_action( &$room, $action, $args ) {
		return new WP_Error( 'bad', 'Unknown action.', array( 'status' => 400 ) );
	}

	/**
	 * What phones, the host and the TV see. The core adds code, game, ver, now and host.
	 *
	 * @param array      $room Room row with decoded state in 's'.
	 * @param array      $rows All players in the room (rows of the players table), oldest first.
	 * @param array|null $me   The player row for the caller, if a player is asking.
	 * @param bool       $host The caller holds the host token.
	 */
	abstract public function view( $room, $rows, $me, $host );

	/** Short label for the stats table, e.g. a mode name. */
	public function stats_mode( $s ) {
		return '';
	}
}
