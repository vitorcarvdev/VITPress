<?php
namespace Hub\Modules\Indicators;

use Hub\Modules\AbstractModule;
use Hub\Core\Database;
use Hub\Core\ProfileManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Módulo de Indicadores Comerciais.
 */
class IndicatorsModule extends AbstractModule {

	public function get_id() {
		return 'indicators';
	}

	public function get_title() {
		return 'Indicadores';
	}

	public function get_menu_slug() {
		return 'hub-indicadores';
	}

	public function get_order() {
		return 6;
	}

	public function init() {}

	public function render() {
		$profile = ProfileManager::get_instance()->get_active_profile();
		$db      = Database::db();

		$table_leads   = Database::table( 'leads' );
		$table_clients = Database::table( 'clients' );

		$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '30_days';
		
		$date_filter = '';
		$where_clause = 'WHERE 1=1';

		if ( 'today' === $period ) {
			$date_filter = " AND DATE(created_at) = CURRENT_DATE";
		} elseif ( '7_days' === $period ) {
			$date_filter = " AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
		} elseif ( '30_days' === $period ) {
			$date_filter = " AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
		} elseif ( 'custom' === $period && !empty( $_GET['date_start'] ) && !empty( $_GET['date_end'] ) ) {
			$start = sanitize_text_field( $_GET['date_start'] );
			$end = sanitize_text_field( $_GET['date_end'] );
			// Basic validation
			if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ) {
				$date_filter = $db->prepare( " AND DATE(created_at) >= %s AND DATE(created_at) <= %s", $start, $end );
			}
		}

		// Métricas
		$total_leads     = (int) $db->get_var( "SELECT COUNT(*) FROM {$table_leads} {$where_clause} {$date_filter}" );
		
		// Converted uses updated_at for the date of conversion
		$date_filter_updated = str_replace('created_at', 'updated_at', $date_filter);
		$converted_leads = (int) $db->get_var( "SELECT COUNT(*) FROM {$table_leads} WHERE stage IN ('cliente','ganho','fechado','won') {$date_filter_updated}" );
		
		$lost_leads      = (int) $db->get_var( "SELECT COUNT(*) FROM {$table_leads} WHERE stage IN ('perdido','descartado','arquivado','lost') {$date_filter_updated}" );
		$total_clients   = (int) $db->get_var( "SELECT COUNT(*) FROM {$table_clients} {$where_clause} {$date_filter}" ); // Total of companies

		$conversion_rate = $total_leads > 0 ? round( ( $converted_leads / $total_leads ) * 100, 1 ) : 0;

		$total_revenue = (float) $db->get_var( "SELECT SUM(revenue_value) FROM {$table_leads} WHERE stage IN ('cliente','ganho','fechado','won') {$date_filter_updated}" );
		$avg_ticket    = $converted_leads > 0 ? ( $total_revenue / $converted_leads ) : 0.00;

		// Últimas conversões (Leads que atingiram o estágio cliente)
		$recent_conversions = $db->get_results(
			"SELECT id, name, email, phone, created_at AS lead_created, updated_at AS created_at FROM {$table_leads} WHERE stage IN ('cliente','ganho','fechado','won') {$date_filter_updated} ORDER BY updated_at DESC LIMIT 10"
		);

		// ==========================================
		// NEW v1.8.3 - ATENDIMENTO & FUNIL METRICS
		// ==========================================
		$cutoff_date = get_option( 'hub_atendimento_start_date', '2026-08-31 00:00:00' );

		// Leads cohort: created in period AND on/after cutoff date
		$cohort_query = "SELECT id, stage, created_at, first_contact_at, attempts_count 
		                 FROM {$table_leads} 
		                 WHERE 1=1 {$date_filter} AND created_at >= %s";
		$cohort_leads = $db->get_results( $db->prepare( $cohort_query, $cutoff_date ) );

		$cohort_total = count( $cohort_leads );
		$cohort_atendidos = 0;
		$cohort_sem_atendimento = 0;
		$total_response_time = 0;
		$atendidos_with_time = 0;

		$faixas = array(
			'rapido'         => 0,
			'moderado'       => 0,
			'demorado'       => 0,
			'muito_demorado' => 0,
		);

