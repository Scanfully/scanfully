<?php

namespace Scanfully\Options;

class Options {

	private static $key = "scanfully_plugin_options";

	private static $options = [];

	public static function register(): void {

		self::$options = [
			[
				"label"       => __( "Site ID", "scanfully" ),
				"name"        => "site_id",
				"type"        => "text",
				"placeholder" => "####-####-####"
			],
			[
				"label"       => __( "Public Key", "scanfully" ),
				"name"        => "public_key",
				"type"        => "text",
				"placeholder" => "public_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
			],
			[
				"label"       => __( "Secret Key", "scanfully" ),
				"name"        => "secret_key",
				"type"        => "secret",
				"placeholder" => "secret_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
			]
		];

		add_action( 'admin_menu', function () {

			// register settings
			register_setting( self::$key, 'scanfully_plugin_options',
				[ Options::class, "validate_options" ] );


			$section = 'api_settings';

			// add a sections
			add_settings_section( $section, __( "API Settings", "scanfully" ), function () {
				?>
                <p><?php printf( __( "In order for your website to securely communicate with your Scanfully dashboard, we need your site's API keys. Your site API details can be found in your %s", "scanfully" ),
						"<a href='https://dashboard.scanfully.com/sites' target='_blank'>" . __( "Scanfully Dashboard",
							"scanfully" ) . "</a>" ); ?></p>
				<?php
			}, Page::$page );


			foreach ( self::$options as $option ) {
				add_settings_field(
					'scanfully_' . $option["name"],
					$option["label"],
					[ Options::class, "field_text" ],
					Page::$page,
					$section,
					[ "name" => $option["name"], "type" => $option["type"], "placeholder" => $option["placeholder"] ]
				);
			}

		} );
	}

	public static function get_options(): array {
		return get_option( self::$key, [
			"site_id" => "",
			"public"  => "",
			"secret"  => "",
		] );
	}

	public static function get_option( string $name ): string {
		$options = self::get_options();

		return $options[ $name ] ?? "";
	}

	// todo validate input
	public static function validate_options( array $input ): array {
		return $input;
	}

	public static function field_text( array $args ): void {
		$name  = $args["name"] ?? "";
		$value = self::get_option( $name );
		echo "<input id='" . esc_attr( self::$key . "_" . $name ) . "' name='" . esc_attr( self::$key ) . "[" . esc_attr( $name ) . "]' type='text' value='" . esc_attr( $value ) . "' placeholder='" . esc_attr( $args["placeholder"] ) . "' />";
	}

	public static function field_secret_key(): void {
		$options = self::get_options();
		echo "<input id='" . esc_attr( self::$key ) . "_secret' name='" . esc_attr( self::$key ) . "[secret]' type='text' value='" . esc_attr( $options["secret"] ) . "' />";
	}


}