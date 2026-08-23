/**
 * Clover checkout tokenization — aligned with official Clover Payments for WooCommerce.
 *
 * WooCommerce calls triggerHandler( 'checkout_place_order' ) on form.checkout only;
 * handlers on document.body never run. Token must be set before form.submit().
 *
 * @package clover-gateway
 */
/* global wc_clover_params, jQuery, Clover */

jQuery(function ($) {
	'use strict';

	var cloverGateway = {
		form: null,
		clover: null,

		init: function () {
			this.initFormEvents();

			$(document.body).on('updated_checkout', function () {
				// Always reset after checkout refresh so a failed attempt cannot reuse a spent token.
				cloverGateway.resetValidationFlag();
				$('input[name="clover_card_token"]').val('');

				if (!document.querySelector('#clover-card-number iframe')) {
					cloverGateway.initCloverElement();
				}
			});

			// Mount iframes on first checkout load (updated_checkout may not have fired yet).
			if ($('form.checkout').length && !document.querySelector('#clover-card-number iframe')) {
				cloverGateway.initCloverElement();
			}

			if ($('form#order_review').length && !document.querySelector('#clover-card-number iframe')) {
				cloverGateway.initCloverElement();
			}
		},

		initFormEvents: function () {
			if ($('form#order_review').length) {
				this.form = $('form#order_review');
				$(document.body).trigger('wc-credit-card-form-init');
				this.form.on('submit', function () {
					if (!cloverGateway.ifWeChosen()) {
						return true;
					}
					if (!cloverGateway.isValidated()) {
						cloverGateway.onSubmit();
						return false;
					}
					return true;
				});
			}

			if ($('form.woocommerce-checkout').length) {
				this.form = $('form.checkout');

				// Must bind directly on form.checkout — WC uses triggerHandler (no bubbling).
				this.form.on('checkout_place_order', function () {
					if (!cloverGateway.ifWeChosen()) {
						return true;
					}
					if (!cloverGateway.isValidated()) {
						cloverGateway.onSubmit();
						return false;
					}
					return true;
				});
			}
		},

		ifWeChosen: function () {
			return $('#payment_method_' + wc_clover_params.gateway_id).is(':checked');
		},

		isValidated: function () {
			var trans = document.getElementById('clover-transresult');
			return trans && trans.value === '1';
		},

		resetValidationFlag: function () {
			var trans = document.getElementById('clover-transresult');
			if (trans) {
				trans.value = '0';
			}
		},

		getPublicKey: function () {
			return wc_clover_params.public_key;
		},

		getMerchantId: function () {
			return wc_clover_params.merchant_id || '';
		},

		getLocale: function () {
			var locale = wc_clover_params.locale || 'en-US';
			return locale.replace('_', '-');
		},

		initCloverElement: function () {
			if (typeof Clover === 'undefined' || !this.getPublicKey()) {
				return;
			}

			var options = {
				locale: this.getLocale(),
			};

			if (this.getMerchantId()) {
				options.merchantId = this.getMerchantId();
			}

			this.clover = new Clover(this.getPublicKey(), options);
			var elements = this.clover.elements();

			var styles = {
				body: {
					fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
					fontSize: '14px',
					margin: '0',
					padding: '0',
				},
				label: {
					display: 'none',
				},
				input: {
					fontSize: '14px',
					padding: '0',
					margin: '0',
					height: '24px',
					lineHeight: '24px',
					border: 'none',
					boxShadow: 'none',
					backgroundColor: 'transparent',
				},
			};

			var cardNumber = elements.create('CARD_NUMBER', styles);
			var cardDate = elements.create('CARD_DATE', styles);
			var cardCvv = elements.create('CARD_CVV', styles);
			var cardZip = elements.create('CARD_POSTAL_CODE', styles);

			cardNumber.mount('#clover-card-number');
			cardDate.mount('#clover-card-date');
			cardCvv.mount('#clover-card-cvv');
			cardZip.mount('#clover-card-zip');

			var resetOnChange = function () {
				cloverGateway.resetValidationFlag();
				$('input[name="clover_card_token"]').val('');
			};

			cardNumber.addEventListener('change', resetOnChange);
			cardDate.addEventListener('change', resetOnChange);
			cardCvv.addEventListener('change', resetOnChange);
			cardZip.addEventListener('change', resetOnChange);
		},

		block: function () {
			if (this.form && $.fn.block) {
				this.form.block({
					message: null,
					overlayCSS: {
						background: '#fff',
						opacity: 0.6,
					},
				});
			}
		},

		unblock: function () {
			if (this.form && $.fn.unblock) {
				this.form.unblock();
			}
		},

		onSubmit: function () {
			if (!this.clover) {
				this.initCloverElement();
			}

			if (!this.clover) {
				cloverGateway.showError(
					'Payment form could not load. Please refresh the page and try again.'
				);
				return;
			}

			this.block();

			this.clover.createToken()
				.then(function (result) {
					cloverGateway.chargeTokenSuccess(result);
				})
				.catch(function (err) {
					cloverGateway.chargeTokenFailure(err);
				});
		},

		getTokenFromResult: function (result) {
			if (!result || !result.token) {
				return '';
			}
			if (typeof result.token === 'string') {
				return result.token;
			}
			if (typeof result.token === 'object' && result.token.token) {
				return result.token.token;
			}
			return '';
		},

		chargeTokenSuccess: function (result) {
			if (result.errors) {
				cloverGateway.chargeTokenError(result.errors);
				return;
			}

			var token = cloverGateway.getTokenFromResult(result);
			if (!token) {
				cloverGateway.unblock();
				cloverGateway.showError(
					wc_clover_params.error_token || 'Card information incomplete. Please fill all fields.'
				);
				return;
			}

			var trans = document.getElementById('clover-transresult');
			var tokenField = document.querySelector('input[name="clover_card_token"]');

			if (tokenField) {
				tokenField.value = token;
			}
			if (trans) {
				trans.value = '1';
			}

			cloverGateway.form.submit();
		},

		chargeTokenFailure: function () {
			cloverGateway.resetValidationFlag();
			$('input[name="clover_card_token"]').val('');
			cloverGateway.unblock();
			cloverGateway.showError(
				wc_clover_params.error_generic || 'Payment could not be processed. Please try again.'
			);
		},

		chargeTokenError: function (errors) {
			cloverGateway.resetValidationFlag();
			$('input[name="clover_card_token"]').val('');
			cloverGateway.unblock();

			var message = '';
			if (errors) {
				Object.keys(errors).forEach(function (key) {
					if (errors[key] && !message) {
						message = errors[key];
					}
				});
			}

			if (!message) {
				message = wc_clover_params.error_invalid || 'Please check your card details and try again.';
			}

			cloverGateway.showError(message);
		},

		showError: function (message) {
			var $form = cloverGateway.form || $('form.checkout');
			$('.woocommerce-NoticeGroup-checkout').remove();
			var $notice = $('<div>', {
				class: 'woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout',
			});
			var $list = $('<ul>', {
				class: 'woocommerce-error',
				role: 'alert',
			});
			$list.append($('<li>').text(message));
			$notice.append($list);
			$form.prepend($notice);
			$('html, body').animate({ scrollTop: $form.offset().top - 100 }, 300);
		},
	};

	cloverGateway.init();
});
