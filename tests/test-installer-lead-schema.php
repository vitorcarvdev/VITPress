<?php
/**
 * Regressão: sites que já registram hub_db_version = 1.0.4, mas cuja tabela hub_leads foi criada
 * antes de shared_contact_id (pacote 1.9.1), precisam receber a coluna sem perder leads.
 *
 * ATENÇÃO: este teste cria/derruba tabelas com prefixo próprio (wptreg_), troca $wpdb->prefix e
 * regrava opções do site carregado. Rode SOMENTE em WordPress descartável de teste.
 * Por isso exige opt-in explícito:
 *
 *   $env:HUB_ALLOW_SCHEMA_TEST = '1'
 *   php tests/test-installer-lead-schema.php
 *
 * Opcional: HUB_WP_LOAD=<caminho do wp-load.php>. Saída 2 = não executado (sem opt-in ou sem WP).
 */

if ( '1' !== getenv( 'HUB_ALLOW_SCHEMA_TEST' ) ) {
	echo "NÃO EXECUTADO: defina HUB_ALLOW_SCHEMA_TEST=1 (somente em WordPress descartável de teste)\n";
	exit( 2 );
}
$wp_load = getenv( 'HUB_WP_LOAD' ) ?: dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $wp_load ) ) {
	echo "NÃO EXECUTADO: wp-load.php não encontrado ({$wp_load})\n";
	exit( 2 );
}
define( 'WP_USE_THEMES', false );
require $wp_load;

use Hub\Core\Installer;

global $wpdb;
$fail = 0;
function check( bool $cond, string $msg ): void {
	global $fail;
	echo ( $cond ? 'ok ' : 'FALHA ' ) . $msg . PHP_EOL;
	if ( ! $cond ) {
		++$fail;
	}
}

$real_prefix = $wpdb->prefix;
$prefixes    = array( 'wptreg_', 'wptreg-x_' ); // o 2º tem hífen: exige identificadores entre crases
$cfg         = require HUB_PATH . 'config/config.php';
$fixture     = (string) file_get_contents( __DIR__ . '/fixtures/hub_leads-1.0.4.sql' );
$saved       = array();
foreach ( array( 'hub_settings', 'hub_atendimento_start_date', 'hub_db_version' ) as $opt ) {
	$saved[ $opt ] = get_option( $opt, null );
}
$saved_error_log = ini_get( 'error_log' );
$log_file        = tempnam( sys_get_temp_dir(), 'hubreg' );
ini_set( 'error_log', $log_file );

// Registro de consultas e injeção de falhas via filtro 'query' do wpdb.
$q_log = array();
$mode  = 'normal'; // normal | fail_alter | race_alter
$GLOBALS['q_log'] = &$q_log;
$GLOBALS['mode']  = &$mode;
add_filter(
	'query',
	static function ( $sql ) {
		global $wpdb;
		static $inside = false;
		$GLOBALS['q_log'][] = $sql;
		if ( ! $inside && 0 === strpos( ltrim( $sql ), 'ALTER TABLE' ) && false !== strpos( $sql, 'ADD COLUMN' ) ) {
			if ( 'fail_alter' === $GLOBALS['mode'] ) {
				return 'ALTER TABLE `tabela_que_nao_existe_hubreg` ADD COLUMN `x` int NULL';
			}
			if ( 'race_alter' === $GLOBALS['mode'] ) {
				$inside = true; // "outra requisição" cria a coluna antes desta
				$wpdb->query( $sql );
				$inside = false;
			}
		}
		return $sql;
	},
	1
);
$count = static function ( string $re ) use ( &$q_log ): int {
	return count( array_filter( $q_log, static fn ( $s ) => (bool) preg_match( $re, $s ) ) );
};
$reset = static function () use ( &$q_log ): void {
	$q_log = array();
};

$drop_all = static function () use ( $wpdb, $prefixes ): void {
	foreach ( $prefixes as $p ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p ) . 'hub\_%' ) ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $t . '`' );
		}
	}
};
$tbl     = static fn () => '`' . $GLOBALS['wpdb']->prefix . 'hub_leads`';
$has_col = static fn () => in_array( 'shared_contact_id', $GLOBALS['wpdb']->get_col( 'SHOW COLUMNS FROM ' . $tbl(), 0 ), true );
$drop_col = static function () use ( $wpdb, $tbl ): void {
	$wpdb->query( 'ALTER TABLE ' . $tbl() . ' DROP COLUMN `shared_contact_id`' );
};
$snapshot = static function () use ( $wpdb, $tbl ): array {
	$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . $tbl() . ' ORDER BY id', ARRAY_A );
	foreach ( $rows as &$r ) {
		unset( $r['shared_contact_id'] );
	}
	return $rows;
};
$make_old_table = static function ( string $prefix ) use ( $wpdb, $fixture ): void {
	$wpdb->prefix = $prefix;
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $prefix . 'hub_leads`' );
	$wpdb->query( str_replace( '{prefix}', $prefix, $fixture ) );
	$fixtures = array(
		array( 'Lead Ficticio A', '(11) 90000-0001', 'lead_inicial', '2026-01-10 10:00:00', null, null ),
		array( 'Lead Ficticio B', '(11) 90000-0002', 'presentation', '2026-02-11 11:00:00', '2026-02-12 09:00:00', 'vitpress_admin' ),
		array( 'Lead Ficticio C', '', 'won', '2026-03-12 12:00:00', '2026-03-13 15:30:00', 'vitpress_mobile' ),
	);
	foreach ( $fixtures as $f ) {
		$wpdb->insert(
			$prefix . 'hub_leads',
			array(
				'name'             => $f[0],
				'email'            => 'ficticio@example.invalid',
				'phone'            => $f[1],
				'stage'            => $f[2],
				'created_at'       => $f[3],
				'stage_updated_at' => $f[4],
				'stage_source'     => $f[5],
				'notes'            => 'nota ' . $f[0],
				'custom_data'      => '{"ficticio":true}',
				'revenue_value'    => 123.45,
			)
		);
	}
};

