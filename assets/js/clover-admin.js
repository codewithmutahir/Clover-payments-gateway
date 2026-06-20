/* global jQuery, clover_admin_params */

(function ($) {
	'use strict';

	function ensureToastContainer() {
		var $c = $('#clover-toast-container');
		if (!$c.length) {
			$c = $('<div id="clover-toast-container"></div>').appendTo('body');
		}
		return $c;
	}

	function toast(message, type) {
		type = type || 'info';
		var $c = ensureToastContainer();
		var $t = $('<div class="clover-toast clover-toast-' + type + '">' + $('<span>').text(message).html() + '</div>');
		$c.append($t);
		setTimeout(function () {
			$t.addClass('clover-toast-out');
			setTimeout(function () { $t.remove(); }, 300);
		}, 4000);
	}

	$(document).on('click', 'button[name="woocommerce_clover_gateway_validate_credentials"], [data-clover-validate]', function (e) {
		e.preventDefault();

		var $button = $(this);
		$button.prop('disabled', true);

		var merchantId = $('#woocommerce_clover_gateway_merchant_id').val();
		var apiToken = $('#woocommerce_clover_gateway_api_token').val();
		var publicKey = $('#woocommerce_clover_gateway_public_key').val();
		var privateKey = $('#woocommerce_clover_gateway_private_key').val();
		var testMode = $('#woocommerce_clover_gateway_test_mode').is(':checked') ? 'yes' : 'no';

		$.post(
			clover_admin_params.ajax_url,
			{
				action: 'clover_validate_credentials',
				nonce: clover_admin_params.nonce,
				merchant_id: merchantId,
				api_token: apiToken,
				public_key: publicKey,
				private_key: privateKey,
				test_mode: testMode
			}
		).done(function (response) {
			var message = (response && response.success) ? clover_admin_params.messages.success : clover_admin_params.messages.error;
			toast(message, response && response.success ? 'success' : 'error');
		}).fail(function () {
			toast(clover_admin_params.messages.error, 'error');
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	$(document).on('click', 'button[name="woocommerce_clover_gateway_refresh_item_cache"], [data-clover-refresh-cache]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		$btn.prop('disabled', true);
		$.post(
			clover_admin_params.ajax_url,
			{ action: 'clover_refresh_item_cache', nonce: clover_admin_params.nonce }
		).done(function (response) {
			var msg = (response && response.data && response.data.message) ? response.data.message : (response && response.success ? 'Cache cleared.' : 'Request failed.');
			toast(msg, response && response.success ? 'success' : 'error');
		}).fail(function () {
			toast('Request failed.', 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// Browse Tax Rates — load rates from Clover and let admin pick one to fill the field.
	$(document).on('click', 'button[name="woocommerce_clover_gateway_browse_tax_rates"], [data-clover-browse-rates]', function (e) {
		e.preventDefault();

		var $btn        = $(this);
		var $rateField  = $('#woocommerce_clover_gateway_default_tax_rate_id');
		var merchantId  = $('#woocommerce_clover_gateway_merchant_id').val();
		var apiToken    = $('#woocommerce_clover_gateway_api_token').val();
		var testMode    = $('#woocommerce_clover_gateway_test_mode').is(':checked') ? 'yes' : 'no';

		// Remove any existing picker.
		$('#clover-tax-rate-picker').remove();

		$btn.prop('disabled', true).text('Loading\u2026');

		$.post(clover_admin_params.ajax_url, {
			action:      'clover_load_tax_rates',
			nonce:       clover_admin_params.nonce,
			merchant_id: merchantId,
			api_token:   apiToken,
			test_mode:   testMode
		}).done(function (res) {
			if (!res.success || !res.data || !res.data.rates.length) {
				var errMsg = (res.data && res.data.message) ? res.data.message : 'No tax rates found.';
				toast(errMsg, 'error');
				return;
			}

			// Build a small inline picker below the button.
			var html = '<div id="clover-tax-rate-picker" style="margin-top:10px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;max-width:420px;border-left:4px solid #2563eb">'
				+ '<strong>Select a tax rate:</strong><br><br>';

			$.each(res.data.rates, function (i, r) {
					var pct  = r.rate ? ' — ' + (r.rate / 100000).toFixed(2) + '%' : '';
					html += '<a href="#" class="clover-pick-rate button button-small" data-id="' + $('<span>').text(r.id).html() + '" style="margin:2px 4px 2px 0;display:inline-block">'
						+ $('<span>').text(r.name + pct).html()
						+ '</a>';
				});

			html += '<br><br><a href="#" id="clover-rate-picker-close" style="font-size:12px;color:#2563eb;text-decoration:none">Close</a></div>';

			$btn.closest('tr').after('<tr><td colspan="2">' + html + '</td></tr>');

			// Click a rate → fill the field, remove picker.
			$(document).on('click.cloverRatePicker', '.clover-pick-rate', function (ev) {
				ev.preventDefault();
				$rateField.val($(this).data('id')).trigger('change');
				$('#clover-tax-rate-picker').closest('tr').remove();
				$(document).off('click.cloverRatePicker');
			});

			$(document).on('click.cloverRateClose', '#clover-rate-picker-close', function (ev) {
				ev.preventDefault();
				$('#clover-tax-rate-picker').closest('tr').remove();
				$(document).off('click.cloverRatePicker click.cloverRateClose');
			});

		}).fail(function () {
			toast('Request failed.', 'error');
		}).always(function () {
			$btn.prop('disabled', false).text('Browse Tax Rates');
		});
	});

})(jQuery);

