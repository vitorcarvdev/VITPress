<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lead_singular = $profile->get_label( 'lead_singular', 'Lead' );
$title         = $lead ? 'Editar ' . $lead_singular . ': ' . esc_html( $lead->name ) : 'Nova ' . $lead_singular;

$stages   = $profile->get_pipeline_stages();
$statuses = isset( $profile->get_statuses()['lead'] ) ? $profile->get_statuses()['lead'] : array( 'novo' => 'Novo' );

$is_client = ( $lead && 'won' === \Hub\Modules\Pipeline\PipelineModule::normalize_stage( $lead->stage ) );
$empresa_disabled_attr = $is_client ? '' : 'disabled="disabled" readonly="readonly"';
$empresa_disabled_class = $is_client ? '' : 'hub-disabled-tab';
?>
<div class="wrap hub-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=hub-persons' ) ); ?>" class="page-title-action">&larr; Voltar para Lista</a>
	<hr class="wp-header-end">

	<div class="nav-tab-wrapper" style="margin-top:20px; border-bottom: 0;">
		<a href="#tab-pessoa" class="nav-tab nav-tab-active" id="nav-pessoa">Dados do Lead</a>
		<a href="#tab-empresa" class="nav-tab" id="nav-empresa">Empresa</a>
	</div>

	<div class="card" style="max-width: 800px; margin-top: 0;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hub_save_person">
			<input type="hidden" name="id" value="<?php echo $lead ? esc_attr( $lead->id ) : 0; ?>">
			<?php wp_nonce_field( 'hub_save_person_nonce', 'hub_nonce' ); ?>

			<div id="tab-pessoa" class="hub-tab-content" style="display: block;">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="name">Nome Completo *</label></th>
						<td><input name="name" type="text" id="name" value="<?php echo $lead ? esc_attr( $lead->name ) : ''; ?>" class="regular-text" required></td>
					</tr>

					<tr>
						<th scope="row"><label for="email">E-mail</label></th>
						<td><input name="email" type="email" id="email" value="<?php echo $lead ? esc_attr( $lead->email ) : ''; ?>" class="regular-text"></td>
					</tr>

					<tr>
						<th scope="row"><label for="phone">Telefone / WhatsApp</label></th>
						<td><input name="phone" type="text" id="phone" value="<?php echo $lead ? esc_attr( $lead->phone ) : ''; ?>" class="regular-text"></td>
					</tr>

					<tr>
						<th scope="row"><label for="stage">Estágio do Pipeline</label></th>
						<td>
							<select name="stage" id="stage" class="regular-text">
								<?php foreach ( $stages as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $lead ? \Hub\Modules\Pipeline\PipelineModule::normalize_stage( $lead->stage ) : '', $slug ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="status">Status</label></th>
						<td>
							<select name="status" id="status" class="regular-text">
								<?php foreach ( $statuses as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $lead ? $lead->status : '', $slug ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="source">Origem</label></th>
						<td>
							<select name="source" id="source" class="regular-text">
								<option value="direto" <?php selected( $lead ? $lead->source : '', 'direto' ); ?>>Contato Direto / WhatsApp</option>
								<option value="captador_site" <?php selected( $lead ? $lead->source : '', 'captador_site' ); ?>>Captador do Site</option>
								<option value="site" <?php selected( $lead ? $lead->source : '', 'site' ); ?>>Formulário do Site</option>
								<option value="indicacao" <?php selected( $lead ? $lead->source : '', 'indicacao' ); ?>>Indicação</option>
								<option value="redes_sociais" <?php selected( $lead ? $lead->source : '', 'redes_sociais' ); ?>>Redes Sociais / Anúncios</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="notes">Observações</label></th>
						<td><textarea name="notes" id="notes" rows="4" class="large-text"><?php echo $lead ? esc_textarea( $lead->notes ) : ''; ?></textarea></td>
					</tr>

					<tr <?php echo ! $is_client ? 'style="opacity: 0.6;"' : ''; ?>>
						<th scope="row">
							<label for="revenue_value">Valor da Conversão (R$)</label>
							<?php if ( ! $is_client ) : ?>
								<br><small style="font-weight:normal;color:#666;">(Apenas para estágio Cliente)</small>
							<?php endif; ?>
						</th>
						<td>
							<input name="revenue_value" type="number" step="0.01" id="revenue_value" value="<?php echo $lead && isset($lead->revenue_value) ? esc_attr( $lead->revenue_value ) : '0.00'; ?>" class="regular-text" <?php echo ! $is_client ? 'readonly' : ''; ?>>
							<p class="description">Valor financeiro que alimentará a Receita, Ticket Médio e ROAS.</p>
						</td>
					</tr>
				</table>

								<?php 
				// Fetch contact attempts for this specific lead (only if editing an existing lead)
				$attempts_list = array();
				if ( $lead ) {
					$table_attempts = \Hub\Core\Database::table( 'lead_contact_attempts' );
					$attempts_list = \Hub\Core\Database::db()->get_results( 
						\Hub\Core\Database::db()->prepare( 
							"SELECT * FROM {$table_attempts} WHERE lead_id = %d ORDER BY attempt_number ASC", 
							$lead->id 
						)
					);
				}
				?>
				
								<?php
				$captador_answers = array();
				if ( $lead && ! empty( $lead->custom_data ) ) {
					$custom_decoded = json_decode( $lead->custom_data, true );
					if ( is_array( $custom_decoded ) && ! empty( $custom_decoded['captador_answers'] ) ) {
						$captador_answers = $custom_decoded['captador_answers'];
					}
				}
				?>
				<?php if ( ! empty( $captador_answers ) ) : ?>
					<h3>Respostas do Captador de Leads</h3>
					<div class="card" style="margin-top: 10px; max-width: 800px; padding: 15px; border-left: 4px solid #1b4d3e; margin-bottom: 20px;">
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th style="width: 40%;">Pergunta do Atendente</th>
									<th>Resposta do Lead</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $captador_answers as $ans ) : ?>
									<tr>
										<td><strong><?php echo esc_html( $ans['question'] ); ?></strong></td>
										<td><?php echo esc_html( $ans['answer'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<h3>Histórico de Tentativas de Contato Comercial (CRM)</h3>
				<div class="card" style="margin-top: 10px; max-width: 800px; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
					<?php if ( ! empty( $attempts_list ) ) : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top: 5px;">
							<thead>
								<tr>
									<th style="width: 100px;">Tentativa</th>
									<th style="width: 150px;">Canal</th>
									<th>Estágio no Momento</th>
									<th style="width: 180px;">Data/Hora</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $attempts_list as $attempt ) : ?>
									<?php 
									$att_channel = ( 'whatsapp' === $attempt->channel ) ? 'WhatsApp' : ( ( 'telefone' === $attempt->channel ) ? 'Telefone' : ucfirst( $attempt->channel ) );
									$stage_title = isset( $stages[ $attempt->stage ] ) ? $stages[ $attempt->stage ] : ucfirst( str_replace( '_', ' ', $attempt->stage ) );
									?>
									<tr>
										<td><strong>#<?php echo esc_html( $attempt->attempt_number ); ?></strong></td>
										<td>
											<?php if ( 'whatsapp' === $attempt->channel ) : ?>
												<span class="dashicons dashicons-whatsapp" style="color: #25d366; vertical-align: middle;"></span>
											<?php else : ?>
												<span class="dashicons dashicons-phone" style="color: #2271b1; vertical-align: middle;"></span>
											<?php endif; ?>
											<?php echo esc_html( $att_channel ); ?>
										</td>
										<td><span style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 500;"><?php echo esc_html( $stage_title ); ?></span></td>
										<td><?php echo esc_html( date_i18n( 'd/m/Y H:i:s', strtotime( $attempt->created_at ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description" style="margin: 0; font-style: italic;">Nenhuma tentativa de contato foi registrada para este lead no Hub.</p>
					<?php endif; ?>
				</div>
<h3>Dados de Rastreamento (Google Ads / Meta Ads / UTMs)</h3>
				<p class="description">Valores capturados na primeira visita ou origem da conversão. (Apenas visualização)</p>
				<style>
					.hub-tracking-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 15px; }
					.hub-track-card { background: #f9f9f9; border: 1px solid #e2e4e7; border-radius: 4px; padding: 12px; }
					.hub-track-card h4 { margin: 0 0 10px 0; font-size: 13px; color: #1d2327; }
					.hub-track-item { margin-bottom: 8px; font-size: 13px; }
					.hub-track-item strong { display: block; color: #646970; font-weight: 600; font-size: 11px; text-transform: uppercase; }
					.hub-track-item span { display: block; word-break: break-all; color: #2271b1; }
					.hub-track-empty { color: #a7aaad !important; font-style: italic; }
				</style>
				<div class="hub-tracking-grid">
					<!-- UTMs -->
					<div class="hub-track-card">
						<h4><span class="dashicons dashicons-admin-links"></span> UTMs (Origem)</h4>
						<?php 
						$utms = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
						foreach($utms as $utm): 
							$val = ($lead && isset($lead->$utm)) ? $lead->$utm : '';
						?>
						<div class="hub-track-item">
							<strong><?php echo esc_html($utm); ?></strong>
							<?php if($val): ?>
								<span><?php echo esc_html($val); ?></span>
							<?php else: ?>
								<span class="hub-track-empty">-</span>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>

					<!-- Meta Ads -->
					<div class="hub-track-card">
						<h4><span class="dashicons dashicons-facebook-alt"></span> Meta Ads / CAPI</h4>
						<?php 
						$metas = ['fbclid' => 'Meta Click ID (fbclid)', '_fbc' => 'Meta FBC (_fbc)', '_fbp' => 'Meta FBP (_fbp)'];
						foreach($metas as $meta_key => $meta_label): 
							$val = ($lead && isset($lead->$meta_key)) ? $lead->$meta_key : '';
						?>
						<div class="hub-track-item">
							<strong><?php echo esc_html($meta_label); ?></strong>
							<?php if($val): ?>
								<span><?php echo esc_html($val); ?></span>
							<?php else: ?>
								<span class="hub-track-empty">-</span>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>

					<!-- Google Ads -->
					<div class="hub-track-card">
						<h4><span class="dashicons dashicons-google"></span> Google Ads</h4>
						<div class="hub-track-item">
							<strong>Google Click ID (gclid)</strong>
							<?php $gclid = ($lead && isset($lead->gclid)) ? $lead->gclid : ''; ?>
							<?php if($gclid): ?>
								<span><?php echo esc_html($gclid); ?></span>
							<?php else: ?>
								<span class="hub-track-empty">-</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<div id="tab-empresa" class="hub-tab-content <?php echo esc_attr($empresa_disabled_class); ?>" style="display: none; padding-top: 15px;">
				<?php if ( ! $is_client ) : ?>
					<div class="notice notice-warning inline"><p><strong>Atenção:</strong> Os dados da empresa só poderão ser editados após o Lead atingir o estágio <strong>✅ Cliente</strong> no Pipeline.</p></div>
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label>Tipo de Cadastro</label></th>
						<td>
							<fieldset>
								<label><input type="radio" name="document_type" value="cnpj" <?php checked( $company ? $company->document_type : 'cnpj', 'cnpj' ); ?> <?php echo $empresa_disabled_attr; ?>> CNPJ</label>
								<br>
								<label><input type="radio" name="document_type" value="cpf" <?php checked( $company ? $company->document_type : '', 'cpf' ); ?> <?php echo $empresa_disabled_attr; ?>> CPF</label>
							</fieldset>
						</td>
					</tr>

					<tr id="row_document">
						<th scope="row"><label for="document">CNPJ / CPF</label></th>
						<td>
							<input name="document" type="text" id="document" value="<?php echo $company ? esc_attr( $company->document ) : ''; ?>" class="regular-text" <?php echo $empresa_disabled_attr; ?>>
							<?php if ( $is_client ) : ?>
								<button type="button" id="btnBuscarCnpj" class="button button-secondary">Buscar Dados</button>
							<?php endif; ?>
							<span class="spinner" id="cnpjSpinner"></span>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="company_name">Razão Social / Nome</label></th>
						<td><input name="company_name" type="text" id="company_name" value="<?php echo $company ? esc_attr( $company->name ) : ''; ?>" class="regular-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>

					<tr>
						<th scope="row"><label for="zipcode">CEP</label></th>
						<td><input name="zipcode" type="text" id="zipcode" value="<?php echo $company ? esc_attr( $company->zipcode ) : ''; ?>" class="regular-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>

					<tr>
						<th scope="row"><label for="address">Endereço</label></th>
						<td><input name="address" type="text" id="address" value="<?php echo $company ? esc_attr( $company->address ) : ''; ?>" class="large-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>

					<tr>
						<th scope="row"><label for="number">Número</label></th>
						<td><input name="number" type="text" id="number" value="<?php echo $company ? esc_attr( $company->number ) : ''; ?>" class="small-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>

					<tr>
						<th scope="row"><label for="complement">Complemento</label></th>
						<td><input name="complement" type="text" id="complement" value="<?php echo $company ? esc_attr( $company->complement ) : ''; ?>" class="regular-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>

					<tr>
						<th scope="row"><label for="neighborhood">Bairro</label></th>
						<td><input name="neighborhood" type="text" id="neighborhood" value="<?php echo $company ? esc_attr( $company->neighborhood ) : ''; ?>" class="regular-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>

					<tr>
						<th scope="row"><label for="city">Cidade</label></th>
						<td><input name="city" type="text" id="city" value="<?php echo $company ? esc_attr( $company->city ) : ''; ?>" class="regular-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>

					<tr>
						<th scope="row"><label for="state">Estado (UF)</label></th>
						<td><input name="state" type="text" id="state" value="<?php echo $company ? esc_attr( $company->state ) : ''; ?>" class="small-text" <?php echo $empresa_disabled_attr; ?>></td>
					</tr>
				</table>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary button-large">Salvar Registro</button>
			</p>
		</form>
	</div>
</div>

<style>
.hub-disabled-tab table, .hub-disabled-tab input, .hub-disabled-tab textarea {
	opacity: 0.6;
	pointer-events: none;
}
</style>

<script>
jQuery(document).ready(function($) {
	// Tabs
	$('.nav-tab').on('click', function(e) {
		e.preventDefault();
		$('.nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.hub-tab-content').hide();
		var target = $(this).attr('href');
		$(target).fadeIn();
	});

	// CNPJ Fetch
	$('#btnBuscarCnpj').on('click', function() {
		var cnpj = $('#document').val().replace(/\D/g, '');
		var docType = $('input[name="document_type"]:checked').val();

		if (docType !== 'cnpj') {
			alert('Selecione CNPJ para usar a busca automática.');
			return;
		}

		if (cnpj.length !== 14) {
			alert('CNPJ inválido.');
			return;
		}

		$('#cnpjSpinner').addClass('is-active');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'hub_lookup_company',
				cnpj: cnpj,
				hub_nonce: '<?php echo wp_create_nonce("hub_lookup_company"); ?>'
			},
			success: function(response) {
				$('#cnpjSpinner').removeClass('is-active');
				if (response.success) {
					var data = response.data;
					$('#company_name').val(data.name || '');
					$('#zipcode').val(data.zipcode || '');
					$('#address').val(data.address || '');
					$('#number').val(data.number || '');
					$('#complement').val(data.complement || '');
					$('#neighborhood').val(data.neighborhood || '');
					$('#city').val(data.city || '');
					$('#state').val(data.state || '');
				} else {
					alert(response.data.message || 'Erro ao buscar CNPJ.');
				}
			},
			error: function() {
				$('#cnpjSpinner').removeClass('is-active');
				alert('Erro de comunicação com o servidor.');
			}
		});
	});

	// Show/Hide fields based on doc type
	$('input[name="document_type"]').on('change', function() {
		if ($(this).val() === 'cpf') {
			$('#btnBuscarCnpj').hide();
			$('label[for="company_name"]').text('Nome Completo');
		} else {
			$('#btnBuscarCnpj').show();
			$('label[for="company_name"]').text('Razão Social / Nome Fantasia');
		}
	}).trigger('change');
});
</script>
