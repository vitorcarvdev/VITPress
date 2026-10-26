<?php
/**
 * Restauração: interrupção em cada etapa (processos PHP encerrados no meio), falhas no RENAME, no pós-processamento
 * e na reversão, restaurações concorrentes, recarga, limpeza automática e acesso HTTP aos temporários.
 *
 * Só usa dados fictícios em pastas temporárias. O banco padrão é simulado em arquivo JSON.
 *   php tests/test-backup-recovery.php
 * Opcionais:
 *   VITPRESS_TMP_DB=vitpress_tmp_xxx  troca e falhas em MySQL descartável (criado e removido pelo teste)
 *   VITPRESS_HTTP_TEST=1              cria uma pasta temporária em D:/LocalHost/www, testa http://localhost e remove
 */

$mode = $argv[1] ?? '';

if ( '--cleanup-child' === $mode || '--cache-child' === $mode ) {
	$root = $argv[2];
	define( 'ABSPATH', $root . '/site/' );
	define( 'WP_CONTENT_DIR', $root . '/site/wp-content' );
} else {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'HUB_PATH', dirname( __DIR__ ) . '/' );

foreach ( array( 'BackupSql', 'BackupArchiveValidator', 'BackupFileMover', 'BackupDatabaseImporter', 'BackupWorkspace', 'RestoreOperation', 'RestoreRunner', 'RecoveryPreflight', 'RecoveryEndpoint' ) as $class ) {
	require_once HUB_PATH . "core/Services/Backup/{$class}.php";
}

use Hub\Core\Services\Backup\BackupArchiveValidator;
use Hub\Core\Services\Backup\BackupFileMover;
use Hub\Core\Services\Backup\BackupWorkspace;
use Hub\Core\Services\Backup\RecoveryEndpoint;
use Hub\Core\Services\Backup\RecoveryPreflight;
use Hub\Core\Services\Backup\RestoreOperation;
use Hub\Core\Services\Backup\RestoreRunner;

/**
 * Tabelas persistidas em arquivo, para sobreviverem ao fim do processo. Cada tabela guarda um marcador
 * (primeiro valor inserido), suficiente para saber se o conteúdo é o do site atual ou o do backup.
 */
class JsonDb {
	private $file;
	private $error = '';
	public function __construct( $file ) {
		$this->file = $file;
	}
	private function load() {
		return json_decode( (string) @file_get_contents( $this->file ), true ) ?: array( 'tables' => array(), 'fail' => array(), 'count' => array() );
	}
	private function save( array $d ) {
		file_put_contents( $this->file, json_encode( $d ), LOCK_EX );
	}
	public function set_fail( $pattern, $after = 0 ) {
		$d                   = $this->load();
		$d['fail'][ $pattern ] = $after;
		$d['count'][ $pattern ] = 0;
		$this->save( $d );
	}
	public function clear_fail() {
		$d         = $this->load();
		$d['fail'] = array();
		$this->save( $d );
	}
	public function query( $sql ) {
		$d           = $this->load();
		$this->error = '';
		foreach ( $d['fail'] as $pattern => $after ) {
			if ( false !== strpos( $sql, $pattern ) ) {
				$d['count'][ $pattern ] = ( $d['count'][ $pattern ] ?? 0 ) + 1;
				if ( $d['count'][ $pattern ] > $after ) {
					$this->save( $d );
					$this->error = 'Lock wait timeout exceeded; try restarting transaction';
					return false;
				}
			}
		}
		if ( 0 === strpos( $sql, 'SET ' ) ) {
			$this->save( $d );
			return true;
		}
		if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) ) {
			$d['tables'][ $m[1] ] = '';
		} elseif ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m ) ) {
			unset( $d['tables'][ $m[1] ] );
		} elseif ( preg_match( "/^INSERT INTO `([^`]+)` VALUES \\('([^']*)'/", $sql, $m ) ) {
			if ( ! array_key_exists( $m[1], $d['tables'] ) ) {
				$this->error = "Table doesn't exist";
				return false;
			}
			$d['tables'][ $m[1] ] = $m[2];
		} elseif ( preg_match( "/^UPDATE `([^`]+)` SET marker = CONCAT\\(REPLACE\\(marker, '([^']*)', ''\\), '[^']*'\\)$/", $sql, $m ) ) {
			$d['tables'][ $m[1] ] = str_replace( $m[2], '', $d['tables'][ $m[1] ] ) . $m[2];
		} elseif ( 0 === strpos( $sql, 'RENAME TABLE ' ) ) {
			$next = $d['tables'];
			foreach ( explode( ', ', substr( $sql, 13 ) ) as $pair ) {
				preg_match( '/`([^`]+)` TO `([^`]+)`/', $pair, $m );
				if ( ! array_key_exists( $m[1], $next ) || array_key_exists( $m[2], $next ) ) {
					$this->error = 'RENAME inválido';
					return false;
				}
				$next[ $m[2] ] = $next[ $m[1] ];
				unset( $next[ $m[1] ] );
			}
			$d['tables'] = $next;
		} else {
			$this->error = 'consulta não suportada no banco simulado';
			return false;
		}
		$this->save( $d );
		return true;
	}
	public function last_error() {
		return $this->error;
	}
	public function tables() {
		return array_keys( $this->load()['tables'] );
	}
	public function value( $sql ) {
		return '31536000';
	}
	public function marker( $table ) {
		return $this->load()['tables'][ $table ] ?? null;
	}
}

class MysqlDb {
	public $m;
	public function __construct( mysqli $m ) {
		$this->m = $m;
	}
	public function query( $sql ) {
		return false !== $this->m->query( $sql );
	}
	public function last_error() {
		return $this->m->error;
	}
	public function tables() {
		return array_column( $this->m->query( 'SHOW TABLES' )->fetch_all(), 0 );
	}
	public function value( $sql ) {
		$r = $this->m->query( $sql );
		return $r ? (string) $r->fetch_row()[0] : null;
	}
	public function marker( $table ) {
		$r = @$this->m->query( "SELECT marker FROM `{$table}` LIMIT 1" );
		return $r ? $r->fetch_row()[0] : null;
	}
}

function env_for( array $ctx, $op_id = '' ) {
	return array(
		'content_dir'       => $ctx['content'],
		'maintenance_file'  => $ctx['root'] . '/site/.maintenance',
		'protected_plugins' => array( 'hub-vitagencia' ),
		'deferred_root'     => $ctx['workspace'] . 'restored-' . $op_id,
		'new_prefix'        => 'wp_',
	);
}

function make_runner( RestoreOperation $op, $db, array $ctx, array $extra = array() ) {
	return new RestoreRunner(
		$op,
		$db,
		array_merge(
			env_for( $ctx, $op->id() ),
			array(
				'post_process'      => function ( array $state, array $tables ) use ( $db, $ctx ) {
					if ( is_file( $ctx['root'] . '/post-fail' ) ) {
						return array( 'ok' => false, 'error' => 'falha simulada' );
					}
					if ( is_file( $ctx['root'] . '/post-throw' ) ) {
						throw new RuntimeException( 'exceção simulada' );
					}
					return $db->query( "UPDATE `{$tables['options']}` SET marker = CONCAT(REPLACE(marker, '+post', ''), '+post')" ) ? array( 'ok' => true, 'error' => '' ) : array( 'ok' => false, 'error' => 'update' );
				},
				'verify_swapped'    => function () use ( $db, $ctx ) {
					if ( is_file( $ctx['root'] . '/verify-fail' ) ) {
						return array( 'ok' => false, 'error' => 'conferência simulada' );
					}
					return 'new-db+post' === $db->marker( 'wp_options' ) ? array( 'ok' => true, 'error' => '' ) : array( 'ok' => false, 'error' => 'banco ativo sem ajuste' );
				},
				'flush_cache'       => function () use ( $ctx ) {
					file_put_contents( $ctx['root'] . '/cache-flushes', "x", FILE_APPEND );
				},
				'preflight'         => function () use ( $ctx ) {
					return is_file( $ctx['root'] . '/preflight-fail' ) ? array( 'ok' => false, 'error' => 'verificação simulada recusada.' ) : array( 'ok' => true, 'error' => '' );
				},
			),
			$extra
		)
	);
}

function db_for( array $ctx ) {
	if ( ! empty( $ctx['mysql'] ) ) {
		mysqli_report( MYSQLI_REPORT_OFF );
		$m = new mysqli( '127.0.0.1', getenv( 'VITPRESS_TMP_DB_USER' ) ?: 'root', getenv( 'VITPRESS_TMP_DB_PASS' ) ?: '', $ctx['mysql'] );
		$m->set_charset( 'utf8mb4' );
		return new MysqlDb( $m );
	}
	return new JsonDb( $ctx['root'] . '/db.json' );
}

