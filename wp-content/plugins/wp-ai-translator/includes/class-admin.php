<?php
/**
 * Admin settings UI (secure configuration via WordPress only).
 *
 * @package WPAITranslator
 */

namespace WPAITranslator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings page.
 */
class Admin {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $menu_slug = 'wp-ai-translator';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpai_test_provider', array( $this, 'ajax_test_provider' ) );
	}

	/**
	 * Top-level admin menu (easier to find than Settings submenu).
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Traducción AI (WPML)', 'wp-ai-translator' ),
			__( 'Traducción AI', 'wp-ai-translator' ),
			'manage_options',
			$this->menu_slug,
			array( $this, 'render_page' ),
			'dashicons-translation',
			58
		);
		add_submenu_page(
			$this->menu_slug,
			__( 'Configuración', 'wp-ai-translator' ),
			__( 'Configuración', 'wp-ai-translator' ),
			'manage_options',
			$this->menu_slug,
			array( $this, 'render_page' )
		);
		// Keep under Settings for discoverability.
		add_options_page(
			__( 'Traducción AI (WPML)', 'wp-ai-translator' ),
			__( 'Traducción AI', 'wp-ai-translator' ),
			'manage_options',
			$this->menu_slug . '-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Assets for settings page.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_assets( $hook ) {
		$ok_hooks = array(
			'toplevel_page_' . $this->menu_slug,
			'settings_page_' . $this->menu_slug . '-settings',
		);
		if ( ! in_array( $hook, $ok_hooks, true ) ) {
			return;
		}
		wp_add_inline_style(
			'wp-admin',
			'.wpai-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:16px 0;max-width:820px}'
			. '.wpai-card h2{margin-top:0}'
			. '.wpai-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600}'
			. '.wpai-status.ok{background:#d5f5e3;color:#0e6245}'
			. '.wpai-status.warn{background:#fcf0e3;color:#8a6116}'
			. '.wpai-provider-panels .wpai-panel{display:none}'
			. '.wpai-provider-panels .wpai-panel.is-active{display:block}'
			. '.wpai-secret-hint{color:#646970;font-size:12px}'
		);
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script(
			'jquery',
			'jQuery(function($){'
			. 'function wpaiShowProvider(){var v=$("#wpai_provider").val();$(".wpai-panel").removeClass("is-active");$(".wpai-panel[data-provider=\""+v+"\"]").addClass("is-active");}'
			. '$("#wpai_provider").on("change",wpaiShowProvider);'
			. 'wpaiShowProvider();'
			. '$("#wpai-test-btn").on("click",function(e){e.preventDefault();var $b=$(this),$o=$("#wpai-test-out");$b.prop("disabled",true);$o.text("...");'
			. '$.post(ajaxurl,{action:"wpai_test_provider",nonce:"' . esc_js( wp_create_nonce( 'wpai_test_provider' ) ) . '"}).done(function(r){'
			. '$o.text((r&&r.success)?r.data.message:(r&&r.data&&r.data.message)||"Error");}).fail(function(){$o.text("Error de red");}).always(function(){$b.prop("disabled",false);});});'
			. '});'
		);
	}

	/**
	 * Settings API.
	 */
	public function register_settings() {
		register_setting(
			Settings::OPTION_GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * AJAX connectivity test for active provider.
	 */
	public function ajax_test_provider() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'wpai_test_provider', 'nonce' );

		$this->settings->flush();
		$client = ( new Translator_Factory( $this->settings ) )->make();
		$result = $client->translate( 'Hello', 'en', 'es' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: provider, 2: sample translation */
					__( 'OK (%1$s): %2$s', 'wp-ai-translator' ),
					$this->settings->get_provider(),
					wp_strip_all_tags( (string) $result )
				),
			)
		);
	}

	/**
	 * Render a secret password field with keep/clear semantics.
	 *
	 * @param string $name     Option array name.
	 * @param string $field    Field key.
	 * @param bool   $is_set   Whether a value is stored.
	 * @param string $plain    Current plain value (for mask only).
	 * @param string $help     Help text.
	 */
	private function render_secret_field( $name, $field, $is_set, $plain, $help ) {
		$clear = $field . '_clear';
		?>
		<input
			type="password"
			id="<?php echo esc_attr( $field ); ?>"
			class="regular-text"
			name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $field ); ?>]"
			value=""
			autocomplete="new-password"
			placeholder="<?php echo $is_set ? esc_attr( Secrets::mask( $plain ) ) : ''; ?>"
		/>
		<p class="description"><?php echo esc_html( $help ); ?></p>
		<?php if ( $is_set ) : ?>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $clear ); ?>]" value="1" />
				<?php echo esc_html__( 'Borrar valor guardado', 'wp-ai-translator' ); ?>
			</label>
			<p class="description"><?php echo esc_html__( 'Campo vacío = se conserva la clave actual. Marcá “Borrar…” para eliminarla. Escribí una nueva para reemplazarla.', 'wp-ai-translator' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->settings->flush();
		$s        = $this->settings->all();
		$provider = $this->settings->get_provider();
		$ready    = $this->settings->is_provider_ready( $provider );
		$name     = Settings::OPTION_NAME;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( ! Secrets::is_available() ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html__( 'OpenSSL no está disponible en PHP: no se pueden guardar ni leer secretos cifrados.', 'wp-ai-translator' ); ?></p></div>
			<?php endif; ?>
			<?php settings_errors( Settings::OPTION_NAME ); ?>

			<div class="wpai-card">
				<strong><?php echo esc_html__( 'Estado', 'wp-ai-translator' ); ?>:</strong>
				<span class="wpai-status <?php echo $ready ? 'ok' : 'warn'; ?>">
					<?php
					echo $ready
						? esc_html__( 'Proveedor listo', 'wp-ai-translator' )
						: esc_html__( 'Falta configuración', 'wp-ai-translator' );
					?>
				</span>
				&nbsp;·&nbsp;
				<?php
				printf(
					/* translators: %s: provider */
					esc_html__( 'Activo: %s', 'wp-ai-translator' ),
					esc_html( $provider )
				);
				?>
				&nbsp;·&nbsp;
				<?php
				echo ! empty( $s['wpml_engine'] )
					? esc_html__( 'Motor WPML: ON', 'wp-ai-translator' )
					: esc_html__( 'Motor WPML: OFF', 'wp-ai-translator' );
				?>
				<?php if ( Rate_Limit::is_paused() ) : ?>
					&nbsp;·&nbsp;
					<span class="wpai-status warn">
						<?php
						printf(
							/* translators: %s: datetime */
							esc_html__( 'Rate limit: pausa hasta %s', 'wp-ai-translator' ),
							esc_html( wp_date( 'Y-m-d H:i', Rate_Limit::paused_until() ) )
						);
						?>
					</span>
					<p class="description"><?php echo esc_html( Rate_Limit::reason() ); ?></p>
				<?php endif; ?>
				<?php
				$pending = get_option( Queue::OPTION, array() );
				$pending = is_array( $pending ) ? $pending : array();
				if ( ! empty( $pending ) ) :
					?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: pending jobs */
							esc_html__( 'Cola: %d job(s) pendientes.', 'wp-ai-translator' ),
							count( $pending )
						);
						?>
						—
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-queue' ) ); ?>">
							<?php echo esc_html__( 'Ver cola', 'wp-ai-translator' ); ?>
						</a>
					</p>
				<?php endif; ?>
				<p>
					<button type="button" class="button" id="wpai-test-btn"><?php echo esc_html__( 'Probar proveedor', 'wp-ai-translator' ); ?></button>
					<span id="wpai-test-out" class="wpai-secret-hint"></span>
				</p>
			</div>

			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( Settings::OPTION_GROUP ); ?>

				<div class="wpai-card">
					<h2><?php echo esc_html__( 'Proveedor de traducción', 'wp-ai-translator' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpai_provider"><?php echo esc_html__( 'Motor', 'wp-ai-translator' ); ?></label></th>
							<td>
								<select id="wpai_provider" name="<?php echo esc_attr( $name ); ?>[provider]">
									<option value="ollama" <?php selected( $provider, Settings::PROVIDER_OLLAMA ); ?>>Ollama</option>
									<option value="minimax" <?php selected( $provider, Settings::PROVIDER_MINIMAX ); ?>>MiniMax</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Motor WPML', 'wp-ai-translator' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[wpml_engine]" value="1" <?php checked( ! empty( $s['wpml_engine'] ) ); ?> <?php disabled( ! defined( 'ICL_SITEPRESS_VERSION' ) ); ?> />
									<?php echo esc_html__( 'Usar este proveedor como motor de WPML (auto + manual, sin créditos ATE)', 'wp-ai-translator' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Preview ?wpai_lang=', 'wp-ai-translator' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enable_public_lang_preview]" value="1" <?php checked( ! empty( $s['enable_public_lang_preview'] ) ); ?> />
									<?php echo esc_html__( 'Permitir preview de traducción en frontend solo para administradores (desactivado por defecto)', 'wp-ai-translator' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( 'Si está apagado, ?wpai_lang= no hace nada. Nunca traduce para visitantes anónimos.', 'wp-ai-translator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpai_prompt"><?php echo esc_html__( 'Prompt base', 'wp-ai-translator' ); ?></label></th>
							<td>
								<textarea id="wpai_prompt" name="<?php echo esc_attr( $name ); ?>[prompt]" rows="4" class="large-text"><?php echo esc_textarea( $s['prompt'] ); ?></textarea>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpai-provider-panels">
					<div class="wpai-card wpai-panel <?php echo Settings::PROVIDER_OLLAMA === $provider ? 'is-active' : ''; ?>" data-provider="ollama">
						<h2>Ollama</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="ollama_endpoint"><?php echo esc_html__( 'Endpoint', 'wp-ai-translator' ); ?></label></th>
								<td>
									<input type="url" id="ollama_endpoint" class="regular-text" name="<?php echo esc_attr( $name ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://ollama.example.com/api/generate" />
									<p class="description"><?php echo esc_html__( 'URL completa del API, p. ej. https://tu-servidor/api/generate', 'wp-ai-translator' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ollama_model"><?php echo esc_html__( 'Modelo', 'wp-ai-translator' ); ?></label></th>
								<td><input type="text" id="ollama_model" class="regular-text" name="<?php echo esc_attr( $name ); ?>[model]" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="llama3.1:8b" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="ollama_user"><?php echo esc_html__( 'Usuario HTTP', 'wp-ai-translator' ); ?></label></th>
								<td>
									<?php
									$this->render_secret_field(
										$name,
										'ollama_user',
										! empty( $s['ollama_user_set'] ),
										(string) $s['user'],
										__( 'Opcional. Basic Auth. Vacío = conservar. Se cifra en la BD.', 'wp-ai-translator' )
									);
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ollama_password"><?php echo esc_html__( 'Password HTTP', 'wp-ai-translator' ); ?></label></th>
								<td>
									<?php
									$this->render_secret_field(
										$name,
										'ollama_password',
										! empty( $s['ollama_password_set'] ),
										(string) $s['password'],
										__( 'Opcional. Vacío = conservar. Se cifra en la BD.', 'wp-ai-translator' )
									);
									?>
								</td>
							</tr>
						</table>
					</div>

					<div class="wpai-card wpai-panel <?php echo Settings::PROVIDER_MINIMAX === $provider ? 'is-active' : ''; ?>" data-provider="minimax">
						<h2>MiniMax</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="minimax_base_url"><?php echo esc_html__( 'Base URL', 'wp-ai-translator' ); ?></label></th>
								<td>
									<input type="url" id="minimax_base_url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[minimax_base_url]" value="<?php echo esc_attr( $s['minimax_base_url'] ); ?>" placeholder="https://api.minimax.io/v1" />
									<p class="description"><?php echo esc_html__( 'OpenAI-compatible: se llama a /chat/completions. Solo https://api.minimax.io o api.minimaxi.com.', 'wp-ai-translator' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="minimax_model"><?php echo esc_html__( 'Modelo', 'wp-ai-translator' ); ?></label></th>
								<td><input type="text" id="minimax_model" class="regular-text" name="<?php echo esc_attr( $name ); ?>[minimax_model]" value="<?php echo esc_attr( $s['minimax_model'] ); ?>" placeholder="MiniMax-M3" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="minimax_api_key"><?php echo esc_html__( 'API key', 'wp-ai-translator' ); ?></label></th>
								<td>
									<?php
									$this->render_secret_field(
										$name,
										'minimax_api_key',
										! empty( $s['minimax_api_key_set'] ),
										(string) $s['minimax_api_key'],
										__( 'Obligatoria para MiniMax. Vacío conserva la actual; usá el checkbox para borrar.', 'wp-ai-translator' )
									);
									?>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<?php submit_button( __( 'Guardar configuración', 'wp-ai-translator' ) ); ?>
			</form>

			<div class="wpai-card">
				<h2><?php echo esc_html__( 'Últimos errores', 'wp-ai-translator' ); ?></h2>
				<?php
				$errors = $this->logger->recent();
				if ( empty( $errors ) ) {
					echo '<p>' . esc_html__( 'Sin errores recientes.', 'wp-ai-translator' ) . '</p>';
				} else {
					echo '<ul>';
					foreach ( array_slice( $errors, 0, 10 ) as $error ) {
						$job = isset( $error['context']['job_id'] ) ? ' job#' . (int) $error['context']['job_id'] : '';
						printf(
							'<li><code>%s</code> %s%s</li>',
							esc_html( gmdate( 'Y-m-d H:i:s', (int) $error['time'] ) ),
							esc_html( $error['message'] ),
							esc_html( $job )
						);
					}
					echo '</ul>';
				}
				?>
			</div>
		</div>
		<?php
	}
}
