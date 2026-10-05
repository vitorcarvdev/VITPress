<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="hub-settings-grid">
	<!-- CARD: LICENÇA -->
	<div class="hub-card">
		<h2>Licenciamento Local v1.7</h2>
		<table class="form-table">
			<tr>
				<th>Status da Licença</th>
				<td>
					<?php if ( $license_status['is_valid'] ) : ?>
						<span style="color: green; font-weight: bold;">✔ Autorizada</span>
					<?php else : ?>
						<span style="color: red; font-weight: bold;">✖ Não Autorizada (<?php echo esc_html( $license_status['reason'] ); ?>)</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>Domínio Registrado</th>
				<td><?php echo esc_html( $license_status['domain'] ?? '-' ); ?></td>
			</tr>
			<tr>
				<th>UUID da Instalação</th>
				<td><code><?php echo esc_html( $license_status['uuid'] ?? '-' ); ?></code></td>
			</tr>
			<?php if ( $license_status['is_valid'] ) : ?>
			<tr>
				<th>Autorizado em</th>
				<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $license_status['authorized_at'] ) ) ); ?> por <?php echo esc_html( $license_status['authorized_by'] ); ?></td>
			</tr>
			<?php endif; ?>
		</table>

		<?php if ( ! $license_status['is_valid'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 15px;">
				<?php wp_nonce_field( 'hub_request_license', 'hub_nonce' ); ?>
				<input type="hidden" name="action" value="hub_request_license">
				<button type="submit" class="button button-primary">Solicitar Autorização por E-mail</button>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 15px;" onsubmit="return confirm('ATENÇÃO: Revogar a licença desativará imediatamente todos os recursos premium e o CRM. Confirma?');">
				<?php wp_nonce_field( 'hub_revoke_license', 'hub_nonce' ); ?>
				<input type="hidden" name="action" value="hub_revoke_license">
				<button type="submit" class="button button-link-delete" style="color: #d63638; text-decoration: none;">Revogar Licença Deste Site</button>
			</form>
		<?php endif; ?>
	</div>

	<!-- CARD: INTEGRAÇÃO VITZAP -->
	<div class="hub-card" style="margin-top: 20px;">
		<h2>Integração com VitZap</h2>
		<p class="description">Vincule as operações deste site (como o Captador de Leads) a uma licença VitZap específica.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hub_save_vitzap_integration', 'hub_nonce' ); ?>
			<input type="hidden" name="action" value="hub_save_vitzap_integration">

			<table class="form-table">
				<tr>
					<th><label for="vitzap_license_uuid">Licença Vinculada</label></th>
					<td>
						<?php
						global $wpdb;
						$table_lic = $wpdb->prefix . 'vitcore_vitzap_licenses';
						$current_tenant = get_option( 'vcsis_pipeline_default_tenant_id', '' );
						$licenses = [];

						// 1. Busca com o prefixo atual
						$table_found = $wpdb->get_var( "SHOW TABLES LIKE '{$table_lic}'" );

						// 2. Fallback resiliente caso o prefixo seja diferente
						if ( empty( $table_found ) ) {
							$table_found = $wpdb->get_var( "SHOW TABLES LIKE '%vitcore_vitzap_licenses'" );
							if ( ! empty( $table_found ) ) {
								$table_lic = $table_found;
							}
						}

						if ( ! empty( $table_found ) ) {
							$licenses = $wpdb->get_results( "SELECT uuid, cliente_nome, produto, status FROM `{$table_lic}` ORDER BY cliente_nome ASC" );
						}
						?>
						<select name="vitzap_license_uuid" id="vitzap_license_uuid" class="regular-text">
							<option value="">-- Modo Isolado (Sem Integração) --</option>
							<?php if ( ! empty( $licenses ) ) : ?>
								<?php foreach ( $licenses as $lic ) : ?>
									<?php
									$label = esc_html( $lic->cliente_nome . ' - ' . $lic->produto . ' (' . $lic->status . ')' );
									?>
									<option value="<?php echo esc_attr( $lic->uuid ); ?>" <?php selected( $current_tenant, $lic->uuid ); ?>>
										<?php echo $label; ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p class="description">Os novos leads captados neste site serão enviados para o VitZap vinculado a esta licença.</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary">Salvar Integração</button>
			</p>
		</form>
	</div>

	<!-- CARD: PIPELINE VITZAP -->
	<div class="hub-card" style="margin-top: 20px;">
		<h2>Pipeline / Indicadores Comerciais</h2>
		<?php $crm_enabled = isset( $settings['crm_enabled'] ) ? ! empty( $settings['crm_enabled'] ) : false; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hub_save_vitagencia', 'hub_nonce' ); ?>
			<input type="hidden" name="action" value="hub_save_vitagencia">
			<input type="hidden" name="vitagencia_tab" value="geral">
			<input type="hidden" name="hub_crm_toggle" value="1">

			<table class="form-table">
				<tr>
					<th scope="row">Pipeline integrado ao VitZap</th>
					<td>
						<label for="hub_crm_enabled">
							<input type="checkbox" name="hub_crm_enabled" id="hub_crm_enabled" value="1" <?php checked( $crm_enabled ); ?>>
							Ativar Pipeline integrado ao VitZap
						</label>
						<p class="description">Exibe no WordPress os indicadores e o pipeline sincronizado pelo VitZap. As ações comerciais continuam sendo realizadas no VitZap.</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary">Salvar Pipeline</button>
			</p>
		</form>
	</div>

	<!-- CARD: INTEGRAÇÃO VITADS -->
	<div class="hub-card" style="margin-top: 20px;">
		<h2>Integração com VitAds</h2>
		<?php
		$marketing_on = \Hub\Core\Services\Integrations\VitAdsMarketing::is_enabled();
		$marketing    = $marketing_on ? \Hub\Core\Services\Integrations\VitAdsMarketing::connection() : array( 'state' => 'off' );
		?>
		<?php
		$vitads          = isset( $settings['vitads'] ) && is_array( $settings['vitads'] ) ? $settings['vitads'] : array();
		$vitads_enabled  = ! empty( $vitads['enabled'] );
		$vitads_endpoint = ! empty( $vitads['endpoint'] ) ? $vitads['endpoint'] : 'https://vitads.vitagencia.com.br/api/v1/events';
		$vitads_segment  = isset( $vitads['routing_key'] ) ? $vitads['routing_key'] : '';
		$vitads_hint     = \Hub\Core\Services\Integrations\VitAdsEventService::token_hint( $vitads['token'] ?? '' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hub_save_vitads', 'hub_nonce' ); ?>
			<input type="hidden" name="action" value="hub_save_vitads">

			<table class="form-table">
				<tr>
					<th scope="row">Marketing e Pipeline</th>
					<td>
						<label for="marketing_pipeline_enabled">
							<input type="checkbox" name="marketing_pipeline_enabled" id="marketing_pipeline_enabled" value="1" <?php checked( $marketing_on ); ?>>
							Ativar Marketing e Pipeline
						</label>
						<p class="description">Ative esta opção quando este site participar de campanhas de marketing e do acompanhamento comercial de leads.</p>
						<?php if ( 'connected' === $marketing['state'] ) : ?>
							<p>
								<strong>● VitAds conectado</strong><br>
								Cliente: <?php echo esc_html( $marketing['client_name'] ?: '—' ); ?><br>
								Google Ads: <?php echo ! empty( $marketing['google_ads'] ) ? 'Configurado' : 'Não configurado'; ?><br>
								Meta Ads: <?php echo ! empty( $marketing['meta_ads'] ) ? 'Configurado' : 'Não configurado'; ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">VitAds</th>
					<td>
						<label for="vitads_enabled">
							<input type="checkbox" name="vitads_enabled" id="vitads_enabled" value="1" <?php checked( $vitads_enabled ); ?>>
							Ativar integração com VitAds
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vitads_endpoint">URL do VitAds</label></th>
					<td>
						<input type="url" class="regular-text" name="vitads_endpoint" id="vitads_endpoint" value="<?php echo esc_attr( $vitads_endpoint ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vitads_token">Token de conexão</label></th>
					<td>
						<input type="password" class="regular-text" name="vitads_token" id="vitads_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $vitads_hint ? 'Token salvo · ' . $vitads_hint : 'Cole o token do VitAds' ); ?>">
						<?php if ( $vitads_hint ) : ?>
							<p class="description">Token salvo · <?php echo esc_html( $vitads_hint ); ?>. Deixe em branco para manter o atual.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vitads_routing_key">Segmento / Público</label></th>
					<td>
						<input type="text" class="regular-text" name="vitads_routing_key" id="vitads_routing_key" value="<?php echo esc_attr( $vitads_segment ); ?>" maxlength="64" placeholder="contabilidade">
						<p class="description">O Segmento/Público identifica para qual operação/campanha este site pertence no VitAds.</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary">Salvar VitAds</button>
			</p>
		</form>
	</div>
</div>
