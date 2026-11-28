<?php
/**
 * Plugin Name: Groupcade
 * Plugin URI:  https://groupcade.com/
 * Description: Party games for the big screen. A host makes a room and gets a code, link and QR; players join on their phones and play there, and the TV shows the big screen. Games: Trivia (/trivia/), Phony Baloney (/baloney/) and Scribble Squad (/scribble/). TV view: /tv/. Also serves the site's home page.
 * Version:     1.6.2
 * Author:      Groupcade
 * Requires PHP: 7.4
 * Text Domain: groupcade
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GC_VERSION', '1.6.2' );
define( 'GC_DB_VERSION', '4' ); // Bump to re-run dbDelta and re-flush rewrite rules.
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
require_once GC_DIR . 'includes/class-gc-fast.php';
require_once GC_DIR . 'includes/class-gc-jwt.php';
require_once GC_DIR . 'includes/class-gc-accounts.php';
require_once GC_DIR . 'includes/class-gc-auth.php';
require_once GC_DIR . 'includes/class-gc-packs.php';
require_once GC_DIR . 'includes/class-gc-account-rest.php';
require_once GC_DIR . 'includes/class-gc-account-page.php';
require_once GC_DIR . 'includes/class-gc-page.php';
require_once GC_DIR . 'includes/class-gc-home.php';
require_once GC_DIR . 'includes/class-gc-home-fields.php';

// Games. Each game lives in games/<slug>/ and registers itself. See docs/ADDING-A-GAME.md.
require_once GC_DIR . 'games/trivia/trivia.php';
require_once GC_DIR . 'games/baloney/baloney.php';
require_once GC_DIR . 'games/scribble/scribble.php';

// Phones' room checks: answer them now, before the theme and the rest of WordPress load.
GC_Fast::maybe_serve();

register_activation_hook( __FILE__, array( 'GC_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GC_DB', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'GC_Fast', 'remove_loader' ) );
add_action(
	'init',
	function () {
		// Refresh the mu-plugin loader once per plugin version (plugin updates skip the activation hook).
		if ( get_option( 'gc_fast_loader' ) !== GC_VERSION ) {
			GC_Fast::install_loader();
			update_option( 'gc_fast_loader', GC_VERSION, false );
		}
	}
);

add_action( 'init', array( 'GC_DB', 'maybe_upgrade' ), 20 ); // After rewrite rules are registered.
add_action( 'rest_api_init', array( 'GC_Rest', 'routes' ) );
add_action( 'rest_api_init', array( 'GC_Account_Rest', 'routes' ) );
add_action( 'gc_cleanup', array( 'GC_DB', 'cleanup' ) );
GC_Page::init();
GC_Account_Page::init();
GC_Home::init();
GC_Home_Fields::init();

if ( is_admin() ) {
	require_once GC_DIR . 'includes/class-gc-admin.php';
	GC_Admin::init();
}
