<?php
/**
 * REST API, namespace groupcade/v1. Everything goes through /wp-json/ (not challenged by the
 * host's bot check). POSTs need the header X-Groupcade: 1.
 *
 * GET  /games                       Games on this site (the iPhone app reads this).
 * POST /rooms                       Make a room. Body: game (slug) plus that game's settings.
 * GET  /rooms/CODE                  Room state. Headers: X-Party-Token (player) or X-Party-Host.
 * POST /rooms/CODE/join             Body: name. Returns token.
 * POST /rooms/CODE/action           Player action. Body: type plus the game's params (Trivia: answer, round, choice).
 * POST /rooms/CODE/host             Host action. Body: action plus params.
 * POST /rooms/CODE/chat             Body: msg.
 * POST /rooms/CODE/leave
 * GET  /app?code=&tv=1&cast=1&game= The game page, as a fallback when the pretty URLs are blocked.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Rest {

	const NS = 'groupcade/v1';

	public static function routes() {
		$open = '__return_true';
		$post = array( __CLASS__, 'post_ok' );
		$c    = '/rooms/(?P<code>[A-Za-z]{4})';
		register_rest_route( self::NS, '/app', array( 'methods' => 'GET', 'callback' => array( 'GC_Page', 'rest_app' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/games', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'games' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/rooms', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $post ) );
		register_rest_route( self::NS, $c, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'state' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, $c . '/join', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'join' ), 'permission_callback' => $post ) );
		register_rest_route( self::NS, $c . '/action', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'action' ), 'permission_callback' => $post ) );
		register_rest_route( self::NS, $c . '/host', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'host' ), 'permission_callback' => $post ) );
		register_rest_route( self::NS, $c . '/chat', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'chat' ), 'permission_callback' => $post ) );
		register_rest_route( self::NS, $c . '/leave', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'leave' ), 'permission_callback' => $post ) );
	}

	public static function post_ok( WP_REST_Request $req ) {
		return '1' === (string) $req->get_header( 'x_groupcade' );
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
		$room = GC_Room::load( $req['code'] );
		if ( ! $room ) {
			return new WP_Error( 'noroom', 'No game with that code. Check the code, or the game may have ended.', array( 'status' => 404 ) );
		}
		return $room;
	}

	public static function games() {
		return self::reply(
			array(
				'games'  => GC_Games::listing(),
				'tv'     => home_url( '/tv/' ),
				'site'   => home_url( '/' ),
				'ver'    => GC_VERSION,
			)
		);
	}

	public static function create( WP_REST_Request $req ) {
		self::nocache();
		if ( ! GC_Limits::hit( 'room', GC_Limits::ROOMS_PER_HOUR, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'slow', 'You have made a lot of rooms. Try again in a bit.', array( 'status' => 429 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $req->get_params();
		}
		$res = GC_Room::create( (string) $req->get_param( 'game' ), $params );
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
		$room = GC_Room::tick( $room );
		return self::reply(
			self::state_view(
				$room,
				(string) $req->get_header( 'x_party_token' ),
				(string) $req->get_header( 'x_party_host' ),
				(int) $req->get_param( 'chat' ),
				(string) $req->get_param( 'h' )
			)
		);
	}

	/**
	 * The room state a phone, the host or the TV polls for. Shared by the REST route and the
	 * fast path (GC_Fast), which answers the same request without loading all of WordPress.
	 *
	 * @param string $known The 'h' the caller got last time. When nothing changed since, the reply
	 *                      is a short { same: true } instead of the whole room.
	 */
	public static function state_view( $room, $tok, $host_tok, $chat_since, $known = '' ) {
		$host = GC_Room::is_host( $room, $host_tok );
		$me   = $tok ? GC_Room::player_by_token( $room, $tok ) : null;
		if ( $tok && ! $me ) {
			$view         = GC_Room::view( $room, null, $host );
			$view['gone'] = true; // Kicked, or the token is from an old room.
			return $view;
		}
		if ( $host || $me ) {
			GC_Room::touch( $room );
		}
		$view      = GC_Room::view( $room, $me, $host );
		$sig       = $view;
		unset( $sig['now'] );
		$view['h'] = substr( md5( (string) wp_json_encode( $sig ) ), 0, 12 );
		$chat      = GC_Chat::since( (int) $room['id'], $chat_since );
		if ( '' !== $known && hash_equals( $view['h'], $known ) ) {
			return array(
				'same' => true,
				'code' => $view['code'],
				'h'    => $view['h'],
				'now'  => $view['now'],
				'chat' => $chat,
			);
		}
		$view['chat'] = $chat;
		return $view;
	}

	public static function chat( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$me = GC_Room::player_by_token( $room, (string) $req->get_header( 'x_party_token' ) );
		if ( ! $me ) {
			return new WP_Error( 'noplayer', 'Join the game to chat.', array( 'status' => 403 ) );
		}
		$id = GC_Chat::add( $room, $me, $req->get_param( 'msg' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		GC_Room::touch( $room );
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
			$me = GC_Room::player_by_token( $room, $tok );
			if ( $me ) {
				return self::reply( array( 'token' => $tok, 'name' => $me['name'] ) );
			}
		}
		$name = GC_Limits::name( $req->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'name', 'Pick a different name.', array( 'status' => 400 ) );
		}
		if ( ! GC_Limits::hit( 'join', GC_Limits::JOINS_PER_10MIN, 600 ) ) {
			return new WP_Error( 'slow', 'Too many joins from this network. Wait a few minutes.', array( 'status' => 429 ) );
		}
		$res = GC_Room::join( $room, $name );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::reply( $res );
	}

	public static function action( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$room = GC_Room::tick( $room );
		$me   = GC_Room::player_by_token( $room, (string) $req->get_header( 'x_party_token' ) );
		if ( ! $me ) {
			return new WP_Error( 'gone', 'You are not in this game.', array( 'status' => 403 ) );
		}
		$res = GC_Room::game( $room )->player_action( $room, $me, sanitize_key( (string) $req->get_param( 'type' ) ), $req->get_params() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$fresh = GC_Room::load( $room['code'] );
		if ( $fresh ) {
			GC_Room::tick( $fresh ); // Move on at once if that was the last answer.
		}
		return self::reply( array( 'ok' => true ) );
	}

	public static function host( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		if ( ! GC_Room::is_host( $room, (string) $req->get_header( 'x_party_host' ) ) ) {
			return new WP_Error( 'nothost', 'Only the host can do that.', array( 'status' => 403 ) );
		}
		$room   = GC_Room::tick( $room );
		$action = sanitize_key( (string) $req->get_param( 'action' ) );
		if ( 'kick' === $action ) {
			$res = GC_Room::kick( $room, (int) $req->get_param( 'player' ) );
		} else {
			$res = GC_Room::game( $room )->host_action( $room, $action, $req->get_params() );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::reply( GC_Room::view( $room, null, true ) );
	}

	public static function leave( WP_REST_Request $req ) {
		self::nocache();
		$room = self::room_or_error( $req );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$me = GC_Room::player_by_token( $room, (string) $req->get_header( 'x_party_token' ) );
		if ( $me ) {
			GC_Room::leave( $room, $me );
		}
		return self::reply( array( 'ok' => true ) );
	}
}
