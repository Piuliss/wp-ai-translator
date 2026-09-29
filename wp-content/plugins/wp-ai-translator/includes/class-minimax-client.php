<?php
/**
 * MiniMax chat-completions client (OpenAI-compatible).
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls MiniMax /v1/chat/completions.
 */
class Minimax_Client implements Translator_Client {

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
		$items = $this->normalize_items( $items );
		if ( empty( $items ) ) {
			return array();
		}

		if ( Rate_Limit::is_paused() ) {
			return new \WP_Error(
				'wpai_rate_paused',
				sprintf(
					/* translators: %s: local datetime */
					__( 'Cola en pausa por rate limit hasta %s.', 'wp-ai-translator' ),
					wp_date( 'Y-m-d H:i:s', Rate_Limit::paused_until() )
				),
				array( 'until' => Rate_Limit::paused_until() )
			);
		}

		$api_key = (string) $this->settings->get( 'minimax_api_key', '' );
		if ( '' === $api_key ) {
			return new \WP_Error( 'wpai_no_minimax_key', __( 'API key de MiniMax no configurada.', 'wp-ai-translator' ) );
		}

		$base = rtrim( (string) $this->settings->get( 'minimax_base_url', 'https://api.minimax.io/v1' ), '/' );
		$safe = Url_Guard::validate_minimax_base( $base );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		$url         = rtrim( $safe, '/' ) . '/chat/completions';
		$model       = (string) $this->settings->get( 'minimax_model', 'MiniMax-M3' );
		$base_prompt = (string) $this->settings->get( 'prompt', '' );

		$system = Batch_Codec::system_prompt( $source_lang, $target_lang, $base_prompt );
		$user   = Batch_Codec::user_prompt( $items );

		$content = $this->chat( $url, $api_key, $model, $system, $user );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$parsed = Batch_Codec::parse( $content, $items );
		if ( ! is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$retry_user = $user . "\n\nIMPORTANTE: responde solo JSON válido con las mismas claves.";
		$content2   = $this->chat( $url, $api_key, $model, $system, $retry_user );
		if ( is_wp_error( $content2 ) ) {
			return $content2;
		}
		return Batch_Codec::parse( $content2, $items );
	}

	/**
	 * POST chat/completions with rate-limit awareness.
	 *
	 * @param string $url     URL.
	 * @param string $api_key Key.
	 * @param string $model   Model.
	 * @param string $system  System prompt.
	 * @param string $user    User prompt.
	 * @return string|\WP_Error
	 */
	private function chat( $url, $api_key, $model, $system, $user ) {
		$attempts = 0;
		$max      = 4;

		while ( $attempts < $max ) {
			++$attempts;

			if ( Rate_Limit::is_paused() ) {
				return new \WP_Error(
					'wpai_rate_paused',
					Rate_Limit::reason(),
					array( 'until' => Rate_Limit::paused_until() )
				);
			}

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 300,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
					'body'    => wp_json_encode(
						array(
							'model'       => $model,
							'messages'    => array(
								array(
									'role'    => 'system',
									'content' => $system,
								),
								array(
									'role'    => 'user',
									'content' => $user,
								),
							),
							'temperature' => 0.1,
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

			// MiniMax sometimes returns HTTP 200 with base_resp.status_code != 0.
			$provider_code = 0;
			if ( is_array( $data ) && isset( $data['base_resp']['status_code'] ) ) {
				$provider_code = (int) $data['base_resp']['status_code'];
			}

			$rate_hit = ( 429 === $code )
				|| in_array( $provider_code, array( 1002, 1039, 2045, 1041, 2056 ), true );

			if ( $rate_hit ) {
				$handled = Rate_Limit::handle_provider_response( $response, $code, $body );
				if ( is_wp_error( $handled ) ) {
					return $handled;
				}
				// Short inline sleep already done → retry.
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error(
					'wpai_minimax_http',
					sprintf(
						/* translators: %d: HTTP status */
						__( 'MiniMax respondió HTTP %d.', 'wp-ai-translator' ),
						$code
					),
					array( 'body' => substr( $body, 0, 500 ) )
				);
			}

			if ( $provider_code > 0 ) {
				$msg = isset( $data['base_resp']['status_msg'] ) ? (string) $data['base_resp']['status_msg'] : '';
				return new \WP_Error(
					'wpai_minimax_provider',
					sprintf(
						/* translators: 1: code, 2: message */
						__( 'MiniMax error %1$d: %2$s', 'wp-ai-translator' ),
						$provider_code,
						$msg ? $msg : __( 'error de proveedor', 'wp-ai-translator' )
					),
					array( 'body' => substr( $body, 0, 500 ) )
				);
			}

			$content = '';
			if ( is_array( $data ) && isset( $data['choices'][0]['message']['content'] ) && is_string( $data['choices'][0]['message']['content'] ) ) {
				$content = $data['choices'][0]['message']['content'];
			}
			if ( '' === trim( $content ) ) {
				return new \WP_Error(
					'wpai_minimax_bad_response',
					__( 'Respuesta de MiniMax inválida.', 'wp-ai-translator' ),
					array( 'body' => substr( $body, 0, 500 ) )
				);
			}
			return $content;
		}

		return new \WP_Error( 'wpai_minimax_retry', __( 'MiniMax rate limit persistente.', 'wp-ai-translator' ) );
	}

	/**
	 * Keep only non-empty string values.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private function normalize_items( array $items ) {
		$out = array();
		foreach ( $items as $key => $text ) {
			$text = (string) $text;
			if ( '' === trim( $text ) ) {
				continue;
			}
			$out[ (string) $key ] = $text;
		}
		return $out;
	}
}
