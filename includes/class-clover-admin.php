<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clover admin functionality.
 */
class Clover_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Clover_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Clover_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'woocommerce_settings_api_form_fields_clover_gateway', array( $this, 'add_admin_fields' ), 20, 1 );
		add_filter( 'admin_body_class', array( $this, 'add_settings_body_class' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		add_action( 'wp_ajax_clover_validate_credentials', array( $this, 'ajax_validate_credentials' ) );
		add_action( 'wp_ajax_clover_refresh_item_cache', array( $this, 'ajax_refresh_item_cache' ) );
		add_action( 'wp_ajax_clover_load_tax_rates', array( $this, 'ajax_load_tax_rates' ) );
		add_action( 'wp_ajax_clover_retry_print', array( $this, 'ajax_retry_print' ) );
		add_action( 'admin_footer', array( $this, 'retry_print_inline_script' ) );

		// Product field: Clover Item ID — links line items to Clover inventory so they appear in Reporting > Revenue Item Sales.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_field_clover_item_id' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_clover_item_id' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_field_clover_item_id' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_clover_item_id' ), 10, 2 );
	}

	/**
	 * Add "Validate Credentials" button to settings.
	 *
	 * @param array $fields Existing fields.
	 *
	 * @return array
	 */
	public function add_admin_fields( $fields ) {
		$fields['validate_credentials'] = array(
			'title'       => __( 'Validate Credentials', 'clover-gateway' ),
			'type'        => 'button',
			'description' => __( 'Click to validate your Clover credentials using live API calls.', 'clover-gateway' ),
			'custom_attributes' => array(
				'data-clover-validate' => '1',
			),
		);
		$fields['refresh_item_cache'] = array(
			'title'       => __( 'Refresh item cache', 'clover-gateway' ),
			'type'        => 'button',
			'description' => __( 'Clear cached Clover inventory so the next order re-fetches items. Use this after adding items in Clover or if product names are not matching (Item Sales / tax).', 'clover-gateway' ),
			'custom_attributes' => array(
				'data-clover-refresh-cache' => '1',
			),
		);
		$fields['browse_tax_rates'] = array(
			'title'             => __( 'Browse Tax Rates', 'clover-gateway' ),
			'type'              => 'button',
			'description'       => __( 'Click to load your Clover tax rates. Select one to fill the Default Tax Rate ID field above.', 'clover-gateway' ),
			'custom_attributes' => array(
				'data-clover-browse-rates' => '1',
			),
		);

		$fields['clover_copyright'] = array(
			'title'       => '',
			'type'        => 'title',
			'description' => '<p class="clover-plugin-copyright" style="margin-top:1.5em;padding-top:1em;border-top:1px solid #dcdcde;color:#646970;font-size:12px;">&copy; ' . gmdate( 'Y' ) . ' <a href="https://elitesolutionusa.com" target="_blank" rel="noopener noreferrer">Elite Solution USA</a> &middot; elitesolutionusa.com</p>',
		);

		return $fields;
	}

	/**
	 * Add order meta box with Clover details.
	 */
	public function add_order_metabox() {
		add_meta_box(
			'clover-payment-details',
			__( 'Clover Payment Details', 'clover-gateway' ),
			array( $this, 'render_order_metabox' ),
			'shop_order',
			'side',
			'default'
		);
	}

	/**
	 * Render meta box content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_order_metabox( $post ) {
		$order_id       = $post->ID;
		$clover_order_id = get_post_meta( $order_id, '_clover_order_id', true );
		$charge_id      = get_post_meta( $order_id, '_clover_charge_id', true );
		$amount_cents   = (int) get_post_meta( $order_id, '_clover_amount_cents', true );

		$amount_display = $amount_cents > 0 ? wc_price( $amount_cents / 100 ) : '&mdash;';

		echo '<p><strong>' . esc_html__( 'Clover Order ID:', 'clover-gateway' ) . '</strong> ' . ( $clover_order_id ? esc_html( $clover_order_id ) : '&mdash;' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Charge ID:', 'clover-gateway' ) . '</strong> ' . ( $charge_id ? esc_html( $charge_id ) : '&mdash;' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Amount Charged:', 'clover-gateway' ) . '</strong> ' . wp_kses_post( $amount_display ) . '</p>';

		if ( $clover_order_id ) {
			$dashboard_url = 'https://www.clover.com/m/' . rawurlencode( $clover_order_id );
			echo '<p><a href="' . esc_url( $dashboard_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View in Clover', 'clover-gateway' ) . '</a></p>';
			echo '<p><button type="button" class="button clover-retry-print" data-order-id="' . esc_attr( (string) $order_id ) . '">' . esc_html__( 'Retry print', 'clover-gateway' ) . '</button></p>';
		}
	}

	/**
	 * Add body class when viewing Clover gateway settings (so we can apply branded styling).
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function add_settings_body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'woocommerce_page_wc-settings' ) {
			return $classes;
		}
		if ( isset( $_GET['tab'] ) && $_GET['tab'] === 'checkout' && isset( $_GET['section'] ) && $_GET['section'] === 'clover_gateway' ) {
			return $classes . ' clover-gateway-settings-page';
		}
		return $classes;
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'clover-admin',
			WC_CLOVER_GATEWAY_PLUGIN_URL . 'assets/css/clover-admin.css',
			array(),
			WC_CLOVER_GATEWAY_VERSION
		);

		wp_enqueue_script(
			'clover-admin',
			WC_CLOVER_GATEWAY_PLUGIN_URL . 'assets/js/clover-admin.js',
			array( 'jquery' ),
			WC_CLOVER_GATEWAY_VERSION,
			true
		);

		wp_localize_script(
			'clover-admin',
			'clover_admin_params',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'clover_validate_credentials' ),
				'messages' => array(
					'success' => __( 'Credentials are valid.', 'clover-gateway' ),
					'error'   => __( 'Credentials are invalid. Please check and try again.', 'clover-gateway' ),
				),
			)
		);
	}

	/**
	 * AJAX handler to validate credentials.
	 */
	public function ajax_validate_credentials() {
		check_ajax_referer( 'clover_validate_credentials', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to perform this action.', 'clover-gateway' ),
				),
				403
			);
		}

		$merchant_id = isset( $_POST['merchant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_id'] ) ) : '';
		$api_token   = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';
		$public_key  = isset( $_POST['public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) : '';
		$private_key = isset( $_POST['private_key'] ) ? sanitize_text_field( wp_unslash( $_POST['private_key'] ) ) : '';
		$test_mode   = isset( $_POST['test_mode'] ) && 'yes' === $_POST['test_mode'];

		$api = new Clover_API(
			$merchant_id,
			$api_token,
			$public_key,
			$private_key,
			$test_mode
		);

		$result = $api->validate_credentials();

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Credentials are valid.', 'clover-gateway' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => __( 'Credentials are invalid. Please check and try again.', 'clover-gateway' ),
			)
		);
	}

	/**
	 * AJAX handler to clear Clover item cache (force re-fetch on next order).
	 */
	public function ajax_refresh_item_cache() {
		check_ajax_referer( 'clover_validate_credentials', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'clover-gateway' ) ), 403 );
		}
		$settings = get_option( 'woocommerce_clover_gateway_settings', array() );
		$mid      = isset( $settings['merchant_id'] ) ? $settings['merchant_id'] : '';
		if ( $mid === '' ) {
			wp_send_json_success( array( 'message' => __( 'Item cache cleared (no merchant ID saved).', 'clover-gateway' ) ) );
		}
		delete_transient( 'clover_items_' . $mid . '_test' );
		delete_transient( 'clover_items_' . $mid . '_live' );
		wp_send_json_success( array( 'message' => __( 'Item cache cleared. Next order will re-fetch Clover items for matching and tax.', 'clover-gateway' ) ) );
	}

	/**
	 * Add Clover Item ID field to product edit (General tab).
	 * When set, order line items for this product are linked to this Clover inventory item and appear in Reporting > Revenue Item Sales.
	 */
	public function product_field_clover_item_id() {
		global $post;
		$value = $post && $post->ID ? get_post_meta( $post->ID, '_clover_item_id', true ) : '';
		woocommerce_wp_text_input(
			array(
				'id'          => '_clover_item_id',
				'label'       => __( 'Clover Item ID', 'clover-gateway' ),
				'value'       => $value,
				'desc_tip'    => true,
				'description' => __( 'Optional. Leave empty to auto-match by product name or SKU with Clover. Or enter a Clover item ID to force a specific link for Reporting > Revenue Item Sales.', 'clover-gateway' ),
				'placeholder' => 'e.g. ABC123XYZ',
			)
		);
	}

	/**
	 * Save Clover Item ID for simple products.
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_clover_item_id( $product_id ) {
		$val = isset( $_POST['_clover_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['_clover_item_id'] ) ) : '';
		update_post_meta( $product_id, '_clover_item_id', $val );
	}

	/**
	 * Add Clover Item ID field to variation.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function variation_field_clover_item_id( $loop, $variation_data, $variation ) {
		$value = get_post_meta( $variation->ID, '_clover_item_id', true );
		if ( empty( $value ) && ! empty( $variation->post_parent ) ) {
			$value = get_post_meta( $variation->post_parent, '_clover_item_id', true );
		}
		echo '<div class="form-row form-row-full">';
		woocommerce_wp_text_input(
			array(
				'id'            => '_clover_item_id_var[' . $loop . ']',
				'name'          => '_clover_item_id_var[' . $loop . ']',
				'label'         => __( 'Clover Item ID', 'clover-gateway' ),
				'value'         => $value,
				'wrapper_class' => 'form-row form-row-full',
				'placeholder'   => __( 'Optional — inherit from parent if empty', 'clover-gateway' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save Clover Item ID for a variation.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop        Variation loop index.
	 */
	public function save_variation_clover_item_id( $variation_id, $loop ) {
		$key = '_clover_item_id_var[' . $loop . ']';
		if ( isset( $_POST['_clover_item_id_var'][ $loop ] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['_clover_item_id_var'][ $loop ] ) );
			update_post_meta( $variation_id, '_clover_item_id', $val );
		}
	}

	/**
	 * AJAX: Load available Clover tax rates so the admin can pick the default.
	 */
	public function ajax_load_tax_rates() {
		check_ajax_referer( 'clover_validate_credentials', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'clover-gateway' ) ), 403 );
		}

		$merchant_id = isset( $_POST['merchant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_id'] ) ) : '';
		$api_token   = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';
		$test_mode   = isset( $_POST['test_mode'] ) && 'yes' === $_POST['test_mode'];

		if ( empty( $merchant_id ) || empty( $api_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter Merchant ID and API Token first.', 'clover-gateway' ) ) );
		}

		$api   = new Clover_API( $merchant_id, $api_token, '', '', $test_mode );
		$rates = $api->get_tax_rates();

		if ( empty( $rates ) ) {
			wp_send_json_error( array( 'message' => __( 'No tax rates found. Check credentials and try again.', 'clover-gateway' ) ) );
		}

		$clean = array();
		foreach ( $rates as $r ) {
			$clean[] = array(
				'id'   => isset( $r['id'] ) ? $r['id'] : '',
				'name' => isset( $r['name'] ) ? $r['name'] : __( '(unnamed)', 'clover-gateway' ),
				'rate' => isset( $r['rate'] ) ? $r['rate'] : 0,
			);
		}

		wp_send_json_success( array( 'rates' => $clean ) );
	}

	/**
	 * AJAX: Retry Clover print for an order.
	 */
	public function ajax_retry_print() {
		check_ajax_referer( 'clover_retry_print_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$wc_order_id     = absint( $_POST['order_id'] );
		$wc_order        = wc_get_order( $wc_order_id );
		$clover_order_id = $wc_order ? get_post_meta( $wc_order_id, '_clover_order_id', true ) : null;

		if ( ! $clover_order_id ) {
			wp_send_json_error( 'No Clover order ID found for this WC order.' );
		}

		$settings = get_option( 'woocommerce_clover_gateway_settings', array() );
		$api = new Clover_API(
			isset( $settings['merchant_id'] )     ? $settings['merchant_id']     : '',
			isset( $settings['api_token'] )        ? $settings['api_token']        : '',
			isset( $settings['ecomm_public_key'] ) ? $settings['ecomm_public_key'] : '',
			isset( $settings['ecomm_private_key'] )? $settings['ecomm_private_key']: '',
			( isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'] ),
			isset( $settings['tax_rate_id'] )      ? $settings['tax_rate_id']      : ''
		);

		$result = $api->fire_order( $clover_order_id, $wc_order_id );

		if ( $result ) {
			$wc_order->add_order_note( 'Clover print retried successfully.' );
			wp_send_json_success( 'Print fired successfully.' );
		} else {
			wp_send_json_error( 'Print retry failed. Check Clover device is online.' );
		}
	}

	/**
	 * Output inline JS on WC order admin pages for the retry-print link.
	 */
	public function retry_print_inline_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( 'woocommerce_page_wc-orders' !== $screen->id && 'shop_order' !== $screen->id ) {
			return;
		}
		$nonce = wp_create_nonce( 'clover_retry_print_nonce' );
		?>
		<script>
		jQuery(document).on('click', '.clover-retry-print', function(e) {
			e.preventDefault();
			var $link = jQuery(this), orderId = $link.data('order-id');
			$link.text('Retrying...');
			jQuery.post(ajaxurl, {
				action: 'clover_retry_print',
				order_id: orderId,
				nonce: '<?php echo esc_js( $nonce ); ?>'
			}, function(res) {
				alert(res.success ? res.data : ('Error: ' + res.data));
				location.reload();
			});
		});
		</script>
		<?php
	}
}