// ------------------------------------------------------------------ processo filho
if ( '--child' === $mode ) {
	$p     = json_decode( file_get_contents( $argv[2] ), true );
	$ctx   = $p['ctx'];
	$db    = db_for( $ctx );
	$hits  = 0;
	$point = function ( $name ) use ( $p, &$hits ) {
		if ( ! empty( $p['sleep_at'] ) && $name === $p['sleep_at'] ) {
			file_put_contents( $p['ready'], '1' );
			sleep( (int) $p['sleep'] );
		}
		if ( ! empty( $p['crash'] ) && $name === $p['crash'] && ++$hits >= ( $p['crash_n'] ?? 1 ) ) {
			exit( 3 );
		}
	};
	if ( 'start' === $p['action'] ) {
		$check  = BackupArchiveValidator::validate( $p['zip'] );
		$result = RestoreRunner::start( $ctx['workspace'], $p['zip'], $check, array( 'owner' => array( 'user' => 1 ), 'dest_url' => 'https://destino.test', 'checkpoint' => $point, 'env' => env_for( $ctx ) ) );
		unset( $result['op'] );
	} elseif ( 'recovery_link' === $p['action'] ) {
		$op     = RestoreOperation::active( $ctx['workspace'] );
		$result = $op ? make_runner( $op, $db, $ctx )->issue_recovery_token() : array( 'ok' => false, 'error' => 'sem operação' );
	} else {
		$op     = RestoreOperation::active( $ctx['workspace'] );
		$result = $op ? make_runner( $op, $db, $ctx, array_merge( array( 'checkpoint' => $point ), (array) ( $p['options'] ?? array() ) ) )->run( $p['action'] ) : array( 'ok' => false, 'error' => 'sem operação' );
	}
	echo json_encode( $result );
	exit( 0 );
}

// ------------------------------------------------------------------ limpeza automática (filho com WordPress simulado)
if ( '--cleanup-child' === $mode ) {
	function add_action() {}
	function add_filter() {}
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
	$_SERVER['DOCUMENT_ROOT'] = '';
	require HUB_PATH . 'core/Services/BackupService.php';
	$service = new Hub\Core\Services\BackupService();
	$service->cleanup_abandoned_backups();
	$ws  = ( new BackupWorkspace( WP_CONTENT_DIR ) )->path();
	$old = time() - 7200;
	$op  = RestoreOperation::create( $ws, array( 'user' => 1 ) );
	$op->update( array( 'phase' => 'switched' ) );
	mkdir( $op->extract_dir() );
	file_put_contents( $op->extract_dir() . 'database.sql', 'ficticio' );
	file_put_contents( $op->dir() . 'journal.jsonl', "{}\n" );
	file_put_contents( $ws . 'uploads/upload-restore-' . str_repeat( 'a', 32 ) . '.zip', 'velho' );
	file_put_contents( $ws . 'uploads/upload-restore-' . str_repeat( 'b', 32 ) . '.zip', 'novo' );
	file_put_contents( $ws . 'tmp/backup-velho.zip', 'velho' );
	$legacy = WP_CONTENT_DIR . '/hub-backups/temp/';
	mkdir( $legacy . 'restore-extract', 0777, true );
	file_put_contents( $legacy . 'restore-extract/database.sql', 'ficticio' );
	file_put_contents( $legacy . 'restore-state.json', '{}' );
	file_put_contents( $legacy . 'upload-restore-123.zip', 'velho' );
	$paths = array( $op->dir() . 'state.json', $op->extract_dir() . 'database.sql', $op->extract_dir(), $op->dir(), $op->dir() . 'journal.jsonl', $ws . 'restore.lock', $ws . 'uploads/upload-restore-' . str_repeat( 'a', 32 ) . '.zip', $ws . 'tmp/backup-velho.zip', $legacy . 'restore-extract/database.sql', $legacy . 'restore-extract', $legacy . 'restore-state.json', $legacy . 'upload-restore-123.zip' );
	foreach ( $paths as $path ) {
		touch( rtrim( $path, '/' ), $old );
	}
	$service->cleanup_abandoned_backups();
	echo json_encode(
		array(
			'op_state'       => is_file( $op->dir() . 'state.json' ),
			'op_sql'         => is_file( $op->extract_dir() . 'database.sql' ),
			'op_journal'     => is_file( $op->dir() . 'journal.jsonl' ),
			'lock'           => is_file( $ws . 'restore.lock' ),
			'upload_old'     => is_file( $ws . 'uploads/upload-restore-' . str_repeat( 'a', 32 ) . '.zip' ),
			'upload_new'     => is_file( $ws . 'uploads/upload-restore-' . str_repeat( 'b', 32 ) . '.zip' ),
			'tmp_old'        => is_file( $ws . 'tmp/backup-velho.zip' ),
			'legacy_extract' => file_exists( $legacy . 'restore-extract' ),
			'legacy_state'   => file_exists( $legacy . 'restore-state.json' ),
			'legacy_zip'     => file_exists( $legacy . 'upload-restore-123.zip' ),
			'legacy_htaccess'=> (string) @file_get_contents( $legacy . '.htaccess' ),
			'ws_in_content'  => 0 === strpos( str_replace( '\\', '/', $ws ), str_replace( '\\', '/', WP_CONTENT_DIR ) ),
			'ws_random'      => (bool) preg_match( '/vitpress-work-[a-f0-9]{32}\/$/', $ws ),
		)
	);
	exit( 0 );
}

/**
 * Primeira requisição do WordPress depois de uma recuperação. Cenários de cache: "none" (sem cache persistente),
 * "ok" (persistente que limpa de verdade) e "broken" (persistente cujo flush diz que limpou mas não limpa).
 */
if ( '--cache-child' === $mode ) {
	$GLOBALS['scenario'] = $argv[3];
	$GLOBALS['store']    = array();
	$GLOBALS['hooks']    = array();
	function add_action( $hook ) {
		$GLOBALS['hooks'][] = $hook;
	}
	function add_filter() {}
	function current_user_can() {
		return true;
	}
	function esc_html( $text ) {
		return $text;
	}
	function wp_using_ext_object_cache() {
		return 'none' !== $GLOBALS['scenario'];
	}
	function wp_cache_set( $key, $value, $group = '' ) {
		$GLOBALS['store'][ "$group:$key" ] = $value;
		return true;
	}
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$found = array_key_exists( "$group:$key", $GLOBALS['store'] );
		return $found ? $GLOBALS['store'][ "$group:$key" ] : false;
	}
	function wp_cache_flush() {
		if ( 'broken' !== $GLOBALS['scenario'] ) {
			$GLOBALS['store'] = array();
		}
		return true;
	}
	require HUB_PATH . 'core/Services/BackupService.php';
	$service = new Hub\Core\Services\BackupService();
	ob_start();
	$service->cache_flush_notice();
	$notice = ob_get_clean();
	echo json_encode(
		array(
			'marker' => is_file( WP_CONTENT_DIR . RecoveryEndpoint::FLUSH_MARKER ),
			'notice' => in_array( 'admin_notices', $GLOBALS['hooks'], true ) ? $notice : '',
		)
	);
	exit( 0 );
}

// ------------------------------------------------------------------ testes
$failed = 0;
function check( $condition, $message ) {
	global $failed;
	echo ( $condition ? 'ok ' : 'FALHA ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failed++;
	}
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vitpress-recovery-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $base );
$base = str_replace( '\\', '/', $base );
register_shutdown_function(
	function () use ( $base ) {
		BackupFileMover::delete_path( $base );
	}
);

function write_file( $path, $content ) {
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $content );
}

function backup_sql() {
	$sql = "-- sql_escaping: literal\n\n";
	foreach ( array( 'wp_options', 'wp_posts', 'wp_extra' ) as $table ) {
		$sql .= "DROP TABLE IF EXISTS `{$table}`;\nCREATE TABLE `{$table}` (\n  `marker` varchar(64) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\nINSERT INTO `{$table}` VALUES ('new-db');\n\n";
	}
	return $sql;
}

/**
 * Site fictício: arquivos "old" em wp-content, tabelas com marcador "old-db" e um ZIP de backup com "new".
 */
