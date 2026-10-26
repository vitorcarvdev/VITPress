<?php
/**
 * Backup e Migração: escrita/leitura do SQL, gramática estrita do importador, validação do ZIP,
 * movimentação de arquivos com diário, importação com tabelas temporárias e tamanho dos blocos de upload.
 *
 * Isolado: não carrega o WordPress e só escreve em uma pasta temporária do sistema, removida no fim.
 * php tests/test-backup-restore.php
 *
 * Ida e volta em MySQL real (opcional, banco descartável criado e removido pelo teste):
 * VITPRESS_TMP_DB=vitpress_tmp_backup php tests/test-backup-restore.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HUB_PATH', dirname( __DIR__ ) . '/' );

require HUB_PATH . 'core/Services/Backup/BackupSql.php';
require HUB_PATH . 'core/Services/Backup/BackupArchiveValidator.php';
require HUB_PATH . 'core/Services/Backup/BackupFileMover.php';
require HUB_PATH . 'core/Services/Backup/BackupDatabaseImporter.php';
require HUB_PATH . 'core/Services/BackupService.php';

use Hub\Core\Services\Backup\BackupArchiveValidator as Validator;
use Hub\Core\Services\Backup\BackupDatabaseImporter;
use Hub\Core\Services\Backup\BackupFileMover;
use Hub\Core\Services\Backup\BackupSql;
use Hub\Core\Services\BackupService;

$failed = 0;
function check( $condition, $message ) {
	global $failed;
	echo ( $condition ? 'ok ' : 'FALHA ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failed++;
	}
}

$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vitpress-backup-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $work );
register_shutdown_function(
	function () use ( $work ) {
		BackupFileMover::delete_path( $work );
	}
);

function make_zip( $path, array $entries, callable $tweak = null ) {
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $entries as $name => $content ) {
		if ( substr( $name, -1 ) === '/' ) {
			$zip->addEmptyDir( rtrim( $name, '/' ) );
		} else {
			$zip->addFromString( $name, $content );
		}
	}
	if ( $tweak ) {
		$tweak( $zip );
	}
	$zip->close();
	return $path;
}

function manifest( array $over = array() ) {
	return json_encode( array_merge( array( 'generator' => 'VitPress', 'backup_schema' => 1, 'table_prefix' => 'wp_', 'sql_escaping' => 'literal' ), $over ) );
}

function write_file( $path, $content ) {
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $content );
}

// ---------------------------------------------------------------- SQL
$tricky = array(
	'percent'    => '100% de 50%% e %s %d %1$s',
	'quotes'     => "aspas 'simples' e \"duplas\"",
	'backslash'  => 'C:\\pasta\\arquivo \\n literal',
	'newlines'   => "linha1\nlinha2\r\nlinha3",
	'utf8'       => 'ação, coração, 日本語, emoji 🚀',
	'nul'        => "antes\0depois",
	'ctrlz'      => "x\x1ay",
	'serialized' => serialize( array( 'url' => 'https://exemplo.test/?a=1%202', 'pct' => '50%', 'txt' => "a'b\"c\\d" ) ),
	'starts'     => "\nDROP TABLE wp_users; -- dentro do dado",
);

$quoted = BackupSql::quote( $tricky['percent'] );
check( "'100% de 50%% e %s %d %1\$s'" === $quoted, 'quote preserva % literalmente' );
check( 0 === preg_match( BackupSql::LEGACY_PLACEHOLDER_PATTERN, implode( '', array_map( array( BackupSql::class, 'quote' ), $tricky ) ) ), 'nenhum marcador {…} de esc_sql no dump' );
check( 'NULL' === BackupSql::quote( null ) && "''" === BackupSql::quote( '' ) && "'0'" === BackupSql::quote( 0 ), 'NULL, vazio e zero distintos' );
check( "'linha1\\nlinha2\\r\\nlinha3'" === BackupSql::quote( $tricky['newlines'] ), 'quebras de linha escapadas (INSERT fica em uma linha)' );
check( "'C:\\\\pasta\\\\arquivo \\\\n literal'" === BackupSql::quote( $tricky['backslash'] ), 'barras invertidas escapadas' );
foreach ( $tricky as $label => $value ) {
	$insert = BackupSql::insert_statement( 'wp_x', array( 1, $value, null ) );
	check( 'INSERT INTO' === ( BackupSql::classify( $insert )['type'] ?? '' ), "INSERT gerado é aceito pela gramática estrita ({$label})" );
}

$dump = "-- Hub Database Backup (Raw SQL)\n-- sql_escaping: literal\n\n"
	. "DROP TABLE IF EXISTS `wp_items`;\nCREATE TABLE `wp_items` (\n  `id` int NOT NULL,\n  `v` longtext,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='a ''b'' c';\n\n"
	. BackupSql::insert_statement( 'wp_items', array( 1, $tricky['starts'] ) ) . "\n"
	. BackupSql::insert_statement( 'wp_items', array( 2, 'texto com `wp_items` e wp_options' ) ) . "\n\n"
	. "CREATE TABLE `wp_child` (\n  `id` int DEFAULT NULL,\n  KEY `id` (`id`)\n);\n";
$dump_path = $work . '/dump.sql';
file_put_contents( $dump_path, $dump );
$h          = fopen( $dump_path, 'r' );
$statements = iterator_to_array( BackupSql::statements( $h ), false );
fclose( $h );
check( 5 === count( $statements ), 'leitor separa DROP, CREATE multi-linha, INSERTs e CREATE final (' . count( $statements ) . ')' );
check( 0 === strpos( $statements[2], 'INSERT INTO `wp_items`' ) && false !== strpos( $statements[2], 'DROP TABLE wp_users' ), 'DROP dentro do dado não inicia nova instrução' );
$types = array_map( function ( $s ) { return BackupSql::classify( $s )['type'] ?? 'erro'; }, $statements );
check( array( 'DROP TABLE IF EXISTS', 'CREATE TABLE', 'INSERT INTO', 'INSERT INTO', 'CREATE TABLE' ) === $types, 'formas geradas pelo backup são aceitas' );

$c  = BackupSql::classify( $statements[3] );
$rw = BackupSql::rewrite_header( $statements[3], $c, 'stage_novo_items' );
check( 0 === strpos( $rw, 'INSERT INTO `stage_novo_items` VALUES' ) && false !== strpos( $rw, '`wp_items` e wp_options' ), 'nome trocado só no cabeçalho; dado com `wp_items` intacto' );
check( 'novo_x' === BackupSql::target_table( 'wp_x', 'wp_', 'novo_' ) && 'outro_x' === BackupSql::target_table( 'outro_x', 'wp_', 'novo_' ), 'troca de prefixo só para tabelas com o prefixo antigo' );

$rejected = array(
	'DROP com duas tabelas'         => 'DROP TABLE IF EXISTS `hubrs_x`, `wp_users`;',
	'DROP sem IF EXISTS'            => 'DROP TABLE `wp_users`;',
	'DROP com comentário'           => 'DROP TABLE IF EXISTS `wp_a`; -- x',
	'nome com ponto'                => 'DROP TABLE IF EXISTS `outro_db`.`wp_users`;',
	'UPDATE'                        => "UPDATE wp_users SET user_pass='x';",
	'INSERT com número sem aspas'   => 'INSERT INTO `wp_a` VALUES (1);',
	'INSERT com duas tuplas'        => "INSERT INTO `wp_a` VALUES ('1'),('2');",
	'INSERT seguido de DROP'        => "INSERT INTO `wp_a` VALUES ('1'); DROP TABLE `wp_users`;",
	'INSERT com SELECT'             => 'INSERT INTO `wp_a` SELECT * FROM `wp_users`;',
	'INSERT com função'             => "INSERT INTO `wp_a` VALUES (LOAD_FILE('/etc/passwd'));",
	'INSERT com aspa aberta'        => "INSERT INTO `wp_a` VALUES ('abc);",
	'INSERT em duas linhas'         => "INSERT INTO `wp_a` VALUES ('a',\n'b');",
	'CREATE em uma linha'           => 'CREATE TABLE `wp_a` (`id` int);',
	'CREATE ... SELECT'             => "CREATE TABLE `wp_a` (\n  `id` int\n) SELECT * FROM `wp_users`;",
	'CREATE com DROP no final'      => "CREATE TABLE `wp_a` (\n  `id` int\n) ENGINE=InnoDB; DROP TABLE `wp_users`;",
	'CREATE com linha estranha'     => "CREATE TABLE `wp_a` (\n  `id` int,\n) ; DROP TABLE `wp_users`; (\n);",
	'CREATE TEMPORARY'              => "CREATE TEMPORARY TABLE `wp_a` (\n  `id` int\n);",
);
foreach ( $rejected as $label => $sql ) {
	check( isset( BackupSql::classify( $sql )['error'] ), "gramática estrita recusa: {$label}" );
}
$fk   = BackupSql::classify( "CREATE TABLE `wp_child` (\n  `id` int,\n  CONSTRAINT `fk1` FOREIGN KEY (`id`) REFERENCES `wp_items` (`id`)\n);" );
$view = BackupSql::classify( 'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `wp_v` AS select 1 AS `x`;' );
check( isset( $fk['error'] ) && false !== strpos( $fk['error'], 'chaves estrangeiras' ), 'chave estrangeira: mensagem clara' );
check( isset( $view['error'] ) && false !== strpos( $view['error'], 'views' ), 'view: mensagem clara' );
check( 'CREATE TABLE' === ( BackupSql::classify( "CREATE TABLE `wp_c` (\n  `j` json DEFAULT NULL,\n  CONSTRAINT `wp_c_chk_1` CHECK (json_valid(`j`))\n) ENGINE=InnoDB;" )['type'] ?? '' ), 'CHECK gerado pelo MySQL/MariaDB é aceito' );

$token_a = '{' . str_repeat( 'ab12', 16 ) . '}';
$token_b = '{' . str_repeat( 'cd34', 16 ) . '}';
check( $token_a === BackupSql::dominant_placeholder( array( $token_a => 5, $token_b => 1 ) ), 'token dominante: o mais frequente' );
check( null === BackupSql::dominant_placeholder( array( $token_a => 2, $token_b => 2 ) ), 'empate: nenhum token é considerado confirmado' );
check( "'100% e {$token_b}'" === BackupSql::repair_placeholder( "'100{$token_a} e {$token_b}'", $token_a ), 'reparo troca só o token dominante' );

// ---------------------------------------------------------------- ZIP
$valid = make_zip( "$work/valid.zip", array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'wp-content/' => '', 'wp-content/plugins/demo/demo.php' => '<?php // demo' ) );
$r     = Validator::validate( $valid );
check( $r['ok'] && Validator::FORMAT_VITPRESS === $r['format'] && 'literal' === $r['manifest']['sql_escaping'], 'backup VitPress novo aceito' );

$old_vitpress = make_zip( "$work/old-vitpress.zip", array( 'manifest.json' => manifest( array( 'sql_escaping' => null ) ), 'database.sql' => $dump, 'wp-content/index.php' => '' ) );
$r            = Validator::validate( $old_vitpress );
check( $r['ok'] && empty( $r['manifest']['sql_escaping'] ), 'backup VitPress 1.9.0 (sem sql_escaping) aceito para reparo' );

$hub_manifest = make_zip( "$work/hub-manifest.zip", array( 'manifest.json' => manifest( array( 'generator' => 'Hub VitAgencia' ) ), 'database.sql' => $dump ) );
check( Validator::validate( $hub_manifest )['ok'], 'manifesto "Hub VitAgencia" com schema 1 aceito' );

$cases = array(
	'schema 2'              => array( 'manifest.json' => manifest( array( 'backup_schema' => 2 ) ), 'database.sql' => $dump ),
	'schema ausente'        => array( 'manifest.json' => json_encode( array( 'generator' => 'VitPress' ) ), 'database.sql' => $dump ),
	'schema texto'          => array( 'manifest.json' => manifest( array( 'backup_schema' => 'um' ) ), 'database.sql' => $dump ),
	'gerador estranho'      => array( 'manifest.json' => manifest( array( 'generator' => 'Outro' ) ), 'database.sql' => $dump ),
	'manifesto inválido'    => array( 'manifest.json' => '{quebrado', 'database.sql' => $dump ),
	'sem database.sql'      => array( 'manifest.json' => manifest(), 'wp-content/a.txt' => 'x' ),
	'arquivo fora da raiz'  => array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'wp-config.php' => '<?php' ),
	'traversal ../'         => array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'wp-content/../../evil.php' => 'x' ),
	'caminho absoluto'      => array( 'manifest.json' => manifest(), 'database.sql' => $dump, '/etc/evil' => 'x' ),
	'letra de unidade'      => array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'C:/evil.php' => 'x' ),
	'barra invertida'       => array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'wp-content\\..\\evil.php' => 'x' ),
	'segmento ponto'        => array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'wp-content/./a.php' => 'x' ),
	'sem manifesto e sem conteúdo antigo' => array( 'qualquer.txt' => 'x' ),
	'sem manifesto com database.sql'      => array( 'database.sql' => $dump, 'wp-content/a.txt' => 'x' ),
	'antigo com wp-config'  => array( 'plugins/a/a.php' => 'x', 'wp-config.php' => '<?php' ),
);
foreach ( $cases as $label => $entries ) {
	$r = Validator::validate( make_zip( "$work/case.zip", $entries ) );
	check( ! $r['ok'] && '' !== $r['error'], "rejeitado: {$label}" );
}

$symlink = make_zip(
	"$work/symlink.zip",
	array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'wp-content/link' => '/etc/passwd' ),
	function ( ZipArchive $zip ) {
		$zip->setExternalAttributesName( 'wp-content/link', ZipArchive::OPSYS_UNIX, ( 0120777 & 0xFFFF ) << 16 );
	}
);
check( ! Validator::validate( $symlink )['ok'], 'rejeitado: link simbólico' );

file_put_contents( "$work/garbage.zip", random_bytes( 4096 ) );
check( ! Validator::validate( "$work/garbage.zip" )['ok'], 'rejeitado: arquivo que não é ZIP' );
file_put_contents( "$work/empty.zip", '' );
check( ! Validator::validate( "$work/empty.zip" )['ok'], 'rejeitado: arquivo vazio' );
$bytes = file_get_contents( $valid );
file_put_contents( "$work/truncated.zip", substr( $bytes, 0, (int) ( strlen( $bytes ) * 0.6 ) ) );
check( ! Validator::validate( "$work/truncated.zip" )['ok'], 'rejeitado: ZIP truncado (envio interrompido)' );
file_put_contents( "$work/cut-end.zip", substr( $bytes, 0, -10 ) );
check( ! Validator::validate( "$work/cut-end.zip" )['ok'], 'rejeitado: ZIP sem o final do diretório central' );

$payload = str_repeat( 'conteudo-ficticio-', 200 );
$stored  = make_zip(
	"$work/crc.zip",
	array( 'manifest.json' => manifest(), 'database.sql' => $dump, 'wp-content/big.txt' => $payload ),
	function ( ZipArchive $zip ) {
		$zip->setCompressionName( 'wp-content/big.txt', ZipArchive::CM_STORE );
	}
);
$raw = file_get_contents( $stored );
$pos = strpos( $raw, $payload );
$raw[ $pos + 100 ] = 'X';
file_put_contents( "$work/crc.zip", $raw );
$crc_check = Validator::validate( "$work/crc.zip" );
$zip       = new ZipArchive();
$zip->open( "$work/crc.zip" );
$extracted = @$zip->extractTo( "$work/crc-extract" );
$zip->close();
$integrity = false === $extracted ? 'extração falhou' : Validator::verify_extracted( "$work/crc.zip", "$work/crc-extract" );
check( ! $crc_check['ok'] || '' !== $integrity, 'dado corrompido dentro do ZIP é detectado antes de mexer no site' );
$zip = new ZipArchive();
$zip->open( $valid );
$zip->extractTo( "$work/valid-extract" );
$zip->close();
check( '' === Validator::verify_extracted( $valid, "$work/valid-extract" ), 'conferência de CRC aceita extração íntegra' );
unlink( "$work/valid-extract/wp-content/plugins/demo/demo.php" );
check( '' !== Validator::verify_extracted( $valid, "$work/valid-extract" ), 'conferência detecta arquivo ausente na extração' );

$legacy = make_zip(
	"$work/backup-content-2026-08-18_11-02-02.zip",
	array( 'index.php' => '<?php', 'debug.log' => '', 'languages/pt_BR.mo' => 'x', 'plugins/demo/demo.php' => '<?php', 'themes/demo/style.css' => '/* */', 'uploads/2026/08/a.txt' => 'x', 'upgrade/' => '' )
);
$r = Validator::validate( $legacy );
check( $r['ok'] && Validator::FORMAT_LEGACY_CONTENT === $r['format'], 'backup antigo do Hub VitAgência (só wp-content) reconhecido como restauração só de arquivos' );
check( ! Validator::validate( $valid, 10.0 )['ok'], 'rejeitado: espaço em disco insuficiente' );

