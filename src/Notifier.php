<?php
/**
 * Email notifications for available and completed updates.
 *
 * @package JMooreWV\UpdatesManager
 */

namespace JMooreWV\UpdatesManager;

defined( 'ABSPATH' ) || exit;

/**
 * Update emails.
 */
class Notifier {

	const SEEN_OPTION = 'jmwv_um_notified';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public function register() {
		// Run after WordPress's own checks (priority 10) have refreshed the transients.
		add_action( 'wp_version_check', array( $this, 'maybe_notify_available' ), 20 );
		add_action( 'wp_update_plugins', array( $this, 'maybe_notify_available' ), 20 );
		add_action( 'wp_update_themes', array( $this, 'maybe_notify_available' ), 20 );

		add_action( 'upgrader_process_complete', array( $this, 'maybe_notify_updated' ), 10, 2 );
	}

	/**
	 * Email about updates that were not present at the previous check.
	 *
	 * The first run only records the current state, so enabling this does not
	 * email every update already pending.
	 *
	 * @return void
	 */
	public function maybe_notify_available() {
		if ( ! $this->settings->get( 'notify_available' ) ) {
			return;
		}

		$found = $this->collect_available();
		$seen  = get_option( self::SEEN_OPTION );
		update_option( self::SEEN_OPTION, array_keys( $found ), false );

		if ( ! is_array( $seen ) ) {
			return;
		}

		$new = array_diff_key( $found, array_flip( $seen ) );
		if ( ! $new ) {
			return;
		}

		$body = __( 'New updates are available:', 'jmwv-updates-manager' ) . "\n\n- " . implode( "\n- ", $new )
			. "\n\n" . admin_url( 'update-core.php' ) . "\n";

		$this->send( __( 'Updates available', 'jmwv-updates-manager' ), $body );
	}

	/**
	 * Email after an update has been installed.
	 *
	 * @param object $upgrader Upgrader instance (unused).
	 * @param array  $extra    Upgrade context.
	 * @return void
	 */
	public function maybe_notify_updated( $upgrader, $extra ) {
		if ( ! $this->settings->get( 'notify_updated' ) || ! is_array( $extra ) || 'update' !== ( isset( $extra['action'] ) ? $extra['action'] : '' ) ) {
			return;
		}

		$type  = isset( $extra['type'] ) ? $extra['type'] : '';
		$lines = array();

		if ( 'core' === $type ) {
			$lines[] = __( 'WordPress core', 'jmwv-updates-manager' );
		} elseif ( 'plugin' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			wp_cache_delete( 'plugins', 'plugins' );
			$installed = get_plugins();
			foreach ( $this->extract_ids( $extra, 'plugins', 'plugin' ) as $file ) {
				$lines[] = isset( $installed[ $file ] ) ? $installed[ $file ]['Name'] . ' ' . $installed[ $file ]['Version'] : $file;
			}
		} elseif ( 'theme' === $type ) {
			wp_clean_themes_cache();
			foreach ( $this->extract_ids( $extra, 'themes', 'theme' ) as $slug ) {
				$theme   = wp_get_theme( $slug );
				$lines[] = $theme->exists() ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : $slug;
			}
		}

		if ( ! $lines ) {
			return;
		}

		$body = __( 'The following were updated:', 'jmwv-updates-manager' ) . "\n\n- " . implode( "\n- ", $lines ) . "\n";

		$this->send( __( 'Updates installed', 'jmwv-updates-manager' ), $body );
	}

	/**
	 * Pending updates keyed by a stable id, valued by a display label.
	 *
	 * @return array<string,string>
	 */
	public function collect_available() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$found = array();

		$core = get_site_transient( 'update_core' );
		if ( is_object( $core ) && ! empty( $core->updates[0] ) && 'upgrade' === $core->updates[0]->response ) {
			$found[ 'core:' . $core->updates[0]->current ] = 'WordPress ' . $core->updates[0]->current;
		}

		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
			$installed = get_plugins();
			foreach ( $plugins->response as $file => $offer ) {
				$name                                     = isset( $installed[ $file ] ) ? $installed[ $file ]['Name'] : $file;
				$found[ 'plugin:' . $file . ':' . $offer->new_version ] = $name . ' ' . $offer->new_version;
			}
		}

		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) ) {
			foreach ( $themes->response as $slug => $offer ) {
				$theme                                    = wp_get_theme( $slug );
				$name                                     = $theme->exists() ? $theme->get( 'Name' ) : $slug;
				$found[ 'theme:' . $slug . ':' . $offer['new_version'] ] = $name . ' ' . $offer['new_version'];
			}
		}

		return $found;
	}

	/**
	 * Ids from a bulk (plural key) or single (singular key) upgrade context.
	 *
	 * @param array  $extra    Upgrade context.
	 * @param string $plural   Bulk key.
	 * @param string $singular Single key.
	 * @return string[]
	 */
	private function extract_ids( array $extra, $plural, $singular ) {
		if ( ! empty( $extra[ $plural ] ) ) {
			return (array) $extra[ $plural ];
		}

		return empty( $extra[ $singular ] ) ? array() : array( $extra[ $singular ] );
	}

	/**
	 * Send a notification.
	 *
	 * @param string $subject Subject without site prefix.
	 * @param string $body    Plain-text body.
	 * @return void
	 */
	private function send( $subject, $body ) {
		$to = $this->settings->get( 'notify_email' );
		$to = is_email( $to ) ? $to : get_option( 'admin_email' );

		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		wp_mail( $to, sprintf( '[%s] %s', $site, $subject ), $body );
	}
}
