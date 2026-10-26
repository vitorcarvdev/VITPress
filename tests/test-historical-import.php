<?php
/**
 * Importação histórica automática (HistoricalImport): ativação/reativação, início após configurar, lotes pelo WP-Cron, cursor,
 * falhas temporárias, concorrência, conclusão permanente, independência da sincronização normal e ausência de conversões.
 * Usa o WordPress de homologação; o VitAds é simulado por um transporte de teste que segue o contrato do backfill.
 * php tests/test-historical-import.php
 */
require dirname( __DIR__, 2 ) . '/hub/wp-load.php';

if ( ! defined( 'HUB_PATH' ) ) {
	require dirname( __DIR__ ) . '/hub.php';
	hub_init();
}

use Hub\Core\Services\Integrations\HistoricalImport;
use Hub\Core\Services\Integrations\LeadBackfillService;
use Hub\Core\Services\Integrations\PipelineContactSync;
use Hub\Core\Services\Integrations\PipelinePullWorker;

define( 'HUB_BACKFILL_TEST_TRANSPORT', true );

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

global $wpdb;
$table     = $wpdb->prefix . 'hub_leads';
$log_table = $wpdb->prefix . 'hub_conversions_log';
$base_id   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$table}" );
$orig      = get_option( 'hub_settings', array() );
$orig_cron = get_option( 'cron' );

$opts = array( HistoricalImport::OPTION, HistoricalImport::OPTION_DONE, LeadBackfillService::OPTION_AUTO_CURSOR, LeadBackfillService::OPTION_FAILURES, LeadBackfillService::OPTION_LOCK, LeadBackfillService::OPTION_STATE );
$wipe = function () use ( $opts ) {
	foreach ( $opts as $o ) {
		delete_option( $o );
	}
	wp_clear_scheduled_hook( HistoricalImport::HOOK );
};
$set_vitads = function ( $enabled, $token = 'hubva_teste_a' ) use ( $orig ) {
	$s           = is_array( $orig ) ? $orig : array();
	$s['vitads'] = array( 'enabled' => (bool) $enabled, 'endpoint' => 'https://vitads.invalid/api/v1/events', 'token' => $token, 'routing_key' => '' );
	update_option( 'hub_settings', $s );
};
$wipe();

