<?php
/**
 * Filters that enforce the configured update behaviour.
 *
 * @package JMooreWV\UpdatesManager
 */

namespace JMooreWV\UpdatesManager;

defined( 'ABSPATH' ) || exit;

/**
 * Update behaviour.
 *
 * Modes: "disabled" hides updates entirely, "manual" allows manual updates
 * only, "auto" (and core "minor"/"all") also installs them automatically.
 */
class Updates {

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
		// This plugin is authoritative; hide WordPress's own per-item auto-update toggles.
		add_filter( 'plugins_auto_update_enabled', '__return_false' );
		add_filter( 'themes_auto_update_enabled', '__return_false' );

		add_filter( 'auto_update_plugin', array( $this, 'auto_plugin' ), 10, 2 );
		add_filter( 'auto_update_theme', array( $this, 'auto_theme' ), 10, 2 );
		add_filter( 'auto_update_translation', array( $this, 'auto_translation' ) );

		add_filter( 'auto_update_core', array( $this, 'auto_core' ) );
		add_filter( 'allow_major_auto_core_updates', array( $this, 'allow_major' ) );
		add_filter( 'allow_minor_auto_core_updates', array( $this, 'allow_minor' ) );
		add_filter( 'allow_dev_auto_core_updates', '__return_false' );

		add_filter( 'site_transient_update_core', array( $this, 'filter_core' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'filter_plugins' ) );
		add_filter( 'site_transient_update_themes', array( $this, 'filter_themes' ) );

		if ( $this->settings->get( 'vcs_updates' ) ) {
			add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		}

		if ( $this->settings->get( 'silence_wp_emails' ) ) {
			add_filter( 'auto_core_update_send_email', '__return_false' );
			add_filter( 'send_core_update_notification_email', '__return_false' );
			add_filter( 'auto_plugin_update_send_email', '__return_false' );
			add_filter( 'auto_theme_update_send_email', '__return_false' );
		}

		if ( $this->settings->get( 'lock_updates' ) ) {
			add_filter( 'map_meta_cap', array( $this, 'lock_caps' ), 10, 2 );
		}

		if ( $this->settings->get( 'nags_admin_only' ) ) {
			add_action( 'admin_init', array( $this, 'maybe_remove_nags' ) );
		}
	}

	/**
	 * Whether a plugin should auto-update.
	 *
	 * @param bool|null $update Current decision.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public function auto_plugin( $update, $item ) {
		if ( empty( $item->plugin ) ) {
			return $update;
		}

		return 'auto' === $this->settings->item_mode( 'plugin', $item->plugin );
	}

	/**
	 * Whether a theme should auto-update.
	 *
	 * @param bool|null $update Current decision.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public function auto_theme( $update, $item ) {
		if ( empty( $item->theme ) ) {
			return $update;
		}

		return 'auto' === $this->settings->item_mode( 'theme', $item->theme );
	}

	/**
	 * Whether translations auto-update.
	 *
	 * @return bool
	 */
	public function auto_translation() {
		return (bool) $this->settings->get( 'translations_auto' );
	}

	/**
	 * Core auto-update gate.
	 *
	 * @param bool|null $update Current decision.
	 * @return bool|null
	 */
	public function auto_core( $update ) {
		return in_array( $this->settings->get( 'core_mode' ), array( 'minor', 'all' ), true ) ? $update : false;
	}

	/**
	 * Allow major core auto-updates.
	 *
	 * @return bool
	 */
	public function allow_major() {
		return 'all' === $this->settings->get( 'core_mode' );
	}

	/**
	 * Allow minor core auto-updates.
	 *
	 * @return bool
	 */
	public function allow_minor() {
		return in_array( $this->settings->get( 'core_mode' ), array( 'minor', 'all' ), true );
	}

	/**
	 * Hide core updates when disabled.
	 *
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public function filter_core( $value ) {
		if ( 'disabled' !== $this->settings->get( 'core_mode' ) || ! is_object( $value ) ) {
			return $value;
		}

		$value          = clone $value;
		$value->updates = array();

		return $value;
	}

	/**
	 * Remove plugins set to "disabled" from the available updates.
	 *
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public function filter_plugins( $value ) {
		return $this->strip_disabled( $value, 'plugin' );
	}

	/**
	 * Remove themes set to "disabled" from the available updates.
	 *
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public function filter_themes( $value ) {
		return $this->strip_disabled( $value, 'theme' );
	}

	/**
	 * Deny the capabilities needed to run updates from the dashboard.
	 *
	 * Automatic updates run without a user and are not affected.
	 *
	 * @param string[] $caps Primitive capabilities required.
	 * @param string   $cap  Capability being checked.
	 * @return string[]
	 */
	public function lock_caps( $caps, $cap ) {
		if ( in_array( $cap, array( 'update_core', 'update_plugins', 'update_themes', 'update_languages' ), true ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Hide update notices from users who cannot update.
	 *
	 * @return void
	 */
	public function maybe_remove_nags() {
		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'network_admin_notices', 'update_nag', 3 );
			remove_action( 'admin_notices', 'maintenance_nag' );
			remove_action( 'network_admin_notices', 'maintenance_nag' );
		}
	}

	/**
	 * Drop "disabled" items from a plugin/theme update transient.
	 *
	 * @param mixed  $value Transient value.
	 * @param string $type  'plugin' or 'theme'.
	 * @return mixed
	 */
	private function strip_disabled( $value, $type ) {
		if ( ! is_object( $value ) || empty( $value->response ) || ! is_array( $value->response ) ) {
			return $value;
		}

		$value = clone $value;
		foreach ( array_keys( $value->response ) as $id ) {
			if ( 'disabled' === $this->settings->item_mode( $type, $id ) ) {
				unset( $value->response[ $id ] );
			}
		}

		return $value;
	}
}
