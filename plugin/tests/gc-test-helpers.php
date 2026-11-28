<?php
/**
 * Test helpers for plugin/tests/accounts-smoke.mjs. LOCAL TEST SITES ONLY: copy into
 * wp-content/mu-plugins/ of a throwaway site. Never put this on groupcade.com.
 *
 * - Mail always "sends", and the last sign-in code is kept so the test can read it.
 * - Apple and Google keys come from wp-content/gc-test-jwks.json (the test writes it), and the
 *   test client IDs are set.
 * - GET /wp-json/gctest/v1/code?email=... returns the last code sent to that address.
 */

add_filter( 'pre_wp_mail', '__return_true' );
add_action( 'gc_email_code', function ( $email, $code ) {
	update_option( 'gctest_code_' . md5( $email ), $code, false );
}, 10, 2 );
add_filter( 'gc_jwks', function ( $keys ) {
	$f = WP_CONTENT_DIR . '/gc-test-jwks.json';
	return file_exists( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : $keys;
} );
foreach ( array( 'gc_google_web' => 'test-web.apps.googleusercontent.com', 'gc_google_ios' => 'test-ios.apps.googleusercontent.com', 'gc_apple_services' => 'com.groupcade.web' ) as $k => $v ) {
	add_filter( 'pre_option_' . $k, function () use ( $v ) {
		return $v;
	} );
}
add_action( 'rest_api_init', function () {
	register_rest_route( 'gctest/v1', '/code', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $r ) {
			return array( 'code' => get_option( 'gctest_code_' . md5( strtolower( (string) $r['email'] ) ) ) );
		},
	) );
} );
