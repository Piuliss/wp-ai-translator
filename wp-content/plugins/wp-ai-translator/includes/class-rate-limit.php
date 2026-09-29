<?php
/**
 * Provider rate-limit / usage-window pause handling.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pauses the translation queue until MiniMax (or similar) resets quota.
 *
 * MiniMax codes of interest:
 * - HTTP 429 / base_resp 1002 / 1039 / 2045 → short rate limit
 * - base_resp 2056 → 5-hour usage window (00/05/10/15/20 site TZ)
 */
class Rate_Limit {

	const OPTION           = 'wpai_rate_limit_pause';
	const MAX_INLINE_SLEEP = 25; // Seconds we may sleep inside a request.
	const SHORT_DEFAULT    = 60; // Default pause when Retry-After missing.
	const WINDOW_HOURS     = array( 0, 5, 10, 15, 20 );

	/**
	 * Whether the queue is currently paused.
	 *
	 * @return bool
	 */
	public static function is_paused() {
		$state = self::get_state();
		if ( empty( $state['until'] ) ) {
			return false;
		}
		if ( time() >= (int) $state['until'] ) {
			self::clear();
			return false;
		}
		return true;
	}

	/**
	 * Unix timestamp when pause ends (0 if not paused).
	 *
	 * @return int
	 */
	public static function paused_until() {
		if ( ! self::is_paused() ) {
			return 0;
		}
		$state = self::get_state();
		return (int) $state['until'];
	}

	/**
	 * Human reason for the pause.
	 *
	 * @return string
	 */
	public static function reason() {
		$state = self::get_state();
		return isset( $state['reason'] ) ? (string) $state['reason'] : '';
	}

	/**
	 * Seconds remaining until resume.
	 *
	 * @return int
	 */
	public static function seconds_remaining() {
		$until = self::paused_until();
		if ( $until <= 0 ) {
			return 0;
		}
		return max( 0, $until - time() );
	}

	/**
	 * Clear pause.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Set a pause until a unix timestamp.
	 *
	 * @param int    $until  Unix timestamp.
	 * @param string $reason Reason.
	 * @param string $type   short|window.
	 */
	public static function pause_until( $until, $reason, $type = 'short' ) {
		$until = (int) $until;
		if ( $until <= time() ) {
			return;
		}
		$current = self::get_state();
		// Keep the later pause if already paused further out.
		if ( ! empty( $current['until'] ) && (int) $current['until'] >= $until ) {
			return;
		}
		update_option(
			self::OPTION,
			array(
				'until'  => $until,
				'reason' => (string) $reason,
				'type'   => (string) $type,
				'set_at' => time(),
			),
			false
		);
	}

	/**
	 * Inspect an HTTP response and pause / sleep as needed.
	 *
	 * @param array|mixed $response WP HTTP response.
	 * @param int         $http_code HTTP status.
	 * @param string      $body      Response body.
	 * @return true|\WP_Error True if caller should retry now; WP_Error if paused / exhausted.
	 */
	public static function handle_provider_response( $response, $http_code, $body ) {
		$http_code = (int) $http_code;
		$body      = (string) $body;
		$data      = json_decode( $body, true );
		$provider  = 0;
		if ( is_array( $data ) ) {
			if ( isset( $data['base_resp']['status_code'] ) ) {
				$provider = (int) $data['base_resp']['status_code'];
			} elseif ( isset( $data['status_code'] ) ) {
				$provider = (int) $data['status_code'];
			} elseif ( isset( $data['error']['type'] ) && 'rate_limit_error' === $data['error']['type'] ) {
				$provider = 1002;
			}
		}

		$is_window = ( 2056 === $provider );
		$is_rate   = ( 429 === $http_code )
			|| in_array( $provider, array( 1002, 1039, 2045, 1041 ), true );

		if ( ! $is_window && ! $is_rate ) {
			return true; // Not a rate-limit case.
		}

		$retry_after = self::parse_retry_after( $response );
		if ( $is_window ) {
			$until  = self::next_window_timestamp();
			$reason = __( 'MiniMax: límite de tokens/ventana (2056). Esperando el próximo reset de 5 horas.', 'wp-ai-translator' );
			self::pause_until( $until, $reason, 'window' );
			return new \WP_Error(
				'wpai_rate_window',
				$reason,
				array(
					'until'         => $until,
					'provider_code' => $provider,
				)
			);
		}

		// Short rate limit (RPM/TPM).
		if ( $retry_after > 0 ) {
			$wait = $retry_after;
		} else {
			$wait = self::SHORT_DEFAULT;
		}
		$wait = max( 5, min( 3600, (int) $wait ) );

		// Inline sleep only for brief waits (cron/background friendly).
		if ( $wait <= self::MAX_INLINE_SLEEP && ! wp_doing_ajax() ) {
			sleep( $wait );
			return true; // Retry immediately after short sleep.
		}

		$until  = time() + $wait;
		$reason = sprintf(
			/* translators: %d: seconds */
			__( 'MiniMax: rate limit (RPM/TPM). Reanudando en ~%d s.', 'wp-ai-translator' ),
			$wait
		);
		self::pause_until( $until, $reason, 'short' );
		return new \WP_Error(
			'wpai_rate_limited',
			$reason,
			array(
				'until'         => $until,
				'provider_code' => $provider,
				'http_code'     => $http_code,
			)
		);
	}

	/**
	 * Next MiniMax 5-hour window boundary in site timezone.
	 *
	 * @return int Unix timestamp.
	 */
	public static function next_window_timestamp() {
		try {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}

		$now  = new \DateTimeImmutable( 'now', $tz );
		$hour = (int) $now->format( 'G' );
		$day  = $now->setTime( 0, 0, 0 );

		foreach ( self::WINDOW_HOURS as $boundary ) {
			if ( $hour < $boundary ) {
				$next = $day->setTime( $boundary, 0, 0 );
				return $next->getTimestamp() + 30; // Small margin after reset.
			}
		}

		// After 20:00 → next day 00:00.
		$next = $day->modify( '+1 day' )->setTime( 0, 0, 0 );
		return $next->getTimestamp() + 30;
	}

	/**
	 * Parse Retry-After header (seconds or HTTP date).
	 *
	 * @param array|mixed $response Response.
	 * @return int Seconds.
	 */
	private static function parse_retry_after( $response ) {
		if ( ! is_array( $response ) ) {
			return 0;
		}
		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( '' === $value || null === $value ) {
			// Common alternates.
			foreach ( array( 'x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens', 'x-ratelimit-reset' ) as $h ) {
				$alt = wp_remote_retrieve_header( $response, $h );
				if ( '' !== $alt && null !== $alt && is_numeric( $alt ) ) {
					$n = (int) $alt;
					// Absolute epoch vs delta.
					if ( $n > time() ) {
						return max( 0, $n - time() );
					}
					return max( 0, $n );
				}
			}
			return 0;
		}
		$value = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		if ( is_numeric( $value ) ) {
			return max( 0, (int) $value );
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return 0;
		}
		return max( 0, $ts - time() );
	}

	/**
	 * Stored pause state.
	 *
	 * @return array
	 */
	private static function get_state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}
}
