<?php
/**
 * Settings for Traducción AI (GUI-only, no .env).
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes plugin options from the WordPress admin UI.
 * Secrets are stored encrypted in the options table.
 */
class Settings {

	const OPTION_NAME  = 'wpai_settings';
	const OPTION_GROUP = 'wpai_settings';

	const PROVIDER_OLLAMA  = 'ollama';
	const PROVIDER_MINIMAX = 'minimax';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Get merged settings (secrets decrypted).
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$defaults = $this->defaults();
		$options  = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$settings             = wp_parse_args( $options, $defaults );
		$settings['provider'] = $this->normalize_provider( isset( $settings['provider'] ) ? $settings['provider'] : self::PROVIDER_OLLAMA );

		$settings['user']     = Secrets::decrypt( isset( $options['ollama_user_enc'] ) ? (string) $options['ollama_user_enc'] : '' );
		$settings['password'] = Secrets::decrypt( isset( $options['ollama_password_enc'] ) ? (string) $options['ollama_password_enc'] : '' );

		$minimax_enc                     = isset( $options['minimax_api_key_enc'] ) ? (string) $options['minimax_api_key_enc'] : '';
		$settings['minimax_api_key']     = Secrets::decrypt( $minimax_enc );
		$settings['minimax_api_key_set'] = '' !== $minimax_enc && '' !== $settings['minimax_api_key'];
		$settings['ollama_user_set']     = ! empty( $options['ollama_user_enc'] ) && '' !== $settings['user'];
		$settings['ollama_password_set'] = ! empty( $options['ollama_password_enc'] ) && '' !== $settings['password'];

		$settings['wpml_engine']                 = ! empty( $settings['wpml_engine'] );
		$settings['enable_public_lang_preview']  = ! empty( $settings['enable_public_lang_preview'] );

		$this->cache = $settings;
		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default if missing.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Active provider slug.
	 *
	 * @return string
	 */
	public function get_provider() {
		return $this->normalize_provider( $this->get( 'provider', self::PROVIDER_OLLAMA ) );
	}

	/**
	 * Whether the WPML engine bridge is enabled and provider is ready.
	 *
	 * @return bool
	 */
	public function is_wpml_engine_enabled() {
		if ( ! $this->get( 'wpml_engine', false ) ) {
			return false;
		}
		return $this->is_provider_ready( $this->get_provider() );
	}

	/**
	 * Whether a provider has enough config to run.
	 *
	 * @param string $provider Provider.
	 * @return bool
	 */
	public function is_provider_ready( $provider ) {
		$provider = $this->normalize_provider( $provider );
		if ( self::PROVIDER_MINIMAX === $provider ) {
			return '' !== (string) $this->get( 'minimax_api_key', '' );
		}
		$endpoint = (string) $this->get( 'endpoint', '' );
		if ( '' === $endpoint ) {
			return false;
		}
		return ! is_wp_error( Url_Guard::validate_ollama_endpoint( $endpoint ) );
	}

	/**
	 * Clear in-memory cache (after save).
	 */
	public function flush() {
		$this->cache = null;
	}

