<?php
/**
 * Plugin bootstrap.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires all components.
 */
class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot services.
	 */
	public function boot() {
		$this->settings = new Settings();
		$logger         = new Logger();
		$client         = ( new Translator_Factory( $this->settings ) )->make();
		$translator     = new WPML_Job_Translator( $client, $logger );
		$queue          = new Queue( $translator, $logger );

		( new Admin( $this->settings, $logger ) )->register();
		( new Admin_Queue( $this->settings, $queue, $logger ) )->register();
		( new GitHub_Updater() )->register();
		if ( $this->settings->get( 'enable_public_lang_preview', false ) ) {
			( new Content_Filter( $client, $this->settings ) )->register();
		}
		$queue->register();

		if ( $this->wpml_available() ) {
			( new WPML_Hooks( $this->settings, $queue, $translator ) )->register();
			( new ATE_Interceptor( $this->settings ) )->register();
		}
	}

	/**
	 * Whether WPML core is loaded.
	 *
	 * @return bool
	 */
	private function wpml_available() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
	}
}
