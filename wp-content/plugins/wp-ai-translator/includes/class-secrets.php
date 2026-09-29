<?php
/**
 * Encrypt / decrypt secrets stored in options.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses OpenSSL + WordPress salts. Fails closed if OpenSSL is missing.
 */
class Secrets {

	const PREFIX     = 'wpai_enc_v1:';
	const PREFIX_LEGACY = 'wpollama_enc_v1:';

	/**
	 * Whether OpenSSL encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'random_bytes' );
	}

	/**
	 * Encrypt a secret for DB storage.
	 *
	 * @param string $plain Plain text.
	 * @return string Encrypted payload, or empty string on failure.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}

		return self::PREFIX . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		$prefix = '';
		if ( 0 === strpos( $stored, self::PREFIX ) ) {
			$prefix = self::PREFIX;
		} elseif ( 0 === strpos( $stored, self::PREFIX_LEGACY ) ) {
			$prefix = self::PREFIX_LEGACY;
		} else {
			// Reject legacy plaintext and unknown formats.
			return '';
		}

		$payload = substr( $stored, strlen( $prefix ) );
		if ( 0 === strpos( $payload, 'b64:' ) ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Mask a secret for UI display.
	 *
	 * @param string $plain Plain secret.
	 * @return string
	 */
	public static function mask( $plain ) {
		$plain = (string) $plain;
		$len   = strlen( $plain );
		if ( $len <= 4 ) {
			return $len ? str_repeat( '•', $len ) : '';
		}
		return str_repeat( '•', max( 8, $len - 4 ) ) . substr( $plain, -4 );
	}

	/**
	 * Derive encryption key from WP salts.
	 *
	 * @return string
	 */
	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'wpai' );
		return hash( 'sha256', $material, true );
	}
}
