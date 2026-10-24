<?php
namespace Hub\Core\Services;

use Hub\Core\Database;
use Hub\Core\ProfileManager;
use Hub\Core\Tracker;
use Hub\Core\Services\MailService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ServiÃ§o de FormulÃ¡rios de CaptaÃ§Ã£o
 * ResponsÃ¡vel por renderizar o shortcode e processar a submissÃ£o via AJAX.
 */
class FormService {

	public function __construct() {
		add_shortcode( 'hub_form', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_nopriv_hub_submit_form', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_hub_submit_form', array( $this, 'handle_submit' ) );
		
		add_action( 'wp_ajax_hub_generate_page', array( $this, 'handle_generate_page' ) );
		add_filter( 'template_include', array( $this, 'override_conversion_template' ) );
		add_action( 'init', array( $this, 'ensure_thank_you_page_exists' ) );
	}

	/**
	 * Garante que a pÃ¡gina /obrigado-contato existe no sistema
	 */
	public function ensure_thank_you_page_exists() {
		if ( ! get_page_by_path( 'obrigado-contato' ) ) {
			wp_insert_post( array(
				'post_title'   => 'Obrigado pelo contato',
				'post_name'    => 'obrigado-contato',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '', 
			) );
		}
	}

	/**
	 * Renderiza o shortcode [hub_form]
	 */
	public function render_shortcode() {
		wp_enqueue_script( 'jquery' );
		
		ob_start();
		?>
		<div class="hub-form-wrapper" id="hub-form-wrapper">
			<form id="hub-capture-form" method="post" action="">
				<?php wp_nonce_field( 'hub_submit_form_nonce', 'hub_form_nonce' ); ?>
				
				<div class="hub-form-group">
					<label for="hub_name">Nome Completo *</label>
					<input type="text" id="hub_name" name="hub_name" required placeholder="Seu nome completo">
				</div>

				<div class="hub-form-group">
					<label for="hub_email">E-mail *</label>
					<input type="email" id="hub_email" name="hub_email" required placeholder="seu@email.com">
				</div>

				<div class="hub-form-group">
					<label for="hub_phone">Telefone / WhatsApp *</label>
					<input type="text" id="hub_phone" name="hub_phone" required placeholder="(00) 00000-0000">
				</div>

				<div class="hub-form-group">
					<button type="submit" id="hub_submit_btn" class="hub-btn-submit">Enviar e Falar no WhatsApp</button>
				</div>
				<div id="hub_form_message" style="display:none; margin-top:10px; padding:10px; border-radius:4px;"></div>
			</form>
		</div>

		<style>
		.hub-form-wrapper {
			max-width: 400px;
			margin: 0 auto;
			font-family: inherit;
		}
		.hub-form-group {
			margin-bottom: 15px;
		}
		.hub-form-group label {
			display: block;
			margin-bottom: 5px;
			font-weight: bold;
		}
		.hub-form-group input {
			width: 100%;
			padding: 10px;
			border: 1px solid #ccc;
			border-radius: 4px;
			box-sizing: border-box;
		}
		.hub-btn-submit {
			width: 100%;
			padding: 12px;
			background: #25D366;
			color: #fff;
			border: none;
			border-radius: 4px;
			font-weight: bold;
			cursor: pointer;
			font-size: 16px;
		}
		.hub-btn-submit:hover {
			background: #128C7E;
		}
		.hub-btn-submit:disabled {
			background: #999;
			cursor: not-allowed;
		}
		</style>

		<script>
		jQuery(document).ready(function($) {
			$('#hub-capture-form').on('submit', function(e) {
				e.preventDefault();
				
				var btn = $('#hub_submit_btn');
				var msg = $('#hub_form_message');
				
				btn.prop('disabled', true).text('Enviando...');
				msg.hide();
				
				var data = {
					action: 'hub_submit_form',
					hub_name: $('#hub_name').val(),
					hub_email: $('#hub_email').val(),
					hub_phone: $('#hub_phone').val(),
					hub_nonce: $('#hub_form_nonce').val()
				};
				
				$.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response) {
					if (response.success) {
						msg.css({'background':'#d4edda', 'color':'#155724'}).html('Redirecionando...').show();
						setTimeout(function() {
							window.location.href = response.data.redirect_url;
						}, 2000);
					} else {
						msg.css({'background':'#f8d7da', 'color':'#721c24'}).html(response.data.message || 'Erro ao processar formulÃ¡rio.').show();
						btn.prop('disabled', false).text('Enviar e Falar no WhatsApp');
					}
				}).fail(function() {
					msg.css({'background':'#f8d7da', 'color':'#721c24'}).html('Erro de comunicaÃ§Ã£o com o servidor.').show();
					btn.prop('disabled', false).text('Enviar e Falar no WhatsApp');
				});
			});
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Processa a submissÃ£o via AJAX
	 */
	public function handle_submit() {
		check_ajax_referer( 'hub_submit_form_nonce', 'hub_nonce' );

		$name  = isset( $_POST['hub_name'] ) ? sanitize_text_field( $_POST['hub_name'] ) : '';
		$email = isset( $_POST['hub_email'] ) ? sanitize_email( $_POST['hub_email'] ) : '';
		$phone = isset( $_POST['hub_phone'] ) ? sanitize_text_field( $_POST['hub_phone'] ) : '';

		if ( empty( $name ) || empty( $email ) || empty( $phone ) ) {
			wp_send_json_error( array( 'message' => 'Por favor, preencha todos os campos obrigatÃ³rios.' ) );
		}

		$settings       = Database::get_setting( 'hub_settings', array() );
		$forms_settings = isset( $settings['forms'] ) ? $settings['forms'] : array();
		
		$whatsapp       = isset( $forms_settings['whatsapp'] ) ? $forms_settings['whatsapp'] : '';
		$whatsapp_msg   = isset( $forms_settings['whatsapp_msg'] ) ? $forms_settings['whatsapp_msg'] : 'OlÃ¡, sou {nome} e gostaria de saber mais sobre os serviÃ§os.';
		$receive_email  = isset( $forms_settings['receive_email'] ) ? $forms_settings['receive_email'] : '';
		$send_confirm   = ! empty( $forms_settings['send_confirm'] );

		$profile_type = ProfileManager::get_instance()->get_active_profile() ? ProfileManager::get_instance()->get_active_profile()->get_id() : 'saude';

		// Captura dados de rastreamento do site via Tracker
		$tracking_data = Tracker::get_current_tracking_data();

		$consent_status = 'unknown';
		if ( isset( $_COOKIE['hub_consent_preferences'] ) ) {
			$cookie_data = json_decode( wp_unslash( $_COOKIE['hub_consent_preferences'] ), true );
			if ( isset( $cookie_data['status'] ) ) {
				$consent_status = sanitize_text_field( $cookie_data['status'] );
			}
		}

		$created_at    = current_time( 'mysql' );
		$initial_stage = array_key_first( \Hub\Modules\Pipeline\PipelineModule::get_stage_definitions() );

		$data = array(
			'profile_type' => $profile_type,
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'stage'        => $initial_stage,
			'stage_updated_at' => $created_at,
			'stage_source' => 'vitpress_form',
			'status'       => 'novo',
			'source'       => 'site',
			'gclid'        => $tracking_data['gclid'],
			'gbraid'       => $tracking_data['gbraid'],
			'wbraid'       => $tracking_data['wbraid'],
			'fbclid'       => $tracking_data['fbclid'],
			'utm_source'   => $tracking_data['utm_source'],
			'utm_medium'   => $tracking_data['utm_medium'],
			'utm_campaign' => $tracking_data['utm_campaign'],
			'utm_content'  => $tracking_data['utm_content'],
			'utm_term'     => $tracking_data['utm_term'],
			'client_ip_address' => $tracking_data['client_ip_address'] ?? '',
			'_fbp'        => $tracking_data['_fbp'] ?? '',
			'_fbc'        => $tracking_data['_fbc'] ?? '',
			'ad_user_data_consent' => $consent_status,
			'consent_source'       => 'site_form_legacy',
			'notes'        => 'Lead capturado via formulÃ¡rio do site.',
		);

		Database::db()->insert( Database::table( 'leads' ), $data );
		$lead_id = Database::db()->insert_id;

		if ( $lead_id ) {
			\Hub\Core\Services\Integrations\PipelineContactSync::register_lead( $lead_id );
			Database::log_history( 'lead', $lead_id, 'create', 'Lead capturado pelo formulÃ¡rio do site.' );
			
			// Dispara evento de conversÃ£o
			do_action( 'hub/lead_created', $lead_id );
			
			// Notification e-mail using existing MailService via hook or object if MailService is accessible
			// MailService intercepta wp_mail usando wp_mail_from. Vamos enviar via wp_mail.
			
			if ( ! empty( $receive_email ) ) {
				$subject = "[Novo Lead] {$name} - Capturado pelo site";
				$body = "Um novo lead acabou de se cadastrar no site.\n\n";
				$body .= "Nome: {$name}\n";
				$body .= "E-mail: {$email}\n";
				$body .= "Telefone: {$phone}\n\n";
				$body .= "Acesse o VitPress para mais detalhes.";
				wp_mail( $receive_email, $subject, $body );
			}

			if ( $send_confirm ) {
				$subject_client = "Recebemos o seu contato!";
				$body_client = "OlÃ¡ {$name},\n\nRecebemos o seu contato com sucesso. Em breve um de nossos consultores falarÃ¡ com vocÃª.\n\nAtenciosamente,\nEquipe " . get_bloginfo('name');
				wp_mail( $email, $subject_client, $body_client );
			}

			// Prepara redirecionamento WhatsApp
			$msg = str_replace( '{nome}', urlencode( $name ), $whatsapp_msg );
			
			// Se o usuÃ¡rio esqueceu de preencher o whatsapp nas configs, tenta voltar pro site
			if ( empty( $whatsapp ) ) {
				$redirect_url = home_url();
			} else {
				$redirect_url = "https://wa.me/{$whatsapp}?text={$msg}";
			}

			wp_send_json_success( array( 'redirect_url' => $redirect_url ) );
		} else {
			wp_send_json_error( array( 'message' => 'Erro ao salvar os dados no banco.' ) );
		}
	}

	/**
	 * Processa a geraÃ§Ã£o automÃ¡tica da pÃ¡gina de conversÃ£o.
	 */
	public function handle_generate_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sem permissÃ£o.' ) );
		}

