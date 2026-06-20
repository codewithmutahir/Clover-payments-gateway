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
		<div class="clover-card-field clover-card-row-full">
			<label class="clover-card-field-label" for="clover-card-number">
				<?php esc_html_e( 'Card Number', 'clover-gateway' ); ?>
			</label>
			<div id="clover-card-number" class="clover-card-field-element"></div>
		</div>

		<div class="clover-card-field">
			<label class="clover-card-field-label" for="clover-card-date">
				<?php esc_html_e( 'Expiry', 'clover-gateway' ); ?>
			</label>
			<div id="clover-card-date" class="clover-card-field-element"></div>
		</div>

		<div class="clover-card-field">
			<label class="clover-card-field-label" for="clover-card-cvv">
				<?php esc_html_e( 'CVC', 'clover-gateway' ); ?>
			</label>
			<div id="clover-card-cvv" class="clover-card-field-element"></div>
		</div>

		<div class="clover-card-field clover-card-row-full">
			<label class="clover-card-field-label" for="clover-card-zip">
				<?php esc_html_e( 'ZIP / Postal Code', 'clover-gateway' ); ?>
			</label>
			<div id="clover-card-zip" class="clover-card-field-element"></div>
		</div>
	</div>

	<input type="hidden" name="clover_card_token" value="" />
</fieldset>