// ---------------------------------------------------------------- Movimentação de arquivos
function journal_to( array &$journal ) {
	return function ( array $entry ) use ( &$journal ) {
		$journal[] = $entry;
	};
}

$target = "$work/site/wp-content";
$source = "$work/extract/wp-content";
write_file( "$target/plugins/elementor/old.php", 'antigo' );
write_file( "$target/plugins/hub-vitagencia/hub.php", 'vitpress-em-uso' );
write_file( "$target/plugins/keep/keep.php", 'fica' );
write_file( "$target/hub-backups/temp/x.txt", 'temp' );
write_file( "$target/index.php", 'index-antigo' );
write_file( "$target/object-cache.php", 'drop-in-atual' );
write_file( "$source/plugins/elementor/new.php", 'novo' );
write_file( "$source/plugins/hub-vitagencia/hub.php", 'versao-do-backup' );
write_file( "$source/uploads/hub-vitagencia/foto.txt", 'upload' );
write_file( "$source/hub-backups/evil.txt", 'nao-copiar' );
write_file( "$source/index.php", 'index-novo' );
write_file( "$source/object-cache.php", 'drop-in-do-backup' );
write_file( "$source/mu-plugins/loader.php", 'mu-do-backup' );

$journal  = array();
$deferred = "$work/deferred";
$moved    = ( new BackupFileMover( array( 'hub-vitagencia' ), 'abcdef0123456789', journal_to( $journal ), $deferred ) )->move( $source, $target );
check( $moved['ok'], 'movimentação concluída' );
check( file_exists( "$target/plugins/elementor/new.php" ) && ! file_exists( "$target/plugins/elementor/old.php" ), 'plugin do backup substitui o atual' );
check( 'vitpress-em-uso' === file_get_contents( "$target/plugins/hub-vitagencia/hub.php" ), 'pasta do VitPress em plugins/ é preservada' );
check( 'upload' === @file_get_contents( "$target/uploads/hub-vitagencia/foto.txt" ), 'proteção vale só em plugins/ (uploads/hub-vitagencia é restaurado)' );
check( ! file_exists( "$target/hub-backups/evil.txt" ) && file_exists( "$target/hub-backups/temp/x.txt" ), 'hub-backups não é tocado' );
check( 'fica' === file_get_contents( "$target/plugins/keep/keep.php" ) && 'index-novo' === file_get_contents( "$target/index.php" ), 'itens fora do backup ficam; arquivos da raiz são trocados' );
check( 'drop-in-atual' === file_get_contents( "$target/object-cache.php" ) && ! is_dir( "$target/mu-plugins" ), 'drop-ins e mu-plugins do backup não são ativados' );
check( 'drop-in-do-backup' === @file_get_contents( "$deferred/object-cache.php" ) && 'mu-do-backup' === @file_get_contents( "$deferred/mu-plugins/loader.php" ), 'drop-ins e mu-plugins do backup ficam guardados fora de wp-content' );
check( array( 'mu-plugins', 'object-cache.php' ) === array_values( array_intersect( array( 'mu-plugins', 'object-cache.php' ), $moved['deferred'] ) ), 'itens adiados informados' );
check( 'antigo' === @file_get_contents( "$target/plugins/.vitpress-old-abcdef0123456789-elementor/old.php" ), 'cópia antiga mantida até a confirmação' );
check( 'index-antigo' === @file_get_contents( "$target/.vitpress-old-abcdef0123456789-index.php" ), 'cópia antiga mantém a extensão original' );
check( count( array_filter( $journal, function ( $e ) { return 'move' === $e['a']; } ) ) === $moved['moved'], 'cada movimentação está no diário' );

