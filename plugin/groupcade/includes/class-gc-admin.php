<?php
/**
 * The Groupcade admin menu. It's the Questions post type's menu (GC_Trivia_Questions), with two
 * more pages under it: Analytics (rooms, games, players) and Settings (home page switch, app
 * links, Google Cast app id).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Admin {

	const PARENT = 'edit.php?post_type=gc_question';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( self::PARENT, 'Groupcade analytics', 'Analytics', 'manage_options', 'gc-analytics', array( __CLASS__, 'analytics' ) );
		add_submenu_page( self::PARENT, 'Groupcade settings', 'Settings', 'manage_options', 'gc-settings', array( __CLASS__, 'settings' ) );

		// Bookmarks to the old Settings > Groupcade, Settings > Groupcade home and Dashboard >
		// Groupcade stats pages land on the new ones.
		foreach ( array( 'groupcade', 'groupcade-home', 'groupcade-stats' ) as $old ) {
			$hook = add_submenu_page( '', 'Groupcade', '', 'manage_options', $old, '__return_null' );
			add_action( 'load-' . $hook, array( __CLASS__, 'old_link' ) );
		}
	}

	public static function url( $page ) {
		return admin_url( self::PARENT . '&page=' . $page );
	}

	public static function old_link() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'groupcade-home' === $page ) {
			$id = GC_Home_Fields::ensure_page();
			wp_safe_redirect( $id ? get_edit_post_link( $id, 'url' ) : admin_url( 'edit.php?post_type=page' ) );
		} else {
			wp_safe_redirect( self::url( 'groupcade-stats' === $page ? 'gc-analytics' : 'gc-settings' ) );
		}
		exit;
	}

	public static function register() {
		register_setting(
			'gc',
			'gc_cast_app',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) );
				},
				'default'           => '',
			)
		);
		register_setting(
			'gc',
			'gc_home',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return $v ? '1' : '0';
				},
				'default'           => '1',
			)
		);
		// Host sign-in. Plain IDs, plus the Sign in with Apple private key (used to revoke Apple's token
		// when a host deletes their account, which Apple requires).
		foreach ( array( 'gc_apple_services', 'gc_apple_bundle', 'gc_apple_team', 'gc_apple_key_id', 'gc_google_web', 'gc_google_ios', 'gc_google_android' ) as $opt ) {
			register_setting(
				'gc',
				$opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => function ( $v ) {
						return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $v );
					},
					'default'           => '',
				)
			);
		}
		register_setting(
			'gc',
			'gc_apple_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					$v = trim( (string) $v );
					// The field shows a placeholder once a key is saved; an untouched field keeps the old key.
					if ( '' === $v || '(saved)' === $v ) {
						return (string) get_option( 'gc_apple_key', '' );
					}
					if ( 'delete' === strtolower( $v ) ) {
						return '';
					}
					return preg_replace( '/[^A-Za-z0-9+\/=\-\n ]/', '', str_replace( "\r", '', $v ) );
				},
				'default'           => '',
			)
		);
		register_setting(
			'gc',
			'gc_paid_launch',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return $v ? (string) get_option( 'gc_paid_launch', '' ) ?: (string) time() : '';
				},
				'default'           => '',
			)
		);
		foreach ( array( 'gc_app_ios', 'gc_app_android' ) as $opt ) {
			register_setting(
				'gc',
				$opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'default'           => '',
				)
			);
		}
	}

	private static function dur( $sec ) {
		$sec = (int) round( $sec );
		return $sec >= 60 ? floor( $sec / 60 ) . 'm ' . ( $sec % 60 ) . 's' : $sec . 's';
	}

	/** Stats: rooms, games, players, length. Kept after rooms expire. */
	private static function stats() {
		global $wpdb;
		$g     = GC_DB::matches();
		$l     = GC_DB::room_log();
		$tz    = wp_timezone();
		$today = ( new DateTime( 'today', $tz ) )->getTimestamp();
		$spans = array(
			'Today'        => $today,
			'Last 7 days'  => time() - 7 * DAY_IN_SECONDS,
			'Last 30 days' => time() - 30 * DAY_IN_SECONDS,
			'All time'     => 0,
		);
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th></th>';
		foreach ( array_keys( $spans ) as $k ) {
			echo '<th>' . esc_html( $k ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rows = array();
		foreach ( $spans as $k => $from ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) rooms, COALESCE(SUM(joins),0) joins, SUM(games > 0) used FROM $l WHERE created >= %d", $from ), ARRAY_A ); // phpcs:ignore
			$q = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) games, COALESCE(SUM(players),0) players, AVG(players) avgp, MAX(players) maxp, AVG(CASE WHEN ended > 0 THEN ended - started END) avgd, AVG(CASE WHEN ended > 0 THEN rounds_played END) avgr, SUM(status = 1) done, SUM(status = 2) stopped, SUM(status = 0) open FROM $g WHERE started >= %d", $from ), ARRAY_A ); // phpcs:ignore
			$rows['Rooms made'][]                 = (int) $r['rooms'];
			$rows['Rooms that played a game'][]   = (int) $r['used'];
			$rows['Players joined (all rooms)'][] = (int) $r['joins'];
			$rows['Games started'][]              = (int) $q['games'];
			$rows['Players in games (total)'][]   = (int) $q['players'];
			$rows['Players per game (avg / most)'][] = $q['games'] ? number_format( (float) $q['avgp'], 1 ) . ' / ' . (int) $q['maxp'] : '–';
			$rows['Game length (avg)'][]          = null !== $q['avgd'] ? self::dur( $q['avgd'] ) : '–';
			$rows['Rounds per game (avg)'][]      = null !== $q['avgr'] ? number_format( (float) $q['avgr'], 1 ) : '–';
			$rows['Played to the end / ended early / left'][] = (int) $q['done'] . ' / ' . (int) $q['stopped'] . ' / ' . (int) $q['open'];
		}
		foreach ( $rows as $label => $vals ) {
			echo '<tr><th>' . esc_html( $label ) . '</th>';
			foreach ( $vals as $v ) {
				echo '<td>' . esc_html( (string) $v ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">"Players joined" counts every join, so someone who plays in two rooms counts twice. "Left" = games nobody finished (everyone closed the page). Times are in the site time zone.</p>';

		// Last 14 days.
		echo '<h3>Last 14 days</h3><table class="widefat striped" style="max-width:700px"><thead><tr><th>Day</th><th>Rooms</th><th>Games</th><th>Players in games</th></tr></thead><tbody>';
		for ( $i = 0; $i < 14; $i++ ) {
			$d0 = ( new DateTime( 'today', $tz ) )->modify( "-$i days" );
			$a  = $d0->getTimestamp();
			$b  = $a + DAY_IN_SECONDS;
			$rm = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE created >= %d AND created < %d", $a, $b ) ); // phpcs:ignore
			$gm = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(players),0) p FROM $g WHERE started >= %d AND started < %d", $a, $b ), ARRAY_A ); // phpcs:ignore
			echo '<tr><td>' . esc_html( $d0->format( 'D M j' ) ) . '</td><td>' . (int) $rm . '</td><td>' . (int) $gm['n'] . '</td><td>' . (int) $gm['p'] . '</td></tr>';
		}
		echo '</tbody></table>';

		// Recent games.
		$recent = $wpdb->get_results( "SELECT * FROM $g ORDER BY id DESC LIMIT 25", ARRAY_A ); // phpcs:ignore
		echo '<h3>Recent games</h3><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Started</th><th>Game</th><th>Room</th><th>Players</th><th>Rounds</th><th>Length</th><th>Result</th></tr></thead><tbody>';
		if ( ! $recent ) {
			echo '<tr><td colspan="7">No games yet.</td></tr>';
		}
		foreach ( $recent as $r ) {
			$len = (int) $r['ended'] ? self::dur( (int) $r['ended'] - (int) $r['started'] ) : '–';
			$res = 1 === (int) $r['status'] ? ( (int) $r['winners'] ? 'Won by ' . $r['winner_names'] : 'No winner' ) : ( 2 === (int) $r['status'] ? 'Host ended it' . ( $r['winner_names'] ? ' (' . $r['winner_names'] . ' standing)' : '' ) : 'Not finished' );
			echo '<tr><td>' . esc_html( wp_date( 'M j, g:i a', (int) $r['started'] ) ) . '</td><td>' . esc_html( ( $gg = GC_Games::get( $r['game'] ) ) ? $gg->name() : $r['game'] ) . '</td><td><code>' . esc_html( $r['room_code'] ) . '</code></td><td>' . (int) $r['players'] . '</td><td>' . (int) $r['rounds_played'] . ' of ' . ( (int) $r['rounds_set'] ? (int) $r['rounds_set'] : '∞' ) . '</td><td>' . esc_html( $len ) . '</td><td>' . esc_html( $res ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function analytics() {
		global $wpdb;
		$t      = GC_DB::rooms();
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE updated >= %d", time() - 1800 ) ); // phpcs:ignore
		?>
		<div class="wrap">
			<h1>Groupcade analytics</h1>
			<p>Games: <?php
			$links = array();
			foreach ( GC_Games::all() as $g ) {
				$links[] = '<a href="' . esc_url( home_url( '/' . $g->path() . '/' ) ) . '" target="_blank">' . esc_html( $g->name() ) . '</a>';
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>. TV view at <a href="<?php echo esc_url( home_url( '/tv/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/tv/' ) ); ?></a>. Rooms active in the last 30 minutes: <strong><?php echo (int) $active; ?></strong>.</p>
			<?php self::stats(); ?>
		</div>
		<?php
	}

	public static function settings() {
		$home = GC_Home_Fields::page_id();
		?>
		<div class="wrap">
			<h1>Groupcade settings</h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'gc' ); ?>
				<table class="form-table">
					<tr>
						<th>Home page</th>
						<td>
							<input type="hidden" name="gc_home" value="0">
							<label><input type="checkbox" name="gc_home" value="1" <?php checked( GC_Home::enabled() ); ?>> Use the Groupcade home page at <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/' ) ); ?></a></label>
							<p class="description">Turn this off to show your theme's front page instead. <?php if ( $home ) : ?><a href="<?php echo esc_url( get_edit_post_link( $home, 'url' ) ); ?>">Edit the home page text and pictures</a> on Pages &gt; <?php echo esc_html( get_the_title( $home ) ); ?>.<?php endif; ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gc_app_ios">App Store link</label></th>
						<td>
							<input id="gc_app_ios" name="gc_app_ios" type="url" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_app_ios', '' ) ); ?>" placeholder="https://apps.apple.com/app/...">
							<p class="description">Leave empty and the home page shows "iPhone app coming soon".</p>
						</td>
					</tr>
					<tr>
						<th><label for="gc_app_android">Google Play link</label></th>
						<td>
							<input id="gc_app_android" name="gc_app_android" type="url" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_app_android', '' ) ); ?>" placeholder="https://play.google.com/store/apps/details?id=...">
							<p class="description">Leave empty and the home page shows "Android app coming soon".</p>
						</td>
					</tr>
					<tr>
						<th><label for="gc_cast_app">Google Cast app ID</label></th>
						<td>
							<input id="gc_cast_app" name="gc_cast_app" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_cast_app', '' ) ); ?>" placeholder="e.g. A1B2C3D4">
							<p class="description">From the Google Cast SDK Developer Console (Custom Receiver). Receiver URL: <code><?php echo esc_html( rest_url( GC_Rest::NS . '/app' ) . '?cast=1' ); ?></code>. Leave empty to hide the Cast button.</p>
						</td>
					</tr>
					<tr><th colspan="2"><h2 style="margin:16px 0 0">Host sign-in</h2><p class="description" style="font-weight:400">Hosts can sign in to save their setup, game history and question packs. Players never sign in. Buttons only show once their IDs are filled in; email codes always work. Hosts so far: <?php echo (int) ( count_users()['avail_roles'][ GC_Accounts::ROLE ] ?? 0 ); ?> (<a href="<?php echo esc_url( admin_url( 'users.php?role=' . GC_Accounts::ROLE ) ); ?>">see them</a>). Account page: <a href="<?php echo esc_url( home_url( '/account/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/account/' ) ); ?></a></p></th></tr>
					<tr>
						<th><label for="gc_apple_bundle">Apple: app bundle ID</label></th>
						<td><input id="gc_apple_bundle" name="gc_apple_bundle" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_apple_bundle', '' ) ); ?>" placeholder="com.groupcade.app"><p class="description">Leave empty for com.groupcade.app.</p></td>
					</tr>
					<tr>
						<th><label for="gc_apple_services">Apple: Services ID (website)</label></th>
						<td><input id="gc_apple_services" name="gc_apple_services" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_apple_services', '' ) ); ?>" placeholder="com.groupcade.web"><p class="description">Shows the Apple button on the website. In the Services ID, add the domain <code><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></code> and the return URL <code><?php echo esc_html( home_url( '/account/' ) ); ?></code>.</p></td>
					</tr>
					<tr>
						<th><label for="gc_apple_team">Apple: Team ID</label></th>
						<td><input id="gc_apple_team" name="gc_apple_team" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_apple_team', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="gc_apple_key_id">Apple: Key ID</label></th>
						<td><input id="gc_apple_key_id" name="gc_apple_key_id" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_apple_key_id', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="gc_apple_key">Apple: private key (.p8)</label></th>
						<td><textarea id="gc_apple_key" name="gc_apple_key" class="large-text code" rows="3" placeholder="-----BEGIN PRIVATE KEY-----"><?php echo get_option( 'gc_apple_key', '' ) ? '(saved)' : ''; ?></textarea><p class="description">The Sign in with Apple key. Used only to cancel Apple's sign-in when a host deletes their account (Apple requires it). Type <code>delete</code> to remove it.</p></td>
					</tr>
					<tr>
						<th><label for="gc_google_web">Google: web client ID</label></th>
						<td><input id="gc_google_web" name="gc_google_web" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_google_web', '' ) ); ?>" placeholder="....apps.googleusercontent.com"><p class="description">Shows the Google button on the website. The Android app uses it too. Add <code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code> as an authorized JavaScript origin.</p></td>
					</tr>
					<tr>
						<th><label for="gc_google_ios">Google: iOS client ID</label></th>
						<td><input id="gc_google_ios" name="gc_google_ios" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_google_ios', '' ) ); ?>" placeholder="....apps.googleusercontent.com"><p class="description">Shows the Google button in the iPhone app.</p></td>
					</tr>
					<tr>
						<th><label for="gc_google_android">Google: Android client ID</label></th>
						<td><input id="gc_google_android" name="gc_google_android" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_google_android', '' ) ); ?>" placeholder="....apps.googleusercontent.com"><p class="description">Optional. Android sign-ins normally come in with the web client ID.</p></td>
					</tr>
					<tr>
						<th>Paid plans</th>
						<td>
							<input type="hidden" name="gc_paid_launch" value="">
							<label><input type="checkbox" name="gc_paid_launch" value="1" <?php checked( (bool) get_option( 'gc_paid_launch' ) ); ?>> Paid plans have launched</label>
							<p class="description">Until this is on, everyone who signs up is marked a founding member, so you can thank early hosts later.<?php if ( get_option( 'gc_paid_launch' ) ) : ?> Turned on <?php echo esc_html( wp_date( get_option( 'date_format' ), (int) get_option( 'gc_paid_launch' ) ) ); ?>.<?php endif; ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
