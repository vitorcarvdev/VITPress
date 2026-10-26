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

// Este teste cobre o app antigo: com o site ligado ao VitAds o /pipeline/ passa a redirecionar ao portal (test-pipeline-vitads-redirect-sync.php).
$legacy_settings = get_option( 'hub_settings', array() );
$legacy_off      = is_array( $legacy_settings ) ? $legacy_settings : array();
$legacy_off['vitads'] = array_merge( isset( $legacy_off['vitads'] ) && is_array( $legacy_off['vitads'] ) ? $legacy_off['vitads'] : array(), array( 'enabled' => false ) );
update_option( 'hub_settings', $legacy_off );
$base = home_url( '/pipeline/' );
$headers = get_headers( $base, true );
$status = is_array( $headers ) ? (string) ( $headers[0] ?? '' ) : '';
$guest = (string) file_get_contents( $base );
check( false !== strpos( $status, '200' ) && ( false !== strpos( $guest, 'wp-login.php' ) || false !== strpos( $guest, 'vcsis' ) ) && false !== strpos( $guest, 'pipeline' ) && false === strpos( $guest, 'vp-card' ), 'pipeline sem sessão pede login e volta ao pipeline' );
update_option( 'hub_settings', $legacy_settings );

$manifest = file_get_contents( home_url( '/pipeline/manifest.webmanifest' ) );
$decoded = json_decode( (string) $manifest, true );
$start = (string) ( $decoded['start_url'] ?? '' );
$scope = (string) ( $decoded['scope'] ?? '' );
check( is_array( $decoded ) && 'VitPress' === ( $decoded['name'] ?? '' ) && 'Pipeline' === ( $decoded['short_name'] ?? '' ) && 'standalone' === ( $decoded['display'] ?? '' ) && '#1b4d3e' === ( $decoded['theme_color'] ?? '' ) && '#f4f7f6' === ( $decoded['background_color'] ?? '' ), 'manifest válido' );
check( '/' === substr( $start, 0, 1 ) && false === strpos( $start, 'localhost' ) && false !== strpos( $start, '/pipeline/' ) && $scope === $start && false !== strpos( $scope, '/pipeline/' ), 'start_url e scope ficam em /pipeline/ sem host' );
$home_parts = wp_parse_url( home_url( '/' ) );
$origin     = $home_parts['scheme'] . '://' . $home_parts['host'] . ( isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '' );
$icon_ok = 0;
foreach ( $decoded['icons'] ?? array() as $icon ) {
	if ( ! in_array( $icon['sizes'] ?? '', array( '192x192', '512x512' ), true ) ) {
		continue;
	}
	$icon_headers = get_headers( $origin . ( $icon['src'] ?? '' ), true );
	$icon_status = is_array( $icon_headers ) ? (string) ( $icon_headers[0] ?? '' ) : '';
	if ( false !== strpos( $icon_status, '200' ) ) {
		$icon_ok++;
	}
}
check( $icon_ok >= 2, 'ícones 192 e 512 respondem 200' );

$sw = (string) file_get_contents( home_url( '/pipeline/sw.js' ) );
$sw_headers = get_headers( home_url( '/pipeline/sw.js' ), true );
$sw_status = is_array( $sw_headers ) ? (string) ( $sw_headers[0] ?? '' ) : '';
check( false !== strpos( $sw_status, '200' ) && strpos( $sw, 'pipeline-mobile.css' ) !== false && strpos( $sw, "request.mode === 'navigate'" ) !== false && strpos( $sw, '/wp-json/' ) !== false && false === strpos( $sw, 'localhost' ), 'service worker registra no escopo e não guarda a página nem a API' );
$install_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/pipeline-mobile.js' );
check( false !== strpos( $install_js, 'beforeinstallprompt' ) && false !== strpos( $install_js, 'userChoice' ) && false !== strpos( $install_js, 'appinstalled' ) && false !== strpos( $install_js, 'serviceWorker.register' ), 'instalação trata o prompt e o app instalado' );
$mobile_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/pipeline-mobile.css' );
check( false !== strpos( $mobile_css, '#vp-detail-wa-wrap' ) && false !== strpos( $mobile_css, 'margin: 16px 0 0' ) && false !== strpos( $mobile_css, '#vp-detail-email' ), 'botão WhatsApp fica separado do e-mail' );

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
