<?php
/**
 * Plugin Name: Groupcade
 * Plugin URI:  https://groupcade.com/
 * Description: Party games for the big screen. A host makes a room and gets a code, link and QR; players join on their phones and play there, and the TV shows the big screen. Game one: Trivia (/trivia/). TV view: /tv/. Also serves the site's home page.
 * Version:     1.3.1
 * Author:      Groupcade
 * Requires PHP: 7.4
 * Text Domain: groupcade
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GC_VERSION', '1.3.1' );
define( 'GC_DB_VERSION', '2' ); // Bump to re-run dbDelta and re-flush rewrite rules.
define( 'GC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GC_FILE', __FILE__ );

// Core: rooms, players, chat, limits, REST, pages. Nothing here knows the rules of a game.
require_once GC_DIR . 'includes/class-gc-game.php';
require_once GC_DIR . 'includes/class-gc-games.php';
require_once GC_DIR . 'includes/class-gc-db.php';
require_once GC_DIR . 'includes/class-gc-limits.php';
require_once GC_DIR . 'includes/class-gc-room.php';
require_once GC_DIR . 'includes/class-gc-chat.php';
require_once GC_DIR . 'includes/class-gc-rest.php';
require_once GC_DIR . 'includes/class-gc-page.php';
require_once GC_DIR . 'includes/class-gc-home.php';
require_once GC_DIR . 'includes/class-gc-home-fields.php';

// Games. Each game lives in games/<slug>/ and registers itself. See docs/ADDING-A-GAME.md.
require_once GC_DIR . 'games/trivia/trivia.php';

register_activation_hook( __FILE__, array( 'GC_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GC_DB', 'deactivate' ) );

add_action( 'init', array( 'GC_DB', 'maybe_upgrade' ), 20 ); // After rewrite rules are registered.
add_action( 'rest_api_init', array( 'GC_Rest', 'routes' ) );
add_action( 'gc_cleanup', array( 'GC_DB', 'cleanup' ) );
GC_Page::init();
GC_Home::init();
GC_Home_Fields::init();

if ( is_admin() ) {
	require_once GC_DIR . 'includes/class-gc-admin.php';
	GC_Admin::init();
}
