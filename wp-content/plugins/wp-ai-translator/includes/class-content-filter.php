<?php
/**
 * Optional URL-based content filter (?wpai_lang=xx).
 *
 * Disabled by default. When enabled, only administrators can trigger it.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the_content via query var for quick admin tests.
 */
class Content_Filter {

	/**
	 * Client.
	 *
	 * @var Translator_Client
	 */
	private $client;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Translator_Client $client   Client.
	 * @param Settings          $settings Settings.
	 */
	public function __construct( Translator_Client $client, Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Register hooks (caller must gate on setting).
	 */
	public function register() {
		add_filter(
			'query_vars',
			static function ( $vars ) {
				$vars[] = 'wpai_lang';
				return $vars;
			}
		);
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
	}

	/**
	 * Filter content when wpai_lang is present (admins only).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! $this->settings->get( 'enable_public_lang_preview', false ) ) {
			return $content;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return $content;
		}

		$target_lang = get_query_var( 'wpai_lang' );
		if ( empty( $target_lang ) ) {
			return $content;
		}

		$target_lang = sanitize_text_field( (string) $target_lang );
		$source_lang = apply_filters( 'wpml_current_language', '' );
		if ( ! is_string( $source_lang ) || '' === $source_lang ) {
			$source_lang = 'auto';
		}

		$translated = $this->client->translate( $content, $source_lang, $target_lang );
		if ( is_wp_error( $translated ) ) {
			return $content;
		}

		return sprintf(
			'<!-- OLLAMA_LANG: %s -->%s<!-- /OLLAMA_LANG -->',
			esc_html( $target_lang ),
			wp_kses_post( (string) $translated )
		);
	}
}
