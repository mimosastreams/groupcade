<?php
/**
 * The game page: mimosa.gg/party/ and /party/CODE (short link for QR codes),
 * plus the same page at /wp-json/mimosa-party/v1/app as a bot-proof fallback.
 * One self-contained HTML file (assets/app.html) with the logo and QR library inlined.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MPT_Page {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	public static function rewrite() {
		add_rewrite_rule( '^party/tv/?$', 'index.php?mpt_party=1&mpt_tv=1', 'top' );
		add_rewrite_rule( '^party/tv/([A-Za-z]{4})/?$', 'index.php?mpt_party=1&mpt_tv=1&mpt_code=$matches[1]', 'top' );
		add_rewrite_rule( '^party/?$', 'index.php?mpt_party=1', 'top' );
		add_rewrite_rule( '^party/([A-Za-z]{4})/?$', 'index.php?mpt_party=1&mpt_code=$matches[1]', 'top' );
	}

	public static function vars( $v ) {
		$v[] = 'mpt_party';
		$v[] = 'mpt_code';
		$v[] = 'mpt_tv';
		return $v;
	}

	public static function maybe_render() {
		if ( ! get_query_var( 'mpt_party' ) ) {
			return;
		}
		self::output( (string) get_query_var( 'mpt_code' ), (bool) get_query_var( 'mpt_tv' ) );
	}

	public static function rest_app( WP_REST_Request $req ) {
		// ?tv=1 = TV view, ?cast=1 = Chromecast receiver (register this URL as the Cast app's receiver).
		self::output( (string) $req->get_param( 'code' ), (bool) $req->get_param( 'tv' ), (bool) $req->get_param( 'cast' ) );
	}

	public static function html( $code = '', $tv = false, $cast = false ) {
		$tpl  = (string) file_get_contents( MPT_DIR . 'assets/app.html' ); // phpcs:ignore
		$logo = (string) file_get_contents( MPT_DIR . 'assets/mimosa-logo.svg' ); // phpcs:ignore
		$qr   = (string) file_get_contents( MPT_DIR . 'assets/qrcode.js' ); // phpcs:ignore
		$cfg  = array(
			'api'   => esc_url_raw( rest_url( MPT_Rest::NS ) ),
			'party' => esc_url_raw( home_url( '/party/' ) ),
			'site'  => esc_url_raw( home_url( '/' ) ),
			'code'  => MPT_Room::norm_code( $code ),
			'ver'     => MPT_VERSION,
			'tv'      => (bool) $tv,
			'cast'    => (bool) $cast,
			'cats'    => MPT_Bank::categories(),
			'specCats' => MPT_Bank::specials(),
			'tvLink'  => esc_url_raw( home_url( '/party/tv/' ) ),
			'castApp' => preg_replace( '/[^A-Za-z0-9]/', '', (string) get_option( 'mpt_cast_app', '' ) ),
		);
		return strtr(
			$tpl,
			array(
				'{{LOGO}}'   => $logo,
				'{{QRLIB}}'  => $qr,
				'{{CONFIG}}' => wp_json_encode( $cfg ),
			)
		);
	}

	private static function output( $code, $tv = false, $cast = false ) {
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
		echo self::html( $code, $tv, $cast ); // phpcs:ignore WordPress.Security.EscapeOutput -- our own template
		exit;
	}
}
