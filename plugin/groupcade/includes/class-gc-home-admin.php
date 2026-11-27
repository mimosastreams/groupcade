<?php
/**
 * Settings > Groupcade home: edit every word and picture on the home page.
 * The form is built from GC_Home::fields(), so a new field there shows up here by itself.
 * Saved as one option, gc_home_content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Home_Admin {

	const SLUG = 'groupcade-home';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_gc_home_reset', array( __CLASS__, 'reset' ) );
	}

	public static function menu() {
		add_options_page( 'Groupcade home page', 'Groupcade home', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'gc_home_content',
			GC_Home::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize( $in ) {
		$out = array();
		$in  = is_array( $in ) ? $in : array();
		foreach ( GC_Home::fields() as $key => $f ) {
			$v = isset( $in[ $key ] ) ? wp_unslash( $in[ $key ] ) : '';
			switch ( $f[2] ) {
				case 'image':
					$out[ $key ] = absint( $v );
					break;
				case 'textarea':
					$out[ $key ] = sanitize_textarea_field( $v );
					break;
				default:
					$out[ $key ] = sanitize_text_field( $v );
			}
		}
		return $out;
	}

	public static function reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gc_home_reset' );
		delete_option( GC_Home::OPTION );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&reset=1' ) );
		exit;
	}

	public static function assets( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_style(
			'common',
			'.gc-home-form .form-table th{width:220px}.gc-home-form textarea{width:100%;max-width:640px}.gc-home-form input.regular-text{width:100%;max-width:640px}'
			. '.gc-img{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.gc-img img{width:120px;height:80px;object-fit:contain;background:#CFEBFF;border:1px solid #c3c4c7;border-radius:6px}'
			. '.gc-sec{margin-top:28px;padding-top:8px;border-top:1px solid #dcdcde}.gc-toc a{margin-right:14px}'
		);
		wp_add_inline_script(
			'media-editor',
			"jQuery(function($){\n"
			. " $(document).on('click','.gc-img-pick',function(e){e.preventDefault();var box=$(this).closest('.gc-img');var f=wp.media({title:'Choose a picture',button:{text:'Use this picture'},library:{type:'image'},multiple:false});\n"
			. "  f.on('select',function(){var a=f.state().get('selection').first().toJSON();box.find('input').val(a.id);box.find('img').attr('src',(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url));});f.open();});\n"
			. " $(document).on('click','.gc-img-clear',function(e){e.preventDefault();var box=$(this).closest('.gc-img');box.find('input').val('0');box.find('img').attr('src',box.data('default'));});\n"
			. '});'
		);
	}

	private static function default_img( $key ) {
		$map = array(
			'share_image' => 'og-image-1200x630.png',
			'hero_image'  => 'groupcade-mascots.svg',
			'host_image'  => 'groupcade-logo.svg',
		);
		return plugins_url( 'assets/brand/' . ( isset( $map[ $key ] ) ? $map[ $key ] : 'groupcade-logo.svg' ), GC_FILE );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$fields   = GC_Home::fields();
		$sections = array();
		foreach ( $fields as $key => $f ) {
			$sections[ $f[0] ][ $key ] = $f;
		}
		$name = GC_Home::OPTION;
		?>
		<div class="wrap gc-home-form">
			<h1>Groupcade home page</h1>
			<?php if ( isset( $_GET['reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p>All home page text and pictures are back to the defaults.</p></div>
			<?php endif; ?>
			<p>Everything on <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/' ) ); ?></a> comes from this page. Type <code>{site}</code> or <code>{tv}</code> in any field to show the site's address or the TV address. Game names, the App Store and Google Play links, and turning the home page off are on <a href="<?php echo esc_url( admin_url( 'options-general.php?page=groupcade' ) ); ?>">Settings &gt; Groupcade</a>.</p>
			<?php if ( ! GC_Home::enabled() ) : ?>
				<div class="notice notice-warning inline"><p>The Groupcade home page is turned off, so your theme's front page shows instead. Turn it on at Settings &gt; Groupcade.</p></div>
			<?php endif; ?>
			<p class="gc-toc">
				<?php foreach ( array_keys( $sections ) as $s ) : ?>
					<a href="#<?php echo esc_attr( sanitize_title( $s ) ); ?>"><?php echo esc_html( $s ); ?></a>
				<?php endforeach; ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'gc_home_content' ); ?>
				<?php foreach ( $sections as $title => $list ) : ?>
					<h2 class="gc-sec" id="<?php echo esc_attr( sanitize_title( $title ) ); ?>"><?php echo esc_html( $title ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						foreach ( $list as $key => $f ) :
							$id  = 'gc_home_' . $key;
							$val = GC_Home::get( $key );
							?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $f[1] ); ?></label></th>
								<td>
									<?php if ( 'image' === $f[2] ) : ?>
										<?php
										$src = (int) $val ? wp_get_attachment_image_url( (int) $val, 'medium' ) : '';
										$def = self::default_img( $key );
										?>
										<div class="gc-img" data-default="<?php echo esc_url( $def ); ?>">
											<img src="<?php echo esc_url( $src ? $src : $def ); ?>" alt="">
											<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="<?php echo (int) $val; ?>">
											<button type="button" class="button gc-img-pick">Choose picture</button>
											<button type="button" class="button-link gc-img-clear">Use the built-in one</button>
										</div>
									<?php elseif ( 'textarea' === $f[2] ) : ?>
										<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" rows="3"><?php echo esc_textarea( (string) $val ); ?></textarea>
									<?php else : ?>
										<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $val ); ?>">
									<?php endif; ?>
									<?php if ( '' !== $f[4] ) : ?>
										<p class="description"><?php echo esc_html( $f[4] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<?php submit_button( 'Save home page' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Put every home page field back to the default text and pictures?');">
				<input type="hidden" name="action" value="gc_home_reset">
				<?php wp_nonce_field( 'gc_home_reset' ); ?>
				<?php submit_button( 'Restore all defaults', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
