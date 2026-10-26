<?php
/**
 * Pipeline antigo -> portal do VitAds: redirecionamento humano, menu, link do e-mail, sincronização agendada das etapas
 * (sem abrir o pipeline antigo) e perfil do lead enviado ao VitAds. Todo HTTP do VitAds é simulado; nada sai da máquina.
 */
$root = dirname( __DIR__, 2 ) . '/hub/wp-load.php';
require $root;

if ( ! defined( 'HUB_PATH' ) ) {
	require dirname( __DIR__ ) . '/hub.php';
	hub_init();
}

use Hub\Core\Database;
use Hub\Core\Services\Integrations\PipelineContactSync;
use Hub\Core\Services\Integrations\PipelinePullWorker;
use Hub\Core\Services\Integrations\VitAdsPortal;
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

class Hub_Test_Redirect_Caught extends Exception {
	public $location = '';
}

global $wpdb;
$leads_table = Database::table( 'leads' );
$orig_settings = get_option( 'hub_settings', array() );
$orig_cursor   = get_option( 'hub_pipeline_pull_cursor', null );
$portal_local  = 'http://127.0.0.1:8090/portal/login';
$set_vitads    = static function ( $enabled, $endpoint = 'http://127.0.0.1:8090/api/v1/events' ) use ( $orig_settings ) {
	$settings           = is_array( $orig_settings ) ? $orig_settings : array();
	$settings['vitads'] = array(
		'enabled'     => $enabled,
		'endpoint'    => $endpoint,
		'token'       => 'hubva_teste',
		'routing_key' => 'teste',
	);
	update_option( 'hub_settings', $settings );
};
$created_leads = array();
$profile_manager = \Hub\Core\ProfileManager::get_instance();
$orig_profile    = Database::get_setting( 'active_profile', '' );

