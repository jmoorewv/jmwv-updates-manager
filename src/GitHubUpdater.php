<?php
/**
 * Self-updates the plugin from its latest GitHub release.
 *
 * Uses the "Update URI" plugin header (WordPress 5.8+), so WordPress never
 * matches this plugin against WordPress.org. The package is the release's
 * source zipball; the folder inside it is renamed to the plugin slug.
 *
 * @package JMooreWV\UpdatesManager
 */

namespace JMooreWV\UpdatesManager;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub release updater.
 */
class GitHubUpdater {

	const REPO = 'jmoorewv/jmwv-updates-manager';
	const SLUG = 'jmwv-updates-manager';

	/**
	 * Where the icon and banner live in the repository. They are not shipped in the plugin zip.
	 */
	const IMAGES = 'https://raw.githubusercontent.com/' . self::REPO . '/main/plugin-info/';

	/**
	 * Plugin basename, e.g. jmwv-updates-manager/jmwv-updates-manager.php.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public function register() {
		$this->basename = plugin_basename( JMWV_UM_FILE );

		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
	}

	/**
	 * Tell WordPress about the latest release.
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin header data (unused).
	 * @param string      $plugin_file Plugin basename being checked.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'id'      => 'github.com/' . self::REPO,
			'slug'    => self::SLUG,
			'plugin'  => $plugin_file,
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
			'icons'   => $this->icons(),
			'banners' => $this->banners(),
		);
	}

	/**
	 * Fill the "View details" popup so WordPress does not look us up on WordPress.org.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'JMWV Updates Manager',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://jmoorewv.com">Jonathan Moore</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'icons'         => $this->icons(),
			'banners'       => $this->banners(),
			'sections'      => array(
				'changelog' => '' !== $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the release page on GitHub.', 'jmwv-updates-manager' ) . '</p>',
			),
		);
	}

	/**
	 * Plugin icon URL. Core prefers the 2x icon over 1x, so only 2x is supplied.
	 *
	 * @return string[]
	 */
	private function icons() {
		return array(
			'2x' => self::IMAGES . 'icon-256x256.png',
		);
	}

	/**
	 * Plugin banner URLs, standard and retina.
	 *
	 * @return string[]
	 */
	private function banners() {
		return array(
			'low'  => self::IMAGES . 'banner-772x250.png',
			'high' => self::IMAGES . 'banner-1544x500.png',
		);
	}

	/**
	 * Rename the extracted GitHub folder (owner-repo-sha) to the plugin slug.
	 *
	 * @param string       $source        Extracted folder path.
	 * @param string       $remote_source Parent of the extracted folder.
	 * @param \WP_Upgrader $upgrader      Upgrader (unused).
	 * @param array        $hook_extra    Upgrade context.
	 * @return string|\WP_Error
	 */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		global $wp_filesystem;

		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';

		if ( trailingslashit( $source ) !== $wanted ) {
			if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ) ) ) {
				return new \WP_Error( 'jmwv_um_rename', __( 'Could not prepare the update package.', 'jmwv-updates-manager' ) );
			}
		}

		// Refuse to install a package that is not this plugin.
		if ( ! $wp_filesystem->exists( $wanted . self::SLUG . '.php' ) ) {
			return new \WP_Error( 'jmwv_um_package', __( 'The update package does not contain this plugin.', 'jmwv-updates-manager' ) );
		}

		return $wanted;
	}

	/**
	 * Fetch the latest published release (drafts and pre-releases are ignored by GitHub).
	 *
	 * @return array|null version, package, url, notes.
	 */
	private function latest_release() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'jmwv-updates-manager',
				),
			)
		);

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['zipball_url'] ) ) {
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		$package = (string) $data['zipball_url'];
		$page    = isset( $data['html_url'] ) ? (string) $data['html_url'] : '';

		// Only trust a plain version number, and a package from this repository's GitHub API.
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) || 0 !== strpos( $package, 'https://api.github.com/repos/' . self::REPO . '/' ) ) {
			return null;
		}

		// Prefer the clean zip attached to the release (no repo-only files such as
		// images). A release without it falls back to the source zipball above.
		$asset_prefix = 'https://github.com/' . self::REPO . '/releases/download/';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'] ) && self::SLUG . '.zip' === $asset['name'] && 0 === strpos( (string) $asset['browser_download_url'], $asset_prefix ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => 0 === strpos( $page, 'https://github.com/' . self::REPO . '/' ) ? $page : 'https://github.com/' . self::REPO,
			'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
		);
	}
}
