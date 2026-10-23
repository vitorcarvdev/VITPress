<?php
/**
 * admin/views/vitagencia-backup.php
 * Aba de Backup e Migração na Área VitAgência.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$backup_service = new \Hub\Core\Services\BackupService();
$legacy_backups = $backup_service->get_legacy_backups();
?>
<div class="card" style="max-width: 800px; padding: 20px;">
	<h2>Backup e Migração</h2>
	<p class="description">
		O Hub não armazena backups persistentes neste servidor devido às políticas de segurança e limite de disco. 
		Todas as operações abaixo geram arquivos temporários que são excluídos logo após o download.
	</p>

	<?php if ( isset( $_GET['message'] ) && 'restored' === $_GET['message'] ) : ?>
		<div class="notice notice-success inline"><p>Restauração concluída com sucesso!</p></div>
	<?php endif; ?>
	
	<?php if ( isset( $_GET['message'] ) && 'legacy_deleted' === $_GET['message'] ) : ?>
		<div class="notice notice-success inline"><p>Backups antigos removidos com sucesso.</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['message'] ) && 's3_saved' === $_GET['message'] ) : ?>
		<div class="notice notice-success inline"><p>Configurações do Amazon S3 salvas com sucesso.</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['message'] ) && 's3_success' === $_GET['message'] ) : ?>
		<div class="notice notice-success inline"><p>Teste de Conexão S3 realizado com sucesso! As credenciais estão corretas.</p></div>
	<?php endif; ?>

	<?php if ( ! empty( $_GET['error'] ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $legacy_backups ) ) : ?>
		<div class="notice notice-warning inline" style="padding: 15px; background: #fff8e5; border-left: 4px solid #f0b849; margin-bottom: 20px;">
			<h3 style="margin-top: 0;">Atenção: Backups Legados Encontrados</h3>
			<p>Existem <strong><?php echo count( $legacy_backups ); ?></strong> backups antigos armazenados neste servidor gerados por versões anteriores. A política atual não permite a permanência desses arquivos.</p>
			
			<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
				<thead>
					<tr>
						<th>Data</th>
						<th>Arquivo</th>
						<th>Tamanho</th>
						<th>Ações</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $legacy_backups as $lb ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', $lb['date'] ) ); ?></td>
							<td><?php echo esc_html( $lb['name'] ); ?></td>
							<td><?php echo esc_html( $lb['size'] ); ?></td>
							<td>
								<div style="display: flex; gap: 5px;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="hub_download_legacy">
										<input type="hidden" name="file_name" value="<?php echo esc_attr( $lb['name'] ); ?>">
										<?php wp_nonce_field( 'hub_legacy_action', 'hub_nonce' ); ?>
										<button type="submit" class="button button-small">Baixar</button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Tem certeza?');">
										<input type="hidden" name="action" value="hub_delete_legacy">
										<input type="hidden" name="file_name" value="<?php echo esc_attr( $lb['name'] ); ?>">
										<?php wp_nonce_field( 'hub_legacy_action', 'hub_nonce' ); ?>
										<button type="submit" class="button button-small button-link-delete" style="color:#b32d2e;">Excluir</button>
									</form>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Apagar TODOS os backups legados?');" style="margin-top: 10px;">
				<input type="hidden" name="action" value="hub_delete_legacy">
				<input type="hidden" name="file_name" value="ALL">
				<?php wp_nonce_field( 'hub_legacy_action', 'hub_nonce' ); ?>
				<button type="submit" class="button button-secondary">Excluir Todos os Arquivos Antigos</button>
			</form>
		</div>
	<?php endif; ?>

	<hr style="margin: 30px 0;">

	<h3>BACKUP COMPLETO</h3>
	<p>Gere uma cópia completa do site para migração ou segurança.<br>O arquivo <code>.zip</code> é temporário e removido automaticamente após o download.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" onsubmit="setTimeout(function(){window.location.reload();}, 3000);">
		<input type="hidden" name="action" value="hub_run_backup_full">
		<?php wp_nonce_field( 'hub_run_backup', 'hub_nonce' ); ?>
		<button type="submit" class="button button-primary">Gerar e Baixar Backup Completo (.zip)</button>
	</form>

	<hr style="margin: 30px 0;">

	<h3>BANCO DE DADOS</h3>
	<p>Gere manualmente uma cópia do banco de dados.<br>O arquivo não permanece armazenado no servidor.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" onsubmit="setTimeout(function(){window.location.reload();}, 3000);">
		<input type="hidden" name="action" value="hub_run_backup_db">
		<?php wp_nonce_field( 'hub_run_backup', 'hub_nonce' ); ?>
		<button type="submit" class="button button-primary">Gerar e Baixar Banco de Dados</button>
	</form>

	<hr style="margin: 30px 0;">

	<h3>RESTAURAR / MIGRAR SITE</h3>
	<p>Importe um <code>.zip</code> completo criado pelo VitPress (arquivos e banco de dados).</p>
	<p class="description">Backups antigos do Hub VitAgência que contêm só a pasta <code>wp-content</code>, sem banco de dados, podem restaurar apenas os arquivos: o banco de dados atual é mantido e você confirma antes de começar. Outros formatos antigos não são compatíveis.</p>
	<form id="hub-chunked-upload-form" enctype="multipart/form-data" data-chunk-size="<?php echo esc_attr( \Hub\Core\Services\BackupService::chunk_size() ); ?>">
		<?php wp_nonce_field( 'hub_upload_backup', 'hub_nonce_upload' ); ?>
		<input type="file" id="hubbackup_file" accept=".zip" required style="margin-bottom: 10px; display: block;">
		<button type="submit" id="hub-btn-upload-chunked" class="button button-secondary">Restaurar Site (Upload Seguro)</button>
		<div id="hub-upload-progress-container" style="display:none; margin-top: 15px; background:#eee; height:20px; width:100%; border-radius:10px; overflow:hidden;">
			<div id="hub-upload-progress-bar" style="background:#2271b1; height:100%; width:0%; transition: width 0.1s;"></div>
		</div>
		<p id="hub-upload-status-text" style="display:none; font-weight:bold; color:#2271b1;"></p>
	</form>

	<script>
	jQuery(document).ready(function($) {
		$('#hub-chunked-upload-form').on('submit', async function(e) {
			e.preventDefault();
			var fileInput = document.getElementById('hubbackup_file');
			if (!fileInput.files.length) return;
			
			var file = fileInput.files[0];
			var chunkSize = parseInt($(this).data('chunk-size'), 10) || (1024 * 1024);
			var totalChunks = Math.ceil(file.size / chunkSize);
			var nonce = $('#hub_nonce_upload').val();
			
			$('#hub-btn-upload-chunked').prop('disabled', true).text('Enviando...');
			$('#hub-upload-progress-container').show();
			var $status = $('#hub-upload-status-text').show().text('Enviando 0%');
			var $bar = $('#hub-upload-progress-bar');
			
			for (var i = 0; i < totalChunks; i++) {
				var start = i * chunkSize;
				var end = Math.min(start + chunkSize, file.size);
				var chunk = file.slice(start, end);
				
				var formData = new FormData();
				formData.append('action', 'hub_upload_backup_chunk');
				formData.append('hub_nonce', nonce);
				formData.append('chunk', chunk);
				formData.append('chunk_index', i);
				formData.append('total_chunks', totalChunks);
				formData.append('filename', file.name);
				
				try {
					var response = await $.ajax({
						url: ajaxurl,
						type: 'POST',
						data: formData,
						processData: false,
						contentType: false
					});
					
					if (!response.success) {
						alert('Erro no envio: ' + (response.data || 'Falha desconhecida.'));
						$('#hub-btn-upload-chunked').prop('disabled', false).text('Restaurar Site (Upload Seguro)');
						return;
					}
					
					var percent = Math.round(((i + 1) / totalChunks) * 100);
					$bar.css('width', percent + '%');
					
					if (i === totalChunks - 1) {
						$status.text('Verificando integridade e preparando restauração...');
						if (response.data && response.data.redirect) {
							window.location.href = response.data.redirect;
						}
					} else {
						$status.text('Enviando ' + percent + '%');
					}
					
				} catch (err) {
					alert('Erro de conexão ao enviar o arquivo.');
					$('#hub-btn-upload-chunked').prop('disabled', false).text('Restaurar Site (Upload Seguro)');
					return;
				}
			}
		});
	});
	</script>
	
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
		Configure o envio automático de backups para o seu bucket no Amazon S3. Quando ativado, o Hub fará o backup do banco de dados <strong>diariamente</strong> e o backup completo <strong>semanalmente</strong>.
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

<?php
$restore_status     = $backup_service->restore_status();
$restore_info       = ( ! $restore_status && isset( $_GET['restore_file'] ) ) ? $backup_service->inspect_restore_file( sanitize_text_field( wp_unslash( $_GET['restore_file'] ) ) ) : null;
$restore_files_only = $restore_info && $restore_info['ok'] && \Hub\Core\Services\Backup\BackupArchiveValidator::FORMAT_LEGACY_CONTENT === $restore_info['format'];
$restore_labels     = array(
	'database' => 'Preparar banco de dados',
	'switch'   => 'Aplicar restauração',
	'confirm'  => 'Confirmar restauração',
	'revert'   => 'Reverter para o site anterior',
	'discard'  => 'Descartar restauração',
);
$restore_phases     = array(
	'starting'      => 'Extraindo o backup.',
	'prepared'      => 'Backup extraído e conferido. O site ainda não foi alterado.',
	'staged'        => 'Banco de dados preparado em tabelas temporárias. O site ainda não foi alterado.',
	'switched'      => 'Restauração aplicada. As cópias antigas de arquivos e tabelas estão guardadas até você confirmar ou reverter.',
	'revert_failed' => 'A reversão falhou. Nada foi apagado; tente reverter novamente.',
);
?>
<?php if ( $restore_status ) : ?>
<div class="card" id="hub-restore-panel" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #d63638;">
	<h2>Restauração em andamento</h2>
	<p><?php echo esc_html( $restore_phases[ $restore_status['phase'] ] ?? 'Estado desconhecido.' ); ?></p>
	<?php if ( $restore_status['interrupted'] ) : ?>
		<div class="notice notice-warning inline"><p>A etapa "<?php echo esc_html( $restore_status['interrupted'] ); ?>" foi interrompida antes de terminar. Escolha a ação de recuperação abaixo; nada será retomado automaticamente.</p></div>
	<?php elseif ( ! $restore_status['allowed'] ) : ?>
		<div class="notice notice-info inline"><p>Uma etapa desta restauração está em execução em outra aba ou requisição. Aguarde e recarregue a página.</p></div>
	<?php endif; ?>
	<?php if ( '' !== $restore_status['last_error'] ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $restore_status['last_error'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( $restore_status['deferred'] ) : ?>
		<p class="description">Não ativados por segurança (guardados na pasta protegida de backups): <?php echo esc_html( implode( ', ', $restore_status['deferred'] ) ); ?>.</p>
	<?php endif; ?>
	<?php if ( ! empty( $restore_status['object_cache'] ) ) : ?>
		<div class="notice notice-warning inline"><p>Este site usa cache de objetos persistente (object-cache.php). O link de recuperação só confirma ou reverte com esse cache desativado: se precisar dele, renomeie wp-content/object-cache.php antes e limpe o cache pela hospedagem antes de reativá-lo.</p></div>
	<?php endif; ?>
	<?php if ( in_array( 'switch', $restore_status['allowed'], true ) ) : ?>
		<p class="description">Antes de aplicar, você recebe um link de recuperação. Guarde-o: ele confirma ou reverte a restauração sem login e funciona mesmo com o site em manutenção ou com algum plugin do backup quebrado. Depois da troca, os usuários e senhas passam a ser os do backup.</p>
	<?php endif; ?>
	<p>
		<?php foreach ( $restore_status['allowed'] as $restore_action ) : ?>
			<button type="button" class="button <?php echo in_array( $restore_action, array( 'switch', 'confirm' ), true ) ? 'button-primary' : 'button-secondary'; ?> hub-restore-action" data-action="<?php echo esc_attr( $restore_action ); ?>"><?php echo esc_html( $restore_labels[ $restore_action ] ?? $restore_action ); ?></button>
		<?php endforeach; ?>
		<?php if ( 'switched' === $restore_status['phase'] && ! $restore_status['interrupted'] && in_array( 'confirm', $restore_status['allowed'], true ) ) : ?>
			<button type="button" class="button button-secondary hub-restore-action" data-action="recovery_link">Gerar novo link de recuperação</button>
		<?php endif; ?>
	</p>
	<p id="hub-restore-link"></p>
	<p id="hub-restore-msg" style="font-weight: bold;"></p>
</div>
<?php elseif ( $restore_info && ! $restore_info['ok'] ) : ?>
<div class="notice notice-error inline" style="max-width: 800px;"><p><?php echo esc_html( $restore_info['error'] ); ?></p></div>
<?php elseif ( $restore_info ) : ?>
<div class="card" id="hub-restore-panel" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #2271b1;">
	<h2>Backup pronto para restauração</h2>
	<p><?php echo $restore_files_only ? 'Backup antigo do Hub VitAgência: somente arquivos de wp-content serão restaurados. O banco de dados atual será mantido.' : 'Backup completo do VitPress (arquivos e banco de dados). Nada será alterado até você aplicar a restauração.'; ?></p>
	<p><button type="button" class="button button-primary hub-restore-action" data-action="start">Iniciar restauração</button></p>
	<p id="hub-restore-msg" style="font-weight: bold;"></p>
</div>
<?php endif; ?>

<?php if ( $restore_status || ( $restore_info && $restore_info['ok'] ) ) : ?>
<script>
jQuery(document).ready(function($) {
	var nonce = '<?php echo esc_js( wp_create_nonce( 'hub_restore_chunk' ) ); ?>';
	var file = '<?php echo esc_js( $restore_info ? basename( sanitize_text_field( wp_unslash( $_GET['restore_file'] ) ) ) : '' ); ?>';
	var filesOnly = <?php echo $restore_files_only ? 'true' : 'false'; ?>;
	var panelUrl = '<?php echo esc_js( admin_url( 'admin.php?page=hub-vitagencia&tab=backup' ) ); ?>';
	var confirms = {
		start: filesOnly ? 'Este é um backup antigo do Hub VitAgência sem banco de dados.\n\nSerão restaurados SOMENTE os arquivos de wp-content (plugins, temas, uploads). O banco de dados atual NÃO será alterado.\n\nDeseja continuar?' : '',
		'switch': 'Aplicar a restauração agora?\n\nArquivos e banco de dados serão trocados pelos do backup. As cópias antigas ficam guardadas até você confirmar ou reverter.',
		revert: 'Reverter para o site anterior?',
		discard: 'Descartar esta restauração? O site atual não será alterado.',
		confirm: 'Confirmar a restauração? As cópias antigas de arquivos e tabelas serão apagadas definitivamente.'
	};
	var $msg = $('#hub-restore-msg');
	var $link = $('#hub-restore-link');

	function send(step) {
		return $.post(ajaxurl, {
			action: 'hub_restore_chunk',
			hub_nonce: nonce,
			step: step,
			file: file,
			confirm_files_only: filesOnly ? 1 : 0
		});
	}

	function showError(text) {
		$msg.css('color', '#d63638').text('Erro: ' + text);
		$('<a class="button" style="margin-left:10px;">Atualizar estado</a>').attr('href', panelUrl).appendTo($msg);
	}

	function showLink(url) {
		var $input = $('<input type="text" readonly class="large-text code" id="hub-restore-link-url">').val(url).on('focus', function() { this.select(); });
		var $status = $('<span id="hub-restore-link-copied" style="margin-left:8px;">');
		var $copy = $('<button type="button" class="button" id="hub-restore-link-copy">Copiar link</button>').on('click', function() {
			var done = function() { $status.text('Link copiado.'); };
			var fallback = function() {
				$input.trigger('focus');
				$input[0].select();
				if (document.execCommand && document.execCommand('copy')) { done(); } else { $status.text('Selecione o link e copie manualmente.'); }
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(url).then(done, fallback);
			} else {
				fallback();
			}
		});
		$link.empty()
			.append($('<strong>').text('Link de recuperação (guarde antes de continuar): '))
			.append($input)
			.append($('<span>').append($copy).append($status))
			.append($('<span class="description">').text(' Funciona sem login, com o site em manutenção e mesmo se um plugin do backup quebrar o site. Vale até a confirmação ou a reversão.'));
	}

	function runStep(action) {
		$msg.css('color', '#2271b1').text('Processando... não feche esta página.');
		send(action).done(function(response) {
			if (!response || !response.success) {
				showError(response && response.data ? response.data : 'falha desconhecida.');
				return;
			}
			var data = response.data || {};
			if (data.phase === 'switched' && !data.closed) {
				$msg.css('color', '#1d2327').empty()
					.append($('<span>').text('Restauração aplicada. Os usuários agora são os do backup. Para confirmar ou reverter, use o link de recuperação acima ou '))
					.append($('<a>').attr('href', data.login_url).text('entre no site restaurado'));
				return;
			}
			window.location.href = panelUrl + (data.closed ? '&message=restored' : '');
		}).fail(function(jqXHR) {
			$msg.css('color', '#d63638').text('A requisição não terminou (HTTP ' + jqXHR.status + '). A etapa pode continuar no servidor. Se o site não abrir, use o link de recuperação; senão, recarregue a página para ver o estado atual antes de qualquer outra ação.');
			$('<a class="button" style="margin-left:10px;">Atualizar estado</a>').attr('href', panelUrl).appendTo($msg);
		});
	}

	$('.hub-restore-action').on('click', function() {
		var action = $(this).data('action');
		if (confirms[action] && action !== 'switch' && !window.confirm(confirms[action])) {
			return;
		}
		$('.hub-restore-action').prop('disabled', true);

		if (action === 'switch' || action === 'recovery_link') {
			$msg.css('color', '#2271b1').text('Gerando o link de recuperação...');
			send('recovery_link').done(function(response) {
				if (!response || !response.success || !response.data.recovery_url) {
					showError(response && response.data ? response.data : 'não foi possível gerar o link de recuperação.');
					return;
				}
				showLink(response.data.recovery_url);
				if (action === 'recovery_link') {
					$msg.text('');
					$('.hub-restore-action').prop('disabled', false);
					return;
				}
				$msg.text('');
				if (!window.confirm('Copie e guarde o link de recuperação exibido no painel.\n\n' + confirms['switch'])) {
					$('.hub-restore-action').prop('disabled', false);
					return;
				}
				runStep('switch');
			}).fail(function(jqXHR) {
				showError('HTTP ' + jqXHR.status + ' ao gerar o link de recuperação.');
			});
			return;
		}
		runStep(action);
	});
});
</script>
<?php endif; ?>
