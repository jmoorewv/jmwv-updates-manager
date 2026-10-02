<?php
/**
 * Dashboard widget and Site Health test.
 *
 * @package JMooreWV\UpdatesManager
 */

namespace JMooreWV\UpdatesManager;

defined( 'ABSPATH' ) || exit;

/**
 * At-a-glance status for administrators.
 */
class Status {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Notifier, used for its list of pending updates.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 * @param Notifier $notifier Notifier.
	 */
	public function __construct( Settings $settings, Notifier $notifier ) {
		$this->settings = $settings;
		$this->notifier = $notifier;
	}

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @return void
	 */
	public function add_widget() {
		if ( ! $this->settings->get( 'dashboard_widget' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget( 'jmwv_um_pending', __( 'Pending Updates', 'jmwv-updates-manager' ), array( $this, 'render_widget' ) );
	}

	/**
	 * Render the dashboard widget.
	 *
	 * @return void
	 */
	public function render_widget() {
		$pending = $this->notifier->collect_available();

		if ( ! $pending ) {
			echo '<p>' . esc_html__( 'Everything is up to date.', 'jmwv-updates-manager' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of pending updates. */
					_n( '%d update is waiting. Nothing changes until you update.', '%d updates are waiting. Nothing changes until you update.', count( $pending ), 'jmwv-updates-manager' ),
					count( $pending )
				)
			) . '</p><ul style="list-style:disc;padding-left:1.5em">';
			foreach ( $pending as $label ) {
				echo '<li>' . esc_html( $label ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $this->settings->get( 'lock_updates' ) ) {
			echo '<p><strong>' . esc_html__( 'Updates are locked.', 'jmwv-updates-manager' ) . '</strong></p>';
		} elseif ( $pending && current_user_can( 'update_core' ) ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Go to Updates', 'jmwv-updates-manager' ) . '</a></p>';
		}
	}

	/**
	 * Register the Site Health test.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function add_test( $tests ) {
		$tests['direct']['jmwv_um_child_theme'] = array(
			'label' => __( 'Active theme update safety', 'jmwv-updates-manager' ),
			'test'  => array( $this, 'test_child_theme' ),
		);

		return $tests;
	}

	/**
	 * Site Health: warn when updating the active theme could overwrite file edits.
	 *
	 * @return array
	 */
	public function test_child_theme() {
		$theme  = wp_get_theme();
		$result = array(
			'label'       => __( 'The active theme is protected from update overwrites', 'jmwv-updates-manager' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Updates', 'jmwv-updates-manager' ),
				'color' => 'green',
			),
			'description' => '<p>' . esc_html__( 'Updating this theme will not overwrite your customizations.', 'jmwv-updates-manager' ) . '</p>',
			'actions'     => '',
			'test'        => 'jmwv_um_child_theme',
		);

		if ( is_child_theme() || 'disabled' === $this->settings->item_mode( 'theme', $theme->get_stylesheet() ) ) {
			return $result;
		}

		$result['label']       = __( 'The active theme is not a child theme', 'jmwv-updates-manager' );
		$result['status']      = 'recommended';
		$result['badge']['color'] = 'blue';
		$result['description'] = '<p>' . esc_html__( 'Updating this theme replaces its files. Any edits made directly to the theme files will be lost. Settings saved through the Customizer or Site Editor are stored in the database and are not affected. Use a child theme for file customizations, or set updates for this theme to "disabled".', 'jmwv-updates-manager' ) . '</p>';

		return $result;
	}
}
