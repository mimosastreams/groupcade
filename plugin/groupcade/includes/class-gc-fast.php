<?php
/**
 * Fast path for the one request phones make over and over: GET /wp-json/groupcade/v1/rooms/CODE.
 *
 * Every phone in a room asks for the room's state about once a second. Through the normal REST API
 * each of those loads the theme, runs every plugin's init code and builds the whole REST server,
 * only to run three or four small queries. This answers the same request, with the same JSON, as
 * soon as the Groupcade plugin file loads, and exits.
 *
 * It runs even earlier, before other plugins load, when the small loader in
 * wp-content/mu-plugins/groupcade-fast.php is there (the plugin writes it; see install_loader()).
 *
 * Anything unusual falls through to the normal REST route: no room, a phase that is due to move
 * on (tick() writes and may need the whole of WordPress), or the gc_fast option set to 'off'.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Fast {

	const LOADER = 'groupcade-fast.php';

	private static $done = false;

	/** The room code when this request is a state poll we can answer, else ''. */
	public static function code() {
		if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore
			return '';
		}
		$route = '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore
			$route = (string) $_GET['rest_route']; // phpcs:ignore
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore
			$at   = strpos( $path, '/' . rest_get_url_prefix() . '/' );
			if ( false === $at ) {
				return '';
			}
			$route = substr( $path, $at + strlen( rest_get_url_prefix() ) + 1 );
		}
		if ( ! preg_match( '#^/groupcade/v1/rooms/([A-Za-z]{4})/?$#', $route, $m ) ) {
			return '';
		}
		return strtoupper( $m[1] );
	}

	/** Answer the poll and exit, or return so WordPress handles it as usual. */
	public static function maybe_serve() {
		if ( self::$done ) {
			return;
		}
		self::$done = true;
		$code = self::code();
		if ( '' === $code || 'off' === get_option( 'gc_fast' ) ) {
			return;
		}
		$room = GC_Room::load( $code );
		if ( ! $room ) {
			return; // Let the REST route send its usual 404.
		}
		$game = GC_Room::game( $room );
		if ( ! $game || $game->due( $room['s'], GC_Room::now_ms() ) ) {
			return; // tick() has work to do: use the full REST route this once.
		}

		// From here on we always answer and exit, so loading the pluggable functions now (for
		// wp_salt) can't clash with a plugin that would have defined its own later.
		require_once ABSPATH . WPINC . '/pluggable.php';
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}

		$h    = function ( $name ) {
			$k = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			return isset( $_SERVER[ $k ] ) ? (string) wp_unslash( $_SERVER[ $k ] ) : ''; // phpcs:ignore
		};
		$view = GC_Rest::state_view(
			$room,
			$h( 'X-Party-Token' ),
			$h( 'X-Party-Host' ),
			isset( $_GET['chat'] ) ? (int) $_GET['chat'] : 0, // phpcs:ignore
			isset( $_GET['h'] ) ? (string) wp_unslash( $_GET['h'] ) : '' // phpcs:ignore
		);

		status_header( 200 );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Groupcade-Fast: 1' );
		$origin = $h( 'Origin' );
		if ( '' !== $origin ) {
			// What the REST API sends for a cross-origin GET.
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Vary: Origin', false );
		}
		echo wp_json_encode( $view );
		exit;
	}

	// ------------------------------------------------------------------ mu-plugin loader

	private static function loader_path() {
		return WPMU_PLUGIN_DIR . '/' . self::LOADER;
	}

	private static function loader_code() {
		$main = wp_normalize_path( GC_FILE );
		return "<?php\n"
			. "/**\n * Plugin Name: Groupcade fast room checks\n"
			. " * Description: Written by the Groupcade plugin. Answers game phones' room checks before other plugins load. Safe to delete; Groupcade rewrites it on its next update.\n */\n"
			. "if ( defined( 'ABSPATH' ) && 'GET' === ( isset( \$_SERVER['REQUEST_METHOD'] ) ? \$_SERVER['REQUEST_METHOD'] : '' ) && isset( \$_SERVER['REQUEST_URI'] ) && false !== strpos( (string) \$_SERVER['REQUEST_URI'], 'groupcade/v1/rooms/' )\n"
			. "\t&& in_array( 'groupcade/groupcade.php', (array) get_option( 'active_plugins', array() ), true )\n"
			. "\t&& file_exists( " . var_export( $main, true ) . " ) ) {\n"
			. "\tinclude_once " . var_export( $main, true ) . ";\n"
			. "}\n";
	}

	/** Write (or refresh) the mu-plugin loader. Quietly does nothing where mu-plugins can't be written. */
	public static function install_loader() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || 'off' === get_option( 'gc_fast' ) ) {
			return;
		}
		$code = self::loader_code();
		$path = self::loader_path();
		if ( file_exists( $path ) && file_get_contents( $path ) === $code ) { // phpcs:ignore
			return;
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return;
		}
		if ( is_writable( WPMU_PLUGIN_DIR ) ) {
			file_put_contents( $path, $code ); // phpcs:ignore
		}
	}

	public static function remove_loader() {
		$path = self::loader_path();
		if ( file_exists( $path ) && false !== strpos( (string) file_get_contents( $path ), 'Groupcade' ) ) { // phpcs:ignore
			unlink( $path ); // phpcs:ignore
		}
	}

	/** True when the loader is in place, for the admin status line. */
	public static function loader_ok() {
		return file_exists( self::loader_path() );
	}
}