$journal_copy = $journal;
check( array() === BackupFileMover::rollback( $journal ), 'reversão pelo diário sem erros' );
check( 'antigo' === @file_get_contents( "$target/plugins/elementor/old.php" ) && ! file_exists( "$target/plugins/elementor/new.php" ), 'reversão devolve o plugin original' );
check( 'index-antigo' === file_get_contents( "$target/index.php" ) && 'index-novo' === file_get_contents( "$source/index.php" ), 'reversão devolve arquivos ao destino e à extração' );
check( ! is_dir( "$target/uploads" ) && 'drop-in-do-backup' === @file_get_contents( "$source/object-cache.php" ), 'pastas criadas removidas e itens adiados devolvidos à extração' );
check( array() === glob( "$target/{,*/}.vitpress-old-*", GLOB_BRACE ), 'nenhuma cópia antiga sobra após reverter' );
check( array() === BackupFileMover::rollback( $journal_copy ), 'reversão repetida é segura (idempotente)' );

$journal = array();
$moved   = ( new BackupFileMover( array( 'hub-vitagencia' ), 'abcdef0123456789', journal_to( $journal ), $deferred ) )->move( $source, $target );
check( $moved['ok'] && array() === BackupFileMover::finalize( $journal ), 'confirmação apaga as cópias antigas' );
check( array() === glob( "$target/{,*/}.vitpress-old-*", GLOB_BRACE ) && file_exists( "$target/plugins/elementor/new.php" ), 'após confirmar: só o conteúdo restaurado' );

