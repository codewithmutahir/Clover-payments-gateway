<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Clover API client.
 */
class Clover_API
{

	protected $merchant_id;
	protected $api_token;
	protected $public_key;
	protected $private_key;
	protected $test_mode;

	/**
	 * Clover tax rate ID to explicitly assign to every product line item.
	 * Required so tax is recorded in Clover's Tax Report, not just displayed on receipts.
	 * Matches the merchant's default/applicable tax rate from Clover Settings → Tax Rates.
	 *
	 * @var string
	 */
	protected $default_tax_rate_id;

	/**
	 * Per-request cache of merchant tax rates (populated on first call to get_tax_rates()).
	 *
	 * @var array|null
	 */
	protected $cached_tax_rates = null;

	public function __construct($merchant_id, $api_token, $public_key, $private_key, $test_mode = false, $default_tax_rate_id = '')
	{
		$this->merchant_id         = $merchant_id;
		$this->api_token           = $api_token;
		$this->public_key          = $public_key;
		$this->private_key         = $private_key;
		$this->test_mode           = (bool) $test_mode;
		$this->default_tax_rate_id = sanitize_text_field((string) $default_tax_rate_id);
	}

	protected function get_v3_base_url()
	{
		return $this->test_mode
			? 'https://apisandbox.dev.clover.com/v3'
			: 'https://api.clover.com/v3';
	}

	protected function get_ecomm_base_url()
	{
		return $this->test_mode
			? 'https://scl-sandbox.dev.clover.com/v1'
			: 'https://scl.clover.com/v1';
	}

	/**
	 * Human-readable message from a failed v3 API response.
	 *
	 * @param array  $result   Response from request_v3().
	 * @param string $fallback Default message.
	 * @return string
	 */
	protected function api_error_message($result, $fallback = '')
	{
		if (! is_array($result)) {
			return $fallback ?: __('Clover API request failed.', 'clover-gateway');
		}

		if (! empty($result['data']) && is_array($result['data'])) {
			if (! empty($result['data']['message'])) {
				return (string) $result['data']['message'];
			}
			if (! empty($result['data']['error']['message'])) {
				return (string) $result['data']['error']['message'];
			}
		}

		if (! empty($result['message']) && __('Payment could not be processed. Please try again.', 'clover-gateway') !== $result['message']) {
			return (string) $result['message'];
		}

		$code = isset($result['http_code']) ? (int) $result['http_code'] : 0;
		if ($code > 0) {
			return sprintf(
				/* translators: %d: HTTP status code */
				__('Clover API HTTP %d', 'clover-gateway'),
				$code
			);
		}

		return $fallback ?: __('Clover API request failed.', 'clover-gateway');
	}

	protected function request_v3($method, $path, $body = array(), $query = array())
	{
		$url = $this->get_v3_base_url() . $path;
		if (! empty($query)) {
			$url = add_query_arg($query, $url);
		}
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if (! empty($body) && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			error_log('Clover v3 API error: ' . $response->get_error_message());
			return array(
				'success'   => false,
				'ambiguous' => true,
				'http_code' => 0,
				'message'   => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code < 200 || $code >= 300) {
			error_log('Clover v3 API HTTP ' . $code . ': ' . wp_json_encode($body));
			$ambiguous = ($code >= 500 || in_array((int) $code, array(408, 429), true));
			$payload   = array(
				'success'   => false,
				'ambiguous' => $ambiguous,
				'http_code' => (int) $code,
				'data'      => $body,
			);
			$payload['message'] = $this->api_error_message($payload);
			return $payload;
		}

		return array('success' => true, 'data' => $body);
	}

	protected function request_ecomm($method, $path, $body = array(), $extra_headers = array())
	{
		$url  = $this->get_ecomm_base_url() . $path;
		$args = array(
			'method'  => $method,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->private_key,
					'Content-Type'  => 'application/json',
				),
				$extra_headers
			),
			'timeout' => 30,
		);

		if (! empty($body)) {
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			error_log('Clover eCommerce API error: ' . $response->get_error_message());
			return array('success' => false, 'message' => __('Payment could not be processed. Please try again.', 'clover-gateway'));
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code < 200 || $code >= 300) {
			error_log('Clover eCommerce API HTTP ' . $code . ': ' . wp_json_encode($body));
			return array('success' => false, 'message' => __('Payment could not be processed. Please try again.', 'clover-gateway'), 'data' => $body);
		}