try {
	// ------------------------------------------------------------------ destino do redirecionamento
	$set_vitads( true, VitAdsEventService_default() );
	check( VitAdsPortal::login_url() === 'https://vitads.vitagencia.com.br/portal/login', 'endpoint padrão: o destino é https://vitads.vitagencia.com.br/portal/login' );
	$set_vitads( true );
	check( VitAdsPortal::login_url() === $portal_local, 'endpoint de homologação: o destino usa o mesmo host do endpoint' );
	$set_vitads( false );
	check( ! VitAdsPortal::is_connected() && ! VitAdsPortal::legacy_redirect_enabled(), 'sem integração ligada ao VitAds não há redirecionamento' );
	$set_vitads( true );
	check( VitAdsPortal::legacy_redirect_enabled(), 'com a integração ligada o acesso humano ao pipeline antigo vai ao portal' );
	add_filter( 'hub_pipeline_legacy_redirect', '__return_false' );
	check( ! VitAdsPortal::legacy_redirect_enabled(), 'o filtro hub_pipeline_legacy_redirect desliga o redirecionamento (emergência)' );
	remove_filter( 'hub_pipeline_legacy_redirect', '__return_false' );

	// ------------------------------------------------------------------ /pipeline/ (HTTP real no site de teste)
	$get = static function ( $path, $method = 'GET' ) {
		$args = array(
			'timeout'     => 10,
			'redirection' => 0,
			'method'      => $method,
		);
		return wp_remote_request( home_url( $path ), $args );
	};
	$loc = static function ( $response ) {
		return is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'location' );
	};
	$code = static function ( $response ) {
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	};
	$set_vitads( true );
	$r = $get( '/pipeline/' );
	check( 302 === $code( $r ) && $portal_local === $loc( $r ), '/pipeline/ redireciona (302) para o login do portal' );
	$r = $get( '/pipeline' );
	check( 302 === $code( $r ) && $portal_local === $loc( $r ), '/pipeline (sem barra) também redireciona' );
	$r = $get( '/pipeline/', 'HEAD' );
	check( 302 === $code( $r ) && $portal_local === $loc( $r ), 'HEAD em /pipeline/ também redireciona' );
	$r = $get( '/pipeline/', 'POST' );
	check( $portal_local !== $loc( $r ), 'POST em /pipeline/ não é redirecionado (só o acesso humano por GET/HEAD)' );
	$r = $get( '/pipeline/manifest.webmanifest' );
	check( 200 === $code( $r ) && false !== strpos( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'manifest' ), 'o manifest do app continua respondendo (instalações antigas)' );
	$r = $get( '/pipeline/sw.js' );
	check( 200 === $code( $r ) && false !== strpos( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'javascript' ), 'o service worker antigo continua respondendo' );
	$r = $get( '/wp-json/hub/v1/pipeline/stage' );
	check( $portal_local !== $loc( $r ) && 302 !== $code( $r ), 'a API REST do pipeline não é redirecionada' );
	$routes = rest_get_server()->get_routes();
	check( isset( $routes['/hub/v1/pipeline/stage'] ), 'a rota REST hub/v1/pipeline/stage continua registrada' );
	check( 200 === $code( $get( '/' ) ) || 301 === $code( $get( '/' ) ) || 302 === $code( $get( '/' ) ), 'o resto do site não é afetado' );

	$set_vitads( false );
	$r = $get( '/pipeline/' );
	check( $portal_local !== $loc( $r ), 'integração desligada: /pipeline/ não vai ao portal (continua o comportamento antigo)' );
	$set_vitads( true );

	// ------------------------------------------------------------------ menu administrativo
	wp_set_current_user( 1 );
	if ( ! $profile_manager->is_profile_selected() ) {
		$profile_manager->set_active_profile( 'saude' ); // o menu só aparece depois da escolha do perfil nesta versão
	}
	$slugs = static function () {
		global $submenu;
		$all = array();
		foreach ( (array) $submenu as $parent => $items ) {
			if ( '' === (string) $parent ) {
				continue; // páginas registradas sem item de menu (ocultas)
			}
			foreach ( (array) $items as $item ) {
				$all[] = $item[2];
			}
		}
		return $all;
	};
	$GLOBALS['menu']    = array();
	$GLOBALS['submenu'] = array();
	\Hub\Core\Admin::get_instance()->register_menu_structure();
	check( ! in_array( 'hub-pipeline', $slugs(), true ) && isset( $GLOBALS['_registered_pages']['admin_page_hub-pipeline'] ), 'ligado ao VitAds: o item "Pipeline" some do menu e a página continua registrada (sem remover o serviço)' );
	$set_vitads( false );
	$GLOBALS['menu']    = array();
	$GLOBALS['submenu'] = array();
	\Hub\Core\Admin::get_instance()->register_menu_structure();
	check( in_array( 'hub-pipeline', $slugs(), true ), 'sem a ligação com o VitAds o menu antigo continua' );
	$set_vitads( true );

	// redirecionamento do kanban antigo no wp-admin (favoritos e links velhos)
	$catch = static function ( $location ) {
		$e           = new Hub_Test_Redirect_Caught( 'redirect' );
		$e->location = $location;
		throw $e;
	};
	add_filter( 'wp_redirect', $catch, 1 );
	$run_admin = static function ( $page, $method = 'GET' ) {
		$_GET['page']              = $page;
		$_SERVER['REQUEST_METHOD'] = $method;
		try {
			\Hub\Core\Admin::get_instance()->maybe_redirect_legacy_pipeline();
		} catch ( Hub_Test_Redirect_Caught $e ) {
			return $e->location;
		}
		return '';
	};
	check( $portal_local === $run_admin( 'hub-pipeline' ), 'admin.php?page=hub-pipeline leva ao portal' );
	check( '' === $run_admin( 'hub-indicadores' ), 'outras páginas do admin não são redirecionadas' );
	check( '' === $run_admin( 'hub-pipeline', 'POST' ), 'POST no admin não é redirecionado' );
	add_filter( 'wp_doing_ajax', '__return_true' );
	check( '' === $run_admin( 'hub-pipeline' ), 'chamadas AJAX não são redirecionadas' );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	$set_vitads( false );
	check( '' === $run_admin( 'hub-pipeline' ), 'sem a ligação com o VitAds o kanban antigo continua acessível' );
	$set_vitads( true );
	remove_filter( 'wp_redirect', $catch, 1 );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	unset( $_GET['page'] );

	// ------------------------------------------------------------------ link do e-mail de novo lead
	$mails = array();
	add_filter(
		'pre_wp_mail',
		static function ( $null, $atts ) use ( &$mails ) {
			$mails[] = $atts;
			return true;
		},
		10,
		2
	);
	$sample = array(
		'name'  => 'Lead E-mail',
		'phone' => '(81) 99999-0000',
		'email' => 'lead.email@example.test',
		'notes' => 'teste',
	);
	\Hub\Core\Services\LeadCollectorService::send_team_notification( 0, $sample );
	$body_on = $mails ? (string) $mails[0]['message'] : '';
	check( '' !== $body_on && false !== strpos( $body_on, 'href="' . $portal_local . '"' ) && false === strpos( $body_on, 'page=hub-pipeline' ), 'e-mail de novo lead: o botão do pipeline aponta para o portal' );
	$set_vitads( false );
	$mails = array();
	\Hub\Core\Services\LeadCollectorService::send_team_notification( 0, $sample );
	$body_off = $mails ? (string) $mails[0]['message'] : '';
	check( '' !== $body_off && false !== strpos( $body_off, 'page=hub-pipeline' ), 'sem a ligação com o VitAds o e-mail mantém o link do kanban local' );
	$set_vitads( true );

	// ------------------------------------------------------------------ sincronização agendada
	PipelinePullWorker::get_instance()->schedule();
	check( false !== wp_next_scheduled( 'hub_pipeline_pull_worker' ) && 'hub_five_minutes' === wp_get_schedule( 'hub_pipeline_pull_worker' ), 'a sincronização está agendada no WP-Cron a cada 5 minutos' );
	check( false !== has_action( 'hub_pipeline_pull_worker' ), 'o gancho do worker está registrado' );
	$before_timestamp = wp_next_scheduled( 'hub_pipeline_pull_worker' );
	PipelinePullWorker::get_instance()->schedule();
	check( wp_next_scheduled( 'hub_pipeline_pull_worker' ) === $before_timestamp, 'reagendar não duplica o evento' );

	$tag     = substr( md5( (string) microtime( true ) ), 0, 8 );
	$uuid    = static function ( $n ) {
		return sprintf( '00000000-0000-4000-8000-%012d', $n );
	};
	$base_id = (int) substr( (string) hexdec( substr( md5( (string) microtime( true ) ), 0, 6 ) ), 0, 8 );
	$old     = '2026-10-20 10:00:00';
	$lead_ids = array();
	foreach ( array( 1, 2, 3 ) as $i ) {
		$wpdb->insert(
			$leads_table,
			array(
				'name'              => "Lead Sync {$tag} {$i}",
				'phone'             => '(81) 98888-000' . $i,
				'stage'             => 'presentation',
				'stage_updated_at'  => $old,
				'stage_source'      => 'vitpress_form',
				'shared_contact_id' => $uuid( $base_id + $i ),
			)
		);
		$lead_ids[ $i ] = (int) $wpdb->insert_id;
		$created_leads[] = (int) $wpdb->insert_id;
	}
	$sync_remote = array(
		$uuid( $base_id + 1 ) => array( 'stage' => 'won', 'stage_updated_at' => '2026-10-21T12:00:00Z' ),
		$uuid( $base_id + 2 ) => array( 'stage' => 'presentation', 'stage_updated_at' => '2026-10-21T12:00:00Z' ),
		$uuid( $base_id + 3 ) => array( 'stage' => 'lost', 'stage_updated_at' => '2020-01-01T00:00:00Z' ), // mais velho que o local: não vale
	);
	$requests = array();
	$mode     = 'ok';
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) use ( &$requests, &$mode, &$sync_remote ) {
			$requests[] = array(
				'url'  => $url,
				'body' => isset( $args['body'] ) ? (string) $args['body'] : '',
			);
			if ( 'fail' === $mode ) {
				return new WP_Error( 'http_request_failed', 'simulado' );
			}
			if ( false !== strpos( $url, '/pipeline/contacts/pull' ) ) {
				$payload = json_decode( (string) $args['body'], true );
				$out     = array();
				foreach ( (array) ( $payload['shared_contact_ids'] ?? array() ) as $id ) {
					if ( isset( $sync_remote[ $id ] ) ) {
						$out[] = array_merge( array( 'shared_contact_id' => $id, 'linked' => true ), $sync_remote[ $id ] );
					}
				}
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $out ), 'headers' => array(), 'cookies' => array() );
			}
			if ( false !== strpos( $url, '/pipeline/contact/resolve' ) ) {
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'linked' => true, 'ambiguous' => false, 'shared_contact_id' => '00000000-0000-4000-8000-0000000000aa', 'stage' => 'presentation', 'stage_updated_at' => '2026-10-20T10:00:00Z' ) ), 'headers' => array(), 'cookies' => array() );
			}
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{}', 'headers' => array(), 'cookies' => array() );
		},
		10,
		3
	);
	$stage_of = static function ( $id ) use ( $wpdb, $leads_table ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT stage, stage_source, stage_updated_at FROM {$leads_table} WHERE id = %d", $id ) );
	};
	$conv_table = Database::table( 'conversions_log' );
	$conv_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table}" );

	// só o worker, sem abrir o pipeline antigo (nenhum transient do board nem do pull manual)
	delete_transient( 'hub_pipeline_pull' );
	update_option( 'hub_pipeline_pull_cursor', $lead_ids[1] - 1, false ); // começa logo antes dos 3 leads do teste
	add_filter( 'hub_pipeline_pull_page_size', static function () { return 2; } );
	$requests = array();
	$s1       = PipelineContactSync::pull_cycle( 1 );
	check( 'ok' === $s1['status'] && 1 === $s1['pages'], 'ciclo agendado: processa uma página' );
	check( 'won' === $stage_of( $lead_ids[1] )->stage && 'vitads_sync' === $stage_of( $lead_ids[1] )->stage_source, 'a etapa do VitAds chega ao WordPress sem ninguém abrir o pipeline (origem vitads_sync)' );
	check( 'presentation' === $stage_of( $lead_ids[3] )->stage, 'a página seguinte ainda não foi processada (o cursor retoma dali)' );
	$cursor_after_1 = (int) get_option( 'hub_pipeline_pull_cursor', 0 );
	check( $cursor_after_1 === $lead_ids[2], 'o cursor guarda onde parou' );
	check( false === get_transient( 'hub_pipeline_pull' ), 'o ciclo não depende do gatilho do pipeline antigo' );
	$s2 = PipelineContactSync::pull_cycle( 1 );
	check( 'ok' === $s2['status'] && 1 === $s2['pages'] && 0 === (int) get_option( 'hub_pipeline_pull_cursor', 1 ), 'a segunda execução termina a volta e zera o cursor' );
	check( 'presentation' === $stage_of( $lead_ids[3] )->stage, 'etapa remota mais antiga que a local não sobrescreve' );
	check( 'presentation' === $stage_of( $lead_ids[2] )->stage, 'etapa igual não gera mudança' );
	check( '2026-10-21 09:00:00' === $stage_of( $lead_ids[1] )->stage_updated_at || '2026-10-21 12:00:00' === $stage_of( $lead_ids[1] )->stage_updated_at, 'a data da etapa vem do VitAds (convertida para o fuso do site)' );

	// idempotência e sem laço
	$requests = array();
	$s3       = PipelineContactSync::pull_cycle( 5 );
	check( 'ok' === $s3['status'] && 0 === $s3['applied'], 'repetir o ciclo não muda nada (idempotente)' );
	$only_pull = true;
	foreach ( $requests as $req ) {
		if ( false === strpos( $req['url'], '/pipeline/contacts/pull' ) ) {
			$only_pull = false;
		}
	}
	check( $requests && $only_pull, 'o ciclo só LÊ do VitAds (/contacts/pull): nada é devolvido nem criado lá (sem laço)' );
	check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table}" ) === $conv_before, 'a sincronização não registra nem dispara conversões' );
	$hosts = array_unique( array_map( static function ( $r ) { return (string) wp_parse_url( $r['url'], PHP_URL_HOST ); }, $requests ) );
	check( array( '127.0.0.1' ) === array_values( $hosts ), 'nenhuma chamada a Google Ads, Meta ou outro serviço' );

	// travas e falhas
	$requests = array();
	set_transient( 'hub_pipeline_pull_lock', 1, 120 );
	$locked = PipelineContactSync::pull_cycle();
	check( 'locked' === $locked['status'] && array() === $requests, 'execução simultânea é bloqueada pela trava' );
	delete_transient( 'hub_pipeline_pull_lock' );
	$set_vitads( false );
	$disabled = PipelineContactSync::pull_cycle();
	check( 'disabled' === $disabled['status'] && array() === $requests, 'integração desligada: nada é feito' );
	$set_vitads( true );
	$sync_remote[ $uuid( $base_id + 2 ) ] = array( 'stage' => 'negotiating', 'stage_updated_at' => '2026-10-22T12:00:00Z' );
	$mode = 'fail';
	update_option( 'hub_pipeline_pull_cursor', $lead_ids[1], false );
	$failed_run = PipelineContactSync::pull_cycle();
	check( 'failed' === $failed_run['status'] && (int) get_option( 'hub_pipeline_pull_cursor' ) === $lead_ids[1] && 'presentation' === $stage_of( $lead_ids[2] )->stage, 'falha de rede: o cursor é mantido e nada é alterado' );
	check( false === get_transient( 'hub_pipeline_pull_lock' ), 'a trava é liberada mesmo na falha' );
	$mode = 'ok';
	update_option( 'hub_pipeline_pull_cursor', 0, false );
	remove_all_filters( 'hub_pipeline_pull_page_size' );
	PipelineContactSync::pull_cycle( 5 );
	check( 'negotiating' === $stage_of( $lead_ids[2] )->stage, 'depois que o VitAds responde, a mudança chega e os Indicadores passam a contá-la' );
	$won_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table} WHERE stage = %s AND id IN (%d,%d,%d)", 'won', $lead_ids[1], $lead_ids[2], $lead_ids[3] ) );
	check( 1 === $won_count, 'a etapa gravada é a que os Indicadores leem (tabela de leads)' );

	// ------------------------------------------------------------------ perfil enviado ao VitAds
	$wpdb->insert(
		$leads_table,
		array(
			'name'             => "  Maria {$tag}  ",
			'email'            => 'MARIA.' . $tag . '@Example.test',
			'phone'            => '(81) 97777-0001',
			'source'           => 'captador_site',
			'gclid'            => 'SEGREDO-GCLID',
			'notes'            => 'observacao interna que nao pode sair',
			'client_ip_address' => '203.0.113.9',
			'stage'            => 'presentation',
			'stage_updated_at' => '2026-10-20 10:00:00',
			'stage_source'     => 'vitpress_form',
			'created_at'       => '2026-10-20 10:00:00',
		)
	);
	$profile_lead = (int) $wpdb->insert_id;
	$created_leads[] = $profile_lead;
	$requests = array();
	PipelineContactSync::register_lead( $profile_lead );
	$resolve = null;
	foreach ( $requests as $req ) {
		if ( false !== strpos( $req['url'], '/pipeline/contact/resolve' ) ) {
			$resolve = json_decode( $req['body'], true );
		}
	}
	check( is_array( $resolve ) && isset( $resolve['contact'] ), 'o registro do lead no VitAds leva o perfil (campo contact)' );
	$contact = is_array( $resolve ) ? $resolve['contact'] : array();
	check( ( $contact['name'] ?? '' ) === "Maria {$tag}" && ( $contact['email'] ?? '' ) === 'maria.' . strtolower( $tag ) . '@example.test', 'nome limpo e e-mail em minúsculas' );
	check( ( $contact['source'] ?? '' ) === 'Captador do Site' && ( $contact['received_at'] ?? '' ) !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $contact['received_at'] ) === 1, 'origem como rótulo e recebimento em ISO 8601 UTC (fuso explícito)' );
	$raw_body = '';
	foreach ( $requests as $req ) {
		if ( false !== strpos( $req['url'], '/pipeline/contact/resolve' ) ) {
			$raw_body = $req['body'];
		}
	}
	check( array( 'name', 'email', 'source', 'received_at' ) === array_keys( $contact ) && false === strpos( $raw_body, 'SEGREDO-GCLID' ) && false === strpos( $raw_body, 'observacao interna' ) && false === strpos( $raw_body, '203.0.113.9' ), 'só os 4 campos do perfil saem: nada de observações, IP nem identificadores de clique' );
	check( isset( $resolve['phone'], $resolve['stage'], $resolve['stage_updated_at'], $resolve['stage_source'], $resolve['local_id'] ), 'os campos antigos do contrato continuam no payload (compatível com o VitAds atual)' );
	$wpdb->insert(
		$leads_table,
		array(
			'name'             => '',
			'phone'            => '(81) 97777-0002',
			'stage'            => 'presentation',
			'stage_updated_at' => '2026-10-20 10:00:00',
			'stage_source'     => 'vitpress_form',
			'source'           => '',
		)
	);
	$bare_lead = (int) $wpdb->insert_id;
	$created_leads[] = $bare_lead;
	$requests = array();
	PipelineContactSync::register_lead( $bare_lead );
	$bare = null;
	foreach ( $requests as $req ) {
		if ( false !== strpos( $req['url'], '/pipeline/contact/resolve' ) ) {
			$bare = json_decode( $req['body'], true );
		}
	}
	check( is_array( $bare ) && isset( $bare['contact']['received_at'] ) && ! isset( $bare['contact']['name'] ) && ! isset( $bare['contact']['email'] ) && ! isset( $bare['contact']['source'] ), 'lead sem nome, e-mail e origem: esses campos não são enviados (nada inventado)' );
	$set_vitads( false );
	$requests = array();
	PipelineContactSync::register_lead( $profile_lead );
	check( array() === $requests, 'integração desligada: nenhum dado do lead sai do WordPress' );
} finally {
	Database::set_setting( 'active_profile', $orig_profile );
	foreach ( $created_leads as $lead_id ) {
		$wpdb->delete( $leads_table, array( 'id' => $lead_id ), array( '%d' ) );
	}
	update_option( 'hub_settings', $orig_settings );
	if ( null === $orig_cursor ) {
		delete_option( 'hub_pipeline_pull_cursor' );
	} else {
		update_option( 'hub_pipeline_pull_cursor', $orig_cursor, false );
	}
	delete_transient( 'hub_pipeline_pull_lock' );
	delete_transient( 'hub_pipeline_pull' );
}

function VitAdsEventService_default() {
	return \Hub\Core\Services\Integrations\VitAdsEventService::DEFAULT_ENDPOINT;
}

echo $failed ? "RESULTADO: {$failed} falha(s)\n" : "RESULTADO: pipeline antigo -> portal e sincronização agendada ok\n";
exit( $failed ? 1 : 0 );
