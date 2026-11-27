<?php
/**
 * Pages:
 *   /<game>/ and /<game>/CODE   A game's page (home, join, host and player views). Trivia: /trivia/.
 *   /tv/ and /tv/CODE           The big screen for any game. Shows the room's game.
 *   /join/CODE                  Short link that sends you to the room's game page.
 *   /wp-json/groupcade/v1/app   The same page, as a fallback when the pretty URLs are blocked.
 * Each page is one self-contained HTML file from the game (games/<slug>/assets/app.html) with the
 * logo, QR library and config inlined.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Page {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		add_shortcode( 'groupcade_games', array( __CLASS__, 'shortcode' ) );
	}

	public static function rewrite() {
		add_rewrite_rule( '^tv/?$', 'index.php?gc_page=tv', 'top' );
		add_rewrite_rule( '^tv/([A-Za-z]{4})/?$', 'index.php?gc_page=tv&gc_code=$matches[1]', 'top' );
		add_rewrite_rule( '^join/([A-Za-z]{4})/?$', 'index.php?gc_page=join&gc_code=$matches[1]', 'top' );
		foreach ( GC_Games::all() as $slug => $g ) {
			$path = preg_quote( trim( $g->path(), '/' ), '#' );
			add_rewrite_rule( '^' . $path . '/?$', 'index.php?gc_page=game&gc_game=' . $slug, 'top' );
			add_rewrite_rule( '^' . $path . '/([A-Za-z]{4})/?$', 'index.php?gc_page=game&gc_game=' . $slug . '&gc_code=$matches[1]', 'top' );
		}
	}

	public static function vars( $v ) {
		$v[] = 'gc_page';
		$v[] = 'gc_game';
		$v[] = 'gc_code';
		return $v;
	}

	public static function maybe_render() {
		$page = (string) get_query_var( 'gc_page' );
		if ( '' === $page ) {
			return;
		}
		$code = GC_Room::norm_code( (string) get_query_var( 'gc_code' ) );
		if ( 'join' === $page ) {
			$room = GC_Room::load( $code );
			$game = $room ? GC_Room::game( $room ) : GC_Games::get( GC_Games::default_slug() );
			wp_safe_redirect( home_url( '/' . $game->path() . '/' . ( $room ? $code . '/' : '' ) ), 302 );
			exit;
		}
		if ( 'tv' === $page ) {
			self::output( self::game_for( '', $code ), $code, true );
		}
		self::output( self::game_for( (string) get_query_var( 'gc_game' ), $code ), $code );
	}

	public static function rest_app( WP_REST_Request $req ) {
		// ?tv=1 = TV view, ?cast=1 = Chromecast receiver (register this URL as the Cast app's receiver).
		$code = GC_Room::norm_code( (string) $req->get_param( 'code' ) );
		self::output( self::game_for( (string) $req->get_param( 'game' ), $code ), $code, (bool) $req->get_param( 'tv' ), (bool) $req->get_param( 'cast' ) );
	}

	/** The room's game when there is a live room with that code, else the named game, else the default. */
	private static function game_for( $slug, $code ) {
		if ( 4 === strlen( $code ) ) {
			$room = GC_Room::load( $code );
			if ( $room ) {
				return GC_Room::game( $room );
			}
		}
		$g = GC_Games::get( $slug );
		return $g ? $g : GC_Games::get( GC_Games::default_slug() );
	}

	public static function logo() {
		return (string) file_get_contents( GC_DIR . 'assets/groupcade-logo.svg' ); // phpcs:ignore
	}

	public static function html( GC_Game $game, $code = '', $tv = false, $cast = false ) {
		$tpl = (string) file_get_contents( $game->client_file() ); // phpcs:ignore
		$qr  = (string) file_get_contents( GC_DIR . 'assets/qrcode.js' ); // phpcs:ignore
		$cfg = array_merge(
			array(
				'api'      => esc_url_raw( rest_url( GC_Rest::NS ) ),
				'game'     => $game->slug(),
				'gameName' => $game->name(),
				'party'    => esc_url_raw( home_url( '/' . $game->path() . '/' ) ),
				'site'     => esc_url_raw( home_url( '/' ) ),
				'tvLink'   => esc_url_raw( home_url( '/tv/' ) ),
				'code'     => GC_Room::norm_code( $code ),
				'ver'      => GC_VERSION,
				'tv'       => (bool) $tv,
				'cast'     => (bool) $cast,
				'castApp'  => preg_replace( '/[^A-Za-z0-9]/', '', (string) get_option( 'gc_cast_app', '' ) ),
				'maxPlayers' => $game->max_players(),
			),
			$game->client_config()
		);
		return strtr(
			$tpl,
			array(
				'{{LOGO}}'   => self::logo(),
				'{{QRLIB}}'  => $qr,
				'{{CONFIG}}' => wp_json_encode( $cfg ),
			)
		);
	}

	private static function output( $game, $code, $tv = false, $cast = false ) {
		if ( ! $game ) {
			return;
		}
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
		echo self::html( $game, $code, $tv, $cast ); // phpcs:ignore WordPress.Security.EscapeOutput -- our own template
		exit;
	}

	/** [groupcade_games]: a simple list of games with links, for the home page. */
	public static function shortcode() {
		$h = '<ul class="groupcade-games">';
		foreach ( GC_Games::listing() as $g ) {
			$h .= '<li><a href="' . esc_url( $g['url'] ) . '"><strong>' . esc_html( $g['name'] ) . '</strong></a>' . ( $g['tagline'] ? ' · ' . esc_html( $g['tagline'] ) : '' ) . '</li>';
		}
		$h .= '</ul><p>Showing a game on a TV? Open <a href="' . esc_url( home_url( '/tv/' ) ) . '">' . esc_html( preg_replace( '#^https?://#', '', home_url( '/tv/' ) ) ) . '</a> on the TV and type the room code.</p>';
		return $h;
	}
}
