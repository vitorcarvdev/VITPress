<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'hub_save_vitagencia', 'hub_nonce' ); ?>
	<input type="hidden" name="action" value="hub_save_vitagencia">
	<input type="hidden" name="vitagencia_tab" value="trafego">

	<?php
	$profile_manager = \Hub\Core\ProfileManager::get_instance();
	$profile_chosen  = $profile_manager->is_profile_selected();
	$profile_current = $profile_manager->get_active_profile()->get_id();
	?>
	<div class="card" style="max-width: 800px; padding: 20px;">
		<h2 style="margin-top: 0;">Área de Atuação</h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="hub_active_profile">Perfil de Uso (Profissão)</label></th>
				<td>
					<select id="hub_active_profile" name="hub_active_profile">
						<?php if ( ! $profile_chosen ) : ?>
							<option value="">— Escolha a sua área —</option>
						<?php endif; ?>
						<?php foreach ( $profile_manager->get_profiles() as $profile_id => $profile_obj ) : ?>
							<option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $profile_chosen && $profile_current === $profile_id ); ?>>
								<?php echo esc_html( $profile_obj->get_name() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">Altera as nomenclaturas (ex: Paciente vs Cliente), ícones e estágios. Enquanto não for escolhida, vale o perfil padrão (<?php echo esc_html( $profile_manager->get_active_profile()->get_name() ); ?>).</p>
				</td>
			</tr>
		</table>
	</div>

	<div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
		<h2>Conversões e Marketing</h2>
		<?php
		$vitads_config = \Hub\Core\Services\Integrations\VitAdsEventService::config();
		$vitads_on     = ! empty( $vitads_config['enabled'] ) && '' !== $vitads_config['token'] && '' !== $vitads_config['endpoint'];
		$vitads_status = \Hub\Core\Services\Integrations\VitAdsMarketing::is_enabled()
			? \Hub\Core\Services\Integrations\VitAdsMarketing::connection()
			: array();
		?>
		<p style="margin: 0 0 8px;">
			<?php if ( $vitads_on ) : ?>
				<strong>● VitAds conectado</strong>
			<?php else : ?>
				<strong>⚠ VitAds desconectado</strong>
			<?php endif; ?>
		</p>
		<p class="description">As conversões de Google Ads e Meta Ads são gerenciadas centralmente pelo VitAds.</p>
		<?php if ( isset( $vitads_status['google_ads'] ) || isset( $vitads_status['meta_ads'] ) ) : ?>
			<p class="description" style="margin-bottom: 0;">
				Google Ads: <?php echo ! empty( $vitads_status['google_ads'] ) ? 'Configurado' : 'Não configurado'; ?>
				· Meta Ads: <?php echo ! empty( $vitads_status['meta_ads'] ) ? 'Configurado' : 'Não configurado'; ?>
			</p>
		<?php endif; ?>
	</div>

	<p class="submit" style="max-width: 800px;">
		<?php submit_button( __( 'Salvar Configurações de Tráfego', 'hub' ), 'primary', 'submit', false ); ?>
	</p>
</form>
