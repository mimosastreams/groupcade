<?php
/**
 * Plugin Name: Groupcade Deploy
 * Description: Lets the GitHub Actions workflow in mimosastreams/groupcade install and update plugins on this site. Each upload is signed by GitHub (OpenID Connect), so there is no password or token to keep. Log at Tools > Groupcade deploy.
 * Version:     1.0.0
 * Author:      Groupcade
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Deploy {

	const NS       = 'groupcade-deploy/v1';
	const ISSUER   = 'https://token.actions.githubusercontent.com';
	const AUDIENCE = 'groupcade-deploy';
	const LOG      = 'gc_deploy_log';

	/** Repositories whose workflows may deploy. Filter: gc_deploy_repositories. */
	public static function repos() {
		return (array) apply_filters( 'gc_deploy_repositories', array( 'mimosastreams/groupcade' ) );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/plugin',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'plugin' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			)
		);
		register_rest_route(
			self::NS,
			'/plugins',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'plugins' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			)
		);
	}

	/* ---------- GitHub OIDC ---------- */

	private static function b64( $s ) {
		return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); // phpcs:ignore
	}

	private static function jwks( $fresh = false ) {
		$k = get_transient( 'gc_deploy_jwks' );
		if ( ! $fresh && is_array( $k ) ) {
			return $k;
		}
		$r = wp_remote_get( self::ISSUER . '/.well-known/jwks', array( 'timeout' => 10 ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
			return array();
		}
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		$k = isset( $j['keys'] ) && is_array( $j['keys'] ) ? $j['keys'] : array();
		set_transient( 'gc_deploy_jwks', $k, HOUR_IN_SECONDS );
		return $k;
	}

	/** DER length. */
	private static function len( $n ) {
		if ( $n < 128 ) {
			return chr( $n );
		}
		$b = ltrim( pack( 'N', $n ), "\0" );
		return chr( 0x80 | strlen( $b ) ) . $b;
	}

	/** Public key from a JWK: its certificate if it has one, else built from n and e. */
	private static function key( $jwk ) {
		if ( ! empty( $jwk['x5c'][0] ) ) {
			$k = openssl_pkey_get_public( "-----BEGIN CERTIFICATE-----\n" . chunk_split( $jwk['x5c'][0], 64, "\n" ) . "-----END CERTIFICATE-----\n" );
			if ( $k ) {
				return $k;
			}
		}
		if ( empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return false;
		}
		$int = function ( $x ) {
			if ( ord( $x[0] ) > 0x7f ) {
				$x = "\0" . $x;
			}
			return "\x02" . self::len( strlen( $x ) ) . $x;
		};
		$seq  = $int( self::b64( $jwk['n'] ) ) . $int( self::b64( $jwk['e'] ) );
		$rsa  = "\x30" . self::len( strlen( $seq ) ) . $seq;
		$bits = "\x03" . self::len( strlen( $rsa ) + 1 ) . "\0" . $rsa;
		$alg  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$spki = "\x30" . self::len( strlen( $alg . $bits ) ) . $alg . $bits;
		return openssl_pkey_get_public( "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n" ); // phpcs:ignore
	}

	/** The verified token claims, or a WP_Error. */
	public static function verify( $jwt ) {
		$p = explode( '.', (string) $jwt );
		if ( 3 !== count( $p ) ) {
			return new WP_Error( 'gc_deploy', 'No signed GitHub token.', array( 'status' => 401 ) );
		}
		$head = json_decode( self::b64( $p[0] ), true );
		$body = json_decode( self::b64( $p[1] ), true );
		if ( ! is_array( $head ) || ! is_array( $body ) || 'RS256' !== ( $head['alg'] ?? '' ) ) {
			return new WP_Error( 'gc_deploy', 'Bad token.', array( 'status' => 401 ) );
		}
		$kid = $head['kid'] ?? '';
		$jwk = null;
		foreach ( array( false, true ) as $fresh ) {
			foreach ( self::jwks( $fresh ) as $k ) {
				if ( ( $k['kid'] ?? '' ) === $kid ) {
					$jwk = $k;
					break 2;
				}
			}
		}
		$key = $jwk ? self::key( $jwk ) : false;
		if ( ! $key || 1 !== openssl_verify( $p[0] . '.' . $p[1], self::b64( $p[2] ), $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'gc_deploy', 'Token signature not valid.', array( 'status' => 401 ) );
		}
		$now = time();
		$aud = (array) ( $body['aud'] ?? array() );
		if ( self::ISSUER !== ( $body['iss'] ?? '' ) || ! in_array( self::AUDIENCE, $aud, true )
			|| ( $body['exp'] ?? 0 ) < $now - 60 || ( $body['nbf'] ?? 0 ) > $now + 60 ) {
			return new WP_Error( 'gc_deploy', 'Token expired or not for this site.', array( 'status' => 401 ) );
		}
		if ( ! in_array( $body['repository'] ?? '', self::repos(), true ) ) {
			return new WP_Error( 'gc_deploy', 'This repository may not deploy here.', array( 'status' => 403 ) );
		}
		return $body;
	}

	public static function allowed( $req ) {
		$h = (string) $req->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $h, $m ) ) {
			return new WP_Error( 'gc_deploy', 'No signed GitHub token.', array( 'status' => 401 ) );
		}
		$claims = self::verify( trim( $m[1] ) );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		$req->set_param( '_gc_claims', $claims );
		return true;
	}

	/* ---------- Actions ---------- */

	private static function log( $entry ) {
		$l   = (array) get_option( self::LOG, array() );
		$l[] = $entry + array( 'time' => time() );
		update_option( self::LOG, array_slice( $l, -30 ), false );
	}

	public static function plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( get_plugins() as $file => $p ) {
			$out[] = array(
				'file'    => $file,
				'name'    => $p['Name'],
				'version' => $p['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		return $out;
	}

	/** POST a plugin zip as multipart field "zip". Installs it (replacing any copy) and activates it unless activate=0. */
	public static function plugin( $req ) {
		$claims = $req->get_param( '_gc_claims' );
		$files  = $req->get_file_params();
		if ( empty( $files['zip']['tmp_name'] ) || ! is_uploaded_file( $files['zip']['tmp_name'] ) ) {
			return new WP_Error( 'gc_deploy', 'Send the plugin zip as the "zip" field.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$tmp = wp_tempnam( 'gc-deploy.zip' );
		move_uploaded_file( $files['zip']['tmp_name'], $tmp );
		if ( ! WP_Filesystem() ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'gc_deploy', 'WordPress cannot write files here.', array( 'status' => 500 ) );
		}
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$ok       = $upgrader->install( $tmp, array( 'overwrite_package' => true ) );
		wp_delete_file( $tmp );
		$file = $upgrader->plugin_info();
		if ( ! $ok || is_wp_error( $ok ) || ! $file ) {
			$err = is_wp_error( $ok ) ? $ok->get_error_message() : implode( ' ', (array) $skin->get_error_messages() );
			self::log( array( 'ok' => false, 'error' => $err, 'sha' => $claims['sha'] ?? '', 'ref' => $claims['ref'] ?? '' ) );
			return new WP_Error( 'gc_deploy', 'Install failed: ' . $err, array( 'status' => 500 ) );
		}
		wp_clean_plugins_cache();
		$activated = false;
		if ( '0' !== (string) $req->get_param( 'activate' ) && ! is_plugin_active( $file ) ) {
			$a = activate_plugin( $file );
			if ( is_wp_error( $a ) ) {
				self::log( array( 'ok' => false, 'plugin' => $file, 'error' => 'Activate: ' . $a->get_error_message() ) );
				return new WP_Error( 'gc_deploy', 'Installed but did not activate: ' . $a->get_error_message(), array( 'status' => 500 ) );
			}
			$activated = true;
		}
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		$row  = array(
			'ok'        => true,
			'plugin'    => $file,
			'version'   => $data['Version'],
			'activated' => $activated,
			'active'    => is_plugin_active( $file ),
			'ref'       => $claims['ref'] ?? '',
			'sha'       => $claims['sha'] ?? '',
			'actor'     => $claims['actor'] ?? '',
			'run'       => isset( $claims['run_id'] ) ? 'https://github.com/' . $claims['repository'] . '/actions/runs/' . $claims['run_id'] : '',
		);
		self::log( $row );
		return $row;
	}

	/* ---------- Tools > Groupcade deploy ---------- */

	public static function menu() {
		add_management_page( 'Groupcade deploy', 'Groupcade deploy', 'manage_options', 'gc-deploy', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$log = array_reverse( (array) get_option( self::LOG, array() ) );
		echo '<div class="wrap"><h1>Groupcade deploy</h1>';
		echo '<p>GitHub Actions workflows in <code>' . esc_html( implode( ', ', self::repos() ) ) . '</code> can install and update plugins here. Each upload carries a token signed by GitHub, checked against GitHub\'s public keys, so there is no password stored on this site or in GitHub. Turn this plugin off to stop deploys.</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>When</th><th>Result</th><th>Plugin</th><th>Version</th><th>Branch</th><th>By</th><th>Run</th></tr></thead><tbody>';
		if ( ! $log ) {
			echo '<tr><td colspan="7">No deploys yet.</td></tr>';
		}
		foreach ( $log as $r ) {
			echo '<tr><td>' . esc_html( wp_date( 'M j, g:i a', (int) ( $r['time'] ?? 0 ) ) ) . '</td><td>' . ( ! empty( $r['ok'] ) ? 'Installed' : 'Failed: ' . esc_html( $r['error'] ?? '' ) ) . '</td><td>' . esc_html( $r['plugin'] ?? '' ) . '</td><td>' . esc_html( $r['version'] ?? '' ) . '</td><td>' . esc_html( preg_replace( '#^refs/heads/#', '', $r['ref'] ?? '' ) ) . '</td><td>' . esc_html( $r['actor'] ?? '' ) . '</td><td>' . ( ! empty( $r['run'] ) ? '<a href="' . esc_url( $r['run'] ) . '" target="_blank">View</a>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

GC_Deploy::init();
