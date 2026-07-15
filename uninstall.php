<?php
/**
 * Uninstall handler. Removes options. The log table is kept (audit data).
 *
 * @package CcOneboxSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cc_onebox_settings' );
delete_option( 'cc_onebox_version' );
delete_option( 'cc_onebox_crypto_key' );
delete_transient( 'cc_onebox_api_token' );
delete_transient( 'cc_onebox_default_workflow' );
