<?php
/**
 * Translates All in One SEO fields after a WPML job completes.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies / translates AIOSEO meta from source post to translation.
 */
class AIOSEO_Translator {

	const META_SOURCE_HASH = '_wpai_seo_source_hash';

	/**
	 * Text fields to translate.
	 *
	 * @var string[]
	 */
	const TEXT_FIELDS = array(
		'title',
		'description',
		'og_title',
		'og_description',
		'twitter_title',
		'twitter_description',
	);

	/**
	 * Image / social fields copied without translation.
	 *
	 * @var string[]
	 */
	const IMAGE_FIELDS = array(
		'og_image_type',
		'og_image_custom_url',
		'twitter_use_og',
		'twitter_image_type',
		'twitter_image_custom_url',
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
	 * @param Settings          $settings Settings.
	 * @param Translator_Client $client   Client.
	 * @param Logger            $logger   Logger.
	 */
	public function __construct( Settings $settings, Translator_Client $client, Logger $logger ) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'wpai_job_translated', array( $this, 'on_job_translated' ), 10, 2 );
	}

	/**
	 * After a content job is saved as complete.
	 *
	 * @param int    $job_id Job ID.
	 * @param object $job    WPML job object.
	 */
	public function on_job_translated( $job_id, $job ) {
		try {
			if ( ! $this->settings->get( 'translate_aioseo', true ) ) {
				return;
			}
			if ( ! $this->aioseo_available() ) {
				return;
			}

			$ids = $this->resolve_post_ids( $job );
			if ( empty( $ids['original'] ) || empty( $ids['translated'] ) ) {
				$this->logger->info(
					__( 'AIOSEO: no se resolvieron IDs de post tras el job.', 'wp-ai-translator' ),
					array( 'job_id' => (int) $job_id )
				);
				return;
			}

			$source_lang = $this->job_source_lang( $job );
			$target_lang = $this->job_target_lang( $job );

			$result = $this->translate_post_seo(
				(int) $ids['original'],
				(int) $ids['translated'],
				$source_lang,
				$target_lang,
				false
			);

			if ( is_wp_error( $result ) ) {
				$this->logger->error(
					$result->get_error_message(),
					array(
						'job_id'        => (int) $job_id,
						'original_id'   => (int) $ids['original'],
						'translated_id' => (int) $ids['translated'],
						'code'          => $result->get_error_code(),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->logger->error(
				sprintf(
					/* translators: %s: exception message */
					__( 'AIOSEO: excepción al traducir SEO: %s', 'wp-ai-translator' ),
					$e->getMessage()
				),
				array( 'job_id' => (int) $job_id )
			);
		}
	}

	/**
	 * Translate SEO fields from source post onto translated post.
	 *
	 * @param int    $original_id   Source post ID.
	 * @param int    $translated_id Target post ID.
	 * @param string $source_lang   Source language.
	 * @param string $target_lang   Target language.
	 * @param bool   $dry_run       If true, do not write.
	 * @return true|array|\WP_Error True on write, array summary on dry-run / skip, WP_Error on failure.
	 */
	public function translate_post_seo( $original_id, $translated_id, $source_lang, $target_lang, $dry_run = false ) {
		$original_id   = (int) $original_id;
		$translated_id = (int) $translated_id;

		if ( $original_id <= 0 || $translated_id <= 0 || $original_id === $translated_id ) {
			return new \WP_Error( 'wpai_seo_bad_ids', __( 'IDs de post inválidos para SEO.', 'wp-ai-translator' ) );
		}

		if ( ! $this->aioseo_available() ) {
			return array(
				'status' => 'skipped',
				'reason' => 'aioseo_inactive',
			);
		}

		$source = \AIOSEO\Plugin\Common\Models\Post::getPost( $original_id );
		if ( ! $source || ! $source->exists() ) {
			return array(
				'status' => 'skipped',
				'reason' => 'no_source_row',
			);
		}

		$source_texts = $this->collect_text_fields( $source );
		if ( empty( $source_texts ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'no_custom_seo',
			);
		}

		$source_hash = $this->hash_fields( $source_texts );
		$stored_hash = (string) get_post_meta( $translated_id, self::META_SOURCE_HASH, true );

		if ( '' !== $stored_hash && hash_equals( $stored_hash, $source_hash ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'already_up_to_date',
				'hash'   => $source_hash,
			);
		}

		$target = \AIOSEO\Plugin\Common\Models\Post::getPost( $translated_id );
		if ( ! $this->may_overwrite( $target, $source_texts, $stored_hash ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'manual_seo',
			);
		}

		if ( $dry_run ) {
			return array(
				'status'         => 'would_translate',
				'original_id'    => $original_id,
				'translated_id'  => $translated_id,
				'fields'         => array_keys( $source_texts ),
				'hash'           => $source_hash,
			);
		}

		if ( Rate_Limit::is_paused() ) {
			return new \WP_Error(
				'wpai_rate_paused',
				sprintf(
					/* translators: %s: datetime */
					__( 'Cola en pausa por rate limit hasta %s.', 'wp-ai-translator' ),
					wp_date( 'Y-m-d H:i:s', Rate_Limit::paused_until() )
				)
			);
		}

		$extra = $this->seo_system_rules();
		$filter = static function ( $prompt ) use ( $extra ) {
			return (string) $prompt . $extra;
		};
		add_filter( 'wpai_batch_system_prompt', $filter );

		$translated = $this->client->translate_batch( $source_texts, $source_lang, $target_lang );

		remove_filter( 'wpai_batch_system_prompt', $filter );

		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		foreach ( $source_texts as $key => $original_text ) {
			$value = isset( $translated[ $key ] ) ? (string) $translated[ $key ] : '';
			$target->{$key} = $this->sanitize_seo_text( $key, $value, (string) $original_text );
		}

		foreach ( self::IMAGE_FIELDS as $field ) {
			if ( isset( $source->{$field} ) ) {
				$target->{$field} = $source->{$field};
			}
		}

		$target->save();
		$this->bust_aioseo_cache( $translated_id, $target );
		update_post_meta( $translated_id, self::META_SOURCE_HASH, $source_hash );

		$this->logger->info(
			__( 'AIOSEO: campos SEO traducidos.', 'wp-ai-translator' ),
			array(
				'original_id'   => $original_id,
				'translated_id' => $translated_id,
				'fields'        => array_keys( $source_texts ),
			)
		);

		return true;
	}

	/**
	 * Whether AIOSEO is loaded and usable.
	 *
	 * @return bool
	 */
	public function aioseo_available() {
		return function_exists( 'aioseo' )
			&& class_exists( '\AIOSEO\Plugin\Common\Models\Post' );
	}

	/**
	 * Resolve original and translated post IDs from a WPML job.
	 *
	 * @param object $job Job.
	 * @return array{original:int,translated:int}
	 */
	public function resolve_post_ids( $job ) {
		$original = 0;
		if ( is_object( $job ) && ! empty( $job->original_doc_id ) ) {
			$original = (int) $job->original_doc_id;
		}

		$target_lang = $this->job_target_lang( $job );
		$translated  = 0;

		if ( $original > 0 && '' !== $target_lang ) {
			$post_type = get_post_type( $original );
			if ( $post_type ) {
				$translated = (int) apply_filters( 'wpml_object_id', $original, $post_type, false, $target_lang );
			}
			if ( $translated <= 0 ) {
				$trid = apply_filters( 'wpml_element_trid', null, $original, 'post_' . $post_type );
				if ( $trid ) {
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type );
					if ( is_array( $translations ) && isset( $translations[ $target_lang ]->element_id ) ) {
						$translated = (int) $translations[ $target_lang ]->element_id;
					}
				}
			}
		}

		return array(
			'original'    => $original,
			'translated'  => $translated,
		);
	}

	/**
	 * Collect non-empty SEO text fields.
	 *
	 * @param object $post AIOSEO post model.
	 * @return array<string,string>
	 */
	private function collect_text_fields( $post ) {
		$out = array();
		foreach ( self::TEXT_FIELDS as $field ) {
			$value = isset( $post->{$field} ) ? trim( (string) $post->{$field} ) : '';
			if ( '' !== $value ) {
				$out[ $field ] = (string) $post->{$field};
			}
		}
		return $out;
	}

	/**
	 * Hash of source SEO values we intend to translate.
	 *
	 * @param array<string,string> $fields Fields.
	 * @return string
	 */
	private function hash_fields( array $fields ) {
		ksort( $fields );
		return hash( 'sha256', wp_json_encode( $fields ) );
	}

	/**
	 * Whether we may overwrite the translated post's SEO.
	 *
	 * @param object               $target       Target AIOSEO model.
	 * @param array<string,string> $source_texts Source texts.
	 * @param string               $stored_hash  Previously stored source hash.
	 * @return bool
	 */
	private function may_overwrite( $target, array $source_texts, $stored_hash ) {
		// Previously managed by this plugin.
		if ( '' !== (string) $stored_hash ) {
			return true;
		}

		$target_texts = $this->collect_text_fields( $target );
		if ( empty( $target_texts ) ) {
			return true;
		}

		// Same as source → WPML/copy leftover, not a manual EN edit.
		foreach ( $source_texts as $key => $value ) {
			$current = isset( $target_texts[ $key ] ) ? (string) $target_texts[ $key ] : '';
			if ( $current !== (string) $value ) {
				return false;
			}
		}

		// Target only has a subset equal to source, or exact copy.
		foreach ( $target_texts as $key => $value ) {
			if ( ! isset( $source_texts[ $key ] ) || (string) $source_texts[ $key ] !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extra system rules for SEO batches.
	 *
	 * @return string
	 */
	private function seo_system_rules() {
		return "\n\nReglas SEO (AIOSEO):\n"
			. "- Conserva intactos CVE (p. ej. CVE-2024-1234), números de versión, puntuaciones CVSS, nombres de productos y marcas.\n"
			. "- Conserva intactas las etiquetas/smart tags de AIOSEO como #post_title, #separator_sa, #site_title, etc.\n"
			. "- No traduzcas URLs ni nombres de archivos de imagen.\n"
			. "- Mantén la longitud razonable para title/description (no alargues innecesariamente).";
	}

	/**
	 * Sanitize a translated SEO string.
	 *
	 * @param string $field  Field name.
	 * @param string $text   Translated text.
	 * @param string $source Source text.
	 * @return string
	 */
	private function sanitize_seo_text( $field, $text, $source ) {
		$text = Response_Cleaner::clean( (string) $text, (string) $source );
		$text = wp_strip_all_tags( $text );
		$text = sanitize_text_field( $text );
		// Preserve intentional #smart_tags that sanitize_text_field keeps.
		unset( $field );
		return $text;
	}

	/**
	 * Clear AIOSEO in-memory meta cache for a post when possible.
	 *
	 * @param int         $post_id Post ID.
	 * @param object|null $model   Optional model to seed cache.
	 */
	private function bust_aioseo_cache( $post_id, $model = null ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}
		try {
			$aioseo = aioseo();
			if ( isset( $aioseo->meta->metaData ) && method_exists( $aioseo->meta->metaData, 'bustPostCache' ) ) {
				$aioseo->meta->metaData->bustPostCache( (int) $post_id, $model );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Best-effort cache bust; ignore.
		}
	}

	/**
	 * Source language from job.
	 *
	 * @param object $job Job.
	 * @return string
	 */
	private function job_source_lang( $job ) {
		if ( is_object( $job ) && ! empty( $job->source_language_code ) ) {
			return (string) $job->source_language_code;
		}
		if ( is_object( $job ) && ! empty( $job->language_code_source ) ) {
			return (string) $job->language_code_source;
		}
		return 'es';
	}

	/**
	 * Target language from job.
	 *
	 * @param object $job Job.
	 * @return string
	 */
	private function job_target_lang( $job ) {
		if ( is_object( $job ) && ! empty( $job->language_code ) ) {
			return (string) $job->language_code;
		}
		return 'en';
	}
}
