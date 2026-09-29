<?php
/**
 * HTTP client for Ollama.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls the Ollama generate API.
 */
class Ollama_Client implements Translator_Client {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function translate( $text, $source_lang, $target_lang ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$batch = $this->translate_batch( array( '_t' => $text ), $source_lang, $target_lang );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		return isset( $batch['_t'] ) ? $batch['_t'] : '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function translate_batch( array $items, $source_lang, $target_lang ) {
		$normalized = array();
		foreach ( $items as $key => $text ) {
			$text = (string) $text;
			if ( '' === trim( $text ) ) {
				continue;
			}
			$normalized[ (string) $key ] = $text;
		}
		$items = $normalized;
		if ( empty( $items ) ) {
			return array();
		}

		$endpoint = (string) $this->settings->get( 'endpoint', '' );
		if ( '' === $endpoint ) {
			return new \WP_Error( 'wpai_no_endpoint', __( 'Endpoint de Ollama no configurado.', 'wp-ai-translator' ) );
		}
		$safe_endpoint = Url_Guard::validate_ollama_endpoint( $endpoint );
		if ( is_wp_error( $safe_endpoint ) ) {
			return $safe_endpoint;
		}
		$endpoint = $safe_endpoint;

		$model  = (string) $this->settings->get( 'model', 'llama3' );
		$prompt = (string) $this->settings->get( 'prompt', '' );
		$user   = (string) $this->settings->get( 'user', '' );
		$pass   = (string) $this->settings->get( 'password', '' );

		$system = Batch_Codec::system_prompt( $source_lang, $target_lang, $prompt );
		$full   = $system . "\n\n" . Batch_Codec::user_prompt( $items );

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $user && '' !== $pass ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $user . ':' . $pass );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 300,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'model'  => $model,
						'prompt' => $full,
						'stream' => false,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'wpai_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Ollama respondió HTTP %d.', 'wp-ai-translator' ),
					$code
				),
				array( 'body' => substr( $body, 0, 500 ) )
			);
		}

		if ( ! is_array( $data ) || empty( $data['response'] ) || ! is_string( $data['response'] ) ) {
			return new \WP_Error(
				'wpai_bad_response',
				__( 'Respuesta de Ollama inválida.', 'wp-ai-translator' ),
				array( 'body' => substr( $body, 0, 500 ) )
			);
		}

		return Batch_Codec::parse( $data['response'], $items );
	}
}
