<?php
/**
 * VitPress — recuperação da restauração de backup.
 *
 * Acessado diretamente (nunca pelo WordPress) para confirmar ou reverter uma restauração quando o site
 * restaurado não carrega: plugin com erro fatal, usuários trocados, URL errada ou .maintenance ativo.
 * Lê só as constantes do wp-config.php; o WordPress, os plugins, os mu-plugins e os drop-ins não são carregados.
 */

if ( defined( 'ABSPATH' ) ) {
	return;
}

@ini_set( 'display_errors', '0' );
$hub_recovery_nonce = bin2hex( random_bytes( 16 ) );
header( 'Content-Type: text/html; charset=utf-8' );
header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
header( 'X-Robots-Tag: noindex, nofollow' );
header( 'X-Frame-Options: DENY' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Referrer-Policy: no-referrer' );
header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$hub_recovery_nonce}'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );

$hub_recovery_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
if ( ! in_array( $hub_recovery_method, array( 'GET', 'POST' ), true ) ) {
	http_response_code( 405 );
	exit;
}

// O wp-config.php termina carregando ABSPATH/wp-settings.php; aqui esse arquivo é vazio.
define( 'ABSPATH', __DIR__ . '/recovery-bootstrap/' );
foreach ( array( 'BackupSql', 'BackupFileMover', 'BackupDatabaseImporter', 'BackupWorkspace', 'RestoreOperation', 'RestoreRunner', 'RecoveryPreflight', 'RecoveryEndpoint' ) as $hub_class ) {
	require_once __DIR__ . '/core/Services/Backup/' . $hub_class . '.php';
}
use Hub\Core\Services\Backup\BackupWorkspace;
use Hub\Core\Services\Backup\RecoveryEndpoint;
use Hub\Core\Services\Backup\RecoveryPreflight;

$hub_recovery_config = RecoveryPreflight::locate_config( __DIR__ );
if ( null === $hub_recovery_config ) {
	http_response_code( 500 );
	exit( 'Configuração do site não encontrada.' );
}
// Um wp-config.php que carrega o WordPress por outro caminho morre aqui (ABSPATH não tem o núcleo).
$hub_recovery_config_done = false;
register_shutdown_function(
	function () use ( &$hub_recovery_config_done ) {
		if ( ! $hub_recovery_config_done ) {
			if ( ! headers_sent() ) {
				http_response_code( 500 );
			}
			echo RecoveryPreflight::CONFIG_FAILED_TEXT . '; o ponto de recuperação não pode ser usado. Use o painel ou o suporte técnico.';
		}
	}
);
require $hub_recovery_config;
$hub_recovery_config_done = true;
if ( RecoveryPreflight::wordpress_loaded() ) {
	http_response_code( 500 );
	exit( RecoveryPreflight::WP_LOADED_TEXT . '; o ponto de recuperação não continua para não agir com plugins carregados. Use o painel ou o suporte técnico.' );
}

$hub_content_dir = null;
foreach ( array( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : null, dirname( __DIR__, 2 ) ) as $hub_candidate ) {
	if ( $hub_candidate && is_file( rtrim( $hub_candidate, '/\\' ) . '/hub-backups/workspace.php' ) ) {
		$hub_content_dir = rtrim( str_replace( '\\', '/', $hub_candidate ), '/' );
		break;
	}
}
$hub_workspace  = $hub_content_dir ? ( new BackupWorkspace( $hub_content_dir ) )->existing() : null;
$hub_db_factory = function () {
	return RecoveryEndpoint::connect(
		defined( 'DB_HOST' ) ? DB_HOST : 'localhost',
		defined( 'DB_USER' ) ? DB_USER : '',
		defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '',
		defined( 'DB_NAME' ) ? DB_NAME : '',
		defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4'
	);
};

if ( isset( $_GET['probe'] ) ) {
	$hub_probe = RecoveryPreflight::respond_probe(
		$hub_workspace,
		(string) $_GET['probe'],
		function () use ( $hub_recovery_config, $hub_content_dir, $hub_workspace, $hub_db_factory ) {
			$db = call_user_func( $hub_db_factory );
			return array(
				'wordpress_loaded' => RecoveryPreflight::wordpress_loaded(),
				'config'           => str_replace( '\\', '/', realpath( $hub_recovery_config ) ?: $hub_recovery_config ),
				'content_dir'      => (string) $hub_content_dir,
				'workspace'        => null !== $hub_workspace,
				'database'         => $db && '1' === $db->value( 'SELECT 1' ),
				'object_cache'     => RecoveryPreflight::object_cache( (string) $hub_content_dir )['active'],
			);
		}
	);
	http_response_code( $hub_probe['status'] );
	header( 'Content-Type: application/json; charset=utf-8' );
	if ( '' !== $hub_probe['signature'] ) {
		header( RecoveryPreflight::SIGNATURE_HEADER . ': ' . $hub_probe['signature'] );
	}
	exit( $hub_probe['body'] );
}

$hub_endpoint = new RecoveryEndpoint( $hub_workspace, (string) $hub_content_dir, $hub_db_factory, RecoveryPreflight::object_cache( (string) $hub_content_dir ) );
$hub_response = $hub_endpoint->handle(
	array(
		'method' => $hub_recovery_method,
		'op'     => isset( $_POST['op'] ) ? (string) $_POST['op'] : ( isset( $_GET['op'] ) ? (string) $_GET['op'] : '' ),
		'token'  => isset( $_POST['token'] ) ? (string) $_POST['token'] : '',
		'action' => isset( $_POST['restore_action'] ) ? (string) $_POST['restore_action'] : '',
	)
);

http_response_code( $hub_response['status'] );
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperação da restauração</title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0}main{max-width:640px;margin:60px auto;background:#fff;border:1px solid #c3c4c7;padding:24px 32px}button{font-size:14px;padding:6px 14px;cursor:pointer}input[type=password]{width:100%;max-width:560px;font-family:monospace}</style>
</head>
<body>
<main>
<h1>Recuperação da restauração</h1>
<?php echo $hub_response['body']; ?>
</main>
<?php if ( $hub_response['form'] ) : ?>
<script nonce="<?php echo $hub_recovery_nonce; ?>">
(function () {
	var m = /(?:^#|&)k=([a-f0-9]{64})/.exec(window.location.hash);
	if (!m) { return; }
	history.replaceState(null, '', window.location.pathname + window.location.search);
	document.getElementById('hub-recovery-token').value = m[1];
	document.getElementById('hub-recovery-form').submit();
})();
</script>
<?php endif; ?>
</body>
</html>
