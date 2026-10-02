<?php
/**
 * Settings storage and sanitization.
 *
 * @package JMooreWV\UpdatesManager
 */

namespace JMooreWV\UpdatesManager;

defined( 'ABSPATH' ) || exit;

/**
 * Single-option settings store.
 */
class Settings {

	const OPTION = 'jmwv_um_settings';

	const CORE_MODES = array( 'disabled', 'manual', 'minor', 'all' );
	const ITEM_MODES = array( 'disabled', 'manual', 'auto' );

	const DEFAULTS = array(
		'core_mode'         => 'manual',
		'plugins_mode'      => 'manual',
		'themes_mode'       => 'manual',
		'plugin_overrides'  => array(),
		'theme_overrides'   => array(),
		'translations_auto' => false,
		'vcs_updates'       => false,
		'nags_admin_only'   => false,
		'silence_wp_emails' => false,
		'dashboard_widget'  => true,
		'lock_updates'      => false,
		'notify_available'  => false,
		'notify_updated'    => false,
		'notify_email'      => '',
	);

	/**
	 * Get all settings merged over defaults, or a single one.
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public function get( $key = null ) {
		$stored = get_option( self::OPTION, array() );
		$all    = wp_parse_args( is_array( $stored ) ? $stored : array(), self::DEFAULTS );

		return null === $key ? $all : $all[ $key ];
	}

	/**
	 * Effective mode for a plugin or theme (per-item override, else global).
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param string $id   Plugin file or theme stylesheet.
	 * @return string One of ITEM_MODES.
	 */
	public function item_mode( $type, $id ) {
		$overrides = (array) $this->get( $type . '_overrides' );

		return isset( $overrides[ $id ] ) ? $overrides[ $id ] : $this->get( $type . 's_mode' );
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::DEFAULTS;

		$out['core_mode']    = $this->pick( $input, 'core_mode', self::CORE_MODES );
		$out['plugins_mode'] = $this->pick( $input, 'plugins_mode', self::ITEM_MODES );
		$out['themes_mode']  = $this->pick( $input, 'themes_mode', self::ITEM_MODES );

		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$field = $type . '_overrides';
			if ( empty( $input[ $field ] ) || ! is_array( $input[ $field ] ) ) {
				continue;
			}
			foreach ( $input[ $field ] as $id => $mode ) {
				if ( in_array( $mode, self::ITEM_MODES, true ) ) {
					$out[ $field ][ sanitize_text_field( $id ) ] = $mode;
				}
			}
		}

		foreach ( array( 'translations_auto', 'vcs_updates', 'nags_admin_only', 'silence_wp_emails', 'dashboard_widget', 'lock_updates', 'notify_available', 'notify_updated' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$email                = ( isset( $input['notify_email'] ) && is_string( $input['notify_email'] ) ) ? sanitize_email( $input['notify_email'] ) : '';
		$out['notify_email'] = is_email( $email ) ? $email : '';

		return $out;
	}

	/**
	 * Return a submitted value if allowed, else the default.
	 *
	 * @param array  $input   Submitted values.
	 * @param string $key     Field key.
	 * @param array  $allowed Allowed values.
	 * @return string
	 */
	private function pick( array $input, $key, array $allowed ) {
		return ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) ? $input[ $key ] : self::DEFAULTS[ $key ];
	}
}
