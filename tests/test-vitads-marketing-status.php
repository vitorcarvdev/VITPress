<?php
/**
 * Chave Marketing e Pipeline e status VitAds. Restaura hub_settings ao final.
 */
$root = dirname( __DIR__, 2 ) . '/hub/wp-load.php';
require $root;

if ( ! defined( 'HUB_PATH' ) ) {
	require dirname( __DIR__ ) . '/hub.php';
	hub_init();
}

use Hub\Core\Database;
use Hub\Core\Services\Integrations\VitAdsMarketing;
use Hub\Modules\Pipeline\PipelineModule;

$failed = 0;
function check( $condition, $message ) {
	global $failed;
	echo ( $condition ? 'ok ' : 'FALHA ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failed++;
	}
}

$original = get_option( 'hub_settings', array() );
register_shutdown_function(
	function () use ( $original ) {
		update_option( 'hub_settings', $original );
	}
);

$http_calls = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$http_calls ) {
		$http_calls++;
		if ( false !== strpos( (string) $url, 'vitads.test/ok' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'connected'   => true,
						'client_id'   => 42,
						'client_name' => 'Cliente Teste',
						'marketing'   => array( 'google_ads' => true, 'meta_ads' => false ),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		if ( false !== strpos( (string) $url, 'vitads.test/down' ) ) {
			return new WP_Error( 'http_request_failed', 'vitads indisponivel' );
		}
		return $pre;
	},
	10,
	3
);

function save_marketing( $enabled, $endpoint, $token ) {
	$settings = get_option( 'hub_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	$settings['marketing_pipeline_enabled'] = $enabled;
	if ( ! isset( $settings['vitads'] ) || ! is_array( $settings['vitads'] ) ) {
		$settings['vitads'] = array();
	}
	$settings['vitads']['endpoint'] = $endpoint;
	$settings['vitads']['token']    = $token;
	update_option( 'hub_settings', $settings );
}

$integrations_before = isset( $original['integrations'] ) ? $original['integrations'] : null;

save_marketing( false, 'https://vitads.test/off/api/v1/events', '' );
check( 'off' === VitAdsMarketing::connection()['state'], 'desligado não consulta conexão' );
check( '' === VitAdsMarketing::notice_html(), 'desligado não mostra alerta' );
check( '' === VitAdsMarketing::mobile_line(), 'desligado não mostra status no pipeline' );
check( 0 === $http_calls, 'desligado não chama o VitAds' );

$home = wp_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 0 ) );
check( ! is_wp_error( $home ) && 200 === (int) wp_remote_retrieve_response_code( $home ), 'site continua respondendo' );
$pipeline = wp_remote_get( home_url( '/pipeline/' ), array( 'timeout' => 8, 'redirection' => 0 ) );
// Contrato atual: sem sessão, 200 com a página de login e redirecionamento por JavaScript para /pipeline/; nunca conteúdo de leads.
$guest_body = is_wp_error( $pipeline ) ? '' : (string) wp_remote_retrieve_body( $pipeline );
check(
	! is_wp_error( $pipeline )
	&& 200 === (int) wp_remote_retrieve_response_code( $pipeline )
	&& false !== strpos( $guest_body, 'wp-login.php' )
	&& false !== strpos( $guest_body, 'location.replace' )
	&& false !== strpos( $guest_body, 'pipeline' )
	&& false === strpos( $guest_body, 'vp-card' ),
	'pipeline continua exigindo sessão'
);
$form = file_get_contents( dirname( __DIR__ ) . '/core/Services/FrontendFormService.php' );
$collector = file_get_contents( dirname( __DIR__ ) . '/core/Services/LeadCollectorService.php' );
check( false !== strpos( $form, '/leads' ) && false !== strpos( $collector, '/collector/submit' ), 'formulário e captador permanecem' );

save_marketing( true, 'https://vitads.test/ok/api/v1/events', 'hubva_teste_conectado' );
$connected = VitAdsMarketing::connection();
check( 'connected' === $connected['state'] && 'Cliente Teste' === $connected['client_name'], 'conectado identifica o cliente' );
check( true === $connected['google_ads'] && false === $connected['meta_ads'], 'Google e Meta aparecem só como status' );
check( '' === VitAdsMarketing::notice_html(), 'conectado não mostra alerta de falha' );
check( 'Marketing conectado' === VitAdsMarketing::mobile_line(), 'PWA mostra marketing conectado' );
$after = get_option( 'hub_settings', array() );
check( $integrations_before === ( $after['integrations'] ?? null ), 'configurações antigas de tráfego não mudaram' );

save_marketing( true, 'https://vitads.test/missing/api/v1/events', '' );
check( 'disconnected' === VitAdsMarketing::connection()['state'], 'sem token fica desconectado' );
$notice = VitAdsMarketing::notice_html();
check( false !== strpos( $notice, 'ATENÇÃO: integração com VitAds desconectada.' ) && false !== strpos( $notice, 'Verificar configuração' ), 'alerta fixo aparece' );
check( 'Marketing desconectado' === VitAdsMarketing::mobile_line(), 'PWA mostra marketing desconectado' );

save_marketing( true, 'https://vitads.test/down/api/v1/events', 'hubva_teste_indisponivel' );
$down = VitAdsMarketing::connection();
check( 'disconnected' === $down['state'], 'VitAds indisponível não derruba o VitPress' );

$mobile = file_get_contents( dirname( __DIR__ ) . '/modules/Pipeline/views/mobile-pipeline.php' );
check( false !== strpos( $mobile, 'Marketing conectado' ) && false !== strpos( $mobile, 'Pipeline local' ), 'topo do pipeline mantém o status discreto' );

global $wpdb;
$wpdb->insert(
	Database::table( 'leads' ),
	array(
		'name'       => 'Lead teste marketing',
		'email'      => 'marketing-' . wp_generate_password( 6, false ) . '@example.com',
		'stage'      => 'presentation',
		'created_at' => current_time( 'mysql' ),
	)
);
$lead_id = (int) $wpdb->insert_id;
$changed = $lead_id ? PipelineModule::change_stage( $lead_id, 'negotiating', 'vitpress_mobile' ) : null;
check( is_array( $changed ) && ! empty( $changed['changed'] ) && 'negotiating' === $changed['stage'], 'mudança de etapa continua funcionando' );
if ( $lead_id ) {
	$wpdb->delete( Database::table( 'leads' ), array( 'id' => $lead_id ), array( '%d' ) );
}

update_option( 'hub_settings', $original );

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: marketing vitads ok\n";
