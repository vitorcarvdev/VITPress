<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'geral';
if ( 'trafego' === $active_tab ) {
	$active_tab = 'geral';
}

// Helper vars para abas de configurações
$plugin_protection = isset( $settings['plugin_protection'] ) && is_array( $settings['plugin_protection'] )
	? $settings['plugin_protection']
	: [ 'enabled' => false, 'admins' => [] ];
$pp_enabled = ! empty( $plugin_protection['enabled'] );
$pp_admins  = array_map( 'intval', (array) ( $plugin_protection['admins'] ?? [] ) );

$all_admins = get_users( [
	'capability' => 'manage_options',
	'orderby'    => 'user_login',
	'order'      => 'ASC',
] );

$integ_settings = isset( $settings['integrations'] ) ? $settings['integrations'] : array();
$gads_contact   = isset( $integ_settings['google_ads_contact'] ) ? $integ_settings['google_ads_contact'] : array();
$gads_offline = isset( $integ_settings['google_ads_offline'] ) ? $integ_settings['google_ads_offline'] : array();
$offline_name = $gads_offline['conversion_name'] ?? 'Compra offline';
$offline_currency = $gads_offline['currency'] ?? 'BRL';

$wp_settings      = isset( $settings['wordpress'] ) ? $settings['wordpress'] : array();
$disable_comments = ! empty( $wp_settings['disable_comments'] );
$hide_login       = ! empty( $wp_settings['hide_login'] );
?>
<div class="wrap hub-wrap">
	<h1><span class="dashicons dashicons-shield"></span> Área VitAgência (Restrito)</h1>
	<p>Esta área é de uso exclusivo da equipe técnica VitAgência. Configurações avançadas de IA, manutenção, integrações e segurança são gerenciadas aqui.</p>

	<h2 class="nav-tab-wrapper">
		<a href="?page=hub-vitagencia&tab=geral" class="nav-tab <?php echo 'geral' === $active_tab ? 'nav-tab-active' : ''; ?>">Marketing</a>
		<a href="?page=hub-vitagencia&tab=lgpd" class="nav-tab <?php echo 'lgpd' === $active_tab ? 'nav-tab-active' : ''; ?>">LGPD</a>
		<a href="?page=hub-vitagencia&tab=emails" class="nav-tab <?php echo 'emails' === $active_tab ? 'nav-tab-active' : ''; ?>">Amazon</a>
		<a href="?page=hub-vitagencia&tab=seguranca" class="nav-tab <?php echo 'seguranca' === $active_tab ? 'nav-tab-active' : ''; ?>">Segurança do WP</a>
		<a href="?page=hub-vitagencia&tab=diagnostico" class="nav-tab <?php echo 'diagnostico' === $active_tab ? 'nav-tab-active' : ''; ?>">Diagnóstico</a>
		<a href="?page=hub-vitagencia&tab=tracker" class="nav-tab <?php echo 'tracker' === $active_tab ? 'nav-tab-active' : ''; ?>">Tracker</a>
	</h2>

	<?php if ( isset( $_GET['message'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php 
				if ( 'saved' === $_GET['message'] ) echo 'Configurações salvas com sucesso.';
				elseif ( 'vitzap_integration_saved' === $_GET['message'] ) echo 'Integração com VitZap salva com sucesso!';
				elseif ( 'vitads_saved' === $_GET['message'] ) echo 'Integração com VitAds salva com sucesso!';
				elseif ( 'license_requested' === $_GET['message'] ) echo 'Solicitação de licença enviada. Verifique o e-mail.';
				elseif ( 'license_authorized' === $_GET['message'] ) echo 'Licença autorizada com sucesso!';
				elseif ( 'ses_saved' === $_GET['message'] ) echo 'Configurações de SMTP salvas com sucesso.';
				elseif ( 's3_saved' === $_GET['message'] ) echo 'Configurações do Amazon S3 salvas com sucesso.';
					elseif ( 's3_success' === $_GET['message'] ) echo 'Teste de conexão S3 realizado com sucesso! As credenciais estão corretas.';
					elseif ( 'ses_test_ok' === $_GET['message'] ) echo 'E-mail de teste enviado com sucesso! Verifique a caixa de entrada.';
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['error'] ) ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// Erro específico para o e-mail que é capturado via transient
	$ses_last_error = get_transient( 'hub_ses_last_error' );
	if ( isset( $_GET['error'] ) && 'ses_test_failed' === $_GET['error'] && $ses_last_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><strong>Falha ao enviar e-mail de teste:</strong><br><?php echo esc_html( $ses_last_error ); ?></p>
		</div>
	<?php endif; ?>

	<div class="hub-tab-content" style="margin-top: 20px;">
		<?php
		if ( 'backup' === $active_tab ) {
			echo '<div class="notice notice-info"><p>O backup mudou para o menu principal: <a href="' . esc_url( admin_url( 'admin.php?page=hub-backup' ) ) . '">Backup e Restauração</a>. As configurações do Amazon S3 estão na aba Amazon.</p></div>';
		} elseif ( 'google-ads-export' === $active_tab ) {
			echo '<div class="notice notice-info"><p>A exportação CSV de compras offline foi desativada. As conversões de Google Ads e Meta Ads são gerenciadas centralmente pelo VitAds.</p></div>';
		} else {
		$tab_file = HUB_PATH . 'admin/views/vitagencia-' . $active_tab . '.php';
		if ( file_exists( $tab_file ) ) {
			require_once $tab_file;
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Aba não encontrada.', 'hub' ) . '</p></div>';
		}
		}
		?>
	</div>
</div>
