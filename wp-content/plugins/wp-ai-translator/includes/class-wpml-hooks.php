<?php
/**
 * WPML hooks for automatic and manual translation.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires WPML job lifecycle to the AI translation queue.
 */
class WPML_Hooks {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Translator.
	 *
	 * @var WPML_Job_Translator
	 */
	private $translator;

	/**
	 * Constructor.
	 *
	 * @param Settings            $settings   Settings.
	 * @param Queue               $queue      Queue.
	 * @param WPML_Job_Translator $translator Translator.
	 */
	public function __construct( Settings $settings, Queue $queue, WPML_Job_Translator $translator ) {
		$this->settings   = $settings;
		$this->queue      = $queue;
		$this->translator = $translator;
	}

	/**
	 * Register hooks when engine is enabled.
	 */
	public function register() {
		// Before ATE (priority 10).
		add_action( 'wpml_added_translation_jobs', array( $this, 'on_jobs_added' ), 5, 3 );
		add_action( 'wpml_added_translation_job', array( $this, 'on_single_job_added' ), 5, 2 );

		add_action( 'wp_ajax_wpai_translate_job', array( $this, 'ajax_translate_job' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_queue_script' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	/**
	 * Enqueue assets on WPML screens.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_admin_assets( $hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'wpml' ) ) {
			return;
		}
		wp_add_inline_style(
			'wp-admin',
			'.wpai-job-btn{margin-left:6px;} .wpai-job-btn[disabled]{opacity:.6;}'
		);
	}

	/**
	 * Handle batch of new jobs.
	 *
	 * @param array                               $jobs     Jobs by service.
	 * @param mixed                               $sent_from Origin.
	 * @param \WPML_TM_Translation_Batch|null      $batch    Batch.
	 */
	public function on_jobs_added( $jobs, $sent_from = null, $batch = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			return;
		}

		$job_ids = array();
		if ( is_array( $jobs ) ) {
			foreach ( $jobs as $group ) {
				if ( ! is_array( $group ) ) {
					if ( is_numeric( $group ) ) {
						$job_ids[] = (int) $group;
					}
					continue;
				}
				foreach ( $group as $maybe_id ) {
					if ( is_numeric( $maybe_id ) ) {
						$job_ids[] = (int) $maybe_id;
					} elseif ( is_object( $maybe_id ) && isset( $maybe_id->job_id ) ) {
						$job_ids[] = (int) $maybe_id->job_id;
					} elseif ( is_array( $maybe_id ) && isset( $maybe_id['job_id'] ) ) {
						$job_ids[] = (int) $maybe_id['job_id'];
					}
				}
			}
		}

		$this->queue->enqueue( $job_ids, true );
	}

	/**
	 * Handle a single job.
	 *
	 * @param int   $job_id              Job ID.
	 * @param mixed $translation_service Service.
	 */
	public function on_single_job_added( $job_id, $translation_service = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			return;
		}
		$this->queue->enqueue( array( (int) $job_id ), true );
	}

	/**
	 * AJAX: translate one job from the WPML queue UI.
	 */
	public function ajax_translate_job() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'translate' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'wpai_translate_job', 'nonce' );

		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Motor WPML AI desactivado.', 'wp-ai-translator' ) ) );
		}

		$job_id   = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$complete = ! isset( $_POST['complete'] ) || (int) $_POST['complete'] === 1;

		$result = $this->translator->translate_job( $job_id, $complete );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'job_id'  => $job_id,
				'message' => __( 'Traducción AI completada.', 'wp-ai-translator' ),
			)
		);
	}

	/**
	 * Inject a small helper on WPML translation queue screens.
	 */
	public function render_queue_script() {
		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'wpml' ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'wpai_translate_job' );
		?>
		<script>
		(function () {
			if (window.wpaiQueueBound) return;
			window.wpaiQueueBound = true;
			window.wpaiTranslateJob = function (jobId, complete) {
				complete = (typeof complete === 'undefined') ? 1 : complete;
				return fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
					body: new URLSearchParams({
						action: 'wpai_translate_job',
						nonce: <?php echo wp_json_encode( $nonce ); ?>,
						job_id: String(jobId),
						complete: String(complete)
					})
				}).then(function (r) { return r.json(); });
			};
			function injectButtons() {
				var links = document.querySelectorAll('a[href*="job_id="], a[href*="jobId="]');
				links.forEach(function (link) {
					if (link.dataset.wpaiBound) return;
					var href = link.getAttribute('href') || '';
					var m = href.match(/job_id=(\d+)/i) || href.match(/jobId=(\d+)/i);
					if (!m) return;
					link.dataset.wpaiBound = '1';
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'button button-small wpai-job-btn';
					btn.setAttribute('data-wpai-job', m[1]);
					btn.textContent = 'AI';
					link.parentNode.insertBefore(btn, link.nextSibling);
				});
			}
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('[data-wpai-job]');
				if (!btn) return;
				e.preventDefault();
				var jobId = btn.getAttribute('data-wpai-job');
				btn.disabled = true;
				window.wpaiTranslateJob(jobId, 1).then(function (res) {
					if (res && res.success) {
						window.location.reload();
					} else {
						alert((res && res.data && res.data.message) || 'Error');
						btn.disabled = false;
					}
				}).catch(function () {
					alert('Error de red');
					btn.disabled = false;
				});
			});
			injectButtons();
			setTimeout(injectButtons, 1500);
		})();
		</script>
		<?php
	}

	/**
	 * Admin notice when engine is on.
	 */
	public function maybe_notice() {
		if ( ! $this->settings->is_wpml_engine_enabled() ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'wpml' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Motor Traducción AI activo para WPML: los trabajos locales se traducen sin créditos ATE. En consola: wpaiTranslateJob(jobId).', 'wp-ai-translator' );
		echo ' <button type="button" class="button button-small" data-wpai-job="" style="display:none"></button>';
		echo '</p></div>';
	}
}
