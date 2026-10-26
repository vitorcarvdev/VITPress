<?php
/**
 * Importação dos leads antigos para o VitAds (LeadBackfillService). Usa o WordPress de homologação; o VitAds é simulado por
 * um transporte de teste que segue o contrato de POST /pipeline/contacts/backfill. Os leads criados aqui são removidos ao final.
 * php tests/test-lead-backfill.php
 */
$root = dirname( __DIR__, 2 ) . '/hub/wp-load.php';
require $root;

if ( ! defined( 'HUB_PATH' ) ) {
	require dirname( __DIR__ ) . '/hub.php';
	hub_init();
}

use Hub\Core\Services\Integrations\LeadBackfillService;

// O gancho de transporte do serviço só existe quando esta constante está definida (nunca em produção).
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
$settings  = get_option( 'hub_settings', array() );

$vitads                  = is_array( $settings ) ? $settings : array();
$vitads['vitads']        = array( 'enabled' => true, 'endpoint' => 'https://vitads.invalid/api/v1/events', 'token' => 'hubva_teste', 'routing_key' => '' );
update_option( 'hub_settings', $vitads );
delete_option( LeadBackfillService::OPTION_STATE );
delete_option( LeadBackfillService::OPTION_FAILURES );
delete_option( LeadBackfillService::OPTION_LOCK );

