<?php
namespace Hub\Core\Services;

use Hub\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço responsável por colocar vendas ganhas (Clientes) em uma fila 
 * de exportação CSV para o Google Ads (Offline Conversions).
 */
class GoogleAdsOfflineQueueService {

	public function init() {
		add_action( 'hub/pipeline_client_won', array( $this, 'enqueue_offline_conversion' ), 10, 1 );
	}

	/**
	 * Coloca a venda na fila de exportação ao ganhar no Pipeline.
	 * 
	 * @param int $lead_id O ID do Lead que acabou de se tornar Cliente.
	 */
	public function enqueue_offline_conversion( $lead_id ) {
		return;
		$lead_id = (int) $lead_id;
		if ( ! $lead_id ) {
			return;
		}

		// 1. Busca os dados do lead para obter o GCLID e revenue_value
		$lead = Database::db()->get_row( Database::db()->prepare(
			"SELECT id, gclid, revenue_value FROM " . Database::table( 'leads' ) . " WHERE id = %d",
			$lead_id
		) );

		if ( ! $lead ) {
			return;
		}

		$settings = get_option( 'hub_settings', array() );
		$offline  = isset( $settings['integrations']['google_ads_offline'] ) ? $settings['integrations']['google_ads_offline'] : array();
		
		$conversion_name = ! empty( $offline['conversion_name'] ) ? sanitize_text_field( $offline['conversion_name'] ) : 'Compra offline';
		$currency        = ! empty( $offline['currency'] ) ? sanitize_text_field( $offline['currency'] ) : 'BRL';
		
		// Converte data/hora para o fuso horário atual do WordPress
		$timezone   = wp_timezone();
		$datetime   = new \DateTime( 'now', $timezone );
		$conv_time  = $datetime->format( 'Y-m-d H:i:sP' ); // Formato: YYYY-MM-DD HH:MM:SS-03:00

		// Prepara dados de inserção
		$data = array(
			'lead_id'             => $lead->id,
			'gclid'               => $lead->gclid,
			'conversion_name'     => $conversion_name,
			'conversion_time'     => $conv_time,
			'conversion_value'    => $lead->revenue_value,
			'conversion_currency' => $currency,
			'status'              => 'pending',
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);

		// Se o GCLID estiver ausente, marca como skipped
		if ( empty( $lead->gclid ) ) {
			$data['status'] = 'skipped';
		}

		// Evita duplicação (lead_id e conversion_name)
		$existing = Database::db()->get_var( Database::db()->prepare(
			"SELECT id FROM " . Database::table( 'google_ads_offline_conversions' ) . " WHERE lead_id = %d AND conversion_name = %s",
			$lead->id,
			$conversion_name
		) );

		if ( ! $existing ) {
			Database::db()->insert( Database::table( 'google_ads_offline_conversions' ), $data );
			
			$status_label = ( $data['status'] === 'skipped' ) ? 'Ignorado (GCLID ausente)' : 'Pendente de exportação (CSV)';
			Database::log_history( 'lead', $lead->id, 'google_ads_offline_queue', "Venda adicionada à fila de conversões offline do Google Ads. Status: {$status_label}. Conversão: {$conversion_name}." );
		}
	}
}
