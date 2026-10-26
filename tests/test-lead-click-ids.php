<?php
/**
 * Persistência de gbraid e wbraid no formulário /novo-lead.
 * Remove os leads de teste e não deixa conversão Google/VitAds sair.
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
// O formulário independente está bloqueado por padrão (captação é do Atendente); este teste exercita o caminho legado
// do formulário, então reabre a captação de forma explícita, só neste processo de teste.
add_filter( 'hub/allow_standalone_lead_forms', '__return_true' );

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

$cookie_backup = $_COOKIE;
$_COOKIE['hub_gclid'] = 'GCLID_FORM_1';
$_COOKIE['hub_gbraid'] = 'GBRAID_FORM_1';
$_COOKIE['hub_wbraid'] = 'WBRAID_FORM_1';
$_COOKIE['hub_utm_source'] = 'google';
$_COOKIE['hub_utm_medium'] = 'cpc';
$_COOKIE['hub_utm_campaign'] = 'campanha_teste';
$_COOKIE['hub_utm_content'] = 'anuncio_teste';
$_COOKIE['hub_utm_term'] = 'termo_teste';
$_COOKIE['hub_consent_preferences'] = wp_json_encode( array( 'status' => 'granted' ) );

function submit_form( $suffix ) {
	$form = new FrontendFormService();
	$request = new WP_REST_Request( 'POST', '/hub/v1/leads' );
	$request->set_param( 'hub_name', 'Lead clique ' . $suffix );
	$request->set_param( 'hub_email', 'click-' . $suffix . '-' . wp_generate_password( 6, false ) . '@example.com' );
	$request->set_param( 'hub_phone', '11977770001' );
	$response = $form->handle_rest_submission( $request );
	$body = $response instanceof WP_REST_Response ? $response->get_data() : array();
	return isset( $body['lead_id'] ) ? (int) $body['lead_id'] : 0;
}

function lead_row( $id ) {
	if ( $id <= 0 ) {
		return null;
	}
	return Database::db()->get_row(
		Database::db()->prepare(
			'SELECT gclid, gbraid, wbraid, utm_source, utm_medium, utm_campaign, utm_content, utm_term FROM ' . Database::table( 'leads' ) . ' WHERE id = %d',
			$id
		)
	);
}

$with_consent = submit_form( 'consent' );
if ( $with_consent > 0 ) {
	$created_ids[] = $with_consent;
}
$granted = lead_row( $with_consent );
check( $granted && 'GBRAID_FORM_1' === $granted->gbraid, 'com consentimento grava gbraid' );
check( $granted && 'WBRAID_FORM_1' === $granted->wbraid, 'com consentimento grava wbraid' );
check( $granted && 'GCLID_FORM_1' === $granted->gclid, 'gclid continua gravado' );
check(
	$granted
	&& 'google' === $granted->utm_source
	&& 'cpc' === $granted->utm_medium
	&& 'campanha_teste' === $granted->utm_campaign
	&& 'anuncio_teste' === $granted->utm_content
	&& 'termo_teste' === $granted->utm_term,
	'UTMs continuam gravadas'
);

$_COOKIE['hub_consent_preferences'] = wp_json_encode( array( 'status' => 'denied' ) );
$without_consent = submit_form( 'denied' );
if ( $without_consent > 0 ) {
	$created_ids[] = $without_consent;
}
$denied = lead_row( $without_consent );
check( $denied && '' === (string) $denied->gbraid && '' === (string) $denied->wbraid && '' === (string) $denied->gclid, 'sem consentimento não grava identificadores de clique' );
check(
	$denied
	&& 'google' === $denied->utm_source
	&& 'cpc' === $denied->utm_medium
	&& 'campanha_teste' === $denied->utm_campaign,
	'sem consentimento as UTMs continuam gravadas'
);

$log_after = (int) Database::db()->get_var( "SELECT COUNT(*) FROM {$log_table}" );
check( $log_before === $log_after, 'nenhum evento de conversão foi registrado' );

$_COOKIE = $cookie_backup;
if ( $vitads_would_send ) {
	$settings['vitads'] = $vitads_backup;
	update_option( 'hub_settings', $settings );
}

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: identificadores do formulario ok\n";
