<?php
/**
 * Link cego do Atendente: /novo-lead/ (e variações) leva para a página inicial com #atendente, mantendo a query string.
 * Roda no WordPress DESCARTÁVEL (127.0.0.1/localhost); não envia nada para fora e restaura as opções.
 */
$root = dirname( __DIR__, 2 ) . '/hub/wp-load.php';
if ( ! file_exists( $root ) ) { $root = 'C:/Users/vitor/AppData/Local/Temp/vcsis-homolog/hub/wp-load.php'; }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8092'; $_SERVER['SERVER_NAME'] = '127.0.0.1';
require $root;
$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $host, array( '127.0.0.1', 'localhost' ), true ) || false === strpos( DB_HOST, '3308' ) ) { echo "ABORTADO: não é o WordPress descartável\n"; exit( 3 ); }

$failed = 0;
function check( $c, $m ) { global $failed; echo ( $c ? 'ok ' : 'FALHA ' ) . $m . PHP_EOL; if ( ! $c ) { $failed++; } }
class RedirectCaught extends Exception { public $location; public $status; }
add_filter( 'wp_redirect', static function ( $location, $status ) { $e = new RedirectCaught( 'redirect' ); $e->location = $location; $e->status = $status; throw $e; }, 1, 2 );

$orig_collector = get_option( 'hub_lead_collector', array() );
$orig_front     = get_option( 'page_on_front' );
$svc = \Hub\Core\Services\LeadCollectorService::get_instance();

$run = static function ( $uri, $method = 'GET' ) use ( $svc ) {
	$_SERVER['REQUEST_URI']    = $uri;
	$_SERVER['QUERY_STRING']   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
	$_SERVER['REQUEST_METHOD'] = $method;
	$_GET = array();
	parse_str( $_SERVER['QUERY_STRING'], $_GET );
	try { $svc->redirect_legacy_lead_page(); } catch ( RedirectCaught $e ) { return $e; }
	return null;
};

try {
	update_option( 'hub_lead_collector', array_merge( (array) $orig_collector, array( 'enabled' => true, 'excluded_pages' => array() ) ) );
	$home = home_url( '/' );

	$r = $run( '/novo-lead/' );
	check( $r && $home . '#atendente' === $r->location && 302 === $r->status, '/novo-lead/ leva para a página inicial com #atendente' );
	$r = $run( '/novo-lead' );
	check( $r && $home . '#atendente' === $r->location, '/novo-lead (sem barra final) também' );
	$r = $run( '/Novo-Lead/' );
	check( $r && $home . '#atendente' === $r->location, 'maiúsculas e minúsculas não importam' );
	$r = $run( '/novo-lead/?gclid=Cj0K123&utm_source=google&utm_campaign=cirurgia%20recife' );
	check( $r && $home . '?gclid=Cj0K123&utm_source=google&utm_campaign=cirurgia%20recife#atendente' === $r->location, 'gclid e UTMs são preservados (atribuição não se perde)' );
	check( null === $run( '/contato/' ) && null === $run( '/novo-lead-obrigado/' ) && null === $run( '/blog/novo-lead/' ) && null === $run( '/' ), 'outras páginas não são redirecionadas' );
	check( null === $run( '/novo-lead/', 'POST' ), 'POST não é redirecionado' );
	check( null === $run( '/novo-lead/?preview=true' ), 'pré-visualização do editor não é redirecionada' );

	add_filter( 'hub/legacy_lead_form_paths', $f = static function ( $p ) { $p[] = 'agende'; return $p; } );
	$r = $run( '/agende/' );
	check( $r && $home . '#atendente' === $r->location, 'outras páginas antigas podem ser adicionadas pelo filtro hub/legacy_lead_form_paths' );
	remove_filter( 'hub/legacy_lead_form_paths', $f );

	update_option( 'hub_lead_collector', array_merge( (array) $orig_collector, array( 'enabled' => false ) ) );
	check( null === $run( '/novo-lead/' ), 'com o Atendente desativado não redireciona (não há para onde levar o visitante)' );

	$front = wp_insert_post( array( 'post_title' => 'Inicio rig', 'post_status' => 'publish', 'post_type' => 'page' ) );
	update_option( 'page_on_front', $front );
	update_option( 'hub_lead_collector', array_merge( (array) $orig_collector, array( 'enabled' => true, 'excluded_pages' => array( $front ) ) ) );
	check( null === $run( '/novo-lead/' ), 'se o Atendente está excluído da página inicial, não redireciona' );
	wp_delete_post( $front, true );

	// subpasta (localhost/projetos/...)
	update_option( 'hub_lead_collector', array_merge( (array) $orig_collector, array( 'enabled' => true, 'excluded_pages' => array() ) ) );
	update_option( 'page_on_front', $orig_front );
	$sub = static function () { return 'http://127.0.0.1:8092/projetos/site'; };
	add_filter( 'home_url', static function ( $url, $path ) use ( $sub ) { return rtrim( $sub(), '/' ) . '/' . ltrim( (string) $path, '/' ); }, 10, 2 );
	$r = $run( '/projetos/site/novo-lead/?utm_source=x' );
	check( $r && 'http://127.0.0.1:8092/projetos/site/?utm_source=x#atendente' === $r->location, 'site em subpasta: /projetos/site/novo-lead/ leva para /projetos/site/#atendente' );
	check( null === $run( '/projetos/site/contato/' ), 'site em subpasta: outras páginas seguem normais' );
} finally {
	update_option( 'hub_lead_collector', $orig_collector );
	update_option( 'page_on_front', $orig_front );
}

$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/hub-collector.js' );
check( false !== strpos( $js, "var LINK_HASH = '#atendente';" ) && false !== strpos( $js, "addEventListener('hashchange'" ) && false !== strpos( $js, 'isLinkHash(window.location.hash)' ) && false !== strpos( $js, 'isLinkHash(target.hash)' ), 'o JavaScript abre o Atendente no carregamento, na mudança de âncora e em cliques para a própria página' );

echo $failed ? "RESULTADO: {$failed} falha(s)\n" : "RESULTADO: link cego do Atendente ok\n";
exit( $failed ? 1 : 0 );