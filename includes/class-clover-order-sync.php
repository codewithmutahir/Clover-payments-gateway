<?php
/**
 * Sync WooCommerce orders (COD, Cash on Pickup, etc.) to Clover POS.
 *
 * @package Clover_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes non-card WooCommerce orders to Clover when they are placed or reach a syncable status.
 */
class Clover_Order_Sync {

	/**
	 * WooCommerce order action key (underscores become hyphens in the action hook).
	 *
	 * @var string
	 */
	private const ORDER_ACTION_KEY = 'clover_sync_pos';

	/**
	 * @var Clover_Order_Sync|null
	 */
	protected static $instance = null;

	/**
	 * @return Clover_Order_Sync
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
		// Single hook: checkout also triggers status_changed; a second checkout hook caused duplicate Clover orders/prints.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_filter( 'woocommerce_order_actions', array( $this, 'register_order_action' ), 10, 2 );
		// WC runs: do_action( 'woocommerce_order_action_' . sanitize_title( $key ), $order ).
		add_action(
			'woocommerce_order_action_' . sanitize_title( self::ORDER_ACTION_KEY ),
			array( $this, 'run_manual_sync_action' )
		);
	}

	/**
	 * WooCommerce order statuses that should trigger a Clover POS sync.
	 *
	 * @return string[]
	 */
	protected function syncable_statuses() {
		$statuses = array( 'processing', 'on-hold', 'pending' );
		/**
		 * Filters which WooCommerce order statuses trigger Clover POS sync.
		 *
		 * @param string[] $statuses Status slugs without wc- prefix.
		 */
		return apply_filters( 'clover_gateway_pos_sync_statuses', $statuses );
	}

	/**
	 * Payment method IDs that already create Clover orders in their own checkout flow.
	 *
	 * @return string[]
	 */
	protected function skip_payment_methods() {
		$methods = array( 'clover_gateway' );
		/**
		 * Filters payment methods that should not use the generic POS sync hook.
		 *
		 * @param string[] $methods Gateway IDs.
		 */
		return apply_filters( 'clover_gateway_skip_pos_sync_methods', $methods );
	}

	/**
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @return void
	 */
	public function on_status_changed( $order_id, $old_status, $new_status ) {
		if ( ! in_array( $new_status, $this->syncable_statuses(), true ) ) {
			return;
		}
		$this->sync_order( (int) $order_id );
	}

	/**
	 * @param array    $actions Order actions.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public function register_order_action( $actions, $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		$actions[ self::ORDER_ACTION_KEY ] = __( 'Send to Clover POS', 'clover-gateway' );
		return $actions;
	}

	/**
	 * @param WC_Order|int $order Order object or ID.
	 * @return void
	 */
	public function run_manual_sync_action( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( (int) $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->sync_order( $order->get_id(), true );
	}

	/**
	 * Persist an order note (HPOS-safe).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $note  Note text.
	 * @return void
	 */
	protected function add_sync_note( WC_Order $order, $note ) {
		$order->add_order_note( $note );
		$order->save();
	}

	/**
	 * API credentials for POS sync (custom gateway settings, then official Clover plugin).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_sync_credentials() {
		$custom = get_option( 'woocommerce_clover_gateway_settings', array() );
		if ( is_array( $custom ) && ! empty( $custom['merchant_id'] ) && ! empty( $custom['api_token'] ) ) {
			return array(
				'merchant_id'         => (string) $custom['merchant_id'],
				'api_token'           => (string) $custom['api_token'],
				'public_key'          => isset( $custom['public_key'] ) ? (string) $custom['public_key'] : '',
				'private_key'         => isset( $custom['private_key'] ) ? (string) $custom['private_key'] : '',
				'test_mode'           => isset( $custom['test_mode'] ) && 'yes' === $custom['test_mode'],
				'default_tax_rate_id' => isset( $custom['default_tax_rate_id'] ) ? (string) $custom['default_tax_rate_id'] : '',
				'source'              => 'clover_gateway',
			);
		}

		$official = get_option( 'woocommerce_clover_payments_settings', array() );
		if ( ! is_array( $official ) ) {
			return null;
		}

		$sandbox = isset( $official['environment'] ) && 'sandbox' === $official['environment'];
		$merchant = $sandbox
			? ( $official['test_merchant_id'] ?? '' )
			: ( $official['merchant_id'] ?? '' );
		$api_key = $sandbox
			? ( $official['test_private_key'] ?? '' )
			: ( $official['private_key'] ?? '' );

		if ( '' === trim( (string) $merchant ) || '' === trim( (string) $api_key ) ) {
			return null;
		}

		return array(
			'merchant_id'         => (string) $merchant,
			'api_token'           => (string) $api_key,
			'public_key'          => $sandbox ? (string) ( $official['test_publishable_key'] ?? '' ) : (string) ( $official['publishable_key'] ?? '' ),
			'private_key'         => (string) $api_key,
			'test_mode'           => $sandbox,
			'default_tax_rate_id' => '',
			'source'              => 'clover_payments',
		);
	}

	/**
	 * Whether this order should be synced by the generic POS hook.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	protected function should_sync_order( WC_Order $order ) {
		if ( in_array( $order->get_payment_method(), $this->skip_payment_methods(), true ) ) {
			return false;
		}

		/**
		 * Last chance to include or exclude an order from Clover POS sync.
		 *
		 * @param bool     $should_sync Default true for non-skipped payment methods.
		 * @param WC_Order $order       Order.
		 */
		return (bool) apply_filters( 'clover_gateway_should_sync_order', true, $order );
	}

