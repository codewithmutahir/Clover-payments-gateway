/* global wc_clover_params, jQuery, Clover */

(function ($) {
	'use strict';

	var cloverInstance = null;
	var cloverElements = null;
	var cardNumber, cardDate, cardCvv, cardZip;
	var isTokenizing = false;

	function initClover() {
		if (cloverInstance || typeof Clover === 'undefined') {
			return;
		}

		if (!wc_clover_params || !wc_clover_params.public_key) {
			return;
		}

		cloverInstance = new Clover(wc_clover_params.public_key, {
			locale: 'en-US'
		});

		cloverElements = cloverInstance.elements();
		cardNumber = cloverElements.create('CARD_NUMBER');
		cardDate = cloverElements.create('CARD_DATE');
		cardCvv = cloverElements.create('CARD_CVV');
		cardZip = cloverElements.create('CARD_POSTAL_CODE');

		cardNumber.mount('#clover-card-number');
		cardDate.mount('#clover-card-date');
		cardCvv.mount('#clover-card-cvv');
		cardZip.mount('#clover-card-zip');
	}

	function resetCheckoutForm($form) {
		$form.data('clover-tokenizing', false);
		$form.removeClass('processing');
		if ($.fn.unblock) {
			$form.unblock();
		}
	}

	function showCheckoutError($form, message) {
		$('.woocommerce-NoticeGroup-checkout').remove();
		$form.prepend(
			'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
				'<ul class="woocommerce-error" role="alert">' +
					'<li>' + message + '</li>' +
				'</ul>' +
			'</div>'
		);
		$('html, body').animate({ scrollTop: $form.offset().top - 100 }, 300);
	}

	function ensureTokenAndSubmit($form) {
		if (isTokenizing) {
			return false;
		}

		var tokenField = $form.find('input[name="clover_card_token"]');
		if (tokenField.val()) {
			// Token already present; allow submission.
			$form.off('submit.clover_gateway');
			$form.submit();
			return false;
		}

		isTokenizing = true;

		cloverInstance.createToken().then(function (result) {
			isTokenizing = false;

			if (result && result.token && result.token.token) {
				tokenField.val(result.token.token);
				$form.off('submit.clover_gateway');
				$form.submit();
				return;
			}

			// No valid token (empty fields or invalid card): submit as COD/order-only.
			tokenField.val('order_only');
			$form.off('submit.clover_gateway');
			$form.submit();
		}).catch(function (err) {
			isTokenizing = false;
			resetCheckoutForm($form);

			var message = wc_clover_params.error_generic || 'Payment could not be processed. Please try again.';
			if (err && err.message) {
				message = err.message;
			} else if (wc_clover_params.error_invalid) {
				message = wc_clover_params.error_invalid;
			}

			showCheckoutError($form, message);
		});

		return false;
	}

	$(document).on('wc-credit-card-form-init', function () {
		initClover();
	});

	$(document.body).on('updated_checkout', function () {
		initClover();
	});

	$(document).on('submit.clover_gateway', 'form.checkout', function (e) {
		var $form = $(this);

		// Only for our gateway.
		var selectedGateway = $form.find('input[name="payment_method"]:checked').val();
		if (selectedGateway !== wc_clover_params.gateway_id) {
			return true;
		}

		if (!cloverInstance) {
			initClover();
		}

		if (!cloverInstance) {
			return true;
		}

		if ($form.data('clover-tokenizing')) {
			e.preventDefault();
			return false;
		}

		$form.data('clover-tokenizing', true);
		e.preventDefault();

		// Let WooCommerce show processing UI if available.
		if ($.fn.block && typeof wc_checkout_params !== 'undefined') {
			$form.addClass('processing').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
		}

		ensureTokenAndSubmit($form);
	});

})(jQuery);

