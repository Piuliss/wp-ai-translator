<?php
/**
 * Admin GUI for the AI translation queue.
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue dashboard under Traducción AI.
 */
class Admin_Queue {

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
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Menu slug parent.
	 *
	 * @var string
	 */
	private $parent_slug;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings    Settings.
	 * @param Queue    $queue       Queue.
	 * @param Logger   $logger      Logger.
	 * @param string   $parent_slug Parent menu.
	 */
	public function __construct( Settings $settings, Queue $queue, Logger $logger, $parent_slug = 'wp-ai-translator' ) {
		$this->settings    = $settings;
		$this->queue       = $queue;
		$this->logger      = $logger;
		$this->parent_slug = $parent_slug;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_wpai_queue_action', array( $this, 'handle_action' ) );
	}

	/**
	 * Submenu.
	 */
	public function register_menu() {
		add_submenu_page(
			$this->parent_slug,
			__( 'Cola de traducción', 'wp-ai-translator' ),
			__( 'Cola', 'wp-ai-translator' ),
			'manage_options',
			$this->parent_slug . '-queue',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle POST actions.
	 */
	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'wp-ai-translator' ) );
		}
		check_admin_referer( 'wpai_queue_action' );

		$action  = isset( $_POST['wpai_action'] ) ? sanitize_key( (string) $_POST['wpai_action'] ) : '';
		$job_id  = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$message = '';

		switch ( $action ) {
			case 'process_one':
				if ( $job_id > 0 ) {
					$res = $this->queue->process_now( $job_id, true );
					$message = is_wp_error( $res )
						? $res->get_error_message()
						: sprintf(
							/* translators: %d: job id */
							__( 'Job #%d procesado.', 'wp-ai-translator' ),
							$job_id
						);
				}
				break;
			case 'remove_one':
				$this->queue->remove_job( $job_id );
				$message = sprintf(
					/* translators: %d: job id */
					__( 'Job #%d quitado de la cola AI.', 'wp-ai-translator' ),
					$job_id
				);
				break;
			case 'clear_all':
				$n       = $this->queue->clear_all();
				$message = sprintf(
					/* translators: %d: count */
					__( 'Cola vaciada (%d jobs).', 'wp-ai-translator' ),
					$n
				);
				break;
			case 'reschedule':
				$n       = $this->queue->reschedule_pending();
				$message = sprintf(
					/* translators: %d: count */
					__( 'Reprogramados %d eventos de cron.', 'wp-ai-translator' ),
					$n
				);
				break;
			case 'clear_pause':
				Rate_Limit::clear();
				$message = __( 'Pausa de rate limit eliminada.', 'wp-ai-translator' );
				break;
			case 'clear_busy':
				$this->queue->clear_busy();
				$message = __( 'Lock de procesamiento liberado.', 'wp-ai-translator' );
				break;
			case 'clear_logs':
				$this->logger->clear();
				$message = __( 'Logs borrados.', 'wp-ai-translator' );
				break;
			case 'process_next':
				$pending = $this->queue->get_pending();
				if ( ! empty( $pending ) ) {
					$res     = $this->queue->process_now( (int) $pending[0], true );
					$message = is_wp_error( $res )
						? $res->get_error_message()
						: sprintf(
							/* translators: %d: job id */
							__( 'Procesado job #%d.', 'wp-ai-translator' ),
							(int) $pending[0]
						);
				} else {
					$message = __( 'La cola está vacía.', 'wp-ai-translator' );
				}
				break;
			default:
				$message = __( 'Acción desconocida.', 'wp-ai-translator' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => $this->parent_slug . '-queue',
					'wpai_notice'  => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render queue page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status  = $this->queue->get_status();
		$pending = $this->queue->get_pending();
		$logs    = $this->logger->recent( 40 );
		$state   = isset( $status['state'] ) ? (string) $status['state'] : 'idle';

		$state_label = array(
			'idle'     => __( 'Inactiva', 'wp-ai-translator' ),
			'working'  => __( 'Trabajando ahora', 'wp-ai-translator' ),
			'queued'   => __( 'En cola (esperando cron)', 'wp-ai-translator' ),
			'stalled'  => __( 'Atascada (sin cron)', 'wp-ai-translator' ),
			'paused'   => __( 'Pausada (rate limit)', 'wp-ai-translator' ),
		);
		$label = isset( $state_label[ $state ] ) ? $state_label[ $state ] : $state;
		$class = in_array( $state, array( 'working', 'queued' ), true ) ? 'ok' : ( in_array( $state, array( 'stalled', 'paused' ), true ) ? 'warn' : '' );

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Cola de traducción AI', 'wp-ai-translator' ); ?></h1>

			<?php if ( ! empty( $_GET['wpai_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( wp_unslash( (string) $_GET['wpai_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?></p></div>
			<?php endif; ?>

			<style>
				.wpai-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:16px 0;max-width:960px}
				.wpai-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;background:#f0f0f1}
				.wpai-status.ok{background:#d5f5e3;color:#0e6245}
				.wpai-status.warn{background:#fcf0e3;color:#8a6116}
				.wpai-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px}
				.wpai-meta div{background:#f6f7f7;padding:10px 12px;border-radius:4px}
				.wpai-meta strong{display:block;font-size:11px;text-transform:uppercase;color:#646970;margin-bottom:4px}
				.wpai-actions form{display:inline-block;margin:0 6px 6px 0}
				.wpai-log-error{color:#b32d2e}
				.wpai-log-info{color:#1d2327}
			</style>

			<div class="wpai-card">
				<p>
					<strong><?php echo esc_html__( 'Estado', 'wp-ai-translator' ); ?>:</strong>
					<span class="wpai-status <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
					<?php if ( ! $this->settings->is_wpml_engine_enabled() ) : ?>
						<span class="wpai-status warn"><?php echo esc_html__( 'Motor WPML OFF', 'wp-ai-translator' ); ?></span>
					<?php endif; ?>
				</p>
				<div class="wpai-meta">
					<div>
						<strong><?php echo esc_html__( 'Pendientes', 'wp-ai-translator' ); ?></strong>
						<?php echo esc_html( (string) $status['pending_count'] ); ?>
					</div>
					<div>
						<strong><?php echo esc_html__( 'Job actual', 'wp-ai-translator' ); ?></strong>
						<?php echo ! empty( $status['current_job'] ) ? '#' . esc_html( (string) $status['current_job'] ) : '—'; ?>
					</div>
					<div>
						<strong><?php echo esc_html__( 'Último job', 'wp-ai-translator' ); ?></strong>
						<?php
						if ( ! empty( $status['last_job'] ) ) {
							$ok = ! empty( $status['last_ok'] );
							echo '#' . esc_html( (string) $status['last_job'] ) . ' · ';
							echo $ok ? esc_html__( 'OK', 'wp-ai-translator' ) : esc_html__( 'Error', 'wp-ai-translator' );
							if ( ! empty( $status['last_elapsed'] ) ) {
								echo ' · ' . esc_html( (string) $status['last_elapsed'] ) . 's';
							}
						} else {
							echo '—';
						}
						?>
					</div>
					<div>
						<strong><?php echo esc_html__( 'Procesados (sesión)', 'wp-ai-translator' ); ?></strong>
						<?php echo esc_html( (string) ( $status['processed_total'] ?? 0 ) ); ?>
					</div>
					<div>
						<strong><?php echo esc_html__( 'Próximo cron', 'wp-ai-translator' ); ?></strong>
						<?php
						echo ! empty( $status['next_cron'] )
							? esc_html( wp_date( 'Y-m-d H:i:s', (int) $status['next_cron'] ) )
							: esc_html__( 'ninguno', 'wp-ai-translator' );
						?>
					</div>
					<div>
						<strong><?php echo esc_html__( 'Último mensaje', 'wp-ai-translator' ); ?></strong>
						<?php echo esc_html( (string) ( $status['last_message'] ?? '—' ) ); ?>
					</div>
				</div>

				<?php if ( ! empty( $status['rate_paused'] ) ) : ?>
					<p class="description" style="margin-top:12px">
						<?php
						printf(
							/* translators: 1: datetime, 2: reason */
							esc_html__( 'Rate limit hasta %1$s — %2$s', 'wp-ai-translator' ),
							esc_html( wp_date( 'Y-m-d H:i', (int) $status['rate_until'] ) ),
							esc_html( (string) $status['rate_reason'] )
						);
						?>
					</p>
				<?php endif; ?>

				<p class="description">
					<?php echo esc_html__( 'Cada job puede tardar 1–3 minutos (contenido largo / MiniMax). Con cientos en cola es normal que tarde horas.', 'wp-ai-translator' ); ?>
				</p>

				<div class="wpai-actions" style="margin-top:14px">
					<?php $this->action_button( 'process_next', __( 'Procesar siguiente ahora', 'wp-ai-translator' ), 'button-primary' ); ?>
					<?php $this->action_button( 'reschedule', __( 'Reprogramar cron', 'wp-ai-translator' ) ); ?>
					<?php if ( ! empty( $status['busy'] ) || 'working' === $state ) : ?>
						<?php $this->action_button( 'clear_busy', __( 'Liberar lock', 'wp-ai-translator' ) ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $status['rate_paused'] ) ) : ?>
						<?php $this->action_button( 'clear_pause', __( 'Quitar pausa rate limit', 'wp-ai-translator' ) ); ?>
					<?php endif; ?>
					<?php $this->action_button( 'clear_all', __( 'Vaciar cola AI', 'wp-ai-translator' ), 'button-link-delete', true ); ?>
				</div>
			</div>

			<div class="wpai-card">
				<h2><?php echo esc_html__( 'Jobs pendientes', 'wp-ai-translator' ); ?></h2>
				<?php if ( empty( $pending ) ) : ?>
					<p><?php echo esc_html__( 'No hay jobs en la cola AI.', 'wp-ai-translator' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Job ID', 'wp-ai-translator' ); ?></th>
								<th><?php echo esc_html__( 'Cron', 'wp-ai-translator' ); ?></th>
								<th><?php echo esc_html__( 'Acciones', 'wp-ai-translator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $pending, 0, 100 ) as $job_id ) : ?>
								<?php
								$cron = wp_next_scheduled( Queue::HOOK, array( (int) $job_id, true ) );
								if ( ! $cron ) {
									$cron = wp_next_scheduled( Queue::HOOK, array( (int) $job_id, false ) );
								}
								?>
								<tr>
									<td>#<?php echo esc_html( (string) $job_id ); ?></td>
									<td>
										<?php
										echo $cron
											? esc_html( wp_date( 'H:i:s', (int) $cron ) )
											: '<span class="wpai-status warn">' . esc_html__( 'sin cron', 'wp-ai-translator' ) . '</span>';
										?>
									</td>
									<td class="wpai-actions">
										<?php $this->action_button( 'process_one', __( 'Procesar', 'wp-ai-translator' ), 'button-small', false, $job_id ); ?>
										<?php $this->action_button( 'remove_one', __( 'Quitar', 'wp-ai-translator' ), 'button-small', false, $job_id ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $pending ) > 100 ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %d: remaining */
								esc_html__( 'Mostrando 100 de %d.', 'wp-ai-translator' ),
								count( $pending )
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="wpai-card">
				<h2><?php echo esc_html__( 'Logs recientes', 'wp-ai-translator' ); ?></h2>
				<?php $this->action_button( 'clear_logs', __( 'Borrar logs', 'wp-ai-translator' ), 'button-small' ); ?>
				<?php if ( empty( $logs ) ) : ?>
					<p><?php echo esc_html__( 'Sin actividad reciente.', 'wp-ai-translator' ); ?></p>
				<?php else : ?>
					<ul>
						<?php foreach ( $logs as $entry ) : ?>
							<?php
							$level = isset( $entry['level'] ) ? (string) $entry['level'] : 'error';
							$job   = isset( $entry['context']['job_id'] ) ? ' job#' . (int) $entry['context']['job_id'] : '';
							$cls   = 'error' === $level ? 'wpai-log-error' : 'wpai-log-info';
							?>
							<li class="<?php echo esc_attr( $cls ); ?>">
								<code><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) ); ?></code>
								[<?php echo esc_html( $level ); ?>]
								<?php echo esc_html( (string) $entry['message'] . $job ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a POST action button.
	 *
	 * @param string $action Action key.
	 * @param string $label  Label.
	 * @param string $class  CSS class.
	 * @param bool   $confirm Confirm dialog.
	 * @param int    $job_id Optional job.
	 */
	private function action_button( $action, $label, $class = 'button', $confirm = false, $job_id = 0 ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo $confirm ? 'onsubmit="return confirm(\'' . esc_js( __( '¿Seguro?', 'wp-ai-translator' ) ) . '\');"' : ''; ?>>
			<?php wp_nonce_field( 'wpai_queue_action' ); ?>
			<input type="hidden" name="action" value="wpai_queue_action" />
			<input type="hidden" name="wpai_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php if ( $job_id > 0 ) : ?>
				<input type="hidden" name="job_id" value="<?php echo esc_attr( (string) $job_id ); ?>" />
			<?php endif; ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
