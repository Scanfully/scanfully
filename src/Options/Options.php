<?php
/**
 * The options class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Options;

/**
 * The options class handles everything related to the plugin options.
 */
class Options {

	/**
	 * The options key
	 *
	 * @var string
	 */
	private static $key = 'scanfully_plugin_options';

	/**
	 * Variable where we store the options
	 *
	 * @var array
	 */
	private static $options = [];

	/**
	 * Register the options page
	 *
	 * @return void
	 */
	public static function register(): void {

		self::$options = [
			[
				'label'       => __( 'Site ID', 'scanfully' ),
				'name'        => 'site_id',
				'type'        => 'text',
				'placeholder' => '####-####-####',
			],
			[
				'label'       => __( 'Public Key', 'scanfully' ),
				'name'        => 'public_key',
				'type'        => 'text',
				'placeholder' => 'public_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			],
			[
				'label'       => __( 'Secret Key', 'scanfully' ),
				'name'        => 'secret_key',
				'type'        => 'secret',
				'placeholder' => 'secret_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			],
		];

		add_action(
			'admin_menu',
			function () {

				// register settings.
				register_setting(
					self::$key,
					'scanfully_plugin_options',
					[ Options::class, 'validate_options' ]
				);

				$section = 'api_settings';

				// add a sections.
				add_settings_section(
					$section,
					__( 'API Settings', 'scanfully' ),
					function () {
						?>
						<p>
							<?php
							printf(
							/* translators: %s is a link to the dashboard where the API keys can be found */
								esc_html__(
									"In order for your website to securely communicate with your Scanfully dashboard, we need your site's API keys. Your site API details can be found in your %s",
									'scanfully'
								),
								"<a href='https://dashboard.scanfully.com/sites' target='_blank'>" . esc_html__(
									'Scanfully Dashboard',
									'scanfully'
								) . '</a>'
							);
							?>
						</p>
						<?php
					},
					Page::$page
				);

				foreach ( self::$options as $option ) {
					add_settings_field(
						'scanfully_' . $option['name'],
						$option['label'],
						[ Options::class, 'field_text' ],
						Page::$page,
						$section,
						[
							'name'        => $option['name'],
							'type'        => $option['type'],
							'placeholder' => $option['placeholder'],
						]
					);
				}
			}
		);
	}

	/**
	 * Get options helper
	 *
	 * @return array
	 */
	public static function get_options(): array {
		return get_option(
			self::$key,
			[
				'site_id' => '',
				'public'  => '',
				'secret'  => '',
			]
		);
	}

	/**
	 * WordPress get_option wrapper
	 *
	 * @param  string $name  The name of the option.
	 *
	 * @return string
	 */
	public static function get_option( string $name ): string {
		$options = self::get_options();

		return $options[ $name ] ?? '';
	}


	/**
	 * Validating the options.
	 *
	 * @param  array $input  The input.
	 *
	 * @return array
	 */
	public static function validate_options( array $input ): array {
		// check if the secret key still contains our redacted value.
		// If so, the user don't want to update it, so we'll use the old value.
		if ( isset( $input['secret_key'] ) && strpos( $input['secret_key'], '•' ) !== false ) {
			$input['secret_key'] = self::get_option( 'secret_key' );
		}

		return $input;
	}

	/**
	 * Render a text field
	 *
	 * @param  array $args  The field arguments.
	 *
	 * @return void
	 */
	public static function field_text( array $args ): void {
		$name  = $args['name'] ?? '';
		$value = self::get_option( $name );
		$name  = self::$key . '[' . $name . ']';

		if ( 'secret' === $args['type'] ) {
			$value = 'secret_' . str_repeat( '•', 46 ) . substr( $value, - 4 );
		}

		echo "<input id='" . esc_attr( self::$key . '_' . $name ) . "' name='" . esc_attr( $name ) . "' type='text' value='" . esc_attr( $value ) . "' placeholder='" . esc_attr( $args['placeholder'] ) . "' />";
	}

	/**
	 * Render a secret field
	 *
	 * @return void
	 */
	public static function field_secret_key(): void {
		$options = self::get_options();
		echo "<input id='" . esc_attr( self::$key ) . "_secret' name='" . esc_attr( self::$key ) . "[secret]' type='text' value='" . esc_attr( $options['secret'] ) . "' />";
	}
}