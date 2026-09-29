<?php
/**
 * Short-circuits WPML ATE job traffic when the local AI engine is enabled.
 *
 * Wizard UI (engines, language pairs, etc.) talks to WPML cloud normally.
 * Job create / sync is intercepted so translation runs locally (MiniMax/Ollama).
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fake ATE/AMS responses so WPML UI keeps working without credits.
 */
class ATE_Interceptor {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Synthetic ATE job id counter seed.
	 *
	 * @var int
	 */
	private $ate_id_base = 900000000;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register filter.
	 */
	public function register() {
		add_filter( 'pre_http_request', array( $this, 'filter_request' ), 5, 3 );
		add_action( 'init', array( $this, 'maybe_clear_wpml_ate_cache' ), 20 );
	}

	/**
	 * Drop stale AMS/ATE caches that can keep the WPML wizard spinning.
	 */
	public function maybe_clear_wpml_ate_cache() {
		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			return;
		}
		$flag = 'wpai_cleared_wpml_ate_cache_v3';
		if ( get_option( $flag ) ) {
			return;
		}
		delete_transient( 'wpml-tm-ams-api-cache' );
		delete_transient( 'wpml-tm-ate-api-cache' );
		if ( class_exists( '\WPML\TM\ATE\API\CachedAMSAPI' ) && method_exists( '\WPML\TM\ATE\API\CachedAMSAPI', 'clearCache' ) ) {
			\WPML\TM\ATE\API\CachedAMSAPI::clearCache();
		}
		update_option( $flag, 1, false );
	}

	/**
	 * Intercept ATE/AMS requests.
	 *
	 * UI calculation (engines, pairs, languages, credits) is passed through to WPML cloud
	 * so the wizard can finish loading. Job create / sync / download is intercepted so
	 * "Translate content" never sends work to ATE — local MiniMax/Ollama queue handles it.
	 *
	 * @param false|array|\WP_Error $preempt Preempt.
	 * @param array                 $args    Args.
	 * @param string                $url     URL.
	 * @return false|array|\WP_Error
	 */
	public function filter_request( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			return $preempt;
		}
		if ( ! $this->is_wpml_cloud_url( $url ) ) {
			return $preempt;
		}

		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body   = isset( $args['body'] ) ? $args['body'] : '';

		// Let WPML AMS/ATE compute engines, pairs, languages, balances for the wizard UI.
		if ( $this->should_passthrough_for_ui( $path, $method ) ) {
			return $preempt;
		}

		// Still fake account credits so "Translate content" is not blocked by empty WPML prepaid.
		if ( $this->path_matches( $path, array( 'account_balances' ) ) ) {
			return $this->json_response( $this->account_balances_payload() );
		}
		if ( preg_match( '#/api/wpml/credits/?$#', $path ) ) {
			return $this->json_response( $this->credits_payload() );
		}

		// --- Translate content / ATE job pipeline: never reach WPML cloud ---
		if ( 'POST' === $method && $this->path_matches( $path, array( 'jobs' ) ) && ! $this->path_matches( $path, array( 'cancel', 'hide', 'confirm' ) ) ) {
			$payload = $this->create_jobs_payload( $body );
			$this->enqueue_wpml_jobs_from_create_body( $body );
			return $this->json_response( $payload );
		}

		if ( $this->path_matches( $path, array( 'sync' ) ) ) {
			return $this->json_response(
				array(
					'authenticated' => true,
					'jobs'          => new \stdClass(),
					'done'          => true,
					'status'        => 'complete',
				)
			);
		}

		if ( $this->path_matches( $path, array( 'cancel', 'hide', 'confirm' ) ) ) {
			return $this->json_response(
				array(
					'success'       => true,
					'authenticated' => true,
				)
			);
		}

		if ( $this->path_matches( $path, array( 'jobs', 'status', 'xliff', 'download' ) ) ) {
			return $this->json_response( $this->status_payload( $path ) );
		}

		// Unknown ATE write path: safe no-op (do not call cloud).
		return $this->json_response(
			array(
				'ok'            => true,
				'authenticated' => true,
				'success'       => true,
				'message'       => 'Handled by Traducción AI',
			)
		);
	}

	/**
	 * Endpoints WPML needs for the wizard calculation UI (pass through to cloud).
	 *
	 * @param string $path   URL path.
	 * @param string $method HTTP method.
	 * @return bool
	 */
	private function should_passthrough_for_ui( $path, $method ) {
		$path = strtolower( (string) $path );

		$pass_needles = array(
			'engines',
			'available_formalities',
			'check_pairs',
			'languages',
			'glossary',
			'website_contexts',
			'websites',
			'clients',
			'translators',
			'translation_managers',
			'access_keys',
			'autologin',
		);

		foreach ( $pass_needles as $needle ) {
			if ( false === strpos( $path, $needle ) ) {
				continue;
			}
			// Never passthrough job create / sync / xliff.
			if ( $this->path_matches( $path, array( '/jobs', 'sync', 'xliff', 'download' ) ) ) {
				return false;
			}
			return true;
		}

		return false;
	}

	/**
	 * Whether URL targets WPML cloud.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_wpml_cloud_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		$allowed = array(
			'ate.wpml.org',
			'ams.wpml.org',
			'tp.wpml.org',
		);
		if ( in_array( $host, $allowed, true ) ) {
			return true;
		}

		// Strict subdomain suffix: foo.ate.wpml.org, not ate.wpml.org.evil.com.
		foreach ( $allowed as $base ) {
			$suffix = '.' . $base;
			$len    = strlen( $suffix );
			if ( strlen( $host ) > $len && substr( $host, -$len ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Path contains any needle.
	 *
	 * @param string   $path    Path.
	 * @param string[] $needles Needles.
	 * @return bool
	 */
	private function path_matches( $path, array $needles ) {
		$path = strtolower( $path );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $path, strtolower( $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fake credits payload.
	 *
	 * @return array
	 */
	private function credits_payload() {
		return array(
			'free_credits_amount'     => 999999,
			'active_subscription'     => true,
			'subscription_usage'      => 0,
			'available_balance'       => 999999,
			'total_credits_deposited' => 999999,
			'total_credits_spent'     => 0,
			'pay_as_you_go'           => true,
			'subscription_max_limit'  => null,
			'subscription_debt'       => 0,
			'account_balance'         => 999999,
			'credits_remaining'       => 999999,
			'total_credits'           => 999999,
			'credits'                 => 999999,
			'authenticated'           => true,
			'success'                 => true,
		);
	}

	/**
	 * Fake account balances payload (AMS).
	 *
	 * @return array
	 */
	private function account_balances_payload() {
		return array(
			'account_balance' => 999999,
			'redirect_url'    => '',
			'authenticated'   => true,
			'success'         => true,
		);
	}

	/**
	 * Fake translation engines list expected by WPML EnginesService.
	 *
	 * @return array
	 */
	private function engines_payload() {
		$provider = $this->settings->get_provider();
		$label    = Settings::PROVIDER_MINIMAX === $provider ? 'MiniMax (local)' : 'Ollama (local)';

		return array(
			'list'          => array(
				array(
					'engine'              => 'google',
					'formal_name'         => $label,
					'cost'                => 0,
					'enabled'             => true,
					'formality_available' => false,
				),
				array(
					'engine'              => 'microsoft',
					'formal_name'         => 'Microsoft (proxy local)',
					'cost'                => 0,
					'enabled'             => false,
					'formality_available' => false,
				),
				array(
					'engine'              => 'deepl',
					'formal_name'         => 'DeepL (proxy local)',
					'cost'                => 0,
					'enabled'             => false,
					'formality_available' => true,
					'formality_settings'  => array(
						'languages' => array(),
					),
				),
			),
			'authenticated' => true,
			'success'       => true,
		);
	}

	/**
	 * Fake formalities map for engines.
	 *
	 * @return array
	 */
	private function formalities_payload() {
		return array(
			'engines'       => array(
				'google'     => array(
					'languages' => array(),
				),
				'microsoft'  => array(
					'languages' => array(),
				),
				'deepl'      => array(
					'languages' => array(),
				),
			),
			'authenticated' => true,
			'success'       => true,
		);
	}

	/**
	 * Fake check_pairs so TE accepts ES→EN and EN→ES.
	 *
	 * @param mixed $body Body.
	 * @return array
	 */
	private function check_pairs_payload( $body ) {
		$decoded = $this->decode_body( $body );
		$results = array();

		$rows = $decoded;
		if ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
			$rows = $decoded;
		} elseif ( isset( $decoded['source_language'] ) ) {
			$rows = array( $decoded );
		}

		if ( empty( $rows ) || ! isset( $rows[0] ) ) {
			$rows = array(
				array(
					'source_language'  => 'es',
					'target_languages' => array( 'en', 'es' ),
				),
				array(
					'source_language'  => 'en',
					'target_languages' => array( 'es', 'en' ),
				),
			);
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source  = isset( $row['source_language'] ) ? (string) $row['source_language'] : 'es';
			$targets = isset( $row['target_languages'] ) && is_array( $row['target_languages'] ) ? $row['target_languages'] : array( 'en', 'es' );
			$map     = array();
			foreach ( $targets as $target ) {
				$target = (string) $target;
				if ( $target === $source ) {
					$map[ $target ] = null;
					continue;
				}
				$map[ $target ] = array(
					'ate_source'    => $source,
					'ate_target'    => $target,
					'engine'        => 'google',
					'engine_source' => $source,
					'engine_target' => $target,
				);
			}
			$results[] = array(
				'source_language'  => $source,
				'target_languages' => $map,
			);
		}

		return array(
			'results'       => $results,
			'authenticated' => true,
			'success'       => true,
		);
	}

	/**
	 * Fake language details (iso codes for engine eligibility).
	 *
	 * WPML reads Obj::prop( 'website_language' ) or Obj::prop( 'language' ).
	 *
	 * @param string $code Lang code.
	 * @return array
	 */
	private function language_details_payload( $code ) {
		$code = strtolower( (string) $code );
		$iso  = 'es' === $code ? 'es' : ( 'en' === $code ? 'en' : $code );
		$lang = array(
			'code'           => $code,
			'name'           => strtoupper( $code ),
			'ms_api_iso'     => $iso,
			'google_api_iso' => $iso,
			'deepl_api_iso'  => $iso,
		);

		return array(
			'website_language' => $lang,
			'language'         => $lang,
			'authenticated'    => true,
			'success'          => true,
		);
	}

	/**
	 * Fake available languages list.
	 *
	 * @return array
	 */
	private function available_languages_payload() {
		return array(
			array(
				'code' => 'en',
				'name' => 'English',
			),
			array(
				'code' => 'es',
				'name' => 'Spanish',
			),
		);
	}

	/**
	 * Build create-jobs response from request body.
	 *
	 * @param mixed $body Body.
	 * @return array
	 */
	private function create_jobs_payload( $body ) {
		$jobs_map = array();
		$decoded  = $this->decode_body( $body );

		$job_list = array();
		if ( isset( $decoded['jobs'] ) && is_array( $decoded['jobs'] ) ) {
			$job_list = $decoded['jobs'];
		}

		$i = 0;
		foreach ( $job_list as $job ) {
			$rid = null;
			if ( is_array( $job ) ) {
				$rid = isset( $job['source_id'] ) ? $job['source_id'] : null;
				if ( null === $rid && isset( $job['id'] ) ) {
					$rid = $job['id'];
				}
			} elseif ( is_object( $job ) ) {
				$rid = isset( $job->source_id ) ? $job->source_id : null;
				if ( null === $rid && isset( $job->id ) ) {
					$rid = $job->id;
				}
			}
			if ( null === $rid || '' === $rid ) {
				continue;
			}
			$jobs_map[ (string) $rid ] = $this->ate_id_base + $i;
			++$i;
		}

		// Fallback: if body already maps rid=>something.
		if ( empty( $jobs_map ) && isset( $decoded['jobs'] ) && $this->is_assoc( $decoded['jobs'] ) ) {
			foreach ( $decoded['jobs'] as $rid => $maybe_id ) {
				if ( is_numeric( $rid ) ) {
					$jobs_map[ (string) $rid ] = $this->ate_id_base + $i;
					++$i;
				}
			}
		}

		return array(
			'jobs'          => empty( $jobs_map ) ? new \stdClass() : $jobs_map,
			'authenticated' => true,
			'success'       => true,
		);
	}

	/**
	 * When ATE create-jobs is faked, enqueue matching WPML job IDs for MiniMax/Ollama.
	 *
	 * @param mixed $body Request body.
	 */
	private function enqueue_wpml_jobs_from_create_body( $body ) {
		$decoded  = $this->decode_body( $body );
		$job_ids  = array();
		$job_list = isset( $decoded['jobs'] ) && is_array( $decoded['jobs'] ) ? $decoded['jobs'] : array();
		foreach ( $job_list as $job ) {
			$id = null;
			if ( is_array( $job ) && isset( $job['id'] ) ) {
				$id = $job['id'];
			} elseif ( is_object( $job ) && isset( $job->id ) ) {
				$id = $job->id;
			}
			if ( is_numeric( $id ) ) {
				$job_ids[] = (int) $id;
			}
		}
		if ( empty( $job_ids ) ) {
			return;
		}
		// Schedule via option + single events without bootstrapping full Queue instance cycles.
		$pending = get_option( Queue::OPTION, array() );
		$pending = is_array( $pending ) ? $pending : array();
		foreach ( $job_ids as $job_id ) {
			$pending[] = $job_id;
			if ( ! wp_next_scheduled( Queue::HOOK, array( $job_id, true ) ) ) {
				wp_schedule_single_event( time() + 2, Queue::HOOK, array( $job_id, true ) );
			}
		}
		update_option( Queue::OPTION, array_values( array_unique( array_map( 'intval', $pending ) ) ), false );
		spawn_cron();
	}

	/**
	 * Status / sync style payload.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	private function status_payload( $path ) {
		return array(
			'authenticated' => true,
			'status'        => 'complete',
			'status_id'     => 10,
			'jobs'          => new \stdClass(),
			'path'          => $path,
			'message'       => 'Completed by Traducción AI',
		);
	}

	/**
	 * Decode request body to array.
	 *
	 * @param mixed $body Body.
	 * @return array
	 */
	private function decode_body( $body ) {
		if ( is_array( $body ) ) {
			return $body;
		}
		if ( ! is_string( $body ) || '' === $body ) {
			return array();
		}
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Associative array check.
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Build a WP_Http-style response.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	/**
	 * Build a WP_Http-style response.
	 *
	 * @param mixed $payload Payload (array/list/object-compatible).
	 * @return array
	 */
	private function json_response( $payload ) {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
