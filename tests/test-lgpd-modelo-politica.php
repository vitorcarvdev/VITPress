<?php
/** Teste local descartável: "Criar Página (Modelo VitAgência)" e "Usar Modelo VitAgência" da aba LGPD. */
require 'C:/Users/vitor/AppData/Local/Temp/vcsis-homolog/hub/wp-load.php';
if ( ! defined( 'HUB_PATH' ) ) { require dirname( __DIR__ ) . '/hub.php'; hub_init(); }
$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $host, array( '127.0.0.1', 'localhost' ), true ) || false === strpos( DB_HOST, '3308' ) ) { echo "ABORTADO: nao e o WordPress descartavel\n"; exit( 3 ); }
$fail = 0;
function check( $c, $m ) { global $fail; echo ( $c ? 'ok ' : 'FALHA ' ) . $m . PHP_EOL; if ( ! $c ) { $fail++; } }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
add_filter( 'user_has_cap', static function ( $caps ) { $caps['hub_vitagencia_manage_internal'] = true; return $caps; } );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'bloqueado', 'sem rede no teste' ); } );
class RedirectStop extends Exception {}
add_filter( 'wp_redirect', static function ( $loc ) { throw new RedirectStop( $loc ); } );

// estado original para restaurar
$orig_settings = get_option( 'hub_settings' );
$orig_page     = get_option( 'wp_page_for_privacy_policy' );
$orig_collector = get_option( 'hub_lead_collector', array() );
$created       = array();

$run = static function ( $post ) {
	$_POST = $post + array( 'hub_nonce' => wp_create_nonce( 'hub_save_vitagencia' ), 'vitagencia_tab' => 'lgpd' );
	$_REQUEST = $_POST;
	$mod = new \Hub\Modules\VitAgencia\VitAgenciaModule();
	try { $mod->handle_save_vitagencia(); } catch ( RedirectStop $e ) { return $e->getMessage(); }
	return '';
};

try {
	// 1) criar página do modelo (site sem política configurada)
	delete_option( 'wp_page_for_privacy_policy' );
	update_option( 'hub_lead_collector', array_merge( (array) $orig_collector, array( 'notification_emails' => '' ) ) );
	$loc = $run( array( 'create_privacy_policy' => '1' ) );
	$pid = (int) get_option( 'wp_page_for_privacy_policy' );
	$created[] = $pid;
	$page = $pid ? get_post( $pid ) : null;
	check( false !== strpos( $loc, 'tab=lgpd' ), 'criar: redireciona para a aba LGPD' );
	check( $page && 'publish' === $page->post_status && 'page' === $page->post_type, 'criar: página publicada' );
	check( $page && 'Política de Privacidade' === $page->post_title, 'criar: título correto' );
	check( $page && false !== strpos( $page->post_content, 'Consentimento para Uso e Compartilhamento de Dados' ) && false !== strpos( $page->post_content, 'Ao aceitar esta política' ) && false !== strpos( $page->post_content, 'plataformas de marketing, publicidade e análise' ) && false !== strpos( $page->post_content, '<strong>“Aceito”</strong>' ), 'criar: texto de consentimento informado pelo cliente' );
	$c = $page ? $page->post_content : '';
	check( strpos( $c, 'Consentimento para Uso' ) < strpos( $c, 'Ao clicar em' ) && strpos( $c, 'Ao clicar em' ) < strpos( $c, 'Quem trata os seus dados' ), 'criar: o texto do cliente vem primeiro e o complemento depois' );
	check( false !== strpos( $c, 'Lei nº 13.709/2018' ) && false !== strpos( $c, 'Seus direitos' ) && false !== strpos( $c, 'revogação do consentimento' ), 'criar: direitos do titular (LGPD)' );
	check( false !== strpos( $c, '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>' ), 'criar: nome do site preenchido' );
	check( false !== strpos( $c, 'mailto:' . sanitize_email( get_option( 'admin_email' ) ) ), 'criar: sem e-mail de notificação, usa o e-mail do administrador' );

	// com e-mail de notificação do Atendente: o primeiro endereço válido vira o contato
	update_option( 'hub_lead_collector', array_merge( (array) $orig_collector, array( 'notification_emails' => 'invalido, aviso@cliente.test, outro@cliente.test' ) ) );
	wp_update_post( array( 'ID' => $pid, 'post_content' => 'x' ) );
	$run( array( 'replace_privacy_policy' => '1' ) );
	$c2 = get_post( $pid )->post_content;
	check( false !== strpos( $c2, 'mailto:aviso@cliente.test' ) && false === strpos( $c2, 'outro@cliente.test' ) && false === strpos( $c2, 'mailto:' . sanitize_email( get_option( 'admin_email' ) ) ), 'usar modelo: contato é o e-mail de notificação do Atendente' );
	update_option( 'hub_lead_collector', $orig_collector );
	check( false !== strpos( $c, 'hub-reset-consent' ) && 1 === preg_match( '#Última atualização: \d{2}/\d{2}/\d{4}#u', $c ) && false === strpos( $c, '{$' ), 'criar: botão de redefinir consentimento, data e nenhuma variável literal' );
	check( false === strpos( $c, 'COOKIES E TECNOLOGIAS SEMELHANTES' ), 'criar: sem o modelo longo antigo' );
	$s = get_option( 'hub_settings' );
	check( (int) ( $s['privacy']['policy_page'] ?? 0 ) === $pid, 'criar: página vinculada nas configurações do VitPress' );

	// 2) "Usar Modelo": sobrescreve o conteúdo de uma página já configurada
	wp_update_post( array( 'ID' => $pid, 'post_content' => 'TEXTO ANTIGO QUALQUER' ) );
	$loc = $run( array( 'replace_privacy_policy' => '1' ) );
	$page = get_post( $pid );
	check( false !== strpos( $loc, 'tab=lgpd' ), 'usar modelo: redireciona para a aba LGPD' );
	check( false === strpos( $page->post_content, 'TEXTO ANTIGO QUALQUER' ) && false !== strpos( $page->post_content, 'Ao aceitar esta política' ), 'usar modelo: conteúdo substituído pelo modelo' );
	check( 'publish' === $page->post_status, 'usar modelo: página segue publicada' );

	// 3) renderização da página
	$html = apply_filters( 'the_content', $page->post_content );
	check( false !== strpos( $html, '<strong>“Aceito”</strong>' ) && false !== strpos( $html, '<h2' ), 'página renderiza o título e o “Aceito” em negrito' );

	// 4) a aba LGPD renderiza com a página configurada
	ob_start();
	$_GET['tab'] = 'lgpd';
	try { include HUB_PATH . 'admin/views/vitagencia-lgpd.php'; } catch ( Throwable $e ) { echo 'ERRO: ' . $e->getMessage(); }
	$view = ob_get_clean();
	check( false !== strpos( $view, 'Usar Modelo' ) && false !== strpos( $view, 'Editar P' ), 'aba LGPD mostra "Editar Página" e "Usar Modelo VitAgência"' );
} finally {
	foreach ( $created as $id ) { if ( $id ) { wp_delete_post( $id, true ); } }
	update_option( 'hub_settings', $orig_settings );
	update_option( 'hub_lead_collector', $orig_collector );
	if ( false === $orig_page ) { delete_option( 'wp_page_for_privacy_policy' ); } else { update_option( 'wp_page_for_privacy_policy', $orig_page ); }
}
echo $fail ? "RESULTADO: {$fail} falha(s)\n" : "RESULTADO: modelo de política ok\n";
exit( $fail ? 1 : 0 );