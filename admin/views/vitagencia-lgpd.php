<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'hub_settings', array() );
$privacy = isset( $settings['privacy'] ) ? $settings['privacy'] : array();
$banner_enabled = ! empty( $privacy['banner_enabled'] );
$banner_text = \Hub\Core\Services\PrivacyService::message( $privacy['banner_text'] ?? '' );

// Sincroniza a política do Hub com a oficial do WordPress
$wp_policy_page = get_option('wp_page_for_privacy_policy');
$policy_page = $wp_policy_page ? intval($wp_policy_page) : (isset( $privacy['policy_page'] ) ? intval( $privacy['policy_page'] ) : 0);

?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="hub_save_vitagencia">
	<input type="hidden" name="vitagencia_tab" value="lgpd">
	<?php wp_nonce_field( 'hub_save_vitagencia', 'hub_nonce' ); ?>

	<div class="card" style="max-width: 800px; padding: 20px;">
		<h2>Privacidade e LGPD</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Consentimento de Cookies</th>
				<td>
					<label>
						<input type="checkbox" name="privacy_banner_enabled" value="1" <?php checked( $banner_enabled ); ?>>
						<strong>Ativar Banner de Consentimento</strong>
					</label>
					<p class="description">Exibe uma janela central (Aceitar/Recusar) que pede uma escolha antes da navegação. Integrado nativamente com Tracker, Google Consent Mode e formulários.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Mensagem do Banner</th>
				<td>
					<textarea name="privacy_banner_text" rows="3" class="large-text"><?php echo esc_textarea( $banner_text ); ?></textarea>
					<p class="description">O link "Política de Privacidade" aparece abaixo dos botões quando uma página estiver selecionada. Um link com a classe <code>hub-reset-consent</code> reabre as preferências.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Política de Privacidade</th>
				<td>
					<?php
					wp_dropdown_pages( array(
						'name'              => 'privacy_policy_page',
						'show_option_none'  => '&mdash; Selecione uma página &mdash;',
						'option_none_value' => '0',
						'selected'          => $policy_page,
					) );
					?>
					<p class="description">Selecione a página que será vinculada no banner.</p>
					
					<?php if ( ! $policy_page ) : ?>
						<p style="margin-top: 15px;">
							<button type="submit" name="create_privacy_policy" class="button" value="1">Criar Página (Modelo VitAgência)</button>
						</p>
					<?php else: ?>
						<div style="margin-top: 15px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
							<p style="margin-top: 0;"><strong>Este site já possui uma Política de Privacidade configurada.</strong></p>
							<div style="display: flex; gap: 10px;">
								<a href="<?php echo esc_url( get_edit_post_link( $policy_page ) ); ?>" class="button button-secondary" target="_blank">Editar Página</a>
								<button type="submit" name="replace_privacy_policy" class="button" value="1" onclick="return confirm('ATENÇÃO: Substituir o conteúdo atual da página pelo modelo VitAgência? Isso sobrescreverá qualquer texto existente e não pode ser desfeito.');">Usar Modelo VitAgência</button>
							</div>
						</div>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<p class="submit" style="margin-bottom: 0;">
			<?php submit_button( __( 'Salvar Privacidade', 'hub' ), 'primary', 'submit', false ); ?>
		</p>
	</div>
</form>