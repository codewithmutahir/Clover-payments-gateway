<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clover Inventory Sync — core matching, linking, and creation logic.
 *
 * Safety: NEVER modifies or deletes existing Clover items.
 * Only reads Clover inventory for matching. Creates new items only on explicit request.
 * Stock import writes Clover quantities onto linked WooCommerce products only.
 */
class Clover_Inventory_Sync {

	protected static $instance = null;

	/** @var Clover_API|null */
	protected $api = null;

	/**
	 * True while this request is writing a Clover quantity onto a WooCommerce product.
	 * Later outbound stock hooks must skip these writes.
	 *
	 * @var bool
	 */
	protected static $applying_clover_stock = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_save' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( 'init', array( $this, 'sync_reconcile_schedule' ) );
		add_action( 'clover_gateway_reconcile_stock', array( $this, 'reconcile_stock_cron' ) );
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'on_reduce_order_stock' ) );
		add_action( 'woocommerce_restore_order_stock', array( $this, 'on_restore_order_stock' ) );
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
	 * Index Clover items by id, SKU, and normalized name for matching.
	 *
	 * @param array $clover_items Clover item records, or an index from this method.
	 * @return array{by_id:array,by_sku:array,by_name:array,items:array}
	 */
	public function build_clover_index( $clover_items ) {
		if ( is_array( $clover_items ) && isset( $clover_items['by_id'], $clover_items['items'] ) ) {
			return $clover_items;
		}

		$index = array(
			'by_id'   => array(),
			'by_sku'  => array(),
			'by_name' => array(),
			'items'   => array(),
		);
		if ( ! is_array( $clover_items ) ) {
			return $index;
		}

		foreach ( $clover_items as $ci ) {
			if ( ! is_array( $ci ) || empty( $ci['id'] ) ) {
				continue;
			}
			$index['items'][]           = $ci;
			$index['by_id'][ $ci['id'] ] = $ci;

			$sku = '';
			if ( ! empty( $ci['sku'] ) ) {
				$sku = $ci['sku'];
			} elseif ( ! empty( $ci['itemCode'] ) ) {
				$sku = $ci['itemCode'];
			}
			$sku = strtolower( trim( (string) $sku ) );
			if ( '' !== $sku ) {
				$index['by_sku'][ $sku ][] = $ci;
			}

			$name = self::normalize_name( isset( $ci['name'] ) ? $ci['name'] : '' );
			if ( '' !== $name ) {
				$index['by_name'][ $name ][] = $ci;
			}
			$alt = self::normalize_name( isset( $ci['alternateName'] ) ? $ci['alternateName'] : '' );
			if ( '' !== $alt && $alt !== $name ) {
				$index['by_name'][ $alt ][] = $ci;
			}
		}

		return $index;
	}

	/**
	 * Whether a normalized phrase appears as whole words inside another name.
	 *
	 * @param string $haystack Normalized name.
	 * @param string $needle   Normalized phrase.
	 * @return bool
	 */
	protected static function contains_phrase( $haystack, $needle ) {
		if ( '' === $needle || '' === $haystack ) {
			return false;
		}
		if ( $haystack === $needle ) {
			return true;
		}
		return (bool) preg_match( '/(?:^|\s)' . preg_quote( $needle, '/' ) . '(?:\s|$)/', $haystack );
	}

	/**
	 * Human-readable attribute values for a variation, such as "Large".
	 *
	 * @param WC_Product $product Variation.
	 * @return string[]
	 */
	protected function variation_attribute_labels( $product ) {
		$labels = array();
		$attrs  = $product->get_attributes();
		if ( ! is_array( $attrs ) ) {
			return $labels;
		}

		foreach ( $attrs as $key => $value ) {
			$label = '';
			if ( is_string( $key ) && method_exists( $product, 'get_attribute' ) ) {
				$label = (string) $product->get_attribute( $key );
			}
			if ( '' === trim( $label ) && is_string( $value ) ) {
				$label    = $value;
				$taxonomy = $key;
				if ( is_string( $key ) && 0 !== strpos( $key, 'pa_' ) && taxonomy_exists( 'pa_' . $key ) ) {
					$taxonomy = 'pa_' . $key;
				}
				if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) ) {
					$term = get_term_by( 'slug', $value, $taxonomy );
					if ( $term && ! is_wp_error( $term ) && ! empty( $term->name ) ) {
						$label = $term->name;
					}
				}
			}
			$label = trim( wp_strip_all_tags( (string) $label ) );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Name shown in the match table.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	protected function match_row_name( $product ) {
		if ( ! $product->is_type( 'variation' ) ) {
			return $product->get_name();
		}

		$parent      = wc_get_product( $product->get_parent_id() );
		$parent_name = $parent ? $parent->get_name() : $product->get_name();
		$labels      = $this->variation_attribute_labels( $product );
		if ( $labels ) {
			return $parent_name . ' - ' . implode( ', ', $labels );
		}

		return $parent_name . ' - #' . $product->get_id();
	}

	/**
	 * Names that may correspond to one Clover item.
	 *
	 * A variation is never matched on the parent name alone, so "Large" and
	 * "Small" cannot both claim the parent item.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string[]
	 */
	protected function match_name_candidates( $product ) {
		if ( ! $product->is_type( 'variation' ) ) {
			return array( $product->get_name() );
		}

		$parent      = wc_get_product( $product->get_parent_id() );
		$parent_name = $parent ? $parent->get_name() : '';
		$labels      = $this->variation_attribute_labels( $product );
		$own_name    = $product->get_name();

		if ( '' === $parent_name || empty( $labels ) ) {
			if ( '' !== $parent_name && self::normalize_name( $own_name ) === self::normalize_name( $parent_name ) ) {
				return array();
			}
			return '' !== $own_name ? array( $own_name ) : array();
		}

		$joined = implode( ' ', $labels );
		return array(
			$parent_name . ' ' . $joined,
			$joined . ' ' . $parent_name,
			$own_name,
		);
	}

	/**
	 * Clover items whose names contain the parent and this variation's attributes.
	 *
	 * @param string $parent_name Parent product name.
	 * @param array  $labels      Variation attribute labels.
	 * @param array  $items       Clover items.
	 * @return array
	 */
	protected function variation_fuzzy_candidates( $parent_name, $labels, $items ) {
		$value_norms = array();
		foreach ( $labels as $label ) {
			$norm = self::normalize_name( $label );
			if ( '' !== $norm ) {
				$value_norms[] = $norm;
			}
		}
		if ( empty( $value_norms ) ) {
			return array();
		}

		$parent_norm = self::normalize_name( $parent_name );
		$tokens      = array();
		if ( '' !== $parent_norm ) {
			foreach ( explode( ' ', $parent_norm ) as $token ) {
				if ( strlen( $token ) >= 3 ) {
					$tokens[] = $token;
				}
			}
		}

		$candidates = array();
		foreach ( $items as $ci ) {
			if ( ! is_array( $ci ) ) {
				continue;
			}
			$ci_norm = self::normalize_name( isset( $ci['name'] ) ? $ci['name'] : '' );
			if ( '' === $ci_norm ) {
				continue;
			}

			$has_values = true;
			foreach ( $value_norms as $value_norm ) {
				if ( ! self::contains_phrase( $ci_norm, $value_norm ) ) {
					$has_values = false;
					break;
				}
			}
			if ( ! $has_values ) {
				continue;
			}

			$has_parent = false;
			if ( '' !== $parent_norm && false !== strpos( $ci_norm, $parent_norm ) ) {
				$has_parent = true;
			} elseif ( $tokens ) {
				foreach ( $tokens as $token ) {
					if ( self::contains_phrase( $ci_norm, $token ) ) {
						$has_parent = true;
						break;
					}
				}
			} else {
				$has_parent = true;
			}

			if ( $has_parent ) {
				$candidates[] = $ci;
			}
		}

		return $candidates;
	}

	/**
	 * @param string     $status Match status.
	 * @param array|null $item   Clover item or null.
	 * @param float|int  $score  Similarity score.
	 * @param string     $note   Extra text appended to the Clover name.
	 * @return array
	 */
	protected function match_result( $status, $item, $score, $note = '' ) {
		$name = '';
		$id   = '';
		if ( is_array( $item ) ) {
			$id   = isset( $item['id'] ) ? $item['id'] : '';
			$name = isset( $item['name'] ) ? $item['name'] : '';
		}
		if ( '' !== $note ) {
			$name .= $note;
		}
		return array(
			'status'      => $status,
			'clover_id'   => $id,
			'clover_name' => $name,
			'score'       => $score,
		);
	}

	/**
	 * @param WC_Product $product
	 * @param array      $clover_items Pre-fetched list or an index from build_clover_index().
	 * @return array     Keys: status, clover_id, clover_name, score
	 */
	public function match_product( $product, $clover_items ) {
		$index = $this->build_clover_index( $clover_items );

		if ( $product->is_type( 'variable' ) && $product->has_child() ) {
			$existing = get_post_meta( $product->get_id(), '_clover_item_id', true );
			$existing = is_string( $existing ) ? trim( $existing ) : '';
			$item     = ( '' !== $existing && isset( $index['by_id'][ $existing ] ) ) ? $index['by_id'][ $existing ] : null;
			$result   = $this->match_result( 'parent_only', $item, $existing !== '' ? 100 : 0 );
			if ( '' === $result['clover_name'] ) {
				$result['clover_name'] = __( 'Link each variation', 'clover-gateway' );
				$result['clover_id']   = $existing;
			}
			return $result;
		}

		$existing = get_post_meta( $product->get_id(), '_clover_item_id', true );
		$existing = is_string( $existing ) ? trim( $existing ) : '';
		if ( '' !== $existing ) {
			$item = isset( $index['by_id'][ $existing ] ) ? $index['by_id'][ $existing ] : array(
				'id'   => $existing,
				'name' => '',
			);
			return $this->match_result( 'already_linked', $item, 100 );
		}

		if ( 'skipped' === get_post_meta( $product->get_id(), '_clover_sync_status', true ) ) {
			return $this->match_result( 'skipped', null, 0 );
		}

		$sku = strtolower( trim( (string) $product->get_sku() ) );
		if ( '' !== $sku && ! empty( $index['by_sku'][ $sku ] ) ) {
			$matches = $index['by_sku'][ $sku ];
			$note    = count( $matches ) > 1 ? ' (+' . ( count( $matches ) - 1 ) . ' other items with this SKU)' : '';
			$status  = 1 === count( $matches ) ? 'auto_linked' : 'needs_review';
			return $this->match_result( $status, $matches[0], 100, $note );
		}

		$found = array();
		foreach ( $this->match_name_candidates( $product ) as $candidate ) {
			$norm = self::normalize_name( $candidate );
			if ( '' === $norm || empty( $index['by_name'][ $norm ] ) ) {
				continue;
			}
			foreach ( $index['by_name'][ $norm ] as $ci ) {
				if ( ! empty( $ci['id'] ) ) {
					$found[ $ci['id'] ] = $ci;
				}
			}
		}
		if ( 1 === count( $found ) ) {
			return $this->match_result( 'auto_linked', reset( $found ), 100 );
		}
		if ( count( $found ) > 1 ) {
			$note = ' (+' . ( count( $found ) - 1 ) . ' other items with this name)';
			return $this->match_result( 'needs_review', reset( $found ), 100, $note );
		}

		$compare_name = $product->get_name();
		$pool         = $index['items'];
		if ( $product->is_type( 'variation' ) ) {
			$parent       = wc_get_product( $product->get_parent_id() );
			$parent_name  = $parent ? $parent->get_name() : '';
			$labels       = $this->variation_attribute_labels( $product );
			$pool         = $this->variation_fuzzy_candidates( $parent_name, $labels, $index['items'] );
			$compare_name = ( '' !== $parent_name && $labels ) ? $parent_name . ' ' . implode( ' ', $labels ) : $compare_name;
			if ( empty( $pool ) ) {
				$shared = $this->match_shared_parent_item( $parent_name, $index );
				if ( null !== $shared ) {
					return $shared;
				}
				return $this->match_result( 'no_match', null, 0 );
			}
		}

		$best_score = 0;
		$best_item  = null;
		foreach ( $pool as $ci ) {
			$ci_name = isset( $ci['name'] ) ? $ci['name'] : '';
			$score   = self::similarity_score( $compare_name, $ci_name );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_item  = $ci;
			}
		}

		if ( $best_score >= 90 && $best_item ) {
			return $this->match_result( 'needs_review', $best_item, $best_score );
		}
		if ( $best_score >= 70 && $best_item ) {
			return $this->match_result( 'possible_match', $best_item, $best_score );
		}

		return $this->match_result( 'no_match', null, $best_score );
	}

	/**
	 * Link a variation to the one Clover item named like the parent dish.
	 *
	 * Full Tray and Half tray then share that item. A size-specific Clover
	 * item is preferred earlier, when its name includes the attribute.
	 *
	 * @param string $parent_name Parent product name.
	 * @param array  $index       Clover index.
	 * @return array|null
	 */
	protected function match_shared_parent_item( $parent_name, $index ) {
		$norm = self::normalize_name( $parent_name );
		if ( '' === $norm || empty( $index['by_name'][ $norm ] ) ) {
			return null;
		}

		$matches = array();
		foreach ( $index['by_name'][ $norm ] as $ci ) {
			if ( ! empty( $ci['id'] ) ) {
				$matches[ $ci['id'] ] = $ci;
			}
		}
		if ( 1 === count( $matches ) ) {
			return $this->match_result( 'auto_linked', reset( $matches ), 100 );
		}
		if ( count( $matches ) > 1 ) {
			$note = ' (+' . ( count( $matches ) - 1 ) . ' other items with this dish name)';
			return $this->match_result( 'needs_review', reset( $matches ), 100, $note );
		}

		return null;
	}

	/**
	 * Owner key for a Clover item claim.
	 *
	 * Variations of one parent share an owner, so Full Tray and Half tray may
	 * use the same Clover item. Two different products may not.
	 *
	 * @param array $row Dry-run row.
	 * @return string
	 */
	protected function auto_link_owner_key( $row ) {
		if ( isset( $row['product_type'] ) && 'variation' === $row['product_type'] ) {
			$parent = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : 0;
			return 'variation:' . $parent;
		}
		$id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		return 'product:' . $id;
	}

	/**
	 * Hold an automatic link when two different products claim one Clover item.
	 *
	 * @param array $results Dry-run rows.
	 * @return array
	 */
	protected function reserve_unique_auto_links( $results ) {
		$owners = array();
		foreach ( $results as $row ) {
			if ( empty( $row['clover_id'] ) || 'already_linked' !== $row['status'] ) {
				continue;
			}
			$owners[ $row['clover_id'] ][ $this->auto_link_owner_key( $row ) ] = true;
		}

		$auto = array();
		foreach ( $results as $i => $row ) {
			if ( 'auto_linked' === $row['status'] && ! empty( $row['clover_id'] ) ) {
				$auto[ $row['clover_id'] ][] = $i;
			}
		}

		foreach ( $auto as $clover_id => $indexes ) {
			$groups = isset( $owners[ $clover_id ] ) ? $owners[ $clover_id ] : array();
			foreach ( $indexes as $i ) {
				$groups[ $this->auto_link_owner_key( $results[ $i ] ) ] = true;
			}
			if ( count( $groups ) > 1 ) {
				foreach ( $indexes as $i ) {
					$results[ $i ]['status'] = 'needs_review';
				}
			}
		}

		return $results;
	}

	// ------------------------------------------------------------------
	// Dry-run sync — returns results without touching anything
	// ------------------------------------------------------------------

	public function dry_run_sync() {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}

		$index = $this->build_clover_index( $this->get_all_clover_items() );

		$parents = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		if ( ! is_array( $parents ) ) {
			$parents = array();
		}

		$by_parent  = array();
		$variations = wc_get_products(
			array(
				'status'  => array( 'publish', 'private' ),
				'type'    => 'variation',
				'limit'   => -1,
				'orderby' => 'menu_order',
				'order'   => 'ASC',
			)
		);
		if ( is_array( $variations ) ) {
			foreach ( $variations as $variation ) {
				$by_parent[ $variation->get_parent_id() ][] = $variation;
			}
		}

		$ordered = array();
		foreach ( $parents as $product ) {
			$ordered[] = $product;
			if ( $product->is_type( 'variable' ) && ! empty( $by_parent[ $product->get_id() ] ) ) {
				foreach ( $by_parent[ $product->get_id() ] as $variation ) {
					$ordered[] = $variation;
				}
			}
		}

		$results = array();
		foreach ( $ordered as $product ) {
			$match     = $this->match_product( $product, $index );
			$results[] = array(
				'product_id'   => $product->get_id(),
				'parent_id'    => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0,
				'product_name' => $this->match_row_name( $product ),
				'product_type' => $product->get_type(),
				'sku'          => $product->get_sku(),
				'status'       => $match['status'],
				'clover_id'    => $match['clover_id'],
				'clover_name'  => $match['clover_name'],
				'score'        => $match['score'],
			);
		}

		$results = $this->reserve_unique_auto_links( $results );
		set_transient( 'clover_sync_dry_run', $results, HOUR_IN_SECONDS );
		return $results;
	}

	// ------------------------------------------------------------------
	// Link / unlink
	// ------------------------------------------------------------------

	public function link_product( $woo_product_id, $clover_item_id ) {
		$woo_product_id = (int) $woo_product_id;
		$clover_item_id = sanitize_text_field( (string) $clover_item_id );
		if ( ! $woo_product_id || '' === $clover_item_id ) {
			return false;
		}

		update_post_meta( $woo_product_id, '_clover_item_id', $clover_item_id );
		update_post_meta( $woo_product_id, '_clover_item_synced_at', time() );
		update_post_meta( $woo_product_id, '_clover_sync_status', 'linked' );
		$this->flush_product_meta_cache( $woo_product_id );

		$stored = get_post_meta( $woo_product_id, '_clover_item_id', true );
		if ( (string) $stored !== $clover_item_id ) {
			return false;
		}

		$this->update_dry_run_row(
			$woo_product_id,
			array(
				'status'    => 'already_linked',
				'clover_id' => $clover_item_id,
				'score'     => 100,
			)
		);
		return true;
	}

	public function skip_product( $woo_product_id ) {
		$woo_product_id = (int) $woo_product_id;
		if ( ! $woo_product_id ) {
			return false;
		}

		update_post_meta( $woo_product_id, '_clover_sync_status', 'skipped' );
		$this->flush_product_meta_cache( $woo_product_id );
		$this->update_dry_run_row(
			$woo_product_id,
			array(
				'status' => 'skipped',
			)
		);
		return true;
	}

	/**
	 * Drop the cached product so the next read sees meta just written.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	protected function flush_product_meta_cache( $product_id ) {
		wp_cache_delete( $product_id, 'post_meta' );
		clean_post_cache( $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Update one saved match row so a reload does not show the old suggestion.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $changes    Row fields to replace.
	 */
	protected function update_dry_run_row( $product_id, $changes ) {
		$results = get_transient( 'clover_sync_dry_run' );
		if ( ! is_array( $results ) ) {
			return;
		}

		$changed = false;
		foreach ( $results as $i => $row ) {
			if ( ! is_array( $row ) || (int) $row['product_id'] !== (int) $product_id ) {
				continue;
			}
			$results[ $i ] = array_merge( $row, $changes );
			$changed       = true;
			break;
		}

		if ( $changed ) {
			set_transient( 'clover_sync_dry_run', $results, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Apply saved links and skips onto a cached match list.
	 *
	 * The list is a snapshot from Run Dry Sync. Linking updated the product,
	 * but a reload was still rendering that old snapshot.
	 *
	 * @param array $results Dry-run rows.
	 * @return array
	 */
	public function reconcile_dry_run_results( $results ) {
		if ( ! is_array( $results ) ) {
			return array();
		}

		foreach ( $results as $i => $row ) {
			if ( is_array( $row ) ) {
				$results[ $i ] = $this->reconcile_dry_run_row( $row );
			}
		}

		return $results;
	}

	/**
	 * @param array $row Dry-run row.
	 * @return array
	 */
	protected function reconcile_dry_run_row( $row ) {
		$product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		$status     = isset( $row['status'] ) ? $row['status'] : '';
		$type       = isset( $row['product_type'] ) ? $row['product_type'] : '';
		if ( ! $product_id || 'parent_only' === $status || 'variable' === $type ) {
			return $row;
		}

		$saved = get_post_meta( $product_id, '_clover_item_id', true );
		$saved = is_string( $saved ) ? trim( $saved ) : '';
		if ( '' !== $saved ) {
			if ( ! isset( $row['clover_id'] ) || (string) $row['clover_id'] !== $saved ) {
				$row['clover_name'] = $saved;
			}
			$row['clover_id'] = $saved;
			$row['status']    = 'already_linked';
			$row['score']     = 100;
			return $row;
		}

		if ( 'skipped' === get_post_meta( $product_id, '_clover_sync_status', true ) ) {
			$row['status'] = 'skipped';
		}

		return $row;
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
				if ( $this->link_product( $row['product_id'], $row['clover_id'] ) ) {
					$count++;
				}
			}
		}
		return $count;
	}

	// ------------------------------------------------------------------
	// Initial stock import: Clover quantity → linked WooCommerce products
	// ------------------------------------------------------------------

	/**
	 * Whether the current request is copying a Clover quantity into WooCommerce.
	 *
	 * @return bool
	 */
	public static function is_applying_clover_stock() {
		return self::$applying_clover_stock;
	}

	/**
	 * Product and variation IDs that already have a Clover item ID.
	 *
	 * Variable parents are included so the import can report them. Their stock
	 * lives on each variation, so the parent quantity is not changed.
	 *
	 * @return int[]
	 */
	public function get_linked_product_ids() {
		$types = array( 'simple', 'variation', 'variable' );
		$ids   = array();

		foreach ( $types as $type ) {
			$found = wc_get_products(
				array(
					'status'  => array( 'publish', 'private', 'draft', 'pending' ),
					'type'    => $type,
					'limit'   => -1,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			if ( is_array( $found ) ) {
				$ids = array_merge( $ids, $found );
			}
		}

		$linked = array();
		foreach ( $ids as $id ) {
			$clover_id = get_post_meta( $id, '_clover_item_id', true );
			if ( is_string( $clover_id ) && '' !== trim( $clover_id ) ) {
				$linked[] = (int) $id;
			}
		}

		$linked = array_values( array_unique( $linked ) );
		sort( $linked, SORT_NUMERIC );
		return $linked;
	}

	/**
	 * Read Clover stock and cache it with the linked product IDs for this import.
	 *
	 * @return array
	 */
	protected function prepare_stock_import() {
		$ids = $this->get_linked_product_ids();
		if ( empty( $ids ) ) {
			return array(
				'success'        => true,
				'empty'          => true,
				'token'          => '',
				'total'          => 0,
				'processed'      => 0,
				'next_offset'    => 0,
				'updated'        => 0,
				'unchanged'      => 0,
				'untracked'      => 0,
				'skipped_parent' => 0,
				'errors'         => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$api = $this->get_api();
		if ( ! $api ) {
			return array(
				'success' => false,
				'message' => __( 'Clover API is not configured. Check the gateway settings.', 'clover-gateway' ),
			);
		}

		$fetched = $api->get_all_item_stocks();
		if ( empty( $fetched['success'] ) ) {
			return array(
				'success' => false,
				'message' => isset( $fetched['message'] ) ? $fetched['message'] : __( 'Could not read Clover item stock.', 'clover-gateway' ),
			);
		}

		try {
			$token = bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			$token = wp_generate_password( 16, false, false );
		}

		set_transient(
			$this->stock_import_transient_key( $token ),
			array(
				'ids'    => $ids,
				'stocks' => isset( $fetched['stocks'] ) && is_array( $fetched['stocks'] ) ? $fetched['stocks'] : array(),
			),
			15 * MINUTE_IN_SECONDS
		);

		return array(
			'success' => true,
			'token'   => $token,
		);
	}

	/**
	 * Transient key for one stock-import run.
	 *
	 * @param string $token Import token.
	 * @return string
	 */
	protected function stock_import_transient_key( $token ) {
		return 'clover_stock_import_' . $token;
	}

	/**
	 * Copy Clover quantities onto one batch of linked WooCommerce products.
	 *
	 * @param int    $offset     Zero-based offset into the linked product list.
	 * @param int    $batch_size Products to update in this request.
	 * @param string $token      Token returned by the first batch. Empty starts a new import.
	 * @return array
	 */
	public function import_stock_batch( $offset, $batch_size, $token = '' ) {
		$offset     = max( 0, (int) $offset );
		$batch_size = max( 1, min( 50, (int) $batch_size ) );
		$token      = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );

		if ( 0 === $offset || '' === $token ) {
			$prepared = $this->prepare_stock_import();
			if ( empty( $prepared['success'] ) || ! empty( $prepared['done'] ) ) {
				return $prepared;
			}
			$token = $prepared['token'];
		}

		$state = get_transient( $this->stock_import_transient_key( $token ) );
		if ( ! is_array( $state ) || ! isset( $state['ids'], $state['stocks'] ) || ! is_array( $state['ids'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'This stock import expired. Start it again.', 'clover-gateway' ),
			);
		}

		$total   = count( $state['ids'] );
		$batch   = array_slice( $state['ids'], $offset, $batch_size );
		$counts  = array(
			'updated'        => 0,
			'unchanged'      => 0,
			'untracked'      => 0,
			'skipped_parent' => 0,
			'errors'         => 0,
		);
		$messages = array();

		foreach ( $batch as $product_id ) {
			$result = $this->import_stock_for_product( (int) $product_id, $state['stocks'] );
			$status = isset( $result['status'] ) ? $result['status'] : 'error';
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			} else {
				$counts['errors']++;
			}
			if ( ! empty( $result['message'] ) && count( $messages ) < 8 ) {
				$messages[] = (string) $result['message'];
			}
		}

		$processed = $offset + count( $batch );
		$done      = ( $processed >= $total ) || empty( $batch );
		if ( $done ) {
			delete_transient( $this->stock_import_transient_key( $token ) );
		}

		return array(
			'success'        => true,
			'token'          => $token,
			'total'          => $total,
			'processed'      => $processed,
			'next_offset'    => $processed,
			'updated'        => $counts['updated'],
			'unchanged'      => $counts['unchanged'],
			'untracked'      => $counts['untracked'],
			'skipped_parent' => $counts['skipped_parent'],
			'errors'         => $counts['errors'],
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Apply one linked product's Clover quantity.
	 *
	 * @param int   $product_id WooCommerce product or variation ID.
	 * @param array $stocks     Map of Clover item ID => quantity.
	 * @return array{status:string,message?:string}
	 */
	protected function import_stock_for_product( $product_id, $stocks ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'status'  => 'errors',
				'message' => sprintf(
					/* translators: %d: WooCommerce product ID */
					__( 'Product %d was not found.', 'clover-gateway' ),
					$product_id
				),
			);
		}

		if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return array( 'status' => 'skipped_parent' );
		}

		$clover_id = get_post_meta( $product_id, '_clover_item_id', true );
		$clover_id = is_string( $clover_id ) ? trim( $clover_id ) : '';
		if ( '' === $clover_id || ! array_key_exists( $clover_id, $stocks ) ) {
			return array( 'status' => 'untracked' );
		}

		try {
			$status = $this->apply_clover_quantity( $product, $stocks[ $clover_id ] );
			return array( 'status' => $status );
		} catch ( Throwable $e ) {
			return array(
				'status'  => 'errors',
				'message' => $product->get_name() . ': ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Set a WooCommerce product's stock from a Clover quantity.
	 *
	 * Enables stock management when it was off. Leaves name, price, description,
	 * images, and URL untouched. Skips the write when the quantity already matches.
	 *
	 * @param WC_Product $product  Linked product or variation.
	 * @param int|float  $quantity Clover quantity.
	 * @return string updated|unchanged
	 */
	public function apply_clover_quantity( $product, $quantity ) {
		$target   = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $quantity ) : (float) $quantity;
		$current  = $product->get_stock_quantity();
		$expected = $this->stock_status_for_quantity( $product, $target );
		$same     = $product->managing_stock()
			&& null !== $current
			&& (float) ( function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $current ) : $current ) === (float) $target
			&& $product->get_stock_status() === $expected;

		if ( $same ) {
			return 'unchanged';
		}

		self::$applying_clover_stock = true;
		try {
			// Set quantity on the product object and save once. WooCommerce's
			// set_stock() writes straight to the database and no-ops unless stock
			// management is already enabled there, so a product that was not
			// tracking stock would keep its old quantity.
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $target );
			$product->set_stock_status( $this->stock_status_for_quantity( $product, $target ) );
			$product->update_meta_data( '_clover_stock_quantity', $target );
			$product->update_meta_data( '_clover_stock_synced_at', time() );
			$product->save();

			do_action( $product->is_type( 'variation' ) ? 'woocommerce_variation_set_stock' : 'woocommerce_product_set_stock', $product );
		} finally {
			self::$applying_clover_stock = false;
		}

		return 'updated';
	}

	/**
	 * Stock status that matches WooCommerce's no-stock threshold and backorders.
	 *
	 * @param WC_Product $product  Product being updated.
	 * @param int|float  $quantity Quantity about to be saved.
	 * @return string instock|outofstock|onbackorder
	 */
	protected function stock_status_for_quantity( $product, $quantity ) {
		$threshold = (float) get_option( 'woocommerce_notify_no_stock_amount', 0 );
		if ( (float) $quantity > $threshold ) {
			return 'instock';
		}
		if ( $product->backorders_allowed() ) {
			return 'onbackorder';
		}
		return 'outofstock';
	}

	/**
	 * Fifteen-minute cron interval for stock reconciliation.
	 *
	 * @param array $schedules Existing intervals.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['clover_fifteen_minutes'] ) ) {
			$schedules['clover_fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes', 'clover-gateway' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule or clear the Clover stock check.
	 */
	public function sync_reconcile_schedule() {
		$hook    = 'clover_gateway_reconcile_stock';
		$enabled = ( 'yes' === get_option( 'clover_stock_reconcile_enabled', 'no' ) );

		if ( ! $enabled ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'clover-gateway' );
			}
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( $hook, array(), 'clover-gateway' ) ) {
				as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, $hook, array(), 'clover-gateway' );
			}
			wp_clear_scheduled_hook( $hook );
			return;
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'clover_fifteen_minutes', $hook );
		}
	}

	/**
	 * Copy current Clover quantities onto linked WooCommerce products.
	 *
	 * Variations that share a Clover item receive that same quantity.
	 */
	public function reconcile_stock_cron() {
		if ( 'yes' !== get_option( 'clover_stock_reconcile_enabled', 'no' ) ) {
			return;
		}
		if ( get_transient( 'clover_stock_reconcile_lock' ) ) {
			return;
		}
		set_transient( 'clover_stock_reconcile_lock', 1, 5 * MINUTE_IN_SECONDS );

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}

		$started = time();
		$offset  = 0;
		$token   = '';
		$totals  = array(
			'updated'        => 0,
			'unchanged'      => 0,
			'untracked'      => 0,
			'skipped_parent' => 0,
			'errors'         => 0,
		);
		$finished = false;

		do {
			$result = $this->import_stock_batch( $offset, 40, $token );
			if ( empty( $result['success'] ) ) {
				$totals['errors']++;
				$finished = true;
				break;
			}
			foreach ( $totals as $key => $value ) {
				if ( isset( $result[ $key ] ) ) {
					$totals[ $key ] += (int) $result[ $key ];
				}
			}
			if ( ! empty( $result['empty'] ) || ! empty( $result['done'] ) ) {
				$finished = true;
				break;
			}
			$offset = isset( $result['next_offset'] ) ? (int) $result['next_offset'] : 0;
			$token  = isset( $result['token'] ) ? (string) $result['token'] : '';
		} while ( '' !== $token && ( time() - $started ) < 50 );

		update_option(
			'clover_stock_reconcile_last',
			array(
				'time'     => time(),
				'finished' => $finished,
				'counts'   => $totals,
			),
			false
		);
		delete_transient( 'clover_stock_reconcile_lock' );
	}

	/**
	 * A paid website order lowered WooCommerce stock. Lower the linked Clover item by the same amount.
	 *
	 * @param WC_Order|int $order Order.
	 */
	public function on_reduce_order_stock( $order ) {
		$this->sync_order_stock_to_clover( $order, 'reduce' );
	}

	/**
	 * WooCommerce restored stock for a cancelled or refunded order. Put that quantity back in Clover.
	 *
	 * @param WC_Order|int $order Order.
	 */
	public function on_restore_order_stock( $order ) {
		$this->sync_order_stock_to_clover( $order, 'restore' );
	}

	/**
	 * Push an order's stock change to Clover.
	 *
	 * @param WC_Order|int $order     Order.
	 * @param string       $direction reduce|restore.
	 */
	protected function sync_order_stock_to_clover( $order, $direction ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$notes = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$note = $this->sync_order_line_stock_to_clover( $item, $direction );
			if ( '' !== $note ) {
				$notes[] = $note;
			}
		}

		if ( $notes ) {
			$order->add_order_note( implode( "\n", $notes ) );
		}
	}

	/**
	 * Apply one line's quantity change to its Clover item.
	 *
	 * @param WC_Order_Item_Product $item      Order line.
	 * @param string                $direction reduce|restore.
	 * @return string Order-note text, or empty when there is nothing to say.
	 */
	protected function sync_order_line_stock_to_clover( $item, $direction ) {
		$name      = $item->get_name();
		$clover_id = $this->clover_item_id_for_order_item( $item );
		if ( '' === $clover_id ) {
			return sprintf(
				/* translators: %s: product name */
				__( '%s is not linked to a Clover item, so Clover stock was not changed.', 'clover-gateway' ),
				$name
			);
		}

		$qty = (int) $item->get_quantity();
		if ( $qty < 1 ) {
			return '';
		}

		$already = (int) $item->get_meta( '_clover_stock_adjusted', true );
		$target  = ( 'reduce' === $direction ) ? ( 0 - $qty ) : 0;
		$delta   = $target - $already;
		if ( 0 === $delta ) {
			return '';
		}

		$result = $this->apply_clover_stock_delta( $clover_id, $delta );
		if ( empty( $result['success'] ) ) {
			return sprintf(
				/* translators: 1: product name, 2: error message */
				__( '%1$s: Clover stock was not changed. %2$s', 'clover-gateway' ),
				$name,
				isset( $result['message'] ) ? $result['message'] : __( 'Clover rejected the stock update.', 'clover-gateway' )
			);
		}

		$item->update_meta_data( '_clover_stock_adjusted', $target );
		$item->save();

		return sprintf(
			/* translators: 1: product name, 2: decreased or increased, 3: quantity, 4: new Clover quantity */
			__( 'Clover stock for %1$s %2$s by %3$d. Clover quantity is now %4$s.', 'clover-gateway' ),
			$name,
			$delta < 0 ? __( 'decreased', 'clover-gateway' ) : __( 'increased', 'clover-gateway' ),
			abs( $delta ),
			(string) $result['quantity']
		);
	}

	/**
	 * Clover item linked to an order line. Variations prefer their own link, then the parent.
	 *
	 * @param WC_Order_Item_Product $item Order line.
	 * @return string
	 */
	protected function clover_item_id_for_order_item( $item ) {
		$variation_id = (int) $item->get_variation_id();
		$product_id   = (int) $item->get_product_id();
		$lookup_id    = $variation_id ? $variation_id : $product_id;
		$clover_id    = $lookup_id ? get_post_meta( $lookup_id, '_clover_item_id', true ) : '';
		$clover_id    = is_string( $clover_id ) ? trim( $clover_id ) : '';

		if ( '' === $clover_id && $variation_id && $product_id ) {
			$parent = get_post_meta( $product_id, '_clover_item_id', true );
			$clover_id = is_string( $parent ) ? trim( $parent ) : '';
		}

		return $clover_id;
	}

	/**
	 * Add a delta to the current Clover quantity.
	 *
	 * Reads Clover first so a website sale does not overwrite a newer register count
	 * with WooCommerce's own number.
	 *
	 * @param string $clover_id Clover item ID.
	 * @param int    $delta     Quantity to add. Negative lowers stock.
	 * @return array{success:bool,quantity?:int|float,message?:string}
	 */
	protected function apply_clover_stock_delta( $clover_id, $delta ) {
		$api = $this->get_api();
		if ( ! $api ) {
			return array(
				'success' => false,
				'message' => __( 'Clover API is not configured.', 'clover-gateway' ),
			);
		}

		$lock  = 'clover_item_stock_lock_' . md5( $clover_id );
		$spins = 0;
		while ( get_transient( $lock ) && $spins < 10 ) {
			usleep( 200000 );
			$spins++;
		}
		set_transient( $lock, 1, 20 );

		try {
			$current = $api->get_item_stock_quantity( $clover_id );
			if ( empty( $current['success'] ) ) {
				return array(
					'success' => false,
					'message' => isset( $current['message'] ) ? $current['message'] : __( 'Could not read Clover stock.', 'clover-gateway' ),
				);
			}
			if ( empty( $current['tracked'] ) || ! isset( $current['quantity'] ) || ! is_numeric( $current['quantity'] ) ) {
				return array(
					'success' => false,
					'message' => __( 'Clover is not tracking stock for this item (the stock column is blank). Turn on stock tracking and set the current count.', 'clover-gateway' ),
				);
			}

			$new     = $current['quantity'] + $delta;
			$updated = $api->set_item_stock_quantity( $clover_id, $new );
			if ( empty( $updated['success'] ) ) {
				return array(
					'success' => false,
					'message' => ! empty( $updated['message'] ) ? (string) $updated['message'] : __( 'Clover rejected the stock update.', 'clover-gateway' ),
				);
			}

			return array(
				'success'  => true,
				'quantity' => $new,
			);
		} finally {
			delete_transient( $lock );
		}
	}
}