$ids = array();
$add = function ( array $data ) use ( $wpdb, $table, &$ids ) {
	$defaults = array( 'name' => 'Lead', 'email' => '', 'phone' => '', 'stage' => 'novo_interessado', 'source' => 'direto', 'created_at' => '2026-02-01 09:00:00' );
	$key = $data['_k'];
	unset( $data['_k'] );
	$wpdb->insert( $table, array_merge( $defaults, $data ) );
	$ids[ $key ] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$rows = array(
	array( '_k' => 'a', 'name' => 'Ana Presentacao', 'email' => 'ANA@example.com', 'phone' => '(81) 91111-0001', 'stage' => 'novo_interessado' ),
	array( '_k' => 'b', 'name' => 'Bruno Negociando', 'email' => 'bruno@example.com', 'phone' => '81911110002', 'stage' => 'proposta_enviada', 'stage_updated_at' => '2026-03-05 12:30:00', 'stage_source' => 'vitpress_mobile', 'source' => 'site', 'utm_source' => 'google', 'gclid' => 'G123' ),
	array( '_k' => 'c', 'name' => 'Carla Cliente Sem Data', 'phone' => '81911110003', 'stage' => 'cliente' ),
	array( '_k' => 'd', 'name' => 'Davi Sem Telefone', 'phone' => '', 'stage' => 'novo_interessado' ),
	array( '_k' => 'e', 'name' => 'Eva Ja Vinculada', 'phone' => '81911110005', 'stage' => 'perdido', 'stage_updated_at' => '2026-04-01 08:00:00', 'shared_contact_id' => '11111111-2222-4333-8444-555555555555' ),
	array( '_k' => 'f', 'name' => 'Fabio Telefone Repetido', 'phone' => '81 91111-0001', 'stage' => 'novo_interessado' ),
	array( '_k' => 'g', 'name' => 'Gabi Zero Date', 'phone' => '81911110007', 'stage' => 'novo_interessado', 'stage_updated_at' => '0000-00-00 00:00:00', 'created_at' => '2026-02-10 10:00:00' ),
);
foreach ( $rows as $row ) {
	$add( $row );
}
$fetch = function ( $k ) use ( $wpdb, $table, &$ids ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ids[ $k ] ) );
};
$snapshot = function () use ( $wpdb, $table, $ids ) {
	return $wpdb->get_results( 'SELECT id, stage, stage_updated_at, stage_source, notes FROM ' . $table . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY id', ARRAY_A );
};
$conversions = function () use ( $wpdb, $log_table ) {
	return $wpdb->get_var( "SHOW TABLES LIKE '{$log_table}'" ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table}" ) : -1;
};

// Simulação do VitAds (contrato do backfill): telefone único por cliente, o primeiro vence, local_id diferente é ambíguo.
$vitads_state = array( 'phones' => array(), 'locals' => array() );
$calls        = array();
$mode         = 'ok'; // ok | 404 | 503 | net
$http_hits    = 0;
add_filter( 'pre_http_request', function ( $pre ) use ( &$http_hits ) {
	$http_hits++;
	return new WP_Error( 'bloqueado_no_teste', 'sem rede' );
}, 1 );
add_filter( 'hub_vitads_backfill_transport', function ( $null, $payload ) use ( &$vitads_state, &$calls, &$mode ) {
	$calls[] = $payload;
	if ( '404' === $mode ) {
		return array( 'code' => 404, 'body' => null );
	}
	if ( '503' === $mode ) {
		return array( 'code' => 503, 'body' => null );
	}
	if ( 'net' === $mode ) {
		return array( 'code' => 0, 'body' => null );
	}
	$results = array();
	foreach ( $payload['contacts'] as $c ) {
		$res = array( 'local_id' => $c['local_id'] );
		$known_phone = $vitads_state['phones'][ $c['phone'] ] ?? null;
		if ( '81911110007' === $c['phone'] ) {
			$res['status'] = 'ambiguous'; // o VitAds acha mais de um contato com este telefone
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
			$res['shared_contact_id'] = empty( $payload['dry_run'] ) ? $shared : null;
		}
		$results[] = $res;
	}
	return array( 'code' => 200, 'body' => array( 'success' => true, 'dry_run' => ! empty( $payload['dry_run'] ), 'results' => $results ) );
}, 10, 2 );

$run = function ( array $args = array() ) use ( $base_id ) {
	return LeadBackfillService::run( array_merge( array( 'after_id' => $base_id, 'sleep_ms' => 0 ), $args ) );
};

$reset_links = function () use ( $wpdb, $table, &$ids ) {
	foreach ( $ids as $k => $id ) {
		$wpdb->update( $table, array( 'shared_contact_id' => 'e' === $k ? '11111111-2222-4333-8444-555555555555' : null ), array( 'id' => $id ) );
	}
};
// ---- montagem do item (sem inventar nada)
$a_item = LeadBackfillService::build_item( $fetch( 'a' ) );
check( is_array( $a_item ) && '81911110001' === $a_item['phone'] && 'presentation' === $a_item['stage'], 'telefone só com dígitos e etapa normalizada (novo_interessado vira presentation)' );
check( false !== strpos( $a_item['stage_updated_at'], '2026-02-01T' ), 'Apresentação sem data da etapa usa a data de criação do lead' );
check( ! isset( $a_item['stage_source'] ) && 'ana@example.com' === $a_item['contact']['email'] && 'Ana Presentacao' === $a_item['contact']['name'], 'sem origem da etapa não envia stage_source; perfil enviado' );
$b_item = LeadBackfillService::build_item( $fetch( 'b' ) );
check( 'negotiating' === $b_item['stage'] && 'vitpress_mobile' === $b_item['stage_source'] && 'Google Ads' === $b_item['contact']['source'], 'etapa, origem da etapa e rótulo de origem do lead' );
check( 'stage_without_date' === LeadBackfillService::build_item( $fetch( 'c' ) ), 'lead fora de Apresentação sem data da etapa é ignorado (data não é inventada)' );
check( 'no_phone' === LeadBackfillService::build_item( $fetch( 'd' ) ), 'lead sem telefone é ignorado' );
$e_item = LeadBackfillService::build_item( $fetch( 'e' ) );
check( '11111111-2222-4333-8444-555555555555' === $e_item['shared_contact_id'] && 'lost' === $e_item['stage'], 'lead já vinculado envia o shared_contact_id' );
check( 'already_linked' === LeadBackfillService::build_item( $fetch( 'e' ), false ), 'com --skip-linked o lead vinculado não é enviado' );
$g_item = LeadBackfillService::build_item( $fetch( 'g' ) );
check( is_array( $g_item ) && false !== strpos( $g_item['stage_updated_at'], '2026-02-10T' ), 'data zerada (0000-00-00) é tratada como ausente' );
check( false === strpos( wp_json_encode( $a_item ), 'notes' ) && ! isset( $a_item['contact']['phone'] ), 'o perfil não carrega observações nem telefone duplicado' );

// ---- dry-run: classifica, pergunta ao VitAds em modo simulação e não grava nada
$snap0 = $snapshot();
$conv0 = $conversions();
$calls = array();
$dry   = $run();
check( 'completed' === $dry['status'] && 'dry_run' === $dry['mode'] && 7 === $dry['scanned'], 'dry-run lê os 7 leads e termina' );
check( ! empty( $calls ) && true === $calls[0]['dry_run'], 'o VitAds é consultado com dry_run=true' );
check( 1 === ( $dry['skipped']['stage_without_date'] ?? 0 ) && 1 === ( $dry['skipped']['no_phone'] ?? 0 ) && 1 === ( $dry['skipped']['duplicate_phone'] ?? 0 ), 'ignorados no site: 1 sem data da etapa, 1 sem telefone e 1 telefone repetido (o mesmo número do lead a)' );
check( 2 === $dry['counts']['created'] && 1 === $dry['counts']['existing'] && 1 === $dry['counts']['ambiguous'], 'dry-run: a contagem por resultado já é a da execução real (2 criados, 1 existente, 1 ambíguo)' );
check( null === $fetch( 'a' )->shared_contact_id && false === get_option( LeadBackfillService::OPTION_STATE, false ) && array() === LeadBackfillService::failures(), 'dry-run não grava vínculo, estado nem registro de falhas' );
check( $snap0 === $snapshot() && $conv0 === $conversions(), 'dry-run não altera etapa, data nem registros de conversão' );

// ---- somente local: nenhuma chamada
$calls = array();
$loc   = $run( array( 'local_only' => true ) );
check( empty( $calls ) && 'local_only' === $loc['mode'] && 4 === $loc['counts']['would_send'], 'local-only não chama o VitAds e conta 4 que seriam enviados' );

// ---- execução real
$calls = array();
$exec  = $run( array( 'execute' => true ) );
check( 'completed' === $exec['status'] && 'execute' === $exec['mode'], 'execução real conclui' );
check( 2 === $exec['counts']['created'] && 1 === $exec['counts']['existing'] && 1 === $exec['counts']['ambiguous'] && 1 === $exec['skipped']['duplicate_phone'], 'resultado: 2 criados (a, b), 1 já existente (e), 1 ambíguo no VitAds (g) e 1 telefone repetido ignorado (f)' );
check( ! empty( $fetch( 'a' )->shared_contact_id ) && ! empty( $fetch( 'b' )->shared_contact_id ) && '11111111-2222-4333-8444-555555555555' === $fetch( 'e' )->shared_contact_id, 'vínculo gravado nos leads importados' );
check( empty( $fetch( 'f' )->shared_contact_id ) && empty( $fetch( 'g' )->shared_contact_id ) && empty( $fetch( 'c' )->shared_contact_id ) && empty( $fetch( 'd' )->shared_contact_id ), 'ambíguo, repetido e ignorados ficam sem vínculo' );
check( $snap0 === $snapshot() && $conv0 === $conversions(), 'importar NÃO altera etapa, data nem cria registro de conversão no VitPress' );
check( 0 === $http_hits, 'nenhuma requisição HTTP além do transporte do backfill (nenhum evento de conversão saiu)' );
$fails = LeadBackfillService::failures();
$blob  = wp_json_encode( $fails );
check( 2 === count( $fails ), 'duas ocorrências para revisão: o telefone repetido e o ambíguo' );
check( in_array( array( $ids['f'], 'skipped', 'duplicate_phone' ), array_map( static fn ( $f ) => array( $f['lead_id'], $f['status'], $f['reason'] ), $fails ), true ) && in_array( array( $ids['g'], 'ambiguous', 'phone_matches_many' ), array_map( static fn ( $f ) => array( $f['lead_id'], $f['status'], $f['reason'] ), $fails ), true ), 'falha registrada: só id do lead, status e código' );
check( false === strpos( $blob, 'Fabio' ) && false === strpos( $blob, '91111' ) && false === strpos( $blob, '@' ), 'o registro de falhas não guarda nome, telefone nem e-mail' );
$st = get_option( LeadBackfillService::OPTION_STATE );
check( is_array( $st ) && ! empty( $st['completed_at'] ) && (int) $st['cursor'] >= $ids['g'], 'estado gravado: concluído e cursor no último lead' );
check( false === get_option( LeadBackfillService::OPTION_LOCK, false ), 'a trava é liberada ao final' );

// ---- idempotência: reexecutar não duplica
$before = $wpdb->get_col( 'SELECT shared_contact_id FROM ' . $table . ' WHERE id IN (' . implode( ',', $ids ) . ') ORDER BY id' );
$again  = $run( array( 'execute' => true ) );
check( 0 === $again['counts']['created'] && $before === $wpdb->get_col( 'SELECT shared_contact_id FROM ' . $table . ' WHERE id IN (' . implode( ',', $ids ) . ') ORDER BY id' ), 'segunda execução: nada novo é criado e os vínculos não mudam' );
check( 2 === count( $vitads_state['phones'] ), 'o VitAds simulado continua com 2 telefones (sem duplicação)' );

// ---- retomada: pausa por limite de lotes e continuação do cursor
$reset_links();

$vitads_state = array( 'phones' => array(), 'locals' => array() );
delete_option( LeadBackfillService::OPTION_FAILURES );
$p1 = $run( array( 'execute' => true, 'restart' => true, 'batch' => 2, 'max_batches' => 1 ) );
check( 'paused' === $p1['status'] && 1 === $p1['batches'] && $p1['cursor'] === $ids['b'], 'limite de lotes pausa e grava o cursor no último lead lido' );
$st = get_option( LeadBackfillService::OPTION_STATE );
check( empty( $st['completed_at'] ) && (int) $st['cursor'] === $ids['b'], 'o estado fica como não concluído, com o cursor' );
$calls = array();
$p2    = $run( array( 'execute' => true, 'batch' => 2 ) );
check( 'completed' === $p2['status'] && $p2['scanned'] === 7 && ! empty( $calls ) && (string) $ids['a'] !== $calls[0]['contacts'][0]['local_id'], 'retomada continua depois do cursor e não reenvia o primeiro lote' );
check( $p2['counts']['created'] === 2 && $p2['counts']['existing'] === 1 && $p2['counts']['ambiguous'] === 2, 'totais acumulados entre as duas execuções (após a retomada, o repetido f chega ao VitAds, que o recusa como ambíguo; nada é duplicado)' );

// ---- falhas do VitAds: interrompe, mantém o cursor, retoma no mesmo lote
$reset_links();

$vitads_state = array( 'phones' => array(), 'locals' => array() );
$mode         = '404';
$x            = $run( array( 'execute' => true, 'restart' => true ) );
check( 'aborted' === $x['status'] && 'vitads_unsupported' === $x['reason'] && $base_id === (int) get_option( LeadBackfillService::OPTION_STATE )['cursor'], 'VitAds sem a rota (404): interrompe com motivo claro e sem avançar o cursor' );
$mode = '503';
$x    = $run( array( 'execute' => true, 'restart' => true ) );
check( 'aborted' === $x['status'] && 'vitads_schema_outdated' === $x['reason'], 'VitAds sem migrations (503): interrompe' );
$mode  = 'net';
$calls = array();
$x     = $run( array( 'execute' => true, 'restart' => true ) );
check( 'aborted' === $x['status'] && 'vitads_unreachable' === $x['reason'] && 3 === count( $calls ), 'rede fora: 3 tentativas do mesmo lote e então interrompe' );
check( null === $fetch( 'a' )->shared_contact_id, 'nada foi gravado nas interrupções' );
$mode = 'ok';
$x    = $run( array( 'execute' => true ) );
check( 'completed' === $x['status'] && 2 === $x['counts']['created'] && 0 === $x['counts']['error'], 'depois que o VitAds volta, a execução termina sem perda e sem contar o lote repetido duas vezes' );

// ---- trava de execução única
update_option( LeadBackfillService::OPTION_LOCK, time(), false );
$x = $run( array( 'execute' => true ) );
check( 'aborted' === $x['status'] && 'locked' === $x['reason'], 'com outra execução em andamento a real é recusada' );
update_option( LeadBackfillService::OPTION_LOCK, time() - 3600, false );
$x = $run( array( 'execute' => true, 'restart' => true ) );
check( 'completed' === $x['status'], 'trava vencida (execução interrompida) é substituída' );
check( 'completed' === $run()['status'], 'o dry-run não depende da trava' );

// ---- ausência de caminho de conversão no código e comando só manual
$code = file_get_contents( dirname( __DIR__ ) . '/core/Services/Integrations/LeadBackfillService.php' );
check( false === strpos( $code, 'do_action' ) && false === strpos( $code, 'ConversionEventService' ) && false === strpos( $code, 'VitAdsEventService::send' ) && false === strpos( $code, 'wp_schedule' ), 'o serviço não dispara hooks/conversões e não se agenda sozinho' );
$main = file_get_contents( dirname( __DIR__ ) . '/hub.php' );
check( false !== strpos( $main, "'vitpress backfill-leads'" ) && false !== strpos( $main, "defined( 'WP_CLI' )" ), 'o comando só existe no WP-CLI' );

// ---- limpeza
$wpdb->query( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' );
update_option( 'hub_settings', $settings );
delete_option( LeadBackfillService::OPTION_STATE );
delete_option( LeadBackfillService::OPTION_FAILURES );
delete_option( LeadBackfillService::OPTION_LOCK );

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: importacao de leads antigos ok\n";
