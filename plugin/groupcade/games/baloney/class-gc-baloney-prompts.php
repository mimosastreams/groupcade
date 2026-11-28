<?php
/**
 * Phony Baloney prompts as a post type, edited under Groupcade > Fake answer prompts.
 *
 * - Fields live in post meta: gc_cat (category key), gc_title (the big word, name or title),
 *   gc_sub (small line), gc_q (question, ___ marks a blank), gc_answer (the real answer),
 *   gc_accept (other ways to write the real answer, one per line) and gc_decoys (house fakes, one
 *   per line, used when too few players write fakes). The featured image is the prompt's picture
 *   (a photo for Who's That?, a poster for Movie Plot). Any category can have a picture.
 * - The built-in prompts in data/prompts.txt are copied in on first run. Games use the text file
 *   until then.
 * - Games read one cached option (gc_baloney_bank), rebuilt whenever a prompt changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Baloney_Prompts {

	const TYPE   = 'gc_baloney';
	const CACHE  = 'gc_baloney_bank';
	const IMPORT = 'gc_baloney_import';

	/** Category key => [ name, default question, hint for the admin ]. */
	public static function cats() {
		return array(
			'blank'   => array( 'Fill the Blank', '', 'A true fact with one part blanked out. Put ___ where the blank goes.' ),
			'word'    => array( 'Weird Word', 'What does it mean?', 'Title: the word. Small line: noun, verb, adjective…' ),
			'acronym' => array( 'Stands For…', 'What does it stand for?', 'Title: the letters.' ),
			'person'  => array( 'Who\'s That?', 'Why are they famous?', 'Title: the person\'s name. Small line: years and who they were. Add their photo as the featured image (public domain or one you have rights to).' ),
			'movie'   => array( 'Movie Plot', 'What\'s it about?', 'Title: the movie. Small line: the year. Add the poster as the featured image (one you have rights to).' ),
			'law'     => array( 'Strange Law', '', 'Title: the place. Question: the rule, with ___ for the blank.' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_action( 'admin_init', array( __CLASS__, 'import' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_hint' ), 10, 2 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'no_blocks' ), 10, 2 );
		foreach ( array( 'save_post_' . self::TYPE, 'trashed_post', 'untrashed_post', 'deleted_post' ) as $h ) {
			add_action( $h, array( __CLASS__, 'flush_post' ) );
		}
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_thumb' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_thumb' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'flush_thumb' ), 10, 3 );
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'               => 'Fake answer prompts',
					'singular_name'      => 'Prompt',
					'all_items'          => 'Fake answer prompts',
					'add_new'            => 'Add prompt',
					'add_new_item'       => 'Add prompt',
					'edit_item'          => 'Edit prompt',
					'new_item'           => 'New prompt',
					'search_items'       => 'Search prompts',
					'not_found'          => 'No prompts found',
					'not_found_in_trash' => 'No prompts in the trash',
					'featured_image'     => 'Picture',
					'set_featured_image' => 'Set picture',
					'remove_featured_image' => 'Remove picture',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=gc_question',
				'supports'        => array( 'title', 'thumbnail' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
				'show_in_rest'    => false,
			)
		);
		add_post_type_support( self::TYPE, 'thumbnail' );
	}

	public static function no_blocks( $use, $type ) {
		return self::TYPE === $type ? false : $use;
	}

	public static function title_hint( $text, $post ) {
		return self::TYPE === $post->post_type ? 'Short name for this prompt (only you see it)' : $text;
	}

	/* ---------- Edit screen ---------- */

	public static function meta_box() {
		add_meta_box( 'gc_baloney_box', 'Prompt', array( __CLASS__, 'box' ), self::TYPE, 'normal', 'high' );
	}

	private static function field( $id, $label, $value, $help = '', $area = false ) {
		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $area ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" class="large-text" rows="3">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" class="large-text" value="' . esc_attr( $value ) . '">';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function box( $post ) {
		wp_nonce_field( 'gc_baloney', 'gc_baloney_nonce' );
		$m   = function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $k, true );
		};
		$cat = $m( 'gc_cat' ) ? $m( 'gc_cat' ) : 'blank';
		echo '<table class="form-table" role="presentation"><tr><th><label for="gc_cat">Category</label></th><td><select id="gc_cat" name="gc_cat">';
		foreach ( self::cats() as $k => $c ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $cat, $k, false ) . '>' . esc_html( $c[0] ) . '</option>';
		}
		echo '</select><ul class="description" style="margin:8px 0 0">';
		foreach ( self::cats() as $c ) {
			echo '<li><b>' . esc_html( $c[0] ) . ':</b> ' . esc_html( $c[2] ) . '</li>';
		}
		echo '</ul></td></tr>';
		self::field( 'gc_title', 'Big title', $m( 'gc_title' ), 'The word, letters, name, movie or place shown big on the TV. Leave empty for Fill the Blank.' );
		self::field( 'gc_sub', 'Small line', $m( 'gc_sub' ), 'Optional. Shown under the title, like "noun" or "1964".' );
		self::field( 'gc_q', 'Question', $m( 'gc_q' ), 'Use ___ for the blank. Leave empty to use the category\'s question (for example "What does it mean?").', true );
		self::field( 'gc_answer', 'Real answer', $m( 'gc_answer' ), 'The true answer, written the way you want it revealed.' );
		self::field( 'gc_accept', 'Also counts as the real answer', $m( 'gc_accept' ), 'One per line. If a player types one of these as a fake, they are told "Too true!" and asked for a different fake.', true );
		self::field( 'gc_decoys', 'House fakes', $m( 'gc_decoys' ), 'One per line. Added to the choices when fewer than three players write a fake.', true );
		echo '</table><p class="description">A prompt is used once it is published and has a category, a question or title, and a real answer. Set a picture with the Picture box on the right.</p>';
	}

	public static function save( $id, $post ) {
		if ( ! isset( $_POST['gc_baloney_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['gc_baloney_nonce'] ) ), 'gc_baloney' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$cat = isset( $_POST['gc_cat'] ) ? sanitize_key( wp_unslash( $_POST['gc_cat'] ) ) : 'blank';
		update_post_meta( $id, 'gc_cat', isset( self::cats()[ $cat ] ) ? $cat : 'blank' );
		foreach ( array( 'gc_title', 'gc_sub', 'gc_answer' ) as $k ) {
			update_post_meta( $id, $k, wp_slash( isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '' ) );
		}
		foreach ( array( 'gc_q', 'gc_accept', 'gc_decoys' ) as $k ) {
			update_post_meta( $id, $k, wp_slash( isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '' ) );
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
			$out[ $k ] = 'title' === $k ? 'Prompt' : $v;
			if ( 'title' === $k ) {
				$out['gc_cat']    = 'Category';
				$out['gc_answer'] = 'Real answer';
				$out['gc_pic']    = 'Picture';
			}
		}
		return $out;
	}

	public static function column( $col, $id ) {
		if ( 'gc_cat' === $col ) {
			$c = (string) get_post_meta( $id, 'gc_cat', true );
			echo esc_html( isset( self::cats()[ $c ] ) ? self::cats()[ $c ][0] : '–' );
		} elseif ( 'gc_answer' === $col ) {
			echo esc_html( (string) get_post_meta( $id, 'gc_answer', true ) );
		} elseif ( 'gc_pic' === $col ) {
			echo has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, array( 48, 48 ) ) : '–'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ---------- Built-in prompts ---------- */

	/** The built-in prompts from data/prompts.txt, as rows for the bank (ids are negative line numbers). */
	public static function from_file() {
		$lines = @file( __DIR__ . '/data/prompts.txt', FILE_IGNORE_NEW_LINES ); // phpcs:ignore
		$out   = array();
		foreach ( (array) $lines as $n => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$f = array_map( 'trim', explode( '|', $line ) );
			if ( count( $f ) !== 7 || ! isset( self::cats()[ $f[0] ] ) || '' === $f[4] ) {
				continue;
			}
			$out[ -( $n + 1 ) ] = array(
				'cat'    => $f[0],
				'title'  => $f[1],
				'sub'    => $f[2],
				'q'      => $f[3],
				'answer' => $f[4],
				'accept' => self::split( $f[5], ';' ),
				'decoys' => self::split( $f[6], ';' ),
				'img'    => '',
			);
		}
		return $out;
	}

	private static function split( $s, $sep ) {
		return array_values( array_filter( array_map( 'trim', explode( $sep, (string) $s ) ), 'strlen' ) );
	}

	/** Copy the built-in prompts into the post type, once. Small enough to do in one go. */
	public static function import() {
		if ( 'done' === get_option( self::IMPORT ) || get_transient( 'gc_baloney_importing' ) ) {
			return;
		}
		set_transient( 'gc_baloney_importing', 1, 60 );
		$i = 0;
		foreach ( self::from_file() as $neg => $p ) {
			$line = -$neg;
			if ( get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'any', 'meta_key' => '_gc_line', 'meta_value' => $line, 'fields' => 'ids', 'numberposts' => 1 ) ) ) { // phpcs:ignore
				continue;
			}
			$name = '' !== $p['title'] ? $p['title'] : wp_trim_words( $p['q'], 8, '…' );
			wp_insert_post(
				wp_slash(
					array(
						'post_type'   => self::TYPE,
						'post_status' => 'publish',
						'post_title'  => $name,
						'menu_order'  => ++$i,
						'meta_input'  => array(
							'gc_cat'    => $p['cat'],
							'gc_title'  => $p['title'],
							'gc_sub'    => $p['sub'],
							'gc_q'      => $p['q'],
							'gc_answer' => $p['answer'],
							'gc_accept' => implode( "\n", $p['accept'] ),
							'gc_decoys' => implode( "\n", $p['decoys'] ),
							'_gc_line'  => $line,
						),
					)
				)
			);
		}
		update_option( self::IMPORT, 'done' );
		delete_transient( 'gc_baloney_importing' );
		self::flush();
	}

	/* ---------- What the game reads ---------- */

	public static function flush() {
		delete_option( self::CACHE );
	}

	public static function flush_post( $id ) {
		if ( self::TYPE === get_post_type( $id ) ) {
			self::flush();
		}
	}

	public static function flush_thumb( $meta_id, $post_id, $key ) {
		if ( '_thumbnail_id' === $key && self::TYPE === get_post_type( $post_id ) ) {
			self::flush();
		}
	}

	/** @return array id => prompt, for every complete, published prompt. */
	public static function load() {
		if ( 'done' !== get_option( self::IMPORT ) ) {
			return self::from_file();
		}
		$c = get_option( self::CACHE );
		if ( is_array( $c ) ) {
			return $c;
		}
		$ids = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'menu_order ID', 'order' => 'ASC' ) ); // phpcs:ignore
		$out = array();
		foreach ( $ids as $id ) {
			$g = function ( $k ) use ( $id ) {
				return trim( (string) get_post_meta( $id, $k, true ) );
			};
			$cat = $g( 'gc_cat' );
			$p   = array(
				'cat'    => $cat,
				'title'  => $g( 'gc_title' ),
				'sub'    => $g( 'gc_sub' ),
				'q'      => $g( 'gc_q' ),
				'answer' => $g( 'gc_answer' ),
				'accept' => self::split( $g( 'gc_accept' ), "\n" ),
				'decoys' => self::split( $g( 'gc_decoys' ), "\n" ),
				'img'    => has_post_thumbnail( $id ) ? (string) get_the_post_thumbnail_url( $id, 'large' ) : '',
			);
			if ( ! isset( self::cats()[ $cat ] ) || '' === $p['answer'] || ( '' === $p['q'] && '' === $p['title'] ) ) {
				continue;
			}
			$out[ (int) $id ] = $p;
		}
		update_option( self::CACHE, $out, false );
		return $out;
	}
}
