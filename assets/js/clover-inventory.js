/* global jQuery, clover_sync_params */

(function ($) {
	'use strict';

	var $spinner = $('#clover-sync-spinner');

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

	function confirmModal(message, onConfirm) {
		var $backdrop = $('#clover-modal-backdrop');
		function close() {
			$backdrop.hide();
		}
		if ($backdrop.length) {
			$backdrop.find('p').text(message);
			$backdrop.find('.clover-modal-cancel').off('click.cloverModal').on('click.cloverModal', close);
			$backdrop.find('.clover-modal-confirm').off('click.cloverModal').on('click.cloverModal', function () {
				close();
				if (typeof onConfirm === 'function') {
					onConfirm();
				}
			});
			$backdrop.show();
			return;
		}
		var html = '<div id="clover-modal-backdrop">' +
			'<div id="clover-modal-box"><p>' + $('<span>').text(message).html() + '</p>' +
			'<div class="clover-modal-actions">' +
			'<button type="button" class="button clover-modal-cancel">Cancel</button>' +
			'<button type="button" class="button button-primary clover-modal-confirm">Confirm</button>' +
			'</div></div></div>';
		$('body').append(html);
		$backdrop = $('#clover-modal-backdrop');
		$backdrop.find('.clover-modal-cancel').on('click.cloverModal', close);
		$backdrop.find('.clover-modal-confirm').on('click.cloverModal', function () {
			close();
			if (typeof onConfirm === 'function') {
				onConfirm();
			}
		});
		$backdrop.on('click.cloverModal', function (e) {
			if (e.target === this) {
				close();
			}
		});
	}

	// ── EXPORT SECTION ──────────────────────────────────────────────────

	// Load tax rates from Clover into the selector.
	$('#clover-load-tax-rates').on('click', function () {
		var $btn = $(this);
		var $sel = $('#clover-export-tax-rate');
		$btn.prop('disabled', true).text('Loading\u2026');

		$.post(clover_sync_params.ajax_url, {
			action: 'clover_get_tax_rates',
			nonce:  clover_sync_params.nonce
		}).done(function (res) {
			if (res.success && res.data.rates.length) {
				$sel.empty().append('<option value="">\u2014 No tax assignment \u2014</option>');
				$.each(res.data.rates, function (i, r) {
					var pct = r.rate ? ' (' + (r.rate / 100000).toFixed(2) + '%)' : '';
					$sel.append('<option value="' + r.id + '">' + $('<span>').text(r.name + pct).html() + '</option>');
				});
				$btn.text('Reload Rates');
			} else {
				var msg = res.data && res.data.message ? res.data.message : 'No tax rates found.';
				toast(msg, 'error');
				$btn.text('Load Rates');
			}
		}).fail(function () {
			toast('Request failed.', 'error');
			$btn.text('Load Rates');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// Bulk export: process batches until done.
	var exportTotals = { created: 0, skipped: 0, errors: 0 };

	function runExportBatch(offset, taxRateId, skipLinked) {
		$.post(clover_sync_params.ajax_url, {
			action:      'clover_export_batch',
			nonce:       clover_sync_params.nonce,
			offset:      offset,
			tax_rate_id: taxRateId,
			skip_linked: skipLinked ? '1' : '0'
		}).done(function (res) {
			if (!res.success) {
				var msg = res.data && res.data.message ? res.data.message : 'Export failed.';
				showExportMsg(msg, 'error');
				finishExport();
				return;
			}

			var d = res.data;
			exportTotals.created += d.created;
			exportTotals.skipped += d.skipped;
			exportTotals.errors  += d.errors;

			// Update progress bar.
			var pct = d.total > 0 ? Math.min(100, Math.round((d.processed / d.total) * 100)) : 100;
			$('#clover-export-bar').css('width', pct + '%');
			$('#clover-export-progress-text').text(
				'Processed ' + d.processed + ' of ' + d.total +
				' — Created: ' + exportTotals.created +
				', Skipped: ' + exportTotals.skipped +
				', Errors: ' + exportTotals.errors
			);

			if (d.done) {
				showExportMsg(
					'Export complete. Created: <strong>' + exportTotals.created + '</strong>' +
					' &nbsp;|&nbsp; Skipped (already linked): <strong>' + exportTotals.skipped + '</strong>' +
					' &nbsp;|&nbsp; Errors: <strong>' + exportTotals.errors + '</strong>',
					exportTotals.errors > 0 ? 'warning' : 'success'
				);
				finishExport();
			} else {
				runExportBatch(d.next_offset, taxRateId, skipLinked);
			}
		}).fail(function () {
			showExportMsg('Request failed. Check your connection and try again.', 'error');
			finishExport();
		});
	}

	function showExportMsg(html, type) {
		$('#clover-export-message').html('<div class="notice notice-' + type + ' is-dismissible" style="margin-top:12px"><p>' + html + '</p></div>');
	}

	function finishExport() {
		$('#clover-start-export').prop('disabled', false);
		$('#clover-export-spinner').removeClass('is-active');
	}

	$('#clover-start-export').on('click', function () {
		var $btn = $(this);
		confirmModal('This will create new Clover inventory items for all unlinked WooCommerce products. Continue?', function () {
			var taxRateId  = $('#clover-export-tax-rate').val() || '';
			var skipLinked = $('#clover-export-skip-linked').is(':checked');

			exportTotals = { created: 0, skipped: 0, errors: 0 };

			$btn.prop('disabled', true);
			$('#clover-export-spinner').addClass('is-active');
			$('#clover-export-message').html('');
			$('#clover-export-bar').css('width', '0%');
			$('#clover-export-progress-text').text('Starting\u2026');
			$('#clover-export-progress').show();

			runExportBatch(0, taxRateId, skipLinked);
		});
	});

	// ── SYNC SECTION ────────────────────────────────────────────────────

	function showMsg(html, type) {
		$('#clover-sync-message').html('<div class="notice notice-' + type + ' is-dismissible"><p>' + html + '</p></div>');
	}

	function setRowLinked($row, cloverId) {
		$row.attr('data-status', 'already_linked');
		$row.find('.clover-status-cell').html('<span class="clover-badge clover-badge-linked">Linked</span>');
		$row.find('.clover-action-cell').html('&mdash;');
	}

	function setRowSkipped($row) {
		$row.attr('data-status', 'skipped');
		$row.find('.clover-status-cell').html('<span class="clover-badge" style="background:#e2e3e5;color:#383d41;">Skipped</span>');
		$row.find('.clover-action-cell').html('&mdash;');
	}

	// Run dry sync
	$('#clover-run-dry-sync').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$spinner.addClass('is-active');
		showMsg('Fetching Clover items and matching products&hellip;', 'info');

		$.post(clover_sync_params.ajax_url, {
			action: 'clover_run_dry_sync',
			nonce: clover_sync_params.nonce
		}).done(function (res) {
			if (res.success) {
				var d = res.data;
				$('#stat-total').text(d.counts.total);
				$('#stat-already_linked').text(d.counts.already_linked);
				$('#stat-auto_linked').text(d.counts.auto_linked);
				$('#stat-needs_review').text(d.counts.needs_review);
				$('#stat-possible_match').text(d.counts.possible_match);
				$('#stat-no_match').text(d.counts.no_match);

				$('#clover-sync-table tbody').html(d.rows_html);
				$('#clover-sync-overview, #clover-sync-table, #clover-apply-auto').show();
				showMsg('Dry sync complete. Review results below.', 'success');
			} else {
				showMsg(res.data && res.data.message ? res.data.message : 'Sync failed.', 'error');
			}
		}).fail(function () {
			showMsg('Request failed.', 'error');
		}).always(function () {
			$btn.prop('disabled', false);
			$spinner.removeClass('is-active');
		});
	});

	// Apply auto-links
	$('#clover-apply-auto').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$spinner.addClass('is-active');

		$.post(clover_sync_params.ajax_url, {
			action: 'clover_apply_auto_links',
			nonce: clover_sync_params.nonce
		}).done(function (res) {
			if (res.success) {
				showMsg(res.data.linked + ' products auto-linked to Clover items. Save meta updated.', 'success');
				$('#clover-sync-table tbody tr[data-status="auto_linked"]').each(function () {
					setRowLinked($(this));
				});
			} else {
				showMsg('Failed to apply auto-links.', 'error');
			}
		}).fail(function () {
			showMsg('Request failed.', 'error');
		}).always(function () {
			$btn.prop('disabled', false);
			$spinner.removeClass('is-active');
		});
	});

	// Link single product
	$(document).on('click', '.clover-action-link', function () {
		var $btn = $(this);
		var pid = $btn.data('pid');
		var cid = $btn.data('cid');
		$btn.prop('disabled', true);

		$.post(clover_sync_params.ajax_url, {
			action: 'clover_link_product',
			nonce: clover_sync_params.nonce,
			product_id: pid,
			clover_id: cid
		}).done(function (res) {
			if (res.success) {
				setRowLinked($('#row-' + pid), cid);
			} else {
				toast(res.data && res.data.message ? res.data.message : 'Failed.', 'error');
				$btn.prop('disabled', false);
			}
		}).fail(function () {
			toast('Request failed.', 'error');
			$btn.prop('disabled', false);
		});
	});

	// Skip single product
	$(document).on('click', '.clover-action-skip', function () {
		var $btn = $(this);
		var pid = $btn.data('pid');
		$btn.prop('disabled', true);

		$.post(clover_sync_params.ajax_url, {
			action: 'clover_skip_product',
			nonce: clover_sync_params.nonce,
			product_id: pid
		}).done(function (res) {
			if (res.success) {
				setRowSkipped($('#row-' + pid));
			} else {
				$btn.prop('disabled', false);
			}
		}).fail(function () {
			$btn.prop('disabled', false);
		});
	});

	// Create in Clover
	$(document).on('click', '.clover-action-create', function () {
		var $btn = $(this);
		var pid = $btn.data('pid');

		confirmModal('Create this product as a new item in Clover?', function () {
			$btn.prop('disabled', true);
			$btn.text('Creating\u2026');

			$.post(clover_sync_params.ajax_url, {
				action: 'clover_create_clover_item',
				nonce: clover_sync_params.nonce,
				product_id: pid
			}).done(function (res) {
				if (res.success) {
					setRowLinked($('#row-' + pid), res.data.clover_id);
					$('#row-' + pid).find('td:eq(2)').text('Created: ' + res.data.clover_id);
				} else {
					toast(res.data && res.data.message ? res.data.message : 'Failed to create item.', 'error');
					$btn.prop('disabled', false).text('Create New');
				}
			}).fail(function () {
				toast('Request failed.', 'error');
				$btn.prop('disabled', false).text('Create New');
			});
		});
	});

})(jQuery);
