<?php
/**
 * WP-CLI commands for Traducción AI.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wpai …` commands.
 */
class CLI {

	/**
	 * AIOSEO translator.
	 *
	 * @var AIOSEO_Translator
	 */
	private $aioseo;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param AIOSEO_Translator $aioseo   AIOSEO helper.
	 * @param Settings          $settings Settings.
	 * @param Logger            $logger   Logger.
	 */
	public function __construct( AIOSEO_Translator $aioseo, Settings $settings, Logger $logger ) {
		$this->aioseo   = $aioseo;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register with WP-CLI.
	 */
	public function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'wpai seo-backfill', array( $this, 'seo_backfill' ) );
	}

	/**
	 * Backfill AIOSEO SEO fields for already-translated posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to scan (default: publicacion). Use "any" for all public types.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated source (default-language) post IDs. When set, post_type is ignored for filtering.
	 *
	 * [--source_lang=<code>]
	 * : Source language code (default: site default / es).
	 *
	 * [--target_lang=<code>]
	 * : Target language code (default: en).
	 *
	 * [--dry-run]
	 * : Report what would be done without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpai seo-backfill --ids=12199 --dry-run
	 *     wp wpai seo-backfill --post_type=publicacion
	 *     wp wpai seo-backfill --post_type=post --target_lang=en
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function seo_backfill( $args, $assoc_args ) {
		unset( $args );

		if ( ! $this->settings->get( 'translate_aioseo', true ) ) {
			\WP_CLI::warning( __( 'La opción «Traducir campos SEO de AIOSEO» está desactivada; se continúa de todos modos desde CLI.', 'wp-ai-translator' ) );
		}

		if ( ! $this->aioseo->aioseo_available() ) {
			\WP_CLI::error( __( 'AIOSEO no está activo.', 'wp-ai-translator' ) );
		}

		$dry_run     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$source_lang = isset( $assoc_args['source_lang'] ) ? (string) $assoc_args['source_lang'] : $this->default_language();
		$target_lang = isset( $assoc_args['target_lang'] ) ? (string) $assoc_args['target_lang'] : 'en';
		$post_type   = isset( $assoc_args['post_type'] ) ? (string) $assoc_args['post_type'] : 'publicacion';

		$ids = array();
		if ( ! empty( $assoc_args['ids'] ) ) {
			$ids = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', preg_split( '/\s*,\s*/', (string) $assoc_args['ids'] ) )
					)
				)
			);
		} else {
			$ids = $this->query_source_ids( $post_type, $source_lang );
		}

		if ( empty( $ids ) ) {
			\WP_CLI::warning( __( 'No hay posts para procesar.', 'wp-ai-translator' ) );
			return;
		}

		$ok      = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $ids as $original_id ) {
			$translated_id = $this->translated_id( $original_id, $target_lang );
			if ( $translated_id <= 0 || $translated_id === $original_id ) {
				\WP_CLI::log( sprintf( '[%d] skip: sin traducción %s', $original_id, $target_lang ) );
				++$skipped;
				continue;
			}

			$result = $this->aioseo->translate_post_seo(
				$original_id,
				$translated_id,
				$source_lang,
				$target_lang,
				(bool) $dry_run
			);

			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning(
					sprintf(
						'[%d → %d] error: %s',
						$original_id,
						$translated_id,
						$result->get_error_message()
					)
				);
				$this->logger->error(
					$result->get_error_message(),
					array(
						'original_id'   => $original_id,
						'translated_id' => $translated_id,
						'cli'           => 'seo-backfill',
					)
				);
				++$failed;
				continue;
			}

			if ( true === $result ) {
				\WP_CLI::log( sprintf( '[%d → %d] translated', $original_id, $translated_id ) );
				++$ok;
				continue;
			}

			$status = is_array( $result ) && isset( $result['status'] ) ? (string) $result['status'] : 'unknown';
			$reason = is_array( $result ) && isset( $result['reason'] ) ? (string) $result['reason'] : '';
			$fields = is_array( $result ) && ! empty( $result['fields'] ) ? implode( ',', $result['fields'] ) : '';

			\WP_CLI::log(
				sprintf(
					'[%d → %d] %s%s%s',
					$original_id,
					$translated_id,
					$status,
					$reason ? " ($reason)" : '',
					$fields ? " fields=$fields" : ''
				)
			);

			if ( 'would_translate' === $status ) {
				++$ok;
			} else {
				++$skipped;
			}
		}

		\WP_CLI::success(
			sprintf(
				'done. ok=%d skipped=%d failed=%d dry_run=%s',
				$ok,
				$skipped,
				$failed,
				$dry_run ? 'yes' : 'no'
			)
		);
	}

	/**
	 * Default WPML language code.
	 *
	 * @return string
	 */
	private function default_language() {
		$code = apply_filters( 'wpml_default_language', null );
		if ( is_string( $code ) && '' !== $code ) {
			return $code;
		}
		return 'es';
	}

	/**
	 * Query source-language post IDs.
	 *
	 * @param string $post_type Post type or "any".
	 * @param string $lang      Language.
	 * @return int[]
	 */
	private function query_source_ids( $post_type, $lang ) {
		$types = ( 'any' === $post_type )
			? get_post_types( array( 'public' => true ), 'names' )
			: array( $post_type );

		$types = array_values( array_filter( (array) $types ) );
		if ( empty( $types ) ) {
			return array();
		}

		// Restrict WP_Query to the source language when WPML is present.
		do_action( 'wpml_switch_language', $lang );

		$query = new \WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );

		do_action( 'wpml_switch_language', null );

		return $ids;
	}

	/**
	 * Translated post ID for a source post.
	 *
	 * @param int    $original_id Source ID.
	 * @param string $target_lang Target lang.
	 * @return int
	 */
	private function translated_id( $original_id, $target_lang ) {
		$post_type = get_post_type( $original_id );
		if ( ! $post_type ) {
			return 0;
		}
		$id = (int) apply_filters( 'wpml_object_id', $original_id, $post_type, false, $target_lang );
		return $id > 0 ? $id : 0;
	}
}
