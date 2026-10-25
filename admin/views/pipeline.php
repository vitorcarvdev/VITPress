<?php
/**
 * admin/views/pipeline.php
 * Visualização do Pipeline Comercial (Kanban Read-Only).
 *
 * @package VitPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$period_selected = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : 'all';
$search_query    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

$column_configs = array(
	'presentation' => array(
		'title'       => 'Apresentação',
		'badge_color' => '#64b5f6',
		'border_top'  => '#64b5f6',
		'bg_header'   => '#f0f6fc',
	),
	'negotiating' => array(
		'title'       => 'Negociando',
		'badge_color' => '#00a884',
		'border_top'  => '#00a884',
		'bg_header'   => '#f0fdf4',
	),
	'won' => array(
		'title'       => 'Cliente Fechado',
		'badge_color' => '#4caf50',
		'border_top'  => '#4caf50',
		'bg_header'   => '#f0fdf4',
	),
	'lost' => array(
		'title'       => 'Cliente Perdido',
		'badge_color' => '#fbc02d',
		'border_top'  => '#fbc02d',
		'bg_header'   => '#fffbeb',
	),
	'disqualified' => array(
		'title'       => 'Ruim / Desqualificado',
		'badge_color' => '#f44336',
		'border_top'  => '#f44336',
		'bg_header'   => '#fef2f2',
	),
);
?>

<div class="wrap hub-wrap hub-pipeline-wrap">
	<!-- Top Bar -->
	<div class="hub-pipeline-topbar" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 20px;">
		<div>
			<h1 class="wp-heading-inline" style="display: flex; align-items: center; gap: 8px; font-size: 22px; font-weight: 700; color: #1e293b; margin: 0;">
				<span class="dashicons dashicons-columns" style="font-size: 26px; width: 26px; height: 26px; color: #1b4d3e;"></span>
				Pipeline de Leads
			</h1>
			<p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">
				Visualização em tempo real do funil e atendimento. Ações operacionais são realizadas exclusivamente no <strong>VitZap</strong>.
			</p>
		</div>

		<!-- Filtros e Status Read-Only -->
		<div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
			<span class="hub-readonly-badge" style="display: inline-flex; align-items: center; gap: 4px; background: #e2e8f0; color: #475569; padding: 5px 12px; border-radius: 16px; font-size: 12px; font-weight: 600;">
				<span class="dashicons dashicons-lock" style="font-size: 14px; width: 14px; height: 14px;"></span> Somente Visualização
			</span>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: flex; gap: 6px; align-items: center;">
				<input type="hidden" name="page" value="hub-pipeline">
				
				<select name="period" onchange="this.form.submit()" style="font-size: 12px; height: 32px; border-radius: 4px; border-color: #cbd5e1;">
					<option value="all" <?php selected( $period_selected, 'all' ); ?>>Todo o Período</option>
					<option value="30days" <?php selected( $period_selected, '30days' ); ?>>Últimos 30 dias</option>
					<option value="90days" <?php selected( $period_selected, '90days' ); ?>>Últimos 90 dias</option>
					<option value="year" <?php selected( $period_selected, 'year' ); ?>>Este Ano</option>
				</select>

				<input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Buscar lead..." style="font-size: 12px; height: 32px; width: 160px; border-radius: 4px; border-color: #cbd5e1;">
				<button type="submit" class="button button-secondary" style="height: 32px; font-size: 12px; padding: 0 10px;">Filtrar</button>
				<?php if ( ! empty( $search_query ) || 'all' !== $period_selected ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=hub-pipeline' ) ); ?>" class="button button-link" style="font-size: 12px; text-decoration: none; color: #64748b;">Limpar</a>
				<?php endif; ?>
			</form>
		</div>
	</div>

	<!-- Kanban Columns Grid -->
	<div class="hub-kanban-board">
		<?php foreach ( $stages as $stage_slug => $stage_title ) : ?>
			<?php
			$cfg   = isset( $column_configs[ $stage_slug ] ) ? $column_configs[ $stage_slug ] : array(
				'title'       => $stage_title,
				'badge_color' => '#64748b',
				'border_top'  => '#64748b',
				'bg_header'   => '#f8fafc',
			);
			$items = isset( $leads_by_stage[ $stage_slug ] ) ? $leads_by_stage[ $stage_slug ] : array();
			?>

			<div class="hub-kanban-col" data-stage="<?php echo esc_attr( $stage_slug ); ?>" style="border-top: 3px solid <?php echo esc_attr( $cfg['border_top'] ); ?>;">
				<!-- Col Header -->
				<div class="hub-kanban-col-header" style="background: <?php echo esc_attr( $cfg['bg_header'] ); ?>;">
					<div style="display: flex; justify-content: space-between; align-items: center;">
						<h3 class="hub-kanban-col-title"><?php echo esc_html( $cfg['title'] ); ?></h3>
						<span class="hub-kanban-col-count" style="background: <?php echo esc_attr( $cfg['badge_color'] ); ?>;">
							<?php echo count( $items ); ?>
						</span>
					</div>
					<?php if ( 'presentation' === $stage_slug && $waiting_attempts_count > 0 ) : ?>
						<div style="font-size: 11px; color: #d97706; margin-top: 4px; font-weight: 500;">
							⏳ <?php echo esc_html( $waiting_attempts_count ); ?> aguardando 1º contato
						</div>
					<?php endif; ?>
				</div>

				<!-- Col Cards List -->
				<div class="hub-kanban-cards">
					<?php if ( ! empty( $items ) ) : ?>
						<?php foreach ( $items as $card ) : ?>
							<div class="hub-lead-card" 
								 data-lead="<?php echo esc_attr( wp_json_encode( $card, JSON_UNESCAPED_UNICODE ) ); ?>"
								 title="Clique para ver os detalhes completos">
								
								<!-- Linha 1: Nome e Origem -->
								<div class="hub-lead-card-header">
									<strong class="hub-lead-card-name"><?php echo esc_html( $card['name'] ); ?></strong>
									<?php if ( ! empty( $card['source_label'] ) ) : ?>
										<span class="hub-lead-source-badge"><?php echo esc_html( $card['source_label'] ); ?></span>
									<?php endif; ?>
								</div>

								<!-- Linha 2: Contatos (Omitidos se vazios) -->
								<?php if ( ! empty( $card['formatted_phone'] ) || ! empty( $card['email'] ) ) : ?>
									<div class="hub-lead-card-contacts">
										<?php if ( ! empty( $card['formatted_phone'] ) ) : ?>
											<div class="hub-lead-contact-item phone">
												<span class="dashicons dashicons-whatsapp" style="color: #25d366; font-size: 14px; width: 14px; height: 14px;"></span>
												<span><?php echo esc_html( $card['formatted_phone'] ); ?></span>
											</div>
										<?php endif; ?>
										<?php if ( ! empty( $card['email'] ) ) : ?>
											<div class="hub-lead-contact-item email" title="<?php echo esc_attr( $card['email'] ); ?>">
												<span class="dashicons dashicons-email-alt" style="color: #64748b; font-size: 14px; width: 14px; height: 14px;"></span>
												<span class="hub-truncate-text"><?php echo esc_html( $card['email'] ); ?></span>
											</div>
										<?php endif; ?>
									</div>
								<?php endif; ?>

								<!-- Linha 3: Data e Idade -->
								<div class="hub-lead-card-date">
									<span class="dashicons dashicons-calendar-alt" style="font-size: 13px; width: 13px; height: 13px; color: #94a3b8;"></span>
									<span><?php echo esc_html( $card['formatted_date'] ); ?></span>
									<span class="hub-card-age-bullet">•</span>
									<span class="hub-card-relative-age"><?php echo esc_html( $card['relative_age'] ); ?></span>
								</div>

								<!-- Linha 4: Métricas de Atendimento -->
								<?php $att = $card['attendance']; ?>
								<div class="hub-lead-attendance-box <?php echo esc_attr( $att['class'] ); ?>" style="border-left-color: <?php echo esc_attr( $att['color'] ); ?>;">
									<div class="hub-lead-att-status" style="color: <?php echo esc_attr( $att['color'] ); ?>;">
										<span class="hub-att-dot" style="background-color: <?php echo esc_attr( $att['color'] ); ?>;"></span>
										<span><?php echo esc_html( $att['status_text'] ); ?></span>
									</div>
									<?php if ( ! empty( $att['badge_text'] ) && $att['has_attempts'] ) : ?>
										<div class="hub-lead-att-count">
											<span class="dashicons dashicons-phone" style="font-size: 12px; width: 12px; height: 12px;"></span>
											<span><?php echo esc_html( $att['badge_text'] ); ?></span>
										</div>
									<?php endif; ?>
								</div>

								<!-- Informações Específicas de Estágio -->
								<?php if ( 'lost' === $stage_slug && ! empty( $card['lost_reason'] ) ) : ?>
									<div class="hub-lead-lost-note">
										<strong>Motivo:</strong> <?php echo esc_html( $card['lost_reason'] ); ?>
									</div>
								<?php endif; ?>

								<?php if ( 'won' === $stage_slug && $card['revenue_value'] > 0 ) : ?>
									<div class="hub-lead-sale-note">
										💰 <strong>Venda:</strong> R$ <?php echo esc_html( number_format( $card['revenue_value'], 2, ',', '.' ) ); ?>
									</div>
								<?php endif; ?>

								<!-- Footer do Card -->
								<div class="hub-lead-card-footer">
									<span>Ver detalhes</span>
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="hub-kanban-empty">
							<span class="dashicons dashicons-info" style="font-size: 18px; width: 18px; height: 18px; color: #94a3b8; display: block; margin: 0 auto 4px;"></span>
							Nenhum lead nesta etapa.
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<!-- Modal Detalhes do Lead (Estritamente Somente Leitura) -->
<div id="hub-lead-details-modal" class="hub-details-modal-overlay" style="display: none;">
	<div class="hub-details-modal-box">
		<div class="hub-details-modal-header">
			<div>
				<h2 id="hub-modal-lead-name" style="margin: 0; font-size: 18px; font-weight: 700; color: #1e293b;"></h2>
				<div style="display: flex; align-items: center; gap: 8px; margin-top: 4px;">
					<span id="hub-modal-lead-stage-badge" class="hub-modal-badge"></span>
					<span id="hub-modal-lead-date" style="color: #64748b; font-size: 12px;"></span>
				</div>
			</div>
			<button type="button" class="hub-modal-close-btn" id="hub-btn-close-lead-modal" aria-label="Fechar">✕</button>
		</div>

		<div class="hub-details-modal-body">
			<!-- 1. Dados de Contato -->
			<div class="hub-modal-section">
				<h4 class="hub-modal-section-title">
					<span class="dashicons dashicons-id-alt"></span> Dados de Contato
				</h4>
				<div class="hub-modal-grid-2">
					<div class="hub-modal-field">
						<label>Telefone / WhatsApp:</label>
						<span id="hub-modal-phone" class="hub-modal-val">-</span>
					</div>
					<div class="hub-modal-field">
						<label>E-mail:</label>
						<span id="hub-modal-email" class="hub-modal-val">-</span>
					</div>
				</div>
			</div>

			<!-- 2. Atendimento & Tentativas -->
			<div class="hub-modal-section">
				<h4 class="hub-modal-section-title">
					<span class="dashicons dashicons-admin-users"></span> Atendimento Comercial
				</h4>
				<div class="hub-modal-grid-2">
					<div class="hub-modal-field">
						<label>Status de Resposta:</label>
						<span id="hub-modal-att-status" class="hub-modal-val">-</span>
					</div>
					<div class="hub-modal-field">
						<label>Tentativas Registradas:</label>
						<span id="hub-modal-att-count" class="hub-modal-val">0</span>
					</div>
				</div>
			</div>

			<!-- 3. Origem e Rastreamento -->
			<div class="hub-modal-section">
				<h4 class="hub-modal-section-title">
					<span class="dashicons dashicons-chart-pie"></span> Origem & Rastreamento
				</h4>
				<div class="hub-modal-grid-2">
					<div class="hub-modal-field">
						<label>Canal de Origem:</label>
						<span id="hub-modal-source" class="hub-modal-val">-</span>
					</div>
					<div class="hub-modal-field">
						<label>Campanha / UTM:</label>
						<span id="hub-modal-campaign" class="hub-modal-val">-</span>
					</div>
				</div>
			</div>

			<!-- 4. Respostas do Atendente Virtual (Captador) -->
			<div class="hub-modal-section" id="hub-modal-captador-wrap" style="display: none;">
				<h4 class="hub-modal-section-title">
					<span class="dashicons dashicons-format-chat"></span> Respostas do Atendente Virtual
				</h4>
				<div id="hub-modal-captador-list" class="hub-modal-captador-table"></div>
			</div>

			<!-- 5. Observações do Lead -->
			<div class="hub-modal-section" id="hub-modal-notes-wrap" style="display: none;">
				<h4 class="hub-modal-section-title">
					<span class="dashicons dashicons-edit-page"></span> Observações
				</h4>
				<div id="hub-modal-notes" style="background: #f8fafc; padding: 10px 12px; border-radius: 6px; font-size: 13px; color: #334155; white-space: pre-wrap;"></div>
			</div>

			<!-- 6. Venda / Perda se aplicável -->
			<div class="hub-modal-section" id="hub-modal-commercial-wrap" style="display: none;">
				<h4 class="hub-modal-section-title">
					<span class="dashicons dashicons-money-alt"></span> Detalhes Comerciais
				</h4>
				<div id="hub-modal-commercial-info" style="font-size: 13px; color: #334155;"></div>
			</div>
		</div>

		<div class="hub-details-modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<button type="button" class="button button-link-delete" id="hub-btn-delete-lead" style="color: #dc2626; text-decoration: none;">Excluir lead</button>
			<?php else : ?>
				<div></div>
			<?php endif; ?>
			<button type="button" class="button button-primary" id="hub-btn-modal-footer-close">Fechar</button>
		</div>
	</div>
</div>