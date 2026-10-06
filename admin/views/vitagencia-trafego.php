<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'hub_save_vitagencia', 'hub_nonce' ); ?>
	<input type="hidden" name="action" value="hub_save_vitagencia">
	<input type="hidden" name="vitagencia_tab" value="trafego">
	
	<div class="card" style="max-width: 800px; padding: 20px;">
		<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ccc; padding-bottom: 15px; margin-bottom: 15px;">
			<h2 style="margin: 0;">Página de Conversão Limpa</h2>
			<div id="hub_page_generator_wrapper">
				<?php if ( $page_exists ) : ?>
					<span style="color: #28a745; font-weight: bold; margin-right: 15px;">✅ Página de Conversão Criada</span>
					<a href="<?php echo esc_url( home_url( '/novo-lead' ) ); ?>" target="_blank" class="button button-secondary">[ Abrir Página ]</a>
				<?php else : ?>
					<button type="button" id="hub_btn_generate_page" class="button button-primary">✨ Gerar Página de Conversão</button>
					<span class="spinner" id="hub_page_spinner" style="float: none; margin-top: 0;"></span>
				<?php endif; ?>
			</div>
		</div>

		<h2>Formulário de Captação</h2>
		<p>Utilize o formulário oficial do VitPress para capturar leads. As conversões de mídia seguem pelo VitAds.</p>
		
		<div style="background: #f0f0f1; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
			<div>
				<strong>Shortcode do Formulário:</strong><br>
				<code id="hub_shortcode_text" style="font-size: 16px; padding: 5px 10px; display: inline-block; margin-top: 5px;">[hub_form]</code>
			</div>
			<button type="button" class="button" onclick="navigator.clipboard.writeText('[hub_form]'); alert('Copiado para a área de transferência!');">Copiar</button>
		</div>

		<h3 style="margin-top: 30px;">Configurações de Redirecionamento e Notificações</h3>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="form_whatsapp">WhatsApp de Destino</label></th>
				<td>
					<input type="text" name="forms[whatsapp]" id="form_whatsapp" class="regular-text" value="<?php echo esc_attr( $whatsapp ); ?>" placeholder="Ex: 5511999999999" pattern="\d+" title="Apenas números">
					<p class="description">Somente números, incluindo o código do país (ex: 55).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="form_whatsapp_msg">Mensagem Automática</label></th>
				<td>
					<input type="text" name="forms[whatsapp_msg]" id="form_whatsapp_msg" class="large-text" value="<?php echo esc_attr( $whatsapp_msg ); ?>">
					<p class="description">Variáveis permitidas: <code>{nome}</code></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="form_receive_email">Receber Leads em (E-mail Interno)</label></th>
				<td>
					<input type="email" name="forms[receive_email]" id="form_receive_email" class="regular-text" value="<?php echo esc_attr( $receive_email ); ?>">
					<p class="description">E-mail da equipe que será notificado a cada novo lead capturado.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Confirmação para o Visitante</th>
				<td>
					<label>
						<input type="checkbox" name="forms[send_confirm]" value="1" <?php checked( $send_confirm ); ?>>
						Enviar confirmação automática para o e-mail do lead após o cadastro.
					</label>
				</td>
			</tr>
		</table>

		<h3 style="margin-top: 40px;">Depoimento na Página de Conversão</h3>
		<table class="form-table">
			<tr>
				<th scope="row">Exibir Depoimento?</th>
				<td>
					<label>
						<input type="checkbox" name="forms[show_testimonial]" value="1" <?php checked( $show_testimonial ); ?>>
						Mostrar um depoimento (5 estrelas fixas) logo abaixo do formulário na página `/novo-lead`.
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="form_testimonial_name">Nome do Cliente</label></th>
				<td>
					<input type="text" name="forms[testimonial_name]" id="form_testimonial_name" class="regular-text" value="<?php echo esc_attr( $testimonial_name ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="form_testimonial_text">Texto do Depoimento</label></th>
				<td>
					<textarea name="forms[testimonial_text]" id="form_testimonial_text" rows="3" class="large-text"><?php echo esc_textarea( $testimonial_text ); ?></textarea>
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
<script>
jQuery(document).ready(function($) {
	$('#hub_btn_generate_page').on('click', function() {
		if (!confirm('Deseja gerar a página limpa de conversão em /novo-lead agora?')) return;
		var btn = $(this), spinner = $('#hub_page_spinner');
		btn.prop('disabled', true).text('Gerando...'); spinner.addClass('is-active');
		$.post(ajaxurl, { action: 'hub_generate_page', hub_nonce: '<?php echo wp_create_nonce("hub_generate_page_nonce"); ?>' }, function(r) {
			spinner.removeClass('is-active');
			if(r.success) $('#hub_page_generator_wrapper').html('<span style="color:#28a745;font-weight:bold;margin-right:15px;">✅ Criada</span> <a href="'+r.data.url+'" target="_blank" class="button button-secondary">[ Abrir Página ]</a>');
			else { alert(r.data.message || 'Erro'); btn.prop('disabled', false).text('✨ Gerar Página'); }
		}).fail(function() { spinner.removeClass('is-active'); alert('Erro'); btn.prop('disabled', false).text('✨ Gerar Página'); });
	});
});
</script>
