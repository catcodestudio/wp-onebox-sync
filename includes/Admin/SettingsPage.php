<?php
/**
 * Settings page under the WooCommerce menu — native WP admin components
 * (.form-table, .nav-tab-wrapper, submit_button). Two tabs: settings + log.
 *
 * Secrets are never echoed back; an empty submit never wipes a stored key.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync\Admin;

use CatCode\OneboxSync\Api\Client;
use CatCode\OneboxSync\Core\Installer;
use CatCode\OneboxSync\Core\Logger;
use CatCode\OneboxSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	private const SLUG = 'catcode-order-sync-with-onebox-for-woocommerce';

	/** @var string Hook suffix of our screen, used to scope the stylesheet. */
	private $hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
		add_action( 'admin_post_ccob_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ccob_test_connection', array( $this, 'handle_test' ) );
	}

	public function register_menu(): void {
		$this->hook = (string) add_submenu_page(
			'woocommerce',
			__( 'OneBox Sync', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			__( 'OneBox Sync', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ccob-wrap">';
		echo '<h1>' . esc_html__( 'OneBox Sync', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</h1>';
		echo '<p class="ccob-lead">' . esc_html__( 'WooCommerce orders are sent to OneBox automatically.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';

		if ( isset( $_GET['ccob_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = isset( $_GET['ccob_err'] ) ? 'notice-error' : 'notice-success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['ccob_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$base = admin_url( 'admin.php?page=' . self::SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( $base ) . '" class="nav-tab' . ( 'log' !== $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Settings', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</a>';
		echo '<a href="' . esc_url( $base . '&tab=log' ) . '" class="nav-tab' . ( 'log' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Log', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</a>';
		echo '</h2>';

		if ( 'log' === $tab ) {
			$this->render_log();
		} else {
			$this->render_settings();
		}

		echo '</div>';
	}

	private function render_settings(): void {
		$cfg     = Settings::all();
		$has_key = '' !== (string) $cfg['api_password'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ccob_save_settings"/>';
		wp_nonce_field( 'ccob_save_settings' );

		echo '<div class="ccob-card">';
		echo '<h2>' . esc_html__( 'Connection', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="ccob-domain">' . esc_html__( 'OneBox domain', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="ccob-domain" name="domain" value="' . esc_attr( (string) $cfg['domain'] ) . '" placeholder="mybox.crm-onebox.com"/>';
		echo '<p class="description">' . esc_html__( 'Your OneBox OS address without https:// (for example mybox.crm-onebox.com or your own domain).', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ccob-api-login">' . esc_html__( 'API login', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="ccob-api-login" name="api_login" value="' . esc_attr( (string) $cfg['api_login'] ) . '" autocomplete="off"/>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ccob-api-password">' . esc_html__( 'API password', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="password" class="regular-text" id="ccob-api-password" name="api_password" value="" autocomplete="new-password" placeholder="' . esc_attr( $has_key ? __( '•••••• saved — type a new one to replace it', 'catcode-order-sync-with-onebox-for-woocommerce' ) : '' ) . '"/>';
		echo '<p class="description">' . esc_html__( 'OneBox account → the “Users and employees” app → the employee card → REST API password. The plugin exchanges it for a token via /api/v2/token/get/ on its own. The password is stored encrypted.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . ( $has_key ? ' <span class="ccob-saved">' . esc_html__( 'Password saved.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</span>' : '' ) . '</p>';
		echo '</td></tr>';

		echo '</table></div>';

		echo '<div class="ccob-card">';
		echo '<h2>' . esc_html__( 'CRM routing (optional)', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$route_fields = array(
			'workflowid' => __( 'Business process ID (workflowid)', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			'statusid'   => __( 'Stage ID (statusid)', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			'sourceid'   => __( 'Source ID (sourceid)', 'catcode-order-sync-with-onebox-for-woocommerce' ),
		);
		foreach ( $route_fields as $key => $label ) {
			$val = (int) ( $cfg[ $key ] ?? 0 );
			echo '<tr><th scope="row"><label for="ccob-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="number" min="0" step="1" class="small-text" id="ccob-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val > 0 ? (string) $val : '' ) . '"/>';
			echo '</td></tr>';
		}
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Leave empty to let OneBox pick its own defaults. The IDs come from your OneBox account (business processes, stages, sources) or from the /api/v2/workflow/get/ and /api/v2/source/get/ methods.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p></td></tr>';
		echo '</table></div>';

		echo '<div class="ccob-card">';
		echo '<h2>' . esc_html__( 'Order sending', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Trigger statuses', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th><td>';
		$selected = is_array( $cfg['trigger_statuses'] ) ? $cfg['trigger_statuses'] : array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$short = 0 === strncmp( $slug, 'wc-', 3 ) ? substr( $slug, 3 ) : $slug;
			echo '<label class="ccob-trigger"><input type="checkbox" name="trigger_statuses[]" value="' . esc_attr( $short ) . '"' . checked( in_array( $short, $selected, true ), true, false ) . '/> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'The order is sent to OneBox right after checkout, or when it moves into one of the selected statuses (unless it has been sent already).', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Payment status', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="pass_payment_status" value="yes"' . checked( 'yes' === $cfg['pass_payment_status'], true, false ) . '/> ' . esc_html__( 'Mark online payments (add “Paid online” to the process comment for paid orders)', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Zero-priced items', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="skip_zero_price" value="yes"' . checked( 'yes' === $cfg['skip_zero_price'], true, false ) . '/> ' . esc_html__( 'Skip lines priced at 0 (gifts, samples)', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Shipping', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="include_shipping" value="yes"' . checked( 'yes' === $cfg['include_shipping'], true, false ) . '/> ' . esc_html__( 'Send the shipping cost as an order line', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '</table></div>';

		echo '<div class="ccob-actions">';
		submit_button( __( 'Save settings', 'catcode-order-sync-with-onebox-for-woocommerce' ), 'primary large', 'submit', false );
		echo '</div>';
		echo '</form>';

		// Separate small form for the connection test (does not touch settings).
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ccob-test">';
		echo '<input type="hidden" name="action" value="ccob_test_connection"/>';
		wp_nonce_field( 'ccob_test_connection' );
		submit_button( __( 'Test connection', 'catcode-order-sync-with-onebox-for-woocommerce' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Requests a token via /api/v2/token/get/ and reads the list of business processes.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</span>';
		echo '</form>';
	}

	private function render_log(): void {
		$rows = Logger::latest( 100 );

		echo '<div class="ccob-card ccob-log">';
		echo '<h2>' . esc_html__( 'Latest events', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No events yet.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Attempt', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th>';
		echo '<th>HTTP</th>';
		echo '<th>' . esc_html__( 'Result', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$order_id = (int) $r['order_id'];
			$link     = $order_id > 0 ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id ) : '';
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['created_at'] ) . '</td>';
			echo '<td>' . ( $order_id > 0 ? '<a href="' . esc_url( $link ) . '">#' . esc_html( (string) $order_id ) . '</a>' : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $r['event'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['attempt_no'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['http_status'] ?? '—' ) ) . '</td>';
			echo '<td>' . ( $r['success'] ? '<span class="ccob-log-ok">OK</span>' : '<span class="ccob-log-fail">' . esc_html__( 'Error', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</span>' ) . '</td>';
			echo '<td><code class="ccob-log-msg">' . esc_html( mb_substr( (string) $r['message'], 0, 200 ) ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'catcode-order-sync-with-onebox-for-woocommerce' ) );
		}
		check_admin_referer( 'ccob_save_settings' );

		$current = Settings::all();

		$api_password = isset( $_POST['api_password'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['api_password'] ) ) ) : '';
		if ( '' === $api_password ) {
			$api_password = (string) $current['api_password']; // Empty submit keeps the stored password.
		}

		$statuses = array();
		if ( isset( $_POST['trigger_statuses'] ) && is_array( $_POST['trigger_statuses'] ) ) {
			$statuses = array_map( 'sanitize_key', wp_unslash( $_POST['trigger_statuses'] ) );
		}
		if ( empty( $statuses ) ) {
			$statuses = Installer::default_settings()['trigger_statuses'];
		}

		Settings::save(
			array(
				'domain'              => isset( $_POST['domain'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['domain'] ) ) ) : '',
				'api_login'           => isset( $_POST['api_login'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['api_login'] ) ) ) : '',
				'api_password'        => $api_password,
				'trigger_statuses'    => $statuses,
				'pass_payment_status' => isset( $_POST['pass_payment_status'] ) ? 'yes' : 'no',
				'skip_zero_price'     => isset( $_POST['skip_zero_price'] ) ? 'yes' : 'no',
				'include_shipping'    => isset( $_POST['include_shipping'] ) ? 'yes' : 'no',
				'workflowid'          => isset( $_POST['workflowid'] ) ? absint( wp_unslash( $_POST['workflowid'] ) ) : 0,
				'statusid'            => isset( $_POST['statusid'] ) ? absint( wp_unslash( $_POST['statusid'] ) ) : 0,
				'sourceid'            => isset( $_POST['sourceid'] ) ? absint( wp_unslash( $_POST['sourceid'] ) ) : 0,
			)
		);

		$this->redirect( __( 'Settings saved.', 'catcode-order-sync-with-onebox-for-woocommerce' ), false );
	}

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'catcode-order-sync-with-onebox-for-woocommerce' ) );
		}
		check_admin_referer( 'ccob_test_connection' );

		$client = new Client();
		$res    = $client->test_connection();

		Logger::log( 0, 'test', 1, $res['status'], $res['ok'] ? 'OK' : $res['error'] . ' ' . mb_substr( $res['body'], 0, 300 ), $res['ok'] );

		if ( $res['ok'] ) {
			/* translators: %d — HTTP status code. */
			$this->redirect( sprintf( __( 'Connection successful (HTTP %d).', 'catcode-order-sync-with-onebox-for-woocommerce' ), $res['status'] ), false );
		}
		/* translators: %s — error details. */
		$this->redirect( sprintf( __( 'Connection error: %s', 'catcode-order-sync-with-onebox-for-woocommerce' ), $res['error'] ), true );
	}

	private function redirect( string $msg, bool $is_error ): void {
		$url = add_query_arg(
			array_filter(
				array(
					'page'     => self::SLUG,
					'ccob_msg' => rawurlencode( $msg ),
					'ccob_err' => $is_error ? '1' : null,
				)
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Screen styles, attached to an enqueued handle instead of an inline
	 * <style> block in the page body.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function styles( $hook = '' ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		$handle = 'ccob-settings';
		wp_register_style( $handle, false, array(), CCOB_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style(
			$handle,
			'.ccob-wrap{max-width:880px}'
			. '.ccob-wrap .ccob-lead{font-size:14px;color:#50575e;margin:.2em 0 1em}'
			. '.ccob-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 22px 18px;margin:16px 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}'
			. '.ccob-card>h2{font-size:15px;margin:14px 0 2px;padding:0;border:0}'
			. '.ccob-card .form-table th{padding-top:16px;padding-bottom:16px;width:220px;font-weight:600}'
			. '.ccob-card .form-table td{padding-top:14px;padding-bottom:14px}'
			. '.ccob-card .ccob-saved{color:#1a7f37;font-weight:600}'
			. '.ccob-trigger{display:block;margin:2px 0}'
			. '.ccob-actions{padding:4px 0 8px}'
			. '.ccob-actions .button-large{padding:6px 26px;height:auto;font-size:14px}'
			. '.ccob-test{margin-top:4px}'
			. '.ccob-log{margin-top:16px}'
			. '.ccob-log-ok{color:#1a7f37;font-weight:600}'
			. '.ccob-log-fail{color:#a00;font-weight:600}'
			. '.ccob-log-msg{font-size:11px}'
		);
	}
}
