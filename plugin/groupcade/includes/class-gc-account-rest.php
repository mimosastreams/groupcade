<?php
/**
 * REST routes for host accounts, namespace groupcade/v1. POSTs need X-Groupcade: 1 like the rest.
 * The app sends its session as X-Groupcade-Session; the account page and game pages use the
 * gc_session cookie (send web: true when signing in to get it).
 *
 * GET  /auth/config             Which sign-in buttons to show (client IDs, no secrets).
 * POST /auth/apple              id_token, code?, nonce?, name?, device?, web?
 * POST /auth/google             id_token, device?, web?
 * POST /auth/email/start        email
 * POST /auth/email/verify       email, code, device?, web?
 * POST /auth/logout             all? (every device)
 * GET  /me                      { user } (user is null when signed out)
 * POST /me                      name?, news?
 * POST /me/delete               Deletes the account and everything in it.
 * GET  /me/prefs?game=trivia    Saved settings for a game.
 * POST /me/prefs                game, settings
 * GET  /me/history              Past games and the most-wins board.
 * GET  /me/packs                Question packs.
 * POST /me/packs                name. Makes a pack.
 * GET  /me/packs/ID             A pack with its questions.
 * POST /me/packs/ID             name?, questions? (the whole list)
 * POST /me/packs/ID/delete
 * POST /rooms/CODE/claim        Header X-Party-Host. Saves the room (and its games so far) to the signed-in host.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Account_Rest {

	public static function routes() {
		$ns   = GC_Rest::NS;
		$open = '__return_true';
		$post = array( 'GC_Rest', 'post_ok' );
		$me   = array( __CLASS__, 'signed_in' );
		$mep  = array( __CLASS__, 'signed_in_post' );
		$r    = function ( $path, $method, $cb, $perm ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/auth/config', 'GET', 'config', $open );
		$r( '/auth/apple', 'POST', 'apple', $post );
		$r( '/auth/google', 'POST', 'google', $post );
		$r( '/auth/email/start', 'POST', 'email_start', $post );
		$r( '/auth/email/verify', 'POST', 'email_verify', $post );
		$r( '/auth/logout', 'POST', 'logout', $post );
		$r( '/me', 'GET', 'me', $open );
		$r( '/me', 'POST', 'update', $mep );
		$r( '/me/delete', 'POST', 'delete', $mep );
		$r( '/me/prefs', 'GET', 'prefs', $me );
		$r( '/me/prefs', 'POST', 'save_prefs', $mep );
		$r( '/me/history', 'GET', 'history', $me );
		$r( '/me/packs', 'GET', 'packs', $me );
		$r( '/me/packs', 'POST', 'pack_create', $mep );
		$r( '/me/packs/(?P<id>\d+)', 'GET', 'pack', $me );
		$r( '/me/packs/(?P<id>\d+)', 'POST', 'pack_save', $mep );
		$r( '/me/packs/(?P<id>\d+)/delete', 'POST', 'pack_delete', $mep );
		$r( '/rooms/(?P<code>[A-Za-z]{4})/claim', 'POST', 'claim', $mep );
	}

	public static function signed_in() {
		return GC_Accounts::current_id() > 0 ? true : new WP_Error( 'signedout', 'Sign in first.', array( 'status' => 401 ) );
	}

	public static function signed_in_post( WP_REST_Request $req ) {
		if ( ! GC_Rest::post_ok( $req ) ) {
			return false;
		}
		return self::signed_in();
	}

	private static function reply( $data, $status = 200 ) {
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store, max-age=0, private' );
		return $r;
	}

	private static function done( WP_REST_Request $req, $uid, $provider ) {
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$tok = GC_Accounts::start_session( $uid, (string) $req->get_param( 'device' ) );
		if ( $req->get_param( 'web' ) ) {
			GC_Accounts::set_cookie( $tok );
		}
		do_action( 'gc_signed_in', $uid, $provider );
		return self::reply(
			array(
				'session' => $tok,
				'user'    => GC_Accounts::profile( $uid ),
			)
		);
	}

	public static function config() {
		return self::reply( GC_Accounts::client_config() );
	}

	public static function apple( WP_REST_Request $req ) {
		if ( ! GC_Limits::hit( 'signin', 30, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'slow', 'Too many sign-ins from this network. Try again in a bit.', array( 'status' => 429 ) );
		}
		$res = GC_Auth::apple( (string) $req->get_param( 'id_token' ), (string) $req->get_param( 'code' ), (string) $req->get_param( 'nonce' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// Apple only sends the name the first time someone signs in, from the app or the page.
		$uid = GC_Accounts::sign_in( 'apple', $res[0], $res[1], (string) $req->get_param( 'name' ), $res[2] );
		return self::done( $req, $uid, 'apple' );
	}

	public static function google( WP_REST_Request $req ) {
		if ( ! GC_Limits::hit( 'signin', 30, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'slow', 'Too many sign-ins from this network. Try again in a bit.', array( 'status' => 429 ) );
		}
		$res = GC_Auth::google( (string) $req->get_param( 'id_token' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$uid = GC_Accounts::sign_in( 'google', $res[0], $res[1], $res[2] );
		return self::done( $req, $uid, 'google' );
	}

	public static function email_start( WP_REST_Request $req ) {
		$res = GC_Auth::email_start( (string) $req->get_param( 'email' ) );
		return is_wp_error( $res ) ? $res : self::reply( array( 'ok' => true, 'minutes' => GC_Accounts::CODE_MINUTES ) );
	}

	public static function email_verify( WP_REST_Request $req ) {
		$email = GC_Auth::email_verify( (string) $req->get_param( 'email' ), (string) $req->get_param( 'code' ) );
		if ( is_wp_error( $email ) ) {
			return $email;
		}
		$uid = GC_Accounts::sign_in( 'email', $email, $email, '' );
		return self::done( $req, $uid, 'email' );
	}

	public static function logout( WP_REST_Request $req ) {
		GC_Accounts::end_session( (bool) $req->get_param( 'all' ) );
		GC_Accounts::set_cookie( '' );
		return self::reply( array( 'ok' => true ) );
	}

	public static function me() {
		$uid = GC_Accounts::current_id();
		return self::reply( array( 'user' => $uid ? GC_Accounts::profile( $uid ) : null ) );
	}

	public static function update( WP_REST_Request $req ) {
		$uid = GC_Accounts::current_id();
		if ( null !== $req->get_param( 'name' ) ) {
			$name = GC_Accounts::clean_name( $req->get_param( 'name' ) );
			if ( '' === $name ) {
				return new WP_Error( 'name', 'Type a name.', array( 'status' => 400 ) );
			}
			wp_update_user( array( 'ID' => $uid, 'display_name' => $name, 'nickname' => $name ) );
		}
		if ( null !== $req->get_param( 'news' ) ) {
			$on = rest_sanitize_boolean( $req->get_param( 'news' ) );
			update_user_meta( $uid, 'gc_news', $on ? '1' : '0' );
			update_user_meta( $uid, 'gc_news_at', time() );
		}
		return self::reply( array( 'user' => GC_Accounts::profile( $uid ) ) );
	}

	public static function delete() {
		$res = GC_Accounts::delete( GC_Accounts::current_id() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		GC_Accounts::set_cookie( '' );
		return self::reply( array( 'ok' => true ) );
	}

	public static function prefs( WP_REST_Request $req ) {
		$game = sanitize_key( (string) $req->get_param( 'game' ) );
		return self::reply( array( 'game' => $game, 'settings' => GC_Accounts::prefs( GC_Accounts::current_id(), $game ) ) );
	}

	public static function save_prefs( WP_REST_Request $req ) {
		$game = sanitize_key( (string) $req->get_param( 'game' ) );
		if ( ! GC_Games::get( $game ) ) {
			return new WP_Error( 'nogame', 'Unknown game.', array( 'status' => 400 ) );
		}
		$set = GC_Accounts::save_prefs( GC_Accounts::current_id(), $game, (array) $req->get_param( 'settings' ) );
		return self::reply( array( 'game' => $game, 'settings' => $set ) );
	}

	public static function history() {
		return self::reply( GC_Accounts::history( GC_Accounts::current_id() ) );
	}

	public static function packs() {
		return self::reply( array( 'packs' => GC_Packs::list_for( GC_Accounts::current_id() ), 'max' => GC_Packs::MAX_PACKS, 'maxQuestions' => GC_Packs::MAX_QUESTIONS ) );
	}

	public static function pack_create( WP_REST_Request $req ) {
		$p = GC_Packs::create( GC_Accounts::current_id(), (string) $req->get_param( 'name' ) );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( null !== $req->get_param( 'questions' ) ) {
			$p = GC_Packs::save( GC_Accounts::current_id(), $p['id'], null, (array) $req->get_param( 'questions' ) );
		}
		return is_wp_error( $p ) ? $p : self::reply( array( 'pack' => $p ) );
	}

	public static function pack( WP_REST_Request $req ) {
		$p = GC_Packs::get( GC_Accounts::current_id(), (int) $req['id'] );
		return is_wp_error( $p ) ? $p : self::reply( array( 'pack' => $p ) );
	}

	public static function pack_save( WP_REST_Request $req ) {
		$q = $req->get_param( 'questions' );
		$p = GC_Packs::save( GC_Accounts::current_id(), (int) $req['id'], $req->get_param( 'name' ), null === $q ? null : (array) $q );
		return is_wp_error( $p ) ? $p : self::reply( array( 'pack' => $p ) );
	}

	public static function pack_delete( WP_REST_Request $req ) {
		$res = GC_Packs::delete( GC_Accounts::current_id(), (int) $req['id'] );
		return is_wp_error( $res ) ? $res : self::reply( array( 'ok' => true ) );
	}

	/** A guest host signs in after a game: the room and the games played in it so far become theirs. */
	public static function claim( WP_REST_Request $req ) {
		global $wpdb;
		$room = GC_Room::load( $req['code'] );
		if ( ! $room ) {
			return new WP_Error( 'noroom', 'That game has ended.', array( 'status' => 404 ) );
		}
		if ( ! GC_Room::is_host( $room, (string) $req->get_header( 'x_party_host' ) ) ) {
			return new WP_Error( 'nothost', 'Only the host can save this game.', array( 'status' => 403 ) );
		}
		$uid = GC_Accounts::current_id();
		$own = (int) ( $room['owner_id'] ?? 0 );
		if ( $own && $own !== $uid ) {
			return new WP_Error( 'taken', 'This game is already saved to another account.', array( 'status' => 409 ) );
		}
		$wpdb->update( GC_DB::rooms(), array( 'owner_id' => $uid ), array( 'id' => (int) $room['id'] ) ); // phpcs:ignore
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . GC_DB::matches() . ' SET owner_id = %d WHERE room_code = %s AND started >= %d AND owner_id = 0', $uid, $room['code'], (int) $room['created'] ) ); // phpcs:ignore
		// The game that just ended still has its players in the room: keep its final places too.
		if ( ! empty( $room['s']['gid'] ) && 'end' === ( $room['s']['phase'] ?? '' ) ) {
			$room['owner_id'] = $uid;
			$st               = GC_Room::game( $room )->standings( $room );
			if ( $st ) {
				$wpdb->update( GC_DB::matches(), array( 'standings' => wp_json_encode( $st ) ), array( 'id' => (int) $room['s']['gid'], 'owner_id' => $uid ) ); // phpcs:ignore
			}
		}
		return self::reply( array( 'ok' => true, 'games' => (int) $n ) );
	}
}