$t2 = "$work/site2/wp-content";
$s2 = "$work/extract2/wp-content";
write_file( "$t2/a-file.txt", 'original-a' );
write_file( "$t2/plugins", 'arquivo-no-lugar-da-pasta' );
write_file( "$s2/a-file.txt", 'backup-a' );
write_file( "$s2/plugins/x/x.php", 'x' );
$journal = array();
$moved   = ( new BackupFileMover( array( 'hub-vitagencia' ), '0011223344556677', journal_to( $journal ), "$work/deferred2" ) )->move( $s2, $t2 );
check( ! $moved['ok'] && $moved['errors'], 'falha na movimentação é reportada' );
check( array() === BackupFileMover::rollback( $journal ), 'falha: reversão pelo diário' );
check( 'original-a' === file_get_contents( "$t2/a-file.txt" ) && 'backup-a' === file_get_contents( "$s2/a-file.txt" ), 'falha desfaz trocas já feitas (destino original preservado)' );
check( 'arquivo-no-lugar-da-pasta' === file_get_contents( "$t2/plugins" ), 'destino em conflito não é apagado' );

// Backup antigo (só arquivos): uploads é mesclado arquivo a arquivo.
$t3 = "$work/site3/wp-content";
$s3 = "$work/extract3/wp-content";
write_file( "$t3/uploads/2026/09/destino.txt", 'so-no-destino' );
write_file( "$t3/uploads/2026/08/existente.txt", 'versao-do-destino' );
write_file( "$t3/uploads/2026/08/vizinho.txt", 'vizinho-do-destino' );
write_file( "$t3/plugins/vt/vt.php", 'plugin-do-destino' );
write_file( "$t3/plugins/vt/so-no-destino.php", 'arquivo-extra-do-plugin' );
write_file( "$s3/uploads/2026/08/existente.txt", 'versao-do-backup' );
write_file( "$s3/uploads/2026/08/legado.txt", 'novo-do-backup' );
write_file( "$s3/uploads/2025/01/antigo.txt", 'ano-novo-no-destino' );
write_file( "$s3/plugins/vt/vt.php", 'plugin-do-backup' );
$before3 = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $t3, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	$before3[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( $t3 ) + 1 ) ) ] = md5_file( $f->getPathname() );
}
ksort( $before3 );
$journal = array();
$moved   = ( new BackupFileMover( array( 'hub-vitagencia' ), '8899aabbccddeeff', journal_to( $journal ), "$work/deferred3", array( 'hub-backups' ), array( 'uploads' ) ) )->move( $s3, $t3 );
check( $moved['ok'], 'mesclagem de uploads concluída' );
check( 'so-no-destino' === @file_get_contents( "$t3/uploads/2026/09/destino.txt" ) && 'vizinho-do-destino' === @file_get_contents( "$t3/uploads/2026/08/vizinho.txt" ), 'mesclagem: arquivos só do destino continuam em uploads (mesmo ano e mesmo mês)' );
check( 'versao-do-backup' === @file_get_contents( "$t3/uploads/2026/08/existente.txt" ) && 'novo-do-backup' === @file_get_contents( "$t3/uploads/2026/08/legado.txt" ) && 'ano-novo-no-destino' === @file_get_contents( "$t3/uploads/2025/01/antigo.txt" ), 'mesclagem: só os caminhos do backup são trocados ou criados' );
check( 'versao-do-destino' === @file_get_contents( "$t3/uploads/2026/08/.vitpress-old-8899aabbccddeeff-existente.txt" ) && ! file_exists( "$t3/uploads/.vitpress-old-8899aabbccddeeff-2026" ), 'mesclagem: cópia antiga só do arquivo substituído, não da pasta do ano' );
check( 'plugin-do-backup' === @file_get_contents( "$t3/plugins/vt/vt.php" ) && ! file_exists( "$t3/plugins/vt/so-no-destino.php" ), 'fora de uploads o comportamento continua: plugin trocado inteiro' );
$journal_copy = $journal;
check( array() === BackupFileMover::rollback( $journal ), 'mesclagem: reversão pelo diário sem erros' );
$after3 = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $t3, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	$after3[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( $t3 ) + 1 ) ) ] = md5_file( $f->getPathname() );
}
ksort( $after3 );
check( $before3 === $after3 && ! is_dir( "$t3/uploads/2025" ), 'mesclagem: reversão devolve exatamente os arquivos anteriores e remove as pastas criadas' );
check( array() === BackupFileMover::rollback( $journal_copy ), 'mesclagem: reversão repetida é segura' );
$journal = array();
$moved   = ( new BackupFileMover( array( 'hub-vitagencia' ), '8899aabbccddeeff', journal_to( $journal ), "$work/deferred3", array( 'hub-backups' ), array( 'uploads' ) ) )->move( $s3, $t3 );
check( $moved['ok'] && array() === BackupFileMover::finalize( $journal ), 'mesclagem: confirmação apaga as cópias antigas' );
check( 'so-no-destino' === @file_get_contents( "$t3/uploads/2026/09/destino.txt" ) && 'versao-do-backup' === @file_get_contents( "$t3/uploads/2026/08/existente.txt" ) && array() === glob( "$t3/uploads/2026/08/.vitpress-old-*" ), 'mesclagem: após confirmar, arquivo só do destino continua e não sobram cópias antigas' );
write_file( "$work/extract4/wp-content/uploads/2026", 'arquivo-no-lugar-da-pasta' );
write_file( "$work/site4/wp-content/uploads/2026/x.txt", 'x' );
$journal = array();
$moved   = ( new BackupFileMover( array(), '0123012301230123', journal_to( $journal ), "$work/deferred4", array( 'hub-backups' ), array( 'uploads' ) ) )->move( "$work/extract4/wp-content", "$work/site4/wp-content" );
check( $moved['ok'] && 'x' === @file_get_contents( "$work/site4/wp-content/uploads/.vitpress-old-0123012301230123-2026/x.txt" ), 'mesclagem: arquivo do backup no lugar de uma pasta do destino troca o item inteiro (com cópia antiga)' );
check( array() === BackupFileMover::rollback( $journal ) && 'x' === @file_get_contents( "$work/site4/wp-content/uploads/2026/x.txt" ), 'mesclagem: e a reversão devolve a pasta' );

