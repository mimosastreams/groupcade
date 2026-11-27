<?php
/**
 * The site's home page (groupcade.com/): join box, game shelf, how to host, app links.
 * Shown in place of the theme's front page while Settings > Groupcade > "Use the Groupcade home
 * page" is on. One self-contained HTML file, assets/home.html, with the games filled in.
 *
 * The shelf lists every registered game, then "coming soon" cards so it always shows at least
 * three. Each game's card uses GC_Game::name(), blurb() and tags().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Home {

	const SHELF_MIN = 3;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	public static function enabled() {
		return '1' === (string) get_option( 'gc_home', '1' );
	}

	public static function maybe_render() {
		if ( ! self::enabled() || ! is_front_page() || is_paged() || '' !== (string) get_query_var( 'gc_page' ) ) {
			return;
		}
		// Previews, searches and feeds keep WordPress's own handling.
		if ( is_search() || is_feed() || is_preview() || isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- our own template, values escaped below
		exit;
	}

	private static function short( $url ) {
		return untrailingslashit( preg_replace( '#^https?://(www\.)?#', '', $url ) );
	}

	public static function html() {
		$tpl   = (string) file_get_contents( GC_DIR . 'assets/home.html' ); // phpcs:ignore
		$logo  = GC_Page::logo();
		$site  = home_url( '/' );
		$tv    = home_url( '/tv/' );
		$def   = GC_Games::get( GC_Games::default_slug() );
		$host  = $def ? home_url( '/' . $def->path() . '/' ) : $site;
		$terms = get_privacy_policy_url();

		return strtr(
			$tpl,
			array(
				'{{LOGO}}'         => $logo,
				'{{ICON}}'         => 'data:image/svg+xml,' . rawurlencode( $logo ),
				'{{SITE}}'         => esc_url( $site ),
				'{{SITE_SHORT}}'   => esc_html( self::short( $site ) ),
				'{{TV_URL}}'       => esc_url( $tv ),
				'{{TV_SHORT}}'     => esc_html( self::short( $tv ) ),
				'{{HOST_URL}}'     => esc_url( $host ),
				'{{JOIN_ACTION}}'  => esc_url( home_url( '/join/' ) ),
				'{{GAMES}}'        => self::shelf(),
				'{{APPS}}'         => self::apps(),
				'{{YEAR}}'         => esc_html( gmdate( 'Y' ) ),
				'{{FOOTER_LINKS}}' => $terms ? '<a href="' . esc_url( $terms ) . '">Privacy</a>' : '',
			)
		);
	}

	private static function shelf() {
		$h = '';
		$n = 0;
		foreach ( GC_Games::all() as $g ) {
			$name  = $g->name();
			$tiles = 'trivia' === $g->slug() ? array( '?', '!', 'A' ) : array_slice( str_split( strtoupper( preg_replace( '/[^A-Za-z]/', '', $name ) ) . '???' ), 0, 3 );
			$tags  = '';
			foreach ( (array) $g->tags() as $t ) {
				$tags .= '<span>' . esc_html( $t ) . '</span>';
			}
			$h .= '<a class="game" href="' . esc_url( home_url( '/' . $g->path() . '/' ) ) . '">'
				. '<div class="art" aria-hidden="true"><span>' . esc_html( $tiles[0] ) . '</span><span>' . esc_html( $tiles[1] ) . '</span><span>' . esc_html( $tiles[2] ) . '</span></div>'
				. '<div class="ghead"><h3>' . esc_html( $name ) . '</h3><span class="badge">PLAY NOW</span></div>'
				. '<p>' . esc_html( $g->blurb() ) . '</p>'
				. ( $tags ? '<div class="tags">' . $tags . '</div>' : '' )
				. '</a>';
			$n++;
		}
		$soon = array( 'A new way to play together is in the works.', 'Tell us what your family wants to play next.' );
		for ( $i = 0; $n < self::SHELF_MIN; $i++, $n++ ) {
			$h .= '<div class="soon"><div class="art">Coming soon</div><h3>New game</h3><p>' . esc_html( $soon[ $i % 2 ] ) . '</p></div>';
		}
		return $h;
	}

	private static function apps() {
		$stores = array(
			array( 'App Store', 'iPhone', (string) get_option( 'gc_app_ios', '' ) ),
			array( 'Google Play', 'Android', (string) get_option( 'gc_app_android', '' ) ),
		);
		$h = '';
		foreach ( $stores as $s ) {
			if ( '' !== $s[2] ) {
				$h .= '<a class="big store" href="' . esc_url( $s[2] ) . '">' . esc_html( $s[0] ) . '</a>';
			} else {
				$h .= '<span class="big store off">' . esc_html( $s[0] ) . '<small>' . esc_html( $s[1] ) . ' app coming soon</small></span>';
			}
		}
		return $h;
	}
}
