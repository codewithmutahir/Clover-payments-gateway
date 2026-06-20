<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clover Inventory Sync — core matching, linking, and creation logic.
 *
 * Safety: NEVER modifies/deletes existing Clover items.
 * Only reads Clover inventory for matching. Creates new items only on explicit request.
 */
class Clover_Inventory_Sync {

	protected static $instance = null;

	/** @var Clover_API|null */
	protected $api = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_save' ) );
	}

	/**
	 * Get a Clover_API instance from saved gateway settings.
	 *
	 * @return Clover_API|null
	 */
	public function get_api() {
		if ( $this->api ) {
			return $this->api;
		}
		$settings = get_option( 'woocommerce_clover_gateway_settings', array() );
		$mid      = isset( $settings['merchant_id'] ) ? $settings['merchant_id'] : '';
		$token    = isset( $settings['api_token'] ) ? $settings['api_token'] : '';
		$pub      = isset( $settings['public_key'] ) ? $settings['public_key'] : '';
		$priv     = isset( $settings['private_key'] ) ? $settings['private_key'] : '';
		$test     = isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'];

		if ( empty( $mid ) || empty( $token ) ) {
			return null;
		}
		$this->api = new Clover_API( $mid, $token, $pub, $priv, $test );
		return $this->api;
	}

	// ------------------------------------------------------------------
	// Normalisation helpers
	// ------------------------------------------------------------------

	public static function normalize_name( $name ) {
		$name = is_string( $name ) ? $name : '';
		$name = strtolower( $name );
		$name = preg_replace( '/[^a-z0-9\s]/u', '', $name );
		$name = preg_replace( '/\s+/', ' ', $name );
		return trim( $name );
	}

	public static function similarity_score( $a, $b ) {
		$a = self::normalize_name( $a );
		$b = self::normalize_name( $b );
		if ( $a === '' || $b === '' ) {
			return 0.0;
		}
		similar_text( $a, $b, $percent );
		return round( $percent, 2 );
	}

	// ------------------------------------------------------------------
	// Fetch all Clover items (paginated, max 100 per page)
	// ------------------------------------------------------------------

	public function get_all_clover_items() {
		$api = $this->get_api();
		if ( ! $api ) {
			return array();
		}

		$settings = get_option( 'woocommerce_clover_gateway_settings', array() );
		$mid      = isset( $settings['merchant_id'] ) ? $settings['merchant_id'] : '';
		$test     = isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'];
		$base     = $test ? 'https://apisandbox.dev.clover.com/v3' : 'https://api.clover.com/v3';
		$token    = isset( $settings['api_token'] ) ? $settings['api_token'] : '';

		$all    = array();
		$offset = 0;
		$limit  = 100;

		do {
			$url  = $base . '/merchants/' . rawurlencode( $mid ) . '/items?limit=' . $limit . '&offset=' . $offset;
			$resp = wp_remote_get( $url, array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 30,
			) );

			if ( is_wp_error( $resp ) ) {
				error_log( 'Clover inventory fetch error: ' . $resp->get_error_message() );
				break;
			}

			$code = wp_remote_retrieve_response_code( $resp );
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );

			if ( $code < 200 || $code >= 300 ) {
				error_log( 'Clover inventory fetch HTTP ' . $code );
				break;
			}

			$elements = array();
			if ( ! empty( $body['elements'] ) && is_array( $body['elements'] ) ) {
				$elements = $body['elements'];
			}

			$all    = array_merge( $all, $elements );
			$offset += $limit;
		} while ( count( $elements ) === $limit );

		return $all;
	}

	// ------------------------------------------------------------------
	// Match a single WooCommerce product against Clover items
	// ------------------------------------------------------------------

	/**
	 * @param WC_Product $product
	 * @param array      $clover_items Pre-fetched list.
	 * @return array     Keys: status, clover_id, clover_name, score
	 */
	public function match_product( $product, $clover_items ) {
		$product_id = $product->get_id();

		// Step 1: Already linked?
		$existing = get_post_meta( $product_id, '_clover_item_id', true );
		if ( ! empty( $existing ) ) {
			$clover_name = '';
			foreach ( $clover_items as $ci ) {
				if ( isset( $ci['id'] ) && $ci['id'] === $existing ) {
					$clover_name = isset( $ci['name'] ) ? $ci['name'] : '';
					break;
				}
			}
			return array(
				'status'      => 'already_linked',
				'clover_id'   => $existing,
				'clover_name' => $clover_name,
				'score'       => 100,
			);
		}

		$sku      = $product->get_sku();
		$woo_name = $product->get_name();

		// Step 2: SKU match.
		if ( ! empty( $sku ) ) {
			foreach ( $clover_items as $ci ) {
				$ci_sku = '';
				if ( ! empty( $ci['sku'] ) ) {
					$ci_sku = $ci['sku'];
				} elseif ( ! empty( $ci['itemCode'] ) ) {
					$ci_sku = $ci['itemCode'];
				}
				if ( $ci_sku !== '' && strcasecmp( trim( $ci_sku ), trim( $sku ) ) === 0 ) {
					return array(
						'status'      => 'auto_linked',
						'clover_id'   => $ci['id'],
						'clover_name' => isset( $ci['name'] ) ? $ci['name'] : '',
						'score'       => 100,
					);
				}
			}
		}

		// Step 3: Normalized exact name match.
		$woo_norm = self::normalize_name( $woo_name );
		if ( $woo_norm !== '' ) {
			foreach ( $clover_items as $ci ) {
				$ci_name = isset( $ci['name'] ) ? $ci['name'] : '';
				if ( self::normalize_name( $ci_name ) === $woo_norm ) {
					return array(
						'status'      => 'auto_linked',
						'clover_id'   => $ci['id'],
						'clover_name' => $ci_name,
						'score'       => 100,
					);
				}
				$ci_alt = isset( $ci['alternateName'] ) ? $ci['alternateName'] : '';
				if ( $ci_alt !== '' && self::normalize_name( $ci_alt ) === $woo_norm ) {
					return array(
						'status'      => 'auto_linked',
						'clover_id'   => $ci['id'],
						'clover_name' => $ci_name,
						'score'       => 100,
					);
				}
			}
		}

		// Step 4: Fuzzy similarity.
		$best_score = 0;
		$best_item  = null;
		foreach ( $clover_items as $ci ) {
			$ci_name = isset( $ci['name'] ) ? $ci['name'] : '';
			$score   = self::similarity_score( $woo_name, $ci_name );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_item  = $ci;
			}
		}

		if ( $best_score >= 90 && $best_item ) {
			return array(
				'status'      => 'needs_review',
				'clover_id'   => $best_item['id'],
				'clover_name' => isset( $best_item['name'] ) ? $best_item['name'] : '',
				'score'       => $best_score,
			);
		}

		if ( $best_score >= 70 && $best_item ) {
			return array(
				'status'      => 'possible_match',
				'clover_id'   => $best_item['id'],
				'clover_name' => isset( $best_item['name'] ) ? $best_item['name'] : '',
				'score'       => $best_score,
			);
		}

		return array(
			'status'      => 'no_match',
			'clover_id'   => '',
			'clover_name' => '',
			'score'       => $best_score,
		);
	}

	// ------------------------------------------------------------------
	// Dry-run sync — returns results without touching anything
	// ------------------------------------------------------------------

	public function dry_run_sync() {
		$clover_items = $this->get_all_clover_items();

		$args = array(
			'status'  => 'publish',
			'limit'   => -1,
			'orderby' => 'title',
			'order'   => 'ASC',
		);
		$products = wc_get_products( $args );

		$results = array();
		foreach ( $products as $product ) {
			$match = $this->match_product( $product, $clover_items );
			$results[] = array(
				'product_id'   => $product->get_id(),
				'product_name' => $product->get_name(),
				'sku'          => $product->get_sku(),
				'status'       => $match['status'],
				'clover_id'    => $match['clover_id'],
				'clover_name'  => $match['clover_name'],
				'score'        => $match['score'],
			);
		}

		set_transient( 'clover_sync_dry_run', $results, HOUR_IN_SECONDS );
		return $results;
	}

	// ------------------------------------------------------------------
	// Link / unlink
	// ------------------------------------------------------------------

	public function link_product( $woo_product_id, $clover_item_id ) {
		update_post_meta( $woo_product_id, '_clover_item_id', sanitize_text_field( $clover_item_id ) );
		update_post_meta( $woo_product_id, '_clover_item_synced_at', time() );
		update_post_meta( $woo_product_id, '_clover_sync_status', 'linked' );
	}

	public function skip_product( $woo_product_id ) {
		update_post_meta( $woo_product_id, '_clover_sync_status', 'skipped' );
	}

	// ------------------------------------------------------------------
	// Create a new item in Clover (only on explicit user action)
	// ------------------------------------------------------------------

	/**
	 * Create a WooCommerce product as a Clover inventory item and link it back.
	 *
	 * @param WC_Product $product     The product to export.
	 * @param string     $tax_rate_id Optional Clover tax rate ID to assign after creation.
	 *
	 * @return array Keys: success (bool), clover_id (string), message (string on failure).
	 */
	public function create_clover_item( $product, $tax_rate_id = '' ) {
		$api = $this->get_api();
		if ( ! $api ) {
			return array( 'success' => false, 'message' => 'API not configured.' );
		}

		$settings = get_option( 'woocommerce_clover_gateway_settings', array() );
		$mid      = isset( $settings['merchant_id'] ) ? $settings['merchant_id'] : '';
		$token    = isset( $settings['api_token'] ) ? $settings['api_token'] : '';
		$test     = isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'];
		$base     = $test ? 'https://apisandbox.dev.clover.com/v3' : 'https://api.clover.com/v3';

		$payload = array(
			'name'      => $product->get_name(),
			'price'     => (int) round( (float) $product->get_price() * 100 ),
			'priceType' => 'FIXED',
			'available' => true,
		);

		$sku = $product->get_sku();
		if ( ! empty( $sku ) ) {
			$payload['sku'] = $sku;
		}

		$url  = $base . '/merchants/' . rawurlencode( $mid ) . '/items';
		$resp = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'message' => $resp->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['id'] ) ) {
			return array( 'success' => false, 'message' => 'Clover returned HTTP ' . $code );
		}

		$clover_id = $body['id'];
		$this->link_product( $product->get_id(), $clover_id );

		// Assign the tax rate so the rate name appears on receipts and in tax reports.
		if ( ! empty( $tax_rate_id ) ) {
			$api->assign_item_tax_rate( $clover_id, $tax_rate_id );
		}

		return array( 'success' => true, 'clover_id' => $clover_id );
	}

	// ------------------------------------------------------------------
	// Bulk export: push WooCommerce products to Clover inventory in batches
	// ------------------------------------------------------------------

	/**
	 * Return the total count of exportable products (simple + variations).
	 *
	 * @return int
	 */
	public function count_exportable_products() {
		$simple = wc_get_products( array(
			'status' => 'publish',
			'type'   => 'simple',
			'limit'  => -1,
			'return' => 'ids',
		) );

		$variations = wc_get_products( array(
			'status' => 'publish',
			'type'   => 'variation',
			'limit'  => -1,
			'return' => 'ids',
		) );

		return count( $simple ) + count( $variations );
	}

	/**
	 * Export one batch of products to Clover.
	 *
	 * @param int    $offset       Zero-based offset into the full exportable list.
	 * @param int    $batch_size   Number of products to process per call.
	 * @param string $tax_rate_id  Clover tax rate ID to assign (empty = no tax assignment).
	 * @param bool   $skip_linked  Skip products that already have _clover_item_id.
	 *
	 * @return array Keys: total, next_offset, created, skipped, errors, done.
	 */
	public function bulk_export_batch( $offset, $batch_size, $tax_rate_id = '', $skip_linked = true ) {
		$offset     = max( 0, (int) $offset );
		$batch_size = max( 1, (int) $batch_size );

		// Build a combined sorted list of IDs on the first call and cache it.
		$all_ids = get_transient( 'clover_export_product_ids' );
		if ( ! is_array( $all_ids ) ) {
			$simple = wc_get_products( array(
				'status'  => 'publish',
				'type'    => 'simple',
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			) );

			$variations = wc_get_products( array(
				'status'  => 'publish',
				'type'    => 'variation',
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			) );

			$all_ids = array_values( array_unique( array_merge( $simple, $variations ) ) );
			sort( $all_ids );
			set_transient( 'clover_export_product_ids', $all_ids, HOUR_IN_SECONDS );
		}

		$total    = count( $all_ids );
		$batch    = array_slice( $all_ids, $offset, $batch_size );
		$created  = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $batch as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				$errors++;
				continue;
			}

			// Skip if already linked.
			if ( $skip_linked && ! empty( get_post_meta( $pid, '_clover_item_id', true ) ) ) {
				$skipped++;
				continue;
			}

			$result = $this->create_clover_item( $product, $tax_rate_id );
			if ( $result['success'] ) {
				$created++;
			} else {
				error_log( 'Clover export: failed for product ' . $pid . ' — ' . ( isset( $result['message'] ) ? $result['message'] : '' ) );
				$errors++;
			}
		}

		$next_offset = $offset + count( $batch );
		$done        = ( $next_offset >= $total ) || empty( $batch );

		if ( $done ) {
			delete_transient( 'clover_export_product_ids' );
		}

		return array(
			'total'       => $total,
			'processed'   => $next_offset,
			'next_offset' => $next_offset,
			'created'     => $created,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'done'        => $done,
		);
	}

	// ------------------------------------------------------------------
	// Auto-sync on product save (only exact / SKU matches)
	// ------------------------------------------------------------------

	public function on_product_save( $product_id ) {
		if ( get_post_meta( $product_id, '_clover_item_id', true ) ) {
			return;
		}
		if ( 'skipped' === get_post_meta( $product_id, '_clover_sync_status', true ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$clover_items = $this->get_all_clover_items();
		if ( empty( $clover_items ) ) {
			return;
		}

		$match = $this->match_product( $product, $clover_items );
		if ( 'auto_linked' === $match['status'] && ! empty( $match['clover_id'] ) ) {
			$this->link_product( $product_id, $match['clover_id'] );
		}
	}

	// ------------------------------------------------------------------
	// Apply all auto-links from a dry-run result set
	// ------------------------------------------------------------------

	public function apply_auto_links( $results = null ) {
		if ( null === $results ) {
			$results = get_transient( 'clover_sync_dry_run' );
		}
		if ( ! is_array( $results ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $results as $row ) {
			if ( 'auto_linked' === $row['status'] && ! empty( $row['clover_id'] ) && ! empty( $row['product_id'] ) ) {
				$this->link_product( $row['product_id'], $row['clover_id'] );
				$count++;
			}
		}
		return $count;
	}
}
