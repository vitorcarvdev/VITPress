<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title          = 'Indicadores Comerciais';
$lead_plural    = $profile->get_label( 'lead_plural', 'Leads' );
$client_plural  = $profile->get_label( 'client_plural', 'Empresas' );

$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '30_days';
?>
<div class="wrap hub-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
	<hr class="wp-header-end">

	<!-- BARRA SUPERIOR (Filtro + Destaque Tempo Médio + Resumo Critérios) -->
	<div class="card" style="margin-top: 20px; padding: 18px 22px; margin-bottom: 20px; max-width: 100%; width: 100%; box-sizing: border-box; border-radius: 6px; border: 1px solid #c3c4c7;">
		<div style="display: grid; grid-template-columns: 240px 1.2fr 1.2fr; gap: 25px; align-items: flex-start;">
			
			<!-- 1. Filtro de Período & Botão Indicadores -->
			<div style="max-width: 170px; width: 100%;">
				<form method="get" style="display: flex; flex-direction: column;">
					<input type="hidden" name="page" value="hub-indicadores">
					<label for="period" style="font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1d2327;">Período:</label>
					<select name="period" id="period" style="width: 100%; box-sizing: border-box; height: 30px; font-size: 13px;">
						<option value="today" <?php selected( $period, 'today' ); ?>>Hoje</option>
						<option value="7_days" <?php selected( $period, '7_days' ); ?>>Últimos 7 dias</option>
						<option value="30_days" <?php selected( $period, '30_days' ); ?>>Últimos 30 dias</option>
						<option value="custom" <?php selected( $period, 'custom' ); ?>>Personalizado</option>
					</select>
					<div id="custom-dates" style="display: <?php echo $period === 'custom' ? 'flex' : 'none'; ?>; flex-direction: column; gap: 6px; margin-top: 8px;">
						<input type="date" name="date_start" value="<?php echo isset( $_GET['date_start'] ) ? esc_attr( $_GET['date_start'] ) : ''; ?>" style="width: 100%;">
						<input type="date" name="date_end" value="<?php echo isset( $_GET['date_end'] ) ? esc_attr( $_GET['date_end'] ) : ''; ?>" style="width: 100%;">
						<button type="submit" class="button button-primary button-small" style="width: 100%;">Filtrar</button>
					</div>
				</form>
				<button type="button" class="button button-small" id="hub-btn-open-indicadores-modal" style="width: 100%; box-sizing: border-box; margin-top: 8px; height: 30px; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border-color: #2271b1; color: #2271b1;">
					<span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px; color: #2271b1;"></span> Indicadores
				</button>
			</div>

			<!-- 2. Tempo Médio até 1ª Tentativa -->
			<div style="border-left: 4px solid <?php echo esc_attr( $avg_response_time_classification['color'] ); ?>; padding-left: 15px;">
				<div style="font-size: 24px; font-weight: bold; color: #1d2327; line-height: 1.2;">
					<?php 
					if ( null === $avg_response_time ) {
						echo 'Sem dados';
					} else {
						if ( $avg_response_time < 60 ) {
							echo esc_html( $avg_response_time . ' min' );
						} else {
							$h = floor( $avg_response_time / 60 );
							$m = $avg_response_time % 60;
							echo esc_html( $h . 'h ' . $m . 'min' );
						}
					}
					?>
				</div>
				<div style="margin-top: 5px; font-size: 11px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
					<span style="background: <?php echo esc_attr( $avg_response_time_classification['color'] ); ?>; color: #fff; padding: 2px 6px; border-radius: 3px; font-weight: bold; font-size: 9px; text-transform: uppercase;">
						<?php echo esc_html( $avg_response_time_classification['label'] ); ?>
					</span>
					<span style="color: #646970;">
						Cobertura: <strong><?php echo esc_html( $cohort_atendidos ); ?> de <?php echo esc_html( $cohort_total ); ?></strong> (<?php echo esc_html( $cobertura_pct ); ?>%)
					</span>
				</div>
				<div style="margin-top: 5px; font-size: 11px; color: #646970;">
					Sem tentativa registrada: <strong><?php echo esc_html( $cohort_sem_atendimento ); ?></strong>
				</div>
				<div style="margin-top: 8px;">
					<button type="button" class="button button-small" id="hub-btn-toggle-orientacao" style="font-size: 11px; height: 24px; line-height: 22px; padding: 0 8px;">Ver orientação</button>
				</div>
				
				<div id="hub-orientacao-bloco" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid #ccd0d4; font-size: 11px; line-height: 1.4; color: #50575e;">
					<?php
					$has_warning = false;
					if ( null !== $avg_response_time && $avg_response_time > 60 ) {
						$has_warning = true;
						$avg_time_text = ( $avg_response_time < 60 ) ? ( $avg_response_time . ' min' ) : ( floor( $avg_response_time / 60 ) . 'h ' . ( $avg_response_time % 60 ) . 'min' );
						echo '<p style="margin: 0 0 8px;"><strong>Métrica de Velocidade:</strong> O tempo médio até a primeira tentativa de contato está acima do ideal neste período. Os leads receberam a primeira tentativa de contato, em média, ' . esc_html( $avg_time_text ) . ' após entrarem em contato. Reduzir esse intervalo pode aumentar as chances de resposta e conversão.</p>';
					}
					
					if ( $cohort_sem_atendimento > 0 ) {
						$has_warning = true;
						echo '<p style="margin: 0 0 8px;"><strong>Métrica de Cobertura:</strong> Neste período, ' . esc_html( $cohort_sem_atendimento ) . ' de ' . esc_html( $cohort_total ) . ' leads não possuem nenhuma tentativa de contato registrada no VitPress. Antes de avaliar a qualidade dos leads recebidos, recomendamos que todas as oportunidades tenham ao menos uma tentativa de atendimento registrada.</p>';
					}
					
					if ( ! $has_warning ) {
						if ( null === $avg_response_time ) {
							echo '<p style="margin: 0;">Nenhuma tentativa de contato foi registrada para os leads criados no período selecionado. Registre as tentativas de atendimento no Pipeline para gerar as orientações comerciais.</p>';
						} else {
							echo '<p style="margin: 0; color: #46b450; font-weight: 600;">Excelente! O tempo médio de resposta e a taxa de cobertura de atendimento estão operando dentro dos parâmetros ideais neste período.</p>';
						}
					}
					?>
				</div>
			</div>

			<!-- 3. Critérios de Atendimento Compacto -->
			<div style="font-size: 11px; line-height: 1.4; color: #646970;">
				<div style="font-weight: 600; color: #1d2327; margin-bottom: 5px;">Critérios de Atendimento</div>
				<p style="margin: 0 0 4px;"><strong>Rápido:</strong> Primeira tentativa de contato registrada em até 15 minutos do recebimento do lead.</p>
				<p style="margin: 0 0 4px;"><strong>Moderado:</strong> Primeira tentativa entre 16 e 60 minutos.</p>
				<p style="margin: 0 0 4px;"><strong>Demorado:</strong> Primeira tentativa entre 1 hora e 4 horas.</p>
				<p style="margin: 0 0 0;"><strong>Muito Demorado:</strong> Primeira tentativa registrada após 4 horas.</p>
			</div>

		</div>
	</div>

	<!-- GRID PRINCIPAL DE 3 COLUNAS ALINHADAS -->
	<div style="display: grid; grid-template-columns: 1.15fr 1fr 1.15fr; gap: 20px; align-items: stretch; width: 100%; box-sizing: border-box;">

		<!-- COLUNA 1: Calculadora & Indicadores -->
		<div style="display: flex; flex-direction: column; gap: 15px; width: 100%; box-sizing: border-box;">
			<!-- Calculadora -->
			<div class="card" style="margin: 0; padding: 16px 20px; border-radius: 6px; border: 1px solid #c3c4c7; width: 100%; box-sizing: border-box;">
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
					<div>
						<label for="investimento" style="display: block; font-size: 13px; font-weight: 600; color: #1d2327; margin-bottom: 6px;">Investimento Total (R$)</label>
						<input type="number" id="investimento" class="regular-text" placeholder="Ex: 1000.00" step="0.01" style="width: 100%; font-size: 14px; font-weight: 600; padding: 5px 8px; box-sizing: border-box;">
						<p class="description" style="margin-top: 4px; font-size: 9px; line-height: 1.2; color: #646970;">Usado apenas para cálculo na tela. Não é salvo no banco.</p>
					</div>
					<div>
						<label for="cliques" style="display: block; font-size: 13px; font-weight: 600; color: #1d2327; margin-bottom: 6px;">Cliques</label>
						<input type="number" id="cliques" class="regular-text" placeholder="Ex: 500" step="1" style="width: 100%; font-size: 14px; font-weight: 600; padding: 5px 8px; box-sizing: border-box;">
					</div>
				</div>

				<div style="display: flex; justify-content: space-between; padding-top: 10px; border-top: 1px solid #f0f0f1;">
					<div>
						<span style="font-size: 10px; color: #646970; display: block; text-transform: uppercase; font-weight: 600;">Custo por Lead (CPL)</span>
						<strong id="calc_cpl" style="font-size: 16px; color: #2271b1; display: block; margin-top: 2px;">R$ 0,00</strong>
					</div>
					<div>
						<span style="font-size: 10px; color: #646970; display: block; text-transform: uppercase; font-weight: 600;">Custo por Cliente (CPA)</span>
						<strong id="calc_cpa" style="font-size: 16px; color: #46b450; display: block; margin-top: 2px;">R$ 0,00</strong>
					</div>
					<div>
						<span style="font-size: 10px; color: #646970; display: block; text-transform: uppercase; font-weight: 600;">Retorno (ROAS)</span>
						<strong id="calc_roas" style="font-size: 16px; color: #d63638; display: block; margin-top: 2px;">0.0x</strong>
					</div>
				</div>
			</div>

			<!-- Grid dos 8 Cards Comerciais e Métricas de Tráfego -->
			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; width: 100%; box-sizing: border-box;">
				<!-- Card 1: Total Interessados -->
				<div class="hub-stat-card border-blue" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-groups" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" id="val_total_leads" style="font-size: 18px; line-height: 1.1;"><?php echo esc_html( $total_leads ); ?></span>
						<span class="hub-stat-label" style="font-size: 11px;">Total de Interessados</span>
					</div>
				</div>
				<!-- Card 2: Total Clientes -->
				<div class="hub-stat-card border-green" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-yes-alt" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" id="val_converted" style="font-size: 18px; line-height: 1.1;"><?php echo esc_html( $converted_leads ); ?></span>
						<span class="hub-stat-label" style="font-size: 11px;">Total de Clientes</span>
					</div>
				</div>
				<!-- Card 3: Total Perdidos -->
				<div class="hub-stat-card border-red" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-dismiss" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" style="font-size: 18px; line-height: 1.1;"><?php echo esc_html( $lost_leads ); ?></span>
						<span class="hub-stat-label" style="font-size: 11px;">Total de Perdidos</span>
					</div>
				</div>
				<!-- Card 4: Taxa Conversão -->
				<div class="hub-stat-card border-purple" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-chart-bar" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" style="font-size: 18px; line-height: 1.1;"><?php echo esc_html( $conversion_rate ); ?>%</span>
						<span class="hub-stat-label" style="font-size: 11px;">Taxa de Conversão</span>
					</div>
				</div>
				<!-- Card 5: Receita Total -->
				<div class="hub-stat-card border-blue" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-money-alt" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" style="font-size: 18px; line-height: 1.1;"><?php echo esc_html( 'R$ ' . number_format( $total_revenue, 2, ',', '.' ) ); ?></span>
						<span class="hub-stat-label" style="font-size: 11px;">Receita Total</span>
					</div>
				</div>
				<!-- Card 6: Ticket Médio -->
				<div class="hub-stat-card border-green" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-cart" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" style="font-size: 18px; line-height: 1.1;"><?php echo esc_html( 'R$ ' . number_format( $avg_ticket, 2, ',', '.' ) ); ?></span>
						<span class="hub-stat-label" style="font-size: 11px;">Ticket Médio</span>
					</div>
				</div>
				<!-- Card 7 (NOVO): Valor do Clique (CPC) -->
				<div class="hub-stat-card border-orange" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-admin-links" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" id="val_cpc" style="font-size: 18px; line-height: 1.1;">R$ 0,00</span>
						<span class="hub-stat-label" style="font-size: 11px;">Valor do Clique (CPC)</span>
					</div>
				</div>
				<!-- Card 8 (NOVO): Taxa de Cliques (CTR) -->
				<div class="hub-stat-card border-purple" style="margin: 0; padding: 8px 12px; box-sizing: border-box; width: 100%; gap: 10px;">
					<div class="hub-stat-icon" style="width: 34px; height: 34px; min-width: 34px;"><span class="dashicons dashicons-chart-area" style="font-size: 18px; width: 18px; height: 18px;"></span></div>
					<div>
						<span class="hub-stat-value" id="val_ctr" style="font-size: 18px; line-height: 1.1;">0,0%</span>
						<span class="hub-stat-label" style="font-size: 11px;">Taxa de Cliques (CTR)</span>
					</div>
				</div>
			</div>
		</div>

		<!-- COLUNA 2: Funil Comercial -->
		<div class="card" style="margin: 0; padding: 18px 20px; border-radius: 6px; border: 1px solid #c3c4c7; display: flex; flex-direction: column; justify-content: space-between; height: 100%; box-sizing: border-box;">
			<h2 style="font-size: 14px; margin-top: 0; margin-bottom: 0; border-bottom: 1px solid #ccd0d4; padding-bottom: 8px; font-weight: 600;">
				<span class="dashicons dashicons-filter" style="vertical-align: middle; margin-right: 5px;"></span> FUNIL COMERCIAL
			</h2>
			
			<div class="hub-funnel-container" style="display: flex; flex-direction: column; justify-content: space-around; flex: 1; margin-top: 12px; margin-bottom: 5px;">
				<div class="hub-funnel-step" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
					<span><strong>Leads Recebidos</strong> (Coorte)</span>
					<span style="font-size: 15px; font-weight: bold; color: #2271b1;"><?php echo esc_html( $funnel_leads ); ?></span>
				</div>
				<div style="text-align: center; color: #a7aaad; font-size: 12px; line-height: 1; padding: 4px 0;"><span class="dashicons dashicons-arrow-down-alt2"></span></div>

				<div class="hub-funnel-step" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
					<span><strong>Atendidos</strong> (&ge; 1 tentativa)</span>
					<span style="font-size: 15px; font-weight: bold; color: #2271b1;">
						<?php echo esc_html( $funnel_atendidos ); ?> 
						<small style="font-size:10px; font-weight:normal; color:#646970;">(<?php echo $funnel_leads > 0 ? round( ( $funnel_atendidos / $funnel_leads ) * 100 ) : 0; ?>%)</small>
					</span>
				</div>
				<div style="text-align: center; color: #a7aaad; font-size: 12px; line-height: 1; padding: 4px 0;"><span class="dashicons dashicons-arrow-down-alt2"></span></div>

				<div class="hub-funnel-step" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
					<span><strong>Avaliados</strong> (Estágio Avaliação)</span>
					<span style="font-size: 15px; font-weight: bold; color: #2271b1;">
						<?php echo esc_html( $funnel_avaliados ); ?>
						<small style="font-size:10px; font-weight:normal; color:#646970;">(<?php echo $funnel_leads > 0 ? round( ( $funnel_avaliados / $funnel_leads ) * 100 ) : 0; ?>%)</small>
					</span>
				</div>
				<div style="text-align: center; color: #a7aaad; font-size: 12px; line-height: 1; padding: 4px 0;"><span class="dashicons dashicons-arrow-down-alt2"></span></div>

				<div class="hub-funnel-step" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
					<span><strong>Proposta Enviada</strong></span>
					<span style="font-size: 15px; font-weight: bold; color: #2271b1;">
						<?php echo esc_html( $funnel_propostas ); ?>
						<small style="font-size:10px; font-weight:normal; color:#646970;">(<?php echo $funnel_leads > 0 ? round( ( $funnel_propostas / $funnel_leads ) * 100 ) : 0; ?>%)</small>
					</span>
				</div>
				<div style="text-align: center; color: #a7aaad; font-size: 12px; line-height: 1; padding: 4px 0;"><span class="dashicons dashicons-arrow-down-alt2"></span></div>

				<div class="hub-funnel-step" style="background: #edf7ed; border: 1px solid #c3e6cb; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
					<span style="color:#1e4620;"><strong>Clientes Convertidos</strong></span>
					<span style="font-size: 15px; font-weight: bold; color: #1e4620;">
						<?php echo esc_html( $funnel_clientes ); ?>
						<small style="font-size:10px; font-weight:normal; color:#1e4620;">(<?php echo $funnel_leads > 0 ? round( ( $funnel_clientes / $funnel_leads ) * 100 ) : 0; ?>%)</small>
					</span>
				</div>
			</div>
		</div>

		<!-- COLUNA 3: Últimos Clientes & Atendimento -->
		<div style="display: flex; flex-direction: column; justify-content: space-between; height: 100%; gap: 15px; box-sizing: border-box;">
			<!-- Últimos Clientes Convertidos -->
			<div class="card" style="margin: 0; padding: 14px 18px; border-radius: 6px; border: 1px solid #c3c4c7; box-sizing: border-box;">
				<h2 style="font-size: 12px; margin-top: 0; color: #1d2327; margin-bottom: 8px; font-weight: 600;">
					<span class="dashicons dashicons-admin-users" style="vertical-align: middle; margin-right: 4px;"></span> Últimos Clientes Convertidos
				</h2>
				<?php if ( empty( $recent_conversions ) ) : ?>
					<p style="color: #646970; font-size: 11px; margin: 0; font-style: italic;">Nenhum cliente convertido no período selecionado.</p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped" style="font-size: 11px; margin-top: 0;">
						<thead>
							<tr>
								<th>Nome</th>
								<th>Data</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_conversions as $conv ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $conv->name ); ?></strong></td>
									<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $conv->created_at ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Seção Atendimento -->
			<div class="card" style="margin: 0; padding: 14px 18px; border-radius: 6px; border: 1px solid #c3c4c7; box-sizing: border-box; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
				<h2 style="font-size: 12px; margin-top: 0; border-bottom: 1px solid #ccd0d4; padding-bottom: 6px; font-weight: 600; margin-bottom: 6px;">
					<span class="dashicons dashicons-phone" style="vertical-align: middle; margin-right: 4px;"></span> ATENDIMENTO
				</h2>
				<table class="wp-list-table widefat striped" style="margin-top: 0; font-size: 11px; border: 0; box-shadow: none; width: 100%;">
					<tbody>
						<tr>
							<td style="padding: 5px 8px;"><strong>Tempo médio até 1ª tentativa</strong></td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;">
								<?php 
								if ( null === $avg_response_time ) {
									echo 'Sem dados';
								} else {
									echo esc_html( ( $avg_response_time < 60 ) ? ( $avg_response_time . ' min' ) : ( floor( $avg_response_time / 60 ) . 'h ' . ( $avg_response_time % 60 ) . 'min' ) );
								}
								?>
							</td>
						</tr>
						<tr>
							<td style="padding: 5px 8px;">Leads atendidos em até 15 min (Rápido)</td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;"><?php echo esc_html( $faixas['rapido'] ); ?></td>
						</tr>
						<tr>
							<td style="padding: 5px 8px;">Leads atendidos entre 16-60 min (Moderado)</td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;"><?php echo esc_html( $faixas['moderado'] ); ?></td>
						</tr>
						<tr>
							<td style="padding: 5px 8px;">Leads atendidos entre 1-4h (Demorado)</td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;"><?php echo esc_html( $faixas['demorado'] ); ?></td>
						</tr>
						<tr>
							<td style="padding: 5px 8px;">Leads atendidos após 4h (Muito Demorado)</td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;"><?php echo esc_html( $faixas['muito_demorado'] ); ?></td>
						</tr>
						<tr style="background-color: #fcf0f1;">
							<td style="padding: 5px 8px;"><span style="color:#d63638; font-weight:600;">Sem tentativa registrada no VitPress</span></td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;"><span style="color:#d63638;"><?php echo esc_html( $cohort_sem_atendimento ); ?></span></td>
						</tr>
						<tr>
							<td style="padding: 5px 8px;">Total de tentativas realizadas</td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;"><?php echo esc_html( $total_attempts ); ?></td>
						</tr>
						<tr>
							<td style="padding: 5px 8px;">Média de tentativas por lead atendido</td>
							<td style="padding: 5px 8px; text-align: right; font-weight: 600; white-space: nowrap;"><?php echo esc_html( $avg_attempts_per_lead ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

	</div>
</div>

<!-- Modal Como Interpretar Indicadores -->
<div id="hub-modal-indicadores" class="hub-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
	<div class="hub-modal-content" style="background-color: #fff; margin: 8% auto; padding: 25px 30px; border: 1px solid #8c8f94; border-radius: 6px; width: 600px; max-width: 90%; box-shadow: 0 4px 15px rgba(0,0,0,0.2); position: relative;">
		<h2 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #ccd0d4; padding-bottom: 10px; color: #1d2327;">
			<span class="dashicons dashicons-info" style="vertical-align: middle; margin-right: 5px; color: #2271b1;"></span> Como interpretar estes indicadores
		</h2>
		<div style="font-size: 12px; line-height: 1.6; color: #50575e; margin: 15px 0;">
			<p style="margin: 0 0 8px;"><strong>Total de Interessados:</strong> Número de Leads que entraram em contato no período selecionado.</p>
			<p style="margin: 0 0 8px;"><strong>Total de Clientes:</strong> Quantos desses Leads chegaram ao estágio "Cliente".</p>
			<p style="margin: 0 0 8px;"><strong>Total de Perdidos:</strong> Quantos Leads chegaram ao estágio "Perdido".</p>
			<p style="margin: 0 0 8px;"><strong>Taxa de Conversão:</strong> A porcentagem de Interessados que se tornaram Clientes.</p>
			<p style="margin: 0 0 8px;"><strong>Custo por Lead (CPL):</strong> Quanto você pagou em média por cada Lead que entrou em contato (Investimento / Total de Interessados).</p>
			<p style="margin: 0 0 8px;"><strong>Custo por Cliente (CPA):</strong> Quanto custou cada venda efetiva (Investimento / Total de Clientes).</p>
			<p style="margin: 0 0 8px;"><strong>Receita Total:</strong> A soma do valor de conversão preenchido em todos os clientes deste período.</p>
			<p style="margin: 0 0 8px;"><strong>Ticket Médio:</strong> Qual é o valor médio gasto por cliente (Receita Total / Total de Clientes).</p>
						<p style="margin: 0 0 8px;"><strong>ROAS (Retorno):</strong> O Retorno sobre o Investimento Publicitário. Um ROAS de 10x significa que a cada R$ 1 investido, voltaram R$ 10 (Receita Total / Investimento).</p>
			<p style="margin: 0 0 8px;"><strong>Valor do Clique (CPC):</strong> O custo médio por clique no anúncio (Investimento / Cliques).</p>
			<p style="margin: 0 0 0;"><strong>Taxa de Cliques (CTR / Conversão de Tráfego):</strong> A porcentagem de cliques nos anúncios que efetivamente converteram em Interessados/Leads (Total de Interessados / Cliques).</p>
		</div>
		<div style="text-align: right; border-top: 1px solid #ccd0d4; padding-top: 12px; margin-top: 15px;">
			<button type="button" class="button button-primary" id="hub-btn-close-indicadores-modal">Fechar</button>
		</div>
	</div>
</div>

<script>
jQuery(document).ready(function($) {
	$('#hub-btn-toggle-orientacao').on('click', function(e) {
		e.preventDefault();
		$('#hub-orientacao-bloco').slideToggle();
	});

	$('#hub-btn-open-indicadores-modal').on('click', function(e) {
		e.preventDefault();
		$('#hub-modal-indicadores').show();
	});

	$('#hub-btn-close-indicadores-modal').on('click', function(e) {
		e.preventDefault();
		$('#hub-modal-indicadores').hide();
	});

	$(window).on('click', function(e) {
		if ($(e.target).is('#hub-modal-indicadores')) {
			$('#hub-modal-indicadores').hide();
		}
	});

	$('#period').on('change', function() {
		if ($(this).val() === 'custom') {
			$('#custom-dates').css('display', 'flex');
		} else {
			$('#custom-dates').hide();
			$(this).closest('form').submit();
		}
	});

	function recalculateKPIs() {
		var investimento = parseFloat($('#investimento').val()) || 0;
		var cliques = parseInt($('#cliques').val()) || 0;
		var totalLeads = parseInt($('#val_total_leads').text()) || 0;
		var totalConverted = parseInt($('#val_converted').text()) || 0;
		var totalRevenue = parseFloat('<?php echo $total_revenue; ?>') || 0;

		var cpl = totalLeads > 0 ? (investimento / totalLeads) : 0;
		var cpa = totalConverted > 0 ? (investimento / totalConverted) : 0;
		var roas = investimento > 0 ? (totalRevenue / investimento) : 0;
		var cpc = cliques > 0 ? (investimento / cliques) : 0;
		var ctr = cliques > 0 ? ((totalLeads / cliques) * 100) : 0;

		$('#calc_cpl').text(cpl.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));
		$('#calc_cpa').text(cpa.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));
		$('#calc_roas').text(roas.toFixed(1) + 'x');
		$('#val_cpc').text(cpc.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));
		$('#val_ctr').text(ctr.toFixed(1).replace('.', ',') + '%');
	}

	$('#investimento, #cliques').on('input', recalculateKPIs);
});
</script>