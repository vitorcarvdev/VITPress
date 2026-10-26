<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap hub-wrap">
	<h1 class="wp-heading-inline">Pedir Suporte</h1>
	<hr class="wp-header-end">

	<?php if ( isset( $_GET['message'] ) && 'sent' === $_GET['message'] ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><strong>Chamado enviado com sucesso!</strong> Nossa equipe de suporte da agência recebeu sua solicitação.</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['error'] ) ) : ?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php
				if ( 'empty_fields' === $_GET['error'] ) {
					echo 'Por favor, preencha o assunto e a descrição do chamado.';
				} else {
					echo 'Ocorreu um erro ao enviar a mensagem por e-mail. Verifique as configurações de SMTP do servidor.';
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="card" style="max-width: 800px; margin-top: 20px;">
		<h2>Abertura de Chamado para Agência</h2>
		<p class="description">
			Envie sua solicitação diretamente para a equipe técnica da agência. Todas as informações do seu ambiente serão anexadas automaticamente.
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hub_send_support">
			<?php wp_nonce_field( 'hub_send_support_nonce', 'hub_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="category">Setor / Categoria *</label></th>
					<td>
						<select name="category" id="category" class="regular-text" required>
							<option value="site">Site / Ajustes no WordPress</option>
							<option value="marketing">Marketing / Tráfego / Campanhas</option>
						</select>
					</td>
				</tr>
						
				<tr id="row_ticket_type_site">
					<th scope="row"><label for="ticket_type_site">Tipo de Atendimento *</label></th>
					<td>
						<select name="ticket_type_site" id="ticket_type_site" class="regular-text">
							<option value="Ajuste">Ajuste</option>
							<option value="Erro">Erro</option>
							<option value="Novo recurso">Novo recurso</option>
							<option value="Outro">Outro</option>
						</select>
					</td>
				</tr>

				<tr id="row_ticket_type_marketing" style="display: none;">
					<th scope="row"><label for="ticket_type_marketing">Tipo de Atendimento *</label></th>
					<td>
						<select name="ticket_type_marketing" id="ticket_type_marketing" class="regular-text">
							<option value="Google Ads">Google Ads</option>
							<option value="Meta Ads">Meta Ads</option>
							<option value="Landing Page">Landing Page</option>
							<option value="Conversões">Conversões</option>
							<option value="Outro">Outro</option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="subject">Assunto *</label></th>
					<td>
						<input name="subject" type="text" id="subject" class="regular-text" placeholder="Ex: Solicitação de ajuste na página de contato" required>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="description">Descrição Detalhada *</label></th>
					<td>
						<textarea name="description" id="description" rows="6" class="large-text" placeholder="Descreva com detalhes o que precisa ser feito..." required></textarea>
					</td>
				</tr>
			</table>

			<div class="hub-telemetry-notice">
				<span class="dashicons dashicons-info"></span>
				<strong>Informações que serão enviadas automaticamente no chamado:</strong>
				<ul>
					<li>Empresa: <em><?php echo esc_html( get_bloginfo( 'name' ) ); ?></em> (<?php echo esc_html( home_url() ); ?>)</li>
					<li>Responsável: <em><?php echo esc_html( wp_get_current_user()->display_name ); ?></em></li>
					<li>Perfil Escolhido: <em><?php echo esc_html( $profile ? $profile->get_name() : 'Não definido' ); ?></em></li>
					<li>Versões do Sistema: VitPress v<?php echo esc_html( HUB_VERSION ); ?>, WordPress v<?php echo esc_html( $GLOBALS['wp_version'] ); ?>, PHP v<?php echo esc_html( PHP_VERSION ); ?></li>
				</ul>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary button-large">Enviar Chamado para a Agência</button>
			</p>
		</form>
	</div>
</div>
<script>
jQuery(document).ready(function($) {
	$('#category').on('change', function() {
		if ($(this).val() === 'site') {
			$('#row_ticket_type_site').show();
			$('#ticket_type_site').prop('required', true);
			$('#row_ticket_type_marketing').hide();
			$('#ticket_type_marketing').prop('required', false);
		} else {
			$('#row_ticket_type_site').hide();
			$('#ticket_type_site').prop('required', false);
			$('#row_ticket_type_marketing').show();
			$('#ticket_type_marketing').prop('required', true);
		}
	}).trigger('change');
});
</script>
