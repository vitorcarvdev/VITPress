<?php
/**
 * Confere o estágio inicial dos dois fluxos de entrada de lead.
 * Remove os registros de teste ao final e não deixa os hooks de conversão rodarem.
 */
$root = dirname( __DIR__, 2 ) . '/hub/wp-load.php';
require $root;

if ( ! defined( 'HUB_PATH' ) ) {
	require dirname( __DIR__ ) . '/hub.php';
	hub_init();
}

use Hub\Core\Database;
use Hub\Core\Services\FrontendFormService;
use Hub\Core\Services\Integrations\VitAdsEventService;
use Hub\Core\Services\LeadCollectorService;
use Hub\Modules\Pipeline\MobilePipeline;
use Hub\Modules\Pipeline\PipelineModule;

$failed = 0;
function check( $condition, $message ) {
	global $failed;
	if ( $condition ) {
		echo "ok {$message}\n";
		return;
	}
	$failed++;
	echo "FALHA {$message}\n";
}

$created_ids = array();
register_shutdown_function(
	function () use ( &$created_ids ) {
		global $wpdb;
		foreach ( $created_ids as $id ) {
			$wpdb->delete( $wpdb->prefix . 'hub_leads', array( 'id' => (int) $id ), array( '%d' ) );
		}
	}
);

remove_all_actions( 'hub/lead_created' );
add_filter( 'pre_wp_mail', '__return_true' );

$settings = get_option( 'hub_settings', array() );
$vitads_backup = isset( $settings['vitads'] ) ? $settings['vitads'] : null;
$vitads = VitAdsEventService::config();
$vitads_would_send = ! empty( $vitads['enabled'] ) && '' !== $vitads['token'] && '' !== $vitads['routing_key'] && '' !== $vitads['endpoint'];
if ( $vitads_would_send ) {
	$settings['vitads']['enabled'] = false;
	update_option( 'hub_settings', $settings );
}

$log_table = Database::table( 'conversions_log' );
$log_before = (int) Database::db()->get_var( "SELECT COUNT(*) FROM {$log_table}" );

function card_for( $lead_id ) {
	foreach ( MobilePipeline::load_cards() as $card ) {
		if ( (int) $card['id'] === (int) $lead_id ) {
			return $card;
		}
	}
	return null;
}

$form_email = 'stage-form-' . wp_generate_password( 8, false ) . '@example.com';
$form = new FrontendFormService();
$form_request = new WP_REST_Request( 'POST', '/hub/v1/leads' );
$form_request->set_param( 'hub_name', 'Lead teste formulario apresentacao' );
$form_request->set_param( 'hub_email', $form_email );
$form_request->set_param( 'hub_phone', '11988880001' );
$form_response = $form->handle_rest_submission( $form_request );
$form_body = $form_response instanceof WP_REST_Response ? $form_response->get_data() : array();
$form_id = isset( $form_body['lead_id'] ) ? (int) $form_body['lead_id'] : 0;
if ( $form_id > 0 ) {
	$created_ids[] = $form_id;
}
$form_row = $form_id ? Database::db()->get_row( Database::db()->prepare( 'SELECT stage, stage_source, stage_updated_at, created_at FROM ' . Database::table( 'leads' ) . ' WHERE id = %d', $form_id ) ) : null;
$form_card = $form_id ? card_for( $form_id ) : null;
check( $form_row && 'presentation' === $form_row->stage, 'formulario Novo Lead grava presentation' );
check( $form_row && 'vitpress_form' === $form_row->stage_source && $form_row->stage_updated_at === $form_row->created_at, 'formulario grava origem e horario da etapa' );
check( $form_card && 'presentation' === $form_card['stage'] && 'Apresentação' === $form_card['stage_label'], 'Pipeline mostra o formulario em Apresentação' );

