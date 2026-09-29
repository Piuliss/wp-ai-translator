<?php
/**
 * Normalize LLM translation output.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strips reasoning / commentary that some models prepend.
 */
class Response_Cleaner {

	/**
	 * Clean a model response to the translated text.
	 *
	 * @param string $content Raw model content.
	 * @param string $source  Original source text (optional hint).
	 * @return string
	 */
	public static function clean( $content, $source = '' ) {
		$content = (string) $content;
		$content = trim( $content );
		if ( '' === $content ) {
			return '';
		}

		// Remove thinking / reasoning blocks.
		$content = preg_replace( '/<think\b[^>]*>.*?<\/think>/is', '', $content );
		$content = preg_replace( '/<thinking\b[^>]*>.*?<\/thinking>/is', '', $content );
		$content = preg_replace( '/```(?:thinking|reasoning)[\s\S]*?```/i', '', $content );
		$content = trim( (string) $content );

		// Quotation wrappers around the whole answer.
		if ( preg_match( '/^["“](.+)["”]$/su', $content, $m ) ) {
			$content = trim( $m[1] );
		}

		// If the model narrates then ends with the translation on the last line.
		if ( self::looks_like_commentary( $content ) ) {
			$lines = preg_split( '/\R+/', $content );
			$lines = array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
			if ( ! empty( $lines ) ) {
				$last = end( $lines );
				// Prefer a short final line without English meta phrasing.
				if ( is_string( $last ) && '' !== $last && ! self::looks_like_commentary( $last ) ) {
					$content = $last;
				} elseif ( preg_match( '/["“]([^"”]{1,500})["”]\s*$/u', $content, $qm ) ) {
					$content = trim( $qm[1] );
				} elseif ( preg_match( '/(?:is|:)\s*["“]?([^"”\n.]{1,200})["“]?\s*\.?$/iu', $content, $qm ) ) {
					$content = trim( $qm[1] );
				}
			}
		}

		$content = trim( $content );

		// Avoid returning the unchanged source when the model only chatted.
		$source = trim( (string) $source );
		if ( '' !== $source && 0 === strcasecmp( $content, $source ) && self::looks_like_commentary( $content ) ) {
			return $content;
		}

		return $content;
	}

	/**
	 * Heuristic: response is explanation rather than translation.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function looks_like_commentary( $text ) {
		$text = (string) $text;
		if ( strlen( $text ) < 40 ) {
			return false;
		}
		return (bool) preg_match(
			'/\b(the user wants|translate|maintaining|additional comments|in spanish|idioma origen|idioma destino|devuelve solo)\b/i',
			$text
		);
	}
}
