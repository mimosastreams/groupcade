<?php
/**
 * Settings > Survive Party: the Google Cast app id (turns on the Cast button) and a live room count.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MPT_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_options_page( 'Survive Party', 'Survive Party', 'manage_options', 'mimosa-party', array( __CLASS__, 'page' ) );
		add_dashboard_page( 'Party stats', 'Party stats', 'manage_options', 'mimosa-party-stats', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'mpt',
			'mpt_cast_app',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) );
				},
				'default'           => '',
			)
		);
	}

	private static function dur( $sec ) {
		$sec = (int) round( $sec );
		return $sec >= 60 ? floor( $sec / 60 ) . 'm ' . ( $sec % 60 ) . 's' : $sec . 's';
	}

	/** Party stats: rooms, games, players, length. Kept after rooms expire. */
	private static function stats() {
		global $wpdb;
		$g     = MPT_DB::games();
		$l     = MPT_DB::room_log();
		$tz    = wp_timezone();
		$today = ( new DateTime( 'today', $tz ) )->getTimestamp();
		$spans = array(
			'Today'        => $today,
			'Last 7 days'  => time() - 7 * DAY_IN_SECONDS,
			'Last 30 days' => time() - 30 * DAY_IN_SECONDS,
			'All time'     => 0,
		);
		echo '<h2>Stats</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th></th>';
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
		echo '<h3>Recent games</h3><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Started</th><th>Room</th><th>Players</th><th>Rounds</th><th>Length</th><th>Result</th></tr></thead><tbody>';
		if ( ! $recent ) {
			echo '<tr><td colspan="6">No games yet.</td></tr>';
		}
		foreach ( $recent as $r ) {
			$len = (int) $r['ended'] ? self::dur( (int) $r['ended'] - (int) $r['started'] ) : '–';
			$res = 1 === (int) $r['status'] ? ( (int) $r['winners'] ? 'Won by ' . $r['winner_names'] : 'No winner' ) : ( 2 === (int) $r['status'] ? 'Host ended it' . ( $r['winner_names'] ? ' (' . $r['winner_names'] . ' standing)' : '' ) : 'Not finished' );
			echo '<tr><td>' . esc_html( wp_date( 'M j, g:i a', (int) $r['started'] ) ) . '</td><td><code>' . esc_html( $r['room_code'] ) . '</code></td><td>' . (int) $r['players'] . '</td><td>' . (int) $r['rounds_played'] . ' of ' . ( (int) $r['rounds_set'] ? (int) $r['rounds_set'] : '∞' ) . '</td><td>' . esc_html( $len ) . '</td><td>' . esc_html( $res ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function page() {
		global $wpdb;
		$t      = MPT_DB::rooms();
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE updated >= %d", time() - 1800 ) ); // phpcs:ignore
		?>
		<div class="wrap">
			<h1>Survive Party</h1>
			<p>Party game at <a href="<?php echo esc_url( home_url( '/party/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/party/' ) ); ?></a>, TV view at <a href="<?php echo esc_url( home_url( '/party/tv/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/party/tv/' ) ); ?></a>. Rooms active in the last 30 minutes: <strong><?php echo (int) $active; ?></strong>.</p>
			<?php self::stats(); ?>
			<h2>Cast</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'mpt' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="mpt_cast_app">Google Cast app ID</label></th>
						<td>
							<input id="mpt_cast_app" name="mpt_cast_app" class="regular-text" value="<?php echo esc_attr( get_option( 'mpt_cast_app', '' ) ); ?>" placeholder="e.g. A1B2C3D4">
							<p class="description">From the Google Cast SDK Developer Console (Custom Receiver). Receiver URL: <code><?php echo esc_html( rest_url( 'mimosa-party/v1/app' ) . '?cast=1' ); ?></code>. Leave empty to hide the Cast button.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
