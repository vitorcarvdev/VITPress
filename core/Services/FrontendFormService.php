<?php
namespace Hub\Core\Services;

use Hub\Core\Database;
use Hub\Core\Tracker;
use Hub\Core\Services\Integrations\VitAdsEventService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço responsável por capturar leads via formulário frontend (shortcode [hub_form]).
 */
class FrontendFormService {

	public function init() {
		// Registra o shortcode
		add_shortcode( 'hub_form', array( $this, 'render_form_shortcode' ) );

		// Registra a rota da REST API
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Registra o endpoint REST API do formulário.
	 */
	public function register_rest_routes() {
		register_rest_route( 'hub/v1', '/leads', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_rest_submission' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Renderiza o HTML do formulário quando o shortcode [hub_form] for chamado.
	 */
	public function render_form_shortcode( $atts ) {
		// CSS e JS Inline para manter a simplicidade e portabilidade do shortcode
		ob_start();
		?>
		<style>
			.hub-frontend-form {
				max-width: 400px;
				margin: 0 auto;
				padding: 20px;
				background: #fff;
				border-radius: 8px;
				box-shadow: 0 4px 15px rgba(0,0,0,0.05);
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			}
			.hub-frontend-form .hub-form-group {
				margin-bottom: 15px;
			}
			.hub-frontend-form label {
				display: block;
				margin-bottom: 5px;
				font-weight: 600;
				color: #333;
			}
			.hub-frontend-form input[type="text"],
			.hub-frontend-form input[type="email"],
			.hub-frontend-form input[type="tel"] {
				width: 100%;
				padding: 10px;
				border: 1px solid #ccc;
				border-radius: 4px;
				box-sizing: border-box;
				transition: border-color 0.3s;
			}
			.hub-frontend-form input[type="text"]:focus,
			.hub-frontend-form input[type="email"]:focus,
			.hub-frontend-form input[type="tel"]:focus {
				border-color: #0073aa;
				outline: none;
			}
			.hub-frontend-form button {
				width: 100%;
				padding: 12px;
				background-color: #0073aa;
				color: #fff;
				border: none;
				border-radius: 4px;
				font-size: 16px;
				font-weight: 600;
				cursor: pointer;
				transition: background-color 0.3s;
			}
			.hub-frontend-form button:hover {
				background-color: #005177;
			}
			.hub-frontend-form .hub-form-message {
				margin-top: 15px;
				padding: 10px;
				border-radius: 4px;
				display: none;
			}
			.hub-frontend-form .hub-form-message.success {
				background-color: #d4edda;
				color: #155724;
				border: 1px solid #c3e6cb;
				display: block;
			}
			.hub-frontend-form .hub-form-message.error {
				background-color: #f8d7da;
				color: #721c24;
				border: 1px solid #f5c6cb;
				display: block;
			}
		</style>

		<div class="hub-frontend-form-container">
			<form class="hub-frontend-form" id="hubFrontendForm">
				<input type="text" name="hub_website_hp" id="hub_website_hp" value="" style="display:none !important;" tabindex="-1" autocomplete="off" aria-hidden="true">
				
				<div class="hub-form-group">
					<label for="hub_name">Nome</label>
					<input type="text" id="hub_name" name="hub_name" required placeholder="Seu nome completo">
				</div>

				<div class="hub-form-group">
					<label for="hub_email">E-mail</label>
					<input type="email" id="hub_email" name="hub_email" required placeholder="seu.email@exemplo.com">
				</div>

				<div class="hub-form-group">
					<label for="hub_phone">WhatsApp / Telefone</label>
					<input type="tel" id="hub_phone" name="hub_phone" required placeholder="(00) 00000-0000">
				</div>

				<button type="submit" id="hubSubmitBtn">Enviar Solicitação</button>
				<div class="hub-form-message" id="hubFormMessage"></div>
			</form>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				var form = document.getElementById('hubFrontendForm');
				if (form) {
					form.addEventListener('submit', function(e) {
						e.preventDefault();

						var btn = document.getElementById('hubSubmitBtn');
						var msg = document.getElementById('hubFormMessage');
						var formData = new FormData(form);
						
						formData.append('conversion_page', window.location.pathname);
						
						if (window.HubConsent) {
							formData.append('ad_user_data_consent', window.HubConsent.getStatus());
						} else {
							formData.append('ad_user_data_consent', 'unknown');
						}

						btn.disabled = true;
						btn.innerText = 'Enviando...';
						msg.className = 'hub-form-message';
						msg.innerText = '';

						fetch('<?php echo esc_url_raw( rest_url( 'hub/v1/leads' ) ); ?>', {
							method: 'POST',
							body: formData,
							credentials: 'same-origin'
						})
						.then(response => response.json())
						.then(data => {
							btn.disabled = false;
							btn.innerText = 'Enviar Solicitação';

							if (data && data.success) {
								msg.classList.add('success');
								msg.innerText = data.message ? data.message : 'Recebemos seu contato com sucesso! Em breve retornaremos.';
								form.reset();

								// Dispara o evento de Lead do Meta Pixel via JS (para deduplicação com o CAPI)
								var isDebug = (typeof hubTrackerConfig !== 'undefined' && hubTrackerConfig.meta_debug);
								if (isDebug) {
									console.log('--- HUB FORM SUCCESS ---');
									console.log('EVENT ID RECEIVED:', data.event_id);
									console.log('FBQ AVAILABLE:', typeof fbq === 'function');
									console.log('WINDOW.FBQ AVAILABLE:', typeof window.fbq === 'function');
								}
								
								if (typeof fbq === 'function' && data.event_id) {
									if (isDebug) console.log('DISPARANDO FBQ TRACK LEAD COM EVENT ID:', data.event_id);
									fbq('track', 'Lead', {}, { eventID: data.event_id });
								} else if (typeof window.fbq === 'function' && data.event_id) {
									if (isDebug) console.log('DISPARANDO WINDOW.FBQ TRACK LEAD COM EVENT ID:', data.event_id);
									window.fbq('track', 'Lead', {}, { eventID: data.event_id });
								} else {
									if (isDebug) console.log('FBQ NAO ENCONTRADO NO ESCOPO PARA DISPARO.');
								}
								
								if (isDebug) console.log('------------------------');

								if (data.redirect_url) {
									msg.innerText += ' Redirecionando...';
									setTimeout(function() {
										window.location.href = data.redirect_url;
									}, 1500);
								}
							} else {
								msg.classList.add('error');
								msg.innerText = data.message ? data.message : 'Ocorreu um erro ao enviar. Tente novamente. (Retorno: ' + JSON.stringify(data) + ')';
							}
						})
						.catch(error => {
							btn.disabled = false;
							btn.innerText = 'Enviar Solicitação';
							msg.classList.add('error');
							msg.innerText = 'Erro de comunicação com o servidor.';
						});
					});
				}
			});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Processa o envio via REST API, insere o Lead e captura UTMs nativamente do Tracker.
	 *
	 * @param \WP_REST_Request $request Objeto da requisição REST.
	 * @return \WP_REST_Response
	 */
	public function handle_rest_submission( \WP_REST_Request $request ) {
		try {
			return $this->process_rest_submission( $request );
		} catch ( \Throwable $e ) {
			$technical = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $e->getMessage() );
			error_log( '[HUB FORM] exception=' . get_class( $e ) . ' ' . $technical . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => 'Não foi possível processar sua solicitação.',
			), 500 );
		}
	}