// ---------------------------------------------------------------- Importador (banco simulado)
class FakeDb {
	public $log = array();
	public $tables;
	public $fail_on;
	public function __construct( array $tables, $fail_on = null ) {
		$this->tables  = $tables;
		$this->fail_on = $fail_on;
	}
	public function query( $sql ) {
		$this->log[] = $sql;
		if ( $this->fail_on && false !== strpos( $sql, $this->fail_on ) ) {
			return false;
		}
		if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) ) {
			$this->tables[] = $m[1];
		}
		if ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m ) ) {
			$this->tables = array_values( array_diff( $this->tables, array( $m[1] ) ) );
		}
		if ( 0 === strpos( $sql, 'RENAME TABLE ' ) ) {
			foreach ( explode( ', ', substr( $sql, 13 ) ) as $pair ) {
				preg_match( '/`([^`]+)` TO `([^`]+)`/', $pair, $m );
				$this->tables = array_values( array_diff( $this->tables, array( $m[1] ) ) );
				$this->tables[] = $m[2];
			}
		}
		return true;
	}
	public function last_error() {
		return "Duplicate entry 'dado-pessoal@exemplo.test' for key 'PRIMARY' near 'INSERT ... dado'";
	}
	public function tables() {
		return $this->tables;
	}
	public function value( $sql ) {
		return '31536000';
	}
}

