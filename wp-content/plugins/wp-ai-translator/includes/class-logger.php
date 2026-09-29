<?php
/**
 * Activity / error log for the plugin.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores recent translation activity.
 */
class Logger {

	const OPTION = 'wpai_recent_errors';
	const MAX    = 80;

	/**
	 * Log an error.
	 *
	 * @param string $message Message.
	 * @param array  $context Context (job_id, etc).
	 */
	public function error( $message, array $context = array() ) {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Log an info event.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function info( $message, array $context = array() ) {
		$this->write( 'info', $message, $context );
	}

	/**
	 * Recent entries (errors + info).
	 *
	 * @param int $limit Max items.
	 * @return array
	 */
	public function recent( $limit = 40 ) {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			return array();
		}
		return array_slice( $items, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Clear log.
	 */
	public function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Persist an entry.
	 *
	 * @param string $level   error|info.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private function write( $level, $message, array $context ) {
		$entry = array(
			'time'    => time(),
			'level'   => (string) $level,
			'message' => (string) $message,
			'context' => $context,
		);

		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		array_unshift( $items, $entry );
		$items = array_slice( $items, 0, self::MAX );
		update_option( self::OPTION, $items, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[wp-ai-translator][' . $level . '] ' . $message . ' ' . wp_json_encode( $context ) );
		}
	}
}
