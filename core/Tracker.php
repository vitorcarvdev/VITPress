<?php
namespace Hub\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Componente responsÃƒÂ¡vel por enfileirar o script de rastreamento no frontend e ler dados de cookies/URL.
 */
class Tracker {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Registra os hooks de frontend.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker_script' ) );
	}

	/**
	 * Enfileira o script JS de captura no frontend.
	 */
		public function enqueue_tracker_script() {
		wp_enqueue_script(
			'hub-tracker-js',
			HUB_URL . 'assets/js/hub-tracker.js',
			array(),
			HUB_VERSION,
			true
		);

		wp_localize_script( 'hub-tracker-js', 'hubTrackerConfig', array(
			'meta_enabled'  => false,
			'meta_pixel_id' => '',
			'meta_debug'    => false,
		) );
	}

	/**
	 * Recupera os dados de rastreamento ativos combinando $_GET e $_COOKIE.
	 * Separa a atribuiÃƒÂ§ÃƒÂ£o operacional (Interesse LegÃƒÂ­timo) da atribuiÃƒÂ§ÃƒÂ£o de marketing (Consentimento).
	 *
	 * @return array
	 */
	public static function get_current_tracking_data() {
		$settings = get_option( 'hub_settings', array() );
		$privacy  = isset( $settings['privacy'] ) ? $settings['privacy'] : array();
		$has_marketing_consent = false;
		
		$consent_status = 'unknown';
		if ( isset( $_COOKIE['hub_consent_preferences'] ) ) {
			$cookie_data = json_decode( wp_unslash( $_COOKIE['hub_consent_preferences'] ), true );
			if ( isset( $cookie_data['status'] ) ) {
				$consent_status = sanitize_text_field( $cookie_data['status'] );
			}
		}
		
		$has_marketing_consent = ( 'granted' === $consent_status );

		$operational_keys = array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term',
			'session_id',
			'external_referrer',
			'first_visit_datetime',
			'landing_url',
			'landing_path',
			'landing_query',
			'pageviews_count',
			'first_touch_utm_source',
			'first_touch_utm_medium',
			'first_touch_utm_campaign',
			'first_touch_utm_content',
			'first_touch_utm_term',
		);

		$marketing_keys = array(
			'gclid',
			'gbraid',
			'wbraid',
			'fbclid',
			'first_touch_gclid',
			'first_touch_fbclid',
		);

		$data = array();

		// AtribuiÃƒÂ§ÃƒÂ£o Operacional (Sempre capturada)
		foreach ( $operational_keys as $key ) {
			$val = '';
			if ( isset( $_GET[ $key ] ) && ! empty( $_GET[ $key ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			} elseif ( isset( $_COOKIE[ 'hub_' . $key ] ) && ! empty( $_COOKIE[ 'hub_' . $key ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_COOKIE[ 'hub_' . $key ] ) );
			}
			$data[ $key ] = $val;
		}

		// AtribuiÃƒÂ§ÃƒÂ£o de Marketing (Requer Consentimento se LGPD ativo)
		foreach ( $marketing_keys as $key ) {
			$val = '';
			if ( $has_marketing_consent ) {
				if ( isset( $_GET[ $key ] ) && ! empty( $_GET[ $key ] ) ) {
					$val = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				} elseif ( isset( $_COOKIE[ 'hub_' . $key ] ) && ! empty( $_COOKIE[ 'hub_' . $key ] ) ) {
					$val = sanitize_text_field( wp_unslash( $_COOKIE[ 'hub_' . $key ] ) );
				}
			}
			$data[ $key ] = $val;
		}

		// Identificadores Meta CAPI
		$data['_fbp'] = '';
		$data['_fbc'] = '';
		
		if ( $has_marketing_consent ) {
			if ( isset( $_COOKIE['_fbp'] ) && ! empty( $_COOKIE['_fbp'] ) ) {
				$data['_fbp'] = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
			}
			
			if ( isset( $_COOKIE['_fbc'] ) && ! empty( $_COOKIE['_fbc'] ) ) {
				$data['_fbc'] = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
			} elseif ( ! empty( $data['fbclid'] ) ) {
				// Forge _fbc if missing but fbclid is available in Tracker
				$timestamp = time() * 1000; // Tempo em milissegundos
				if ( ! empty( $data['first_visit_datetime'] ) ) {
					$first_time = strtotime( $data['first_visit_datetime'] );
					if ( $first_time ) {
						$timestamp = $first_time * 1000;
					}
				}
				$data['_fbc'] = 'fb.1.' . $timestamp . '.' . $data['fbclid'];
			}
		}

		// Calcula tempo de conversÃƒÂ£o (em segundos) se `first_visit_datetime` existir
		$time_to_conversion = 0;
		if ( ! empty( $data['first_visit_datetime'] ) ) {
			$first_time = strtotime( $data['first_visit_datetime'] );
			if ( $first_time ) {
				$time_to_conversion = time() - $first_time;
			}
		}
		$data['time_to_conversion'] = max( 0, $time_to_conversion );
		
		$data['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$data['client_ip_address'] = self::get_client_ip();

		return $data;
	}

	/**
	 * Tenta obter o IP real do cliente.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip = '';
		$headers = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' );
		foreach ( $headers as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', $_SERVER[ $key ] ) as $ip_candidate ) {
					$ip_candidate = trim( $ip_candidate );
					if ( filter_var( $ip_candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
						$ip = $ip_candidate;
						break 2;
					}
				}
			}
		}
		if ( empty( $ip ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}
}