		check_ajax_referer( 'hub_generate_page_nonce', 'hub_nonce' );

		$page = get_page_by_path( 'novo-lead' );

		if ( $page ) {
			wp_send_json_error( array( 'message' => 'A pÃ¡gina de conversÃ£o jÃ¡ existe e permanece inalterada.' ) );
		}

		$post_id = wp_insert_post( array(
			'post_title'   => 'Novo Lead',
			'post_name'    => 'novo-lead',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '', // FicarÃ¡ vazio, o layout virÃ¡ do template customizado.
		) );

		if ( ! is_wp_error( $post_id ) ) {
			wp_send_json_success( array( 'url' => get_permalink( $post_id ) ) );
		} else {
			wp_send_json_error( array( 'message' => 'Erro ao tentar criar a pÃ¡gina: ' . $post_id->get_error_message() ) );
		}
	}

	/**
	 * ForÃ§a o carregamento do template customizado caso a pÃ¡gina seja /novo-lead
	 */
	public function override_conversion_template( $template ) {
		if ( is_page( 'novo-lead' ) ) {
			$custom_template = HUB_PATH . 'templates/page-clean-conversion.php';
			if ( file_exists( $custom_template ) ) {
				return $custom_template;
			}
		}
		if ( is_page( 'obrigado-contato' ) ) {
			$custom_template = HUB_PATH . 'templates/page-clean-thank-you.php';
			if ( file_exists( $custom_template ) ) {
				return $custom_template;
			}
		}
		return $template;
	}
}
