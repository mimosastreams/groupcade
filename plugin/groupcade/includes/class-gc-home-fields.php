<?php
/**
 * The home page's words and pictures live on the real WordPress page set as the front page
 * (Pages > Home), edited with Advanced Custom Fields. The field group is registered here in
 * code, built from GC_Home::fields(), so it ships with the plugin and nothing needs importing
 * in ACF. Each field is stored as post meta "gc_<key>" on that page, so the home page still
 * renders (with the saved values) even while ACF is turned off.
 *
 * Before 1.3.0 these values were one option, gc_home_content, edited at Settings > Groupcade
 * home. migrate() copies them onto the page once and keeps the old option as a backup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Home_Fields {

	const GROUP  = 'group_gc_home';
	const PREFIX = 'gc_';

	public static function init() {
		add_action( 'acf/init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'migrate' ), 30 );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'classic_editor' ), 20, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'editor_note' ) );
		add_action( 'admin_notices', array( __CLASS__, 'acf_notice' ) );
	}

	/** Meta key for a field. */
	public static function meta( $key ) {
		return self::PREFIX . $key;
	}

	public static function field_key( $key ) {
		return 'field_gc_home_' . $key;
	}

	/**
	 * The page whose fields run the home page: the front page from Settings > Reading, or else a
	 * published page at /home/. 0 when there is neither.
	 */
	public static function page_id() {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$id = (int) get_option( 'page_on_front' );
			if ( $id && get_post( $id ) ) {
				return $id;
			}
		}
		$p = get_page_by_path( 'home' );
		return $p ? (int) $p->ID : 0;
	}

	/**
	 * Make sure a Home page exists and is the front page. Uses the current front page, or a page
	 * at /home/, or makes one. Returns its id.
	 */
	public static function ensure_page() {
		$id = self::page_id();
		if ( ! $id ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Home',
					'post_name'   => 'home',
				)
			);
		}
		if ( $id && ( 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_on_front' ) !== $id ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}
		return $id;
	}

	/** One-time move from the old option to the Home page. */
	public static function migrate() {
		if ( get_option( 'gc_home_fields_v' ) === '1' ) {
			return;
		}
		$id = self::ensure_page();
		if ( ! $id ) {
			return; // Try again next load.
		}
		$old    = get_option( 'gc_home_content', null );
		$fields = GC_Home::fields();
		if ( is_array( $old ) ) {
			foreach ( $old as $key => $v ) {
				if ( ! isset( $fields[ $key ] ) || metadata_exists( 'post', $id, self::meta( $key ) ) ) {
					continue;
				}
				update_post_meta( $id, self::meta( $key ), wp_slash( $v ) );
				update_post_meta( $id, '_' . self::meta( $key ), self::field_key( $key ) ); // ACF's pointer to the field.
			}
			update_option( 'gc_home_content_backup', $old, false );
			delete_option( 'gc_home_content' );
		}
		update_option( 'gc_home_fields_v', '1' );
	}

	/** Register the field group with ACF: one tab per section. */
	public static function register() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		$list = array(
			array(
				'key'       => 'field_gc_home_intro',
				'label'     => '',
				'name'      => '',
				'type'      => 'message',
				'message'   => 'Everything on the home page comes from these fields. Type {site} or {tv} in any field to show the site\'s address or the TV address. An empty field goes back to the built-in text, except a card or step title, where empty hides that card. The on/off switch and the App Store and Google Play links are at Groupcade > Settings.',
				'new_lines' => '',
			),
		);
		$tab = null;
		foreach ( GC_Home::fields() as $key => $f ) {
			if ( $f[0] !== $tab ) {
				$tab    = $f[0];
				$list[] = array(
					'key'       => 'field_gc_home_tab_' . sanitize_title( $tab ),
					'label'     => $tab,
					'name'      => '',
					'type'      => 'tab',
					'placement' => 'top',
				);
			}
			$field = array(
				'key'          => self::field_key( $key ),
				'label'        => $f[1],
				'name'         => self::meta( $key ),
				'type'         => $f[2],
				'instructions' => $f[4],
				'required'     => 0,
			);
			if ( 'image' === $f[2] ) {
				$field['return_format'] = 'id';
				$field['preview_size']  = 'medium';
				$field['library']       = 'all';
			} else {
				$field['default_value'] = (string) $f[3];
				$field['placeholder']   = (string) $f[3];
				if ( 'textarea' === $f[2] ) {
					$field['rows']      = 3;
					$field['new_lines'] = '';
				}
			}
			$list[] = $field;
		}

		$location = array( array( array( 'param' => 'page_type', 'operator' => '==', 'value' => 'front_page' ) ) );
		$id       = self::page_id();
		if ( $id && (int) get_option( 'page_on_front' ) !== $id ) {
			$location[] = array( array( 'param' => 'page', 'operator' => '==', 'value' => (string) $id ) );
		}

		acf_add_local_field_group(
			array(
				'key'                   => self::GROUP,
				'title'                 => 'Groupcade home page',
				'fields'                => $list,
				'location'              => $location,
				'menu_order'            => 0,
				'position'              => 'acf_after_title',
				'style'                 => 'seamless',
				'label_placement'       => 'left',
				'instruction_placement' => 'field',
				'hide_on_screen'        => array( 'the_content', 'excerpt', 'discussion', 'comments', 'featured_image', 'format', 'send-trackbacks', 'categories', 'tags' ),
				'active'                => true,
			)
		);
	}

	private static function is_home_page( $post ) {
		$post = get_post( $post );
		return $post && 'page' === $post->post_type && (int) $post->ID === self::page_id();
	}

	/** The page's own content isn't shown on the site, so edit it in the classic screen with only the fields. */
	public static function classic_editor( $use, $post ) {
		return self::is_home_page( $post ) ? false : $use;
	}

	public static function post_state( $states, $post ) {
		if ( self::is_home_page( $post ) && GC_Home::enabled() ) {
			$states['gc_home'] = 'Groupcade home page';
		}
		return $states;
	}

	public static function editor_note( $post ) {
		if ( ! self::is_home_page( $post ) ) {
			return;
		}
		echo '<div class="notice notice-info inline" style="margin:12px 0"><p>';
		if ( GC_Home::enabled() ) {
			echo 'This page is the Groupcade home page at <a href="' . esc_url( home_url( '/' ) ) . '" target="_blank">' . esc_html( home_url( '/' ) ) . '</a>. Edit its words and pictures below, then press Update.';
		} else {
			echo 'The Groupcade home page is turned off at Groupcade &gt; Settings, so your theme shows this page instead.';
		}
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			echo ' <strong>Turn on Advanced Custom Fields to see the home page fields.</strong>';
		}
		echo '</p></div>';
	}

	public static function acf_notice() {
		if ( function_exists( 'acf_add_local_field_group' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $s || ! in_array( $s->id, array( 'dashboard', 'plugins', 'page', 'edit-page' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>Groupcade: the home page text and pictures are edited on Pages &gt; Home with Advanced Custom Fields. Install and activate ACF (or ACF Pro) to edit them. The home page keeps showing the saved text meanwhile.</p></div>';
	}
}