$db       = new FakeDb( array( 'novo_items', 'novo_users' ) );
$importer = new BackupDatabaseImporter( $db, 'aaaaaa' );
$staged   = $importer->stage( $dump_path, 'wp_', 'novo_', false );
$live_touched = false;
foreach ( $db->log as $sql ) {
	if ( preg_match( '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `novo_/', $sql ) ) {
		$live_touched = true;
	}
}
check( $staged['ok'] && ! $live_touched, 'preparação só escreve em tabelas temporárias' );
check( array( 'novo_items' => 'hubrsaaaaaa_novo_items', 'novo_child' => 'hubrsaaaaaa_novo_child' ) === $staged['staged'], 'tabelas temporárias mapeadas' );
$plan = $importer->plan( $staged['staged'] );
check( array( 'novo_items' => 'hubold' . 'aaaaaa_novo_items' ) === $plan['retired'] && array( 'novo_child' ) === $plan['created'], 'plano registra tabelas substituídas e criadas' );
$swap    = $importer->swap( $plan );
$renames = array_values( array_filter( $db->log, function ( $s ) { return 0 === strpos( $s, 'RENAME TABLE' ); } ) );
check( $swap['ok'] && 1 === count( $renames ), 'ativação usa um único RENAME TABLE' );
check( (bool) preg_grep( '/^SET SESSION lock_wait_timeout = 30$/', $db->log ) && (bool) preg_grep( '/^SET SESSION lock_wait_timeout = 31536000$/', $db->log ), 'RENAME com limite de espera por locks, valor anterior restaurado' );
check( in_array( 'hubold' . 'aaaaaa_novo_items', $db->tables, true ) && 'swapped' === $importer->swap_status( $plan ), 'tabela antiga mantida (hubold) até a confirmação' );
check( $importer->unswap( $plan )['ok'] && 'staged' === $importer->swap_status( $plan ) && in_array( 'novo_items', $db->tables, true ) && ! in_array( 'novo_child', $db->tables, true ), 'reversão devolve a tabela original e retira a criada' );
check( $importer->unswap( $plan )['ok'], 'reversão repetida não faz nada (idempotente)' );
$importer->swap( $plan );
check( array() === $importer->drop_retired( $plan ) && ! preg_grep( '/^hubold/', $db->tables ) && in_array( 'novo_users', $db->tables, true ), 'confirmação remove hubold; tabelas fora do backup ficam' );

$db   = new FakeDb( array( 'novo_items' ) );
$imp  = new BackupDatabaseImporter( $db, 'abcabc' );
$st   = $imp->stage( $dump_path, 'wp_', 'novo_', false );
$plan = $imp->plan( $st['staged'] );
$db->tables[] = 'novo_child';
check( ! $imp->swap( $plan )['ok'] && ! preg_grep( '/^RENAME/', $db->log ), 'tabelas mudaram desde a preparação: troca recusada sem alterar nada' );

$db     = new FakeDb( array( 'novo_items' ), "VALUES ('2'," );
$result = ( new BackupDatabaseImporter( $db, 'bbbbbb' ) )->stage( $dump_path, 'wp_', 'novo_', false );
check( ! $result['ok'] && false !== strpos( $result['error'], 'novo_items' ), 'erro de SQL interrompe e identifica a tabela' );
check( false === strpos( $result['error'], 'exemplo.test' ) && false === strpos( $result['error'], 'dado' ), 'mensagem de erro não expõe dados das linhas' );
check( ! preg_grep( '/^RENAME/', $db->log ) && array( 'novo_items' ) === $db->tables, 'após erro: nenhuma troca e temporárias removidas' );

file_put_contents( "$work/evil.sql", "DROP TABLE IF EXISTS `wp_a`;\nCREATE TABLE `wp_a` (\n  `id` int\n);\nDROP TABLE IF EXISTS `hubrsx_wp_a`, `novo_users`;\n" );
$db     = new FakeDb( array( 'novo_users' ) );
$result = ( new BackupDatabaseImporter( $db, 'cccccc' ) )->stage( "$work/evil.sql", 'wp_', 'novo_', false );
check( ! $result['ok'] && array() === $db->log && array( 'novo_users' ) === $db->tables, 'dump preparado com DROP de tabela real: recusado antes de qualquer consulta' );

file_put_contents( "$work/fk.sql", "CREATE TABLE `wp_a` (\n  `id` int,\n  CONSTRAINT `f` FOREIGN KEY (`id`) REFERENCES `wp_b` (`id`)\n);\n" );
$db     = new FakeDb( array( 'novo_a' ) );
$result = ( new BackupDatabaseImporter( $db, 'cccccc' ) )->stage( "$work/fk.sql", 'wp_', 'novo_', false );
check( ! $result['ok'] && false !== strpos( $result['error'], 'chaves estrangeiras' ) && array() === $db->log, 'chave estrangeira: falha clara sem alterar o banco' );

