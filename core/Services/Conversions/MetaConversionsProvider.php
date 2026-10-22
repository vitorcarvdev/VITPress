<?php
namespace Hub\Core\Services\Conversions;

use Hub\Core\Database;

if ( ! defined( "ABSPATH" ) ) { exit; }

class MetaConversionsProvider implements ConversionProviderInterface {

	private $enabled;
	private $pixel_id;
	private $access_token;
	private $debug_mode;
	private $test_event_code;

	public function __construct() {
		$settings = get_option( "hub_settings", array() );
		$meta_settings = isset( $settings["meta_ads"] ) ? $settings["meta_ads"] : array();
		$this->enabled = ! empty( $meta_settings["enabled"] );
		$this->pixel_id = $meta_settings["pixel_id"] ?? "";
		$this->access_token = $meta_settings["access_token"] ?? "";
		$this->debug_mode = ! empty( $meta_settings["debug_mode"] );
		$this->test_event_code = $meta_settings["test_event_code"] ?? "";
	}

	public function is_configured() {
		return false;
	}

	public function send_event( $event_name, array $lead_data, array $event_data = array(), $transaction_id = "" ) {
		return;
		$now = current_time("mysql");
		$status = "processing";

		// 1. Validar LGPD
		$consent = isset($lead_data["ad_user_data_consent"]) ? $lead_data["ad_user_data_consent"] : "unknown";
		if ( "granted" !== $consent ) {
			// Aborta evento por falta de consentimento binÃƒÂ¡rio
			ConversionLogger::log( array(
				"lead_id"         => $lead_data["id"],
				"lead_name"       => $lead_data["name"],
				"platform"        => "Meta Ads",
				"conversion_type" => $event_name,
				"status"          => "error",
				"transaction_id"  => $transaction_id,
				"error_message"   => "Abortado: Lead nÃƒÂ£o forneceu consentimento (DENIED).",
				"created_at"      => $now
			) );
			return;
		}

		// 2. Montar Payload
		$meta_event_name = $this->map_event_name( $event_name );
		$action_source = "website";

		$user_data = array(
			"client_ip_address" => $lead_data["client_ip_address"] ?? "",
			"client_user_agent" => $lead_data["user_agent"] ?? "",
		);

		if ( ! empty( $lead_data["_fbp"] ) ) $user_data["fbp"] = $lead_data["_fbp"];
		if ( ! empty( $lead_data["_fbc"] ) ) $user_data["fbc"] = $lead_data["_fbc"];

		if ( ! empty( $lead_data["email"] ) ) {
			$user_data["em"] = array( hash("sha256", strtolower(trim($lead_data["email"]))) );
		}

		if ( ! empty( $lead_data["phone"] ) ) {
			$phone = preg_replace("/\D/", "", $lead_data["phone"]);
			if ( strlen($phone) >= 10 && substr($phone, 0, 2) !== "55" ) $phone = "55" . $phone;
			$user_data["ph"] = array( hash("sha256", $phone) );
		}

		$event = array(
			"event_name" => $meta_event_name,
			"event_time" => time(),
			"event_id"   => $transaction_id,
			"action_source" => $action_source,
			"user_data"  => $user_data,
		);

		if ( "Purchase" === $meta_event_name && isset( $event_data["value"] ) ) {
			$event["custom_data"] = array(
				"value" => $event_data["value"],
				"currency" => $event_data["currency"] ?? "BRL"
			);
		}

		$payload = array( "data" => array( $event ) );
		if ( $this->debug_mode && ! empty( $this->test_event_code ) ) {
			$payload["test_event_code"] = $this->test_event_code;
		}

		$url = "https://graph.facebook.com/v19.0/{$this->pixel_id}/events?access_token={$this->access_token}";

		// Cria log inicial
		$log_id = ConversionLogger::log( array(
			"lead_id"         => $lead_data["id"],
			"lead_name"       => $lead_data["name"],
			"platform"        => "Meta Ads",
			"conversion_type" => $event_name,
			"status"          => "processing",
			"transaction_id"  => $transaction_id,
			"request_payload" => wp_json_encode($payload),
			"created_at"      => $now
		) );

		// 3. Enviar via cURL / wp_remote_post
		$args = array(
			"body"    => wp_json_encode($payload),
			"headers" => array("Content-Type" => "application/json"),
			"timeout" => 20
		);

		$response = wp_remote_post( $url, $args );

		$this->handle_response( $response, $log_id, $now );
	}

	private function map_event_name( $event_name ) {
		switch ( $event_name ) {
			case "LEAD": return "Lead";
			case "QUALIFIED_LEAD": return "QualifiedLead";
			case "PURCHASE": return "Purchase";
			default: return $event_name;
		}
	}

	public function handle_response( $response, $log_id, $now ) {
		if ( is_wp_error( $response ) ) {
			ConversionLogger::update_log( $log_id, array(
				"status"        => "retry",
				"attempts"      => 1,
				"next_retry_at" => gmdate("Y-m-d H:i:s", time() + 300),
				"error_message" => "Erro de Rede/Timeout: " . $response->get_error_message()
			) );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 200 && $code < 300 ) {
			ConversionLogger::update_log( $log_id, array(
				"status"       => "success",
				"api_response" => $body,
				"http_status"  => $code,
				"processed_at" => current_time("mysql"),
				"events_accepted" => $data["events_received"] ?? 1
			) );
		} elseif ( $code === 429 || $code >= 500 ) {
			// Erro TransitÃƒÂ³rio
			ConversionLogger::update_log( $log_id, array(
				"status"        => "retry",
				"attempts"      => 1,
				"next_retry_at" => gmdate("Y-m-d H:i:s", time() + 300),
				"http_status"   => $code,
				"api_response"  => $body,
				"error_message" => "Falha temporÃƒÂ¡ria da API (HTTP {$code}).",
			) );
		} else {
			// Erro permanente.(400, auth invÃƒÂ¡lida, etc)
			$err_msg = isset($data["error"]["message"]) ? $data["error"]["message"] : "Erro permanente.";
			ConversionLogger::update_log( $log_id, array(
				"status"        => "error",
				"http_status"   => $code,
				"api_response"  => $body,
				"error_message" => $err_msg,
				"processed_at"  => current_time("mysql")
			) );
		}
	}
}

