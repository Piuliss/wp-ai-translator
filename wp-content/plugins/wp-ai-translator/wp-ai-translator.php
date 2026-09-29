<?php
/**
 * Plugin Name:       Traducción AI (WPML)
 * Description:       Motor de traducción (Ollama / MiniMax) para WordPress y WPML (automático y manual).
 * Version:           1.7.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Raúl Benitez Netto
 * Author URI:        mailto:raulbeni@gmail.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-ai-translator
 * Update URI:        https://github.com/Piuliss/wp-ai-translator
 *
 * @package WPAITranslator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAI_VERSION', '1.7.2' );
define( 'WPAI_PLUGIN_FILE', __FILE__ );
define( 'WPAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPAI_PLUGIN_DIR . 'includes/class-settings.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-secrets.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-url-guard.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-response-cleaner.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-batch-codec.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-rate-limit.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-translator-client.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-ollama-client.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-minimax-client.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-translator-factory.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-logger.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-migrator.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-admin.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-admin-queue.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-content-filter.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-queue.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-wpml-job-translator.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-wpml-hooks.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-ate-interceptor.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-github-updater.php';
require_once WPAI_PLUGIN_DIR . 'includes/class-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		WPAITranslator\Migrator::maybe_migrate();
		WPAITranslator\Plugin::instance()->boot();
	},
	20
);
