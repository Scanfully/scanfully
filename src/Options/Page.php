<?php

namespace Scanfully\Options;

class Page {

	public static $page = 'scanfully';

	public static function register(): void {
		add_action( 'admin_menu', function () {
			add_options_page(
				__( "Scanfully", "scanfully" ),
				__( "Scanfully", "scanfully" ),
				'manage_options',
				self::$page,
				[ Page::class, "render" ]
			);
		} );
	}

	public static function render(): void {
		?>
        <div class="wrap">
            <h1>Scanfully Settings</h1>
            <p><?php _e( "Welcome to Scanfully, your dashboard for your WordPress sites’ Performance and Health.", "scanfully" ); ?></p>
            <form action="options.php" method="post">
				<?php
				settings_fields( 'scanfully_plugin_options' );
				do_settings_sections( self::$page ); ?>
                <input name="submit" class="button button-primary" type="submit"
                       value="<?php esc_attr_e( __( "Save Changes", "scanfully" ) ); ?>"/>
            </form>
        </div>
		<?php
	}

}