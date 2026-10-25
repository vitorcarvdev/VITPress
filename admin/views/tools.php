<?php
/**
 * admin/views/tools.php
 * Tela principal de Ferramentas com abas.
 *
 * @package VitPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'perfil';
?>
<div class="wrap hub-wrap">
	<h1><?php esc_html_e( 'Ferramentas do VitPress', 'hub' ); ?></h1>
	<h2 class="nav-tab-wrapper">
		<a href="?page=hub-tools&tab=perfil" class="nav-tab <?php echo 'perfil' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Perfil', 'hub' ); ?>
		</a>
		<a href="?page=hub-tools&tab=captador" class="nav-tab <?php echo 'captador' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Captador de Leads', 'hub' ); ?>
		</a>
		<a href="?page=hub-tools&tab=logs" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Logs', 'hub' ); ?>
		</a>
	</h2>

	<div class="hub-tab-content" style="margin-top: 20px;">
		<?php
		$tab_file = HUB_PATH . 'admin/views/tools-' . $active_tab . '.php';
		if ( file_exists( $tab_file ) ) {
			require_once $tab_file;
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Aba não encontrada.', 'hub' ) . '</p></div>';
		}
		?>
	</div>
</div>