function new_site( $base, $name, $mysql = null ) {
	$root = "$base/$name";
	$ctx  = array( 'root' => $root, 'content' => "$root/site/wp-content", 'workspace' => "$root/work/", 'mysql' => $mysql );
	write_file( "{$ctx['content']}/plugins/app/version.txt", 'old' );
	write_file( "{$ctx['content']}/plugins/hub-vitagencia/hub.php", 'vitpress-em-uso' );
	write_file( "{$ctx['content']}/uploads/2026/old.txt", 'old' );
	write_file( "{$ctx['content']}/index.php", 'old' );
	write_file( "{$ctx['content']}/object-cache.php", 'drop-in-atual' );
	mkdir( $ctx['workspace'] );

	$db = db_for( $ctx );
	foreach ( array( 'wp_options', 'wp_posts' ) as $table ) {
		$db->query( "DROP TABLE IF EXISTS `{$table}`" );
		$db->query( "CREATE TABLE `{$table}` (\n  `marker` varchar(64) NOT NULL\n) ENGINE=InnoDB" );
		$db->query( "INSERT INTO `{$table}` VALUES ('old-db')" );
	}

	$zip = new ZipArchive();
	$zip->open( "$root/upload-restore.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'manifest.json', json_encode( array( 'generator' => 'VitPress', 'backup_schema' => 1, 'table_prefix' => 'wp_', 'sql_escaping' => 'literal', 'original_url' => 'https://destino.test' ) ) );
	$zip->addFromString( 'database.sql', backup_sql() );
	$zip->addFromString( 'wp-content/plugins/app/version.txt', 'new' );
	$zip->addFromString( 'wp-content/plugins/fresh/fresh.php', 'new' );
	$zip->addFromString( 'wp-content/plugins/hub-vitagencia/hub.php', 'versao-do-backup' );
	$zip->addFromString( 'wp-content/uploads/2026/new.txt', 'new' );
	$zip->addFromString( 'wp-content/index.php', 'new' );
	$zip->addFromString( 'wp-content/object-cache.php', 'drop-in-do-backup' );
	$zip->addFromString( 'wp-content/mu-plugins/mu.php', 'mu-do-backup' );
	$zip->close();
	$ctx['zip'] = "$root/upload-restore.zip";
	return $ctx;
}

function child( array $ctx, $action, array $extra = array() ) {
	$params = array_merge( array( 'ctx' => $ctx, 'action' => $action, 'zip' => $ctx['zip'] ), $extra );
	$file   = $ctx['root'] . '/child-' . bin2hex( random_bytes( 4 ) ) . '.json';
	file_put_contents( $file, json_encode( $params ) );
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child ' . escapeshellarg( $file );
	exec( $cmd . ' 2>&1', $out, $code );
	@unlink( $file );
	return array( 'code' => $code, 'result' => json_decode( implode( '', $out ), true ), 'raw' => implode( "\n", $out ) );
}

function versions( array $ctx ) {
	$db    = db_for( $ctx );
	$files = @file_get_contents( "{$ctx['content']}/plugins/app/version.txt" );
	$data  = $db->marker( 'wp_options' );
	return array( $files, null === $data ? null : preg_replace( '/\+post$/', '', $data ) );
}

function consistent( array $ctx, $which ) {
	return array( $which, $which . '-db' ) === versions( $ctx );
}

function leftovers( array $ctx ) {
	$found = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ctx['content'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $it as $file ) {
		if ( 0 === strpos( $file->getFilename(), '.vitpress-old-' ) ) {
			$found[] = $file->getFilename();
		}
	}
	return $found;
}

function sql_files( array $ctx ) {
	$found = array();
	if ( is_dir( $ctx['workspace'] ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ctx['workspace'], FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'sql' === strtolower( $file->getExtension() ) ) {
				$found[] = $file->getFilename();
			}
		}
	}
	return $found;
}

function temp_tables( array $ctx ) {
	return array_values( preg_grep( '/^hub(rs|old)/', db_for( $ctx )->tables() ) );
}

function runner_for( array $ctx, array $extra = array() ) {
	$op = RestoreOperation::active( $ctx['workspace'] );
	return $op ? make_runner( $op, db_for( $ctx ), $ctx, $extra ) : null;
}

function drop_ins_safe( array $ctx ) {
	return 'drop-in-atual' === @file_get_contents( "{$ctx['content']}/object-cache.php" ) && ! is_dir( "{$ctx['content']}/mu-plugins" )
		&& 'vitpress-em-uso' === @file_get_contents( "{$ctx['content']}/plugins/hub-vitagencia/hub.php" );
}

function finish_and_check( array $ctx, $label ) {
	child( $ctx, 'recovery_link' );
	$r = child( $ctx, 'switch' );
	check( ! empty( $r['result']['ok'] ) && consistent( $ctx, 'new' ) && drop_ins_safe( $ctx ), "{$label}: nova troca aplica arquivos e banco do backup juntos" );
	$r = child( $ctx, 'confirm' );
	check( ! empty( $r['result']['closed'] ) && consistent( $ctx, 'new' ) && array() === leftovers( $ctx ) && array() === temp_tables( $ctx ), "{$label}: confirmação remove cópias antigas e tabelas hubold" );
	check( null === RestoreOperation::active( $ctx['workspace'] ) && array() === sql_files( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ), "{$label}: operação encerrada, sem database.sql e sem manutenção" );
}