$create = "CREATE TABLE `wp_a` (\n  `v` text\n);\n";
file_put_contents( "$work/legacy.sql", $create . "INSERT INTO `wp_a` VALUES ('100{$token_a}');\nINSERT INTO `wp_a` VALUES ('50{$token_a} {$token_b}');\n" );
$db = new FakeDb( array() );
( new BackupDatabaseImporter( $db, 'dddddd' ) )->stage( "$work/legacy.sql", 'wp_', 'wp_', true );
check( (bool) preg_grep( "/VALUES \('100%'\)/", $db->log ) && (bool) preg_grep( '/' . preg_quote( "('50% {$token_b}')", '/' ) . '/', $db->log ), 'backup antigo: só o token dominante é reparado' );
$db = new FakeDb( array() );
( new BackupDatabaseImporter( $db, 'eeeeee' ) )->stage( "$work/legacy.sql", 'wp_', 'wp_', false );
check( (bool) preg_grep( '/' . preg_quote( $token_a, '/' ) . '/', $db->log ), 'backup novo: dados não passam pelo reparo' );
file_put_contents( "$work/ambiguous.sql", $create . "INSERT INTO `wp_a` VALUES ('{$token_a}');\nINSERT INTO `wp_a` VALUES ('{$token_b}');\n" );
$db     = new FakeDb( array() );
$result = ( new BackupDatabaseImporter( $db, 'eeeeee' ) )->stage( "$work/ambiguous.sql", 'wp_', 'wp_', true );
check( ! $result['ok'] && array() === $db->log, 'marcadores ambíguos: falha sem executar nada' );

$db      = new FakeDb( array( 'wp_posts', 'hubrsabc123_wp_posts', 'hubold123abc_wp_posts', 'hubrs_manual' ) );
$removed = ( new BackupDatabaseImporter( $db, 'ffffff' ) )->discard_orphans();
check( 1 === $removed && array( 'wp_posts', 'hubold123abc_wp_posts', 'hubrs_manual' ) === $db->tables, 'órfãs: só temporárias hubrs… removidas; hubold… (banco anterior) preservadas' );

// ---------------------------------------------------------------- Blocos de upload
$mb = 1048576;
check( 2 * $mb === BackupService::chunk_size_for( 2 * $mb, 8 * $mb ), 'bloco = upload_max_filesize quando menor (2M/8M)' );
check( 8 * $mb - 65536 === BackupService::chunk_size_for( 64 * $mb, 8 * $mb ), 'bloco cabe em post_max_size com margem (64M/8M)' );
check( 2 * $mb - 65536 === BackupService::chunk_size_for( 8 * $mb, 2 * $mb ), 'post_max_size menor que upload (8M/2M)' );
check( 8 * $mb === BackupService::chunk_size_for( 0, 0 ), 'sem limite: teto de 8 MB' );
check( 262144 === BackupService::chunk_size_for( 100000, 8 * $mb ), 'mínimo de 256 KB' );

// ---------------------------------------------------------------- Ligações no código
$service = file_get_contents( HUB_PATH . 'core/Services/BackupService.php' );
$runner  = file_get_contents( HUB_PATH . 'core/Services/Backup/RestoreRunner.php' );
$view    = file_get_contents( HUB_PATH . 'admin/views/vitagencia-backup.php' );
check( false === strpos( $service, 'esc_sql' ) && false !== strpos( $service, 'BackupSql::insert_statement' ), 'gerador usa BackupSql, não esc_sql' );
check( false !== strpos( $service, "'sql_escaping'     => BackupSql::ESCAPING_MARKER" ), 'manifesto marca sql_escaping=literal' );
check( 4 === substr_count( $service, 'BackupArchiveValidator::validate(' ), 'mesmo validador no upload em blocos, no upload direto, na tela e na restauração' );
check( false !== strpos( $runner, 'BackupArchiveValidator::verify_extracted(' ), 'restauração confere CRC após extrair' );
check( false === strpos( $service, 'instant_move' ) && false === strpos( $service, 'delete_dir(' ), 'movimentação antiga que apagava o destino foi removida' );
check( false !== strpos( $view, 'data-chunk-size' ) && false === strpos( $view, '2 * 1024 * 1024' ), 'tela usa o tamanho de bloco do servidor' );
check( false === strpos( $view, 'continuam válidos' ) && false !== strpos( $view, 'confirm_files_only' ), 'tela não promete compatibilidade total e pede confirmação para só arquivos' );
check( false === strpos( $view, "processStep('init')" ) && false === strpos( $view, 'processStep(' ), 'tela não inicia nem retoma restauração sozinha ao carregar' );
check( false === strpos( $service, "Deny from all\\n\" )" ) && false === strpos( $service, "'/hub-backups/temp/' . \$file" ), 'arquivos temporários fora da pasta pública antiga' );

