<?php
/**
 * Testes direcionados do Pipeline mobile. Não altera leads reais de forma permanente.
 */
$root = dirname( __DIR__, 2 ) . '/hub/wp-load.php';
require $root;

if ( ! defined( 'HUB_PATH' ) ) {
	require dirname( __DIR__ ) . '/hub.php';
	hub_init();
}

$active = get_option( 'active_plugins', array() );
if ( ! in_array( 'hub-vitagencia/hub.php', $active, true ) ) {
	$active[] = 'hub-vitagencia/hub.php';
	update_option( 'active_plugins', $active );
	flush_rewrite_rules( false );
}

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
	echo "FAIL {$message}\n";
}

$labels = PipelineModule::get_canonical_stages();
check( array_keys( $labels ) === array( 'presentation', 'negotiating', 'won', 'lost', 'disqualified' ), 'ordem das etapas' );
check( array_values( $labels ) === array( 'Apresentação', 'Negociando', 'Cliente Fechado', 'Cliente Perdido', 'Ruim / Desqualificado' ), 'nomes iguais ao VitZap' );
check( PipelineModule::get_stage_definitions()['presentation']['commercial_event'] === 'LEAD_QUALIFIED', 'marco LEAD_QUALIFIED' );
check( PipelineModule::get_stage_definitions()['negotiating']['commercial_event'] === 'IN_PROGRESS', 'marco IN_PROGRESS' );
check( PipelineModule::get_stage_definitions()['won']['commercial_event'] === 'OPPORTUNITY_WON', 'marco OPPORTUNITY_WON' );
check( PipelineModule::get_stage_definitions()['lost']['commercial_event'] === 'OPPORTUNITY_LOST', 'marco OPPORTUNITY_LOST' );
check( PipelineModule::get_stage_definitions()['disqualified']['commercial_event'] === 'LEAD_DISQUALIFIED', 'marco LEAD_DISQUALIFIED' );

check( PipelineModule::normalize_stage( 'novo_interessado' ) === 'presentation', 'novo interessado continua visível' );
check( PipelineModule::normalize_stage( 'avaliacao' ) === 'presentation', 'avaliação vira Apresentação' );
check( PipelineModule::normalize_stage( 'proposta_enviada' ) === 'negotiating', 'proposta vira Negociando' );
check( PipelineModule::normalize_stage( 'cliente' ) === 'won', 'cliente vira Cliente Fechado' );
check( PipelineModule::normalize_stage( 'perdido' ) === 'lost', 'perdido vira Cliente Perdido' );
check( PipelineModule::normalize_stage( 'descartado' ) === 'lost', 'descartado permanece em Cliente Perdido' );

global $wpdb;
$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}hub_leads", 0 );
check( in_array( 'stage_updated_at', $columns, true ), 'coluna stage_updated_at' );
check( in_array( 'stage_source', $columns, true ), 'coluna stage_source' );

$wpdb->insert(
	$wpdb->prefix . 'hub_leads',
	array(
		'name'       => 'Lead teste pipeline mobile',
		'email'      => 'pipeline-mobile-test@example.com',
		'phone'      => '11999990000',
		'stage'      => 'novo_interessado',
		'source'     => 'direto',
		'created_at' => current_time( 'mysql' ),
	)
);
$lead_id = (int) $wpdb->insert_id;
check( $lead_id > 0, 'lead antigo de teste criado' );

$shown = false;
foreach ( MobilePipeline::load_cards() as $card ) {
	if ( (int) $card['id'] === $lead_id && 'presentation' === $card['stage'] && 'Lead teste pipeline mobile' === $card['name'] ) {
		$shown = true;
	}
}
check( $shown, 'lead antigo aparece em Apresentação' );

$invalid = PipelineModule::change_stage( $lead_id, 'avaliacao', 'vitpress_mobile' );
check( is_wp_error( $invalid ), 'etapa inválida rejeitada' );

