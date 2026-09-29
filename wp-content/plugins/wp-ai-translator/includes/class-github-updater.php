<?php
/**
 * Self-updates from GitHub Releases.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into WordPress plugin update checks.
 */
class GitHub_Updater {

	const REPO           = 'Piuliss/wp-ai-translator';
	const SLUG           = 'wp-ai-translator';
	const TRANSIENT      = 'wpai_gh_release';
	const CACHE_TTL      = 21600; // 6 hours.
	const API_RELEASES   = 'https://api.github.com/repos/Piuliss/wp-ai-translator/releases/latest';

	/**
	 * Register filters.
	 */
	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Inject update info when a newer GitHub release exists.
	 *
	 * @param object $transient Transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( empty( $release ) || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( WPAI_VERSION, $release['version'], '>=' ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( WPAI_PLUGIN_FILE );
		$transient->response[ $plugin_file ] = (object) array(
			'slug'        => self::SLUG,
			'plugin'      => $plugin_file,
			'new_version' => $release['version'],
			'url'         => $release['html_url'],
			'package'     => $release['package'],
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => '',
			'requires'    => '6.0',
			'requires_php'=> '8.0',
		);

		return $transient;
	}

	/**
	 * Plugin details modal.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		$version = ! empty( $release['version'] ) ? $release['version'] : WPAI_VERSION;
		$body    = ! empty( $release['body'] ) ? $release['body'] : '';

		return (object) array(
			'name'          => 'Traducción AI (WPML)',
			'slug'          => self::SLUG,
			'version'       => $version,
			'author'        => '<a href="mailto:raulbeni@gmail.com">Raúl Benitez Netto</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'downloaded'    => 0,
			'last_updated'  => ! empty( $release['published_at'] ) ? $release['published_at'] : '',
			'sections'      => array(
				'description' => __( 'Motor de traducción (Ollama / MiniMax) para WordPress y WPML.', 'wp-ai-translator' ),
				'changelog'   => $body ? wp_kses_post( nl2br( esc_html( $body ) ) ) : '',
			),
			'download_link' => ! empty( $release['package'] ) ? $release['package'] : '',
		);
	}

	/**
	 * Clear cached release after update.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $options  Options.
	 */
	public function clear_cache( $upgrader, $options ) {
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Fetch and cache latest GitHub release.
	 *
	 * @return array{version?:string,package?:string,html_url?:string,body?:string,published_at?:string}
	 */
	private function latest_release() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::API_RELEASES,
			array(
				'timeout' => 12,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wp-ai-translator/' . WPAI_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS );
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			set_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS );
			return array();
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS );
			return array();
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		$package = $this->pick_zip_url( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() );
		if ( ! $package && ! empty( $data['zipball_url'] ) ) {
			// zipball extracts as Piuliss-wp-ai-translator-<sha>/ — not usable for WP updates.
			$package = '';
		}

		$out = array(
			'version'      => $version,
			'package'      => $package,
			'html_url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : ( 'https://github.com/' . self::REPO ),
			'body'         => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);

		set_transient( self::TRANSIENT, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Prefer wp-ai-translator.zip, else first .zip asset.
	 *
	 * @param array $assets GitHub assets.
	 * @return string
	 */
	private function pick_zip_url( array $assets ) {
		$fallback = '';
		foreach ( $assets as $asset ) {
			if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
				continue;
			}
			$name = (string) $asset['name'];
			$url  = (string) $asset['browser_download_url'];
			if ( ! preg_match( '/\.zip$/i', $name ) ) {
				continue;
			}
			if ( 'wp-ai-translator.zip' === $name ) {
				return $url;
			}
			if ( preg_match( '/^wp-ai-translator-/i', $name ) && ! $fallback ) {
				$fallback = $url;
			}
			if ( ! $fallback ) {
				$fallback = $url;
			}
		}
		return $fallback;
	}
}
