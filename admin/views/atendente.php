<?php
/**
 * admin/views/atendente.php
 * Tela de Configuração do Atendente Virtual (Captador de Leads).
 *
 * @package VitPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_media();
$settings = \Hub\Core\Services\LeadCollectorService::get_settings();
$avatar_url = '';
if ( ! empty( $settings['attendant_avatar_id'] ) ) {
	$img = wp_get_attachment_image_src( (int) $settings['attendant_avatar_id'], 'thumbnail' );
	if ( $img && ! empty( $img[0] ) ) {
		$avatar_url = $img[0];
	}
}
?>

<div class="wrap hub-wrap" style="max-width: 1050px;">
	<h1 class="wp-heading-inline">
		<span class="dashicons dashicons-admin-users" style="font-size: 26px; width: 26px; height: 26px; vertical-align: middle; margin-right: 4px; color: #1b4d3e;"></span>
		Atendente Virtual (Captador de Leads)
	</h1>
	<hr class="wp-header-end">

	<?php if ( isset( $_GET['message'] ) && 'saved' === $_GET['message'] ) : ?>
		<div class="notice notice-success is-dismissible" style="margin-top: 15px;">
			<p><strong>Configurações do Atendente salvas com sucesso!</strong></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hub-captador-form" style="margin-top: 20px;">
		<input type="hidden" name="action" value="hub_save_lead_collector">
		<?php wp_nonce_field( 'hub_lead_collector_nonce', 'hub_nonce' ); ?>

		<!-- 1. ATIVAÇÃO GERAL -->
		<div class="card" style="margin-top: 0; padding: 18px 22px; border-radius: 6px; border-left: 4px solid #1b4d3e; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
			<div style="display: flex; align-items: center; justify-content: space-between;">
				<div>
					<h2 style="font-size: 16px; margin: 0 0 4px; font-weight: 600;">Status do Atendente no Site</h2>
					<p style="margin: 0; color: #646970; font-size: 12px;">Quando ativado, o atendente conversacional será exibido automaticamente para capturar leads no site.</p>
				</div>
				<div>
					<label class="hub-switch" style="position: relative; display: inline-block; width: 50px; height: 26px;">
						<input type="checkbox" name="collector[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> style="opacity: 0; width: 0; height: 0;">
						<span class="hub-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 26px;"></span>
					</label>
				</div>
			</div>
		</div>

		<!-- 2. IDENTIDADE DO ATENDENTE & APARÊNCIA -->
		<div class="card" style="margin-top: 0; padding: 20px; border-radius: 6px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
			<h2 style="font-size: 15px; margin-top: 0; border-bottom: 1px solid #ccd0d4; padding-bottom: 8px; font-weight: 600;">
				<span class="dashicons dashicons-id-alt" style="vertical-align: middle; margin-right: 4px;"></span> Identidade & Aparência
			</h2>
			
			<table class="form-table" style="margin-top: 10px;">
				<tr>
					<th scope="row"><label>Foto / Avatar</label></th>
					<td>
						<div style="display: flex; align-items: center; gap: 15px;">
							<div id="hub-avatar-preview" style="width: 56px; height: 56px; border-radius: 50%; background: #f0f0f1; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid #ccd0d4;">
								<?php if ( ! empty( $avatar_url ) ) : ?>
									<img src="<?php echo esc_url( $avatar_url ); ?>" style="width: 100%; height: 100%; object-fit: cover;">
								<?php else : ?>
									<span class="dashicons dashicons-format-image" style="color: #a7aaad; font-size: 24px; width: 24px; height: 24px;"></span>
								<?php endif; ?>
							</div>
							<input type="hidden" name="collector[attendant_avatar_id]" id="hub-avatar-id" value="<?php echo esc_attr( $settings['attendant_avatar_id'] ); ?>">
							<button type="button" class="button" id="hub-btn-upload-avatar">Selecionar Foto na Biblioteca</button>
							<button type="button" class="button button-link-delete" id="hub-btn-remove-avatar" style="<?php echo empty( $avatar_url ) ? 'display:none;' : ''; ?>">Remover</button>
						</div>
						<p class="description">Escolha uma foto quadrada e amigável para representar o atendimento.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="attendant_name">Nome do Atendente</label></th>
					<td>
						<input type="text" name="collector[attendant_name]" id="attendant_name" value="<?php echo esc_attr( $settings['attendant_name'] ); ?>" class="regular-text" placeholder="Ex: Larissa">
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="attendant_role">Cargo / Descrição</label></th>
					<td>
						<input type="text" name="collector[attendant_role]" id="attendant_role" value="<?php echo esc_attr( $settings['attendant_role'] ); ?>" class="regular-text" placeholder="Ex: Especialista em Projetos">
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="primary_color">Cor Principal</label></th>
					<td>
						<input type="color" name="collector[primary_color]" id="primary_color" value="<?php echo esc_attr( $settings['primary_color'] ); ?>" style="height: 34px; padding: 2px; width: 60px; vertical-align: middle;">
						<span style="font-family: monospace; margin-left: 8px; font-weight: 600;"><?php echo esc_html( $settings['primary_color'] ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="position">Posição no Site</label></th>
					<td>
						<select name="collector[position]" id="position" class="regular-text">
							<option value="right" <?php selected( $settings['position'], 'right' ); ?>>Canto Inferior Direito (Padrão)</option>
							<option value="left" <?php selected( $settings['position'], 'left' ); ?>>Canto Inferior Esquerdo</option>
						</select>
					</td>
				</tr>
			</table>
		</div>

		<!-- 3. COMPORTAMENTO & TEMPOS -->
		<div class="card" style="margin-top: 0; padding: 20px; border-radius: 6px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
			<h2 style="font-size: 15px; margin-top: 0; border-bottom: 1px solid #ccd0d4; padding-bottom: 8px; font-weight: 600;">
				<span class="dashicons dashicons-clock" style="vertical-align: middle; margin-right: 4px;"></span> Comportamento & Timers Locais
			</h2>

			<table class="form-table" style="margin-top: 10px;">
				<tr>
					<th scope="row"><label for="badge_delay">Exibir notificação após</label></th>
					<td>
						<input type="number" name="collector[badge_delay]" id="badge_delay" value="<?php echo esc_attr( $settings['badge_delay'] ); ?>" class="small-text" min="0"> segundos
						<p class="description">Tempo até surgir o indicador visual vermelho "1" sobre o balão flutuante.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="auto_open_enabled">Abertura Automática</label></th>
					<td>
						<label>
							<input type="checkbox" name="collector[auto_open_enabled]" id="auto_open_enabled" value="1" <?php checked( ! empty( $settings['auto_open_enabled'] ) ); ?>>
							Abrir o chat automaticamente para o visitante
						</label>
					</td>
				</tr>

				<tr id="row-auto-open-delay" style="<?php echo empty( $settings['auto_open_enabled'] ) ? 'display:none;' : ''; ?>">
					<th scope="row"><label for="auto_open_delay">Abrir chat após</label></th>
					<td>
						<input type="number" name="collector[auto_open_delay]" id="auto_open_delay" value="<?php echo esc_attr( $settings['auto_open_delay'] ); ?>" class="small-text" min="1"> segundos
						<p class="description">Abre o chat automaticamente apenas 1 vez por sessão para não incomodar o usuário.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="notification_emails">E-mails para Notificação</label></th>
					<td>
						<input type="text" name="collector[notification_emails]" id="notification_emails" value="<?php echo esc_attr( $settings['notification_emails'] ); ?>" class="large-text" placeholder="Ex: comercial@empresa.com.br, gestor@empresa.com.br">
						<p class="description">Destinatários que receberão o aviso assim que o lead concluir o atendimento. Separe múltiplos e-mails por vírgula. Se deixado em branco, enviará automaticamente para o e-mail do administrador do site (<strong><?php echo esc_html( get_option( 'admin_email' ) ); ?></strong>).</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="final_message">Mensagem de Conclusão</label></th>
					<td>
						<textarea name="collector[final_message]" id="final_message" rows="2" class="large-text"><?php echo esc_textarea( $settings['final_message'] ); ?></textarea>
						<p class="description">Mensagem exibida logo após o envio com sucesso. Suporta a variável <code>{nome}</code>.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- 4. CONSTRUTOR DE FLUXO DE PERGUNTAS -->
		<div class="card" style="margin-top: 0; padding: 20px; border-radius: 6px; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
			<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ccd0d4; padding-bottom: 10px;">
				<h2 style="font-size: 15px; margin: 0; font-weight: 600;">
					<span class="dashicons dashicons-format-chat" style="vertical-align: middle; margin-right: 4px;"></span> Construtor de Fluxo de Perguntas
				</h2>
				<div style="display: flex; gap: 8px;">
					<button type="button" class="button" id="hub-btn-restore-default-flow">Restaurar Fluxo Padrão</button>
					<button type="button" class="button button-primary" id="hub-btn-add-step">+ Adicionar Pergunta</button>
				</div>
			</div>

			<p style="color: #646970; font-size: 12px; margin: 12px 0 15px;">
				Configure a sequência de mensagens que o atendente fará. Use <code>{nome}</code> nas perguntas posteriores para personalizar a conversa.
			</p>

			<div id="hub-flow-container" style="display: flex; flex-direction: column; gap: 12px;">
				<!-- As perguntas serão renderizadas via JavaScript interativo -->
			</div>
			
			<input type="hidden" name="collector[flow_json]" id="hub-flow-json" value="<?php echo esc_attr( wp_json_encode( $settings['flow'], JSON_UNESCAPED_UNICODE ) ); ?>">
		</div>

		<!-- 5. BARRA DE AÇÕES INFERIOR -->
		<div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; padding-bottom: 30px;">
			<div>
				<button type="button" class="button button-secondary button-hero" id="hub-btn-preview-collector" style="display: inline-flex; align-items: center; gap: 6px;">
					<span class="dashicons dashicons-visibility" style="font-size: 20px; width: 20px; height: 20px;"></span> Visualizar Atendente (Preview)
				</button>
			</div>
			<div>
				<button type="submit" class="button button-primary button-hero">
					Salvar Configurações
				</button>
			</div>
		</div>

	</form>
</div>

<!-- MODAL DE PREVIEW DO CAPTADOR NO ADMIN -->
<div id="hub-preview-modal" style="display: none; position: fixed; z-index: 1000000; left: 0; top: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.6); align-items: center; justify-content: center;">
	<div style="position: relative; width: 420px; max-width: 95%; background: transparent;">
		<div style="display: flex; justify-content: flex-end; margin-bottom: 8px;">
			<button type="button" class="button" id="hub-btn-close-preview" style="background: #fff; border-radius: 20px; font-weight: bold;">✕ Fechar Simulação</button>
		</div>
		<div id="hub-preview-embed-container" style="position: relative; height: 560px;">
			<!-- Container renderizado pelo widget em modo preview -->
		</div>
	</div>
</div>

<style>
.hub-switch input:checked + .hub-slider { background-color: #1b4d3e; }
.hub-switch input:checked + .hub-slider:before { transform: translateX(24px); }
.hub-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
.hub-flow-card { background: #fdfdfd; border: 1px solid #ccd0d4; border-radius: 6px; padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; }
.hub-flow-card.step-branching { border-left: 4px solid #dba617; }
</style>

<script>
jQuery(document).ready(function($) {

	// Toggle da linha de tempo de auto-abertura
	$('#auto_open_enabled').on('change', function() {
		if ($(this).is(':checked')) {
			$('#row-auto-open-delay').show();
		} else {
			$('#row-auto-open-delay').hide();
		}
	});

	// Media Library para Avatar
	var mediaFrame;
	$('#hub-btn-upload-avatar').on('click', function(e) {
		e.preventDefault();
		if (mediaFrame) {
			mediaFrame.open();
			return;
		}
		mediaFrame = wp.media({
			title: 'Selecionar Foto do Atendente',
			button: { text: 'Usar esta foto' },
			multiple: false
		});
		mediaFrame.on('select', function() {
			var attachment = mediaFrame.state().get('selection').first().toJSON();
			$('#hub-avatar-id').val(attachment.id);
			var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
			$('#hub-avatar-preview').html('<img src="' + thumb + '" style="width:100%;height:100%;object-fit:cover;">');
			$('#hub-btn-remove-avatar').show();
		});
		mediaFrame.open();
	});

	$('#hub-btn-remove-avatar').on('click', function(e) {
		e.preventDefault();
		$('#hub-avatar-id').val(0);
		$('#hub-avatar-preview').html('<span class="dashicons dashicons-format-image" style="color: #a7aaad; font-size: 24px; width: 24px; height: 24px;"></span>');
		$(this).hide();
	});

	// Flow Builder Logic
	var flowData = [];
	try {
		flowData = JSON.parse($('#hub-flow-json').val()) || [];
	} catch(e) {
		flowData = [];
	}

	function renderFlowCards() {
		var container = $('#hub-flow-container');
		container.empty();

		if (flowData.length === 0) {
			container.html('<div style="color:#646970; font-style:italic; padding:15px; text-align:center;">Nenhuma pergunta configurada no fluxo. Clique em "+ Adicionar Pergunta" ou "Restaurar Fluxo Padrão".</div>');
			return;
		}

		flowData.forEach(function(step, index) {
			var typeLabels = {
				'text': 'Texto Curto',
				'textarea': 'Texto Longo',
				'phone': 'WhatsApp / Telefone',
				'email': 'E-mail',
				'single_choice': 'Escolha Única (Botões)',
				'boolean': 'Sim / Não'
			};
			var mappingLabels = {
				'name': 'Nome (Lead)',
				'phone': 'Telefone (Lead)',
				'email': 'E-mail (Lead)',
				'notes': 'Observação / Projeto',
				'custom': 'Campo Personalizado'
			};

			var card = $('<div class="hub-flow-card"></div>');
			if (step.type === 'single_choice' && step.options && step.options.length > 0) {
				card.addClass('step-branching');
			}

			var topRow = $('<div style="display:flex; justify-content:space-between; align-items:center;"></div>');
			topRow.append('<div><strong>#' + (index + 1) + ' [' + (typeLabels[step.type] || step.type) + ']</strong> <span style="color:#646970; font-size:11px; margin-left:8px;">Destino: <strong>' + (mappingLabels[step.mapping] || step.mapping) + '</strong></span>' + (step.required ? ' <span style="color:#d63638; font-size:11px;">(Obrigatório)</span>' : '') + '</div>');

			var actions = $('<div style="display:flex; gap:4px;"></div>');
			if (index > 0) {
				actions.append('<button type="button" class="button button-small hub-btn-move-up" data-index="' + index + '" title="Mover para Cima">▲</button>');
			}
			if (index < flowData.length - 1) {
				actions.append('<button type="button" class="button button-small hub-btn-move-down" data-index="' + index + '" title="Mover para Baixo">▼</button>');
			}
			actions.append('<button type="button" class="button button-small hub-btn-edit-step" data-index="' + index + '">Editar</button>');
			actions.append('<button type="button" class="button button-small button-link-delete hub-btn-delete-step" data-index="' + index + '">Excluir</button>');

			topRow.append(actions);
			card.append(topRow);

			// Mensagem / Pergunta
			card.append('<div style="font-size:13px; color:#1d2327; background:#fff; padding:8px 12px; border:1px solid #e2e4e7; border-radius:4px;">💬 "' + $('<div>').text(step.message).html() + '"</div>');

			// Detalhes das Opções de Escolha Única
			if (step.type === 'single_choice' && step.options && step.options.length > 0) {
				var optSummary = $('<div style="font-size:11px; color:#50575e; display:flex; gap:8px; flex-wrap:wrap; margin-top:2px;"></div>');
				optSummary.append('<span>Opções:</span>');
				step.options.forEach(function(o) {
					var badge = $('<span style="background:#edf2f7; padding:2px 8px; border-radius:12px; border:1px solid #cbd5e0;"></span>');
					badge.text(o.label + (o.next_step_id ? ' ➔ ' + o.next_step_id : ''));
					optSummary.append(badge);
				});
				card.append(optSummary);
			}

			container.append(card);
		});

		$('#hub-flow-json').val(JSON.stringify(flowData));
	}

	renderFlowCards();

	// Ações do Flow Builder
	$(document).on('click', '.hub-btn-move-up', function() {
		var idx = parseInt($(this).data('index'), 10);
		if (idx > 0) {
			var temp = flowData[idx - 1];
			flowData[idx - 1] = flowData[idx];
			flowData[idx] = temp;
			renderFlowCards();
		}
	});

	$(document).on('click', '.hub-btn-move-down', function() {
		var idx = parseInt($(this).data('index'), 10);
		if (idx < flowData.length - 1) {
			var temp = flowData[idx + 1];
			flowData[idx + 1] = flowData[idx];
			flowData[idx] = temp;
			renderFlowCards();
		}
	});

	$(document).on('click', '.hub-btn-delete-step', function() {
		var idx = parseInt($(this).data('index'), 10);
		if (confirm('Tem certeza que deseja excluir esta pergunta?')) {
			flowData.splice(idx, 1);
			renderFlowCards();
		}
	});

	$('#hub-btn-restore-default-flow').on('click', function() {
		if (confirm('Deseja restaurar o fluxo de perguntas para a sequência padrão recomendada?')) {
			flowData = <?php echo wp_json_encode( \Hub\Core\Services\LeadCollectorService::get_default_flow(), JSON_UNESCAPED_UNICODE ); ?>;
			renderFlowCards();
		}
	});

	// Adicionar / Editar Pergunta (Modal Simples / Prompt)
	$('#hub-btn-add-step').on('click', function() {
		var msg = prompt('Digite o texto da pergunta que o atendente fará:');
		if (!msg) return;

		var newId = 'step_' + Date.now();
		flowData.push({
			id: newId,
			message: msg,
			type: 'text',
			mapping: 'custom',
			required: true,
			options: []
		});
		renderFlowCards();
	});

	$(document).on('click', '.hub-btn-edit-step', function() {
		var idx = parseInt($(this).data('index'), 10);
		var step = flowData[idx];
		var newMsg = prompt('Editar mensagem da pergunta:', step.message);
		if (newMsg !== null && newMsg.trim() !== '') {
			step.message = newMsg.trim();
			renderFlowCards();
		}
	});

	// Preview Interativa no Admin
	$('#hub-btn-preview-collector').on('click', function() {
		var previewConfig = {
			api_url: '',
			attendant_name: $('#attendant_name').val() || 'Larissa',
			attendant_role: $('#attendant_role').val() || 'Especialista em Projetos',
			attendant_avatar_url: $('#hub-avatar-preview img').attr('src') || '',
			primary_color: $('#primary_color').val() || '#1b4d3e',
			position: 'right',
			badge_delay: 1,
			auto_open_delay: 1,
			auto_open_enabled: true,
			final_message: $('#final_message').val() || 'Obrigado! Entraremos em contato.',
			show_privacy_policy: true,
			privacy_policy_url: '#',
			flow: flowData
		};

		window.HubCollectorConfig = previewConfig;
		$('#hub-preview-embed-container').empty();
		
		// Insere stylesheet do coletor no documento se não presente
		if (!$('#hub-preview-css').length) {
			$('head').append('<link rel="stylesheet" id="hub-preview-css" href="<?php echo esc_url( HUB_URL . 'assets/css/hub-collector.css' ); ?>?v=' + Date.now() + '">');
		}

		$('#hub-preview-modal').css('display', 'flex');

		// Carrega e executa o script do coletor dentro do container de preview
		$.getScript('<?php echo esc_url( HUB_URL . 'assets/js/hub-collector.js' ); ?>?v=' + Date.now());
	});

	$('#hub-btn-close-preview').on('click', function() {
		$('#hub-preview-modal').hide();
		$('#hub-lead-collector-root').remove();
		sessionStorage.removeItem('hub_collector_session');
	});
});
</script>