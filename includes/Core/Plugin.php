<?php
/**
 * Plugin singleton.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync\Core;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'maybe_upgrade' ) );

		( new \CatCode\OneboxSync\Sender() )->register_hooks();

		if ( is_admin() ) {
			new \CatCode\OneboxSync\Admin\SettingsPage();
			new \CatCode\OneboxSync\Admin\OrderMetaBox();
		}
	}

	public function maybe_upgrade(): void {
		$installed = get_option( 'cc_onebox_version' );
		if ( CCOB_VERSION === $installed ) {
			return;
		}
		Installer::activate();

		$cfg = get_option( 'cc_onebox_settings' );
		if ( is_array( $cfg ) ) {
			$cfg = wp_parse_args( $cfg, Installer::default_settings() );
			update_option( 'cc_onebox_settings', $cfg, true );
		}

		update_option( 'cc_onebox_version', CCOB_VERSION, false );
	}
}
