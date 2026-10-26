<?php
/**
 * Banco antigo em utf8 (3 bytes): salvar o Atendente com emoji falhava em silêncio (a gravação inteira era recusada).
 * Roda no WordPress DESCARTÁVEL: converte a tabela de opções para utf8mb3, testa numa 2ª execução (o wpdb guarda a
 * codificação das colunas em cache dentro da requisição) e restaura utf8mb4. Nada sai da máquina.
 */
$phase = isset( $argv[1] ) ? $argv[1] : 'main';
$_SERVER['HTTP_HOST'] = '127.0.0.1:8092'; $_SERVER['SERVER_NAME'] = '127.0.0.1';
require 'C:/Users/vitor/AppData/Local/Temp/vcsis-homolog/hub/wp-load.php';
global $wpdb;
if ( $wpdb->get_var( 'SELECT DATABASE()' ) !== 'wp_homolog' || false === strpos( DB_HOST, '3308' ) ) { echo "ABORTADO: não é o WordPress descartável\n"; exit( 3 ); }
$failed = 0;
function check( $c, $m ) { global $failed; echo ( $c ? 'ok ' : 'FALHA ' ) . $m . PHP_EOL; if ( ! $c ) { $failed++; } }
class Rd extends Exception { public $loc; }
add_filter( 'wp_redirect', static function ( $l ) { $e = new Rd( 'r' ); $e->loc = $l; throw $e; }, 1 );
wp_set_current_user( 1 );
add_filter( 'user_has_cap', static function ( $c ) { $c['hub_vitagencia_manage_internal'] = true; return $c; } );
$t   = $wpdb->options;
$col = static function () use ( $wpdb, $t ) { return $wpdb->get_var( "SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = 'option_value'" ); };
$svc = '\Hub\Core\Services\LeadCollectorService';

if ( 'phase2' === $phase ) {
	// Nova execução: o banco já está em utf8 de 3 bytes.
	check( 'utf8mb3' === $col(), 'tabela de opções em utf8 de 3 bytes (como em bancos antigos)' );
	check( ! $svc::db_supports_4byte(), 'utf8 de 3 bytes é detectado' );
	$wpdb->suppress_errors( true );
	delete_option( 'rig_emoji' );
	$ok  = update_option( 'rig_emoji', "Olá! \u{1F44B}" );
	$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$t} WHERE option_name = %s", 'rig_emoji' ) );
	check( false === $ok && null === $row, 'o problema original: update_option com emoji retorna false e não grava nada' );
	$wpdb->suppress_errors( false );

	$_POST = array( 'action' => 'hub_save_lead_collector', 'hub_nonce' => wp_create_nonce( 'hub_lead_collector_nonce' ), 'collector' => array( 'enabled' => '1', 'attendant_name' => 'Vitor', 'attendant_role' => 'Especialista', 'attendant_avatar_id' => '25', 'primary_color' => '#1b4d3e', 'position' => 'right', 'badge_delay' => '10', 'auto_open_enabled' => '1', 'auto_open_delay' => '20', 'notification_emails' => '', 'final_message' => "Obrigado, {nome}! \u{1F389}", 'flow_json' => wp_slash( wp_json_encode( $svc::get_default_flow(), JSON_UNESCAPED_UNICODE ) ) ), 'forms' => array( 'whatsapp' => '5581999999999', 'whatsapp_msg' => "Oi \u{1F44B} sou {nome}" ) );
	$_REQUEST = $_POST;
	$loc = '';
	try { ( new \Hub\Modules\Tools\ToolsModule() )->handle_save_lead_collector(); } catch ( Rd $e ) { $loc = $e->loc; }
	check( false !== strpos( $loc, 'message=saved' ), 'com a correção, salvar o Atendente (com emojis) funciona no banco antigo' );
	$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$t} WHERE option_name = %s", 'hub_lead_collector' ) );
	check( false !== strpos( $raw, '&#x1f44b;' ) && ! preg_match( '/[\x{10000}-\x{10FFFF}]/u', $raw ), 'no banco ficam entidades, sem emoji bruto' );
	$s = $svc::get_settings();
	check( false !== strpos( $s['flow'][0]['message'], "\u{1F44B}" ) && false !== strpos( $s['final_message'], "\u{1F389}" ) && $s['enabled'] && 'Vitor' === $s['attendant_name'] && 25 === (int) $s['attendant_avatar_id'], 'na leitura os emojis voltam, e ativar/nome/foto persistem' );
	$hs = get_option( 'hub_settings' );
	check( "Oi \u{1F44B} sou {nome}" === $svc::decode_emoji( $hs['forms']['whatsapp_msg'] ) && '5581999999999' === $hs['forms']['whatsapp'], 'a mensagem do WhatsApp também' );
	ob_start();
	include HUB_PATH . 'admin/views/atendente.php';
	$html = ob_get_clean();
	check( false !== strpos( $html, 'codificação da tabela de opções: <strong>utf8' ) && false !== strpos( $html, 'sem emojis de 4 bytes' ), 'o diagnóstico da página informa a codificação do banco' );
	echo $failed ? "FASE2: {$failed} falha(s)\n" : "FASE2 ok\n";
	exit( $failed ? 1 : 0 );
}

// Fase principal
$orig = get_option( 'hub_lead_collector' );
check( '&#x1f44b; ok' === $svc::encode_emoji( "\u{1F44B} ok" ) && "\u{1F44B} ok" === $svc::decode_emoji( '&#x1f44b; ok' ), 'emoji vira entidade e volta ao original' );
check( 'Olá ç &amp; &#x41;' === $svc::decode_emoji( 'Olá ç &amp; &#x41;' ) && 'Olá ç' === $svc::encode_emoji( 'Olá ç' ), 'acentos e entidades comuns não são tocados' );
check( array( 'a' => array( '&#x1f389;' ) ) === $svc::encode_emoji( array( 'a' => array( "\u{1F389}" ) ) ), 'funciona dentro de listas (fluxo de perguntas)' );
check( 'utf8mb4' === $col() && $svc::db_supports_4byte(), 'utf8mb4: tabela de opções reconhecida, nada é convertido' );
try {
	if ( false === $wpdb->query( "ALTER TABLE {$t} CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci" ) ) { echo 'ALTER falhou: ' . $wpdb->last_error . "\n"; exit( 4 ); }
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -c ' . escapeshellarg( (string) php_ini_loaded_file() ) . ' ' . escapeshellarg( __FILE__ ) . ' phase2 2>&1', $out, $code );
	echo implode( PHP_EOL, $out ) . PHP_EOL;
	check( 0 === $code, 'fase com o banco em utf8 de 3 bytes passou' );
} finally {
	$wpdb->query( "DELETE FROM {$t} WHERE option_name IN ('rig_emoji','hub_lead_collector')" );
	$wpdb->query( "ALTER TABLE {$t} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" );
	if ( false !== $orig ) { update_option( 'hub_lead_collector', $orig ); }
	wp_cache_flush();
}
check( 'utf8mb4' === $col(), 'tabela de opções restaurada em utf8mb4' );
echo $failed ? "RESULTADO: {$failed} falha(s)\n" : "RESULTADO: emojis em banco utf8 ok\n";
exit( $failed ? 1 : 0 );