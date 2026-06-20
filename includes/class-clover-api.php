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
			return array('success' => false, 'message' => __('Payment could not be processed. Please try again.', 'clover-gateway'));
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code < 200 || $code >= 300) {
			error_log('Clover v3 API HTTP ' . $code . ': ' . wp_json_encode($body));
			return array('success' => false, 'message' => __('Payment could not be processed. Please try again.', 'clover-gateway'), 'data' => $body);
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
	 * @return string|false Clover line item ID on success, false on failure.
	 */
	protected function add_line_item($clover_order_id, $name, $price_cents, $clover_item_id = null, $apply_tax = false)
	{

		if (! empty($clover_item_id) && is_string($clover_item_id)) {
			$body = array(
				'item'    => array('id' => trim($clover_item_id)),
				'price'   => (int) $price_cents,
				'printed' => false,
			);
		} else {
			$body = array(
				'name'    => $name,
				'price'   => (int) $price_cents,
				'printed' => false,
			);
		}

		// Embed the full tax rate object AND the pre-calculated taxAmount on the line item.
		// taxRates  → tells Clover which rate applies (affects receipt display + rate association).
		// taxAmount → the explicit cents value; this is what Clover's Tax Report reads directly.
		// Both are needed: taxRates alone is silently ignored for API orders, and taxAmount alone
		// is not attributed to a named rate without taxRates.
		if ($apply_tax && ! empty($this->default_tax_rate_id)) {
			$full_rate    = $this->get_tax_rate_by_id($this->default_tax_rate_id);
			$rate_element = array('id' => $this->default_tax_rate_id);

			if ($full_rate) {
				$rate_element['name'] = isset($full_rate['name']) ? $full_rate['name'] : '';
				$rate_element['rate'] = isset($full_rate['rate']) ? (int) $full_rate['rate'] : 0;

				// Pre-calculate the tax for this line item in cents.
				// Clover stores rate as integer where 875000 = 8.75% (i.e. rate / 10,000,000 = decimal).
				if ($rate_element['rate'] > 0) {
					$body['taxAmount'] = (int) round($price_cents * $rate_element['rate'] / 10000000);
				}
			}

			$body['taxRates'] = array(
				'elements' => array($rate_element),
			);
		}

		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id) . '/line_items',
			$body
		);

		if (! $result['success'] || empty($result['data']['id'])) {
			error_log('Clover: Failed to add line item "' . $name . '" to order ' . $clover_order_id . ' — ' . wp_json_encode(isset($result['data']) ? $result['data'] : $result));
			return false;
		}

		return $result['data']['id'];
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
	 * Create Clover order with all WooCommerce order data.
	 *
	 * Syncs dynamically for ANY merchant — no hardcoded rates or fee names:
	 * - Product line items (1 call per unit, linked via item.id when synced)
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

		// Build note: order number (no "WooCommerce" label), customer, phone, pickup/delivery date & time.
		$note_lines   = array();
		$note_lines[] = 'Order #' . $order->get_order_number();

		$billing_name = trim($order->get_formatted_billing_full_name());
		if ($billing_name) {
			$note_lines[] = 'Customer: ' . $billing_name;
		}
		if ($order->get_billing_phone()) {
			$note_lines[] = 'Phone: ' . $order->get_billing_phone();
		}

		// Pickup/delivery date and time (from your checkout custom fields).
		$order_id      = $order->get_id();
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

		// Create Clover order shell.
		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders',
			array(
				'state'             => 'open',
				'manualTransaction' => false,
				'groupLineItems'    => true,
				'testMode'          => (bool) $this->test_mode,
				'note'              => implode("\n", $note_lines),
			)
		);

		if (! $result['success'] || empty($result['data']['id'])) {
			return array('success' => false, 'message' => __('Payment could not be processed. Please try again.', 'clover-gateway'));
		}

		$clover_order_id = $result['data']['id'];

		// --------------------------------------------------
		// 1. Product line items — 1 API call per unit
		// --------------------------------------------------
		foreach ($order->get_items() as $item) {
			$quantity = (int) $item->get_quantity();
			$total    = (float) $item->get_total();

			if ($quantity <= 0) {
				continue;
			}

			$unit_price_cents = (int) round(($total / $quantity) * 100);

			$clover_item_id = null;
			$product_id     = $item->get_product_id();
			$variation_id   = method_exists($item, 'get_variation_id') ? $item->get_variation_id() : 0;
			$lookup_id      = $variation_id ? $variation_id : $product_id;

			if ($lookup_id) {
				$clover_item_id = get_post_meta($lookup_id, '_clover_item_id', true);
				if (empty($clover_item_id) && $variation_id && $product_id) {
					$clover_item_id = get_post_meta($product_id, '_clover_item_id', true);
				}
			}

		// Ensure the Clover inventory item has the tax rate permanently assigned (catalog level).
		// Cached per item for 7 days. The transient is set regardless of API result to prevent
		// hammering the endpoint on every order — 409 (already assigned) is treated as success.
		if (! empty($clover_item_id) && ! empty($this->default_tax_rate_id)) {
				$cache_key = 'clover_itax_' . md5($clover_item_id . '_' . $this->default_tax_rate_id);
				if (! get_transient($cache_key)) {
					$assigned = $this->assign_item_tax_rate($clover_item_id, $this->default_tax_rate_id);
					// Always set the transient — even on failure/duplicate — to stop retry spam.
					set_transient($cache_key, $assigned ? 'ok' : 'attempted', 7 * DAY_IN_SECONDS);
				}
			}

			for ($i = 0; $i < $quantity; $i++) {
				$line_item_id = $this->add_line_item($clover_order_id, $item->get_name(), $unit_price_cents, $clover_item_id, true);
				if (! $line_item_id) {
					return array('success' => false, 'message' => __('Payment could not be processed. Please try again.', 'clover-gateway'));
				}
				$this->apply_line_item_tax_rate($clover_order_id, $line_item_id);
			}
		}

		// --------------------------------------------------
		// 2. Shipping — dynamic label with method name
		// --------------------------------------------------
		$shipping_total = (float) $order->get_shipping_total();
		if ($shipping_total > 0) {
			$shipping_label  = __('Shipping', 'clover-gateway');
			$shipping_method = $order->get_shipping_method();
			if ($shipping_method) {
				$shipping_label = sprintf(__('Shipping: %s', 'clover-gateway'), $shipping_method);
			}
			$this->add_line_item($clover_order_id, $shipping_label, (int) round($shipping_total * 100));
		}

		// --------------------------------------------------
		// 3. Fees — reads ALL fees dynamically
		// --------------------------------------------------
		foreach ($order->get_fees() as $fee) {
			$fee_total = (float) $fee->get_total();
			$fee_name  = $fee->get_name();

			if (abs($fee_total) <= 0) {
				continue;
			}

			if (stripos($fee_name, 'tax') !== false) {
				continue;
			}

			$this->add_line_item($clover_order_id, $fee_name, (int) round($fee_total * 100));
		}

		// --------------------------------------------------
		// 4. Calculate tax and stamp it on the order
		// --------------------------------------------------
		// Primary: derive tax from the configured Clover rate (reliable regardless of whether
		// WooCommerce tax is enabled or what tax class the product uses).
		// Fallback: use WooCommerce's own get_total_tax() if no Clover rate is configured.
		$tax_cents      = 0;
		$wc_tax_cents   = (int) round((float) $order->get_total_tax() * 100);
		$clover_full_rate = ! empty($this->default_tax_rate_id) ? $this->get_tax_rate_by_id($this->default_tax_rate_id) : null;

		if ($clover_full_rate && isset($clover_full_rate['rate']) && (int) $clover_full_rate['rate'] > 0) {
			// Sum up only taxable product line items (not shipping / fees).
			$product_subtotal_cents = 0;
			foreach ($order->get_items() as $_item) {
				$product_subtotal_cents += (int) round((float) $_item->get_total() * 100);
			}
			$tax_cents = (int) round($product_subtotal_cents * (int) $clover_full_rate['rate'] / 10000000);
		}

		// Fall back to WooCommerce tax if Clover rate produced nothing.
		if ($tax_cents === 0 && $wc_tax_cents > 0) {
			$tax_cents = $wc_tax_cents;
		}

		if ($tax_cents > 0) {
			$this->update_order_tax_amount($clover_order_id, $tax_cents);
		}

		return array(
			'success'         => true,
			'clover_order_id' => $clover_order_id,
			'tax_cents'       => $tax_cents,
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
	 * Fire order to kitchen/printer.
	 *
	 * @param string $clover_order_id Clover order ID.
	 * @return bool
	 */
	public function fire_order($clover_order_id)
	{
		$result = $this->request_v3(
			'POST',
			'/merchants/' . rawurlencode($this->merchant_id) . '/orders/' . rawurlencode($clover_order_id) . '/fire',
			array()
		);
		return ! empty($result['success']);
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