// ------------------------------------------------------------------ fluxo completo
$ctx = new_site( $base, 'happy' );
$r   = child( $ctx, 'start' );
check( ! empty( $r['result']['ok'] ) && ! file_exists( $ctx['zip'] ), 'início: ZIP movido para a pasta da operação' );
check( consistent( $ctx, 'old' ), 'início: site intacto' );
$r = child( $ctx, 'database' );
check( ! empty( $r['result']['ok'] ) && consistent( $ctx, 'old' ) && 3 === count( temp_tables( $ctx ) ), 'banco preparado em tabelas temporárias; site intacto' );
$stage_options = RestoreOperation::active( $ctx['workspace'] )->state()['staged']['wp_options'];
check( 'new-db+post' === db_for( $ctx )->marker( $stage_options ) && 'old-db' === db_for( $ctx )->marker( 'wp_options' ), 'ajuste de prefixo/URL feito nas tabelas temporárias, antes da troca' );
check( array() === sql_files( $ctx ), 'database.sql extraído removido logo após a preparação' );
$runner = runner_for( $ctx );
check( array( 'switch', 'discard' ) === $runner->allowed_actions(), 'após preparar: aplicar ou descartar' );
$r = child( $ctx, 'switch' );
check( empty( $r['result']['ok'] ) && false !== strpos( (string) $r['result']['error'], 'link de recuperação' ) && consistent( $ctx, 'old' ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ), 'troca recusada sem link de recuperação emitido antes; nada alterado' );
$link = child( $ctx, 'recovery_link' );
$token = (string) ( $link['result']['token'] ?? '' );
check( ! empty( $link['result']['ok'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token ), 'link de recuperação emitido antes da troca' );
$state = RestoreOperation::active( $ctx['workspace'] )->state();
check( false === strpos( json_encode( $state ), $token ) && (int) $state['recovery_expires'] > time(), 'estado guarda só o hash do token e a validade' );
$flushes_before = strlen( (string) @file_get_contents( $ctx['root'] . '/cache-flushes' ) );
$r              = child( $ctx, 'switch' );
check( ! empty( $r['result']['ok'] ) && consistent( $ctx, 'new' ) && 'new-db+post' === db_for( $ctx )->marker( 'wp_options' ), 'troca: arquivos e banco do backup ativos, já ajustados' );
check( ! isset( $r['result']['recovery_token'] ), 'troca não depende de devolver um token novo (o link já existia)' );
check( strlen( (string) @file_get_contents( $ctx['root'] . '/cache-flushes' ) ) - $flushes_before >= 2, 'troca: cache de objetos limpo antes e depois do RENAME' );
check( ! file_exists( $ctx['root'] . '/site/.maintenance' ), 'troca: manutenção removida ao terminar' );
check( drop_ins_safe( $ctx ) && 'drop-in-do-backup' === @file_get_contents( "{$ctx['workspace']}restored-" . RestoreOperation::active( $ctx['workspace'] )->id() . '/object-cache.php' ), 'drop-ins/mu-plugins do backup guardados sem ativar; VitPress em uso preservado' );
check( count( leftovers( $ctx ) ) > 0 && in_array( 'hubold' . substr( RestoreOperation::active( $ctx['workspace'] )->id(), 0, 6 ) . '_wp_options', temp_tables( $ctx ), true ), 'cópias antigas de arquivos e tabelas guardadas até a confirmação' );
check( runner_for( $ctx )->verify_recovery_token( $token ) && ! runner_for( $ctx )->verify_recovery_token( str_repeat( '0', 64 ) ) && ! runner_for( $ctx )->verify_recovery_token( strtoupper( $token ) ), 'token de recuperação válido só para o valor emitido' );
$state  = RestoreOperation::active( $ctx['workspace'] )->state();
$events = array_column( $state['history'], 'event' );
check( in_array( 'recovery:issued', $events, true ) && in_array( 'switch:begin', $events, true ) && in_array( 'switch:rename:begin', $events, true ) && in_array( 'switch:rename:done', $events, true ) && in_array( 'switch:done', $events, true ), 'estado registrado antes e depois de cada etapa' );
$r = child( $ctx, 'revert' );
check( ! empty( $r['result']['ok'] ) && consistent( $ctx, 'old' ) && array() === leftovers( $ctx ) && drop_ins_safe( $ctx ), 'reverter após a troca: arquivos e banco anteriores de volta' );
check( false === runner_for( $ctx )->verify_recovery_token( $token ), 'reverter invalida o token de recuperação (uso único)' );
$r = child( $ctx, 'switch' );
check( empty( $r['result']['ok'] ) && consistent( $ctx, 'old' ), 'depois de reverter, nova troca exige novo link' );
finish_and_check( $ctx, 'após reverter' );

// ------------------------------------------------------------------ validade do link
$ctx = new_site( $base, 'expiry' );
child( $ctx, 'start' );
child( $ctx, 'database' );
$token = (string) ( child( $ctx, 'recovery_link' )['result']['token'] ?? '' );
RestoreOperation::active( $ctx['workspace'] )->update( array( 'recovery_expires' => time() - 1 ) );
check( ! runner_for( $ctx )->verify_recovery_token( $token ), 'link vencido não é aceito' );
$r = child( $ctx, 'switch' );
check( empty( $r['result']['ok'] ) && consistent( $ctx, 'old' ), 'troca recusada com link vencido' );
$token2 = (string) ( child( $ctx, 'recovery_link' )['result']['token'] ?? '' );
check( '' !== $token2 && $token2 !== $token && runner_for( $ctx )->verify_recovery_token( $token2 ) && ! runner_for( $ctx )->verify_recovery_token( $token ), 'novo link substitui o anterior' );
finish_and_check( $ctx, 'após renovar o link' );

// ------------------------------------------------------------------ interrupção em cada ponto
$points = array(
	array( 'start', 'start:zip_moved', 1, array( 'discard' ) ),
	array( 'start', 'start:extracted', 1, array( 'discard' ) ),
	array( 'database', 'database:begin', 1, array( 'discard' ) ),
	array( 'database', 'database:staged', 1, array( 'discard' ) ),
	array( 'database', 'database:post_processed', 1, array( 'discard' ) ),
	array( 'switch', 'switch:planned', 1, array( 'revert' ) ),
	array( 'switch', 'switch:journal', 1, array( 'revert' ) ),
	array( 'switch', 'switch:journal', 4, array( 'revert' ) ),
	array( 'switch', 'switch:files_moved', 1, array( 'revert' ) ),
	array( 'switch', 'switch:before_rename', 1, array( 'revert' ) ),
	array( 'switch', 'switch:after_rename', 1, array( 'revert' ) ),
	array( 'switch', 'switch:verified', 1, array( 'revert' ) ),
	array( 'revert', 'revert:begin', 1, array( 'revert' ) ),
	array( 'revert', 'revert:database_done', 1, array( 'revert' ) ),
	array( 'confirm', 'confirm:files_finalized', 1, array( 'confirm' ) ),
	array( 'discard', 'discard:tables_dropped', 1, array( 'discard' ) ),
);
$sequence = array( 'start', 'database', 'recovery_link', 'switch' );

foreach ( $points as $i => list( $action, $crash, $n, $expected ) ) {
	$label = "interrupção em {$crash}" . ( $n > 1 ? " (#{$n})" : '' );
	$ctx   = new_site( $base, "crash{$i}" );
	$pre   = array( 'start' => array(), 'database' => array( 'start' ), 'switch' => array( 'start', 'database', 'recovery_link' ), 'revert' => $sequence, 'confirm' => $sequence, 'discard' => array( 'start', 'database' ) )[ $action ];
	foreach ( $pre as $step ) {
		child( $ctx, $step );
	}
	$r = child( $ctx, $action, array( 'crash' => $crash, 'crash_n' => $n ) );
	check( 3 === $r['code'], "{$label}: processo encerrado no meio" );
	if ( 'switch:after_rename' === $crash ) {
		check( 'new-db+post' === db_for( $ctx )->marker( 'wp_options' ), "{$label}: banco ativo já está ajustado (pós-processamento antes do RENAME)" );
	}

	$runner = runner_for( $ctx );
	check( $runner && $expected === $runner->allowed_actions(), "{$label}: só a recuperação explícita é oferecida (" . implode( ',', $runner ? $runner->allowed_actions() : array() ) . ')' );
	$snapshot = json_encode( RestoreOperation::active( $ctx['workspace'] )->state() );
	runner_for( $ctx )->allowed_actions();
	check( json_encode( RestoreOperation::active( $ctx['workspace'] )->state() ) === $snapshot, "{$label}: recarregar a tela não muda o estado nem retoma a etapa" );
	if ( in_array( $crash, array( 'switch:journal', 'switch:files_moved', 'switch:before_rename', 'switch:after_rename', 'switch:verified' ), true ) ) {
		check( file_exists( $ctx['root'] . '/site/.maintenance' ), "{$label}: site fica em manutenção enquanto arquivos e banco podem estar misturados" );
	}
	$blocked = child( $ctx, 'switch' );
	check( empty( $blocked['result']['ok'] ), "{$label}: etapas normais bloqueadas até a recuperação" );

	$recovery = $expected[0];
	$r        = child( $ctx, $recovery );
	check( ! empty( $r['result']['ok'] ), "{$label}: recuperação '{$recovery}' concluída" . ( empty( $r['result']['ok'] ) ? ' — ' . ( $r['result']['error'] ?? $r['raw'] ) : '' ) );
	check( drop_ins_safe( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ), "{$label}: drop-ins atuais preservados e manutenção removida" );

	if ( 'discard' === $recovery ) {
		check( consistent( $ctx, 'old' ) && array() === temp_tables( $ctx ) && null === RestoreOperation::active( $ctx['workspace'] ) && array() === sql_files( $ctx ), "{$label}: descartado sem alterar o site e sem deixar temporários" );
	} elseif ( 'revert' === $recovery ) {
		check( consistent( $ctx, 'old' ) && array() === leftovers( $ctx ), "{$label}: revertido para arquivos e banco anteriores, sem misturar" );
		finish_and_check( $ctx, $label );
	} else {
		check( consistent( $ctx, 'new' ) && null === RestoreOperation::active( $ctx['workspace'] ) && array() === leftovers( $ctx ) && array() === temp_tables( $ctx ), "{$label}: confirmação retomada até o fim" );
	}
}

// ------------------------------------------------------------------ falhas forçadas
$ctx = new_site( $base, 'rename-fail' );
child( $ctx, 'start' );
child( $ctx, 'database' );
child( $ctx, 'recovery_link' );
db_for( $ctx )->set_fail( 'RENAME TABLE', 0 );
$r = child( $ctx, 'switch' );
check( empty( $r['result']['ok'] ) && false !== strpos( $r['result']['error'], 'Falha ao ativar' ) && false !== strpos( $r['result']['error'], 'mantido como estava' ), 'falha no RENAME: erro claro' );
check( consistent( $ctx, 'old' ) && array() === leftovers( $ctx ) && drop_ins_safe( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ), 'falha no RENAME: arquivos revertidos, banco intacto, manutenção removida' );
check( array( 'switch', 'discard' ) === runner_for( $ctx )->allowed_actions(), 'falha no RENAME: pode tentar de novo ou descartar' );
db_for( $ctx )->clear_fail();
finish_and_check( $ctx, 'após falha no RENAME' );

foreach ( array( 'post-fail' => 'Falha no ajuste', 'post-throw' => 'Erro inesperado' ) as $flag => $message ) {
	$ctx = new_site( $base, $flag );
	child( $ctx, 'start' );
	touch( $ctx['root'] . '/' . $flag );
	$r = child( $ctx, 'database' );
	check( empty( $r['result']['ok'] ) && false !== strpos( (string) $r['result']['error'], $message ), "erro no pós-processamento ({$flag}): erro claro na preparação" );
	check( consistent( $ctx, 'old' ) && array() === leftovers( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ) && 'prepared' === RestoreOperation::active( $ctx['workspace'] )->state()['phase'], "erro no pós-processamento ({$flag}): site nunca foi alterado, preparação pode ser refeita" );
	unlink( $ctx['root'] . '/' . $flag );
	$r = child( $ctx, 'database' );
	check( ! empty( $r['result']['ok'] ) && 3 === count( temp_tables( $ctx ) ), "erro no pós-processamento ({$flag}): nova preparação sem sobras da anterior" );
	finish_and_check( $ctx, "após erro no pós-processamento ({$flag})" );
}

$ctx = new_site( $base, 'verify-fail' );
child( $ctx, 'start' );
child( $ctx, 'database' );
child( $ctx, 'recovery_link' );
touch( $ctx['root'] . '/verify-fail' );
$r = child( $ctx, 'switch' );
check( empty( $r['result']['ok'] ) && false !== strpos( (string) $r['result']['error'], 'conferência' ) && consistent( $ctx, 'old' ) && array() === leftovers( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ), 'conferência após o RENAME falha: troca desfeita, site anterior de volta' );
unlink( $ctx['root'] . '/verify-fail' );
finish_and_check( $ctx, 'após falha na conferência' );

$ctx = new_site( $base, 'revert-fail' );
child( $ctx, 'start' );
child( $ctx, 'database' );
child( $ctx, 'recovery_link' );
touch( $ctx['root'] . '/verify-fail' );
db_for( $ctx )->set_fail( 'RENAME TABLE', 1 );
$r = child( $ctx, 'switch' );
check( empty( $r['result']['ok'] ) && false !== strpos( $r['result']['error'], 'reversão automática falhou' ), 'falha na reversão: erro claro' );
$op_id = RestoreOperation::active( $ctx['workspace'] )->id();
check( array( 'revert' ) === runner_for( $ctx )->allowed_actions() && 'revert_failed' === RestoreOperation::active( $ctx['workspace'] )->state()['phase'], 'falha na reversão: estado revert_failed, só reverter é oferecido' );
check( in_array( 'hubold' . substr( $op_id, 0, 6 ) . '_wp_options', temp_tables( $ctx ), true ) && count( leftovers( $ctx ) ) > 0, 'falha na reversão: nada apagado (tabelas e arquivos antigos guardados)' );
check( file_exists( $ctx['root'] . '/site/.maintenance' ), 'falha na reversão: site continua em manutenção' );
db_for( $ctx )->clear_fail();
unlink( $ctx['root'] . '/verify-fail' );
$r = child( $ctx, 'revert' );
check( ! empty( $r['result']['ok'] ) && consistent( $ctx, 'old' ) && array() === leftovers( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ), 'falha na reversão: nova tentativa devolve o site anterior' );
finish_and_check( $ctx, 'após falha na reversão' );

$ctx = new_site( $base, 'maint-existing' );
file_put_contents( $ctx['root'] . '/site/.maintenance', '<?php $upgrading = 1; // do administrador' );
child( $ctx, 'start' );
child( $ctx, 'database' );
child( $ctx, 'recovery_link' );
child( $ctx, 'switch' );
check( false !== strpos( (string) @file_get_contents( $ctx['root'] . '/site/.maintenance' ), 'do administrador' ), '.maintenance criado por outra pessoa não é removido' );

// ------------------------------------------------------------------ ponto de entrada de recuperação (sem WordPress)
$ctx = new_site( $base, 'endpoint' );
mkdir( $ctx['content'] . '/hub-backups' );
child( $ctx, 'start' );
child( $ctx, 'database' );
$token = (string) ( child( $ctx, 'recovery_link' )['result']['token'] ?? '' );
$r     = child( $ctx, 'switch', array( 'crash' => 'switch:after_rename' ) );
$op    = RestoreOperation::active( $ctx['workspace'] );
check( 3 === $r['code'] && file_exists( $ctx['root'] . '/site/.maintenance' ) && 'new-db+post' === db_for( $ctx )->marker( 'wp_options' ), 'recuperação: PHP morto logo após o RENAME, site em manutenção' );
$db_ok = function () use ( $ctx ) {
	return db_for( $ctx );
};
$ep    = new RecoveryEndpoint( $ctx['workspace'], $ctx['content'], $db_ok );
$state = json_encode( $op->state() );
$res   = $ep->handle( array( 'method' => 'GET', 'op' => $op->id() ) );
check( 200 === $res['status'] && $res['form'] && false === strpos( $res['body'], $token ), 'recuperação: GET mostra só o formulário (token não vem na URL)' );
check( 404 === $ep->handle( array( 'method' => 'GET', 'op' => str_repeat( 'a', 16 ) ) )['status'] && 404 === $ep->handle( array( 'method' => 'GET', 'op' => '../x' ) )['status'], 'recuperação: operação desconhecida ou inválida responde 404' );
check( 409 === ( new RecoveryEndpoint( $ctx['workspace'], $base . '/outro-site/wp-content', $db_ok ) )->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'revert' ) )['status'], 'recuperação: operação de outro site é recusada' );
foreach ( array( 'inválido' => str_repeat( 'f', 64 ), 'malformado' => $token . 'x', 'vazio' => '' ) as $label => $bad ) {
	$res = $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $bad, 'action' => 'revert' ) );
	check( 403 === $res['status'] && json_encode( $op->state() ) === $state, "recuperação: token {$label} recusado (HTTP 403) sem alterar nada" );
}
$expires = $op->state()['recovery_expires'];
$op->update( array( 'recovery_expires' => time() - 1 ) );
$res = $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'revert' ) );
check( 403 === $res['status'] && 'new-db+post' === db_for( $ctx )->marker( 'wp_options' ), 'recuperação: token expirado recusado (HTTP 403) sem alterar nada' );
$op->update( array( 'recovery_expires' => $expires ) );
$res = ( new RecoveryEndpoint( $ctx['workspace'], $ctx['content'], function () { return null; } ) )->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'revert' ) );
check( 503 === $res['status'] && 'new-db+post' === db_for( $ctx )->marker( 'wp_options' ), 'recuperação: banco indisponível responde 503 sem alterar nada' );
$res = $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'status' ) );
check( 200 === $res['status'] && false !== strpos( $res['body'], 'interrompida' ) && false !== strpos( $res['body'], 'value="revert"' ) && false === strpos( $res['body'], 'value="confirm"' ), 'recuperação: troca interrompida oferece só reverter' );
$res = $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'confirm' ) );
check( 200 === $res['status'] && 'new-db+post' === db_for( $ctx )->marker( 'wp_options' ) && null !== $op->interrupted_step(), 'recuperação: ação não permitida não é executada' );
$res = $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'revert' ) );
check( 200 === $res['status'] && false !== strpos( $res['body'], 'revertida' ) && consistent( $ctx, 'old' ) && array() === leftovers( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ) && drop_ins_safe( $ctx ), 'recuperação: reverter sem login devolve arquivos e banco anteriores e tira a manutenção' );
check( is_file( $ctx['content'] . '/hub-backups/.flush-cache' ), 'recuperação: limpeza do cache de objetos marcada para a próxima requisição' );
$res = $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'revert' ) );
check( 403 === $res['status'], 'recuperação: token reutilizado recusado (HTTP 403)' );
$token = (string) ( child( $ctx, 'recovery_link' )['result']['token'] ?? '' );
child( $ctx, 'switch' );
$res = $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'confirm' ) );
check( 200 === $res['status'] && false !== strpos( $res['body'], 'confirmada' ) && consistent( $ctx, 'new' ) && null === RestoreOperation::active( $ctx['workspace'] ) && array() === leftovers( $ctx ) && array() === temp_tables( $ctx ), 'recuperação: retomada e confirmação sem login encerram a operação sem sobras' );
check( 404 === $ep->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'confirm' ) )['status'], 'recuperação: link de operação encerrada não vale mais' );
$marker = json_decode( (string) @file_get_contents( $ctx['content'] . RecoveryEndpoint::FLUSH_MARKER ), true );
check( is_array( $marker ) && false === $marker['persistent'], 'recuperação: marcador registra que o site não usava cache persistente na troca' );

