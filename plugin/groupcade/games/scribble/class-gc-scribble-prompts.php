<?php
/**
 * Scribble Squad drawing prompts, edited under Groupcade > Drawing prompts: one big list, one prompt a
 * line, with other answers that also count after a | (separated by ;). Saved in the option
 * gc_scribble_prompts. Until someone saves the page, games use the built-in list in data/prompts.txt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Scribble_Prompts {

	const OPT  = 'gc_scribble_prompts';
	const PAGE = 'gc-scribble-prompts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_gc_scribble_prompts', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=gc_question', 'Drawing prompts', 'Drawing prompts', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** The list as text, the way the admin page shows it. */
	public static function text() {
		$t = get_option( self::OPT );
		if ( is_string( $t ) && '' !== trim( $t ) ) {
			return $t;
		}
		$lines = (array) @file( __DIR__ . '/data/prompts.txt', FILE_IGNORE_NEW_LINES ); // phpcs:ignore
		return implode( "\n", array_filter( $lines, function ( $l ) {
			return '' !== trim( $l ) && '#' !== substr( ltrim( $l ), 0, 1 );
		} ) );
	}

	/** @return array of [ prompt, [ other answers ] ]. */
	public static function load() {
		$out = array();
		$seen = array();
		foreach ( preg_split( '/\r\n|\r|\n/', self::text() ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$f = array_map( 'trim', explode( '|', $line, 2 ) );
			$w = mb_substr( sanitize_text_field( $f[0] ), 0, 60 );
			$k = mb_strtolower( $w );
			if ( '' === $w || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = 1;
			$acc   = isset( $f[1] ) ? array_values( array_filter( array_map( 'trim', explode( ';', sanitize_text_field( $f[1] ) ) ), 'strlen' ) ) : array();
			$out[] = array( $w, array_slice( $acc, 0, 10 ) );
		}
		return $out;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = count( self::load() );
		echo '<div class="wrap"><h1>Drawing prompts</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
		}
		echo '<p>What Scribble Squad players are asked to draw. One prompt a line. To let other answers count as right guesses, add them after a <code>|</code>, separated by <code>;</code>. For example: <code>a rocket ship | rocket;spaceship</code></p>';
		echo '<p>Guesses ignore capital letters, punctuation and a leading "a", "an" or "the", so <code>a giraffe</code> also matches "Giraffe". Each player gets a different prompt, and a room doesn\'t repeat prompts until it has used them all.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gc_scribble_prompts' );
		echo '<input type="hidden" name="action" value="gc_scribble_prompts">';
		echo '<textarea name="prompts" rows="28" class="large-text code" spellcheck="false">' . esc_textarea( self::text() ) . '</textarea>';
		echo '<p class="description">' . (int) $n . ' prompts. Clear the box and save to go back to the built-in list.</p>';
		submit_button( 'Save prompts' );
		echo '</form></div>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gc_scribble_prompts' );
		$t = isset( $_POST['prompts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompts'] ) ) : '';
		update_option( self::OPT, $t, false );
		wp_safe_redirect( admin_url( 'edit.php?post_type=gc_question&page=' . self::PAGE . '&saved=1' ) );
		exit;
	}
}
