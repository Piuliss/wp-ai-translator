<?php
/**
 * Builds the active translator client.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider factory.
 */
class Translator_Factory {

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
	 * Create client for configured provider.
	 *
	 * @return Translator_Client
	 */
	public function make() {
		$provider = $this->settings->get_provider();
		if ( Settings::PROVIDER_MINIMAX === $provider ) {
			return new Minimax_Client( $this->settings );
		}
		return new Ollama_Client( $this->settings );
	}
}
