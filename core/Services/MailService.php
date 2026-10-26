<?php
namespace Hub\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço de E-mail (Integração Amazon SES).
 */
class MailService {

	/**
	 * Construtor.
	 */
	public function __construct() {
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_wp_mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_wp_mail_from_name' ) );
	}

	/**
	 * Filtra o remetente padrão do wp_mail para garantir remetente válido.
	 */
	public function filter_wp_mail_from( $from_email ) {
		$settings = get_option( 'hub_settings', array() );
		if ( ! empty( $settings['ses']['enabled'] ) && ! empty( $settings['ses']['from_email'] ) ) {
			return sanitize_email( $settings['ses']['from_email'] );
		}
		if ( strpos( $from_email, '@localhost' ) !== false || ! is_email( $from_email ) ) {
			$admin_email = get_option( 'admin_email' );
			if ( is_email( $admin_email ) ) {
				return $admin_email;
			}
		}
		return $from_email;
	}

	/**
	 * Filtra o nome do remetente padrão.
	 */
	public function filter_wp_mail_from_name( $from_name ) {
		$settings = get_option( 'hub_settings', array() );
		if ( ! empty( $settings['ses']['enabled'] ) && ! empty( $settings['ses']['from_name'] ) ) {
			return sanitize_text_field( $settings['ses']['from_name'] );
		}
		if ( 'WordPress' === $from_name ) {
			return get_bloginfo( 'name' ) ?: 'VitPress';
		}
		return $from_name;
	}

	/**
	 * Configura o PHPMailer com as credenciais salvas no hub_settings.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Instância do PHPMailer.
	 */
	public function configure_smtp( $phpmailer ) {
		$settings = get_option( 'hub_settings', array() );

		if ( empty( $settings['ses']['enabled'] ) ) {
			return;
		}

		$ses = $settings['ses'];

		$host = ! empty( $ses['host'] ) ? $ses['host'] : 'email-smtp.sa-east-1.amazonaws.com';

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Port       = isset( $ses['port'] ) && (int) $ses['port'] > 0 ? (int) $ses['port'] : 587;
		$password = isset( $ses['password'] ) ? $ses['password'] : '';

		// Se a senha tiver exatamente 40 caracteres, trata-se de uma AWS Secret Access Key (IAM).
		// A Amazon SES aceita conexões SMTP normais desde que a Secret Key seja convertida em uma SMTP Password (V4 HMAC-SHA256).
		if ( strlen( $password ) === 40 ) {
			$region = 'sa-east-1'; // fallback
			if ( preg_match( '/email-smtp\.(.+?)\.amazonaws\.com/i', $host, $matches ) ) {
				$region = $matches[1];
			}

			// Algoritmo AWS Signature V4 para senhas SMTP SES
			$date      = '11111111';
			$service   = 'ses';
			$terminal  = 'aws4_request';
			$message   = 'SendRawEmail';
			$version   = chr( 0x04 );

			$kSecret   = 'AWS4' . $password;
			$kDate     = hash_hmac( 'sha256', $date, $kSecret, true );
			$kRegion   = hash_hmac( 'sha256', $region, $kDate, true );
			$kService  = hash_hmac( 'sha256', $service, $kRegion, true );
			$kTerminal = hash_hmac( 'sha256', $terminal, $kService, true );
			$kMessage  = hash_hmac( 'sha256', $message, $kTerminal, true );
			
			$password  = base64_encode( $version . $kMessage );
		}

		$phpmailer->Username   = isset( $ses['username'] ) ? $ses['username'] : '';
		$phpmailer->Password   = $password;
		$phpmailer->SMTPSecure = ! empty( $ses['secure'] ) ? $ses['secure'] : 'tls';
		
		$from_email = ! empty( $ses['from_email'] ) ? $ses['from_email'] : get_option( 'admin_email' );
		$from_name  = ! empty( $ses['from_name'] ) ? $ses['from_name'] : ( get_bloginfo( 'name' ) ?: 'VitPress' );

		$phpmailer->From = $from_email;
		$phpmailer->FromName = $from_name;
		$phpmailer->Sender = $from_email;
		try {
			$phpmailer->setFrom( $from_email, $from_name, false );
		} catch ( \Exception $e ) {
			// ignore
		}
	}

	/**
	 * Envia um e-mail de teste.
	 *
	 * @param string $to O destinatário do teste.
	 * @return bool|\WP_Error Retorna true em caso de sucesso, ou WP_Error em caso de falha.
	 */
	public function send_test_email( $to ) {
		$subject = 'VitPress - Teste de E-mail Amazon SES';
		$message = "Este é um e-mail de teste gerado pelo VitPress.\nSe você recebeu este e-mail, sua configuração do Amazon SES está correta.";
		
		// Usa um buffer e uma classe anônima para capturar o log detalhado do PHPMailer
		global $phpmailer;
		$debug_output = '';
		add_action( 'phpmailer_init', function( $mailer ) use ( &$debug_output ) {
			$mailer->SMTPDebug = 3;
			$mailer->Debugoutput = function( $str, $level ) use ( &$debug_output ) {
				$debug_output .= $str . "\n";
			};
		}, 999 );

		// Força o PHPMailer a capturar erros genéricos se falhar.
		add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );
		
		$this->last_error = null;

		$sent = wp_mail( $to, $subject, $message );

		remove_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );

		if ( $sent ) {
			return true;
		}

		$error_message = $this->last_error ? $this->last_error : 'Erro desconhecido ao enviar o e-mail.';
		if ( ! empty( $debug_output ) ) {
			// Adiciona o log técnico de rede para debug
			$error_message .= "\n\n=== LOG DO SERVIDOR ===\n" . $debug_output;
		}

		return new \WP_Error( 'mail_failed', $error_message );
	}

	private $last_error = null;

	public function capture_mail_error( $wp_error ) {
		$this->last_error = $wp_error->get_error_message();
	}
}
