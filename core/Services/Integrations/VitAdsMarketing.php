<?php
namespace Hub\Core\Services\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chave "Ativar Marketing e Pipeline" e status da conexão VitAds.
 * Não envia evento e não altera Google Ads, Meta ou o envio já existente.
 */
class VitAdsMarketing {

	const OPTION_KEY = 'marketing_pipeline_enabled';

	public static function is_enabled() {
		$settings = get_option( 'hub_settings', array() );
		return is_array( $settings ) && ! empty( $settings[ self::OPTION_KEY ] );
	}

	/**
	 * @return array{state:string,client_name?:string,google_ads?:bool,meta_ads?:bool}
	 */
	public static function connection() {
		if ( ! self::is_enabled() ) {
			return array( 'state' => 'off' );
		}

		$settings = get_option( 'hub_settings', array() );
		$vitads   = isset( $settings['vitads'] ) && is_array( $settings['vitads'] ) ? $settings['vitads'] : array();
		$token    = isset( $vitads['token'] ) ? (string) $vitads['token'] : '';
		$raw_url  = isset( $vitads['endpoint'] ) ? trim( (string) $vitads['endpoint'] ) : '';
		$events   = '' !== $raw_url ? $raw_url : VitAdsEventService::DEFAULT_ENDPOINT;

		if ( '' === $token || ! self::is_http_url( $events ) ) {
			return array( 'state' => 'disconnected' );
		}

		$status_url = self::status_url( $events );
		if ( '' === $status_url ) {
			return array( 'state' => 'disconnected' );
		}

		$cache_key = 'hub_mkt_vitads_' . substr( hash( 'sha256', $status_url . '|' . $token ), 0, 16 );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return $cached;
		}

		$result = array( 'state' => 'disconnected' );
		try {
			$response = wp_remote_get(
				$status_url,
				array(
					'timeout'     => 4,
					'redirection' => 0,
					'headers'     => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);
		} catch ( \Throwable $e ) {
			$response = new \WP_Error( 'vitads_status', 'unavailable' );
		}

		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 === $code && is_array( $body ) && ! empty( $body['connected'] ) ) {
				$marketing = isset( $body['marketing'] ) && is_array( $body['marketing'] ) ? $body['marketing'] : array();
				$result    = array(
					'state'       => 'connected',
					'client_name' => sanitize_text_field( (string) ( $body['client_name'] ?? '' ) ),
					'google_ads'  => ! empty( $marketing['google_ads'] ),
					'meta_ads'    => ! empty( $marketing['meta_ads'] ),
				);
			}
		}

		set_transient( $cache_key, $result, MINUTE_IN_SECONDS );
		return $result;
	}

	public static function is_http_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	public static function status_url( $events_url ) {
		$parts = wp_parse_url( (string) $events_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		if ( substr( $path, -7 ) === '/events' ) {
			$path = substr( $path, 0, -7 ) . '/integration/status';
		} else {
			$path = '/api/v1/integration/status';
		}
		$url = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$url .= ':' . (int) $parts['port'];
		}
		return $url . $path;
	}

	public static function notice_html() {
		if ( ! self::is_enabled() || 'disconnected' !== self::connection()['state'] ) {
			return '';
		}
		$link = admin_url( 'admin.php?page=hub-vitagencia' );
		return '<div class="notice notice-warning"><p><strong>ATENÇÃO: integração com VitAds desconectada.</strong> O Pipeline continua disponível, mas eventos de marketing e conversão podem não ser enviados. <a href="' . esc_url( $link ) . '">Verificar configuração</a></p></div>';
	}

	public static function render_admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'hub' !== $page && 0 !== strpos( $page, 'hub-' ) ) {
			return;
		}
		echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function mobile_line() {
		$status = self::connection();
		if ( 'connected' === $status['state'] ) {
			return 'Marketing conectado';
		}
		if ( 'disconnected' === $status['state'] ) {
			return 'Marketing desconectado';
		}
		return '';
	}
}