// ---------------------------------------------------------------- MySQL real (opcional)
$tmp_db = getenv( 'VITPRESS_TMP_DB' );
if ( $tmp_db ) {
	if ( ! preg_match( '/^vitpress_tmp_[a-z0-9_]+$/', $tmp_db ) ) {
		check( false, 'VITPRESS_TMP_DB precisa começar com vitpress_tmp_' );
	} else {
		mysqli_report( MYSQLI_REPORT_OFF );
		$mysqli = new mysqli( getenv( 'VITPRESS_TMP_DB_HOST' ) ?: '127.0.0.1', getenv( 'VITPRESS_TMP_DB_USER' ) ?: 'root', getenv( 'VITPRESS_TMP_DB_PASS' ) ?: '' );
		if ( $mysqli->connect_errno ) {
			check( false, 'conexão MySQL para o banco descartável' );
		} else {
			$mysqli->set_charset( 'utf8mb4' );
			$mysqli->query( "DROP DATABASE IF EXISTS `{$tmp_db}`" );
			$mysqli->query( "CREATE DATABASE `{$tmp_db}` CHARACTER SET utf8mb4" );
			$mysqli->select_db( $tmp_db );
			try {
				$adapter = new class( $mysqli ) {
					private $m;
					public function __construct( $m ) {
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
				};

				$mysqli->query( 'CREATE TABLE `src_items` (`id` int NOT NULL, `v` longtext NULL, `b` blob NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
				$stmt = $mysqli->prepare( 'INSERT INTO `src_items` VALUES (?, ?, ?)' );
				$id   = 0;
				$expected = array();
				foreach ( $tricky as $value ) {
					$id++;
					$bin = random_bytes( 32 );
					$stmt->bind_param( 'iss', $id, $value, $bin );
					$stmt->execute();
					$expected[ $id ] = array( $value, $bin );
				}
				$id++;
				$null = null;
				$stmt->bind_param( 'iss', $id, $null, $null );
				$stmt->execute();
				$expected[ $id ] = array( null, null );

				$create = $mysqli->query( 'SHOW CREATE TABLE `src_items`' )->fetch_row()[1];
				$sql    = "-- sql_escaping: literal\n\nDROP TABLE IF EXISTS `src_items`;\n{$create};\n\n";
				foreach ( $mysqli->query( 'SELECT * FROM `src_items`' )->fetch_all() as $row ) {
					$sql .= BackupSql::insert_statement( 'src_items', $row ) . "\n";
				}
				file_put_contents( "$work/real.sql", $sql );

				$mysqli->query( 'CREATE TABLE `dst_items` (`id` int NOT NULL, `v` longtext NULL, `b` blob NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
				$mysqli->query( "INSERT INTO `dst_items` VALUES (999, 'dado-atual', NULL)" );

				$bad = $sql . "INSERT INTO `src_items` VALUES ('1','duplicado',NULL);\n";
				file_put_contents( "$work/real-bad.sql", $bad );
				$result = ( new BackupDatabaseImporter( $adapter, 'ffffff' ) )->stage( "$work/real-bad.sql", 'src_', 'dst_', false );
				$after  = $mysqli->query( 'SELECT id, v FROM `dst_items`' )->fetch_all();
				check( ! $result['ok'] && array( array( '999', 'dado-atual' ) ) === $after, 'MySQL: erro no meio da importação mantém a tabela real intacta' );
				check( ! preg_grep( '/^hub(rs|old)/', $adapter->tables() ), 'MySQL: temporárias removidas após erro' );

				$importer = new BackupDatabaseImporter( $adapter, 'aabbcc' );
				$staged   = $importer->stage( "$work/real.sql", 'src_', 'dst_', false );
				$plan     = $importer->plan( $staged['staged'] );
				$swap     = $importer->swap( $plan );
				check( $staged['ok'] && $swap['ok'], 'MySQL: importação com troca de prefixo concluída' );
				$same = true;
				foreach ( $mysqli->query( 'SELECT id, v, b FROM `dst_items` ORDER BY id' )->fetch_all() as $row ) {
					$exp  = $expected[ (int) $row[0] ] ?? array( '__ausente__', null );
					$same = $same && $exp[0] === $row[1] && $exp[1] === $row[2];
				}
				check( $same && count( $expected ) === (int) $mysqli->query( 'SELECT COUNT(*) FROM `dst_items`' )->fetch_row()[0], 'MySQL: %, aspas, barras, quebras, UTF-8, NUL, binário e NULL idênticos após ida e volta' );
				$ser = $mysqli->query( 'SELECT v FROM `dst_items` WHERE id = 8' )->fetch_row()[0];
				check( is_array( @unserialize( $ser ) ) && '50%' === unserialize( $ser )['pct'], 'MySQL: dado serializado continua válido' );
				check( 'swapped' === $importer->swap_status( $plan ) && $importer->unswap( $plan )['ok'] && array( array( '999', 'dado-atual' ) ) === $mysqli->query( 'SELECT id, v FROM `dst_items`' )->fetch_all(), 'MySQL: reversão devolve a tabela original' );
				check( $importer->swap( $plan )['ok'] && array() === $importer->drop_retired( $plan ) && ! preg_grep( '/^hub(rs|old)/', $adapter->tables() ), 'MySQL: confirmação não deixa tabelas temporárias ou antigas' );
				check( '31536000' === $adapter->value( 'SELECT @@SESSION.lock_wait_timeout' ), 'MySQL: lock_wait_timeout da sessão restaurado após o RENAME' );

				$mysqli->query( 'CREATE TABLE `src_parent` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
				$mysqli->query( 'CREATE TABLE `src_kid` (`id` int NOT NULL, `p` int, PRIMARY KEY (`id`), CONSTRAINT `fk_kid` FOREIGN KEY (`p`) REFERENCES `src_parent` (`id`)) ENGINE=InnoDB' );
				$mysqli->query( 'CREATE VIEW `src_view` AS SELECT 1 AS x' );
				$kid  = $mysqli->query( 'SHOW CREATE TABLE `src_kid`' )->fetch_row()[1];
				$view = $mysqli->query( 'SHOW CREATE TABLE `src_view`' )->fetch_row()[1];
				file_put_contents( "$work/real-fk.sql", "DROP TABLE IF EXISTS `src_kid`;\n{$kid};\n" );
				file_put_contents( "$work/real-view.sql", "DROP TABLE IF EXISTS `src_view`;\n{$view};\n" );
				$before = $adapter->tables();
				$r1     = ( new BackupDatabaseImporter( $adapter, 'ababab' ) )->stage( "$work/real-fk.sql", 'src_', 'dst_', false );
				$r2     = ( new BackupDatabaseImporter( $adapter, 'ababab' ) )->stage( "$work/real-view.sql", 'src_', 'dst_', false );
				check( ! $r1['ok'] && false !== strpos( $r1['error'], 'chaves estrangeiras' ), 'MySQL: SHOW CREATE com chave estrangeira gera mensagem clara' );
				check( ! $r2['ok'] && false !== strpos( $r2['error'], 'views' ), 'MySQL: SHOW CREATE de view gera mensagem clara' );
				check( $before === $adapter->tables(), 'MySQL: backups com FK/view não alteram tabelas' );
			} finally {
				$mysqli->query( "DROP DATABASE IF EXISTS `{$tmp_db}`" );
				$gone = ! $mysqli->query( "SHOW DATABASES LIKE '{$tmp_db}'" )->num_rows;
				check( $gone, "MySQL: banco descartável {$tmp_db} removido" );
				$mysqli->close();
			}
		}
	}
} else {
	echo "ignorado: ida e volta em MySQL (defina VITPRESS_TMP_DB=vitpress_tmp_...)\n";
}

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: backup e restauração ok\n";
