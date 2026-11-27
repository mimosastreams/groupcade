<?php
/**
 * Trivia questions as a post type, so they're edited in the admin under Groupcade > Questions.
 *
 * - The question text is the post title. Level (easy/medium/hard), the right answer and three
 *   wrong answers are post meta (gc_level, gc_right, gc_wrong1..3), edited in a box on the
 *   question screen. Categories are the gc_question_cat taxonomy.
 * - Only published questions with every field filled in are used in games.
 * - On first run the questions in data/questions.txt are imported, a few hundred per page load
 *   (admin or WP-Cron), so the upgrade never times out. Games keep using the text file until the
 *   import is done.
 * - GC_Trivia_Bank reads everything from one cached option (gc_trivia_bank), rebuilt after any
 *   question or category changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Trivia_Questions {

	const TYPE   = 'gc_question';
	const TAX    = 'gc_question_cat';
	const CACHE  = 'gc_trivia_bank';
	const IMPORT = 'gc_trivia_import'; // Next line to import, or 'done'.
	const BATCH  = 300;
	const LEVELS = array(
		'easy'   => 'Easy',
		'medium' => 'Medium',
		'hard'   => 'Hard',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'import_step' ) );
		add_action( 'gc_trivia_import', array( __CLASS__, 'import_step' ) );
		add_action( 'init', array( __CLASS__, 'schedule_import' ), 40 );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_hint' ), 10, 2 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'import_notice' ) );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'no_blocks' ), 10, 2 );

		// Anything that changes a question or category clears the cache.
		foreach ( array( 'save_post_' . self::TYPE, 'trashed_post', 'untrashed_post', 'deleted_post' ) as $h ) {
			add_action( $h, array( __CLASS__, 'flush_post' ) );
		}
		foreach ( array( 'created_' . self::TAX, 'edited_' . self::TAX, 'delete_' . self::TAX ) as $h ) {
			add_action( $h, array( __CLASS__, 'flush' ) );
		}
		add_action( 'set_object_terms', array( __CLASS__, 'flush_terms' ), 10, 4 );
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'               => 'Questions',
					'singular_name'      => 'Question',
					'menu_name'          => 'Groupcade',
					'all_items'          => 'Questions',
					'add_new'            => 'Add question',
					'add_new_item'       => 'Add question',
					'edit_item'          => 'Edit question',
					'new_item'           => 'New question',
					'view_item'          => 'View question',
					'search_items'       => 'Search questions',
					'not_found'          => 'No questions found',
					'not_found_in_trash' => 'No questions in the trash',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 3,
				'menu_icon'       => 'dashicons-games',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
				'show_in_rest'    => false,
			)
		);
		register_taxonomy(
			self::TAX,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => 'Categories',
					'singular_name' => 'Category',
					'all_items'     => 'All categories',
					'edit_item'     => 'Edit category',
					'add_new_item'  => 'Add category',
					'search_items'  => 'Search categories',
					'not_found'     => 'No categories found',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
				'show_in_rest'      => false,
			)
		);
	}

	public static function no_blocks( $use, $type ) {
		return self::TYPE === $type ? false : $use;
	}

	public static function title_hint( $text, $post ) {
		return self::TYPE === $post->post_type ? 'Type the question' : $text;
	}

	/* ---------- Edit screen ---------- */

	public static function meta_box() {
		add_meta_box( 'gc_answers', 'Level and answers', array( __CLASS__, 'box' ), self::TYPE, 'normal', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'gc_question', 'gc_question_nonce' );
		$lvl = (string) get_post_meta( $post->ID, 'gc_level', true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="gc_level">Level</label></th>
				<td>
					<select id="gc_level" name="gc_level">
						<?php foreach ( self::LEVELS as $k => $v ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $lvl ? $lvl : 'medium', $k ); ?>><?php echo esc_html( $v ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Games start with easy questions and get harder.</p>
				</td>
			</tr>
			<tr>
				<th><label for="gc_right">Right answer</label></th>
				<td><input id="gc_right" name="gc_right" class="regular-text" value="<?php echo esc_attr( get_post_meta( $post->ID, 'gc_right', true ) ); ?>"></td>
			</tr>
			<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
				<tr>
					<th><label for="gc_wrong<?php echo (int) $i; ?>">Wrong answer <?php echo (int) $i; ?></label></th>
					<td><input id="gc_wrong<?php echo (int) $i; ?>" name="gc_wrong<?php echo (int) $i; ?>" class="regular-text" value="<?php echo esc_attr( get_post_meta( $post->ID, 'gc_wrong' . $i, true ) ); ?>"></td>
				</tr>
			<?php endfor; ?>
		</table>
		<p class="description">Players see the four answers in a random order. A question is only used once it is published, has a category and has all four answers.</p>
		<?php
	}

	public static function save( $id, $post ) {
		if ( ! isset( $_POST['gc_question_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['gc_question_nonce'] ) ), 'gc_question' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$lvl = isset( $_POST['gc_level'] ) ? sanitize_key( wp_unslash( $_POST['gc_level'] ) ) : 'medium';
		update_post_meta( $id, 'gc_level', isset( self::LEVELS[ $lvl ] ) ? $lvl : 'medium' );
		foreach ( array( 'gc_right', 'gc_wrong1', 'gc_wrong2', 'gc_wrong3' ) as $k ) {
			$v = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
			update_post_meta( $id, $k, wp_slash( $v ) );
		}
		self::flush();
	}

	/* ---------- List screen ---------- */

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				continue;
			}
			$out[ $k ] = 'title' === $k ? 'Question' : $v;
			if ( 'title' === $k ) {
				$out['gc_right'] = 'Right answer';
				$out['gc_level'] = 'Level';
			}
		}
		return $out;
	}

	public static function column( $col, $id ) {
		if ( 'gc_right' === $col ) {
			echo esc_html( (string) get_post_meta( $id, 'gc_right', true ) );
		} elseif ( 'gc_level' === $col ) {
			$l = (string) get_post_meta( $id, 'gc_level', true );
			echo esc_html( isset( self::LEVELS[ $l ] ) ? self::LEVELS[ $l ] : '–' );
		}
	}

	public static function filters( $type ) {
		if ( self::TYPE !== $type ) {
			return;
		}
		wp_dropdown_categories(
			array(
				'taxonomy'        => self::TAX,
				'name'            => self::TAX,
				'value_field'     => 'slug',
				'show_option_all' => 'All categories',
				'selected'        => isset( $_GET[ self::TAX ] ) ? sanitize_title( wp_unslash( $_GET[ self::TAX ] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'hide_empty'      => false,
				'orderby'         => 'name',
			)
		);
		$cur = isset( $_GET['gc_level'] ) ? sanitize_key( wp_unslash( $_GET['gc_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="gc_level"><option value="">All levels</option>';
		foreach ( self::LEVELS as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select>';
	}

	public static function filter_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || self::TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		$lvl = isset( $_GET['gc_level'] ) ? sanitize_key( wp_unslash( $_GET['gc_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( self::LEVELS[ $lvl ] ) ) {
			$q->set( 'meta_key', 'gc_level' );
			$q->set( 'meta_value', $lvl );
		}
		$cat = isset( $_GET[ self::TAX ] ) ? sanitize_title( wp_unslash( $_GET[ self::TAX ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $cat && '0' !== $cat ) {
			$q->set(
				'tax_query',
				array(
					array(
						'taxonomy' => self::TAX,
						'field'    => 'slug',
						'terms'    => $cat,
					),
				)
			);
		}
	}

	/* ---------- Import from data/questions.txt ---------- */

	public static function imported() {
		return 'done' === get_option( self::IMPORT );
	}

	public static function schedule_import() {
		if ( ! self::imported() && ! wp_next_scheduled( 'gc_trivia_import' ) ) {
			wp_schedule_single_event( time() + 30, 'gc_trivia_import' );
		}
	}

	public static function import_step() {
		if ( self::imported() ) {
			return;
		}
		// One importer at a time.
		if ( get_transient( 'gc_trivia_importing' ) ) {
			return;
		}
		set_transient( 'gc_trivia_importing', 1, 60 );

		$lines = @file( GC_DIR . 'games/trivia/data/questions.txt', FILE_IGNORE_NEW_LINES ); // phpcs:ignore
		$lines = $lines ? $lines : array();
		$from  = (int) get_option( self::IMPORT, 0 );
		$to    = min( count( $lines ), $from + self::BATCH );

		wp_defer_term_counting( true );
		$terms = array();
		for ( $n = $from; $n < $to; $n++ ) {
			$line = trim( $lines[ $n ] );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$f = array_map( 'trim', explode( '|', $line ) );
			if ( count( $f ) !== 7 ) {
				continue;
			}
			$lvl = strtolower( $f[0] );
			if ( ! isset( self::LEVELS[ $lvl ] ) ) {
				continue;
			}
			$line_no = $n + 1;
			if ( get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'any', 'meta_key' => '_gc_line', 'meta_value' => $line_no, 'fields' => 'ids', 'numberposts' => 1 ) ) ) { // phpcs:ignore
				continue; // Already imported (a step that was cut off).
			}
			if ( ! isset( $terms[ $f[1] ] ) ) {
				$t = term_exists( $f[1], self::TAX );
				if ( ! $t ) {
					$t = wp_insert_term( $f[1], self::TAX );
				}
				$terms[ $f[1] ] = is_wp_error( $t ) ? 0 : (int) $t['term_id'];
			}
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => self::TYPE,
						'post_status' => 'publish',
						'post_title'  => $f[2],
						'menu_order'  => $line_no,
						'meta_input'  => array(
							'gc_level'  => $lvl,
							'gc_right'  => $f[3],
							'gc_wrong1' => $f[4],
							'gc_wrong2' => $f[5],
							'gc_wrong3' => $f[6],
							'_gc_line'  => $line_no,
						),
					)
				),
				true
			);
			if ( ! is_wp_error( $id ) && $terms[ $f[1] ] ) {
				wp_set_object_terms( $id, array( $terms[ $f[1] ] ), self::TAX );
			}
		}
		wp_defer_term_counting( false );

		if ( $to >= count( $lines ) ) {
			update_option( self::IMPORT, 'done' );
			delete_option( 'gc_trivia_recent' ); // Those were line numbers; ids are post ids now.
			self::flush();
		} else {
			update_option( self::IMPORT, $to );
			if ( ! wp_next_scheduled( 'gc_trivia_import' ) ) {
				wp_schedule_single_event( time() + 5, 'gc_trivia_import' );
			}
		}
		delete_transient( 'gc_trivia_importing' );
	}

	public static function import_notice() {
		if ( self::imported() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$lines = @file( GC_DIR . 'games/trivia/data/questions.txt' ); // phpcs:ignore
		$total = $lines ? count( $lines ) : 0;
		$at    = (int) get_option( self::IMPORT, 0 );
		echo '<div class="notice notice-info"><p>Groupcade is copying the trivia questions into Groupcade &gt; Questions (' . (int) min( 100, $total ? round( 100 * $at / $total ) : 100 ) . '% done). It keeps going as you use the admin. Games use the built-in questions until it finishes.</p></div>';
	}

	/* ---------- What the game reads ---------- */

	public static function flush() {
		delete_option( self::CACHE );
		GC_Trivia_Bank::reset();
	}

	public static function flush_post( $id ) {
		if ( self::TYPE === get_post_type( $id ) ) {
			self::flush();
		}
	}

	public static function flush_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( self::TAX === $taxonomy ) {
			self::flush();
		}
	}

	/** @return array id => [level, cat, q, right, w1, w2, w3] for every complete, published question. */
	public static function load() {
		$c = get_option( self::CACHE );
		if ( is_array( $c ) ) {
			return $c;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY menu_order, ID", self::TYPE ), ARRAY_A ); // phpcs:ignore
		$meta = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_key IN ('gc_level','gc_right','gc_wrong1','gc_wrong2','gc_wrong3')", self::TYPE ), ARRAY_A ) as $m ) { // phpcs:ignore
			$meta[ (int) $m['post_id'] ][ $m['meta_key'] ] = trim( (string) $m['meta_value'] );
		}
		$cats = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, t.name FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY t.term_id", self::TAX ), ARRAY_A ) as $r ) { // phpcs:ignore
			if ( ! isset( $cats[ (int) $r['object_id'] ] ) ) {
				$cats[ (int) $r['object_id'] ] = wp_specialchars_decode( $r['name'], ENT_QUOTES );
			}
		}
		$out = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['ID'];
			$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$q  = array(
				isset( $m['gc_level'] ) ? $m['gc_level'] : '',
				isset( $cats[ $id ] ) ? $cats[ $id ] : '',
				trim( wp_specialchars_decode( $r['post_title'], ENT_QUOTES ) ),
				isset( $m['gc_right'] ) ? $m['gc_right'] : '',
				isset( $m['gc_wrong1'] ) ? $m['gc_wrong1'] : '',
				isset( $m['gc_wrong2'] ) ? $m['gc_wrong2'] : '',
				isset( $m['gc_wrong3'] ) ? $m['gc_wrong3'] : '',
			);
			if ( ! isset( self::LEVELS[ $q[0] ] ) || in_array( '', $q, true ) ) {
				continue;
			}
			$out[ $id ] = $q;
		}
		update_option( self::CACHE, $out, false );
		return $out;
	}
}
