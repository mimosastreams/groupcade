<?php
/**
 * Plugin Name: Mimosa Survive Party
 * Description: Survive Trivia as a party game. A host makes a room and gets a code, link and QR; players join on their phones and answer there. Fully separate from the on-stream Survive Trivia (own questions, own tables, no records kept after a room expires). Page: /party/
 * Version:     0.8.3
 * Author: Mimosa
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPT_VERSION', '0.8.3' );
define( 'MPT_DB_VERSION', '5' ); // Bump to re-flush rewrite rules.
define( 'MPT_DIR', plugin_dir_path( __FILE__ ) );

require_once MPT_DIR . 'includes/class-mpt-db.php';
require_once MPT_DIR . 'includes/class-mpt-bank.php';
require_once MPT_DIR . 'includes/class-mpt-limits.php';
require_once MPT_DIR . 'includes/class-mpt-room.php';
require_once MPT_DIR . 'includes/class-mpt-chat.php';
require_once MPT_DIR . 'includes/class-mpt-rest.php';
require_once MPT_DIR . 'includes/class-mpt-page.php';

register_activation_hook( __FILE__, array( 'MPT_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MPT_DB', 'deactivate' ) );

add_action( 'init', array( 'MPT_DB', 'maybe_upgrade' ), 20 ); // After rewrite rules are registered.
add_action( 'rest_api_init', array( 'MPT_Rest', 'routes' ) );
add_action( 'mpt_cleanup', array( 'MPT_DB', 'cleanup' ) );
MPT_Page::init();

if ( is_admin() ) {
	require_once MPT_DIR . 'includes/class-mpt-admin.php';
	MPT_Admin::init();
}