	/**
	 * Sanitize settings from admin form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$this->flush();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$provider = $this->normalize_provider( isset( $input['provider'] ) ? $input['provider'] : self::PROVIDER_OLLAMA );

		$endpoint_raw = isset( $input['endpoint'] ) ? trim( (string) $input['endpoint'] ) : ( $current['endpoint'] ?? '' );
		$endpoint     = '';
		if ( '' !== $endpoint_raw ) {
			$checked = Url_Guard::validate_ollama_endpoint( $endpoint_raw );
			if ( is_wp_error( $checked ) ) {
				add_settings_error(
					self::OPTION_NAME,
					'wpai_endpoint',
					$checked->get_error_message(),
					'error'
				);
				$endpoint = isset( $current['endpoint'] ) ? (string) $current['endpoint'] : '';
			} else {
				$endpoint = $checked;
			}
		}

		$model  = isset( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : ( $current['model'] ?? 'llama3' );
		$prompt = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : ( $current['prompt'] ?? '' );

		$minimax_base_raw = isset( $input['minimax_base_url'] )
			? trim( (string) $input['minimax_base_url'] )
			: ( $current['minimax_base_url'] ?? 'https://api.minimax.io/v1' );
		$minimax_base     = 'https://api.minimax.io/v1';
		if ( '' !== $minimax_base_raw ) {
			$checked_mm = Url_Guard::validate_minimax_base( $minimax_base_raw );
			if ( is_wp_error( $checked_mm ) ) {
				add_settings_error(
					self::OPTION_NAME,
					'wpai_minimax_base',
					$checked_mm->get_error_message(),
					'error'
				);
				$minimax_base = isset( $current['minimax_base_url'] ) ? (string) $current['minimax_base_url'] : 'https://api.minimax.io/v1';
			} else {
				$minimax_base = $checked_mm;
			}
		}

		$minimax_model = isset( $input['minimax_model'] )
			? sanitize_text_field( (string) $input['minimax_model'] )
			: ( $current['minimax_model'] ?? 'MiniMax-M3' );

		if ( ! Secrets::is_available() ) {
			add_settings_error(
				self::OPTION_NAME,
				'wpai_openssl',
				__( 'OpenSSL no está disponible: no se pueden guardar secretos cifrados.', 'wp-ai-translator' ),
				'error'
			);
		}

		return array(
			'provider'                   => $provider,
			'endpoint'                   => $endpoint,
			'model'                      => $model,
			'prompt'                     => $prompt,
			'wpml_engine'                => ! empty( $input['wpml_engine'] ),
			'enable_public_lang_preview' => ! empty( $input['enable_public_lang_preview'] ),
			'minimax_base_url'           => rtrim( $minimax_base, '/' ),
			'minimax_model'              => $minimax_model,
			'minimax_api_key_enc'        => $this->sanitize_secret_field( $input, $current, 'minimax_api_key', 'minimax_api_key_enc', 'minimax_api_key_clear' ),
			'ollama_user_enc'            => $this->sanitize_secret_field( $input, $current, 'ollama_user', 'ollama_user_enc', 'ollama_user_clear' ),
			'ollama_password_enc'        => $this->sanitize_secret_field( $input, $current, 'ollama_password', 'ollama_password_enc', 'ollama_password_clear' ),
		);
	}

	/**
	 * Update or keep an encrypted secret from form input.
	 *
	 * @param array  $input       Form input.
	 * @param array  $current     Current option.
	 * @param string $plain_key   Plain field name.
	 * @param string $enc_key     Encrypted option key.
	 * @param string $clear_key   Clear checkbox name.
	 * @return string
	 */
	private function sanitize_secret_field( array $input, array $current, $plain_key, $enc_key, $clear_key ) {
		$existing = isset( $current[ $enc_key ] ) ? (string) $current[ $enc_key ] : '';
		if ( ! empty( $input[ $clear_key ] ) ) {
			return '';
		}
		if ( ! isset( $input[ $plain_key ] ) ) {
			return $existing;
		}
		$plain = trim( (string) $input[ $plain_key ] );
		if ( '' === $plain ) {
			return $existing;
		}
		if ( false !== strpos( $plain, '•' ) ) {
			return $existing;
		}
		if ( ! Secrets::is_available() ) {
			return $existing;
		}
		$encrypted = Secrets::encrypt( $plain );
		return '' !== $encrypted ? $encrypted : $existing;
	}

	/**
	 * Default settings (static; no host env).
	 *
	 * @return array
	 */
	private function defaults() {
		return array(
			'provider'                   => self::PROVIDER_OLLAMA,
			'endpoint'                   => '',
			'model'                      => 'llama3',
			'prompt'                     => 'Traduce el siguiente texto manteniendo el HTML y el formato, sin agregar comentarios adicionales. Devuelve solo el texto traducido.',
			'user'                       => '',
			'password'                   => '',
			'wpml_engine'                => false,
			'enable_public_lang_preview' => false,
			'minimax_base_url'           => 'https://api.minimax.io/v1',
			'minimax_model'              => 'MiniMax-M3',
			'minimax_api_key'            => '',
			'minimax_api_key_enc'        => '',
			'ollama_user_enc'            => '',
			'ollama_password_enc'        => '',
		);
	}

	/**
	 * Normalize provider slug.
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	private function normalize_provider( $provider ) {
		$provider = strtolower( trim( (string) $provider ) );
		if ( self::PROVIDER_MINIMAX === $provider ) {
			return self::PROVIDER_MINIMAX;
		}
		return self::PROVIDER_OLLAMA;
	}
}
