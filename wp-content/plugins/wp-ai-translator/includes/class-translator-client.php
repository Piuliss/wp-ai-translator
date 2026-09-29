<?php
/**
 * Translator client contract.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common interface for LLM translation backends.
 */
interface Translator_Client {

	/**
	 * Translate text.
	 *
	 * @param string $text        Source text.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return string|\WP_Error
	 */
	public function translate( $text, $source_lang, $target_lang );

	/**
	 * Translate many fields in one request.
	 *
	 * @param array  $items       Map of field_key => source text.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return array|\WP_Error Map of field_key => translated text.
	 */
	public function translate_batch( array $items, $source_lang, $target_lang );
}
