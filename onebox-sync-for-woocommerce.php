<?php
/**
 * Plugin Name: OneBox Sync for WooCommerce
 * Plugin URI: https://catcode.com.ua/plugins/onebox-sync-for-woocommerce
 * Description: Автоматична відправка замовлень WooCommerce у OneBox: створення замовлення в CRM після checkout, дедуплікація, повторні спроби, журнал подій.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: CatCode
 * Author URI: https://catcode.com.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: onebox-sync-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 7.0
 * WC tested up to: 10.7
 *
 * @package CcOneboxSync
 */

defined( 'ABSPATH' ) || exit;

// Per-constant guards keep WP's activation sandbox-scrape (which includes this
// file twice) from emitting "already defined" warnings — without ever skipping
// the autoloader/hook registration below.
defined( 'CCOB_VERSION' ) || define( 'CCOB_VERSION', '0.1.0' );
defined( 'CCOB_FILE' ) || define( 'CCOB_FILE', __FILE__ );
defined( 'CCOB_DIR' ) || define( 'CCOB_DIR', plugin_dir_path( __FILE__ ) );
defined( 'CCOB_URL' ) || define( 'CCOB_URL', plugin_dir_url( __FILE__ ) );
defined( 'CCOB_BASENAME' ) || define( 'CCOB_BASENAME', plugin_basename( __FILE__ ) );

// Explicit, dependency-ordered includes. (An spl_autoload_register closure
// proved unreliable across SAPIs on some hosts, so we load the small class set
// directly — deterministic and fast.)
foreach (
	array(
		'includes/Core/Crypto.php',
		'includes/Core/Logger.php',
		'includes/Core/Settings.php',
		'includes/Core/Installer.php',
		'includes/Api/Client.php',
		'includes/OrderMapper.php',
		'includes/Sender.php',
		'includes/Admin/SettingsPage.php',
		'includes/Admin/OrderMetaBox.php',
		'includes/Core/Plugin.php',
	) as $ccob_inc
) {
	require_once CCOB_DIR . $ccob_inc;
}
unset( $ccob_inc );

register_activation_hook(
	__FILE__,
	static function () {
		require_once __DIR__ . '/includes/Core/Installer.php';
		\CatCode\OneboxSync\Core\Installer::activate();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		require_once __DIR__ . '/includes/Core/Installer.php';
		\CatCode\OneboxSync\Core\Installer::deactivate();
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Для роботи OneBox Sync for WooCommerce потрібен активний WooCommerce.', 'onebox-sync-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}
		\CatCode\OneboxSync\Core\Plugin::instance()->boot();
	}
);
