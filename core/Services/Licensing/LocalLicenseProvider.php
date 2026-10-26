<?php
namespace Hub\Core\Services\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocalLicenseProvider implements LicenseProviderInterface {

	/**
	 * Verifica se a instalação atual possui uma licença válida.
	 */
	public function isValid(): bool {
		$status = $this->getStatus();
		return $status['is_valid'];
	}

	/**
	 * O domínio do site (host de site_url(), configurado no WordPress; nunca o cabeçalho Host da requisição)
	 * é exatamente "localhost"? Subdomínios (ex.: x.localhost), IPs e domínios que apenas contêm "localhost" não contam.
	 * Pode ser desligado com: add_filter( 'hub/auto_license_localhost', '__return_false' );
	 *
	 * @param mixed $host Host do site.
	 * @return bool
	 */
	public static function is_local_development_host( $host ) {
		if ( ! is_string( $host ) || 'localhost' !== strtolower( trim( $host ) ) ) {
			return false;
		}
		return false !== apply_filters( 'hub/auto_license_localhost', true );
	}

	/**
	 * Retorna o status detalhado da licença local.
	 */
	public function getStatus(): array {
		$settings = get_option( 'hub_settings', [] );
		$license  = $settings['local_license'] ?? [];

		$uuid = $settings['installation_uuid'] ?? '';
		
		// Fallback caso a instalação não tenha gerado o UUID
		if ( empty( $uuid ) ) {
			$uuid = wp_generate_uuid4();
			$settings['installation_uuid'] = $uuid;
			update_option( 'hub_settings', $settings );
		}

		$host = wp_parse_url( site_url(), PHP_URL_HOST );

		// Desenvolvimento local: o domínio registrado "localhost" é licenciado automaticamente.
		if ( self::is_local_development_host( $host ) ) {
			return [
				'is_valid'      => true,
				'reason'        => 'licensed_localhost',
				'domain'        => $host,
				'uuid'          => $uuid,
				'authorized_at' => current_time( 'mysql' ),
				'authorized_by' => 'localhost (automático)',
			];
		}

		if ( empty( $license['signature'] ) || empty( $uuid ) ) {
			return [
				'is_valid' => false,
				'reason'   => 'not_licensed',
				'domain'   => $host,
				'uuid'     => $uuid,
			];
		}

		// Valida a assinatura
		$expected_sig = hash_hmac( 'sha256', $host . '|' . $uuid, wp_salt( 'auth' ) );

		if ( hash_equals( $expected_sig, $license['signature'] ) ) {
			return [
				'is_valid'      => true,
				'reason'        => 'licensed',
				'domain'        => $license['domain'] ?? $host,
				'uuid'          => $uuid,
				'authorized_at' => $license['authorized_at'] ?? '',
				'authorized_by' => $license['authorized_user'] ?? '',
			];
		}

		return [
			'is_valid' => false,
			'reason'   => 'signature_mismatch',
			'domain'   => $host,
			'uuid'     => $uuid,
		];
	}

	/**
	 * Gera um token seguro, salva o hash e envia e-mail solicitando autorização.
	 */
	public function requestAuthorization() {
		$settings = get_option( 'hub_settings', [] );
		$uuid     = $settings['installation_uuid'] ?? '';

		if ( empty( $uuid ) ) {
			$uuid = wp_generate_uuid4();
			$settings['installation_uuid'] = $uuid;
			update_option( 'hub_settings', $settings );
		}

		// Gera um token criptograficamente seguro (32 bytes em hexa)
		$token = bin2hex( random_bytes( 32 ) );
		
		// Salva o hash para validação posterior (uso único)
		$settings['license_auth_token_hash'] = wp_hash_password( $token );
		$settings['license_auth_expires']    = time() + ( 24 * HOUR_IN_SECONDS );
		update_option( 'hub_settings', $settings );

		$host       = wp_parse_url( site_url(), PHP_URL_HOST );
		$auth_url   = admin_url( 'admin-post.php?action=hub_authorize_license&token=' . $token );
		$user_login = is_user_logged_in() ? wp_get_current_user()->user_login : 'Desconhecido';

		$subject = "[VitPress] Solicitação de Autorização - {$host}";
		$message = "Olá Equipe VitAgência,\n\n";
		$message .= "Uma nova instalação do VitPress solicitou licenciamento local.\n\n";
		$message .= "Site: " . site_url() . "\n";
		$message .= "UUID: {$uuid}\n";
		$message .= "Solicitado por: {$user_login}\n";
		$message .= "Data: " . current_time( 'mysql' ) . "\n\n";
		$message .= "Para autorizar esta instalação e habilitar a Área VitAgência neste site, clique no link abaixo. (O link expira em 24h e requer login na área administrativa com privilégios internos):\n\n";
		$message .= $auth_url . "\n\n";
		$message .= "Se você não reconhece esta instalação, ignore este e-mail.";

		$headers = [ 'Cc: grupovcsis@gmail.com' ];

		// Força a captura do erro do PHPMailer/SES
		$mail_error = 'Erro desconhecido. Verifique as configurações de SMTP ou se o E-mail Remetente é autorizado.';
		$error_catcher = function( $wp_error ) use ( &$mail_error ) {
			$mail_error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $error_catcher );

		$sent = wp_mail( 'suporte@vcsis.com.br', $subject, $message, $headers );

		remove_action( 'wp_mail_failed', $error_catcher );

		if ( ! $sent ) {
			return new \WP_Error( 'mail_failed', 'Falha ao enviar e-mail de solicitação. Detalhe: ' . $mail_error );
		}

		return true;
	}

	/**
	 * Valida o token e consolida a licença no banco de dados.
	 */
	public function authorize( string $token ) {
		$settings = get_option( 'hub_settings', [] );

		$hash    = $settings['license_auth_token_hash'] ?? '';
		$expires = (int) ( $settings['license_auth_expires'] ?? 0 );

		if ( empty( $hash ) || time() > $expires ) {
			return new \WP_Error( 'token_expired', 'O token expirou ou não existe.' );
		}

		if ( ! wp_check_password( $token, $hash ) ) {
			return new \WP_Error( 'token_invalid', 'O token é inválido.' );
		}

		$uuid = $settings['installation_uuid'] ?? '';
		$host = wp_parse_url( site_url(), PHP_URL_HOST );

		// Cria a assinatura usando o wp_salt e o domínio. 
		// Se moverem o banco ou a pasta wp-content para outro domínio, a validação falhará.
		$signature = hash_hmac( 'sha256', $host . '|' . $uuid, wp_salt( 'auth' ) );

		$settings['local_license'] = [
			'domain'          => $host,
			'signature'       => $signature,
			'authorized_at'   => current_time( 'mysql' ),
			'authorized_user' => wp_get_current_user()->user_login,
		];

		// Invalida o token
		unset( $settings['license_auth_token_hash'] );
		unset( $settings['license_auth_expires'] );

		update_option( 'hub_settings', $settings );

		return true;
	}

	/**
	 * Remove a licença local, desativando os recursos premium da instalação.
	 */
	public function revokeAuthorization() {
		$settings = get_option( 'hub_settings', [] );
		
		if ( isset( $settings['local_license'] ) ) {
			unset( $settings['local_license'] );
			update_option( 'hub_settings', $settings );
		}
		
		return true;
	}
}
