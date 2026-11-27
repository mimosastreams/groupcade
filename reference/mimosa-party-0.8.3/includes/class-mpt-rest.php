<?php
/**
 * REST API, namespace mimosa-party/v1. Everything goes through /wp-json/ (not challenged by the host's bot check).
 * POSTs need the header X-Mimosa-Party: 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MPT_Rest {

	const NS = 'mimosa-party/v1';

	public static function routes() {
		$open = '__return_true';
		register_rest_route( self::NS, '/app', array( 'methods' => 'GET', 'callback' => array( 'MPT_Page', 'rest_app' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/rooms', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => array( __CLASS__, 'post_ok' ) ) );
		register_rest_route( self::NS, '/rooms/(?P<code>[A-Za-z]{4})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'state' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/rooms/(?P<code>[A-Za-z]{4})/join', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'join' ), 'permission_callback' => array( __CLASS__, 'post_ok' ) ) );
		register_rest_route( self::NS, '/rooms/(?P<code>[A-Za-z]{4})/answer', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'answer' ), 'permission_callback' => array( __CLASS__, 'post_ok' ) ) );
		register_rest_route( self::NS, '/rooms/(?P<code>[A-Za-z]{4})/host', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'host' ), 'permission_callback' => array( __CLASS__, 'post_ok' ) ) );
		register_rest_route( self::NS, '/rooms/(?P<code>[A-Za-z]{4})/chat', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'chat' ), 'permission_callback' => array( __CLASS__, 'post_ok' ) ) );
		register_rest_route( self::NS, '/rooms/(?P<code>[A-Za-z]{4})/leave', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'leave' ), 'permission_callback' => array( __CLASS__, 'post_ok' ) ) );
	}

	public static function post_ok( WP_REST_Request $req ) {
		return '1' === (string) $req->get_header( 'x_mimosa_party' );
	}

	private static function nocache() {
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	private static function reply( $data, $status = 200 ) {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store, max-age=0' );
		return $r;
	}

	private static function room_or_error( WP_REST_Request $req ) {
		$room = MPT_Room::load( $req['code'] );
		if ( ! $room ) {
			return new WP_Error( 'noroom', 'No game with that code. Check the code, or the game may have ended.', array( 'status' => 404 ) );
		}
		return $room;
	}

	public static function create( WP_REST_Request $req ) {
		self::nocache();
		if ( ! MPT_Limits::hit( 'room', MPT_Limits::ROOMS_PER_HOUR, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'slow', 'You have made a lot of rooms. Try again in a bit.', array( 'status' => 429 ) );
		}
		$res = MPT_Room::create( (int) $req->get_param( 'rounds' ), (int) $req->get_param( 'secs' ), $req->get_param( 'cats' ), (string) $req->get_param( 'mode' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::reply( $res );
	}

	public static function state( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$room = MPT_Room::tick( $room );
		$host = MPT_Room::is_host( $room, (string) $req->get_header( 'x_party_host' ) );
		$tok  = (string) $req->get_header( 'x_party_token' );
		$me   = $tok ? MPT_Room::player_by_token( $room, $tok ) : null;
		if ( $tok && ! $me ) {
			$view          = MPT_Room::view( $room, null, $host );
			$view['gone']  = true; // Kicked, or the token is from an old room.
			return self::reply( $view );
		}
		if ( $host || $me ) {
			MPT_Room::touch( $room );
		}
		$view         = MPT_Room::view( $room, $me, $host );
		$view['chat'] = MPT_Chat::since( (int) $room['id'], (int) $req->get_param( 'chat' ) );
		return self::reply( $view );
	}

	public static function chat( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$me = MPT_Room::player_by_token( $room, (string) $req->get_header( 'x_party_token' ) );
		if ( ! $me ) {
			return new WP_Error( 'noplayer', 'Join the game to chat.', array( 'status' => 403 ) );
		}
		$id = MPT_Chat::add( $room, $me, $req->get_param( 'msg' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		MPT_Room::touch( $room );
		return self::reply( array( 'ok' => true, 'id' => $id ) );
	}

	public static function join( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		// Rejoin with an existing token (page reload).
		$tok = (string) $req->get_header( 'x_party_token' );
		if ( $tok ) {
			$me = MPT_Room::player_by_token( $room, $tok );
			if ( $me ) {
				return self::reply( array( 'token' => $tok, 'name' => $me['name'] ) );
			}
		}
		$name = MPT_Limits::name( $req->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'name', 'Pick a different name.', array( 'status' => 400 ) );
		}
		if ( ! MPT_Limits::hit( 'join', MPT_Limits::JOINS_PER_10MIN, 600 ) ) {
			return new WP_Error( 'slow', 'Too many joins from this network. Wait a few minutes.', array( 'status' => 429 ) );
		}
		$res = MPT_Room::join( $room, $name );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::reply( $res );
	}

	public static function answer( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$room = MPT_Room::tick( $room );
		$me   = MPT_Room::player_by_token( $room, (string) $req->get_header( 'x_party_token' ) );
		if ( ! $me ) {
			return new WP_Error( 'gone', 'You are not in this game.', array( 'status' => 403 ) );
		}
		$res = MPT_Room::answer( $room, $me, (int) $req->get_param( 'round' ), (int) $req->get_param( 'choice' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$fresh = MPT_Room::load( $room['code'] );
		if ( $fresh ) {
			MPT_Room::tick( $fresh ); // Reveal at once if that was the last answer.
		}
		return self::reply( array( 'ok' => true ) );
	}

	public static function host( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		if ( ! MPT_Room::is_host( $room, (string) $req->get_header( 'x_party_host' ) ) ) {
			return new WP_Error( 'nothost', 'Only the host can do that.', array( 'status' => 403 ) );
		}
		$room = MPT_Room::tick( $room );
		$res  = MPT_Room::host_action(
			$room,
			sanitize_key( (string) $req->get_param( 'action' ) ),
			array(
				'player' => (int) $req->get_param( 'player' ),
				'rounds' => $req->get_param( 'rounds' ),
				'secs'   => $req->get_param( 'secs' ),
				'cats'   => $req->get_param( 'cats' ),
				'mode'   => (string) $req->get_param( 'mode' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::reply( MPT_Room::view( $room, null, true ) );
	}

	public static function leave( WP_REST_Request $req ) {
		global $wpdb;
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$me = MPT_Room::player_by_token( $room, (string) $req->get_header( 'x_party_token' ) );
		if ( $me ) {
			$wpdb->delete( MPT_DB::players(), array( 'id' => (int) $me['id'] ) ); // phpcs:ignore
		}
		return self::reply( array( 'ok' => true ) );
	}
}
