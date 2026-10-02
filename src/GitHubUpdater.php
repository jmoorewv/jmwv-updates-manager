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

		// Single source of truth for the requirements and the description: the plugin header.
		$header = get_file_data(
			JMWV_UM_FILE,
			array(
				'description'  => 'Description',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
			)
		);

		$info = array(
			'name'          => 'JMWV Updates Manager',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://jmoorewv.com">Jonathan Moore</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $header['requires'],
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'icons'         => $this->icons(),
			'banners'       => $this->banners(),
			'sections'      => array(
				'description'  => '<p>' . esc_html( $header['description'] ) . '</p>',
				'installation' => '<ol><li>'
					. sprintf(
						/* translators: %s: link to the latest GitHub release. */
						esc_html__( 'Download jmwv-updates-manager.zip from the %s.', 'jmwv-updates-manager' ),
						'<a href="https://github.com/' . self::REPO . '/releases/latest">' . esc_html__( 'latest release', 'jmwv-updates-manager' ) . '</a>'
					)
					. '</li><li>' . esc_html__( 'In WordPress, go to Plugins > Add New > Upload Plugin, choose the zip and install it.', 'jmwv-updates-manager' )
					. '</li><li>' . esc_html__( 'Activate the plugin and open Settings > Updates Manager.', 'jmwv-updates-manager' ) . '</li></ol>',
				'changelog'    => '' !== $release['notes'] ? $this->markdown( $release['notes'] ) : '<p>' . esc_html__( 'See the release page on GitHub.', 'jmwv-updates-manager' ) . '</p>',
			),
		);

		if ( '' !== $release['published'] ) {
			$info['last_updated'] = $release['published'];
		}

		return (object) $info;
	}

	/**
	 * Turn GitHub release notes into safe HTML: headings, bullet lists, bold and inline code.
	 *
	 * Everything is escaped first; only these few tags are ever added.
	 *
	 * @param string $text Markdown release notes.
	 * @return string
	 */
	private function markdown( $text ) {
		$out  = '';
		$list = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line    = trim( $line );
			$is_item = (bool) preg_match( '/^[-*]\s+(.+)$/', $line, $item );

			if ( $list && ! $is_item ) {
				$out .= '</ul>';
				$list = false;
			}

			if ( $is_item ) {
				if ( ! $list ) {
					$out .= '<ul>';
					$list = true;
				}
				$out .= '<li>' . $this->inline( $item[1] ) . '</li>';
			} elseif ( preg_match( '/^#{1,6}\s+(.+)$/', $line, $heading ) ) {
				$out .= '<h4>' . $this->inline( $heading[1] ) . '</h4>';
			} elseif ( '' !== $line ) {
				$out .= '<p>' . $this->inline( $line ) . '</p>';
			}
		}

		return $list ? $out . '</ul>' : $out;
	}

	/**
	 * Escape a line of text, then add bold and inline code.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function inline( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

		return preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
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
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = ltrim( $this->text( $data, 'tag_name' ), 'vV' );
		$package = $this->text( $data, 'zipball_url' );
		$page    = $this->text( $data, 'html_url' );
		$repo    = preg_quote( self::REPO, '#' );

		// Only trust a plain version number, and a source package that is exactly this
		// repository's zipball for a tag. The patterns are anchored, so "../" and
		// look-alike hosts cannot slip through a prefix check.
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) || ! preg_match( '#^https://api\.github\.com/repos/' . $repo . '/zipball/[A-Za-z0-9][\w.-]*$#', $package ) ) {
			return null;
		}

		// Prefer the clean zip attached to the release (no repo-only files such as
		// images). A release without it falls back to the source zipball above.
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( ! is_array( $asset ) || self::SLUG . '.zip' !== $this->text( $asset, 'name' ) ) {
					continue;
				}

				$url = $this->text( $asset, 'browser_download_url' );
				if ( preg_match( '#^https://github\.com/' . $repo . '/releases/download/[A-Za-z0-9][\w.-]*/' . preg_quote( self::SLUG, '#' ) . '\.zip$#', $url ) ) {
					$package = $url;
					break;
				}
			}
		}

		$published = strtotime( $this->text( $data, 'published_at' ) );

		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => preg_match( '#^https://github\.com/' . $repo . '/releases/tag/[A-Za-z0-9][\w.-]*$#', $page ) ? $page : 'https://github.com/' . self::REPO,
			'notes'     => $this->text( $data, 'body' ),
			'published' => $published ? gmdate( 'Y-m-d g:ia', $published ) . ' GMT' : '',
		);
	}

	/**
	 * A string value from decoded JSON, or '' if it is missing or not a string.
	 *
	 * @param array  $data Decoded JSON object.
	 * @param string $key  Key to read.
	 * @return string
	 */
	private function text( array $data, $key ) {
		return ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) ? $data[ $key ] : '';
	}
}
