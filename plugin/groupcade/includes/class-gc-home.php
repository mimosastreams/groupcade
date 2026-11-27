<?php
/**
 * The site's home page (groupcade.com/), design C "Sticker Book".
 * Shown in place of the theme's front page while Settings > Groupcade > "Use the Groupcade home
 * page" is on. The layout is assets/home.html; every word and picture on it comes from the
 * gc_home_content option, edited at Settings > Groupcade home (GC_Home_Admin). Empty fields fall
 * back to the defaults below; an empty optional card or step is left out.
 *
 * Text fields are plain text. Two shortcuts work in any of them: {site} and {tv} become the
 * site's short address (groupcade.com) and TV address (groupcade.com/tv). In the headline,
 * *stars* around words mark the highlighted part.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Home {

	const OPTION = 'gc_home_content';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	public static function enabled() {
		return '1' === (string) get_option( 'gc_home', '1' );
	}

	/**
	 * Every editable field: key => array( section, label, type, default, help ).
	 * Types: text, textarea, image (attachment id; 0 = the built-in picture).
	 */
	public static function fields() {
		$f = array(
			// Page.
			'meta_title'       => array( 'Page and search', 'Browser tab title', 'text', 'Groupcade · Party games for the whole family', '' ),
			'meta_description' => array( 'Page and search', 'Search and link-preview description', 'textarea', 'Party games the whole family plays together. Put the game on your TV, and everyone plays from their own phone. No downloads for players.', 'Shown by Google and when someone shares the link.' ),
			'share_image'      => array( 'Page and search', 'Link-preview image', 'image', 0, 'Shown when the link is shared. Best at 1200×630. Leave empty for the built-in one.' ),

			// Top bar.
			'nav_games'        => array( 'Top bar', 'Games link', 'text', 'Games', '' ),
			'nav_how'          => array( 'Top bar', 'How it works link', 'text', 'How it works', '' ),
			'nav_host'         => array( 'Top bar', 'Host button', 'text', 'Host a game', '' ),

			// Hero.
			'hero_title'       => array( 'Top of the page', 'Headline', 'text', 'Everybody plays. *Nobody* waits.', 'Put *stars* around the words to highlight in yellow.' ),
			'hero_text'        => array( 'Top of the page', 'Intro', 'textarea', 'Put Groupcade on the TV, grab your phones and play together. Made for living rooms full of kids, grown-ups and grandparents.', '' ),
			'join_placeholder' => array( 'Top of the page', 'Room code box hint', 'text', 'Room code', '' ),
			'join_button'      => array( 'Top of the page', 'Join button', 'text', 'Join', '' ),
			'host_prompt'      => array( 'Top of the page', 'Line under the join box', 'text', 'Hosting?', '' ),
			'host_prompt_link' => array( 'Top of the page', 'Link in that line', 'text', 'Start in your browser or get the app', 'Goes to the host section further down.' ),
			'hero_image'       => array( 'Top of the page', 'Main picture', 'image', 0, 'Leave empty for the TV and phone friends.' ),
		);
		$cards = array(
			1 => array( 'No app for players', 'Just the phone\'s browser.' ),
			2 => array( 'Any TV works', 'AirPlay, Chromecast, browser or HDMI.' ),
		);
		foreach ( $cards as $i => $c ) {
			$f[ "hero_card{$i}_title" ] = array( 'Top of the page', "Sticker card $i title", 'text', $c[0], 1 === $i ? 'The small tilted cards under the picture. Leave a title empty to hide that card.' : '' );
			$f[ "hero_card{$i}_text" ]  = array( 'Top of the page', "Sticker card $i text", 'text', $c[1], '' );
		}

		// Games.
		$f['games_title']  = array( 'Games', 'Heading', 'text', 'Pick a game', '' );
		$f['play_badge']   = array( 'Games', 'Badge on playable games', 'text', 'Play now', '' );
		$f['play_button']  = array( 'Games', 'Button on playable games', 'text', 'Host {game}', '{game} becomes the game\'s name.' );
		foreach ( GC_Games::all() as $g ) {
			$f[ 'game_' . $g->slug() . '_text' ] = array( 'Games', $g->name() . ' card text', 'textarea', $g->blurb(), '' );
		}
		$f['soon_badge'] = array( 'Games', 'Badge on coming-soon cards', 'text', 'Coming soon', '' );
		$soon            = array(
			1 => array( 'Drawing game', 'Sketch on your phone, everyone guesses on the TV.' ),
			2 => array( 'Word game', 'Write the funniest answer, then vote for your favorite.' ),
			3 => array( '', '' ),
		);
		foreach ( $soon as $i => $s ) {
			$f[ "soon{$i}_title" ] = array( 'Games', "Coming-soon card $i title", 'text', $s[0], 1 === $i ? 'Leave a title empty to hide that card.' : '' );
			$f[ "soon{$i}_text" ]  = array( 'Games', "Coming-soon card $i text", 'text', $s[1], '' );
		}

		// How it works.
		$f['how_title'] = array( 'How it works', 'Heading', 'text', 'How it works', '' );
		$steps          = array(
			1 => array( 'Step 1', 'Host starts a game', 'At {site} on any laptop, tablet or phone, or in the iPhone or Android app.' ),
			2 => array( 'Step 2', 'It goes on the TV', 'AirPlay, Chromecast, the TV\'s browser at {tv}, or a laptop with HDMI.' ),
			3 => array( 'Step 3', 'Phones join in', 'Type the 4-letter code at {site} or scan the QR code on the TV.' ),
		);
		foreach ( $steps as $i => $s ) {
			$f[ "step{$i}_label" ] = array( 'How it works', "Step $i small label", 'text', $s[0], 1 === $i ? 'Leave a step\'s title empty to hide it.' : '' );
			$f[ "step{$i}_title" ] = array( 'How it works', "Step $i title", 'text', $s[1], '' );
			$f[ "step{$i}_text" ]  = array( 'How it works', "Step $i text", 'textarea', $s[2], '' );
		}

		// Host.
		$f['host_title']  = array( 'Host section', 'Heading', 'text', 'Ready to host?', '' );
		$f['host_text']   = array( 'Host section', 'Text', 'textarea', 'Start right now in your browser, or get the app for iPhone or Android. Whatever screen you have, Groupcade can play on it.', '' );
		$f['host_button'] = array( 'Host section', 'Browser button', 'text', 'Host in your browser', '' );
		$f['host_ios']    = array( 'Host section', 'App Store button', 'text', 'App Store', 'The link is set on the Settings > Groupcade page. Without a link the button says "coming soon".' );
		$f['host_android'] = array( 'Host section', 'Google Play button', 'text', 'Google Play', '' );
		$f['host_soon']   = array( 'Host section', 'Word added when an app has no link yet', 'text', 'coming soon', '' );
		$f['host_image']  = array( 'Host section', 'Picture', 'image', 0, 'Leave empty for the full logo.' );

		// Footer.
		$f['footer_tv']   = array( 'Footer', 'TV screen link', 'text', 'Open the TV screen', '' );
		$f['footer_note'] = array( 'Footer', 'Copyright line', 'text', '© {year} Groupcade', '{year} becomes this year.' );

		return $f;
	}

	/** Cards and steps that can be hidden by emptying them. */
	public static function optional( $key ) {
		return (bool) preg_match( '/^(hero_card|soon|step)\d_/', $key );
	}

	/**
	 * The saved value. A field never saved gets its default. An emptied field gets its default
	 * too, except optional cards and steps, where empty means "hide it".
	 */
	public static function get( $key ) {
		static $fields = null;
		if ( null === $fields ) {
			$fields = self::fields();
		}
		$saved = get_option( self::OPTION, array() );
		$def   = isset( $fields[ $key ] ) ? $fields[ $key ][3] : '';
		if ( ! is_array( $saved ) || ! array_key_exists( $key, $saved ) ) {
			return $def;
		}
		$v = $saved[ $key ];
		if ( ( '' === $v || null === $v ) && ! self::optional( $key ) ) {
			return $def;
		}
		return $v;
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

	/** Escaped text with {site}, {tv}, {year} filled in. */
	private static function t( $key, $extra = array() ) {
		$v = esc_html( (string) self::get( $key ) );
		return strtr(
			$v,
			array_merge(
				array(
					'{site}' => esc_html( self::short( home_url( '/' ) ) ),
					'{tv}'   => esc_html( self::short( home_url( '/tv/' ) ) ),
					'{year}' => esc_html( gmdate( 'Y' ) ),
				),
				$extra
			)
		);
	}

	/** Attachment URL, or the plugin's built-in picture. */
	private static function img( $key, $fallback ) {
		$id = (int) self::get( $key );
		if ( $id ) {
			$u = wp_get_attachment_image_url( $id, 'full' );
			if ( $u ) {
				return $u;
			}
		}
		return plugins_url( 'assets/brand/' . $fallback, GC_FILE );
	}

	private static function alt( $key, $fallback ) {
		$id = (int) self::get( $key );
		if ( $id ) {
			return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		}
		return $fallback;
	}

	public static function html() {
		$tpl  = (string) file_get_contents( GC_DIR . 'assets/home.html' ); // phpcs:ignore
		$site = home_url( '/' );
		$tv   = home_url( '/tv/' );
		$def  = GC_Games::get( GC_Games::default_slug() );
		$host = $def ? home_url( '/' . $def->path() . '/' ) : $site;
		$priv = get_privacy_policy_url();

		// Headline: *word* -> highlight.
		$title = self::t( 'hero_title' );
		$title = preg_replace( '/\*([^*]+)\*/', '<mark>$1</mark>', $title );

		$map = array(
			'{{META_TITLE}}'   => self::t( 'meta_title' ),
			'{{META_DESC}}'    => esc_attr( self::get( 'meta_description' ) ),
			'{{SHARE_IMG}}'    => esc_url( self::img( 'share_image', 'og-image-1200x630.png' ) ),
			'{{FAVICON}}'      => esc_url( plugins_url( 'assets/brand/favicon.svg', GC_FILE ) ),
			'{{FAVICON_PNG}}'  => esc_url( plugins_url( 'assets/brand/favicon-48.png', GC_FILE ) ),
			'{{TOUCH_ICON}}'   => esc_url( plugins_url( 'assets/brand/apple-touch-icon-180.png', GC_FILE ) ),
			'{{LOGO_H}}'       => esc_url( plugins_url( 'assets/brand/groupcade-horizontal.svg', GC_FILE ) ),
			'{{LOGO_H_DARK}}'  => esc_url( plugins_url( 'assets/brand/groupcade-horizontal-dark.svg', GC_FILE ) ),
			'{{SITE}}'         => esc_url( $site ),
			'{{TV_URL}}'       => esc_url( $tv ),
			'{{HOST_URL}}'     => esc_url( $host ),
			'{{JOIN_ACTION}}'  => esc_url( home_url( '/join/' ) ),
			'{{NAV_GAMES}}'    => self::t( 'nav_games' ),
			'{{NAV_HOW}}'      => self::t( 'nav_how' ),
			'{{NAV_HOST}}'     => self::t( 'nav_host' ),
			'{{HERO_TITLE}}'   => $title,
			'{{HERO_TEXT}}'    => self::t( 'hero_text' ),
			'{{JOIN_HINT}}'    => esc_attr( self::get( 'join_placeholder' ) ),
			'{{JOIN_BUTTON}}'  => self::t( 'join_button' ),
			'{{HOST_PROMPT}}'  => self::t( 'host_prompt' ),
			'{{HOST_PROMPT_LINK}}' => self::t( 'host_prompt_link' ),
			'{{HERO_IMG}}'     => esc_url( self::img( 'hero_image', 'groupcade-mascots.svg' ) ),
			'{{HERO_ALT}}'     => esc_attr( self::alt( 'hero_image', 'The Groupcade TV and three phone friends cheering' ) ),
			'{{HERO_CARDS}}'   => self::hero_cards(),
			'{{GAMES_TITLE}}'  => self::t( 'games_title' ),
			'{{GAMES}}'        => self::shelf(),
			'{{HOW_TITLE}}'    => self::t( 'how_title' ),
			'{{STEPS}}'        => self::steps(),
			'{{HOST_TITLE}}'   => self::t( 'host_title' ),
			'{{HOST_TEXT}}'    => self::t( 'host_text' ),
			'{{HOST_BUTTON}}'  => self::t( 'host_button' ),
			'{{HOST_IMG}}'     => esc_url( self::img( 'host_image', 'groupcade-logo.svg' ) ),
			'{{APPS}}'         => self::apps(),
			'{{FOOTER_TV}}'    => self::t( 'footer_tv' ),
			'{{FOOTER_NOTE}}'  => self::t( 'footer_note' ),
			'{{FOOTER_LINKS}}' => $priv ? '<a href="' . esc_url( $priv ) . '">Privacy</a>' : '',
		);
		return strtr( $tpl, $map );
	}

	private static function hero_cards() {
		$h = '';
		foreach ( array( 1 => 'sun', 2 => 'mint' ) as $i => $cls ) {
			if ( '' === trim( (string) self::get( "hero_card{$i}_title" ) ) ) {
				continue;
			}
			$h .= '<div class="sticker small ' . $cls . '"><p class="st">' . self::t( "hero_card{$i}_title" ) . '</p><p>' . self::t( "hero_card{$i}_text" ) . '</p></div>';
		}
		return $h;
	}

	private static function shelf() {
		$h = '';
		foreach ( GC_Games::all() as $g ) {
			$name = esc_html( $g->name() );
			$url  = esc_url( home_url( '/' . $g->path() . '/' ) );
			$h   .= '<article class="game live">'
				. '<span class="badge green">' . self::t( 'play_badge' ) . '</span>'
				. '<h3><a href="' . $url . '">' . $name . '</a></h3>'
				. '<p>' . self::t( 'game_' . $g->slug() . '_text' ) . '</p>'
				. '<a class="btn indigo" href="' . $url . '">' . self::t( 'play_button', array( '{game}' => $name ) ) . '</a>'
				. '</article>';
		}
		foreach ( array( 1 => 'coral', 2 => 'sun', 3 => 'mint' ) as $i => $cls ) {
			if ( '' === trim( (string) self::get( "soon{$i}_title" ) ) ) {
				continue;
			}
			$h .= '<article class="game ' . $cls . '">'
				. '<span class="badge">' . self::t( 'soon_badge' ) . '</span>'
				. '<h3>' . self::t( "soon{$i}_title" ) . '</h3>'
				. '<p>' . self::t( "soon{$i}_text" ) . '</p>'
				. '</article>';
		}
		return $h;
	}

	private static function steps() {
		$h = '';
		foreach ( array( 1 => 'coral', 2 => 'sun', 3 => 'mint' ) as $i => $cls ) {
			if ( '' === trim( (string) self::get( "step{$i}_title" ) ) ) {
				continue;
			}
			$h .= '<div class="step ' . $cls . '"><p class="label">' . self::t( "step{$i}_label" ) . '</p><h3>' . self::t( "step{$i}_title" ) . '</h3><p>' . self::t( "step{$i}_text" ) . '</p></div>';
		}
		return $h;
	}

	private static function apps() {
		$stores = array(
			array( 'host_ios', (string) get_option( 'gc_app_ios', '' ) ),
			array( 'host_android', (string) get_option( 'gc_app_android', '' ) ),
		);
		$h = '';
		foreach ( $stores as $s ) {
			if ( '' !== $s[1] ) {
				$h .= '<a class="btn white" href="' . esc_url( $s[1] ) . '">' . self::t( $s[0] ) . '</a>';
			} else {
				$h .= '<span class="btn white off">' . self::t( $s[0] ) . ' <small>' . self::t( 'host_soon' ) . '</small></span>';
			}
		}
		return $h;
	}
}
