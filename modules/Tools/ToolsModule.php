<?php
namespace Hub\Modules\Tools;

use Hub\Modules\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Módulo de Ferramentas (Backup, Logs, E-mails).
 */
class ToolsModule extends AbstractModule {

	public function get_id() {
		return 'tools';
	}

	public function get_title() {
		return 'Ferramentas';
	}

	public function get_menu_slug() {
		return 'hub-tools';
	}

	public function get_order() {
		return 8;
	}

	public function init() {
		add_action( 'wp_ajax_hub_download_log', array( $this, 'ajax_download_log' ) );
		add_action( 'admin_post_hub_save_ses', array( $this, 'handle_save_ses' ) );
		add_action( 'admin_post_hub_save_lead_collector', array( $this, 'handle_save_lead_collector' ) );
	}

	public function render() {
		// Determina qual aba está ativa
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'perfil';

		// Renderiza a estrutura de abas e inclui a view específica
		require_once HUB_PATH . 'admin/views/tools.php';
	}

	public function handle_save_ses() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'hub_ses_nonce', 'hub_nonce' );

		$settings = get_option( 'hub_settings', array() );
		$ses = isset( $_POST['ses'] ) ? (array) $_POST['ses'] : array();
		
		// Sanitize values
		$settings['ses'] = array(
			'enabled'            => ! empty( $ses['enabled'] ),
			'host'               => ! empty( $ses['host'] ) ? sanitize_text_field( $ses['host'] ) : 'email-smtp.sa-east-1.amazonaws.com',
			'port'               => ! empty( $ses['port'] ) ? absint( $ses['port'] ) : 587,
			'secure'             => sanitize_key( $ses['secure'] ?? 'tls' ),
			'username'           => sanitize_text_field( $ses['username'] ?? '' ),
			'password'           => sanitize_text_field( $ses['password'] ?? '' ),
			'from_email'         => sanitize_email( $ses['from_email'] ?? '' ),
			'from_name'          => sanitize_text_field( $ses['from_name'] ?? '' ),
		);

		update_option( 'hub_settings', $settings );

		$action = isset( $_POST['submit_action'] ) ? sanitize_key( $_POST['submit_action'] ) : 'save';

		if ( 'test' === $action ) {
			$mail_service = new \Hub\Core\Services\MailService();
			$test_to      = isset( $_POST['ses_test_to'] ) && is_email( $_POST['ses_test_to'] ) ? $_POST['ses_test_to'] : wp_get_current_user()->user_email;
			
			// Guarda temporariamente qual foi o último destino testado para não sumir da caixinha
			set_transient( 'hub_ses_last_test_to', $test_to, 300 );
			
			$result       = $mail_service->send_test_email( $test_to );

			if ( is_wp_error( $result ) ) {
				set_transient( 'hub_ses_last_error', $result->get_error_message(), 60 );
				wp_safe_redirect( admin_url( "admin.php?page=hub-vitagencia&tab=emails&error=ses_test_failed" ) );
				exit;
			} else {
				delete_transient( 'hub_ses_last_error' );
				wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=emails&message=ses_test_ok' ) );
				exit;
			}
		}
		
		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=emails&message=ses_saved' ) );
		exit;
	}

	public function handle_save_lead_collector() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'hub_lead_collector_nonce', 'hub_nonce' );

		$raw = isset( $_POST['collector'] ) ? (array) $_POST['collector'] : array();

		$flow = array();
		if ( ! empty( $raw['flow_json'] ) ) {
			$decoded = json_decode( wp_unslash( $raw['flow_json'] ), true );
			if ( is_array( $decoded ) ) {
				$flow = $decoded;
			}
		}

		if ( empty( $flow ) ) {
			$flow = \Hub\Core\Services\LeadCollectorService::get_default_flow();
		}

		$settings = array(
			'enabled'              => ! empty( $raw['enabled'] ),
			'attendant_name'       => sanitize_text_field( $raw['attendant_name'] ?? 'Larissa' ),
			'attendant_role'       => sanitize_text_field( $raw['attendant_role'] ?? 'Especialista em Projetos' ),
			'attendant_avatar_id'  => absint( $raw['attendant_avatar_id'] ?? 0 ),
			'primary_color'        => sanitize_hex_color( $raw['primary_color'] ?? '#1b4d3e' ) ?: '#1b4d3e',
			'position'             => in_array( $raw['position'] ?? 'right', array( 'left', 'right' ), true ) ? $raw['position'] : 'right',
			'badge_delay'          => absint( $raw['badge_delay'] ?? 10 ),
			'auto_open_delay'      => absint( $raw['auto_open_delay'] ?? 20 ),
			'auto_open_enabled'    => ! empty( $raw['auto_open_enabled'] ),
			'excluded_pages'       => isset( $raw['excluded_pages'] ) && is_array( $raw['excluded_pages'] ) ? array_map( 'absint', $raw['excluded_pages'] ) : array(),
			'notification_emails'  => sanitize_text_field( $raw['notification_emails'] ?? '' ),
			'final_message'        => sanitize_textarea_field( $raw['final_message'] ?? 'Muito obrigado, {nome}! Recebemos suas informações e nossa equipe entrará em contato com você em breve.' ),
			'show_privacy_policy'  => true,
			'flow'                 => $flow,
		);

		update_option( 'hub_lead_collector', $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=hub-atendente&message=saved' ) );
		exit;
	}
}