try {
	$drop_all();

	// ---- 1. Estrutura antiga fiel ao backup (57 colunas, sem shared_contact_id) -------------
	$make_old_table( 'wptreg_' );
	$before = $snapshot();
	check( 3 === count( $before ) && 57 === count( $wpdb->get_col( 'SHOW COLUMNS FROM ' . $tbl(), 0 ) ), 'fixture: DDL completo do backup (57 colunas) com 3 leads fictícios' );
	check( ! $has_col(), 'fixture: tabela antiga NÃO tem shared_contact_id' );
	check( array( 'shared_contact_id' ) === Installer::missing_lead_columns(), 'missing_lead_columns() aponta somente shared_contact_id' );
	check( ! Installer::lead_schema_complete(), 'lead_schema_complete() falso na estrutura antiga' );

	// ---- 2. ensure_lead_columns() DIRETO, sem dbDelta/instalador --------------------------------
	$reset();
	$r = Installer::ensure_lead_columns();
	check( array( 'shared_contact_id' ) === $r['added'] && array() === $r['failed'], 'ensure_lead_columns() adiciona a coluna e reporta sucesso' );
	check( $has_col(), 'coluna existe depois de ensure_lead_columns()' );
	check( 0 === $count( '/^\s*(DESCRIBE|SHOW INDEX)/i' ), 'nenhuma chamada a dbDelta/instalador (sem DESCRIBE/SHOW INDEX)' );
	check( $snapshot() === $before, 'leads idênticos (todas as 57 colunas antigas, sem perda nem alteração)' );
	check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl() . ' WHERE shared_contact_id IS NOT NULL' ), 'shared_contact_id nasce NULL' );
	check( 'varchar(36)' === $wpdb->get_row( 'SHOW COLUMNS FROM ' . $tbl() . " LIKE 'shared_contact_id'", ARRAY_N )[1], 'tipo da coluna varchar(36)' );
	$r2 = Installer::ensure_lead_columns();
	check( array() === $r2['added'] && array() === $r2['failed'], 'segunda chamada é idempotente (nada a adicionar)' );
	check( 3 === (int) $wpdb->get_var( 'SELECT COUNT(*) - COUNT(DISTINCT id) + 3 FROM ' . $tbl() ), 'sem ids duplicados' );
	$wpdb->suppress_errors( true );
	$row = $wpdb->get_row( 'SELECT id, stage, shared_contact_id FROM ' . $tbl() . ' WHERE id = ' . (int) $before[0]['id'], ARRAY_A );
	$wpdb->suppress_errors( false );
	check( is_array( $row ) && '' === (string) $wpdb->last_error, 'SELECT com shared_contact_id (como no PipelineContactSync) funciona' );

	// ---- 3. maybe_upgrade() com versão e esquema corretos: caminho barato ----------------------------
	update_option( 'hub_db_version', $cfg['db_version'] );
	$reset();
	Installer::maybe_upgrade();
	check( 0 === $count( '/^\s*(DESCRIBE|SHOW INDEX|ALTER|CREATE)/i' ), 'versão e esquema corretos: o instalador completo NÃO roda' );
	check( 1 === $count( '/^\s*SHOW COLUMNS/i' ), 'caminho normal faz uma única consulta SHOW COLUMNS' );
	check( false === get_transient( Installer::REPAIR_BACKOFF_KEY ), 'sem backoff registrado quando tudo está certo' );

	// ---- 4. Reparo via maybe_upgrade() com versão JÁ registrada (o bug real) ------------------------
	$drop_col();
	check( ! $has_col() && $cfg['db_version'] === get_option( 'hub_db_version' ), 'cenário: coluna ausente e hub_db_version já igual ao config' );
	$reset();
	Installer::maybe_upgrade();
	check( $has_col(), 'maybe_upgrade() repara a coluna sem mudar a versão' );
	check( 0 === $count( '/^\s*(DESCRIBE|SHOW INDEX)/i' ), 'o reparo NÃO executa o instalador completo (tabela existe)' );
	check( $snapshot() === $before, 'leads preservados após o reparo' );
	check( false === get_transient( Installer::REPAIR_BACKOFF_KEY ), 'reparo bem-sucedido não deixa backoff' );

	// ---- 5. Falha no ALTER: não é sucesso e o backoff evita repetição -------------------------
	$drop_col();
	$mode = 'fail_alter';
	$reset();
	$r = Installer::ensure_lead_columns();
	check( array() === $r['added'] && array( 'shared_contact_id' ) === $r['failed'], 'falha no ALTER NÃO é reportada como sucesso' );
	check( ! $has_col(), 'coluna continua ausente após a falha' );
	$reset();
	Installer::maybe_upgrade();
	Installer::maybe_upgrade();
	Installer::maybe_upgrade();
	check( 1 === $count( '/ADD COLUMN/i' ), 'três requisições seguidas: apenas UMA tentativa de ALTER (backoff)' );
	check( false !== get_transient( Installer::REPAIR_BACKOFF_KEY ), 'backoff registrado após a falha' );
	check( 0 === $count( '/^\s*(DESCRIBE|SHOW INDEX)/i' ), 'falha não dispara o instalador completo' );
	$ttl = (int) get_option( '_transient_timeout_' . Installer::REPAIR_BACKOFF_KEY ) - time();
	check( $ttl > 3500 && $ttl <= 3600, 'backoff de ~1 hora (' . $ttl . 's restantes)' );
	$log = (string) file_get_contents( $log_file );
	check( false !== strpos( $log, 'Falha ao adicionar a coluna shared_contact_id' ), 'falha registrada com error_log' );
	check( false === strpos( $log, 'Lead Ficticio' ) && false === strpos( $log, 'example.invalid' ) && false === strpos( $log, '90000-' ), 'log sem dados de leads' );
	check( $snapshot() === $before, 'leads preservados após a falha' );

	// ---- 6. Backoff vigente bloqueia; limpo + causa resolvida => repara e limpa o backoff ----------
	$mode = 'normal';
	$reset();
	Installer::maybe_upgrade();
	check( ! $has_col() && 0 === $count( '/ADD COLUMN/i' ), 'com backoff vigente não tenta de novo, mesmo com a causa resolvida' );
	delete_transient( Installer::REPAIR_BACKOFF_KEY );
	Installer::maybe_upgrade();
	check( $has_col() && false === get_transient( Installer::REPAIR_BACKOFF_KEY ), 'após o backoff, repara e deixa o backoff limpo' );

	// ---- 7. Concorrência: outra requisição cria a coluna entre o SHOW e o ALTER ---------------------
	$drop_col();
	$mode = 'race_alter';
	$log_before = strlen( (string) file_get_contents( $log_file ) );
	$r = Installer::ensure_lead_columns();
	$mode = 'normal';
	check( $has_col(), 'concorrência: coluna existe' );
	check( array() === $r['failed'], 'concorrência: "coluna já existe" não é tratada como falha' );
	check( array() === $r['added'], 'concorrência: não conta como adicionada por esta chamada' );
	check( strlen( (string) file_get_contents( $log_file ) ) === $log_before, 'concorrência: nada novo no error_log' );

	// ---- 8. Caminho clássico por mudança de versão ----------------------------------------------
	$drop_col();
	update_option( 'hub_db_version', '0.0.1' );
	Installer::maybe_upgrade();
	check( $has_col() && $snapshot() === $before, 'versão diferente: instalador completo repara sem perda' );
	check( $cfg['db_version'] === get_option( 'hub_db_version' ), 'hub_db_version volta ao valor do config' );

	// ---- 9. Tabela inexistente: instalador completo cria tudo ---------------------------------------
	$wpdb->query( 'DROP TABLE ' . $tbl() );
	check( null === Installer::missing_lead_columns(), 'tabela inexistente é distinguida de esquema incompleto (null)' );
	check( array() === Installer::ensure_lead_columns()['added'], 'ensure_lead_columns() ignora tabela inexistente' );
	$reset();
	Installer::maybe_upgrade();
	check( $count( '/^\s*DESCRIBE/i' ) > 0 && Installer::lead_schema_complete(), 'tabela ausente: o instalador completo roda e cria a tabela completa' );

	// ---- 10. Prefixo com caractere especial (hífen): identificadores entre crases ----------------------
	$make_old_table( 'wptreg-x_' );
	$before_x = $snapshot();
	$r        = Installer::ensure_lead_columns();
	check( array( 'shared_contact_id' ) === $r['added'] && $has_col() && $snapshot() === $before_x, 'prefixo com hífen: coluna criada e leads preservados' );
} finally {
	remove_all_filters( 'query', 1 );
	$drop_all();
	$wpdb->prefix = $real_prefix;
	delete_transient( Installer::REPAIR_BACKOFF_KEY );
	foreach ( $saved as $opt => $val ) {
		if ( null === $val ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $val );
		}
	}
	ini_set( 'error_log', (string) $saved_error_log );
	@unlink( $log_file );
}

if ( $fail > 0 ) {
	echo "RESULTADO: {$fail} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: installer lead schema ok\n";
