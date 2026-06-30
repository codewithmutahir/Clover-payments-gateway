<?php
/**
 * Plugin Name: Clover Payment Gateway for WooCommerce
 * Plugin URI: https://elitesolutionusa.com
 * Description: Accept credit card payments via Clover with full order item sync, tax reporting, and inventory sync.
 * Requires Plugins: woocommerce
 * Author: Elite Solution USA
 * Author URI: https://elitesolutionusa.com
 * Version: 1.0.2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: clover-gateway
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-clover-license-manager.php';

global $clover_license;
$clover_license = new Clover_License_Manager();

/**
 * Schedule weekly license verification on plugin activation.
 *
 * @return void
 */
function clover_gateway_on_activate() {
	if ( ! wp_next_scheduled( 'clover_license_weekly_verify' ) ) {
		wp_schedule_event( time(), 'weekly', 'clover_license_weekly_verify' );
	}
	add_option( 'clover_license_setup_pending', 'yes', '', 'no' );
	set_transient( 'clover_gw_license_activation_redirect', true, 30 );
}

/**
 * Clear scheduled license verification on deactivation.
 *
 * @return void
 */
function clover_gateway_on_deactivate() {
	wp_clear_scheduled_hook( 'clover_license_weekly_verify' );
}

register_activation_hook( __FILE__, 'clover_gateway_on_activate' );
register_deactivation_hook( __FILE__, 'clover_gateway_on_deactivate' );

/**
 * Weekly cron: verify license and track consecutive failures.
 *
 * @return void
 */
function clover_gateway_run_license_weekly_verify() {
	global $clover_license;
	if ( ! $clover_license instanceof Clover_License_Manager ) {
		return;
	}
	$clover_license->run_weekly_verify();
}

add_action( 'clover_license_weekly_verify', 'clover_gateway_run_license_weekly_verify' );

/**
 * Whether the Clover order debug panel is enabled (settings or WP_DEBUG).
 *
 * @return bool
 */
function clover_gateway_is_order_debug_enabled() {
	$settings = get_option( 'woocommerce_clover_gateway_settings', array() );
	if ( isset( $settings['order_debug'] ) && 'yes' === $settings['order_debug'] ) {
		return true;
	}
	return defined( 'WP_DEBUG' ) && WP_DEBUG;
}

/**
 * Main plugin bootstrap.
 */
class Clover_Gateway_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Clover_Gateway_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Plugin init.
	 *
	 * @return Clover_Gateway_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Clover_Gateway_Plugin constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialise plugin once plugins are loaded.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
			return;
		}

		$this->define_constants();
		$this->includes();
		$this->hooks();
	}

	/**
	 * Define plugin constants.
	 */
	protected function define_constants() {
		if ( ! defined( 'WC_CLOVER_GATEWAY_VERSION' ) ) {
			define( 'WC_CLOVER_GATEWAY_VERSION', '1.0.2' );
		}

		if ( ! defined( 'WC_CLOVER_GATEWAY_PLUGIN_FILE' ) ) {
			define( 'WC_CLOVER_GATEWAY_PLUGIN_FILE', __FILE__ );
		}

		if ( ! defined( 'WC_CLOVER_GATEWAY_PLUGIN_DIR' ) ) {
			define( 'WC_CLOVER_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		}

		if ( ! defined( 'WC_CLOVER_GATEWAY_PLUGIN_URL' ) ) {
			define( 'WC_CLOVER_GATEWAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		}
	}

	/**
	 * Include required files.
	 */
	protected function includes() {
		require_once WC_CLOVER_GATEWAY_PLUGIN_DIR . 'includes/class-clover-license-setup.php';
		require_once WC_CLOVER_GATEWAY_PLUGIN_DIR . 'includes/class-clover-api.php';
		require_once WC_CLOVER_GATEWAY_PLUGIN_DIR . 'includes/class-clover-order-sync.php';
		require_once WC_CLOVER_GATEWAY_PLUGIN_DIR . 'includes/class-wc-clover-gateway.php';
		require_once WC_CLOVER_GATEWAY_PLUGIN_DIR . 'includes/class-clover-admin.php';
		require_once WC_CLOVER_GATEWAY_PLUGIN_DIR . 'includes/class-clover-inventory-sync.php';
		require_once WC_CLOVER_GATEWAY_PLUGIN_DIR . 'includes/class-clover-inventory-admin.php';
	}

	/**
	 * Register hooks.
	 */
	protected function hooks() {
		Clover_License_Setup::instance();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );

		if ( ! $this->is_license_active() ) {
			return;
		}

		Clover_Order_Sync::instance();
		Clover_Inventory_Sync::instance();

		if ( is_admin() ) {
			Clover_Admin::instance();
			Clover_Inventory_Admin::instance();
		}
	}

	/**
	 * Whether the Clover license is valid for this site.
	 *
	 * @return bool
	 */
	protected function is_license_active() {
		global $clover_license;
		return $clover_license instanceof Clover_License_Manager && $clover_license->is_active();
	}

	/**
	 * Register the Clover gateway with WooCommerce.
	 *
	 * @param array $gateways Gateways.
	 *
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		if ( ! $this->is_license_active() ) {
			return $gateways;
		}

		$gateways[] = 'WC_Clover_Gateway';

		return $gateways;
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public function missing_wc_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo esc_html__(
					'Clover Payment Gateway for WooCommerce requires WooCommerce to be installed and active.',
					'clover-gateway'
				);
				?>
			</p>
		</div>
		<?php
	}
}

// Bootstrap plugin.
Clover_Gateway_Plugin::instance();