	/**
	 * Executa validação, gravação, e-mail e redirecionamento do formulário público.
	 *
	 * @param \WP_REST_Request $request Objeto da requisição REST.
	 * @return \WP_REST_Response
	 */
	private function process_rest_submission( \WP_REST_Request $request ) {
		error_log( '[HUB FORM] request_received' );
		$honeypot = $request->get_param( 'hub_website_hp' );

		// Verifica Honeypot (Prevenção de Spam amigável para Cache)
		if ( ! empty( $honeypot ) ) {
			// Se o campo oculto foi preenchido, é um bot. Simulamos sucesso para enganar o bot.
			return new \WP_REST_Response( array( 'success' => true, 'message' => 'Recebemos seu contato com sucesso!' ), 200 );
		}

		$name  = sanitize_text_field( wp_unslash( $request->get_param( 'hub_name' ) ?? '' ) );
		$email = sanitize_email( wp_unslash( $request->get_param( 'hub_email' ) ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $request->get_param( 'hub_phone' ) ?? '' ) );

		if ( empty( $name ) || empty( $email ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Por favor, preencha nome e e-mail.' ), 400 );
		}

		error_log( '[HUB FORM] validation_ok' );

		// Coleta UTMs e IDs do Tracker
		$tracking_data = Tracker::get_current_tracking_data();
		$created_at    = current_time( 'mysql' );
		$initial_stage = array_key_first( \Hub\Modules\Pipeline\PipelineModule::get_stage_definitions() );

		$data = array(
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'source'       => 'site_form',
			'stage'        => $initial_stage,
			'stage_updated_at' => $created_at,
			'stage_source' => 'vitpress_form',
			'gclid'        => $tracking_data['gclid'] ?? '',
			'gbraid'       => $tracking_data['gbraid'] ?? '',
			'wbraid'       => $tracking_data['wbraid'] ?? '',
			'fbclid'       => $tracking_data['fbclid'] ?? '',
			'utm_source'   => $tracking_data['utm_source'] ?? '',
			'utm_medium'   => $tracking_data['utm_medium'] ?? '',
			'utm_campaign' => $tracking_data['utm_campaign'] ?? '',
			'utm_term'     => $tracking_data['utm_term'] ?? '',
			'utm_content'  => $tracking_data['utm_content'] ?? '',
			
			// Novos campos avançados do Tracker
			'first_touch_gclid'        => $tracking_data['first_touch_gclid'] ?? '',
			'first_touch_fbclid'       => $tracking_data['first_touch_fbclid'] ?? '',
			'first_touch_utm_source'   => $tracking_data['first_touch_utm_source'] ?? '',
			'first_touch_utm_medium'   => $tracking_data['first_touch_utm_medium'] ?? '',
			'first_touch_utm_campaign' => $tracking_data['first_touch_utm_campaign'] ?? '',
			'first_touch_utm_term'     => $tracking_data['first_touch_utm_term'] ?? '',
			'first_touch_utm_content'  => $tracking_data['first_touch_utm_content'] ?? '',
			
			'session_id'           => $tracking_data['session_id'] ?? '',
			'external_referrer'    => $tracking_data['external_referrer'] ?? '',
			'first_visit_datetime' => $tracking_data['first_visit_datetime'] ?? null,
			'landing_url'          => $tracking_data['landing_url'] ?? '',
			'landing_path'         => $tracking_data['landing_path'] ?? '',
			'landing_query'        => $tracking_data['landing_query'] ?? '',
			'conversion_page'      => sanitize_text_field( wp_unslash( $request->get_param( 'conversion_page' ) ?? '' ) ),
			'pageviews_count'      => isset( $tracking_data['pageviews_count'] ) ? absint( $tracking_data['pageviews_count'] ) : 1,
			'time_to_conversion'   => $tracking_data['time_to_conversion'] ?? 0,
			
			'ad_user_data_consent' => sanitize_text_field( wp_unslash( $request->get_param( 'ad_user_data_consent' ) ?? 'unknown' ) ),
			'consent_source'       => 'site_form',
			
			'user_agent'  => $tracking_data['user_agent'] ?? '',
			'client_ip_address' => $tracking_data['client_ip_address'] ?? '',
			'_fbp'        => $tracking_data['_fbp'] ?? '',
			'_fbc'        => $tracking_data['_fbc'] ?? '',
			'device_type' => $tracking_data['device_type'] ?? 'Desktop',
			'browser'     => $tracking_data['browser'] ?? 'Desconhecido',
			'os'          => $tracking_data['os'] ?? 'Desconhecido',
			
			'created_at'   => $created_at,
			'updated_at'   => $created_at,
		);

		global $wpdb;
		$wpdb->suppress_errors(true);
		
		$inserted = Database::db()->insert( Database::table( 'leads' ), $data );

		if ( $inserted ) {
			$lead_id = Database::db()->insert_id;
			Database::log_history( 'lead', $lead_id, 'created', 'Lead capturado automaticamente pelo formulário do site.' );
			error_log( '[HUB FORM] persistence_ok' );
			
			// Dispara evento de conversão. Falha de integração não pode derrubar a resposta do visitante.
			try {
				do_action( 'hub/lead_created', $lead_id );
			} catch ( \Throwable $e ) {
				$technical = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $e->getMessage() );
				error_log( '[HUB FORM] exception=' . get_class( $e ) . ' ' . $technical . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
			}
			
			// Processa redirecionamento para o WhatsApp
			$settings = get_option( 'hub_settings', array() );
			$forms    = isset( $settings['forms'] ) ? $settings['forms'] : array();
			
			// Envia e-mails de notificação e confirmação
			$receive_email = isset( $forms['receive_email'] ) ? $forms['receive_email'] : '';
			$send_confirm  = ! empty( $forms['send_confirm'] );
			
			if ( ! empty( $receive_email ) ) {
				error_log( '[HUB FORM] mail_service_start' );
				$subject = "[Novo Lead] {$name} - Capturado pelo site";
				$body = "Um novo lead acabou de se cadastrar no site.\n\n";
				$body .= "Nome: {$name}\n";
				$body .= "E-mail: {$email}\n";
				$body .= "Telefone: {$phone}\n\n";
				$body .= "Acesse o Hub para mais detalhes.";
				try {
					$sent = wp_mail( $receive_email, $subject, $body );
					error_log( '[HUB FORM] mail_service_' . ( $sent ? 'success' : 'fail' ) );
				} catch ( \Throwable $e ) {
					$technical = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $e->getMessage() );
					error_log( '[HUB FORM] exception=' . get_class( $e ) . ' ' . $technical . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
				}
			}

			if ( $send_confirm ) {
				$subject_client = "Recebemos o seu contato!";
				$body_client = "Olá {$name},\n\nRecebemos o seu contato com sucesso. Em breve um de nossos consultores falará com você.\n\nAtenciosamente,\nEquipe " . get_bloginfo('name');
				try {
					wp_mail( $email, $subject_client, $body_client );
				} catch ( \Throwable $e ) {
					$technical = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $e->getMessage() );
					error_log( '[HUB FORM] exception=' . get_class( $e ) . ' ' . $technical . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
				}
			}

			$whatsapp = isset( $forms['whatsapp'] ) ? preg_replace( '/\D/', '', $forms['whatsapp'] ) : '';
			$msg_tpl  = isset( $forms['whatsapp_msg'] ) ? $forms['whatsapp_msg'] : 'Olá, sou {nome} e gostaria de saber mais.';
			
			$redirect_url = '';
			$whatsapp_url = '';
			if ( ! empty( $whatsapp ) ) {
				$msg = str_replace( '{nome}', $name, $msg_tpl );
				$whatsapp_url = 'https://api.whatsapp.com/send?phone=' . $whatsapp . '&text=' . urlencode( $msg );
			}
			
			// Gera token seguro e armazena a URL final (ou marcador de sucesso) no Transient
			$token = wp_generate_password( 32, false );
			set_transient( 'hub_redir_' . $token, empty( $whatsapp_url ) ? 'none' : $whatsapp_url, 5 * MINUTE_IN_SECONDS );
			
			$redirect_url = home_url( '/obrigado-contato?hub_token=' . $token );

			VitAdsEventService::send_form_submit( $lead_id, $this->vitads_lead_context( $request, $name, $email, $phone, $tracking_data ) );

			return new \WP_REST_Response( array( 
				'success' => true, 
				'message' => 'Obrigado! Seu contato foi recebido com sucesso.',
				'redirect_url' => $redirect_url,
				'lead_id' => $lead_id,
				'event_id' => 'LEAD-' . $lead_id
			), 200 );
		} else {
			$db_error = Database::db()->last_error;
			if ( empty( $db_error ) ) {
				$db_error = 'Falha desconhecida no banco de dados.';
			}
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Erro interno ao salvar: ' . $db_error ), 500 );
		}
	}

	/**
	 * Monta o contexto do FORM_SUBMIT a partir do lead e do Tracker já existente.
	 *
	 * @param array<string,mixed> $tracking_data
	 * @return array<string,mixed>
	 */
	private function vitads_lead_context( \WP_REST_Request $request, $name, $email, $phone, array $tracking_data ) {
		$pick = function ( $key ) use ( $tracking_data ) {
			$current = isset( $tracking_data[ $key ] ) ? trim( (string) $tracking_data[ $key ] ) : '';
			if ( '' !== $current ) {
				return $current;
			}
			$first = isset( $tracking_data[ 'first_touch_' . $key ] ) ? trim( (string) $tracking_data[ 'first_touch_' . $key ] ) : '';
			return $first;
		};

		return array(
			'name'             => $name,
			'email'            => $email,
			'phone'            => $phone,
			'event_source_url' => $this->event_source_url( $request ),
			'utm_source'       => $pick( 'utm_source' ),
			'utm_medium'       => $pick( 'utm_medium' ),
			'utm_campaign'     => $pick( 'utm_campaign' ),
			'utm_content'      => $pick( 'utm_content' ),
			'utm_term'         => $pick( 'utm_term' ),
			'gclid'            => $pick( 'gclid' ),
			'wbraid'           => $pick( 'wbraid' ),
			'gbraid'           => $pick( 'gbraid' ),
			'fbp'              => isset( $tracking_data['_fbp'] ) ? (string) $tracking_data['_fbp'] : '',
			'fbc'              => isset( $tracking_data['_fbc'] ) ? (string) $tracking_data['_fbc'] : '',
		);
	}

	/**
	 * URL da página em que o formulário foi enviado.
	 */
	private function event_source_url( \WP_REST_Request $request ) {
		$referer = wp_get_referer();
		if ( is_string( $referer ) && '' !== $referer ) {
			$referer  = esc_url_raw( $referer );
			$home     = wp_parse_url( home_url(), PHP_URL_HOST );
			$ref_host = wp_parse_url( $referer, PHP_URL_HOST );
			if ( $referer && $home && $ref_host && 0 === strcasecmp( (string) $home, (string) $ref_host ) ) {
				return $referer;
			}
		}

		$path = sanitize_text_field( wp_unslash( $request->get_param( 'conversion_page' ) ?? '' ) );
		if ( '' !== $path ) {
			return esc_url_raw( home_url( $path ) );
		}

		return home_url( '/' );
	}
}
