<?php
/**
 * admin/views/tools-emails.php
 * Aba de E-mails na tela de Ferramentas.
 *
 * @package VitPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'hub_settings', array() );
$ses      = isset( $settings['ses'] ) ? $settings['ses'] : array();

$enabled    = ! empty( $ses['enabled'] );
$host       = isset( $ses['host'] ) ? $ses['host'] : 'email-smtp.sa-east-1.amazonaws.com';
$port       = isset( $ses['port'] ) ? $ses['port'] : '587';
$secure     = isset( $ses['secure'] ) ? $ses['secure'] : 'tls';
$username   = isset( $ses['username'] ) ? $ses['username'] : '';
$password   = isset( $ses['password'] ) ? $ses['password'] : '';
$from_email = isset( $ses['from_email'] ) ? $ses['from_email'] : get_option( 'admin_email' );
$from_name  = isset( $ses['from_name'] ) ? $ses['from_name'] : get_bloginfo( 'name' );

?>
<div class="card" style="max-width: 800px; padding: 20px;">
	<h2>Configuração Amazon SES</h2>
	<p class="description">
		Configure as credenciais SMTP do Amazon SES. Quando ativado, todos os e-mails enviados pelo VitPress (e pelo WordPress) utilizarão esta conexão de forma nativa.
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="hub_save_ses">
		<?php wp_nonce_field( 'hub_ses_nonce', 'hub_nonce' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row">Status</th>
				<td>
					<label>
						<input type="checkbox" name="ses[enabled]" value="1" <?php checked( $enabled ); ?>>
						Habilitar envio via Amazon SES
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ses_host">Servidor SMTP</label></th>
				<td>
					<input name="ses[host]" type="text" id="ses_host" value="<?php echo esc_attr( $host ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ses_port">Porta</label></th>
				<td>
					<input name="ses[port]" type="number" id="ses_port" value="<?php echo esc_attr( $port ); ?>" class="small-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ses_secure">Criptografia (TLS/SSL)</label></th>
				<td>
					<select name="ses[secure]" id="ses_secure">
						<option value="tls" <?php selected( $secure, 'tls' ); ?>>TLS (Recomendado para porta 587)</option>
						<option value="ssl" <?php selected( $secure, 'ssl' ); ?>>SSL (Recomendado para porta 465)</option>
						<option value="" <?php selected( $secure, '' ); ?>>Nenhuma</option>
					</select>
					<p class="description">Se você estiver utilizando a porta <strong>587</strong>, selecione <strong>TLS</strong>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ses_username">Access Key ID (ou Usuário SMTP)</label></th>
				<td>
					<input name="ses[username]" type="text" id="ses_username" value="<?php echo esc_attr( $username ); ?>" class="regular-text" placeholder="Ex: AKIAIOSFODNN7EXAMPLE">
					<p class="description">Você pode colar diretamente seu IAM Access Key ID.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ses_password">Secret Access Key (ou Senha SMTP)</label></th>
				<td>
					<input name="ses[password]" type="password" id="ses_password" value="<?php echo esc_attr( $password ); ?>" class="regular-text" placeholder="Ex: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY">
					<p class="description">Você pode colar diretamente seu IAM Secret Access Key. O VitPress fará a conversão automática para Senha SMTP em background.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ses_from_email">E-mail Remetente (From)</label></th>
				<td>
					<input name="ses[from_email]" type="email" id="ses_from_email" value="<?php echo esc_attr( $from_email ); ?>" class="regular-text" placeholder="Ex: suporte@vcsis.com.br">
					<p class="description"><strong>Importante:</strong> Deve ser um e-mail ou domínio previamente verificado na sua conta Amazon SES (ex: <code>suporte@vcsis.com.br</code>). Contas gratuitas do Gmail/Hotmail não são aceitas como remetente pela Amazon.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ses_from_name">Nome do Remetente</label></th>
				<td>
					<input name="ses[from_name]" type="text" id="ses_from_name" value="<?php echo esc_attr( $from_name ); ?>" class="regular-text" placeholder="Ex: VitAgência / VCSIS">
				</td>
			</tr>
		</table>

		<p class="submit" style="display: flex; gap: 15px; align-items: center; max-width: 800px; flex-wrap: wrap;">
			<button type="submit" name="submit_action" value="save" class="button button-primary">Salvar Configurações</button>
			
			<span style="border-left: 1px solid #ccc; height: 30px;"></span>
			
			<?php $last_test_to = get_transient( 'hub_ses_last_test_to' ) ?: wp_get_current_user()->user_email; ?>
			<input type="email" name="ses_test_to" value="<?php echo esc_attr( $last_test_to ); ?>" class="regular-text" placeholder="E-mail de destino do teste">
			<button type="submit" name="submit_action" value="test" class="button button-secondary">Enviar E-mail de Teste</button>
		</p>
	</form>
</div>

<?php 
$s3 = $settings['s3'] ?? []; 
$s3_enabled = ! empty( $s3['enabled'] );
$s3_access  = $s3['access_key'] ?? '';
$s3_secret  = $s3['secret_key'] ?? '';
$s3_region  = $s3['region'] ?? 'sa-east-1';
$s3_bucket  = $s3['bucket'] ?? '';
$retention_db = $s3['retention_db'] ?? 30;
$retention_full = $s3['retention_full'] ?? 4;

$display_secret = !empty($s3_secret) ? str_repeat('*', 36) . substr($s3_secret, -4) : '';
?>
<div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
	<h2>Integração Amazon S3 (Backups Automáticos)</h2>
	<p class="description">
		Configure o envio automático de backups para o seu bucket no Amazon S3. Quando ativado, o VitPress fará o backup do banco de dados <strong>diariamente</strong> e o backup completo <strong>semanalmente</strong>.
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="hub_save_s3_backup">
		<?php wp_nonce_field( 'hub_s3_backup_nonce', 'hub_nonce' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row">Status</th>
				<td>
					<label>
						<input type="checkbox" name="s3[enabled]" value="1" <?php checked( $s3_enabled ); ?>>
						Habilitar rotinas de backup automático para o S3
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="s3_access">Chave de Acesso (Access Key)</label></th>
				<td>
					<input name="s3[access_key]" type="text" id="s3_access" value="<?php echo esc_attr( $s3_access ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="s3_secret">Chave Secreta (Secret Key)</label></th>
				<td>
					<input name="s3[secret_key]" type="password" id="s3_secret" value="<?php echo esc_attr( $display_secret ); ?>" class="regular-text" placeholder="Deixe em branco para manter a atual">
					<p class="description">A chave secreta não é exibida integralmente por motivos de segurança.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="s3_region">Região</label></th>
				<td>
					<input name="s3[region]" type="text" id="s3_region" value="<?php echo esc_attr( $s3_region ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="s3_bucket">Bucket</label></th>
				<td>
					<input name="s3[bucket]" type="text" id="s3_bucket" value="<?php echo esc_attr( $s3_bucket ); ?>" class="regular-text" placeholder="ex: bkps-sites-vcsis">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="s3_retention_db">Retenção de BD</label></th>
				<td>
					Manter os últimos <input name="s3[retention_db]" type="number" id="s3_retention_db" value="<?php echo esc_attr( $retention_db ); ?>" class="small-text"> backups de Banco de Dados armazenados na Amazon S3.
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="s3_retention_full">Retenção Completo</label></th>
				<td>
					Manter os últimos <input name="s3[retention_full]" type="number" id="s3_retention_full" value="<?php echo esc_attr( $retention_full ); ?>" class="small-text"> backups Completos armazenados na Amazon S3.
				</td>
			</tr>
		</table>

		<p class="submit" style="display: flex; gap: 15px; align-items: center; max-width: 800px; flex-wrap: wrap;">
			<button type="submit" name="submit_action" value="save" class="button button-primary">Salvar Configurações S3</button>
			<span style="border-left: 1px solid #ccc; height: 30px;"></span>
			<button type="submit" name="submit_action" value="test" class="button button-secondary">Testar Conexão S3</button>
		</p>
	</form>

	<hr>
	<h3>Diagnóstico do Worker</h3>
	<?php
	$job_state = get_option( 'hub_backup_job_state', false );
	if ( $job_state ) :
		$status = $job_state['status'] ?? 'N/A';
		$color = 'black';
		if ($status === 'done') $color = 'green';
		if ($status === 'error') $color = 'red';
		if (in_array($status, ['uploading', 'pending_generation', 'init_upload', 'completing'])) $color = 'orange';
	?>
		<p><strong>Último Job:</strong> <?php echo esc_html($job_state['type'] ?? ''); ?></p>
		<p><strong>Status:</strong> <span style="color: <?php echo $color; ?>; font-weight: bold;"><?php echo esc_html($status); ?></span></p>
		<p><strong>Atualizado em:</strong> <?php echo esc_html($job_state['updated_at'] ?? $job_state['start'] ?? 'N/A'); ?></p>
		<?php if ( ! empty($job_state['error']) ) : ?>
			<p style="color:red;"><strong>Último Erro:</strong> <?php echo esc_html($job_state['error']); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<p>Nenhum job de backup registrado ainda.</p>
	<?php endif; ?>
</div>
