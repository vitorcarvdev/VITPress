<?php
namespace Hub\Core\Services\Conversions;
use Hub\Core\Database;

if ( ! defined( "ABSPATH" ) ) { exit; }

class ConversionRetryWorker {
	private static $instance = null;
	public static function get_instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}
	public function init() {
		// Hook for wp-cron
		add_action( "hub_conversion_retry_worker", array( $this, "process_queue" ) );
		if ( ! wp_next_scheduled( "hub_conversion_retry_worker" ) ) {
			wp_schedule_event( time(), "hub_five_minutes", "hub_conversion_retry_worker" );
		}
	}
	public function process_queue() {
		$db = Database::db();
		$table = Database::table( "conversions_log" );
		$now = current_time("mysql");

		// Pega eventos elegíveis para retry (limitando a 20 por vez)
		$rows = $db->get_results( $db->prepare(
			"SELECT * FROM {$table} WHERE status = %s AND (next_retry_at IS NULL OR next_retry_at <= %s) AND attempts < 5 ORDER BY id ASC LIMIT 20",
			"retry", $now
		) );

		if ( empty( $rows ) ) { return; }

		foreach ( $rows as $row ) {
			if ( "Meta Ads" === $row->platform ) {
				$this->retry_meta( $row, $now );
			}
		}
	}
	private function retry_meta( $row, $now ) {
		return;
		$provider = new MetaConversionsProvider();
		if ( ! $provider->is_configured() ) { return; }

		$payload = json_decode( $row->request_payload, true );
		if ( ! is_array( $payload ) ) {
			ConversionLogger::update_log( $row->id, array(
				"status" => "error", "error_message" => "Payload de retry inválido.", "processed_at" => $now
			) );
			return;
		}

		$settings = get_option( "hub_settings", array() );
		$pixel_id = $settings["meta_ads"]["pixel_id"] ?? "";
		$access_token = $settings["meta_ads"]["access_token"] ?? "";
		$url = "https://graph.facebook.com/v19.0/{$pixel_id}/events?access_token={$access_token}";

		$response = wp_remote_post( $url, array(
			"body" => wp_json_encode( $payload ),
			"headers" => array("Content-Type" => "application/json"),
			"timeout" => 20
		) );

		if ( is_wp_error( $response ) ) {
			$this->schedule_retry( $row, "Erro de Rede no Retry: " . $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 200 && $code < 300 ) {
			ConversionLogger::update_log( $row->id, array(
				"status" => "success", "api_response" => $body, "http_status" => $code,
				"processed_at" => $now, "events_accepted" => $data["events_received"] ?? 1
			) );
		} elseif ( $code === 429 || $code >= 500 ) {
			$this->schedule_retry( $row, "Falha temporária no Retry (HTTP {$code})." );
		} else {
			$err_msg = isset($data["error"]["message"]) ? $data["error"]["message"] : "Erro permanente.";
			ConversionLogger::update_log( $row->id, array(
				"status" => "error", "api_response" => $body, "http_status" => $code,
				"error_message" => $err_msg, "processed_at" => $now
			) );
		}
	}
	private function schedule_retry( $row, $message ) {
		$attempts = absint( $row->attempts ) + 1;
		if ( $attempts >= 5 ) {
			ConversionLogger::update_log( $row->id, array(
				"status" => "error", "attempts" => $attempts, "error_message" => "Limite de retries excedido. " . $message,
				"processed_at" => current_time("mysql"), "next_retry_at" => null
			) );
		} else {
			$delay = min( 3600, 300 * (2 ** ($attempts - 1)) ); // Exponential backoff
			ConversionLogger::update_log( $row->id, array(
				"status" => "retry", "attempts" => $attempts, "error_message" => $message,
				"next_retry_at" => gmdate("Y-m-d H:i:s", time() + $delay)
			) );
		}
	}
}

