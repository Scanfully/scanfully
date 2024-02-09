<?php

namespace Scanfully\Connect;

class Button {

	/**
	 * Generate the URL for the connect button.
	 *
	 * @return string
	 */
	private function generate_url(): string {
		return add_query_arg( [
			'page'                    => 'scanfully',
			'scanfully-connect'       => 1,
			'scanfully-connect-nonce' => wp_create_nonce( 'scanfully-connect-redirect' )
		], admin_url( 'options-general.php' ) );
	}

	/**
	 * Render the button.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<a href="<?php esc_attr_e( $this->generate_url() ); ?>" class="scanfully-connect-button">Connect your Scanfully account</a>
		<?php
	}
}