		foreach ( $cohort_leads as $lead ) {
			if ( $lead->attempts_count > 0 ) {
				$cohort_atendidos++;
				
				if ( ! empty( $lead->first_contact_at ) && '0000-00-00 00:00:00' !== $lead->first_contact_at ) {
					$created = strtotime( $lead->created_at );
					$contact = strtotime( $lead->first_contact_at );
					$diff_min = round( ( $contact - $created ) / 60 );
					if ( $diff_min < 0 ) {
						$diff_min = 0;
					}
					
					$total_response_time += $diff_min;
					$atendidos_with_time++;

					if ( $diff_min <= 15 ) {
						$faixas['rapido']++;
					} elseif ( $diff_min <= 60 ) {
						$faixas['moderado']++;
					} elseif ( $diff_min <= 240 ) {
						$faixas['demorado']++;
					} else {
						$faixas['muito_demorado']++;
					}
				}
			} else {
				$cohort_sem_atendimento++;
			}
		}

		$avg_response_time = $atendidos_with_time > 0 ? round( $total_response_time / $atendidos_with_time ) : null;
		$avg_response_time_classification = \Hub\Core\Services\ContactAttemptService::get_classification( $avg_response_time );
		$cobertura_pct = $cohort_total > 0 ? round( ( $cohort_atendidos / $cohort_total ) * 100 ) : 0;

		$total_attempts = array_sum( wp_list_pluck( $cohort_leads, 'attempts_count' ) );
		$avg_attempts_per_lead = $cohort_atendidos > 0 ? round( $total_attempts / $cohort_atendidos, 1 ) : 0;

		// Funnel Progress cohort calculation
		$reached_avaliacao = array();
		$reached_proposta  = array();
		$reached_cliente   = array();

		$cohort_ids = wp_list_pluck( $cohort_leads, 'id' );

		if ( ! empty( $cohort_ids ) ) {
			// Current stage checks (implies they reached it)
			foreach ( $cohort_leads as $lead ) {
				if ( in_array( $lead->stage, array( 'avaliacao', 'qualificado', 'em_atendimento', 'presentation', 'proposta_enviada', 'proposta', 'negociacao', 'negotiating', 'cliente', 'ganho', 'fechado', 'won' ), true ) ) {
					$reached_avaliacao[ $lead->id ] = true;
				}
				if ( in_array( $lead->stage, array( 'proposta_enviada', 'proposta', 'negociacao', 'negotiating', 'cliente', 'ganho', 'fechado', 'won' ), true ) ) {
					$reached_proposta[ $lead->id ] = true;
				}
				if ( in_array( $lead->stage, array( 'cliente', 'ganho', 'fechado', 'won' ), true ) ) {
					$reached_cliente[ $lead->id ] = true;
				}
			}

			// History checks for stage progress
			$ids_str = implode( ',', array_map( 'intval', $cohort_ids ) );
			$table_history = Database::table( 'history' );
			$history_rows = $db->get_results( "
				SELECT entity_id, description FROM {$table_history} 
				WHERE entity_type = 'lead' 
				  AND action = 'stage_change' 
				  AND entity_id IN ($ids_str)
			" );

			foreach ( $history_rows as $row ) {
				$desc = $row->description;
				if ( stripos( $desc, 'avaliacao' ) !== false ) {
					$reached_avaliacao[ $row->entity_id ] = true;
				}
				if ( stripos( $desc, 'proposta_enviada' ) !== false ) {
					$reached_avaliacao[ $row->entity_id ] = true;
					$reached_proposta[ $row->entity_id ] = true;
				}
				if ( stripos( $desc, 'cliente' ) !== false ) {
					$reached_avaliacao[ $row->entity_id ] = true;
					$reached_proposta[ $row->entity_id ] = true;
					$reached_cliente[ $row->entity_id ] = true;
				}
			}
		}

		$funnel_leads     = $cohort_total;
		$funnel_atendidos = $cohort_atendidos;
		$funnel_avaliados = count( $reached_avaliacao );
		$funnel_propostas = count( $reached_proposta );
		$funnel_clientes  = count( $reached_cliente );

		require_once HUB_PATH . 'admin/views/indicators.php';
	}
}
