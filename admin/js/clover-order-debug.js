(function ($) {
	'use strict';

	function statusClass(status) {
		if (status === 'ok') {
			return 'clover-debug-ok';
		}
		if (status === 'warn') {
			return 'clover-debug-warn';
		}
		return 'clover-debug-error';
	}

	function boolLabel(value) {
		if (value === true) {
			return 'true';
		}
		if (value === false) {
			return 'false';
		}
		return '—';
	}

	function renderTable(rows) {
		if (!rows || !rows.length) {
			return '<p>' + clover_order_debug_params.i18n.no_lines + '</p>';
		}

		var html = '<table class="widefat striped clover-debug-table"><thead><tr>';
		html += '<th>#</th><th>' + clover_order_debug_params.i18n.item + '</th>';
		html += '<th>' + clover_order_debug_params.i18n.sent_qty + '</th>';
		html += '<th>' + clover_order_debug_params.i18n.clover_qty + '</th>';
		html += '<th>' + clover_order_debug_params.i18n.pos_display + '</th>';
		html += '<th>' + clover_order_debug_params.i18n.printed + '</th>';
		html += '<th>' + clover_order_debug_params.i18n.type + '</th>';
		html += '<th>' + clover_order_debug_params.i18n.status + '</th>';
		html += '</tr></thead><tbody>';

		rows.forEach(function (row) {
			var sent = row.sent || {};
			var clover = row.clover || {};
			html += '<tr class="' + statusClass(row.status) + '">';
			html += '<td>' + row.index + '</td>';
			html += '<td>' + (row.name || '—') + '</td>';
			html += '<td>' + (sent.quantity_sold != null ? sent.quantity_sold : '—') + ' / uQ ' + (sent.unit_qty != null ? sent.unit_qty : '—') + '</td>';
			html += '<td>' + (clover.quantity_sold != null ? clover.quantity_sold : '—') + ' / uQ ' + (clover.unit_qty != null ? clover.unit_qty : '—') + '</td>';
			html += '<td><strong>' + (clover.display_qty || '—') + '</strong></td>';
			html += '<td>' + boolLabel(clover.printed) + '</td>';
			html += '<td>' + (clover.type || sent.type || '—') + '</td>';
			html += '<td>' + (row.notes || clover_order_debug_params.i18n.ok) + '</td>';
			html += '</tr>';
		});

		html += '</tbody></table>';
		return html;
	}

	function renderSummary(data) {
		var ok = 0;
		var warn = 0;
		var err = 0;

		(data.rows || []).forEach(function (row) {
			if (row.status === 'ok') {
				ok++;
			} else if (row.status === 'warn') {
				warn++;
			} else {
				err++;
			}
		});

		var cls = err > 0 ? 'clover-debug-error' : (warn > 0 ? 'clover-debug-warn' : 'clover-debug-ok');
		var html = '<p class="clover-debug-summary ' + cls + '">';
		html += clover_order_debug_params.i18n.summary_prefix + ' ';
		html += ok + ' OK, ' + warn + ' ' + clover_order_debug_params.i18n.warnings + ', ' + err + ' ' + clover_order_debug_params.i18n.errors;
		if (data.order_state) {
			html += ' · Clover state: <strong>' + data.order_state + '</strong>';
		}
		if (data.sync_path) {
			html += ' · Sync path: <strong>' + data.sync_path + '</strong>';
		}
		html += '</p>';
		return html;
	}

	$(document).on('click', '.clover-fetch-debug', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var orderId = $btn.data('order-id');
		var $panel = $('#clover-order-debug-panel');

		$btn.prop('disabled', true).text(clover_order_debug_params.i18n.loading);

		$.post(clover_order_debug_params.ajax_url, {
			action: 'clover_fetch_order_debug',
			order_id: orderId,
			nonce: clover_order_debug_params.nonce
		}).done(function (res) {
			if (!res.success) {
				$panel.html('<p class="clover-debug-error">' + (res.data && res.data.message ? res.data.message : clover_order_debug_params.i18n.fetch_failed) + '</p>');
				return;
			}
			var html = renderSummary(res.data);
			html += renderTable(res.data.rows);
			if (res.data.fetched_at) {
				html += '<p class="description">' + clover_order_debug_params.i18n.refreshed + ' ' + res.data.fetched_at + '</p>';
			}
			$panel.html(html);
		}).fail(function () {
			$panel.html('<p class="clover-debug-error">' + clover_order_debug_params.i18n.fetch_failed + '</p>');
		}).always(function () {
			$btn.prop('disabled', false).text(clover_order_debug_params.i18n.refresh);
		});
	});

	$(function () {
		var $btn = $('.clover-fetch-debug').first();
		if ($btn.length) {
			$btn.trigger('click');
		}
	});
})(jQuery);