		return array('success' => true, 'data' => $body);
	}

	/**
	 * Generate a unique idempotency key for a charge attempt.
	 * Combining order ID + a per-page-load random suffix ensures that:
	 * - A second submit of the same form is blocked by Clover (same key = same charge).
	 * - A genuine retry after a network timeout produces a new key so the charge can proceed.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string UUID-formatted idempotency key.
	 */
	protected function generate_idempotency_key($order_id)
	{
		// Use a transient that lives for 5 minutes: long enough to catch duplicate submits,
		// short enough to allow a genuine retry.
		$transient_key = 'clover_idem_' . (int) $order_id;
		$existing      = get_transient($transient_key);
		if ($existing) {
			return $existing;
		}

		try {
			$bytes = random_bytes(16);
		} catch ( Exception $e ) {
			$bytes = openssl_random_pseudo_bytes(16);
		}
		$bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
		$bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
		$key = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

		set_transient($transient_key, $key, 5 * MINUTE_IN_SECONDS);
		return $key;
	}

	/**
	 * Add a single line item to a Clover order.
	 *
	 * @param string      $clover_order_id  Clover order ID.
	 * @param string      $name             Line item name (used for custom items without inventory link).
	 * @param int         $price_cents      Price in cents.
	 * @param string|null $clover_item_id   Optional Clover inventory item ID. Links the line item to a
	 *                                      revenue item (Reporting > Revenue Item Sales).
	 * @param bool        $apply_tax        When true, embeds the configured default tax rate into the
	 *                                      line item creation payload so Clover records it in the Tax Report.
	 *                                      Pass false for shipping/fees to avoid taxing non-product lines.
	 *
	 * @return array{success:bool,id?:string,message?:string}
	 */
	protected function add_line_item($clover_order_id, $name, $price_cents, $clover_item_id = null, $apply_tax = false, $unit_qty = 1000)
	{
		$unit_qty = max(1000, (int) $unit_qty);
		$attempts = array();

		if (! empty($clover_item_id) && is_string($clover_item_id)) {
			$attempts[] = array(
				'item_id'   => trim($clover_item_id),
				'apply_tax' => (bool) $apply_tax,
			);
		}

		$attempts[] = array(
			'item_id'   => null,
			'apply_tax' => (bool) $apply_tax,
		);

		if ($apply_tax) {
			$attempts[] = array(
				'item_id'   => null,
				'apply_tax' => false,
			);
		}

		$last_result = null;

		foreach ($attempts as $attempt) {
			$body = $this->build_line_item_request_body(
				$name,
				(int) $price_cents,
				$unit_qty,
				$attempt['item_id'],
				$attempt['apply_tax']
			);

			$result = $this->request_v3(
				'POST',
				'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id) . '/line_items',
				$body
			);

			$last_result = $result;

			if (! empty($result['success']) && ! empty($result['data']['id'])) {
				return array(
					'success' => true,
					'id'      => (string) $result['data']['id'],
				);
			}
		}

		error_log(
			'Clover: Failed to add line item "' . $name . '" to order ' . $clover_order_id
				. ' — ' . wp_json_encode(isset($last_result['data']) ? $last_result['data'] : $last_result)
		);

		return array(
			'success' => false,
			'message' => $this->api_error_message($last_result),
		);
	}

	/**
	 * Build the POST body for a single Clover order line item.
	 *
	 * @param string      $name         Line item name.
	 * @param int         $price_cents  Unit price in cents.
	 * @param int         $unit_qty     Quantity in Clover milli-units.
	 * @param string|null $clover_item_id Optional inventory item ID.
	 * @param bool        $apply_tax    Whether to embed default tax metadata.
	 * @return array<string,mixed>
	 */
	protected function build_line_item_request_body($name, $price_cents, $unit_qty, $clover_item_id = null, $apply_tax = false)
	{
		if (! empty($clover_item_id) && is_string($clover_item_id)) {
			$body = array(
				'item'    => array('id' => trim($clover_item_id)),
				'price'   => (int) $price_cents,
				'unitQty' => (int) $unit_qty,
				'printed' => false,
			);
		} else {
			$body = array(
				'name'    => $name,
				'price'   => (int) $price_cents,
				'unitQty' => (int) $unit_qty,
				'printed' => false,
			);
		}

		if ($apply_tax && ! empty($this->default_tax_rate_id)) {
			$full_rate = $this->get_tax_rate_by_id($this->default_tax_rate_id);

			if ($full_rate) {
				$rate_val = isset($full_rate['rate']) ? (int) $full_rate['rate'] : 0;

				if ($rate_val > 0) {
					$line_total_cents  = (int) round($price_cents * $unit_qty / 1000);
					$body['taxAmount'] = (int) round($line_total_cents * $rate_val / 1000000000);
				}

				$body['taxRates'] = array(
					array(
						'id'        => $this->default_tax_rate_id,
						'name'      => isset($full_rate['name']) ? $full_rate['name'] : '',
						'rate'      => isset($full_rate['rate']) ? (int) $full_rate['rate'] : 0,
						'isDefault' => true,
					),
				);
			}
		}

		return $body;
	}

	/**
	 * Explicitly record the configured tax rate on an order line item so Clover's
	 * Tax Report shows the collected amount.
	 *
	 * Tries two API paths:
	 *   1. POST /orders/{orderId}/line_items/{lineItemId}/taxRates  — per-line-item sub-resource
	 *   2. POST /line_item_tax_rates  — merchant-level join table
	 *
	 * At least one of these registers the tax in Clover's accounting layer.
	 * (The taxRates field in the line item creation payload covers display;
	 * these calls make it count in the Tax Report.)
	 *
	 * Only called for product line items — not shipping or fees.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @param string $line_item_id    Clover line item ID returned from add_line_item().
	 */
	protected function apply_line_item_tax_rate($clover_order_id, $line_item_id)
	{
		if (empty($this->default_tax_rate_id) || empty($line_item_id)) {
			return;
		}

		$full_rate    = $this->get_tax_rate_by_id($this->default_tax_rate_id);
		$rate_element = array('id' => $this->default_tax_rate_id);

		if ($full_rate) {
			$rate_element['name'] = isset($full_rate['name']) ? $full_rate['name'] : '';
			$rate_element['rate'] = isset($full_rate['rate']) ? (int) $full_rate['rate'] : 0;
		}

		// Path 1: sub-resource on the order line item.
		$r1 = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id)
				. '/orders/' . rawurlencode($clover_order_id)
				. '/line_items/' . rawurlencode($line_item_id)
				. '/taxRates',
			$rate_element
		);

		// Path 2: merchant-level line_item_tax_rates join table.
		$r2 = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/line_item_tax_rates',
			array(
				'lineItem' => array('id' => $line_item_id),
				'taxRate'  => array('id' => $this->default_tax_rate_id),
			)
		);

		if (empty($r1['success']) && empty($r2['success'])) {
			error_log(
				'Clover: Both tax-rate paths failed for line item ' . $line_item_id
					. ' on order ' . $clover_order_id
					. ' | path1=' . wp_json_encode(isset($r1['data']) ? $r1['data'] : $r1)
					. ' | path2=' . wp_json_encode(isset($r2['data']) ? $r2['data'] : $r2)
			);
		}
	}

	/**
	 * Ensure WooCommerce order totals are calculated before reading amounts.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	protected function ensure_order_totals($order)
	{
		if (method_exists($order, 'calculate_totals')) {
			$order->calculate_totals();
		}
	}

	/**
	 * Clover line-item quantity in milli-units (qty 1 => 1000).
	 *
	 * @param int $quantity Human-readable quantity.
	 * @return int
	 */
	protected function clover_unit_qty($quantity)
	{
		return max(1000, (int) $quantity * 1000);
	}

	/**
	 * Resolve a Clover inventory item ID for a WooCommerce order line item.
	 *
	 * Links by default when _clover_item_id meta is set (same as official Clover plugin).
	 *
	 * @param WC_Order              $order WooCommerce order.
	 * @param WC_Order_Item_Product $item  Order line item.
	 * @return string|null Clover item ID or null.
	 */
	protected function resolve_clover_item_id_for_line_item( $order, $item ) {
		$product_id   = $item->get_product_id();
		$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
		$lookup_id    = $variation_id ? $variation_id : $product_id;

		if ( ! $lookup_id ) {
			return null;
		}

		$clover_item_id = get_post_meta( $lookup_id, '_clover_item_id', true );
		if ( empty( $clover_item_id ) && $variation_id && $product_id ) {
			$clover_item_id = get_post_meta( $product_id, '_clover_item_id', true );
		}

		if ( empty( $clover_item_id ) || ! is_string( $clover_item_id ) ) {
			return null;
		}

		$link = (bool) apply_filters(
			'clover_gateway_link_inventory_items',
			true,
			$order,
			$item
		);

		return $link ? trim( $clover_item_id ) : null;
	}

	/**
	 * Build atomic-order line items (simpler payload — matches Clover POS expectations).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array{elements:array<int,array<string,mixed>>}
	 */
	protected function build_atomic_line_items($order)
	{
		$elements = array();
		$currency = $order->get_currency();

		foreach ($order->get_items() as $item) {
			$quantity = (int) $item->get_quantity();
			$total    = (float) $item->get_total();

			if ($quantity <= 0) {
				continue;
			}

			$unit_price_cents = (int) round(($total / $quantity) * 100);

			$li = array(
				'name'         => $item->get_name(),
				'price'        => $unit_price_cents,
				'unitQty'      => $this->clover_unit_qty( $quantity ),
				'quantitySold' => (float) $quantity,
				'printed'      => false,
				'exchanged'    => false,
			);

			$clover_item_id = $this->resolve_clover_item_id_for_line_item( $order, $item );
			if ( $clover_item_id ) {
				$li['item'] = array( 'id' => $clover_item_id );
			}

			if ( ! empty( $this->default_tax_rate_id ) ) {
				$full_rate = $this->get_tax_rate_by_id( $this->default_tax_rate_id );

				if ( $full_rate && ! empty( $full_rate['rate'] ) && (int) $full_rate['rate'] > 0 ) {
					$line_total_cents = $unit_price_cents * $quantity;
					$li['taxAmount']  = (int) round( $line_total_cents * (int) $full_rate['rate'] / 1000000000 );

					$li['taxRates'] = array(
						array(
							'id'        => $this->default_tax_rate_id,
							'name'      => isset( $full_rate['name'] ) ? (string) $full_rate['name'] : '',
							'rate'      => (int) $full_rate['rate'],
							'isDefault' => true,
						),
					);
				}
			}

			$elements[] = $li;
		}

		$shipping_total = (float) $order->get_shipping_total();
		if ($shipping_total > 0) {
			$shipping_label = __('Shipping', 'clover-gateway');
			$shipping_method = $order->get_shipping_method();
			if ($shipping_method) {
				$shipping_label = sprintf(__('Shipping: %s', 'clover-gateway'), $shipping_method);
			}

			$elements[] = array(
				'name'    => $shipping_label,
				'price'   => (int) round($shipping_total * 100),
				'unitQty' => 1000,
				'printed' => false,
			);
		}

		foreach ($order->get_fees() as $fee) {
			$fee_total = (float) $fee->get_total();
			$fee_name  = $fee->get_name();

			if (abs($fee_total) <= 0 || stripos($fee_name, 'tax') !== false) {
				continue;
			}

			$elements[] = array(
				'name'    => $fee_name,
				'price'   => (int) round($fee_total * 100),
				'unitQty' => 1000,
				'printed' => false,
			);
		}

		// Only add WC tax as a line item when no Clover native tax rate is configured.
		// When default_tax_rate_id is set, Clover calculates tax from taxRates on each
		// product line item — adding a separate Tax line item would double-count it.
		if ( empty( $this->default_tax_rate_id ) ) {
			$tax_total = (float) $order->get_total_tax();
			if ( $tax_total > 0 ) {
				$elements[] = array(
					'name'    => __( 'Tax', 'clover-gateway' ),
					'price'   => (int) round( $tax_total * 100 ),
					'unitQty' => 1000,
					'printed' => false,
				);
			}
		}

		$discount_total = (float) $order->get_discount_total();
		if ($discount_total > 0) {
			$elements[] = array(
				'name'    => __('Discount', 'clover-gateway'),
				'price'   => -1 * abs((int) round($discount_total * 100)),
				'unitQty' => 1000,
				'printed' => false,
			);
		}

		return array('elements' => $elements);
	}

	/**
	 * Build Clover line item payloads from a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array {
	 *     @type array $elements Line item payloads for atomic_order or sequential sync.
	 * }
	 */
	protected function build_line_items_from_order($order)
	{
		$elements = array();

		foreach ($order->get_items() as $item) {
			$quantity = (int) $item->get_quantity();
			$total    = (float) $item->get_total();

			if ($quantity <= 0) {
				continue;
			}

			$unit_price_cents = (int) round(($total / $quantity) * 100);

			$clover_item_id = $this->resolve_clover_item_id_for_line_item( $order, $item );

			if ( ! empty( $clover_item_id ) && ! empty( $this->default_tax_rate_id ) ) {
				$cache_key = 'clover_itax_' . md5($clover_item_id . '_' . $this->default_tax_rate_id);
				if (! get_transient($cache_key)) {
					$assigned = $this->assign_item_tax_rate($clover_item_id, $this->default_tax_rate_id);
					set_transient(
						$cache_key,
						$assigned ? 'ok' : 'attempted',
						$assigned ? 7 * DAY_IN_SECONDS : HOUR_IN_SECONDS
					);
				}
			}

			$li = array(
				'name'         => $item->get_name(),
				'price'        => $unit_price_cents,
				'unitQty'      => $this->clover_unit_qty( $quantity ),
				'quantitySold' => (float) $quantity,
				'printed'      => false,
				'exchanged'    => false,
			);

			if ( $clover_item_id ) {
				$li['item'] = array( 'id' => $clover_item_id );
			}

			if (! empty($this->default_tax_rate_id)) {
				$full_rate = $this->get_tax_rate_by_id($this->default_tax_rate_id);

				if ($full_rate) {
					$rate_val = isset($full_rate['rate']) ? (int) $full_rate['rate'] : 0;

					if ($rate_val > 0) {
						$li['taxAmount'] = (int) round($unit_price_cents * $quantity * $rate_val / 1000000000);
					}

					$li['taxRates'] = array(
						array(
							'id'        => $this->default_tax_rate_id,
							'name'      => isset( $full_rate['name'] ) ? (string) $full_rate['name'] : '',
							'rate'      => $rate_val,
							'isDefault' => true,
						),
					);
				}
			}

			$li['apply_tax']      = true;
			$li['clover_item_id'] = $clover_item_id;
			$elements[]           = $li;
		}

		$shipping_total = (float) $order->get_shipping_total();
		if ($shipping_total > 0) {
			$shipping_label  = __('Shipping', 'clover-gateway');
			$shipping_method = $order->get_shipping_method();
			if ($shipping_method) {
				$shipping_label = sprintf(__('Shipping: %s', 'clover-gateway'), $shipping_method);
			}

			$elements[] = array(
				'name'      => $shipping_label,
				'price'     => (int) round($shipping_total * 100),
				'unitQty'   => 1000,
				'printed'   => false,
				'apply_tax' => false,
			);
		}

		foreach ($order->get_fees() as $fee) {
			$fee_total = (float) $fee->get_total();
			$fee_name  = $fee->get_name();

			if (abs($fee_total) <= 0 || stripos($fee_name, 'tax') !== false) {
				continue;
			}

			$elements[] = array(
				'name'      => $fee_name,
				'price'     => (int) round($fee_total * 100),
				'unitQty'   => 1000,
				'printed'   => false,
				'apply_tax' => false,
			);
		}

		return array(
			'elements' => $elements,
		);
	}

	/**
	 * Strip internal-only keys before sending line items to Clover API.
	 *
	 * @param array $line_items Line item payloads.
	 * @return array
	 */
	protected function sanitize_line_items_for_api($line_items)
	{
		$clean = array();

		foreach ($line_items as $li) {
			unset($li['apply_tax'], $li['clover_item_id']);
			$clean[] = $li;
		}

		return $clean;
	}

	/**
	 * Calculate order tax in cents for Clover.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int
	 */
	protected function calculate_order_tax_cents( $order ) {
		$wc_tax_cents = (int) round( (float) $order->get_total_tax() * 100 );

		if ( empty( $this->default_tax_rate_id ) ) {
			return $wc_tax_cents;
		}

		$full_rate = $this->get_tax_rate_by_id( $this->default_tax_rate_id );

		if ( $full_rate && isset( $full_rate['rate'] ) && (int) $full_rate['rate'] > 0 ) {
			$product_subtotal_cents = 0;
			foreach ( $order->get_items() as $_item ) {
				$product_subtotal_cents += (int) round( (float) $_item->get_total() * 100 );
			}
			// Clover rate: 87500000 = 8.75% -> divide by 10,000,000
			$clover_tax_cents = (int) round( $product_subtotal_cents * (int) $full_rate['rate'] / 1000000000 );
			if ( $clover_tax_cents > 0 ) {
				return $clover_tax_cents;
			}
		}

		return $wc_tax_cents;
	}

	/**
	 * Build the order note from WooCommerce order data.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	protected function build_order_note($order)
	{
		$note_lines   = array();
		$note_lines[] = 'Order #' . $order->get_order_number();

		$billing_name = trim($order->get_formatted_billing_full_name());
		if ($billing_name) {
			$note_lines[] = 'Customer: ' . $billing_name;
		}
		if ($order->get_billing_phone()) {
			$note_lines[] = 'Phone: ' . $order->get_billing_phone();
		}

		$order_type    = $order->get_meta('_order_type', true);
		$delivery_date = $order->get_meta('_delivery_date', true);
		$delivery_time = $order->get_meta('_delivery_time', true);
		$pickup_time   = $order->get_meta('_pickup_time', true);

		if ($order_type && ($delivery_date || $delivery_time || $pickup_time)) {
			$label = ($order_type === 'pickup') ? __('Pickup', 'clover-gateway') : __('Delivery', 'clover-gateway');
			$parts = array();
			if ($delivery_date) {
				$parts[] = $delivery_date;
			}
			if ($order_type === 'pickup' && $pickup_time) {
				$parts[] = $pickup_time;
			} elseif ($order_type === 'delivery' && $delivery_time) {
				$parts[] = $delivery_time;
			}
			if (! empty($parts)) {
				$note_lines[] = $label . ': ' . implode(' ', $parts);
			}
		}

		return implode("\n", $note_lines);
	}

	/**
	 * Whether a failed v3 API response may have succeeded server-side (timeout, 5xx, etc.).
	 *
	 * @param array $result Response from request_v3().
	 * @return bool
	 */
	protected function is_ambiguous_api_failure($result)
	{
		if (! empty($result['success'])) {
			return false;
		}

		if (! empty($result['ambiguous'])) {
			return true;
		}

		$code = isset($result['http_code']) ? (int) $result['http_code'] : 0;

		return $code <= 0 || $code >= 500 || in_array($code, array(408, 429), true);
	}

	/**
	 * Build the Clover order title used for idempotent lookups.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	protected function get_order_title($order)
	{
		return 'Order #' . $order->get_order_number();
	}

	/**
	 * Count line items on a Clover order payload (requires lineItems expand).
	 *
	 * @param array $clover_order Clover order object.
	 * @return int
	 */
	protected function count_clover_line_items($clover_order)
	{
		if (empty($clover_order['lineItems']['elements']) || ! is_array($clover_order['lineItems']['elements'])) {
			return 0;
		}

		return count($clover_order['lineItems']['elements']);
	}

	/**
	 * Fetch Clover orders matching a title (last 90 days per Clover filter limits).
	 *
	 * @param string $title Order title.
	 * @return array
	 */
	protected function find_clover_orders_by_title($title)
	{
		$result = $this->request_v3(
			'GET',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders',
			array(),
			array(
				'filter' => 'title=' . $title,
				'expand' => 'lineItems',
				'limit'  => 5,
			)
		);

		if (empty($result['success']) || empty($result['data']['elements'])) {
			return array();
		}

		return $result['data']['elements'];
	}

	/**
	 * Idempotent lookup for an existing Clover order created for this WooCommerce order.
	 *
	 * @param WC_Order $order                 WooCommerce order.
	 * @param int|null $expected_line_count   Minimum line items required to treat as complete.
	 * @return array|null Clover order object or null.
	 */
	protected function lookup_existing_clover_order($order, $expected_line_count = null)
	{
		$title = $this->get_order_title($order);

		foreach ($this->find_clover_orders_by_title($title) as $clover_order) {
			$order_test_mode = ! empty($clover_order['testMode']);
			if ($order_test_mode !== (bool) $this->test_mode) {
				continue;
			}

			if (null !== $expected_line_count) {
				if ($this->count_clover_line_items($clover_order) >= (int) $expected_line_count) {
					return $clover_order;
				}
				continue;
			}

			if (! empty($clover_order['id'])) {
				return $clover_order;
			}
		}

		return null;
	}

	/**
	 * Fetch the current line-item count for a Clover order.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @return int
	 */
	protected function get_order_line_item_count($clover_order_id)
	{
		$result = $this->request_v3(
			'GET',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id),
			array(),
			array('expand' => 'lineItems')
		);

		if (empty($result['success']) || empty($result['data'])) {
			return 0;
		}

		return $this->count_clover_line_items($result['data']);
	}

	/**
	 * Delete an orphaned Clover order shell.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @return bool
	 */
	protected function delete_clover_order($clover_order_id)
	{
		if (empty($clover_order_id)) {
			return false;
		}

		$result = $this->request_v3(
			'DELETE',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id)
		);

		if (empty($result['success'])) {
			error_log('Clover: Failed to delete orphaned order ' . $clover_order_id . ' — ' . wp_json_encode(isset($result['data']) ? $result['data'] : $result));
			return false;
		}

		error_log('Clover: Deleted orphaned order ' . $clover_order_id);
		return true;
	}

	/**
	 * Parse an order-creation API response, recovering from ambiguous failures when possible.
	 *
	 * @param WC_Order $order               WooCommerce order.
	 * @param array    $result              Response from request_v3().
	 * @param int      $tax_cents           Tax in cents.
	 * @param int      $total_cents         Total in cents.
	 * @param int      $expected_line_count Expected line item count.
	 * @return array Result array, or array with 'failure' => 'ambiguous'|'deterministic'.
	 */
	protected function parse_order_create_api_result($order, $result, $tax_cents, $total_cents, $expected_line_count)
	{
		if (! empty($result['success']) && ! empty($result['data']['id'])) {
			return array(
				'clover_order_id' => $result['data']['id'],
				'tax_cents'       => $tax_cents,
				'total_cents'     => $total_cents,
			);
		}

		error_log(
			'Clover: order creation failed for WC order #' . $order->get_order_number()
				. ' — ' . wp_json_encode(isset($result['data']) ? $result['data'] : $result)
		);

		if ($this->is_ambiguous_api_failure($result)) {
			$existing = $this->lookup_existing_clover_order($order, $expected_line_count);
			if ($existing && ! empty($existing['id'])) {
				error_log(
					'Clover: Recovered existing order ' . $existing['id']
						. ' after ambiguous failure for WC order #' . $order->get_order_number()
				);
				return array(
					'clover_order_id' => $existing['id'],
					'tax_cents'       => $tax_cents,
					'total_cents'     => $total_cents,
				);
			}

			return array('failure' => 'ambiguous', 'message' => $this->api_error_message($result));
		}

		return array(
			'failure' => 'deterministic',
			'message' => $this->api_error_message($result),
		);
	}

	/**
	 * Whether an order creation helper returned a failure marker instead of a Clover order.
	 *
	 * @param array|false $result Result from create_atomic_order() or create_order_sequential().
	 * @return bool
	 */
	protected function is_order_creation_failure($result)
	{
		return is_array($result) && isset($result['failure']);
	}

	/**
	 * Create Clover order via atomic_order API (preferred — single call with all line items).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array|false Result array on success, false on failure.
	 */
	protected function create_atomic_order($order)
	{
		$line_data  = $this->build_atomic_line_items($order);
		$line_items = $line_data['elements'];

		if (empty($line_items)) {
			error_log('Clover: No line items to sync for WC order #' . $order->get_order_number());
			return array(
				'failure' => 'deterministic',
				'message' => __('No line items could be mapped for Clover.', 'clover-gateway'),
			);
		}

		$tax_cents   = $this->calculate_order_tax_cents( $order );
		$total_cents = (int) round((float) $order->get_total() * 100);

		$payload = array(
			'state'      => 'open',
			'title'      => $this->get_order_title($order),
			'note'       => $this->build_order_note($order),
			'currency'   => strtoupper($order->get_currency() ?: 'USD'),
			'total'      => $total_cents,
			'taxAmount'  => $tax_cents,
			'lineItems'  => $line_data,
			'testMode'   => (bool) $this->test_mode,
		);

		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/atomic_order/orders',
			$payload
		);

		return $this->parse_order_create_api_result($order, $result, $tax_cents, $total_cents, count($line_items));
	}

	/**
	 * Fallback: create Clover order shell then add line items sequentially.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array|false Result array on success, false on failure.
	 */
	protected function create_order_sequential($order)
	{
		$line_data  = $this->build_atomic_line_items($order);
		$line_items = $line_data['elements'];

		if (empty($line_items)) {
			return array(
				'failure' => 'deterministic',
				'message' => __('No line items could be mapped for Clover.', 'clover-gateway'),
			);
		}

		$tax_cents = $this->calculate_order_tax_cents( $order );
		$total_cents = (int) round((float) $order->get_total() * 100);

		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders',
			array(
				'state'             => 'open',
				'title'             => $this->get_order_title($order),
				'manualTransaction' => false,
				'testMode'          => (bool) $this->test_mode,
				'note'              => $this->build_order_note($order),
				'total'             => $total_cents,
				'currency'          => strtoupper($order->get_currency() ?: 'USD'),
			)
		);

		$parsed = $this->parse_order_create_api_result($order, $result, $tax_cents, $total_cents, count($line_items));
		if ($this->is_order_creation_failure($parsed)) {
			return $parsed;
		}

		$clover_order_id = $parsed['clover_order_id'];
		$line_item_total = count($line_items);

		foreach ( $line_items as $index => $li ) {
			$name    = isset( $li['name'] )         ? $li['name']         : '';
			$price   = isset( $li['price'] )        ? (int) $li['price']  : 0;
			$qty     = isset( $li['unitQty'] )      ? (int) $li['unitQty']: 1000;
			$item_id = isset( $li['item']['id'] )   ? $li['item']['id']   : null;
			// Apply tax only for product line items (those that have taxRates set).
			// Shipping and fee items in the array do not have taxRates.
			$apply_tax_for_item = ! empty( $li['taxRates'] );

			$added = $this->add_line_item( $clover_order_id, $name, $price, $item_id, $apply_tax_for_item, $qty );

			if (empty($added['success']) || empty($added['id'])) {
				$api_message = ! empty($added['message']) ? (string) $added['message'] : '';
				error_log(
					'Clover: Sequential line item failed for "' . $name . '" on order ' . $clover_order_id
						. ($api_message ? ' — ' . $api_message : '')
				);

				$existing = $this->lookup_existing_clover_order($order, $line_item_total);
				if ($existing && ! empty($existing['id'])) {
					if ($existing['id'] !== $clover_order_id) {
						$this->delete_clover_order($clover_order_id);
						return array(
							'clover_order_id' => $existing['id'],
							'tax_cents'       => $tax_cents,
							'total_cents'     => $total_cents,
						);
					}
					if ($this->count_clover_line_items($existing) >= $line_item_total) {
						break;
					}
				}

				$actual_count = $this->get_order_line_item_count($clover_order_id);
				if ($actual_count >= ($index + 1)) {
					usleep(200000);
					continue;
				}

				$this->delete_clover_order($clover_order_id);

				$message = $api_message
					? sprintf(
						/* translators: 1: line item name, 2: Clover API error */
						__('Failed to add "%1$s" to Clover order: %2$s', 'clover-gateway'),
						$name,
						$api_message
					)
					: sprintf(
						/* translators: %s: line item name */
						__('Failed to add line item "%s" to Clover order.', 'clover-gateway'),
						$name
					);

				return array(
					'failure' => 'deterministic',
					'message' => $message,
				);
			}

			usleep(200000);
		}

		$this->update_order_total($clover_order_id, $total_cents);

		return array(
			'clover_order_id' => $clover_order_id,
			'tax_cents'       => $tax_cents,
			'total_cents'     => $total_cents,
		);
	}

	/**
	 * Explicitly set the total on a Clover order after line items are added.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @param int    $total_cents     Total in cents.
	 * @return bool
	 */
	public function update_order_total($clover_order_id, $total_cents)
	{
		if ($total_cents <= 0) {
			return false;
		}

		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id),
			array('total' => (int) $total_cents)
		);

		if (empty($result['success'])) {
			error_log('Clover: Failed to set total on order ' . $clover_order_id . ' — ' . wp_json_encode($result));
			return false;
		}

		return true;
	}

	/**
	 * Create Clover order with all WooCommerce order data.
	 *
	 * Syncs dynamically for ANY merchant — no hardcoded rates or fee names:
	 * - Product line items (atomic_order preferred; sequential fallback with unitQty)
	 * - Shipping (with method name)
	 * - All fees (service fee, convenience fee, any custom fee)
	 *
	 * Tax is NOT sent as a line item — Clover handles tax natively via
	 * tax rates assigned to inventory items in the Clover dashboard.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	public function create_order_with_items($order)
	{
		$this->ensure_order_totals($order);

		$created        = $this->create_atomic_order($order);
		$atomic_failure = null;

		if ($this->is_order_creation_failure($created)) {
			$atomic_failure = $created;
			error_log(
				'Clover: Atomic order creation failed for WC order #' . $order->get_order_number()
					. ' — falling back to sequential. '
					. (isset($created['message']) ? $created['message'] : '')
			);
			$created = $this->create_order_sequential($order);
		}

		if ($this->is_order_creation_failure($created)) {
			$message = '';
			if (! empty($created['message'])) {
				$message = (string) $created['message'];
			} elseif ($atomic_failure && ! empty($atomic_failure['message'])) {
				$message = (string) $atomic_failure['message'];
			}
			if ('' === $message) {
				$message = __('Order could not be created in Clover.', 'clover-gateway');
			}
			return array('success' => false, 'message' => $message);
		}

		if (false === $created || empty($created['clover_order_id'])) {
			return array(
				'success' => false,
				'message' => __('Order could not be created in Clover.', 'clover-gateway'),
			);
		}

		$final_tax = isset( $created['tax_cents'] ) ? (int) $created['tax_cents'] : 0;
		if ( $final_tax > 0 ) {
			$this->update_order_tax_amount( $created['clover_order_id'], $final_tax );
		}

		return array(
			'success'         => true,
			'clover_order_id' => $created['clover_order_id'],
			'tax_cents'       => $final_tax,
			'total_cents'     => isset($created['total_cents']) ? $created['total_cents'] : 0,
		);
	}



	/**
	 * Update the taxAmount on a Clover order so Tax Report records collected tax.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @param int    $tax_cents       Tax amount in cents.
	 * @return bool
	 */
	public function update_order_tax_amount($clover_order_id, $tax_cents)
	{
		if ($tax_cents <= 0) {
			return false;
		}

		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id),
			array('taxAmount' => (int) $tax_cents)
		);

		if (empty($result['success'])) {
			error_log('Clover: Failed to set taxAmount on order ' . $clover_order_id . ' — ' . wp_json_encode($result));
			return false;
		}

		return true;
	}

	/**
	 * Charge a tokenized card against a Clover order.
	 *
	 * Returns a rich result array including card brand, last4, and the Clover v3 payment ID
	 * so callers can persist these for display in WooCommerce and in the Clover Orders dashboard.
	 *
	 * @param string   $card_token      Card token from Clover iframe.
	 * @param int      $amount_cents    Total amount in cents (includes tax + fees).
	 * @param string   $clover_order_id Clover order ID.
	 * @param WC_Order $order           WooCommerce order.
	 *
	 * @return array {
	 *     @type bool   $success        Whether the charge succeeded.
	 *     @type string $charge_id      Clover eCommerce charge ID.
	 *     @type string $payment_id     Clover v3 payment ID (linked to the order tender).
	 *     @type string $card_brand     Normalised card brand, e.g. "Visa", "MasterCard", "AMEX".
	 *     @type string $card_last4     Last 4 digits of the card.
	 *     @type string $card_exp_month Two-digit expiry month.
	 *     @type string $card_exp_year  Four-digit expiry year.
	 *     @type string $tender_label   Human-readable tender label from Clover, e.g. "Credit Card".
	 *     @type array  $raw            Full decoded API response.
	 * }
	 */
	public function charge_card($card_token, $amount_cents, $clover_order_id, $order)
	{
		$order_id  = $order->get_id();
		$currency  = strtolower($order->get_currency() ?: 'usd');
		$tax_cents = (int) round((float) $order->get_total_tax() * 100);

		// Resolve the customer's IP for Clover fraud signals.
		$ip = class_exists('WC_Geolocation')
			? WC_Geolocation::get_ip_address()
			: sanitize_text_field(wp_unslash(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''));

		$payload = array(
			'source'                       => $card_token,
			'amount'                       => (int) $amount_cents,
			'currency'                     => $currency,
			'orderId'                      => $clover_order_id,
			'capture'                      => true,
			'description'                  => 'WooCommerce Order #' . $order->get_order_number(),
			'external_reference_id'        => (string) $order_id,
			'metadata'                     => array(
				'shopping_cart' => 'WP ' . get_bloginfo('version') . ' | WC ' . WC_VERSION . ' | CloverGW ' . WC_CLOVER_GATEWAY_VERSION,
			),
			'skip_default_convenience_fee' => true,
		);

		if ($tax_cents > 0) {
			$payload['tax_amount'] = $tax_cents;
		}

		// Pass customer billing details for Clover's customer records and AVS/fraud checks.
		$customer = array(
			'email' => $order->get_billing_email(),
		);
		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();
		if ($first || $last) {
			$customer['first_name'] = $first;
			$customer['last_name']  = $last;
		}
		$phone = $order->get_billing_phone();
		if ($phone) {
			$customer['phone'] = $phone;
		}
		$payload['customer'] = $customer;

		// Idempotency key: prevents duplicate charges on accidental double-submit.
		$extra_headers = array(
			'Idempotency-Key' => $this->generate_idempotency_key($order_id),
		);
		if ($ip) {
			$extra_headers['X-Forwarded-For'] = $ip;
		}

		$result = $this->request_ecomm('POST', '/charges', $payload, $extra_headers);

		if (! $result['success'] || empty($result['data']['id'])) {
			$message = __('Payment could not be processed. Please try again.', 'clover-gateway');

			if (! empty($result['data']['error']['reason'])) {
				$reason = strtolower((string) $result['data']['error']['reason']);
				if (false !== strpos($reason, 'declined')) {
					$message = __('Your card was declined. Please try another card.', 'clover-gateway');
				}
			} elseif (! empty($result['data']['error']['message'])) {
				error_log('Clover charge error: ' . $result['data']['error']['message']);
			}

			return array('success' => false, 'message' => $message);
		}

		$data = $result['data'];

		if (!isset($data['paid']) || true !== $data['paid']) {
			error_log(sprintf(
				'Clover charge not paid: charge_id=%s status=%s amount=%s paid=%s order_id=%s',
				$data['id'],
				isset($data['status']) ? $data['status'] : 'unknown',
				isset($data['amount']) ? $data['amount'] : $amount_cents,
				isset($data['paid']) ? (true === $data['paid'] ? 'true' : 'false') : 'unset',
				$clover_order_id
			));
			return array(
				'success' => false,
				'message' => __('Payment could not be processed. Please try again.', 'clover-gateway'),
			);
		}

		// ---------------------------------------------------------------
		// Extract card details from the charge response.
		//
		// Clover returns card information in two places:
		//   $data['source']              – tokenised card metadata
		//   $data['payment']             – v3 payment record (contains the
		//                                  tender label shown in Clover Orders
		//                                  dashboard and cardTransaction details)
		//
		// We prefer `source` for brand/last4 (most reliable for eComm tokens)
		// and fall back to `payment.cardTransaction` when `source` is sparse.
		// ---------------------------------------------------------------
		$card_brand     = '';
		$card_last4     = '';
		$card_exp_month = '';
		$card_exp_year  = '';
		$payment_id     = '';
		$tender_label   = '';

		if (! empty($data['source']) && is_array($data['source'])) {
			$src            = $data['source'];
			$card_brand     = isset($src['brand'])     ? strtoupper(trim((string) $src['brand']))     : '';
			$card_last4     = isset($src['last4'])      ? trim((string) $src['last4'])                : '';
			$card_exp_month = isset($src['exp_month'])  ? str_pad((string) $src['exp_month'], 2, '0', STR_PAD_LEFT) : '';
			$card_exp_year  = isset($src['exp_year'])   ? (string) $src['exp_year']                  : '';
		}

		if (! empty($data['payment']) && is_array($data['payment'])) {
			$payment    = $data['payment'];
			$payment_id = isset($payment['id']) ? (string) $payment['id'] : '';

			// Tender label is what the Clover Orders dashboard shows (e.g. "Credit Card").
			if (! empty($payment['tender']['label'])) {
				$tender_label = (string) $payment['tender']['label'];
			}

			// Fallback: populate brand/last4 from the v3 cardTransaction when source was sparse.
			if (! empty($payment['cardTransaction']) && is_array($payment['cardTransaction'])) {
				$ct = $payment['cardTransaction'];
				if (empty($card_brand) && ! empty($ct['cardType'])) {
					$card_brand = strtoupper(trim((string) $ct['cardType']));
				}
				if (empty($card_last4) && ! empty($ct['last4'])) {
					$card_last4 = trim((string) $ct['last4']);
				}
			}
		}

		// Normalise brand codes to human-readable names (mirrors official plugin).
		$brand_map = array(
			'MC'      => 'MasterCard',
			'MASTER'  => 'MasterCard',
			'VISA'    => 'Visa',
			'AMEX'    => 'American Express',
			'DISCOVER' => 'Discover',
			'DINERS'  => 'Diners Club',
			'JCB'     => 'JCB',
		);
		$brand_upper = strtoupper($card_brand);
		if (isset($brand_map[$brand_upper])) {
			$card_brand = $brand_map[$brand_upper];
		} elseif ($card_brand) {
			// Title-case any unrecognised brand so display looks clean.
			$card_brand = ucwords(strtolower($card_brand));
		}

		error_log(sprintf(
			'Clover charge success: charge_id=%s payment_id=%s brand=%s last4=%s tender=%s order_id=%s',
			$data['id'],
			$payment_id,
			$card_brand,
			$card_last4,
			$tender_label,
			$clover_order_id
		));

		return array(
			'success'        => true,
			'charge_id'      => $data['id'],
			'payment_id'     => $payment_id,
			'card_brand'     => $card_brand,
			'card_last4'     => $card_last4,
			'card_exp_month' => $card_exp_month,
			'card_exp_year'  => $card_exp_year,
			'tender_label'   => $tender_label,
			'raw'            => $data,
		);
	}

	/**
	 * Trigger physical printer via Clover print_event API.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @param array  $categories      Print categories (ORDER, RECEIPT, LABEL).
	 * @return bool True if at least one print event succeeded.
	 */
	public function trigger_clover_print($clover_order_id, $categories = null)
	{
		if (null === $categories) {
			$categories = array('ORDER', 'RECEIPT');
		}

		$any_success = false;

		foreach ($categories as $category) {
			$result = $this->request_v3(
				'POST',
				'/merchants/' . rawurlencode($this->merchant_id) . '/print_event',
				array(
					'orderRef' => array('id' => $clover_order_id),
					'category' => $category,
				)
			);

			if (! empty($result['success']) && ! empty($result['data']['id'])) {
				$any_success = true;
				error_log(
					sprintf(
						'Clover Print: event %s | category=%s | state=%s | order=%s',
						$result['data']['id'],
						$category,
						isset($result['data']['state']) ? $result['data']['state'] : 'unknown',
						$clover_order_id
					)
				);
			} else {
				error_log(
					'Clover Print: failed for order ' . $clover_order_id
						. ' category ' . $category
						. ' — ' . wp_json_encode(isset($result['data']) ? $result['data'] : $result)
				);
			}

			usleep(300000);
		}

		return $any_success;
	}

	/**
	 * Print order tickets via Clover's documented print_event REST API.
	 *
	 * Uses POST /print_event (not the undocumented /orders/{id}/fire endpoint).
	 * The /fire endpoint only marks printer-tagged inventory items as sent, which
	 * causes x1/0 and x1/1 quantity display on POS and kitchen tickets.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @return bool
	 */
	public function fire_order($clover_order_id)
	{
		if (empty($clover_order_id)) {
			return false;
		}

		/**
		 * Print categories for API-created orders.
		 *
		 * @param string[] $categories      e.g. array( 'ORDER', 'RECEIPT' ).
		 * @param string   $clover_order_id Clover order ID.
		 */
		$categories = apply_filters('clover_gateway_print_categories', array('ORDER', 'RECEIPT'), $clover_order_id);
		if (empty($categories)) {
			return false;
		}

		if ($this->trigger_clover_print($clover_order_id, $categories)) {
			return true;
		}

		/**
		 * Optional legacy fallback to /fire. Off by default — partial item marking
		 * produces incorrect x1/0 quantity display on devices and printed tickets.
		 *
		 * @param bool   $allow           Whether to call /fire when print_event fails.
		 * @param string $clover_order_id Clover order ID.
		 */
		if (! apply_filters('clover_gateway_allow_fire_endpoint', false, $clover_order_id)) {
			return false;
		}

		$fire_result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id) . '/fire',
			array()
		);

		if (! empty($fire_result['success'])) {
			error_log('Clover: used legacy /fire fallback for order ' . $clover_order_id);
			return true;
		}

		error_log(
			'Clover: print_event and /fire both failed for order ' . $clover_order_id
				. ' — ' . wp_json_encode(isset($fire_result['data']) ? $fire_result['data'] : $fire_result)
		);

		return false;
	}

	/**
	 * Fetch all tax rates for this merchant.
	 *
	 * Results are cached per-instance so multiple line items in one order only hit the API once.
	 *
	 * @return array Array of tax rate objects, each with 'id', 'name', 'rate' keys.
	 */
	public function get_tax_rates()
	{
		if (null !== $this->cached_tax_rates) {
			return $this->cached_tax_rates;
		}

		$result = $this->request_v3(
			'GET',
			'/merchants/' . rawurlencode($this->merchant_id) . '/tax_rates'
		);

		if (! $result['success'] || empty($result['data']['elements'])) {
			$this->cached_tax_rates = array();
			return array();
		}

		$this->cached_tax_rates = $result['data']['elements'];
		return $this->cached_tax_rates;
	}

	/**
	 * Fetch the full tax rate object for a given tax rate ID.
	 * Uses the per-instance cache so only one API call is made per order.
	 *
	 * @param string $tax_rate_id Clover tax rate ID.
	 * @return array|null Full rate object (with id, name, rate), or null if not found.
	 */
	protected function get_tax_rate_by_id($tax_rate_id)
	{
		$rates = $this->get_tax_rates();
		foreach ($rates as $rate) {
			if (isset($rate['id']) && $rate['id'] === $tax_rate_id) {
				return $rate;
			}
		}
		return null;
	}

	/**
	 * Assign a tax rate to a Clover inventory item.
	 *
	 * @param string $item_id     Clover item ID.
	 * @param string $tax_rate_id Clover tax rate ID.
	 *
	 * @return bool
	 */
	public function assign_item_tax_rate($item_id, $tax_rate_id)
	{
		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/item_tax_rates',
			array(
				'item'    => array('id' => $item_id),
				'taxRate' => array('id' => $tax_rate_id),
			)
		);

		if (! empty($result['success'])) {
			return true;
		}

		error_log('Clover: Failed to assign tax rate ' . $tax_rate_id . ' to item ' . $item_id . ' — ' . wp_json_encode($result));
		return false;
	}

	/**
	 * Fetch the merchant's cash tender ID from Clover.
	 * The tender ID is merchant-specific and required for the payments endpoint.
	 * Used when recording a cash payment via add_cash_tender() (e.g. optional COD-as-paid flow).
	 *
	 * @return string|null Cash tender ID, or null on failure.
	 */
	protected function get_cash_tender_id()
	{
		$result = $this->request_v3(
			'GET',
			'/merchants/' . rawurlencode($this->merchant_id) . '/tenders'
		);

		if (! $result['success'] || empty($result['data']['elements'])) {
			error_log('Clover: Could not fetch tenders for merchant ' . $this->merchant_id);
			return null;
		}

		foreach ($result['data']['elements'] as $tender) {
			if (isset($tender['labelKey']) && $tender['labelKey'] === 'com.clover.tender.cash') {
				return $tender['id'];
			}
		}

		error_log('Clover: Cash tender not found in merchant tenders list');
		return null;
	}

	/**
	 * Add a cash tender to a Clover order, marking it as paid.
	 * Optional: use only if you want COD/offline orders to appear as Paid in Clover
	 * (e.g. for reporting). By default, COD orders stay Open until closed on the device.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @param int    $amount_cents   Amount in cents.
	 * @param int    $tax_cents      Tax amount in cents (for Tax Report).
	 * @return bool
	 */
	public function add_cash_tender($clover_order_id, $amount_cents, $tax_cents = 0)
	{
		$tender_id = $this->get_cash_tender_id();

		if (empty($tender_id)) {
			return false;
		}

		$payload = array(
			'tender'        => array('id' => $tender_id),
			'amount'        => (int) $amount_cents,
			'tipAmount'     => 0,
			'cashTendered'  => (int) $amount_cents,
		);

		if ($tax_cents > 0) {
			$payload['taxAmount'] = (int) $tax_cents;
		}

		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id) . '/payments',
			$payload
		);

		if (! empty($result['success'])) {
			return true;
		}

		error_log('Clover: Failed to add cash tender to order ' . $clover_order_id . ' — ' . wp_json_encode(isset($result['data']) ? $result['data'] : $result));
		return false;
	}

	/**
	 * Issue a refund.
	 *
	 * @param string $charge_id    Charge ID.
	 * @param int    $amount_cents Amount in cents.
	 * @param string $reason       Reason.
	 *
	 * @return array
	 */
	public function refund($charge_id, $amount_cents, $reason = 'requested_by_customer')
	{
		$result = $this->request_ecomm(
			'POST',
			'/refunds',
			array(
				'charge' => $charge_id,
				'amount' => (int) $amount_cents,
				'reason' => $reason,
			)
		);

		if (! $result['success']) {
			return array('success' => false, 'message' => __('Refund could not be processed. Please try again.', 'clover-gateway'));
		}

		return array('success' => true, 'data' => $result['data']);
	}

	/**
	 * Validate credentials — tests both v3 REST and eCommerce API.
	 *
	 * @return array
	 */
	public function validate_credentials()
	{
		$result = $this->request_v3('GET', '/merchants/' . rawurlencode($this->merchant_id) . '/orders?limit=1');
		if (! $result['success']) {
			return $result;
		}

		$result_ecomm = $this->request_ecomm('GET', '/charges?limit=1');
		if (! $result_ecomm['success']) {
			return $result_ecomm;
		}

		return array('success' => true);
	}
}
