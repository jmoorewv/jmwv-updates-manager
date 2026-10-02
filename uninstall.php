<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package JMooreWV\UpdatesManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'jmwv_um_settings' );
delete_option( 'jmwv_um_notified' );