// ---- leads de teste (os mesmos cenários do backfill manual)
$ids  = array();
$rows = array(
	array( '_k' => 'a', 'name' => 'Ana Presentacao', 'email' => 'ana@example.com', 'phone' => '(81) 91111-0001', 'stage' => 'novo_interessado' ),
	array( '_k' => 'b', 'name' => 'Bruno Negociando', 'email' => 'bruno@example.com', 'phone' => '81911110002', 'stage' => 'proposta_enviada', 'stage_updated_at' => '2026-03-05 12:30:00', 'stage_source' => 'vitpress_mobile' ),
	array( '_k' => 'c', 'name' => 'Carla Sem Data', 'phone' => '81911110003', 'stage' => 'cliente' ),
	array( '_k' => 'd', 'name' => 'Davi Sem Telefone', 'phone' => '', 'stage' => 'novo_interessado' ),
	array( '_k' => 'e', 'name' => 'Eva Ja Vinculada', 'phone' => '81911110005', 'stage' => 'perdido', 'stage_updated_at' => '2026-04-01 08:00:00', 'shared_contact_id' => '11111111-2222-4333-8444-555555555555' ),
	array( '_k' => 'f', 'name' => 'Fabio Telefone Repetido', 'phone' => '81 91111-0001', 'stage' => 'novo_interessado' ),
	array( '_k' => 'g', 'name' => 'Gabi Ambigua', 'phone' => '81911110007', 'stage' => 'novo_interessado', 'created_at' => '2026-02-10 10:00:00' ),
);
foreach ( $rows as $row ) {
	$k = $row['_k'];
	unset( $row['_k'] );
	$wpdb->insert( $table, array_merge( array( 'email' => '', 'source' => 'direto', 'created_at' => '2026-02-01 09:00:00' ), $row ) );
	$ids[ $k ] = (int) $wpdb->insert_id;
}
$fetch = function ( $k ) use ( $wpdb, $table, &$ids ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ids[ $k ] ) );
};
$reset_links = function () use ( $wpdb, $table, &$ids ) {
	foreach ( $ids as $k => $id ) {
		$wpdb->update( $table, array( 'shared_contact_id' => 'e' === $k ? '11111111-2222-4333-8444-555555555555' : null ), array( 'id' => $id ) );
	}
};
$snapshot = function () use ( $wpdb, $table, &$ids ) {
	return $wpdb->get_results( 'SELECT id, stage, stage_updated_at, stage_source FROM ' . $table . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY id', ARRAY_A );
};
$conversions = function () use ( $wpdb, $log_table ) {
	return $wpdb->get_var( "SHOW TABLES LIKE '{$log_table}'" ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table}" ) : -1;
};
$snap0 = $snapshot();
$conv0 = $conversions();

// ---- simulação do VitAds (contrato do backfill) e do HTTP do plugin
$vitads_state = array( 'phones' => array() );
$calls        = array();
$mode         = 'ok'; // ok | 404 | 401 | 409 | net | lost_once | throw
$lost_done    = false;
$other_http   = 0;
$sync_calls   = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$other_http, &$sync_calls ) {
	if ( false !== strpos( $url, '/pipeline/contact/resolve' ) ) {
		$sync_calls[] = 'resolve';
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'linked' => true, 'ambiguous' => false, 'shared_contact_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'stage' => 'presentation', 'stage_updated_at' => '2026-02-01T12:00:00Z' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( false !== strpos( $url, '/pipeline/contacts/pull' ) ) {
		$sync_calls[] = 'pull';
		return array( 'headers' => array(), 'body' => '[]', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	$other_http++;
	return new WP_Error( 'bloqueado_no_teste', 'sem rede' );
}, 1, 3 );
add_filter( 'hub_vitads_backfill_transport', function ( $null, $payload ) use ( &$vitads_state, &$calls, &$mode, &$lost_done ) {
	$calls[] = $payload;
	if ( 'throw' === $mode ) {
		throw new RuntimeException( 'falha inesperada simulada' );
	}
	$fixed = array( '404' => 404, '401' => 401, '409' => 409, 'net' => 0 );
	if ( isset( $fixed[ $mode ] ) ) {
		return array( 'code' => $fixed[ $mode ], 'body' => null );
	}
	$results = array();
	foreach ( $payload['contacts'] as $c ) {
		$res         = array( 'local_id' => $c['local_id'] );
		$known_phone = $vitads_state['phones'][ $c['phone'] ] ?? null;
		if ( '81911110007' === $c['phone'] ) {
			$res['status'] = 'ambiguous';
			$res['reason'] = 'phone_matches_many';
		} elseif ( ! empty( $c['shared_contact_id'] ) ) {
			$res['status']            = 'existing';
			$res['shared_contact_id'] = $c['shared_contact_id'];
		} elseif ( null !== $known_phone && $known_phone['local'] !== $c['local_id'] ) {
			$res['status'] = 'ambiguous';
			$res['reason'] = 'local_id_conflict';
		} elseif ( null !== $known_phone ) {
			$res['status']            = 'existing';
			$res['shared_contact_id'] = $known_phone['shared'];
		} else {
			$shared = wp_generate_uuid4();
			if ( empty( $payload['dry_run'] ) ) {
				$vitads_state['phones'][ $c['phone'] ] = array( 'local' => $c['local_id'], 'shared' => $shared );
			}
			$res['status']            = 'created';
			$res['shared_contact_id'] = $shared;
		}
		$results[] = $res;
	}
	if ( 'lost_once' === $mode && ! $lost_done ) {
		$lost_done = true; // o VitAds processou o lote, mas a resposta se perdeu
		return array( 'code' => 0, 'body' => null );
	}
	return array( 'code' => 200, 'body' => array( 'success' => true, 'dry_run' => ! empty( $payload['dry_run'] ), 'results' => $results ) );
}, 10, 2 );
$limits = array( 'batch' => 2, 'max_batches' => 1, 'max_seconds' => 20, 'sleep_ms' => 0, 'http_timeout' => 5, 'after_id' => $base_id );
add_filter( 'hub_historical_import_limits', function ( $defaults ) use ( &$limits ) {
	return $limits;
} );
$state_of = function () {
	$s = get_option( HistoricalImport::OPTION, array() );
	return is_array( $s ) ? $s : array();
};
$restart_scenario = function () use ( $wipe, $reset_links, &$vitads_state, &$calls, &$mode, &$lost_done, $set_vitads ) {
	$wipe();
	$reset_links();
	$vitads_state = array( 'phones' => array() );
	$calls        = array();
	$mode         = 'ok';
	$lost_done    = false;
	$set_vitads( true );
};

// ===== 1. ativação e reativação não iniciam nem reiniciam nada
$set_vitads( false );
$wipe();
HistoricalImport::on_activation();
$s1 = $state_of();
check( HistoricalImport::PENDING === ( $s1['status'] ?? '' ) && ! HistoricalImport::is_done(), 'ativação registra o estado "pending"' );
HistoricalImport::on_activation();
check( $s1 === $state_of(), 'reativar sem concluir não altera o estado' );
check( false === wp_next_scheduled( HistoricalImport::HOOK ), 'a ativação não agenda nem executa nada na própria requisição' );

// ===== 2. agendamento recorrente pelo cron do plugin
HistoricalImport::get_instance()->schedule();
$next = wp_next_scheduled( HistoricalImport::HOOK );
$event = $next ? wp_get_scheduled_event( HistoricalImport::HOOK ) : null;
check( false !== $next && $event && 'hub_five_minutes' === $event->schedule, 'o ciclo é agendado no intervalo hub_five_minutes já usado pelo plugin' );

// ===== 3. sem integração: espera, sem chamar o VitAds
$calls = array();
$r     = HistoricalImport::run_once();
check( 'waiting_config' === $r['reason'] && HistoricalImport::PENDING === $state_of()['status'] && array() === $calls && ! HistoricalImport::is_done(), 'sem integração configurada: aguarda, não chama o VitAds e não conclui' );

// ===== 4. configurar a integração antecipa o início automaticamente
wp_clear_scheduled_hook( HistoricalImport::HOOK );
$set_vitads( true );
$next = wp_next_scheduled( HistoricalImport::HOOK );
check( false !== $next && $next <= time() + 60, 'salvar a integração agenda o início em até 1 minuto (sem ação manual)' );

// ===== 5. importação em vários lotes, ciclo a ciclo
$conv_before = $conversions();
$statuses    = array();
$cursors     = array();
for ( $i = 0; $i < 8 && ! HistoricalImport::is_done(); $i++ ) {
	$r          = HistoricalImport::run_once();
	$statuses[] = $r['status'];
	$cursors[]  = (int) $state_of()['cursor'];
}
check( array( 'running', 'running', 'running', 'completed' ) === $statuses, 'quatro ciclos: três "running" e o último "completed" (lotes de 2 leads)' );
check( $cursors === array( $ids['b'], $ids['d'], $ids['f'], $ids['g'] ), 'o cursor avança lote a lote até o último lead' );
$st = $state_of();
check( HistoricalImport::COMPLETED === $st['status'] && ! empty( $st['completed_at'] ) && HistoricalImport::is_done(), 'conclusão grava a marca permanente' );
check( 2 === $st['counts']['created'] && 1 === $st['counts']['existing'] && 2 === $st['counts']['ambiguous'], 'totais acumulados: 2 criados (a, b), 1 existente (e), 2 ambíguos (f repetido e g)' );
check( 1 === ( $st['skipped']['stage_without_date'] ?? 0 ) && 1 === ( $st['skipped']['no_phone'] ?? 0 ), 'ignorados: 1 sem data da etapa e 1 sem telefone (nada inventado)' );
check( false === wp_next_scheduled( HistoricalImport::HOOK ) && false === get_option( LeadBackfillService::OPTION_AUTO_CURSOR, false ), 'ao concluir o agendamento e o cursor são removidos' );
check( ! empty( $fetch( 'a' )->shared_contact_id ) && ! empty( $fetch( 'b' )->shared_contact_id ) && empty( $fetch( 'f' )->shared_contact_id ) && empty( $fetch( 'g' )->shared_contact_id ) && empty( $fetch( 'c' )->shared_contact_id ), 'vínculos gravados só nos leads importados' );
check( 2 === count( $vitads_state['phones'] ), 'o VitAds simulado tem 2 contatos (sem duplicação)' );
check( $snap0 === $snapshot() && $conv_before === $conversions() && $conv0 === $conversions(), 'etapas locais intactas e nenhum registro de conversão criado' );
check( 0 === $other_http, 'nenhuma requisição externa além do transporte do backfill' );

// ===== 6. depois de concluída, nada reinicia
$done_at = get_option( HistoricalImport::OPTION_DONE );
$calls   = array();
HistoricalImport::on_deactivation();
HistoricalImport::on_activation();
HistoricalImport::get_instance()->schedule();
$r = HistoricalImport::run_once();
check( false === $r['ran'] && 'done' === $r['reason'] && array() === $calls, 'reativação + ciclo depois de concluída: nenhuma chamada ao VitAds' );
check( $done_at === get_option( HistoricalImport::OPTION_DONE ) && $st['completed_at'] === $state_of()['completed_at'] && false === wp_next_scheduled( HistoricalImport::HOOK ), 'a marca permanente não muda e o ciclo não é reagendado' );
$set_vitads( true, 'hubva_outro_token' );
$r = HistoricalImport::run_once();
check( array() === $calls && false === wp_next_scheduled( HistoricalImport::HOOK ), 'trocar o token depois de concluída também não repete a importação' );

// ===== 7. falha temporária de rede: espera, retoma do cursor, sem duplicar
$restart_scenario();
$limits['max_batches'] = 1;
HistoricalImport::run_once(); // lote 1 (a, b)
HistoricalImport::run_once(); // lote 2 (c, d): nada a enviar, só avança o cursor
$cursor1 = (int) $state_of()['cursor'];
$mode    = 'net'; // o lote 3 (e, f) tem o que enviar
$calls   = array();
$r       = HistoricalImport::run_once();
$st      = $state_of();
check( HistoricalImport::FAILED === $r['status'] && 'vitads_unreachable' === $st['last_error'] && 1 === (int) $st['attempts'] && (int) $st['next_retry_at'] > time(), 'rede fora: "failed_recoverable" com espera' );
check( 3 === count( $calls ) && (int) $st['cursor'] === $cursor1, 'o mesmo lote foi tentado 3 vezes e o cursor não andou' );
$calls = array();
$r     = HistoricalImport::run_once();
check( 'backoff' === $r['reason'] && array() === $calls, 'durante a espera nenhuma chamada é feita' );
$st                  = $state_of();
$st['next_retry_at'] = time() - 1;
update_option( HistoricalImport::OPTION, $st, false );
$mode = 'ok';
for ( $i = 0; $i < 6 && ! HistoricalImport::is_done(); $i++ ) {
	HistoricalImport::run_once();
}
$st = $state_of();
check( HistoricalImport::is_done() && 0 === (int) $st['attempts'] && '' === $st['last_error'], 'depois da espera retoma do cursor e conclui' );
check( 2 === count( $vitads_state['phones'] ) && 2 === $st['counts']['created'], 'retomada sem duplicar contatos nem recontar lotes' );

// ===== 8. VitAds ainda sem a rota (404) e token recusado (401)
$restart_scenario();
$mode = '404';
$r    = HistoricalImport::run_once();
$st   = $state_of();
check( HistoricalImport::FAILED === $r['status'] && 'vitads_unsupported' === $st['last_error'] && (int) $st['next_retry_at'] >= time() + 3000 && $base_id === (int) $st['cursor'], 'VitAds sem a rota (404): falha recuperável, espera de ~1 h, nada marcado como concluído' );
check( ! HistoricalImport::is_done(), 'a falha nunca marca a importação como concluída' );
$restart_scenario();
$mode = '401';
$r    = HistoricalImport::run_once();
$st   = $state_of();
check( 'vitads_unauthorized' === $st['last_error'] && (int) $st['next_retry_at'] >= time() + 20000, 'token recusado (401/403): falha recuperável com espera longa' );
$set_vitads( true, 'hubva_token_corrigido' );
check( 0 === (int) $state_of()['next_retry_at'], 'salvar a integração de novo libera a espera' );
$mode = 'ok';
for ( $i = 0; $i < 8 && ! HistoricalImport::is_done(); $i++ ) {
	HistoricalImport::run_once();
}
check( HistoricalImport::is_done(), 'com o token corrigido a importação segue sozinha até o fim' );

// ===== 9. dois processos: a trava impede o mesmo lote
$restart_scenario();
update_option( LeadBackfillService::OPTION_LOCK, time(), false );
$calls = array();
$r     = HistoricalImport::run_once();
check( 'locked' === $r['reason'] && array() === $calls && 0 === (int) ( $state_of()['cursor'] ?? 0 ), 'com outro processo no lote o ciclo não faz nada' );
delete_option( LeadBackfillService::OPTION_LOCK );
HistoricalImport::run_once();
check( (int) $state_of()['cursor'] === $ids['b'] && false === get_option( LeadBackfillService::OPTION_LOCK, false ), 'sem trava processa o lote e libera a trava' );

// ===== 10. resposta perdida depois de o VitAds processar o lote: o reenvio não duplica
$restart_scenario();
$mode = 'lost_once';
$limits['max_batches'] = 0;
$limits['batch']       = 50;
$r = HistoricalImport::run_once();
check( HistoricalImport::is_done() && 2 === count( $vitads_state['phones'] ), 'resposta perdida: o lote é reenviado e o VitAds só reconhece (2 contatos, nenhuma duplicata)' );
check( ! empty( $fetch( 'a' )->shared_contact_id ) && ! empty( $fetch( 'b' )->shared_contact_id ), 'e o vínculo local é gravado no reenvio' );
$limits['batch'] = 2;
$limits['max_batches'] = 1;

// ===== 11. trocar de cliente no meio da importação recomeça para o novo cliente
$restart_scenario();
HistoricalImport::run_once();
$cursor_mid = (int) $state_of()['cursor'];
$set_vitads( true, 'hubva_token_de_outro_cliente' );
$calls = array();
HistoricalImport::run_once();
check( $cursor_mid > 0 && isset( $calls[0] ) && (string) $ids['a'] === $calls[0]['contacts'][0]['local_id'], 'token de outro cliente: recomeça do primeiro lead (idempotente)' );

// ===== 12. a sincronização normal não depende da importação
$restart_scenario();
$mode = 'net';
HistoricalImport::run_once();
update_option( LeadBackfillService::OPTION_LOCK, time(), false ); // importação "ocupada"
$lead_a = $ids['a'];
$sync_calls = array();
$uuid = PipelineContactSync::register_lead( $lead_a );
check( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' === $uuid && 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' === $fetch( 'a' )->shared_contact_id, 'novo lead continua sendo registrado no VitAds com a importação falhando e travada' );
$pull_threw = false;
try {
	PipelinePullWorker::get_instance()->run();
} catch ( Throwable $e ) {
	$pull_threw = true;
}
check( ! $pull_threw && in_array( 'pull', $sync_calls, true ), 'o ciclo de sincronização de etapas segue funcionando' );
delete_option( LeadBackfillService::OPTION_LOCK );
$mode  = 'throw';
$st   = $state_of();
$st['next_retry_at'] = 0;
update_option( HistoricalImport::OPTION, $st, false );
$threw = false;
try {
	HistoricalImport::get_instance()->tick();
} catch ( Throwable $e ) {
	$threw = true;
}
check( ! $threw && 'internal_error' === $state_of()['last_error'] && HistoricalImport::FAILED === $state_of()['status'], 'exceção no fluxo da importação é contida (nunca derruba o site) e vira falha recuperável' );
check( false === get_option( LeadBackfillService::OPTION_LOCK, false ), 'a trava é liberada mesmo com exceção' );
$mode = 'ok';

// ===== 13. nenhum dado pessoal no estado nem nas falhas
$restart_scenario();
$limits['max_batches'] = 0;
$limits['batch']       = 50;
HistoricalImport::run_once();
$blob = wp_json_encode( array( get_option( HistoricalImport::OPTION ), get_option( LeadBackfillService::OPTION_FAILURES ), HistoricalImport::status() ) );
check( false === strpos( $blob, 'Ana' ) && false === strpos( $blob, '91111' ) && false === strpos( $blob, '@' ) && false === strpos( $blob, 'hubva_' ) && false === strpos( $blob, 'vitads.invalid' ), 'estado, falhas e status público não guardam nome, telefone, e-mail, token nem endpoint' );
check( 2 === count( LeadBackfillService::failures() ), 'ambíguos ficam registrados para revisão (só id e código)' );

// ===== 13b. erro de banco nunca conclui a importação; ciclo seguinte já agendado quando ainda falta lead
$restart_scenario();
$limits['max_batches'] = 1;
$limits['batch']       = 2;
HistoricalImport::run_once();
$fast = false;
foreach ( (array) _get_cron_array() as $ts => $hooks ) {
	if ( isset( $hooks[ HistoricalImport::HOOK ] ) ) {
		foreach ( $hooks[ HistoricalImport::HOOK ] as $ev ) {
			if ( false === $ev['schedule'] && $ts <= time() + 120 ) {
				$fast = true;
			}
		}
	}
}
check( $fast, 'depois de um lote que ainda deixa leads, o próximo ciclo é agendado em ~1 minuto (não espera os 5 do recorrente)' );
$break_sql = function ( $query ) use ( $table ) {
	return ( false !== strpos( $query, "FROM {$table} WHERE id >" ) ) ? 'SELEC quebrado' : $query;
};
add_filter( 'query', $break_sql );
$r = HistoricalImport::run_once();
remove_filter( 'query', $break_sql );
check( HistoricalImport::FAILED === $r['status'] && 'internal_error' === $state_of()['last_error'] && ! HistoricalImport::is_done(), 'erro de banco ao ler os leads vira falha recuperável e NUNCA conclui a importação' );
$st                  = $state_of();
$st['next_retry_at'] = 0;
update_option( HistoricalImport::OPTION, $st, false );
$limits['max_batches'] = 0;
$limits['batch']       = 50;
HistoricalImport::run_once();
check( HistoricalImport::is_done(), 'sem o erro a importação retoma e conclui' );
// ===== 14. limites padrão e estrutura
$captured = null;
$limits_backup = $limits;
remove_all_filters( 'hub_historical_import_limits' );
add_filter( 'hub_historical_import_limits', function ( $defaults ) use ( &$captured ) {
	$captured = $defaults;
	return array_merge( $defaults, array( 'after_id' => PHP_INT_MAX ) );
} );
$restart_scenario();
HistoricalImport::run_once();
check( 50 === $captured['batch'] && $captured['max_seconds'] <= 30 && $captured['max_batches'] <= 20 && $captured['http_timeout'] <= 15, 'limites padrão: lotes de 50, poucos lotes e até 30 s por ciclo, timeout HTTP curto' );
$code = file_get_contents( dirname( __DIR__ ) . '/core/Services/Integrations/HistoricalImport.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/hub.php' );
check( false === strpos( $code, 'WP_CLI' ) && false === strpos( $code, 'do_action' ) && false === strpos( $code, 'ConversionEventService' ), 'a importação automática não depende de WP-CLI e não dispara conversões' );
check( false !== strpos( $main, 'HistoricalImport::get_instance()->init()' ) && false !== strpos( $main, 'HistoricalImport::on_activation()' ) && false !== strpos( $main, 'HistoricalImport::on_deactivation()' ), 'hub.php registra a inicialização, a ativação e a desativação' );

// ---- limpeza
$wpdb->query( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' );
update_option( 'hub_settings', $orig );
$wipe();
if ( false !== $orig_cron ) {
	update_option( 'cron', $orig_cron );
}

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: importacao historica automatica ok\n";