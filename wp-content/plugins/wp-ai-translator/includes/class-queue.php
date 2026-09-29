<?php
/**
 * Async queue for WPML jobs via WP-Cron.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and processes translation jobs.
 */
class Queue {

	const HOOK      = 'wpai_process_wpml_job';
	const SCAN_HOOK = 'wpai_scan_pending_jobs';
	const OPTION    = 'wpai_pending_jobs';
	const STATUS    = 'wpai_queue_status';
	const SYNC_MAX  = 0; // Always async: MiniMax/Ollama are slow for multi-field jobs.

	/**
	 * Job translator.
	 *
	 * @var WPML_Job_Translator
	 */
	private $translator;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WPML_Job_Translator $translator Translator.
	 * @param Logger              $logger     Logger.
	 */
	public function __construct( WPML_Job_Translator $translator, Logger $logger ) {
		$this->translator = $translator;
		$this->logger     = $logger;
	}

	/**
	 * Register cron hook.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'process_job' ), 10, 2 );
		add_action( self::SCAN_HOOK, array( $this, 'scan_pending' ) );
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_event( time() + 30, 'hourly', self::SCAN_HOOK );
		}
		add_action( 'admin_init', array( $this, 'maybe_drain_pending' ), 20 );
	}

	/**
	 * Pending job IDs.
	 *
	 * @return int[]
	 */
	public function get_pending() {
		$pending = get_option( self::OPTION, array() );
		if ( ! is_array( $pending ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $pending ) ) ) );
	}

	/**
	 * Queue runtime status.
	 *
	 * @return array
	 */
	public function get_status() {
		$status = get_option( self::STATUS, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$pending = $this->get_pending();
		$next    = $this->next_cron_timestamp( $pending );
		$scan    = wp_next_scheduled( self::SCAN_HOOK );

		$state = 'idle';
		$busy  = ! empty( $status['busy'] );
		$started = isset( $status['last_started'] ) ? (int) $status['last_started'] : 0;
		$stale   = $busy && $started && ( time() - $started ) >= 900;
		if ( $stale ) {
			$busy = false;
			$status['busy']         = false;
			$status['current_job']  = 0;
			$status['last_message'] = __( 'Lock liberado (timeout 15 min).', 'wp-ai-translator' );
			update_option( self::STATUS, $status, false );
		}

		if ( Rate_Limit::is_paused() ) {
			$state = 'paused';
		} elseif ( $busy ) {
			$state = 'working';
		} elseif ( ! empty( $pending ) ) {
			$state = $next ? 'queued' : 'stalled';
		}

		return array_merge(
			array(
				'busy'            => false,
				'current_job'     => 0,
				'last_job'        => 0,
				'last_started'    => 0,
				'last_finished'   => 0,
				'last_ok'         => null,
				'last_message'    => '',
				'last_elapsed'    => 0,
				'processed_total' => 0,
			),
			$status,
			array(
				'state'           => $state,
				'pending_count'   => count( $pending ),
				'next_cron'       => $next ? (int) $next : 0,
				'next_scan'       => $scan ? (int) $scan : 0,
				'rate_paused'     => Rate_Limit::is_paused(),
				'rate_until'      => Rate_Limit::paused_until(),
				'rate_reason'     => Rate_Limit::reason(),
			)
		);
	}

	/**
	 * Remove one job from the pending queue (does not delete WPML job).
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function remove_job( $job_id ) {
		$job_id  = (int) $job_id;
		$pending = $this->get_pending();
		$before  = count( $pending );
		$pending = array_values( array_diff( $pending, array( $job_id ) ) );
		update_option( self::OPTION, $pending, false );
		wp_clear_scheduled_hook( self::HOOK, array( $job_id, true ) );
		wp_clear_scheduled_hook( self::HOOK, array( $job_id, false ) );
		$this->logger->info(
			__( 'Job quitado de la cola AI.', 'wp-ai-translator' ),
			array( 'job_id' => $job_id )
		);
		return count( $pending ) < $before;
	}

	/**
	 * Clear entire pending queue.
	 *
	 * @return int Removed count.
	 */
	public function clear_all() {
		$pending = $this->get_pending();
		$count   = count( $pending );
		foreach ( $pending as $job_id ) {
			wp_clear_scheduled_hook( self::HOOK, array( (int) $job_id, true ) );
			wp_clear_scheduled_hook( self::HOOK, array( (int) $job_id, false ) );
		}
		update_option( self::OPTION, array(), false );
		$this->logger->info(
			__( 'Cola AI vaciada.', 'wp-ai-translator' ),
			array( 'removed' => $count )
		);
		return $count;
	}

	/**
	 * Ensure every pending job has a cron event.
	 *
	 * @return int Scheduled count.
	 */
	public function reschedule_pending() {
		$pending = $this->get_pending();
		$n       = 0;
		$i       = 0;
		foreach ( $pending as $job_id ) {
			$args = array( (int) $job_id, true );
			if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
				wp_schedule_single_event( time() + 5 + ( $i * 3 ), self::HOOK, $args );
				++$n;
				++$i;
			}
		}
		$this->logger->info(
			__( 'Cron reprogramado para jobs pendientes.', 'wp-ai-translator' ),
			array( 'scheduled' => $n, 'pending' => count( $pending ) )
		);
		return $n;
	}

	/**
	 * Clear stuck busy lock.
	 */
	public function clear_busy() {
		$this->update_status(
			array(
				'busy'         => false,
				'current_job'  => 0,
				'last_message' => __( 'Lock liberado manualmente.', 'wp-ai-translator' ),
			)
		);
		$this->logger->info( __( 'Lock busy liberado.', 'wp-ai-translator' ) );
	}

	/**
	 * Process one job immediately (admin action).
	 *
	 * @param int  $job_id   Job ID.
	 * @param bool $complete Complete flag.
	 * @return true|\WP_Error
	 */
	public function process_now( $job_id, $complete = true ) {
		$job_id = (int) $job_id;
		if ( Rate_Limit::is_paused() ) {
			return new \WP_Error( 'wpai_rate_paused', Rate_Limit::reason() );
		}
		$this->process_job( $job_id, $complete );
		$status = $this->get_status();
		if ( ! empty( $status['last_ok'] ) ) {
			return true;
		}
		if ( ! empty( $status['last_message'] ) ) {
			return new \WP_Error( 'wpai_process_failed', (string) $status['last_message'] );
		}
		return true;
	}

	/**
	 * Find untranslated local WPML jobs and enqueue them.
	 */
	public function scan_pending() {
		if ( Rate_Limit::is_paused() ) {
			$this->reschedule_after_pause();
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb->prefix ) ) {
			return;
		}
		$ids = $wpdb->get_col(
			"SELECT j.job_id FROM {$wpdb->prefix}icl_translate_job j
			JOIN {$wpdb->prefix}icl_translation_status s ON s.rid = j.rid
			WHERE j.translated = 0
			  AND s.translation_service = 'local'
			  AND j.job_id = (
			  	SELECT MAX(j2.job_id) FROM {$wpdb->prefix}icl_translate_job j2 WHERE j2.rid = j.rid
			  )
			  AND j.job_id >= (
			  	SELECT COALESCE(MAX(j3.job_id), 0) - 50 FROM {$wpdb->prefix}icl_translate_job j3
			  )
			ORDER BY j.job_id DESC
			LIMIT 5"
		);
		if ( empty( $ids ) ) {
			return;
		}
		$this->enqueue( array_map( 'intval', $ids ), true );
	}

	/**
	 * Process one pending job per admin hit (fallback when WP-Cron is idle).
	 */
	public function maybe_drain_pending() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( Rate_Limit::is_paused() ) {
			return;
		}
		$status = get_option( self::STATUS, array() );
		if ( is_array( $status ) && ! empty( $status['busy'] ) ) {
			$started = isset( $status['last_started'] ) ? (int) $status['last_started'] : 0;
			// Stale lock > 15 min.
			if ( $started && ( time() - $started ) < 900 ) {
				return;
			}
		}
		$pending = $this->get_pending();
		if ( empty( $pending ) ) {
			return;
		}
		$job_id = (int) array_shift( $pending );
		update_option( self::OPTION, array_values( $pending ), false );
		if ( $job_id > 0 ) {
			$this->process_job( $job_id, true );
		}
	}

	/**
	 * Enqueue one or more job IDs.
	 *
	 * @param int[] $job_ids Job IDs.
	 * @param bool  $complete Mark complete when done.
	 */
	public function enqueue( array $job_ids, $complete = true ) {
		$job_ids = array_values( array_unique( array_filter( array_map( 'intval', $job_ids ) ) ) );
		if ( empty( $job_ids ) ) {
			return;
		}

		if ( count( $job_ids ) <= self::SYNC_MAX ) {
			foreach ( $job_ids as $job_id ) {
				$this->process_job( $job_id, $complete );
			}
			return;
		}

		foreach ( $job_ids as $job_id ) {
			$this->schedule( $job_id, $complete );
		}
	}

	/**
	 * Schedule a single job.
	 *
	 * @param int  $job_id   Job ID.
	 * @param bool $complete Complete flag.
	 */
	public function schedule( $job_id, $complete = true ) {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return;
		}

		$pending   = get_option( self::OPTION, array() );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = $job_id;
		update_option( self::OPTION, array_values( array_unique( $pending ) ), false );

		$delay = 5;
		if ( Rate_Limit::is_paused() ) {
			$delay = max( 5, Rate_Limit::paused_until() - time() + 5 );
		}

		if ( ! wp_next_scheduled( self::HOOK, array( $job_id, (bool) $complete ) ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, array( $job_id, (bool) $complete ) );
		}
	}

	/**
	 * Process a job (cron or sync).
	 *
	 * @param int  $job_id   Job ID.
	 * @param bool $complete Complete flag.
	 */
	public function process_job( $job_id, $complete = true ) {
		$job_id = (int) $job_id;

		if ( Rate_Limit::is_paused() ) {
			$this->requeue_job( $job_id, $complete );
			$this->reschedule_after_pause();
			$this->logger->info(
				__( 'Cola en pausa por rate limit; job reprogramado.', 'wp-ai-translator' ),
				array( 'job_id' => $job_id )
			);
			return;
		}

		$started = time();
		$this->update_status(
			array(
				'busy'         => true,
				'current_job'  => $job_id,
				'last_job'     => $job_id,
				'last_started' => $started,
				'last_message' => __( 'Procesando…', 'wp-ai-translator' ),
			)
		);
		$this->logger->info(
			__( 'Iniciando traducción de job.', 'wp-ai-translator' ),
			array( 'job_id' => $job_id )
		);

		$result = $this->translator->translate_job( $job_id, (bool) $complete );
		$elapsed = max( 0, time() - $started );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$this->logger->error(
				$result->get_error_message(),
				array(
					'job_id'  => $job_id,
					'code'    => $code,
					'elapsed' => $elapsed,
				)
			);

			$this->update_status(
				array(
					'busy'          => false,
					'current_job'   => 0,
					'last_finished' => time(),
					'last_ok'       => false,
					'last_message'  => $result->get_error_message(),
					'last_elapsed'  => $elapsed,
				)
			);

			if ( in_array( $code, array( 'wpai_rate_limited', 'wpai_rate_window', 'wpai_rate_paused' ), true ) ) {
				$this->requeue_job( $job_id, $complete );
				$this->reschedule_after_pause();
				return;
			}
		} else {
			$status = get_option( self::STATUS, array() );
			$total  = is_array( $status ) && isset( $status['processed_total'] ) ? (int) $status['processed_total'] + 1 : 1;
			$this->update_status(
				array(
					'busy'            => false,
					'current_job'     => 0,
					'last_finished'   => time(),
					'last_ok'         => true,
					'last_message'    => __( 'OK', 'wp-ai-translator' ),
					'last_elapsed'    => $elapsed,
					'processed_total' => $total,
				)
			);
			$this->logger->info(
				__( 'Job traducido OK.', 'wp-ai-translator' ),
				array(
					'job_id'  => $job_id,
					'elapsed' => $elapsed,
				)
			);
		}

		$pending = get_option( self::OPTION, array() );
		if ( is_array( $pending ) ) {
			$pending = array_values( array_diff( $pending, array( $job_id ) ) );
			update_option( self::OPTION, $pending, false );
		}
	}

	/**
	 * Put job back into pending and schedule after pause.
	 *
	 * @param int  $job_id   Job.
	 * @param bool $complete Complete flag.
	 */
	private function requeue_job( $job_id, $complete ) {
		$pending   = get_option( self::OPTION, array() );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = (int) $job_id;
		update_option( self::OPTION, array_values( array_unique( array_map( 'intval', $pending ) ) ), false );

		$delay = Rate_Limit::is_paused() ? max( 5, Rate_Limit::paused_until() - time() + 5 ) : 60;
		wp_clear_scheduled_hook( self::HOOK, array( (int) $job_id, (bool) $complete ) );
		wp_schedule_single_event( time() + $delay, self::HOOK, array( (int) $job_id, (bool) $complete ) );
	}

	/**
	 * Ensure scan runs after pause ends.
	 */
	private function reschedule_after_pause() {
		if ( ! Rate_Limit::is_paused() ) {
			return;
		}
		$until = Rate_Limit::paused_until();
		$ts    = $until + 5;
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_single_event( $ts, self::SCAN_HOOK );
		}
	}

	/**
	 * Merge status fields.
	 *
	 * @param array $fields Fields.
	 */
	private function update_status( array $fields ) {
		$current = get_option( self::STATUS, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( self::STATUS, array_merge( $current, $fields ), false );
	}

	/**
	 * Earliest scheduled cron among pending jobs (HOOK uses args).
	 *
	 * @param int[] $pending Job IDs.
	 * @return int Timestamp or 0.
	 */
	private function next_cron_timestamp( array $pending ) {
		$earliest = 0;
		foreach ( $pending as $job_id ) {
			foreach ( array( true, false ) as $complete ) {
				$ts = wp_next_scheduled( self::HOOK, array( (int) $job_id, $complete ) );
				if ( $ts && ( ! $earliest || $ts < $earliest ) ) {
					$earliest = (int) $ts;
				}
			}
		}
		return $earliest;
	}
}