	/**
	 * Atomically acquire a per-order POS sync lock (object cache + DB fallback).
	 *
	 * @param int $order_id Order ID.
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_pos_sync_lock( $order_id ) {
		$lock_key = 'clover_pos_sync_' . (int) $order_id;
		$group    = 'clover_pos_sync';
		$ttl      = 2 * MINUTE_IN_SECONDS;

		if ( false === wp_cache_add( $lock_key, 1, $group, $ttl ) ) {
			return false;
		}

		// add_option() fails when option_name already exists (atomic DB insert).
		if ( false === add_option( $lock_key, time(), '', 'no' ) ) {
			$locked_at = (int) get_option( $lock_key );
			if ( $locked_at && ( time() - $locked_at ) < $ttl ) {
				wp_cache_delete( $lock_key, $group );
				return false;
			}

			delete_option( $lock_key );
			wp_cache_delete( $lock_key, $group );

			if ( false === wp_cache_add( $lock_key, 1, $group, $ttl ) ) {
				return false;
			}
			if ( false === add_option( $lock_key, time(), '', 'no' ) ) {
				wp_cache_delete( $lock_key, $group );
				return false;
			}
		}

		return true;
	}

	/**
	 * Release a per-order POS sync lock.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function release_pos_sync_lock( $order_id ) {
		$lock_key = 'clover_pos_sync_' . (int) $order_id;
		wp_cache_delete( $lock_key, 'clover_pos_sync' );
		delete_option( $lock_key );
	}

	/**
	 * Create the Clover POS order and fire printers.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $force    Re-sync even if meta already exists.
	 * @return bool True when synced (or already synced).
	 */
	public function sync_order( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		if ( $force ) {
			$order->delete_meta_data( '_clover_order_id' );
			$order->delete_meta_data( '_clover_amount_cents' );
			$order->save();
		} elseif ( $order->get_meta( '_clover_order_id' ) ) {
			return true;
		}

		if ( ! $force && ! $this->acquire_pos_sync_lock( $order_id ) ) {
			return true;
		}

		if ( ! $this->should_sync_order( $order ) ) {
			if ( ! $force ) {
				$this->release_pos_sync_lock( $order_id );
			}
			$this->add_sync_note(
				$order,
				sprintf(
					/* translators: %s: payment method ID */
					__( 'Clover POS sync skipped for payment method "%s".', 'clover-gateway' ),
					$order->get_payment_method()
				)
			);
			return false;
		}

		$creds = self::get_sync_credentials();
		if ( ! $creds ) {
			if ( ! $force ) {
				$this->release_pos_sync_lock( $order_id );
			}
			$this->add_sync_note(
				$order,
				__( 'Clover POS sync skipped: add Merchant ID + API token under WooCommerce → Settings → Payments → Clover Payments, or configure the official Clover plugin keys.', 'clover-gateway' )
			);
			return false;
		}

		if ( method_exists( $order, 'calculate_totals' ) ) {
			$order->calculate_totals();
		}

		$api = new Clover_API(
			$creds['merchant_id'],
			$creds['api_token'],
			$creds['public_key'],
			$creds['private_key'],
			$creds['test_mode'],
			$creds['default_tax_rate_id']
		);

		$result = $api->create_order_with_items( $order );
		if ( empty( $result['success'] ) ) {
			if ( ! $force ) {
				$this->release_pos_sync_lock( $order_id );
			}
			$message = ! empty( $result['message'] ) ? (string) $result['message'] : __( 'Unknown Clover API error.', 'clover-gateway' );
			$env     = ! empty( $creds['test_mode'] )
				? __( 'sandbox', 'clover-gateway' )
				: __( 'production', 'clover-gateway' );
			$this->add_sync_note(
				$order,
				sprintf(
					/* translators: 1: error message, 2: sandbox or production, 3: credential source label */
					__( 'Clover POS sync failed (%2$s, %3$s): %1$s', 'clover-gateway' ),
					$message,
					$env,
					(string) $creds['source']
				)
			);
			error_log( 'Clover Sync: failed for order #' . $order_id . ' — ' . $message );
			return false;
		}

		$clover_order_id = (string) $result['clover_order_id'];
		$amount_cents    = (int) round( (float) $order->get_total() * 100 );

		// Persist before fire so a parallel status/checkout hook cannot create a second Clover order.
		$order->update_meta_data( '_clover_order_id', $clover_order_id );
		$order->update_meta_data( '_clover_amount_cents', $amount_cents );
		$order->save();

		$api->fire_order( $clover_order_id );

		$env = ! empty( $creds['test_mode'] )
			? __( 'sandbox Clover', 'clover-gateway' )
			: __( 'production Clover', 'clover-gateway' );

		$this->add_sync_note(
			$order,
			sprintf(
				/* translators: 1: payment method title, 2: Clover order ID, 3: sandbox or production */
				__( 'Order synced to %3$s (%1$s). Clover Order ID: %2$s', 'clover-gateway' ),
				$order->get_payment_method_title(),
				$clover_order_id,
				$env
			)
		);

		error_log( 'Clover Sync: order #' . $order_id . ' synced as ' . $clover_order_id );

		if ( ! $force ) {
			$this->release_pos_sync_lock( $order_id );
		}

		return true;
	}
}
