<?php
/**
 * admin/views/tools-google-ads-export.php
 * View administrativa para exportação de vendas em CSV para o Google Ads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$export_service = new \Hub\Core\Services\GoogleAdsOfflineExportService();
$stats = $export_service->get_stats();

$pending_count = $stats['pending'];
$skipped_count = $stats['skipped'];
$recent_batches = $stats['recent_batches'];

// Recupera o nome da conversão nas configurações
$settings = get_option( 'hub_settings', array() );
$offline_settings = isset( $settings['integrations']['google_ads_offline'] ) ? $settings['integrations']['google_ads_offline'] : array();
$conversion_name = ! empty( $offline_settings['conversion_name'] ) ? $offline_settings['conversion_name'] : 'Compra offline';

?>
<div class="card" style="max-width: 800px; padding: 20px;">
	<h2>Exportar compras para Google Ads</h2>
	<p>Baixe o arquivo CSV formatado com as vendas recentes e faça o upload no painel do Google Ads. Isso permite que a inteligência artificial do Google entenda quais leads se tornaram clientes e com qual valor de receita.</p>
	
	<div class="notice notice-info inline">
		<p>
			<strong>Importante:</strong> As vendas são registradas aqui automaticamente quando você as marca como "Ganho" no Pipeline.<br>
			Apenas leads que possuem o <code>GCLID</code> rastreado (oriundos de anúncios) são contabilizados para exportação.
		</p>
	</div>

	<?php if ( isset( $_GET['message'] ) ) : ?>
		<?php if ( 'no_pending' === $_GET['message'] ) : ?>
			<div class="notice notice-warning inline"><p>Não há compras pendentes com GCLID válido para exportar no momento.</p></div>
		<?php elseif ( 'batch_not_found' === $_GET['message'] ) : ?>
			<div class="notice notice-error inline"><p>O lote solicitado não foi encontrado no banco de dados.</p></div>
		<?php endif; ?>
	<?php endif; ?>

	<div style="display: flex; gap: 20px; margin: 30px 0;">
		<div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; text-align: center;">
			<h3 style="margin-top: 0; font-size: 24px; color: #0073aa;"><?php echo esc_html( $pending_count ); ?></h3>
			<p style="margin-bottom: 0;"><strong>Compras Prontas para Exportar</strong></p>
			<p style="font-size: 12px; color: #666; margin-top: 5px;">Aguardando geração do CSV</p>
		</div>
		
		<div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; text-align: center;">
			<h3 style="margin-top: 0; font-size: 24px; color: #d63638;"><?php echo esc_html( $skipped_count ); ?></h3>
			<p style="margin-bottom: 0;"><strong>Vendas Sem GCLID (Skipped)</strong></p>
			<p style="font-size: 12px; color: #666; margin-top: 5px;">Orgânicas ou diretas, não exportáveis</p>
		</div>
	</div>

	<div style="background: #f0f0f1; border-left: 4px solid #2271b1; padding: 15px; margin-bottom: 25px;">
		<h4 style="margin: 0 0 10px 0;">Configuração Atual da Conversão</h4>
		<p style="margin: 0;">Nome da ação de conversão: <strong><?php echo esc_html( $conversion_name ); ?></strong></p>
		<p style="margin: 5px 0 0 0; font-size: 13px; color: #555;">
			<em>Aviso: Antes de gerar o CSV, certifique-se de que a ação "<?php echo esc_html( $conversion_name ); ?>" foi criada no Google Ads com a opção "Importar de cliques". O VitPress não consegue validar isso de forma offline.</em>
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="text-align: center;">
		<input type="hidden" name="action" value="hub_download_offline_conversions">
		<input type="hidden" name="export_action" value="pending">
		<?php wp_nonce_field( 'hub_export_offline_conversions', 'hub_nonce' ); ?>
		
		<button type="submit" class="button button-primary button-hero" <?php disabled( $pending_count, 0 ); ?>>
			<span class="dashicons dashicons-download" style="margin-top:4px;"></span> Baixar CSV de Compras Pendentes
		</button>
		<?php if ( $pending_count > 0 ) : ?>
			<p style="font-size: 13px; color: #666;">Isso gerará um novo lote e marcará estas vendas como exportadas.</p>
		<?php endif; ?>
	</form>

	<hr style="margin: 40px 0;">

	<h3><span class="dashicons dashicons-backup"></span> Histórico de Exportações (Lotes)</h3>
	<p>Se ocorrer algum erro durante a importação no Google Ads, você pode baixar um lote antigo novamente.</p>

	<?php if ( empty( $recent_batches ) ) : ?>
		<p style="color: #666;"><em>Nenhum lote foi exportado ainda.</em></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Data e Hora</th>
					<th>ID do Lote</th>
					<th>Total de Vendas</th>
					<th style="width: 150px;">Ação</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $recent_batches as $batch ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $batch['exported_at'] ) ) ); ?></td>
						<td><code><?php echo esc_html( $batch['export_batch_id'] ); ?></code></td>
						<td><?php echo esc_html( $batch['total_items'] ); ?> itens</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="hub_download_offline_conversions">
								<input type="hidden" name="export_action" value="reexport">
								<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch['export_batch_id'] ); ?>">
								<?php wp_nonce_field( 'hub_export_offline_conversions', 'hub_nonce' ); ?>
								<button type="submit" class="button button-small">Re-exportar CSV</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd;">
		<h4>Como enviar o arquivo no Google Ads</h4>
		<ol style="margin-left: 20px;">
			<li>Acesse sua conta do Google Ads.</li>
			<li>No menu superior, vá em <strong>Metas</strong> > <strong>Conversões</strong> > <strong>Uploads</strong>.</li>
			<li>Clique no botão azul de mais (+) e selecione a aba <strong>Uploads de conversão</strong>.</li>
			<li>Faça o upload do arquivo CSV gerado pelo VitPress.</li>
			<li>Clique em <strong>Aplicar</strong>. O Google exibirá os resultados e quais conversões foram contabilizadas.</li>
		</ol>
	</div>
</div>
