<?php
/**
 * Plugin Name:       JMWV Updates Manager
 * Description:       Lightweight control over core, plugin, theme and translation updates: disable them, make them manual-only, or let them run automatically.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jonathan Moore
 * Author URI:        https://jmoorewv.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jmwv-updates-manager
 * Update URI:        https://github.com/jmoorewv/jmwv-updates-manager
 *
 * @package JMooreWV\UpdatesManager
 */

namespace JMooreWV\UpdatesManager;

defined( 'ABSPATH' ) || exit;

define( 'JMWV_UM_FILE', __FILE__ );
define( 'JMWV_UM_VERSION', '1.0.2' ); // Keep in sync with the Version header above.

require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Updates.php';
require_once __DIR__ . '/src/Notifier.php';
require_once __DIR__ . '/src/Status.php';
require_once __DIR__ . '/src/GitHubUpdater.php';
require_once __DIR__ . '/src/Admin.php';

/**
 * Wire everything up.
 *
 * @return void
 */
function boot() {
	$settings = new Settings();
	( new Updates( $settings ) )->register();
	$notifier = new Notifier( $settings );
	$notifier->register();
	( new Status( $settings, $notifier ) )->register();
	( new GitHubUpdater() )->register();

	if ( is_admin() ) {
		( new Admin( $settings ) )->register();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\boot' );