// ------------------------------------------------------------------ cache de objetos persistente no ponto de entrada
$ctx = new_site( $base, 'endpoint-cache' );
mkdir( $ctx['content'] . '/hub-backups' );
child( $ctx, 'start' );
child( $ctx, 'database' );
$token = (string) ( child( $ctx, 'recovery_link' )['result']['token'] ?? '' );
$r     = child( $ctx, 'switch', array( 'options' => array( 'object_cache' => true ) ) );
$op    = RestoreOperation::active( $ctx['workspace'] );
check( ! empty( $r['result']['ok'] ) && true === $op->state()['object_cache'], 'cache: a troca registra que o site usava cache de objetos persistente' );
$db_ok  = function () use ( $ctx ) {
	return db_for( $ctx );
};
$cached = new RecoveryEndpoint( $ctx['workspace'], $ctx['content'], $db_ok, array( 'active' => true, 'hints' => array( 'WP_REDIS_HOST' ) ) );
$state  = json_encode( $op->state() );
$res    = $cached->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'status' ) );
check( 200 === $res['status'] && false === strpos( $res['body'], 'value="revert"' ) && false === strpos( $res['body'], 'value="confirm"' ) && false !== strpos( $res['body'], 'object-cache.php.desativado' ), 'cache ativo: status sem botões e com orientação para desativar o cache' );
foreach ( array( 'revert', 'confirm' ) as $action ) {
	$res = $cached->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => $action ) );
	check( 409 === $res['status'] && json_encode( $op->state() ) === $state && consistent( $ctx, 'new' ) && ! is_file( $ctx['content'] . RecoveryEndpoint::FLUSH_MARKER ), "cache ativo: {$action} recusado (HTTP 409) sem alterar nada nem marcar limpeza" );
}
$plain = new RecoveryEndpoint( $ctx['workspace'], $ctx['content'], $db_ok, array( 'active' => false, 'hints' => array( 'WP_REDIS_HOST' ) ) );
$res   = $plain->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'status' ) );
check( false !== strpos( $res['body'], 'value="revert"' ) && false !== strpos( $res['body'], 'WP_REDIS_HOST' ), 'cache desativado: ações liberadas, configuração de Redis informada' );
$res    = $plain->handle( array( 'method' => 'POST', 'op' => $op->id(), 'token' => $token, 'action' => 'revert' ) );
$marker = json_decode( (string) @file_get_contents( $ctx['content'] . RecoveryEndpoint::FLUSH_MARKER ), true );
check( 200 === $res['status'] && consistent( $ctx, 'old' ) && is_array( $marker ) && true === $marker['persistent'] && $op->id() === $marker['op'], 'cache desativado: reverter funciona e o marcador exige limpeza com o cache persistente de volta' );
RecoveryEndpoint::write_flush_marker( $ctx['content'], '', false );
$marker = json_decode( (string) @file_get_contents( $ctx['content'] . RecoveryEndpoint::FLUSH_MARKER ), true );
check( true === $marker['persistent'], 'cache: novo marcador não rebaixa um marcador de cache persistente pendente' );

