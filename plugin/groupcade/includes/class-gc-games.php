<?php
/**
 * Registry of the games this site offers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Games {

	/** @var GC_Game[] slug => game */
	private static $games = array();

	public static function register( GC_Game $game ) {
		$slug = preg_replace( '/[^a-z]/', '', strtolower( $game->slug() ) );
		if ( '' === $slug || 'tv' === $slug || 'join' === $slug ) {
			return; // Reserved by the core pages.
		}
		self::$games[ $slug ] = $game;
	}

	/** @return GC_Game|null */
	public static function get( $slug ) {
		$slug = strtolower( (string) $slug );
		return isset( self::$games[ $slug ] ) ? self::$games[ $slug ] : null;
	}

	/** @return GC_Game[] */
	public static function all() {
		return self::$games;
	}

	/** The game used when a request does not name one (the first registered). */
	public static function default_slug() {
		$k = array_keys( self::$games );
		return $k ? $k[0] : '';
	}

	/** Public list for GET /games and the [groupcade_games] shortcode. */
	public static function listing() {
		$out = array();
		foreach ( self::$games as $slug => $g ) {
			$out[] = array(
				'slug'       => $slug,
				'name'       => $g->name(),
				'tagline'    => $g->tagline(),
				'url'        => home_url( '/' . $g->path() . '/' ),
				'maxPlayers' => $g->max_players(),
				'config'     => $g->client_config(),
			);
		}
		return $out;
	}
}