$before = $wpdb->get_row( $wpdb->prepare( "SELECT stage_updated_at, stage FROM {$wpdb->prefix}hub_leads WHERE id = %d", $lead_id ) );
$saved = PipelineModule::change_stage( $lead_id, 'negotiating', 'vitpress_mobile' );
check( ! is_wp_error( $saved ) && ! empty( $saved['changed'] ), 'etapa salva' );
$after = $wpdb->get_row( $wpdb->prepare( "SELECT stage, stage_updated_at, stage_source FROM {$wpdb->prefix}hub_leads WHERE id = %d", $lead_id ) );
check( $after && 'negotiating' === $after->stage && 'vitpress_mobile' === $after->stage_source && ! empty( $after->stage_updated_at ), 'stage_updated_at e origem gravados' );

$stamp = $after->stage_updated_at;
$wpdb->update(
	$wpdb->prefix . 'hub_leads',
	array( 'notes' => 'nota sem mexer na etapa' ),
	array( 'id' => $lead_id ),
	array( '%s' ),
	array( '%d' )
);
$notes = $wpdb->get_row( $wpdb->prepare( "SELECT notes, stage, stage_updated_at FROM {$wpdb->prefix}hub_leads WHERE id = %d", $lead_id ) );
check( $notes && 'nota sem mexer na etapa' === $notes->notes && 'negotiating' === $notes->stage && $notes->stage_updated_at === $stamp, 'outro campo não altera stage_updated_at' );

$wpdb->delete( $wpdb->prefix . 'hub_leads', array( 'id' => $lead_id ), array( '%d' ) );

$base = home_url( '/pipeline/' );
$headers = get_headers( $base, true );
$location = '';
if ( is_array( $headers ) ) {
	foreach ( $headers as $key => $value ) {
		if ( strtolower( (string) $key ) === 'location' ) {
			$location = is_array( $value ) ? end( $value ) : $value;
		}
	}
}
check( is_string( $location ) && strpos( $location, 'pipeline' ) !== false && ( strpos( $location, 'wp-login.php' ) !== false || strpos( $location, 'vcsis' ) !== false ), 'pipeline sem sessão pede login e volta ao pipeline' );

$manifest = file_get_contents( home_url( '/pipeline/manifest.webmanifest' ) );
$decoded = json_decode( (string) $manifest, true );
check( is_array( $decoded ) && 'VitPress' === ( $decoded['name'] ?? '' ) && 'standalone' === ( $decoded['display'] ?? '' ) && false !== strpos( (string) ( $decoded['start_url'] ?? '' ), '/pipeline' ), 'manifest válido' );

$sw = (string) file_get_contents( home_url( '/pipeline/sw.js' ) );
check( strpos( $sw, 'pipeline-mobile.css' ) !== false && strpos( $sw, "request.mode === 'navigate'" ) !== false && strpos( $sw, '/wp-json/' ) !== false, 'service worker não guarda a página nem a API' );

$admin = file_get_contents( dirname( __DIR__ ) . '/admin/views/pipeline.php' );
check( strpos( $admin, 'Somente Visualização' ) !== false && strpos( $admin, 'hub-pipeline' ) !== false, 'kanban administrativo continua somente leitura' );

$collector = file_get_contents( dirname( __DIR__ ) . '/core/Services/LeadCollectorService.php' );
$form = file_get_contents( dirname( __DIR__ ) . '/core/Services/FrontendFormService.php' );
check( strpos( $collector, '/collector/submit' ) !== false, 'captador público permanece' );
check( strpos( $form, "'/leads'" ) !== false || strpos( $form, '"/leads"' ) !== false, 'formulário público permanece' );

$admin_php = file_get_contents( dirname( __DIR__ ) . '/core/Admin.php' );
check( strpos( $admin_php, 'LocalLicenseProvider' ) !== false && strpos( $admin_php, 'isValid()' ) !== false, 'Atendente continua condicionado à licença' );

$connector = file_get_contents( dirname( __DIR__ ) . '/modules/Pipeline/PipelineModule.php' );
check( strpos( $connector, 'hub/pipeline_stage_changed' ) === false && strpos( $connector, 'hub/pipeline_client_won' ) === false, 'troca de etapa não dispara conversão' );

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: pipeline mobile ok\n";
