<?php
/**
 * Translates a WPML job via Ollama and saves with official WPML APIs.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPML job bridge.
 */
class WPML_Job_Translator {

	/**
	 * Soft char budget per batch request (~generous for MiniMax M3 1M context).
	 * Leaves headroom for system prompt + JSON overhead.
	 */
	const BATCH_CHAR_BUDGET = 700000;

	/**
	 * Translator client.
	 *
	 * @var Translator_Client
	 */
	private $client;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Translator_Client $client Client.
	 * @param Logger            $logger Logger.
	 */
	public function __construct( Translator_Client $client, Logger $logger ) {
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * Translate and optionally complete a WPML job.
	 *
	 * @param int  $job_id   Job ID.
	 * @param bool $complete Mark job complete.
	 * @return true|\WP_Error
	 */
	public function translate_job( $job_id, $complete = true ) {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return new \WP_Error( 'wpai_bad_job', __( 'Job ID inválido.', 'wp-ai-translator' ) );
		}

		if ( ! function_exists( 'wpml_tm_save_data' ) && defined( 'WPML_TM_PATH' ) ) {
			$private = WPML_TM_PATH . '/inc/wpml-private-actions-tm.php';
			if ( file_exists( $private ) ) {
				require_once $private;
			}
		}

		if ( ! function_exists( 'wpml_tm_load_job_factory' ) || ! function_exists( 'wpml_tm_save_data' ) ) {
			return new \WP_Error( 'wpai_no_wpml', __( 'WPML Translation Management no está disponible.', 'wp-ai-translator' ) );
		}

		$job = wpml_tm_load_job_factory()->get_translation_job( $job_id, true );
		if ( ! is_object( $job ) || empty( $job->elements ) || ! is_array( $job->elements ) ) {
			return new \WP_Error( 'wpai_job_missing', __( 'No se pudo cargar el trabajo WPML.', 'wp-ai-translator' ) );
		}

		$source_lang = $this->job_source_lang( $job );
		$target_lang = $this->job_target_lang( $job );

		$fields      = array();
		$to_translate = array();
		$meta         = array();

		foreach ( $job->elements as $element ) {
			if ( empty( $element->field_translate ) ) {
				continue;
			}
			if ( empty( $element->tid ) || empty( $element->field_type ) ) {
				continue;
			}

			$field_type = (string) $element->field_type;
			$source     = $this->decode_field( $element );
			$meta[ $field_type ] = array(
				'tid' => (int) $element->tid,
			);

			if ( '' === trim( (string) $source ) ) {
				$fields[ $field_type ] = array(
					'tid'      => (int) $element->tid,
					'data'     => '',
					'finished' => 1,
				);
				continue;
			}

			$to_translate[ $field_type ] = $source;
		}

		if ( empty( $fields ) && empty( $to_translate ) ) {
			return new \WP_Error( 'wpai_no_fields', __( 'El trabajo no tiene campos traducibles.', 'wp-ai-translator' ) );
		}

		if ( ! empty( $to_translate ) ) {
			$chunks = $this->chunk_items( $to_translate );
			foreach ( $chunks as $chunk ) {
				$translated = $this->client->translate_batch( $chunk, $source_lang, $target_lang );
				if ( is_wp_error( $translated ) ) {
					$this->logger->error(
						$translated->get_error_message(),
						array(
							'job_id'       => $job_id,
							'batch_fields' => count( $chunk ),
						)
					);
					return $translated;
				}
				foreach ( $translated as $field_type => $text ) {
					$fields[ $field_type ] = array(
						'tid'      => isset( $meta[ $field_type ]['tid'] ) ? (int) $meta[ $field_type ]['tid'] : 0,
						'data'     => $this->sanitize_translated_field( (string) $field_type, (string) $text ),
						'finished' => 1,
					);
				}
			}
		}

		$data = array(
			'job_id'   => $job_id,
			'fields'   => $fields,
			'complete' => $complete ? 1 : 0,
		);

		$result = wpml_tm_save_data( $data, false );
		if ( false === $result ) {
			return new \WP_Error( 'wpai_save_failed', __( 'WPML rechazó el guardado de la traducción.', 'wp-ai-translator' ) );
		}

		return true;
	}

	/**
	 * Split items into batches under char budget (M3 can take large payloads).
	 *
	 * @param array $items Items.
	 * @return array[]
	 */
	private function chunk_items( array $items ) {
		$chunks  = array();
		$current = array();
		$size    = 0;

		foreach ( $items as $key => $text ) {
			$len = strlen( (string) $key ) + strlen( (string) $text ) + 16;
			if ( ! empty( $current ) && ( $size + $len ) > self::BATCH_CHAR_BUDGET ) {
				$chunks[] = $current;
				$current  = array();
				$size     = 0;
			}
			$current[ $key ] = $text;
			$size           += $len;
		}
		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * Decode a job element to plain text.
	 *
	 * @param object $element Element.
	 * @return string
	 */
	private function decode_field( $element ) {
		$data   = isset( $element->field_data ) ? $element->field_data : '';
		$format = isset( $element->field_format ) ? $element->field_format : 'base64';

		global $iclTranslationManagement;
		if ( isset( $iclTranslationManagement ) && is_object( $iclTranslationManagement ) ) {
			$decoded = $iclTranslationManagement->decode_field_data( $data, $format );
			if ( is_array( $decoded ) ) {
				return implode( ', ', array_map( 'strval', $decoded ) );
			}
			return is_string( $decoded ) ? $decoded : (string) $decoded;
		}

		if ( 'base64' === $format && is_string( $data ) ) {
			$decoded = base64_decode( $data, true );
			return false !== $decoded ? $decoded : $data;
		}

		return (string) $data;
	}

	/**
	 * Sanitize LLM output before saving into WPML fields.
	 *
	 * @param string $field_type Field type.
	 * @param string $text       Translated text.
	 * @return string
	 */
	private function sanitize_translated_field( $field_type, $text ) {
		$field_type = (string) $field_type;
		$text       = (string) $text;

		if ( in_array( $field_type, array( 'title', 'excerpt' ), true )
			|| ( 0 === strpos( $field_type, 'field-' ) && false !== strpos( $field_type, 'name' ) ) ) {
			return sanitize_text_field( wp_strip_all_tags( $text ) );
		}

		// Plain short custom fields (boletin numbers, etc.).
		if ( 0 === strpos( $field_type, 'field-' ) && false === strpos( $field_type, 'package-string' ) ) {
			$stripped = wp_strip_all_tags( $text );
			if ( strlen( $stripped ) < 200 && $stripped === trim( $text ) ) {
				return sanitize_text_field( $stripped );
			}
		}

		return wp_kses_post( $text );
	}

	/**
	 * Source language from job.
	 *
	 * @param object $job Job.
	 * @return string
	 */
	private function job_source_lang( $job ) {
		if ( ! empty( $job->source_language_code ) ) {
			return (string) $job->source_language_code;
		}
		if ( ! empty( $job->language_code_source ) ) {
			return (string) $job->language_code_source;
		}
		return 'en';
	}

	/**
	 * Target language from job.
	 *
	 * @param object $job Job.
	 * @return string
	 */
	private function job_target_lang( $job ) {
		if ( ! empty( $job->language_code ) ) {
			return (string) $job->language_code;
		}
		return 'es';
	}
}
