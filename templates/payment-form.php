<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment form template for Clover iframe.
 *
 * @var WC_Clover_Gateway $gateway
 * @var string            $public_key
 * @var bool              $test_mode
 */
?>

<fieldset id="wc-<?php echo esc_attr( $gateway->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
	<div class="clover-card-fields">
		<div id="clover-card-number" class="clover-card-field-element clover-card-row-full"></div>
		<div id="clover-card-date" class="clover-card-field-element"></div>
		<div id="clover-card-cvv" class="clover-card-field-element"></div>
		<div id="clover-card-zip" class="clover-card-field-element clover-card-row-full"></div>
	</div>

	<input type="hidden" name="clover_card_token" value="" />
</fieldset>
