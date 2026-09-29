<?php
/**
 * Encode / decode multi-field translation payloads.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds prompts and parses JSON batch responses.
 */
class Batch_Codec {

	/**
	 * System instructions for batch translation.
	 *
	 * @param string $source_lang Source lang.
	 * @param string $target_lang Target lang.
	 * @param string $base_prompt User base prompt.
	 * @return string
	 */
	public static function system_prompt( $source_lang, $target_lang, $base_prompt ) {
		$base = trim( (string) $base_prompt );
		$out  = $base;
		$out .= "\n\nReglas estrictas:\n"
			. "- Traduce del idioma «{$source_lang}» al idioma «{$target_lang}».\n"
			. "- Recibirás un objeto JSON con varias claves; cada valor es un texto a traducir.\n"
			. "- Responde ÚNICAMENTE con un objeto JSON válido (sin markdown, sin explicaciones).\n"
			. "- Las claves del JSON de salida deben ser EXACTAMENTE las mismas que en la entrada.\n"
			. "- Cada valor de salida es SOLO el texto traducido correspondiente.\n"
			. "- Conserva HTML, placeholders y formato del original.\n"
			. "- No agregues claves nuevas ni omitas ninguna clave.";
		return $out;
	}

	/**
	 * User message body.
	 *
	 * @param array $items Field map.
	 * @return string
	 */
	public static function user_prompt( array $items ) {
		return "Traduce estos campos. Devuelve solo JSON:\n" . wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Parse model content into field map.
	 *
	 * @param string $content Raw model content.
	 * @param array  $items   Original items (for key validation).
	 * @return array|\WP_Error
	 */
	public static function parse( $content, array $items ) {
		$content = Response_Cleaner::clean( (string) $content );
		$json    = self::extract_json_object( $content );
		if ( null === $json ) {
			return new \WP_Error( 'wpai_batch_parse', __( 'No se pudo parsear el JSON de traducción por lotes.', 'wp-ai-translator' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'wpai_batch_json', __( 'JSON de lote inválido.', 'wp-ai-translator' ) );
		}

		$out = array();
		foreach ( $items as $key => $source ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $decoded ) ) {
				return new \WP_Error(
					'wpai_batch_missing_key',
					sprintf(
						/* translators: %s: field key */
						__( 'Falta la clave «%s» en la respuesta del lote.', 'wp-ai-translator' ),
						$key
					)
				);
			}
			$val = $decoded[ $key ];
			if ( is_array( $val ) || is_object( $val ) ) {
				$val = wp_json_encode( $val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			$out[ $key ] = Response_Cleaner::clean( (string) $val, (string) $source );
		}

		return $out;
	}

	/**
	 * Extract first JSON object from text.
	 *
	 * @param string $content Content.
	 * @return string|null
	 */
	private static function extract_json_object( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return null;
		}

		// Strip ```json fences if present.
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/i', $content, $m ) ) {
			$content = trim( $m[1] );
		}

		if ( '{' === substr( $content, 0, 1 ) ) {
			$decoded = json_decode( $content, true );
			if ( is_array( $decoded ) ) {
				return $content;
			}
		}

		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$slice = substr( $content, $start, $end - $start + 1 );
		$decoded = json_decode( $slice, true );
		return is_array( $decoded ) ? $slice : null;
	}
}