$attendant_email = 'stage-attendant-' . wp_generate_password( 8, false ) . '@example.com';
$collector = LeadCollectorService::get_instance();
$collector_request = new WP_REST_Request( 'POST', '/hub/v1/collector/submit' );
$collector_request->set_param( 'time_spent', 5 );
$collector_request->set_param( 'submission_uuid', 'stage' . wp_generate_password( 10, false ) );
$collector_request->set_param(
	'answers',
	array(
		'nome'  => array( 'value' => 'Lead teste atendente apresentacao', 'mapping' => 'name', 'label' => 'Nome' ),
		'email' => array( 'value' => $attendant_email, 'mapping' => 'email', 'label' => 'E-mail' ),
		'fone'  => array( 'value' => '11988880002', 'mapping' => 'phone', 'label' => 'Telefone' ),
	)
);
$collector_response = $collector->handle_rest_submission( $collector_request );
$collector_body = $collector_response instanceof WP_REST_Response ? $collector_response->get_data() : array();
$collector_id = isset( $collector_body['lead_id'] ) ? (int) $collector_body['lead_id'] : 0;
if ( $collector_id > 0 ) {
	$created_ids[] = $collector_id;
}
$collector_row = $collector_id ? Database::db()->get_row( Database::db()->prepare( 'SELECT stage, stage_source, stage_updated_at, created_at FROM ' . Database::table( 'leads' ) . ' WHERE id = %d', $collector_id ) ) : null;
$collector_card = $collector_id ? card_for( $collector_id ) : null;
check( $collector_row && 'presentation' === $collector_row->stage, 'Atendente grava presentation' );
check( $collector_row && 'vitpress_attendant' === $collector_row->stage_source && $collector_row->stage_updated_at === $collector_row->created_at, 'Atendente grava origem e horario da etapa' );
check( $collector_card && 'presentation' === $collector_card['stage'] && 'Apresentação' === $collector_card['stage_label'], 'Pipeline mostra o Atendente em Apresentação' );

$legacy_email = 'stage-legacy-' . wp_generate_password( 8, false ) . '@example.com';
Database::db()->insert(
	Database::table( 'leads' ),
	array(
		'name'       => 'Lead antigo novo interessado',
		'email'      => $legacy_email,
		'stage'      => 'novo_interessado',
		'created_at' => current_time( 'mysql' ),
	)
);
$legacy_id = (int) Database::db()->insert_id;
if ( $legacy_id > 0 ) {
	$created_ids[] = $legacy_id;
}
$legacy_card = $legacy_id ? card_for( $legacy_id ) : null;
$legacy_raw = $legacy_id ? Database::db()->get_var( Database::db()->prepare( 'SELECT stage FROM ' . Database::table( 'leads' ) . ' WHERE id = %d', $legacy_id ) ) : '';
check( $legacy_card && 'presentation' === $legacy_card['stage'] && 'Apresentação' === $legacy_card['stage_label'] && 'novo_interessado' === $legacy_raw, 'lead antigo continua em Apresentação sem regravar' );

$mappings = isset( $settings['conversions_mapping'] ) && is_array( $settings['conversions_mapping'] ) ? $settings['conversions_mapping'] : array();
$mapped = isset( $mappings['presentation'] ) ? $mappings['presentation'] : 'NONE';
$log_after = (int) Database::db()->get_var( "SELECT COUNT(*) FROM {$log_table}" );
$form_source = file_get_contents( dirname( __DIR__ ) . '/core/Services/FrontendFormService.php' );
$collector_source = file_get_contents( dirname( __DIR__ ) . '/core/Services/LeadCollectorService.php' );
check( 'NONE' === $mapped || '' === $mapped, 'presentation não está mapeada para conversão' );
check( $log_before === $log_after, 'nenhum log de conversão foi criado' );
check( false === strpos( $form_source, 'hub/pipeline_stage_changed' ) && false === strpos( $collector_source, 'hub/pipeline_stage_changed' ), 'criação não dispara hook de etapa' );
check( false === strpos( file_get_contents( dirname( __DIR__ ) . '/core/Services/FormService.php' ), "'novo_contato'" ), 'ajax legado do Novo Lead não grava estado antigo' );

if ( $vitads_would_send ) {
	$settings['vitads'] = $vitads_backup;
	update_option( 'hub_settings', $settings );
}

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: estagio inicial ok\n";
