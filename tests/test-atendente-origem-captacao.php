<?php
/**
 * O Atendente é a origem da captação e do FORM_SUBMIT; o formulário independente fica bloqueado.
 * Roda no WordPress DESCARTÁVEL de teste (como os demais testes que carregam o WordPress):
 *  - todo HTTP é interceptado (pre_http_request) e respondido de forma simulada: nada sai para VitAds, Google ou Meta;
 *  - nenhum e-mail é enviado (pre_wp_mail);
 *  - os leads criados são removidos e as opções, restauradas.
 */
$root = dirname( __DIR__, 2 ) . '/hub/wp-load.php';
require $root;

if ( ! defined( 'HUB_PATH' ) ) {
	require dirname( __DIR__ ) . '/hub.php';
	hub_init();
}

use Hub\Core\Database;
use Hub\Core\Services\FrontendFormService;
use Hub\Core\Services\LeadCollectorService;

$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ) {
	echo "ABORTADO: este teste só roda em WordPress local descartável (host atual: {$host})\n";
	exit( 3 );
}

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

const TEST_UUID = '269f9b7f-3294-43b3-8ecc-45ca6560d888';
$marker    = 'atd-origem-' . wp_generate_password( 8, false );
$saved     = array( 'hub_settings' => get_option( 'hub_settings', array() ), 'hub_lead_collector' => get_option( 'hub_lead_collector', null ) );
$leads_tbl = Database::table( 'leads' );

register_shutdown_function(
	function () use ( $marker, $saved, $leads_tbl ) {
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$leads_tbl} WHERE email LIKE %s", '%' . $wpdb->esc_like( $marker ) . '%' ) ) as $id ) {
			$wpdb->delete( $leads_tbl, array( 'id' => (int) $id ), array( '%d' ) );
		}
		update_option( 'hub_settings', $saved['hub_settings'] );
		if ( null === $saved['hub_lead_collector'] ) {
			delete_option( 'hub_lead_collector' );
		} else {
			update_option( 'hub_lead_collector', $saved['hub_lead_collector'] );
		}
		foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_hub\\_col\\_%'" ) as $name ) {
			delete_option( $name );
		}
	}
);

// Isolamento: HTTP simulado, sem e-mail, sem ouvintes antigos de hub/lead_created.
$http = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$http ) {
		$http[] = array( 'url' => $url, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) );
		if ( false !== strpos( $url, '/pipeline/contact/resolve' ) ) {
			return array( 'headers' => array(), 'cookies' => array(), 'filename' => null, 'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body' => wp_json_encode( array( 'shared_contact_id' => TEST_UUID, 'stage' => 'presentation', 'created' => true, 'existing' => false ) ) );
		}
		if ( false !== strpos( $url, '/events' ) ) {
			return array( 'headers' => array(), 'cookies' => array(), 'filename' => null, 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'body' => '{}' );
		}
		return new WP_Error( 'teste_bloqueado', 'Saída externa bloqueada no teste.' );
	},
	1,
	3
);
add_filter( 'pre_wp_mail', '__return_true' );
remove_all_actions( 'hub/lead_created' );
$lead_created = 0;
add_action(
	'hub/lead_created',
	function () use ( &$lead_created ) {
		$lead_created++;
	}
);

$settings                    = $saved['hub_settings'];
$settings['vitads']          = array( 'enabled' => true, 'endpoint' => 'https://vitads.test/api/v1/events', 'token' => 'hubva_teste', 'routing_key' => 'cliente-teste' );
$settings['forms']['receive_email'] = '';
update_option( 'hub_settings', $settings );
$collector_settings            = LeadCollectorService::get_settings();
$collector_settings['enabled'] = true;
update_option( 'hub_lead_collector', $collector_settings );

function events_posts( array $http ) {
	return array_values( array_filter( $http, static fn ( $h ) => false !== strpos( $h['url'], '/events' ) ) );
}
function resolve_posts( array $http ) {
	return array_values( array_filter( $http, static fn ( $h ) => false !== strpos( $h['url'], '/pipeline/contact/resolve' ) ) );
}
function lead_by_email( $email ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'leads' ) . ' WHERE email = %s', $email ) );
}
function attendant_request( $uuid, $name, $email, $phone ) {
	$r = new WP_REST_Request( 'POST', '/hub/v1/collector/submit' );
	$r->set_param( 'time_spent', 5 );
	$r->set_param( 'submission_uuid', $uuid );
	$r->set_param( 'ad_user_data_consent', 'denied' );
	$r->set_param(
		'answers',
		array(
			'nome'  => array( 'value' => $name, 'mapping' => 'name', 'label' => 'Nome' ),
			'email' => array( 'value' => $email, 'mapping' => 'email', 'label' => 'E-mail' ),
			'fone'  => array( 'value' => $phone, 'mapping' => 'phone', 'label' => 'Telefone' ),
		)
	);
	return $r;
}
$count_leads = static function () use ( $leads_tbl ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_tbl}" );
};

