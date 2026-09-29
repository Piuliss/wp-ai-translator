<?php
/**
 * URL validation to reduce SSRF risk.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates provider endpoint URLs before HTTP requests.
 */
class Url_Guard {

	/**
	 * Allowed MiniMax API hosts.
	 *
	 * @var string[]
	 */
	const MINIMAX_HOSTS = array(
		'api.minimax.io',
		'api.minimaxi.com',
	);

	/**
	 * Blocked hosts / IPs (cloud metadata, link-local).
	 *
	 * @var string[]
	 */
	const BLOCKED_HOSTS = array(
		'169.254.169.254',
		'metadata.google.internal',
		'metadata.google.com',
		'metadata',
	);

	/**
	 * Validate Ollama generate endpoint.
	 *
	 * @param string $url URL.
	 * @return string|\WP_Error Sanitized URL or error.
	 */
	public static function validate_ollama_endpoint( $url ) {
		return self::validate_generic( $url, false );
	}

	/**
	 * Validate MiniMax base URL (https + allowlist).
	 *
	 * @param string $url URL.
	 * @return string|\WP_Error Sanitized URL or error.
	 */
	public static function validate_minimax_base( $url ) {
		$checked = self::validate_generic( $url, true );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$host = strtolower( (string) wp_parse_url( $checked, PHP_URL_HOST ) );
		if ( ! in_array( $host, self::MINIMAX_HOSTS, true ) ) {
			return new \WP_Error(
				'wpai_url_host',
				sprintf(
					/* translators: %s: comma-separated hosts */
					__( 'Base URL de MiniMax no permitida. Hosts válidos: %s', 'wp-ai-translator' ),
					implode( ', ', self::MINIMAX_HOSTS )
				)
			);
		}

		return $checked;
	}

	/**
	 * Shared URL checks.
	 *
	 * @param string $url          URL.
	 * @param bool   $https_only   Require https.
	 * @return string|\WP_Error
	 */
	private static function validate_generic( $url, $https_only ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new \WP_Error( 'wpai_url_empty', __( 'URL vacía.', 'wp-ai-translator' ) );
		}

		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'wpai_url_invalid', __( 'URL inválida.', 'wp-ai-translator' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'wpai_url_invalid', __( 'URL inválida.', 'wp-ai-translator' ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( $https_only ) {
			if ( 'https' !== $scheme ) {
				return new \WP_Error( 'wpai_url_scheme', __( 'La URL debe usar HTTPS.', 'wp-ai-translator' ) );
			}
		} elseif ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'wpai_url_scheme', __( 'Solo se permiten esquemas http/https.', 'wp-ai-translator' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'wpai_url_creds', __( 'No se permiten credenciales en la URL.', 'wp-ai-translator' ) );
		}

		if ( isset( $parts['port'] ) && ( ! is_numeric( $parts['port'] ) || (int) $parts['port'] < 1 || (int) $parts['port'] > 65535 ) ) {
			return new \WP_Error( 'wpai_url_port', __( 'Puerto inválido en la URL.', 'wp-ai-translator' ) );
		}

		$host = strtolower( (string) $parts['host'] );
		$host = trim( $host, '[]' ); // IPv6 brackets.

		if ( in_array( $host, self::BLOCKED_HOSTS, true ) ) {
			return new \WP_Error( 'wpai_url_blocked', __( 'Host bloqueado por seguridad.', 'wp-ai-translator' ) );
		}

		// Block link-local / metadata ranges by IP.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( self::is_blocked_ip( $host ) ) {
				return new \WP_Error( 'wpai_url_blocked', __( 'IP bloqueada por seguridad.', 'wp-ai-translator' ) );
			}
		}

		return $url;
	}

	/**
	 * Whether an IP is blocked (link-local / metadata).
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private static function is_blocked_ip( $ip ) {
		if ( @inet_pton( $ip ) === false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}
		// 169.254.0.0/16 link-local (includes cloud metadata).
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			if ( false !== $long && ( $long & 0xFFFF0000 ) === 0xA9FE0000 ) {
				return true;
			}
		}
		return false;
	}
}
