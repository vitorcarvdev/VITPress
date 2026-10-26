<?php
namespace Hub\Modules\Support;

use Hub\Modules\AbstractModule;
use Hub\Core\ProfileManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Módulo Central de Atendimento (Integração de suporte via e-mail/Trello).
 */
class SupportModule extends AbstractModule {

	public function get_id() {
		return 'support';
	}

	public function get_title() {
		return 'Pedir Suporte';
	}

	public function get_order() {
		return 100;
	}

	public function init() {
		add_action( 'admin_post_hub_send_support', array( $this, 'handle_send_support' ) );
	}

	public function render() {
		$profile = ProfileManager::get_instance()->get_active_profile();
		require_once HUB_PATH . 'admin/views/support.php';
	}

	public function handle_send_support() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}

		check_admin_referer( 'hub_send_support_nonce', 'hub_nonce' );

		$category    = isset( $_POST['category'] ) ? sanitize_key( $_POST['category'] ) : 'site';
		$ticket_type = 'site' === $category 
			? ( isset( $_POST['ticket_type_site'] ) ? sanitize_text_field( $_POST['ticket_type_site'] ) : 'Outro' ) 
			: ( isset( $_POST['ticket_type_marketing'] ) ? sanitize_text_field( $_POST['ticket_type_marketing'] ) : 'Outro' );
			
		$subject     = isset( $_POST['subject'] ) ? sanitize_text_field( $_POST['subject'] ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';

		if ( empty( $subject ) || empty( $description ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hub-support&error=empty_fields' ) );
			exit;
		}

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		// Auto subject based on category
		if ( 'site' === $category ) {
			$to = 'producao.vcsis@gmail.com, suporte@vcsis.com.br';
			$final_subject = "Suporte para Site - {$site_name}";
		} else {
			$to = 'grupovcsis@gmail.com';
			$final_subject = "Suporte para anúncios do site {$site_name}";
		}

		// Append user subject if not empty (as sub-subject or just keep auto subject)
		$final_subject .= " | " . $subject;

		$profile_manager = \Hub\Core\ProfileManager::get_instance();
		$active_profile  = $profile_manager->get_active_profile();
		$profile_name    = $active_profile ? $active_profile->get_name() : 'Não definido';

		$current_user = wp_get_current_user();
		$user_name    = $current_user->display_name;
		$user_email   = $current_user->user_email;

		$hub_version  = defined('HUB_VERSION') ? HUB_VERSION : 'Desconhecida';
		$wp_version   = $GLOBALS['wp_version'];
		$php_version  = PHP_VERSION;
		$date_time    = current_time( 'd/m/Y H:i:s' );

		$mail_body = "Um novo chamado de suporte foi aberto via VitPress.\n\n";
		$mail_body .= "=== INFORMAÇÕES DO CHAMADO ===\n";
		$mail_body .= "Setor: " . ( 'site' === $category ? 'Site / WordPress' : 'Marketing / Campanhas' ) . "\n";
		$mail_body .= "Tipo do Atendimento: {$ticket_type}\n";
		$mail_body .= "Assunto: {$subject}\n";
		$mail_body .= "Data e Hora: {$date_time}\n\n";
		
		$mail_body .= "=== DESCRIÇÃO ===\n";
		$mail_body .= $description . "\n\n";
		
		$mail_body .= "=== DADOS DO AMBIENTE ===\n";
		$mail_body .= "Nome do Site: {$site_name}\n";
		$mail_body .= "URL do Site: {$site_url}\n";
		$mail_body .= "Perfil Selecionado: {$profile_name}\n";
		$mail_body .= "Solicitante: {$user_name} ({$user_email})\n";
		$mail_body .= "Versão do VitPress: {$hub_version}\n";
		$mail_body .= "Versão WP: {$wp_version}\n";
		$mail_body .= "Versão PHP: {$php_version}\n";

		$headers = array( 'Reply-To: ' . $user_name . ' <' . $user_email . '>' );

		// Disparo do E-mail
		$sent = wp_mail( $to, $final_subject, $mail_body, $headers );

		if ( $sent ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hub-support&message=sent' ) );
			exit;
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=hub-support&error=mail_failed' ) );
			exit;
		}
	}
}