// ------------------------------------------------------------------ primeira requisição do WordPress após a recuperação
$cache_child = function ( $scenario, $marker ) use ( $base ) {
	$root = "$base/cache-" . bin2hex( random_bytes( 4 ) );
	mkdir( "$root/site/wp-content/hub-backups", 0777, true );
	if ( null !== $marker ) {
		file_put_contents( "$root/site/wp-content" . RecoveryEndpoint::FLUSH_MARKER, $marker );
	}
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --cache-child ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1', $out );
	return json_decode( implode( '', $out ), true ) ?: array( 'marker' => null, 'notice' => implode( "\n", $out ) );
};
$r = $cache_child( 'none', json_encode( array( 'op' => 'x', 'at' => time(), 'persistent' => false ) ) );
check( false === $r['marker'] && '' === $r['notice'], 'cache: sem cache persistente, limpeza conferida apaga o marcador' );
$r = $cache_child( 'none', '1700000000' );
check( false === $r['marker'], 'cache: marcador do formato anterior é tratado como não persistente' );
$r = $cache_child( 'none', json_encode( array( 'op' => 'x', 'at' => time(), 'persistent' => true ) ) );
check( true === $r['marker'] && false !== strpos( $r['notice'], 'desativado' ), 'cache: troca com cache persistente e requisição sem ele: marcador mantido e aviso no painel' );
$r = $cache_child( 'ok', json_encode( array( 'op' => 'x', 'at' => time(), 'persistent' => true ) ) );
check( false === $r['marker'] && '' === $r['notice'], 'cache: com o cache persistente de volta, limpeza conferida apaga o marcador' );
$r = $cache_child( 'broken', json_encode( array( 'op' => 'x', 'at' => time(), 'persistent' => true ) ) );
check( true === $r['marker'] && false !== strpos( $r['notice'], 'não pôde ser limpo' ), 'cache: flush que não limpa de verdade mantém o marcador e avisa' );
$r = $cache_child( 'ok', null );
check( false === $r['marker'] && '' === $r['notice'], 'cache: sem marcador, nada a fazer' );

// ------------------------------------------------------------------ verificação prévia do ponto de recuperação
$standard = "<?php\ndefine( 'DB_NAME', 'ficticio' );\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
check( array() === RecoveryPreflight::analyze_config( $standard ), 'wp-config padrão é compatível' );
check( array() === RecoveryPreflight::analyze_config( "<?php require_once( ABSPATH . \"wp-settings.php\" );" ), 'wp-config com parênteses e aspas duplas é compatível' );
check( array() === RecoveryPreflight::analyze_config( "<?php require __DIR__ . '/../config/app.php';\n// require_once __DIR__ . '/wp-settings.php';\nrequire_once ABSPATH . 'wp-settings.php';" ), 'inclusão sem ABSPATH e comentários não contam (a sondagem confere o resto)' );
$incompatible = array(
	'caminho fixo com __DIR__'       => "<?php require_once __DIR__ . '/wp-settings.php';",
	'caminho absoluto'               => "<?php require_once( '/var/www/html/wp-settings.php' );",
	'wp-load.php'                    => "<?php require_once dirname( __FILE__ ) . '/wp-load.php';",
	'wp-blog-header.php'             => "<?php include 'wp-blog-header.php';",
	'inclusão relativa a ABSPATH'    => "<?php include ABSPATH . 'local-config.php';\nrequire_once ABSPATH . 'wp-settings.php';",
	'ABSPATH entre parênteses extra' => "<?php require_once ( ABSPATH . 'wp-settings.php' ) . '';",
	'eval'                           => "<?php eval( 'return 1;' );",
);
foreach ( $incompatible as $label => $code ) {
	check( array() !== RecoveryPreflight::analyze_config( $code ), "wp-config incompatível detectado: {$label}" );
}

$lc = "$base/locate";
write_file( "$lc/a/wp-config.php", '<?php' );
mkdir( "$lc/a/wp-content/plugins/hub-vitagencia", 0777, true );
check( RecoveryPreflight::same_path( RecoveryPreflight::locate_config( "$lc/a/wp-content/plugins/hub-vitagencia" ), RecoveryPreflight::wordpress_config( "$lc/a/" ) ), 'wp-config na raiz: ponto de entrada e WordPress usam o mesmo arquivo' );
write_file( "$lc/b/wp-config.php", '<?php' );
mkdir( "$lc/b/public/wp-content/plugins/hub-vitagencia", 0777, true );
check( RecoveryPreflight::same_path( RecoveryPreflight::locate_config( "$lc/b/public/wp-content/plugins/hub-vitagencia" ), RecoveryPreflight::wordpress_config( "$lc/b/public/" ) ), 'wp-config um nível acima: mesmo arquivo nos dois lados' );
write_file( "$lc/c/wp-config.php", '<?php' );
write_file( "$lc/c/wp-settings.php", '<?php' );
mkdir( "$lc/c/inner/wp-content/plugins/hub-vitagencia", 0777, true );
check( null === RecoveryPreflight::wordpress_config( "$lc/c/inner/" ) && ! RecoveryPreflight::same_path( RecoveryPreflight::locate_config( "$lc/c/inner/wp-content/plugins/hub-vitagencia" ), RecoveryPreflight::wordpress_config( "$lc/c/inner/" ) ), 'wp-config de outra instalação acima: divergência detectada' );
check( ! RecoveryPreflight::same_path( null, null ) && ! RecoveryPreflight::same_path( '', '' ), 'caminhos vazios nunca são considerados iguais' );

$oc = "$base/object-cache";
mkdir( $oc );
check( false === RecoveryPreflight::object_cache( $oc )['active'], 'sem object-cache.php: cache persistente inativo' );
write_file( "$oc/object-cache.php", '<?php' );
define( 'WP_REDIS_HOST', '127.0.0.1' );
$info = RecoveryPreflight::object_cache( $oc );
check( true === $info['active'] && in_array( 'WP_REDIS_HOST', $info['hints'], true ), 'object-cache.php e WP_REDIS_HOST detectados' );

$pw     = "$base/probe";
$called = 0;
mkdir( $pw );
$facts = function () use ( &$called ) {
	$called++;
	return array( 'wordpress_loaded' => false, 'database' => true );
};
$p    = RecoveryPreflight::create_probe( $pw );
$file = "$pw/" . RecoveryPreflight::PROBE_FILE;
check( is_array( $p ) && false === strpos( (string) file_get_contents( $file ), $p['challenge'] ), 'sondagem: arquivo guarda só o hash do desafio' );
check( 404 === RecoveryPreflight::respond_probe( $pw, str_repeat( '0', 64 ), $facts )['status'] && 404 === RecoveryPreflight::respond_probe( $pw, '../x', $facts )['status'] && 0 === $called, 'sondagem: desafio errado ou malformado responde 404 sem levantar os fatos' );
$res = RecoveryPreflight::respond_probe( $pw, $p['challenge'], $facts );
$got = RecoveryPreflight::verify_probe( $p['key'], $p['challenge'], $res['body'], $res['signature'] );
check( 200 === $res['status'] && 1 === $called && is_array( $got ) && true === $got['database'] && false === $got['wordpress_loaded'], 'sondagem: resposta assinada e conferida' );
check( null === RecoveryPreflight::verify_probe( $p['key'], $p['challenge'], str_replace( 'false', 'true', $res['body'] ), $res['signature'] ), 'sondagem: resposta alterada não confere' );
check( null === RecoveryPreflight::verify_probe( str_repeat( '1', 64 ), $p['challenge'], $res['body'], $res['signature'] ) && null === RecoveryPreflight::verify_probe( $p['key'], $p['challenge'], $res['body'], '' ), 'sondagem: chave errada ou assinatura ausente não confere' );
check( 404 === RecoveryPreflight::respond_probe( $pw, $p['challenge'], $facts )['status'] && ! is_file( $file ), 'sondagem: desafio de uso único' );
$p    = RecoveryPreflight::create_probe( $pw );
$data = json_decode( (string) file_get_contents( $file ), true );
file_put_contents( $file, json_encode( array( 'expires' => time() - 1 ) + $data ) );
check( 404 === RecoveryPreflight::respond_probe( $pw, $p['challenge'], $facts )['status'], 'sondagem: desafio vencido responde 404' );
RecoveryPreflight::discard_probe( $pw );
check( 404 === RecoveryPreflight::respond_probe( $pw, $p['challenge'], $facts )['status'] && 1 === $called, 'sondagem: sem desafio gravado responde 404' );
check( false === RecoveryPreflight::wordpress_loaded(), 'teste: WordPress não carregado neste processo' );

// ------------------------------------------------------------------ verificação prévia recusada na troca
$ctx = new_site( $base, 'preflight' );
child( $ctx, 'start' );
child( $ctx, 'database' );
child( $ctx, 'recovery_link' );
touch( $ctx['root'] . '/preflight-fail' );
$r  = child( $ctx, 'switch' );
$op = RestoreOperation::active( $ctx['workspace'] );
check( empty( $r['result']['ok'] ) && false !== strpos( (string) $r['result']['error'], 'verificação simulada' ) && false !== strpos( (string) $r['result']['error'], 'não foi alterado' ), 'verificação prévia recusada: troca bloqueada com mensagem clara' );
check( consistent( $ctx, 'old' ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ) && array() === leftovers( $ctx ) && 'staged' === $op->state()['phase'] && null === $op->interrupted_step(), 'verificação prévia recusada: site, banco e operação intactos' );
unlink( $ctx['root'] . '/preflight-fail' );
finish_and_check( $ctx, 'após liberar a verificação prévia' );

