<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clover Inventory Sync admin page and AJAX handlers.
 */
class Clover_Inventory_Admin {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_clover_run_dry_sync', array( $this, 'ajax_run_dry_sync' ) );
		add_action( 'wp_ajax_clover_apply_auto_links', array( $this, 'ajax_apply_auto_links' ) );
		add_action( 'wp_ajax_clover_link_product', array( $this, 'ajax_link_product' ) );
		add_action( 'wp_ajax_clover_create_clover_item', array( $this, 'ajax_create_clover_item' ) );
		add_action( 'wp_ajax_clover_skip_product', array( $this, 'ajax_skip_product' ) );
		add_action( 'wp_ajax_clover_get_tax_rates', array( $this, 'ajax_get_tax_rates' ) );
		add_action( 'wp_ajax_clover_export_batch', array( $this, 'ajax_export_batch' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Clover Inventory Sync', 'clover-gateway' ),
			__( 'Clover Sync', 'clover-gateway' ),
			'manage_woocommerce',
			'clover-inventory-sync',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_clover-inventory-sync' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'clover-admin',
			WC_CLOVER_GATEWAY_PLUGIN_URL . 'assets/css/clover-admin.css',
			array(),
			WC_CLOVER_GATEWAY_VERSION
		);
		wp_enqueue_style( 'clover-inventory', false );
		wp_add_inline_style( 'clover-inventory', $this->inline_css() );

		wp_enqueue_script(
			'clover-inventory',
			WC_CLOVER_GATEWAY_PLUGIN_URL . 'assets/js/clover-inventory.js',
			array( 'jquery' ),
			WC_CLOVER_GATEWAY_VERSION,
			true
		);
		wp_localize_script( 'clover-inventory', 'clover_sync_params', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'clover_inventory_sync' ),
		) );
	}

	// ------------------------------------------------------------------
	// Page render
	// ------------------------------------------------------------------

	public function render_page() {
		$cached = get_transient( 'clover_sync_dry_run' );
		$has_results = is_array( $cached ) && ! empty( $cached );

		$counts = array(
			'total'          => 0,
			'already_linked' => 0,
			'auto_linked'    => 0,
			'needs_review'   => 0,
			'possible_match' => 0,
			'no_match'       => 0,
		);
		if ( $has_results ) {
			$counts['total'] = count( $cached );
			foreach ( $cached as $r ) {
				if ( isset( $counts[ $r['status'] ] ) ) {
					$counts[ $r['status'] ]++;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Clover Inventory Sync', 'clover-gateway' ); ?></h1>

			<?php /* ── EXPORT SECTION ─────────────────────────────────────── */ ?>
			<div class="clover-sync-card">
				<h2 style="margin-top:0"><?php esc_html_e( 'Export WooCommerce Products → Clover', 'clover-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Push all WooCommerce products (simple + variations) to Clover as inventory items. Items already linked are skipped by default. Tax rates are assigned to each exported item so the rate name appears on receipts.', 'clover-gateway' ); ?></p>

				<table class="form-table" style="max-width:600px">
					<tr>
						<th scope="row"><label for="clover-export-tax-rate"><?php esc_html_e( 'Tax Rate', 'clover-gateway' ); ?></label></th>
						<td>
							<select id="clover-export-tax-rate" style="min-width:260px">
								<option value=""><?php esc_html_e( '— Load tax rates first —', 'clover-gateway' ); ?></option>
							</select>
							<button type="button" id="clover-load-tax-rates" class="button" style="margin-left:6px"><?php esc_html_e( 'Load Rates', 'clover-gateway' ); ?></button>
							<p class="description"><?php esc_html_e( 'Select the tax rate to assign to every exported item (so it shows by name on receipts). Leave empty to skip tax assignment.', 'clover-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'clover-gateway' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="clover-export-skip-linked" checked>
								<?php esc_html_e( 'Skip products already linked to a Clover item', 'clover-gateway' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" id="clover-start-export" class="button button-primary"><?php esc_html_e( 'Export All Products to Clover', 'clover-gateway' ); ?></button>
					<span id="clover-export-spinner" class="spinner" style="float:none"></span>
				</p>

				<div id="clover-export-progress" style="display:none;max-width:500px;margin-top:8px">
					<div style="background:#e2e3e5;border-radius:4px;overflow:hidden;height:20px">
						<div id="clover-export-bar" style="background:#2271b1;height:100%;width:0%;transition:width .3s"></div>
					</div>
					<p id="clover-export-progress-text" style="margin-top:4px;font-size:13px"></p>
				</div>

				<div id="clover-export-message"></div>
			</div>

			<?php /* ── SYNC SECTION ─────────────────────────────────────── */ ?>
			<h2><?php esc_html_e( 'Match / Link Products', 'clover-gateway' ); ?></h2>
			<p><?php esc_html_e( 'Match WooCommerce products to existing Clover inventory items. Useful after a partial export or when Clover already has inventory.', 'clover-gateway' ); ?></p>

			<div id="clover-sync-overview" class="clover-sync-card" <?php echo $has_results ? '' : 'style="display:none"'; ?>>
				<h2><?php esc_html_e( 'Sync Status', 'clover-gateway' ); ?></h2>
				<table class="widefat clover-sync-stats">
					<tbody>
						<tr><td><?php esc_html_e( 'Total products', 'clover-gateway' ); ?></td><td id="stat-total"><?php echo (int) $counts['total']; ?></td></tr>
						<tr><td><?php esc_html_e( 'Already linked', 'clover-gateway' ); ?></td><td id="stat-already_linked"><?php echo (int) $counts['already_linked']; ?></td></tr>
						<tr><td><?php esc_html_e( 'Auto-linked (exact / SKU)', 'clover-gateway' ); ?></td><td id="stat-auto_linked"><?php echo (int) $counts['auto_linked']; ?></td></tr>
						<tr><td><?php esc_html_e( 'Needs review (>=90%)', 'clover-gateway' ); ?></td><td id="stat-needs_review"><?php echo (int) $counts['needs_review']; ?></td></tr>
						<tr><td><?php esc_html_e( 'Possible match (70-89%)', 'clover-gateway' ); ?></td><td id="stat-possible_match"><?php echo (int) $counts['possible_match']; ?></td></tr>
						<tr><td><?php esc_html_e( 'Not matched', 'clover-gateway' ); ?></td><td id="stat-no_match"><?php echo (int) $counts['no_match']; ?></td></tr>
					</tbody>
				</table>
			</div>

			<p>
				<button type="button" id="clover-run-dry-sync" class="button button-primary"><?php esc_html_e( 'Run Dry Sync', 'clover-gateway' ); ?></button>
				<button type="button" id="clover-apply-auto" class="button" <?php echo $has_results ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Apply Auto-Links', 'clover-gateway' ); ?></button>
				<span id="clover-sync-spinner" class="spinner" style="float:none;"></span>
			</p>

			<div id="clover-sync-message"></div>

			<table class="wp-list-table widefat fixed striped" id="clover-sync-table" <?php echo $has_results ? '' : 'style="display:none"'; ?>>
				<thead>
					<tr>
						<th style="width:25%"><?php esc_html_e( 'WooCommerce Product', 'clover-gateway' ); ?></th>
						<th style="width:10%"><?php esc_html_e( 'SKU', 'clover-gateway' ); ?></th>
						<th style="width:25%"><?php esc_html_e( 'Clover Match', 'clover-gateway' ); ?></th>
						<th style="width:8%"><?php esc_html_e( 'Similarity', 'clover-gateway' ); ?></th>
						<th style="width:12%"><?php esc_html_e( 'Status', 'clover-gateway' ); ?></th>
						<th style="width:20%"><?php esc_html_e( 'Action', 'clover-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( $has_results ) {
						foreach ( $cached as $row ) {
							echo $this->render_row( $row );
						}
					}
					?>
				</tbody>
			</table>
		</div>

			<p class="clover-plugin-copyright">
				&copy; <?php echo (int) gmdate( 'Y' ); ?> <a href="https://elitesolutionusa.com" target="_blank" rel="noopener noreferrer">Elite Solution USA</a> &middot; elitesolutionusa.com
			</p>
		</div>
		<?php
	}

	protected function render_row( $row ) {
		$pid         = (int) $row['product_id'];
		$status      = esc_attr( $row['status'] );
		$status_label = $this->status_label( $row['status'] );
		$score       = $row['score'] > 0 ? (int) $row['score'] . '%' : '&mdash;';
		$clover_name = ! empty( $row['clover_name'] ) ? esc_html( $row['clover_name'] ) : esc_html__( 'No match found', 'clover-gateway' );

		$actions = '';
		if ( 'needs_review' === $row['status'] || 'possible_match' === $row['status'] ) {
			$actions  = '<button class="button button-small clover-action-link" data-pid="' . $pid . '" data-cid="' . esc_attr( $row['clover_id'] ) . '">' . esc_html__( 'Link', 'clover-gateway' ) . '</button> ';
			$actions .= '<button class="button button-small clover-action-skip" data-pid="' . $pid . '">' . esc_html__( 'Skip', 'clover-gateway' ) . '</button> ';
			$actions .= '<button class="button button-small clover-action-create" data-pid="' . $pid . '">' . esc_html__( 'Create New', 'clover-gateway' ) . '</button>';
		} elseif ( 'no_match' === $row['status'] ) {
			$actions  = '<button class="button button-small clover-action-create" data-pid="' . $pid . '">' . esc_html__( 'Create in Clover', 'clover-gateway' ) . '</button> ';
			$actions .= '<button class="button button-small clover-action-skip" data-pid="' . $pid . '">' . esc_html__( 'Skip', 'clover-gateway' ) . '</button>';
		} elseif ( 'already_linked' === $row['status'] || 'auto_linked' === $row['status'] ) {
			$actions = '&mdash;';
		}

		return '<tr id="row-' . $pid . '" data-status="' . $status . '">'
			. '<td>' . esc_html( $row['product_name'] ) . '</td>'
			. '<td>' . esc_html( $row['sku'] ) . '</td>'
			. '<td>' . $clover_name . '</td>'
			. '<td>' . $score . '</td>'
			. '<td class="clover-status-cell">' . $status_label . '</td>'
			. '<td class="clover-action-cell">' . $actions . '</td>'
			. '</tr>';
	}

	protected function status_label( $status ) {
		switch ( $status ) {
			case 'already_linked':
				return '<span class="clover-badge clover-badge-linked">' . esc_html__( 'Linked', 'clover-gateway' ) . '</span>';
			case 'auto_linked':
				return '<span class="clover-badge clover-badge-auto">' . esc_html__( 'Auto-linked', 'clover-gateway' ) . '</span>';
			case 'needs_review':
				return '<span class="clover-badge clover-badge-review">' . esc_html__( 'Needs Review', 'clover-gateway' ) . '</span>';
			case 'possible_match':
				return '<span class="clover-badge clover-badge-possible">' . esc_html__( 'Possible Match', 'clover-gateway' ) . '</span>';
			case 'no_match':
				return '<span class="clover-badge clover-badge-nomatch">' . esc_html__( 'Not Matched', 'clover-gateway' ) . '</span>';
			default:
				return esc_html( $status );
		}
	}

	// ------------------------------------------------------------------
	// AJAX: Run dry sync
	// ------------------------------------------------------------------

	public function ajax_run_dry_sync() {
		check_ajax_referer( 'clover_inventory_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$sync    = Clover_Inventory_Sync::instance();
		$results = $sync->dry_run_sync();

		$counts = array( 'total' => count( $results ), 'already_linked' => 0, 'auto_linked' => 0, 'needs_review' => 0, 'possible_match' => 0, 'no_match' => 0 );
		$rows_html = '';
		foreach ( $results as $r ) {
			if ( isset( $counts[ $r['status'] ] ) ) {
				$counts[ $r['status'] ]++;
			}
			$rows_html .= $this->render_row( $r );
		}

		wp_send_json_success( array(
			'counts'    => $counts,
			'rows_html' => $rows_html,
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Apply auto-links
	// ------------------------------------------------------------------

	public function ajax_apply_auto_links() {
		check_ajax_referer( 'clover_inventory_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$sync  = Clover_Inventory_Sync::instance();
		$count = $sync->apply_auto_links();

		wp_send_json_success( array( 'linked' => $count ) );
	}

	// ------------------------------------------------------------------
	// AJAX: Link specific product
	// ------------------------------------------------------------------

	public function ajax_link_product() {
		check_ajax_referer( 'clover_inventory_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$pid = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$cid = isset( $_POST['clover_id'] ) ? sanitize_text_field( wp_unslash( $_POST['clover_id'] ) ) : '';

		if ( ! $pid || ! $cid ) {
			wp_send_json_error( array( 'message' => 'Missing product or Clover ID.' ) );
		}

		Clover_Inventory_Sync::instance()->link_product( $pid, $cid );
		wp_send_json_success( array( 'product_id' => $pid, 'clover_id' => $cid ) );
	}

	// ------------------------------------------------------------------
	// AJAX: Create item in Clover
	// ------------------------------------------------------------------

	public function ajax_create_clover_item() {
		check_ajax_referer( 'clover_inventory_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$pid = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( ! $pid ) {
			wp_send_json_error( array( 'message' => 'Missing product ID.' ) );
		}

		$product = wc_get_product( $pid );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Product not found.' ) );
		}

		$tax_rate_id = isset( $_POST['tax_rate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_rate_id'] ) ) : '';
		$result      = Clover_Inventory_Sync::instance()->create_clover_item( $product, $tax_rate_id );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['message'] ) ? $result['message'] : 'Failed to create item.' ) );
		}

		wp_send_json_success( array( 'product_id' => $pid, 'clover_id' => $result['clover_id'] ) );
	}

	// ------------------------------------------------------------------
	// AJAX: Skip product
	// ------------------------------------------------------------------

	public function ajax_skip_product() {
		check_ajax_referer( 'clover_inventory_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$pid = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( ! $pid ) {
			wp_send_json_error( array( 'message' => 'Missing product ID.' ) );
		}

		Clover_Inventory_Sync::instance()->skip_product( $pid );
		wp_send_json_success( array( 'product_id' => $pid ) );
	}

	// ------------------------------------------------------------------
	// AJAX: Fetch Clover tax rates
	// ------------------------------------------------------------------

	public function ajax_get_tax_rates() {
		check_ajax_referer( 'clover_inventory_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$sync = Clover_Inventory_Sync::instance();
		$api  = $sync->get_api();

		if ( ! $api ) {
			wp_send_json_error( array( 'message' => 'Clover API not configured. Check gateway settings.' ) );
		}

		$rates = $api->get_tax_rates();

		$options = array();
		foreach ( $rates as $rate ) {
			$options[] = array(
				'id'   => isset( $rate['id'] ) ? $rate['id'] : '',
				'name' => isset( $rate['name'] ) ? $rate['name'] : __( '(unnamed)', 'clover-gateway' ),
				'rate' => isset( $rate['rate'] ) ? $rate['rate'] : 0,
			);
		}

		wp_send_json_success( array( 'rates' => $options ) );
	}

	// ------------------------------------------------------------------
	// AJAX: Bulk export batch
	// ------------------------------------------------------------------

	public function ajax_export_batch() {
		check_ajax_referer( 'clover_inventory_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$offset      = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$tax_rate_id = isset( $_POST['tax_rate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_rate_id'] ) ) : '';
		$skip_linked = isset( $_POST['skip_linked'] ) && '1' === $_POST['skip_linked'];

		$sync   = Clover_Inventory_Sync::instance();
		$result = $sync->bulk_export_batch( $offset, 15, $tax_rate_id, $skip_linked );

		wp_send_json_success( $result );
	}

	// ------------------------------------------------------------------
	// Inline CSS for the sync page
	// ------------------------------------------------------------------

	protected function inline_css() {
		return '
#clover-export-bar { border-radius: 3px; }
';
	}
}
