<?php
/**
 * One-time migration from wp-ollama-translator → wp-ai-translator.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies legacy options and rewrites active plugin / cron hooks.
 */
class Migrator {

	const FLAG = 'wpai_migrated_from_wpollama';

	/**
	 * Option key map: old => new.
	 *
	 * @var array<string,string>
	 */
	private static $options = array(
		'wpollama_settings'         => 'wpai_settings',
		'wpollama_pending_jobs'     => 'wpai_pending_jobs',
		'wpollama_rate_limit_pause' => 'wpai_rate_limit_pause',
		'wpollama_recent_errors'    => 'wpai_recent_errors',
	);

	/**
	 * Cron hook map: old => new.
	 *
	 * @var array<string,string>
	 */
	private static $crons = array(
		'wpollama_process_wpml_job'  => 'wpai_process_wpml_job',
		'wpollama_scan_pending_jobs' => 'wpai_scan_pending_jobs',
	);

	/**
	 * Run migration if needed.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::FLAG ) ) {
			self::ensure_active_plugin_path();
			return;
		}

		foreach ( self::$options as $old => $new ) {
			$current = get_option( $new, null );
			$legacy  = get_option( $old, null );
			if ( null === $current && null !== $legacy ) {
				update_option( $new, $legacy, false );
			}
		}

		self::migrate_cron_hooks();
		self::ensure_active_plugin_path();
		update_option( self::FLAG, WPAI_VERSION, false );
	}

	/**
	 * Replace old cron events with new hook names.
	 */
	private static function migrate_cron_hooks() {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}
		$changed = false;
		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( self::$crons as $old => $new ) {
				if ( empty( $hooks[ $old ] ) ) {
					continue;
				}
				foreach ( $hooks[ $old ] as $key => $event ) {
					if ( ! isset( $crons[ $timestamp ][ $new ] ) ) {
						$crons[ $timestamp ][ $new ] = array();
					}
					$crons[ $timestamp ][ $new ][ $key ] = $event;
				}
				unset( $crons[ $timestamp ][ $old ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			_set_cron_array( $crons );
		}
	}

	/**
	 * Swap active_plugins path from legacy folder to new.
	 */
	private static function ensure_active_plugin_path() {
		$old = 'wp-ollama-translator/wp-ollama-translator.php';
		$new = 'wp-ai-translator/wp-ai-translator.php';
		$plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $plugins ) ) {
			return;
		}
		$idx = array_search( $old, $plugins, true );
		if ( false === $idx ) {
			return;
		}
		$plugins[ $idx ] = $new;
		$plugins           = array_values( array_unique( $plugins ) );
		update_option( 'active_plugins', $plugins );
	}
}
