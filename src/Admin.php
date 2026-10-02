<?php
/**
 * Settings page.
 *
 * @package JMooreWV\UpdatesManager
 */

namespace JMooreWV\UpdatesManager;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Updates Manager.
 */
class Admin {

	const PAGE = 'jmwv-updates-manager';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

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
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_jmwv_um_force', array( $this, 'handle_force' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JMWV_UM_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Schedule an immediate update check and automatic-update run.
	 *
	 * Only installs what the current settings allow automatically.
	 *
	 * @return void
	 */
	public function handle_force() {
		check_admin_referer( 'jmwv_um_force' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'jmwv-updates-manager' ), '', array( 'response' => 403 ) );
		}

		wp_schedule_single_event( time() + 10, 'wp_update_plugins' );
		wp_schedule_single_event( time() + 10, 'wp_version_check' );
		wp_schedule_single_event( time() + 10, 'wp_update_themes' );
		wp_schedule_single_event( time() + 45, 'wp_maybe_auto_update' );

		// A stale lock from an interrupted run would otherwise block the auto-update.
		delete_option( 'auto_updater.lock' );

		wp_safe_redirect( add_query_arg( 'jmwv_um_forced', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public function add_page() {
		$this->hook = (string) add_options_page(
			__( 'Updates Manager', 'jmwv-updates-manager' ),
			__( 'Updates Manager', 'jmwv-updates-manager' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Load the stylesheet on our page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			wp_enqueue_style( 'jmwv-um-admin', plugins_url( 'assets/admin.css', JMWV_UM_FILE ), array(), JMWV_UM_VERSION );
		}
	}

	/**
	 * Register the option.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			'jmwv_um',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::DEFAULTS,
			)
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'jmwv-updates-manager' ) . '</a>' );

		return $links;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$s       = $this->settings->get();
		$o       = Settings::OPTION;
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ $file ] = $data['Name'];
		}
		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[ $slug ] = $theme->get( 'Name' );
		}
		?>
		<div class="wrap jmwv-um">
			<h1><?php esc_html_e( 'Updates Manager', 'jmwv-updates-manager' ); ?></h1>
			<p class="jmwv-lead"><?php esc_html_e( 'Decide what WordPress updates on its own, what waits for you, and what is hidden entirely.', 'jmwv-updates-manager' ); ?></p>

			<?php if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'DISALLOW_FILE_MODS is set in wp-config.php, which blocks all updates and installs regardless of these settings.', 'jmwv-updates-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['jmwv_um_forced'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only. ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Update check and automatic-update run scheduled. Allow about 90 seconds, then check the Updates screen. Needs WP-Cron to be running.', 'jmwv-updates-manager' ); ?></p></div>
			<?php endif; ?>

			<div class="jmwv-summary">
				<?php
				$this->tile( __( 'Core', 'jmwv-updates-manager' ), $s['core_mode'], $this->core_choices() );
				$this->tile( __( 'Plugins', 'jmwv-updates-manager' ), $s['plugins_mode'], $this->item_choices() );
				$this->tile( __( 'Themes', 'jmwv-updates-manager' ), $s['themes_mode'], $this->item_choices() );
				$this->tile(
					__( 'Update lock', 'jmwv-updates-manager' ),
					$s['lock_updates'] ? 'locked' : 'unlocked',
					array(
						'locked'   => array( __( 'Locked', 'jmwv-updates-manager' ), '' ),
						'unlocked' => array( __( 'Off', 'jmwv-updates-manager' ), '' ),
					)
				);
				?>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'jmwv_um' ); ?>

				<?php $this->card_open( __( 'WordPress core', 'jmwv-updates-manager' ), __( 'How WordPress itself is updated.', 'jmwv-updates-manager' ) ); ?>
				<?php $this->radios( $o . '[core_mode]', $this->core_choices(), $s['core_mode'] ); ?>
				<?php $this->card_close(); ?>

				<?php $this->card_open( __( 'Plugins', 'jmwv-updates-manager' ), __( 'The default for every plugin. Override individual plugins below.', 'jmwv-updates-manager' ) ); ?>
				<?php $this->radios( $o . '[plugins_mode]', $this->item_choices(), $s['plugins_mode'] ); ?>
				<?php $this->overrides( 'plugin', $plugins, $s['plugin_overrides'], __( 'Per-plugin overrides', 'jmwv-updates-manager' ) ); ?>
				<?php $this->card_close(); ?>

				<?php $this->card_open( __( 'Themes', 'jmwv-updates-manager' ), __( 'The default for every theme. Override individual themes below.', 'jmwv-updates-manager' ) ); ?>
				<?php $this->radios( $o . '[themes_mode]', $this->item_choices(), $s['themes_mode'] ); ?>
				<?php $this->overrides( 'theme', $themes, $s['theme_overrides'], __( 'Per-theme overrides', 'jmwv-updates-manager' ) ); ?>
				<?php $this->card_close(); ?>

				<?php $this->card_open( __( 'General', 'jmwv-updates-manager' ) ); ?>
				<?php $this->checkbox( 'translations_auto', __( 'Update translations automatically', 'jmwv-updates-manager' ), __( 'Language files are updated separately from plugins and themes.', 'jmwv-updates-manager' ), $s ); ?>
				<?php $this->checkbox( 'vcs_updates', __( 'Allow updates on version-controlled installs', 'jmwv-updates-manager' ), __( 'WordPress normally skips automatic updates when it detects git or svn.', 'jmwv-updates-manager' ), $s ); ?>
				<?php $this->checkbox( 'nags_admin_only', __( 'Show update notices only to users who can update', 'jmwv-updates-manager' ), __( 'Hides the update banner from editors and other roles.', 'jmwv-updates-manager' ), $s ); ?>
				<?php $this->checkbox( 'silence_wp_emails', __( "Turn off WordPress's own update emails", 'jmwv-updates-manager' ), __( 'Silences the emails WordPress sends after automatic updates.', 'jmwv-updates-manager' ), $s ); ?>
				<?php $this->checkbox( 'dashboard_widget', __( 'Show a pending-updates widget on the dashboard', 'jmwv-updates-manager' ), __( 'Lists what is waiting, visible to administrators.', 'jmwv-updates-manager' ), $s ); ?>
				<?php $this->card_close(); ?>

				<?php $this->card_open( __( 'Email notifications', 'jmwv-updates-manager' ), __( 'Get told about updates without having them installed for you.', 'jmwv-updates-manager' ) ); ?>
				<?php $this->checkbox( 'notify_available', __( 'When new updates become available', 'jmwv-updates-manager' ), '', $s ); ?>
				<?php $this->checkbox( 'notify_updated', __( 'When updates are installed', 'jmwv-updates-manager' ), '', $s ); ?>
				<div class="jmwv-field">
					<label for="jmwv-um-email"><?php esc_html_e( 'Send to', 'jmwv-updates-manager' ); ?></label>
					<input type="email" id="jmwv-um-email" class="regular-text" name="<?php echo esc_attr( $o . '[notify_email]' ); ?>" value="<?php echo esc_attr( $s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				</div>
				<?php $this->card_close(); ?>

				<?php $this->card_open( __( 'Lock updates', 'jmwv-updates-manager' ), __( 'A hard freeze for sites that must not change.', 'jmwv-updates-manager' ), 'is-danger' ); ?>
				<?php $this->checkbox( 'lock_updates', __( 'Nobody, including administrators, can run updates from the dashboard', 'jmwv-updates-manager' ), __( 'Uncheck this to update. Automatic updates enabled above still run.', 'jmwv-updates-manager' ), $s ); ?>
				<?php $this->card_close(); ?>

				<div class="jmwv-actions"><?php submit_button( '', 'primary', 'submit', false ); ?></div>
			</form>

			<?php $this->card_open( __( 'Tools', 'jmwv-updates-manager' ), __( 'Useful for debugging.', 'jmwv-updates-manager' ) ); ?>
			<p><?php esc_html_e( 'Run an update check now and attempt the automatic updates immediately, instead of waiting for the next scheduled run. Only updates allowed to install automatically by the settings above are installed.', 'jmwv-updates-manager' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="jmwv_um_force">
				<?php wp_nonce_field( 'jmwv_um_force' ); ?>
				<?php submit_button( __( 'Force update check', 'jmwv-updates-manager' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php $this->card_close(); ?>
		</div>
		<?php
	}

	/**
	 * Core mode choices as value => [title, description].
	 *
	 * @return array<string,string[]>
	 */
	private function core_choices() {
		return array(
			'manual'   => array( __( 'Manual', 'jmwv-updates-manager' ), __( 'You are notified, nothing installs automatically.', 'jmwv-updates-manager' ) ),
			'minor'    => array( __( 'Minor and security only', 'jmwv-updates-manager' ), __( 'Small maintenance releases install automatically. Major versions wait for you.', 'jmwv-updates-manager' ) ),
			'all'      => array( __( 'All releases automatically', 'jmwv-updates-manager' ), __( 'Every release installs automatically, including major versions.', 'jmwv-updates-manager' ) ),
			'disabled' => array( __( 'Disabled', 'jmwv-updates-manager' ), __( 'Core updates are hidden and never offered.', 'jmwv-updates-manager' ) ),
		);
	}

	/**
	 * Plugin/theme mode choices as value => [title, description].
	 *
	 * @return array<string,string[]>
	 */
	private function item_choices() {
		return array(
			'manual'   => array( __( 'Manual', 'jmwv-updates-manager' ), __( 'You are notified, nothing installs automatically.', 'jmwv-updates-manager' ) ),
			'auto'     => array( __( 'Automatic', 'jmwv-updates-manager' ), __( 'Updates install automatically.', 'jmwv-updates-manager' ) ),
			'disabled' => array( __( 'Disabled', 'jmwv-updates-manager' ), __( 'Updates are hidden and never offered.', 'jmwv-updates-manager' ) ),
		);
	}

	/**
	 * Status tile.
	 *
	 * @param string                $label   Tile label.
	 * @param string                $value   Current value key.
	 * @param array<string,string[]> $choices Value => [title, description].
	 * @return void
	 */
	private function tile( $label, $value, array $choices ) {
		$class = in_array( $value, array( 'minor', 'all' ), true ) ? 'auto' : $value;
		$title = isset( $choices[ $value ] ) ? $choices[ $value ][0] : $value;
		?>
		<div class="jmwv-tile">
			<span class="jmwv-tile-label"><?php echo esc_html( $label ); ?></span>
			<span class="jmwv-tile-value"><span class="jmwv-dot is-<?php echo esc_attr( $class ); ?>"></span><?php echo esc_html( $title ); ?></span>
		</div>
		<?php
	}

	/**
	 * Open a card.
	 *
	 * @param string $title Card title.
	 * @param string $desc  Optional subtitle.
	 * @param string $class Optional extra class.
	 * @return void
	 */
	private function card_open( $title, $desc = '', $class = '' ) {
		echo '<section class="jmwv-card ' . esc_attr( $class ) . '"><header><h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $desc ) {
			echo '<p>' . esc_html( $desc ) . '</p>';
		}
		echo '</header><div class="jmwv-card-body">';
	}

	/**
	 * Close a card.
	 *
	 * @return void
	 */
	private function card_close() {
		echo '</div></section>';
	}

	/**
	 * Radio group.
	 *
	 * @param string                $name    Field name.
	 * @param array<string,string[]> $choices Value => [title, description].
	 * @param string                $current Current value.
	 * @return void
	 */
	private function radios( $name, array $choices, $current ) {
		foreach ( $choices as $value => $text ) {
			printf(
				'<label class="jmwv-choice"><input type="radio" name="%s" value="%s"%s><span><strong>%s</strong><small>%s</small></span></label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( (string) $current, (string) $value, false ),
				esc_html( $text[0] ),
				esc_html( $text[1] )
			);
		}
	}

	/**
	 * Collapsible per-item override table.
	 *
	 * @param string   $type      'plugin' or 'theme'.
	 * @param string[] $items     Id => display name.
	 * @param array    $overrides Saved overrides.
	 * @param string   $title     Summary text.
	 * @return void
	 */
	private function overrides( $type, array $items, array $overrides, $title ) {
		if ( ! $items ) {
			return;
		}

		$labels = array( '' => __( 'Use default', 'jmwv-updates-manager' ) );
		foreach ( $this->item_choices() as $value => $text ) {
			$labels[ $value ] = $text[0];
		}
		$set = count( array_intersect_key( $overrides, $items ) );
		?>
		<details class="jmwv-overrides"<?php echo $set ? ' open' : ''; ?>>
			<summary><?php echo esc_html( $title ); ?><?php echo $set ? '<span class="jmwv-count">' . (int) $set . '</span>' : ''; ?></summary>
			<table>
				<tbody>
				<?php foreach ( $items as $id => $name ) : ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td>
							<select name="<?php echo esc_attr( Settings::OPTION . '[' . $type . '_overrides][' . $id . ']' ); ?>">
								<?php foreach ( $labels as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"<?php selected( isset( $overrides[ $id ] ) ? $overrides[ $id ] : '', $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/**
	 * Output a checkbox row with a description.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label text.
	 * @param string $desc  Help text.
	 * @param array  $s     Current settings.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc, array $s ) {
		printf(
			'<label class="jmwv-choice"><input type="checkbox" name="%s" value="1"%s><span><strong>%s</strong>%s</span></label>',
			esc_attr( Settings::OPTION . '[' . $key . ']' ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $label ),
			'' !== $desc ? '<small>' . esc_html( $desc ) . '</small>' : ''
		);
	}
}