// ------------------------------------------------------------------ backup antigo (só arquivos): uploads mesclados
$ctx = new_site( $base, 'legacy' );
write_file( "{$ctx['content']}/uploads/2026/09/destino.txt", 'so-no-destino' );
$zip = new ZipArchive();
$zip->open( $ctx['root'] . '/legacy.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'plugins/app/version.txt', 'new' );
$zip->addFromString( 'uploads/2026/old.txt', 'substituido' );
$zip->addFromString( 'uploads/2026/08/legado.txt', 'legado' );
$zip->close();
$ctx['zip'] = $ctx['root'] . '/legacy.zip';
$r          = child( $ctx, 'start' );
$op         = RestoreOperation::active( $ctx['workspace'] );
check( ! empty( $r['result']['ok'] ) && $op && ! empty( $op->state()['files_only'] ), 'backup antigo: aceito como restauração só de arquivos' );
child( $ctx, 'recovery_link' );
$r       = child( $ctx, 'switch' );
$uploads = $ctx['content'] . '/uploads/2026';
check( ! empty( $r['result']['ok'] ) && 'new' === @file_get_contents( "{$ctx['content']}/plugins/app/version.txt" ) && 'old-db' === db_for( $ctx )->marker( 'wp_options' ), 'backup antigo: arquivos trocados, banco intocado' );
check( 'so-no-destino' === @file_get_contents( "$uploads/09/destino.txt" ) && 'substituido' === @file_get_contents( "$uploads/old.txt" ) && 'legado' === @file_get_contents( "$uploads/08/legado.txt" ), 'backup antigo: uploads mesclados (arquivo só do destino preservado, os do backup aplicados)' );
$r = child( $ctx, 'revert' );
check( ! empty( $r['result']['ok'] ) && 'old' === @file_get_contents( "$uploads/old.txt" ) && 'so-no-destino' === @file_get_contents( "$uploads/09/destino.txt" ) && ! file_exists( "$uploads/08" ) && array() === leftovers( $ctx ), 'backup antigo: reverter devolve exatamente os uploads anteriores' );
child( $ctx, 'recovery_link' );
child( $ctx, 'switch' );
$r = child( $ctx, 'confirm' );
check( ! empty( $r['result']['closed'] ) && 'so-no-destino' === @file_get_contents( "$uploads/09/destino.txt" ) && 'legado' === @file_get_contents( "$uploads/08/legado.txt" ) && array() === leftovers( $ctx ), 'backup antigo: confirmar mantém o arquivo só do destino e não deixa sobras' );

// ------------------------------------------------------------------ concorrência
$ctx   = new_site( $base, 'concurrent' );
child( $ctx, 'start' );
$ready = $ctx['root'] . '/ready';
$file  = $ctx['root'] . '/sleeper.json';
file_put_contents( $file, json_encode( array( 'ctx' => $ctx, 'action' => 'database', 'zip' => '', 'sleep_at' => 'database:begin', 'sleep' => 4, 'ready' => $ready ) ) );
$proc = proc_open( array( PHP_BINARY, __FILE__, '--child', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
for ( $w = 0; $w < 100 && ! file_exists( $ready ); $w++ ) {
	usleep( 100000 );
}
check( file_exists( $ready ), 'concorrência: primeira etapa em execução' );
copy( new_site( $base, 'concurrent-zip' )['zip'], $ctx['root'] . '/other.zip' );
$other = RestoreRunner::start( $ctx['workspace'], $ctx['root'] . '/other.zip', BackupArchiveValidator::validate( $ctx['root'] . '/other.zip' ), array() );
check( ! $other['ok'] && false !== strpos( $other['error'], 'Já existe' ) && file_exists( $ctx['root'] . '/other.zip' ), 'concorrência: segunda restauração recusada sem tocar no arquivo dela' );
$busy = runner_for( $ctx )->run( 'discard' );
check( ! $busy['ok'] && false !== strpos( $busy['error'], 'em execução' ), 'concorrência: outra aba não descarta a etapa em execução' );
$busy = runner_for( $ctx )->run( 'database' );
check( ! $busy['ok'] && false !== strpos( $busy['error'], 'em execução' ), 'concorrência: mesma etapa em paralelo bloqueada' );
check( array() === runner_for( $ctx )->allowed_actions() && null === RestoreOperation::active( $ctx['workspace'] )->interrupted_step(), 'concorrência/recarga: etapa viva não é tratada como interrompida' );
$out = stream_get_contents( $pipes[1] );
proc_close( $proc );
$res = json_decode( $out, true );
check( ! empty( $res['ok'] ) && 'staged' === RestoreOperation::active( $ctx['workspace'] )->state()['phase'], 'concorrência: etapa original termina normalmente' );

$ctx   = new_site( $base, 'race' );
copy( $ctx['zip'], $ctx['root'] . '/second.zip' );
$procs = array();
foreach ( array( $ctx['zip'], $ctx['root'] . '/second.zip' ) as $k => $zip ) {
	$file = $ctx['root'] . "/race{$k}.json";
	file_put_contents( $file, json_encode( array( 'ctx' => $ctx, 'action' => 'start', 'zip' => $zip ) ) );
	$procs[] = array( proc_open( array( PHP_BINARY, __FILE__, '--child', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $p ), $p );
}
$oks = 0;
foreach ( $procs as list( $proc, $p ) ) {
	$res = json_decode( stream_get_contents( $p[1] ), true );
	proc_close( $proc );
	$oks += ! empty( $res['ok'] ) ? 1 : 0;
}
check( 1 === $oks && 1 === count( glob( $ctx['workspace'] . 'op-*', GLOB_ONLYDIR ) ), 'duas restaurações iniciadas ao mesmo tempo: só uma é aceita' );

// ------------------------------------------------------------------ limpeza automática
$clean_root = "$base/cleanup";
mkdir( "$clean_root/site/wp-content", 0777, true );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --cleanup-child ' . escapeshellarg( $clean_root ) . ' 2>&1', $cout, $ccode );
$c = json_decode( implode( '', $cout ), true );
check( is_array( $c ) && $c['op_state'] && $c['op_sql'] && $c['op_journal'] && $c['lock'], 'limpeza automática preserva a operação ativa (estado, diário, extração e lock), mesmo com mais de 1 hora' );
check( is_array( $c ) && ! $c['upload_old'] && $c['upload_new'] && ! $c['tmp_old'], 'limpeza remove uploads e temporários abandonados; mantém os recentes' );
check( is_array( $c ) && ! $c['legacy_extract'] && ! $c['legacy_state'] && ! $c['legacy_zip'], 'limpeza remove extração, estado e ZIP antigos da pasta pública anterior' );
check( is_array( $c ) && false !== strpos( $c['legacy_htaccess'], 'Require all denied' ) && false !== strpos( $c['legacy_htaccess'], 'Deny from all' ), 'pasta antiga recebe proteção Apache 2.4 e 2.2' );
check( is_array( $c ) && $c['ws_in_content'] && $c['ws_random'], 'sem DOCUMENT_ROOT conhecido: pasta de trabalho em hub-backups com nome imprevisível' );

// ------------------------------------------------------------------ escolha da pasta de trabalho
$wroot = "$base/ws";
mkdir( "$wroot/public/wp-content", 0777, true );
$outside = ( new BackupWorkspace( "$wroot/public/wp-content", $wroot, "$wroot/public" ) )->path();
check( 0 !== strpos( $outside, "$wroot/public/" ) && 0 === strpos( $outside, "$wroot/.vitpress-work-" ), 'com DOCUMENT_ROOT conhecido: pasta de trabalho fora da raiz web' );
check( ( new BackupWorkspace( "$wroot/public/wp-content", $wroot, "$wroot/public" ) )->path() === $outside, 'pasta de trabalho reutilizada pelo ponteiro' );
check( 0 === strpos( (string) file_get_contents( "$wroot/public/wp-content/hub-backups/workspace.php" ), '<?php exit; ?>' ), 'ponteiro protegido por <?php exit; ?>' );
mkdir( "$wroot/b/public/wp-content", 0777, true );
$inside = ( new BackupWorkspace( "$wroot/b/public/wp-content", "$wroot/b/public", "$wroot/b/public" ) )->path();
check( 0 === strpos( $inside, "$wroot/b/public/wp-content/hub-backups/vitpress-work-" ), 'candidato dentro da raiz web é recusado (usa hub-backups protegido)' );

// ------------------------------------------------------------------ HTTP (Apache local e servidor embutido)
function http_get( $url ) {
	$ctx  = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'timeout' => 10 ) ) );
	$body = @file_get_contents( $url, false, $ctx );
	$code = 0;
	foreach ( $http_response_header ?? array() as $h ) {
		if ( preg_match( '#^HTTP/\S+ (\d{3})#', $h, $m ) ) {
			$code = (int) $m[1];
		}
	}
	return array( $code, (string) $body );
}

if ( getenv( 'VITPRESS_HTTP_TEST' ) ) {
	$www = 'D:/LocalHost/www';
	$dir = 'vitpress-tmp-http-' . bin2hex( random_bytes( 6 ) );
	$web = "$www/$dir";
	try {
		mkdir( "$web/wp-content", 0777, true );
		$ws = ( new BackupWorkspace( "$web/wp-content" ) )->path();
		$op = RestoreOperation::create( $ws, array( 'user' => 1 ) );
		$op->update( array( 'phase' => 'staged' ) );
		$op->journal_append( array( 'a' => 'move' ) );
		mkdir( $op->extract_dir() );
		file_put_contents( $op->extract_dir() . 'database.sql', "INSERT INTO `x` VALUES ('ficticio');" );
		file_put_contents( $op->dir() . 'backup.zip', 'PK-ficticio' );
		mkdir( $ws . 'uploads' );
		BackupWorkspace::protect( rtrim( $ws . 'uploads', '/' ) );
		file_put_contents( $ws . 'uploads/upload-restore-' . str_repeat( 'c', 32 ) . '.zip', 'PK-ficticio' );
		mkdir( "$web/wp-content/hub-backups/temp", 0777, true );
		BackupWorkspace::protect( "$web/wp-content/hub-backups/temp" );
		file_put_contents( "$web/wp-content/hub-backups/temp/restore-state.json", '{}' );
		file_put_contents( "$web/canary.txt", 'visivel' );

		$rel = function ( $path ) use ( $www ) {
			return substr( str_replace( '\\', '/', $path ), strlen( $www ) );
		};
		$targets = array(
			'estado'           => $op->dir() . 'state.json',
			'diário'           => $op->dir() . 'journal.jsonl',
			'SQL extraído'     => $op->extract_dir() . 'database.sql',
			'ZIP da operação'  => $op->dir() . 'backup.zip',
			'ZIP enviado'      => $ws . 'uploads/upload-restore-' . str_repeat( 'c', 32 ) . '.zip',
			'lock'             => $ws . 'restore.lock',
			'listagem da pasta de trabalho' => $ws,
			'listagem da extração'          => $op->extract_dir(),
			'pasta antiga temp'             => "$web/wp-content/hub-backups/temp/restore-state.json",
		);
		list( $canary_code, $canary_body ) = http_get( "http://localhost/$dir/canary.txt" );
		if ( 200 !== $canary_code ) {
			check( false, "Apache local acessível em http://localhost/ (HTTP {$canary_code})" );
		} else {
			foreach ( $targets as $label => $path ) {
				list( $code, $body ) = http_get( 'http://localhost' . $rel( $path ) );
				check( 403 === $code && false === strpos( $body, 'ficticio' ), "Apache 2.4: {$label} bloqueado (HTTP {$code})" );
			}
			list( $code, $body ) = http_get( 'http://localhost' . $rel( "$web/wp-content/hub-backups/workspace.php" ) );
			check( in_array( $code, array( 200, 403 ), true ) && false === strpos( $body, 'vitpress-work' ), "Apache 2.4: ponteiro não revela a pasta (HTTP {$code})" );
		}

		$port   = 18000 + random_int( 0, 999 );
		$server = proc_open( array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $web ), array( 1 => array( 'file', 'NUL', 'w' ), 2 => array( 'file', 'NUL', 'w' ) ), $sp );
		for ( $w = 0; $w < 50; $w++ ) {
			if ( 200 === http_get( "http://127.0.0.1:{$port}/canary.txt" )[0] ) {
				break;
			}
			usleep( 100000 );
		}
		$builtin = function ( $path ) use ( $web, $port ) {
			return http_get( "http://127.0.0.1:{$port}" . substr( str_replace( '\\', '/', $path ), strlen( $web ) ) );
		};
		check( 404 === $builtin( "$web/wp-content/hub-backups/temp/restore-extract/database.sql" )[0] && 404 === $builtin( "$web/wp-content/hub-backups/temp/upload-restore-1700000000.zip" )[0], 'sem .htaccess (como Nginx): caminhos previsíveis antigos não existem mais' );
		list( $code, $body ) = $builtin( "$web/wp-content/hub-backups/workspace.php" );
		check( 200 === $code && '' === trim( $body ), 'sem .htaccess: ponteiro executa <?php exit; ?> e não revela a pasta' );
		list( $code ) = $builtin( $op->extract_dir() . 'database.sql' );
		echo 'info sem .htaccess (como Nginx sem regra própria): SQL extraído com o nome aleatório conhecido responde HTTP ' . $code . " — só o nome imprevisível protege; fora da raiz web isso não se aplica\n";
		proc_terminate( $server );
		proc_close( $server );
	} finally {
		BackupFileMover::delete_path( $web );
		check( ! file_exists( $web ), 'HTTP: pasta temporária em D:/LocalHost/www removida' );
	}
} else {
	echo "ignorado: HTTP (defina VITPRESS_HTTP_TEST=1)\n";
}

