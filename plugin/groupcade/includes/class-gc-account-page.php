<?php
/**
 * The host account page: /account/ (sign in, your games, your question packs, delete your
 * account) and /account/delete/ (the deletion page the app stores ask for). One self-contained
 * HTML file, assets/account.html, that talks to the account REST routes with the gc_session cookie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Account_Page {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	public static function rewrite() {
		add_rewrite_rule( '^account/?$', 'index.php?gc_page=account', 'top' );
		add_rewrite_rule( '^account/delete/?$', 'index.php?gc_page=account&gc_code=DELETE', 'top' );
	}

	public static function maybe_render() {
		if ( 'account' !== (string) get_query_var( 'gc_page' ) ) {
			return;
		}
		$tpl  = (string) file_get_contents( GC_DIR . 'assets/account.html' ); // phpcs:ignore
		$cats = array();
		$t    = GC_Games::get( 'trivia' );
		if ( $t ) {
			$cfg  = $t->client_config();
			$cats = array_values( array_merge( (array) ( $cfg['cats'] ?? array() ), (array) ( $cfg['specCats'] ?? array() ) ) );
		}
		$cfg = array(
			'api'      => esc_url_raw( rest_url( GC_Rest::NS ) ),
			'site'     => esc_url_raw( home_url( '/' ) ),
			'account'  => esc_url_raw( home_url( '/account/' ) ),
			'privacy'  => esc_url_raw( (string) get_privacy_policy_url() ),
			'auth'     => GC_Accounts::client_config(),
			'del'      => 'DELETE' === (string) get_query_var( 'gc_code' ),
			'cats'     => $cats,
			'maxQ'     => GC_Packs::MAX_QUESTIONS,
		);
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store, max-age=0, private' );
		header( 'X-Robots-Tag: noindex' );
		echo strtr( $tpl, array( '{{LOGO}}' => GC_Page::logo(), '{{CONFIG}}' => wp_json_encode( $cfg ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- our own template
		exit;
	}
}