// ------------------------------------------------------------------ A. Formulário independente: bloqueado, nada acontece
$before = $count_leads();
$form   = new FrontendFormService();
$req    = new WP_REST_Request( 'POST', '/hub/v1/leads' );
$req->set_param( 'hub_name', 'Teste Formulario' );
$req->set_param( 'hub_email', 'form-' . $marker . '@example.com' );
$req->set_param( 'hub_phone', '11988880010' );
$res = $form->handle_rest_submission( $req );
check( $res instanceof WP_REST_Response && 410 === $res->get_status() && 'standalone_form_disabled' === ( $res->get_data()['code'] ?? '' ), 'formulário independente: REST responde 410 standalone_form_disabled' );
check( $before === $count_leads() && null === lead_by_email( 'form-' . $marker . '@example.com' ), 'formulário independente: nenhum lead gravado' );
check( array() === $http && 0 === $lead_created, 'formulário independente: nenhuma chamada ao VitAds (registro nem FORM_SUBMIT) e nenhum hub/lead_created' );
check( '' === do_shortcode( '[hub_form]' ), 'shortcode [hub_form] não renderiza nada (e não mostra o texto do shortcode)' );

// ------------------------------------------------------------------ B. Atendente: origem oficial
$email_a = 'a1-' . $marker . '@example.com';
$collector = LeadCollectorService::get_instance();
$res_a = $collector->handle_rest_submission( attendant_request( 'u1' . $marker, 'Teste Atendente Um', $email_a, '11988880011' ) );
$lead_a = lead_by_email( $email_a );
check( $res_a instanceof WP_REST_Response && 200 === $res_a->get_status() && $lead_a, 'Atendente: aceita o envio e grava o lead' );
check( $lead_a && 'vitpress_attendant' === $lead_a->stage_source && 'presentation' === $lead_a->stage, 'Atendente: lead em Apresentação, origem vitpress_attendant' );
check( 1 === count( resolve_posts( $http ) ), 'Atendente: um único registro no pipeline do VitAds' );
$events = events_posts( $http );
check( 1 === count( $events ), 'Atendente: exatamente UM FORM_SUBMIT enviado' );
$body = $events[0]['body'] ?? array();
check( 'FORM_SUBMIT' === ( $body['event_type'] ?? '' ) && $lead_a && 'hubva:' . $lead_a->id . ':form_submit' === ( $body['event_id'] ?? '' ), 'Atendente: FORM_SUBMIT com event_id estável hubva:<id>:form_submit' );
check( TEST_UUID === ( $body['shared_contact_id'] ?? '' ) && $lead_a && TEST_UUID === $lead_a->shared_contact_id, 'Atendente: o FORM_SUBMIT leva o shared_contact_id devolvido pelo pipeline (e o lead o guarda)' );
check( 'denied' === ( $body['ad_user_data_consent'] ?? '' ), 'Atendente: consentimento informado é mantido' );
check( 1 === $lead_created, 'Atendente: um hub/lead_created' );

// ------------------------------------------------------------------ C. Reenvio da MESMA submissão: sem duplicidade
$n_leads = $count_leads();
$n_http  = count( $http );
$collector->handle_rest_submission( attendant_request( 'u1' . $marker, 'Teste Atendente Um', $email_a, '11988880011' ) );
check( $n_leads === $count_leads() && 1 === count( events_posts( $http ) ) && 1 === $lead_created, 'mesma submissão repetida: nenhum lead novo, nenhum FORM_SUBMIT novo, nenhum hub/lead_created novo' );

// ------------------------------------------------------------------ D. Mesmo telefone em outra submissão: contato reaproveitado, sem novo evento de captação
$email_b = 'a2-' . $marker . '@example.com';
$collector->handle_rest_submission( attendant_request( 'u2' . $marker, 'Teste Atendente Dois', $email_b, '11988880011' ) );
global $wpdb;
$by_phone = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_tbl} WHERE phone = %s AND email LIKE %s", '11988880011', '%' . $wpdb->esc_like( $marker ) . '%' ) );
check( 1 === $by_phone, 'mesmo telefone no Atendente: reaproveita o lead existente (sem duplicar)' );
check( 1 === count( events_posts( $http ) ) && 1 === $lead_created, 'mesmo telefone no Atendente: sem novo FORM_SUBMIT nem novo hub/lead_created' );

// ------------------------------------------------------------------ E. Formulário bloqueado não cria duplicidade com o Atendente
$req2 = new WP_REST_Request( 'POST', '/hub/v1/leads' );
$req2->set_param( 'hub_name', 'Teste Formulario Dois' );
$req2->set_param( 'hub_email', 'form2-' . $marker . '@example.com' );
$req2->set_param( 'hub_phone', '11988880011' );
$res2 = $form->handle_rest_submission( $req2 );
$by_phone = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_tbl} WHERE phone = %s AND email LIKE %s", '11988880011', '%' . $wpdb->esc_like( $marker ) . '%' ) );
check( 410 === $res2->get_status() && 1 === $by_phone && 1 === count( events_posts( $http ) ), 'formulário com o mesmo telefone do Atendente: bloqueado, sem lead duplicado e sem segundo FORM_SUBMIT' );

echo $failed > 0 ? "RESULTADO: {$failed} falha(s)\n" : "RESULTADO: atendente é a origem da captação ok\n";
exit( $failed > 0 ? 1 : 0 );
