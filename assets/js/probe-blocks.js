/**
 * Scanfully Probe — WooCommerce Blocks payment method.
 *
 * Registers a minimal payment method block so the probe gateway is
 * selectable in block-based Cart/Checkout. The block must render an input
 * whose name+value matches the underlying WC_Payment_Gateway id; Blocks
 * handles that automatically via registerPaymentMethod, so the only
 * requirement here is that the method be registered with the same id.
 */
( function () {
	if ( typeof window.wc === 'undefined' || ! window.wc.wcBlocksRegistry ) {
		return;
	}
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { createElement } = window.wp.element;
	const settings = ( window.wc.wcSettings && window.wc.wcSettings.getSetting )
		? window.wc.wcSettings.getSetting( 'scanfully_probe_data', {} )
		: {};

	const label = settings.title || 'Scanfully Probe (test order)';
	const description = settings.description || '';

	const Content = () => createElement( 'div', { 'data-scanfully-probe-block': '1' }, description );

	registerPaymentMethod( {
		name: 'scanfully_probe',
		label: createElement( 'span', null, label ),
		ariaLabel: label,
		canMakePayment: () => true,
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		supports: {
			features: ( settings.supports && settings.supports.length ) ? settings.supports : [ 'products' ],
		},
	} );
} )();
