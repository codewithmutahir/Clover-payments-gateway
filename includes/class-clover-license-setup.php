<?php
/**
 * License setup screen, activation redirect, and restricted-area redirects.
 *
 * @package Clover_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for entering and managing the Clover Gateway license.
 */
class Clover_License_Setup {

	const MENU_SLUG = 'clover-gateway-license';

	/**
	 * Singleton instance.
	 *
	 * @var Clover_License_Setup|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Clover_License_Setup
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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 56 );
		add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_handle_post' ), 2 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_restricted_screens' ), 5 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Clover Gateway License', 'clover-gateway' ),
			__( 'Clover License', 'clover-gateway' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * One-time redirect to the license screen after plugin activation.
	 *
	 * @return void
	 */
	public function maybe_activation_redirect() {
		if ( ! get_transient( 'clover_gw_license_activation_redirect' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			delete_transient( 'clover_gw_license_activation_redirect' );
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Activation flow only.
		if ( isset( $_GET['activate-multi'] ) ) {
			delete_transient( 'clover_gw_license_activation_redirect' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && self::MENU_SLUG === $_GET['page'] ) {
			delete_transient( 'clover_gw_license_activation_redirect' );
			return;
		}

		delete_transient( 'clover_gw_license_activation_redirect' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Process activate / deactivate / verify actions.
	 *
	 * @return void
	 */
	public function maybe_handle_post() {
		if ( ! isset( $_POST['clover_license_action'] ) ) {
			return;
		}
		if ( ! isset( $_POST['clover_license_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clover_license_nonce'] ) ), 'clover_license_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $clover_license;
		if ( ! $clover_license instanceof Clover_License_Manager ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['clover_license_action'] ) );
		$args   = array( 'page' => self::MENU_SLUG );

		if ( 'activate' === $action ) {
			$key = isset( $_POST['clover_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['clover_license_key'] ) ) : '';
			$res = $clover_license->activate( $key );
			if ( ! empty( $res['success'] ) ) {
				$clover_license->verify();
				$args['clover_license_msg'] = 'activated';
				delete_option( 'clover_license_setup_pending' );
			} else {
				$args['clover_license_msg'] = 'activate_fail';
				$args['clover_license_err'] = rawurlencode( $res['message'] );
			}
		} elseif ( 'deactivate' === $action ) {
			$res = $clover_license->deactivate();
			if ( ! empty( $res['success'] ) ) {
				$args['clover_license_msg'] = 'deactivated';
			} else {
				$args['clover_license_msg'] = 'deactivate_fail';
				$args['clover_license_err'] = rawurlencode( $res['message'] );
			}
		} elseif ( 'verify' === $action ) {
			$ok = $clover_license->verify();
			$args['clover_license_msg'] = $ok ? 'verified_ok' : 'verified_fail';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Block direct access to Clover gateway settings or inventory tools without a valid license.
	 *
	 * @return void
	 */
	public function maybe_redirect_restricted_screens() {
		global $clover_license;
		if ( ! $clover_license instanceof Clover_License_Manager || $clover_license->is_active() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG === $page ) {
			return;
		}

		$target = '';

		if ( 'wc-settings' === $page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
			if ( 'clover_gateway' === $section && in_array( $tab, array( 'checkout', 'payment', 'payments' ), true ) ) {
				$target = self::license_url();
			}
		}

		if ( 'clover-inventory-sync' === $page ) {
			$target = self::license_url();
		}

		// WooCommerce Admin (React): block direct navigation to Clover payment settings without a license.
		if ( 'wc-admin' === $page && isset( $_GET['path'] ) ) {
			$wc_path = sanitize_text_field( wp_unslash( $_GET['path'] ) );
			if ( is_string( $wc_path ) && preg_match( '#/payments#i', $wc_path ) && stripos( $wc_path, 'clover' ) !== false ) {
				$target = self::license_url();
			}
		}

		if ( $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * License admin URL.
	 *
	 * @return string
	 */
	public static function license_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Remind store managers to activate when needed.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'woocommerce_page_' . self::MENU_SLUG === $screen->id ) {
			return;
		}

		global $clover_license;
		if ( ! $clover_license instanceof Clover_License_Manager || $clover_license->is_active() ) {
			return;
		}

		$url = esc_url( self::license_url() );
		echo '<div class="notice notice-warning"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: link to license page */
				__( '<strong>Clover Payment Gateway:</strong> Enter a valid license key to enable Clover payments and related features. <a href="%s">Open license setup</a>', 'clover-gateway' ),
				$url
			)
		);
		echo '</p></div>';
	}

	/**
	 * Render the license setup page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'clover-gateway' ) );
		}

		global $clover_license;
		$data   = $clover_license instanceof Clover_License_Manager ? $clover_license->get_license_data() : null;
		$active = $clover_license instanceof Clover_License_Manager && $clover_license->is_active();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['clover_license_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['clover_license_msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['clover_license_err'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) wp_unslash( $_GET['clover_license_err'] ) ) ) ) : '';

		?>
		<div class="wrap clover-license-setup">
			<h1><?php echo esc_html__( 'Clover Gateway — License', 'clover-gateway' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Activate your license to use Clover Payments, inventory sync, and gateway settings. Your site hostname is sent to the licensing server for activation. No API URL setup is required.', 'clover-gateway' ); ?>
			</p>

			<?php if ( $msg ) : ?>
				<?php
				switch ( $msg ) {
					case 'activated':
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'License activated successfully.', 'clover-gateway' ) . '</p></div>';
						break;
					case 'activate_fail':
						echo '<div class="notice notice-error"><p>' . esc_html( $err ? $err : __( 'Activation failed.', 'clover-gateway' ) ) . '</p></div>';
						break;
					case 'deactivated':
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'License deactivated on this site.', 'clover-gateway' ) . '</p></div>';
						break;
					case 'deactivate_fail':
						echo '<div class="notice notice-error"><p>' . esc_html( $err ? $err : __( 'Deactivation failed.', 'clover-gateway' ) ) . '</p></div>';
						break;
					case 'verified_ok':
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'License verified successfully.', 'clover-gateway' ) . '</p></div>';
						break;
					case 'verified_fail':
						echo '<div class="notice notice-error"><p>' . esc_html__( 'License verification failed. Check your key or contact support.', 'clover-gateway' ) . '</p></div>';
						break;
				}
				?>
			<?php endif; ?>

			<?php if ( $active ) : ?>
				<div class="card" style="max-width:640px;margin-top:1em;">
					<h2 style="margin-top:0;"><?php echo esc_html__( 'License active', 'clover-gateway' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'Plan', 'clover-gateway' ); ?></th>
							<td><?php echo isset( $data['plan'] ) ? esc_html( (string) $data['plan'] ) : '&mdash;'; ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Status', 'clover-gateway' ); ?></th>
							<td><?php echo isset( $data['status'] ) ? esc_html( (string) $data['status'] ) : '&mdash;'; ?></td>
						</tr>
					</table>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=clover_gateway' ) ); ?>">
							<?php echo esc_html__( 'Clover payment settings', 'clover-gateway' ); ?>
						</a>
					</p>
					<form method="post" style="margin-top:1em;padding-top:1em;border-top:1px solid #c3c4c7;">
						<?php wp_nonce_field( 'clover_license_save', 'clover_license_nonce' ); ?>
						<input type="hidden" name="clover_license_action" value="verify" />
						<?php submit_button( __( 'Verify license now', 'clover-gateway' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Deactivate this license on this site?', 'clover-gateway' ) ); ?>');">
						<?php wp_nonce_field( 'clover_license_save', 'clover_license_nonce' ); ?>
						<input type="hidden" name="clover_license_action" value="deactivate" />
						<?php submit_button( __( 'Deactivate license', 'clover-gateway' ), 'delete', 'submit', false, array( 'style' => 'margin-top:8px;' ) ); ?>
					</form>
				</div>
			<?php else : ?>
				<form method="post" class="card" style="max-width:640px;padding:1em 1.5em;margin-top:1em;">
					<h2 style="margin-top:0;"><?php echo esc_html__( 'Enter license key', 'clover-gateway' ); ?></h2>
					<?php wp_nonce_field( 'clover_license_save', 'clover_license_nonce' ); ?>
					<input type="hidden" name="clover_license_action" value="activate" />
					<p>
						<label for="clover_license_key"><strong><?php echo esc_html__( 'License key', 'clover-gateway' ); ?></strong></label><br />
						<input type="text" class="regular-text code" id="clover_license_key" name="clover_license_key" value="" autocomplete="off" style="width:100%;max-width:480px;" required />
					</p>
					<?php submit_button( __( 'Activate license', 'clover-gateway' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