// ------------------------------------------------------------------ MySQL descartável (opcional)
$tmp_db = getenv( 'VITPRESS_TMP_DB' );
if ( $tmp_db ) {
	if ( ! preg_match( '/^vitpress_tmp_[a-z0-9_]+$/', $tmp_db ) ) {
		check( false, 'VITPRESS_TMP_DB precisa começar com vitpress_tmp_' );
	} else {
		mysqli_report( MYSQLI_REPORT_OFF );
		$admin = new mysqli( '127.0.0.1', getenv( 'VITPRESS_TMP_DB_USER' ) ?: 'root', getenv( 'VITPRESS_TMP_DB_PASS' ) ?: '' );
		$admin->query( "DROP DATABASE IF EXISTS `{$tmp_db}`" );
		$admin->query( "CREATE DATABASE `{$tmp_db}` CHARACTER SET utf8mb4" );
		try {
			$ctx = new_site( $base, 'mysql-happy', $tmp_db );
			child( $ctx, 'start' );
			child( $ctx, 'database' );
			$token = (string) ( child( $ctx, 'recovery_link' )['result']['token'] ?? '' );
			$r     = child( $ctx, 'switch', array( 'crash' => 'switch:after_rename' ) );
			check( 3 === $r['code'] && 'new-db+post' === db_for( $ctx )->marker( 'wp_options' ), 'MySQL: interrompido logo após o RENAME (banco do backup ativo e já ajustado)' );
			check( array( 'revert' ) === runner_for( $ctx )->allowed_actions(), 'MySQL: só reverter é oferecido' );
			$id  = RestoreOperation::active( $ctx['workspace'] )->id();
			$res = ( new RecoveryEndpoint( $ctx['workspace'], $ctx['content'], function () use ( $ctx ) { return db_for( $ctx ); } ) )->handle( array( 'method' => 'POST', 'op' => $id, 'token' => $token, 'action' => 'revert' ) );
			check( 200 === $res['status'] && consistent( $ctx, 'old' ) && array() === leftovers( $ctx ), 'MySQL: reversão pelo ponto de entrada (sem login) devolve arquivos e banco anteriores' );
			finish_and_check( $ctx, 'MySQL' );

			$ctx = new_site( $base, 'mysql-lock', $tmp_db );
			child( $ctx, 'start' );
			child( $ctx, 'database' );
			child( $ctx, 'recovery_link' );
			$locker = new mysqli( '127.0.0.1', getenv( 'VITPRESS_TMP_DB_USER' ) ?: 'root', getenv( 'VITPRESS_TMP_DB_PASS' ) ?: '', $tmp_db );
			$locker->query( 'LOCK TABLES `wp_options` READ' );
			$t0 = microtime( true );
			$r  = child( $ctx, 'switch' );
			$dt = microtime( true ) - $t0;
			$locker->query( 'UNLOCK TABLES' );
			$locker->close();
			check( empty( $r['result']['ok'] ) && false !== stripos( $r['result']['error'], 'lock wait timeout' ) && $dt < 45, sprintf( 'MySQL: RENAME bloqueado por lock desiste em %.0fs (limite de 30s), em vez de esperar indefinidamente', $dt ) );
			check( consistent( $ctx, 'old' ) && array() === leftovers( $ctx ) && ! file_exists( $ctx['root'] . '/site/.maintenance' ), 'MySQL: após o timeout, arquivos revertidos e banco intacto' );
			finish_and_check( $ctx, 'MySQL após timeout de lock' );
		} finally {
			$admin->query( "DROP DATABASE IF EXISTS `{$tmp_db}`" );
			check( 0 === $admin->query( "SHOW DATABASES LIKE '{$tmp_db}'" )->num_rows, "MySQL: banco descartável {$tmp_db} removido" );
			$admin->close();
		}
	}
} else {
	echo "ignorado: MySQL (defina VITPRESS_TMP_DB=vitpress_tmp_...)\n";
}

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: recuperação da restauração ok\n";
