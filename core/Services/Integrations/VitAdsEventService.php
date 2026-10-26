<?php
namespace Hub\Core\Services\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Envia FORM_SUBMIT ao VitAds depois que o formulário público já foi salvo.
 * Falha de rede ou HTTP não interrompe o visitante.
 */
class VitAdsEventService {

	public const DEFAULT_ENDPOINT = 'https://vitads.vitagencia.com.br/api/v1/events';

	/**
	 * @return array{enabled:bool,endpoint:string,token:string,routing_key:string}
	 */
	public static function config() {
		$settings = get_option( 'hub_settings', array() );
		$vitads   = isset( $settings['vitads'] ) && is_array( $settings['vitads'] ) ? $settings['vitads'] : array();

		$endpoint = isset( $vitads['endpoint'] ) ? esc_url_raw( (string) $vitads['endpoint'] ) : '';
		if ( '' === $endpoint ) {
			$endpoint = self::DEFAULT_ENDPOINT;
		}

		return array(
			'enabled'     => ! empty( $vitads['enabled'] ),
			'endpoint'    => $endpoint,
			'token'       => isset( $vitads['token'] ) ? (string) $vitads['token'] : '',
			'routing_key' => isset( $vitads['routing_key'] ) ? (string) $vitads['routing_key'] : '',
		);
	}

	public static function normalize_routing_key( $raw ) {
		$key = remove_accents( (string) $raw );
		$key = strtolower( trim( $key ) );
		$key = preg_replace( '/[^a-z0-9_-]+/', '-', $key );
		$key = trim( (string) $key, '-' );
		return substr( $key, 0, 64 );
	}

	public static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		return substr( (string) $digits, 0, 20 );
	}

	/**
	 * UUID do contato compartilhado (formato 8-4-4-4-12) em minúsculas; '' se ausente ou inválido.
	 * O VitAds recusa (422) um shared_contact_id mal formado, então nunca se envia um valor que não passe aqui.
	 *
	 * @param mixed $value
	 */
	public static function normalize_shared_contact_id( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$id = strtolower( trim( $value ) );
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ? $id : '';
	}

	/**
	 * Só granted/denied explícitos; ausente ou fora do vocabulário vira unknown.
	 */
	public static function normalize_consent( $value ) {
		if ( ! is_string( $value ) ) {
			return 'unknown';
		}
		$status = strtolower( trim( $value ) );
		return in_array( $status, array( 'granted', 'denied' ), true ) ? $status : 'unknown';
	}

	public static function token_hint( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return '';
		}
		if ( strlen( $token ) <= 4 ) {
			return 'salvo';
		}
		return 'final ' . substr( $token, -4 );
	}

	/**
	 * @param array<string,mixed> $lead
	 */
	public static function send_form_submit( $lead_id, array $lead ) {
		try {
			self::dispatch( (int) $lead_id, $lead );
		} catch ( \Throwable $e ) {
			error_log( '[HUB VITADS] form_submit_failed status=exception' );
		}
	}

	/**
	 * @param array<string,mixed> $lead
	 */
	private static function dispatch( $lead_id, array $lead ) {
		if ( $lead_id <= 0 ) {
			error_log( '[HUB VITADS] form_submit_failed status=config' );
			return;
		}

		$config = self::config();
		if ( ! $config['enabled'] ) {
			error_log( '[HUB VITADS] disabled' );
			return;
		}

		if ( '' === $config['token'] || '' === $config['routing_key'] || '' === $config['endpoint'] ) {
			error_log( '[HUB VITADS] form_submit_failed status=config' );
			return;
		}

		$payload = array(
			'event_id'    => 'hubva:' . $lead_id . ':form_submit',
			'event_type'  => 'FORM_SUBMIT',
			'routing_key' => $config['routing_key'],
			'occurred_at' => gmdate( 'c' ),
			'ad_user_data_consent' => self::normalize_consent( $lead['ad_user_data_consent'] ?? null ),
		);

		$source_url = isset( $lead['event_source_url'] ) ? esc_url_raw( (string) $lead['event_source_url'] ) : '';
		if ( '' !== $source_url ) {
			$payload['event_source_url'] = $source_url;
		}

		$name = isset( $lead['name'] ) ? sanitize_text_field( (string) $lead['name'] ) : '';
		if ( '' !== $name ) {
			$payload['name'] = $name;
		}

		$email = isset( $lead['email'] ) ? sanitize_email( (string) $lead['email'] ) : '';
		if ( '' !== $email ) {
			$payload['email'] = $email;
		}

		$phone = self::normalize_phone( $lead['phone'] ?? '' );
		if ( '' !== $phone ) {
			$payload['phone'] = $phone;
		}

		$attribution_keys = array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term',
			'gclid',
			'wbraid',
			'gbraid',
			'fbp',
			'fbc',
		);
		foreach ( $attribution_keys as $key ) {
			$value = isset( $lead[ $key ] ) ? sanitize_text_field( (string) $lead[ $key ] ) : '';
			if ( '' !== $value ) {
				$payload[ $key ] = $value;
			}
		}

		// Vínculo com o contato do pipeline, quando o registro do lead o devolveu. Ausente/inválido: o campo não vai.
		$shared_contact_id = self::normalize_shared_contact_id( $lead['shared_contact_id'] ?? null );
		if ( '' !== $shared_contact_id ) {
			$payload['shared_contact_id'] = $shared_contact_id;
		}

		$response = wp_remote_post(
			$config['endpoint'],
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $config['token'],
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$code    = $response->get_error_code();
			$message = $response->get_error_message();
			$status  = ( false !== strpos( $code, 'timeout' ) || false !== stripos( $message, 'timed out' ) ) ? 'timeout' : 'network';
			error_log( '[HUB VITADS] form_submit_failed status=' . $status );
			return;
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( 202 === $http ) {
			error_log( '[HUB VITADS] form_submit_sent' );
			return;
		}

		if ( 200 === $http ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['duplicate'] ) ) {
				error_log( '[HUB VITADS] duplicate' );
				return;
			}
		}

		error_log( '[HUB VITADS] form_submit_failed status=' . $http );
	}
}
