<?php
namespace Hub\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Política de captação de leads do VitPress.
 *
 * Regra: a captação (gravação do lead) e o evento de conversão correspondente (FORM_SUBMIT, hub/lead_created)
 * têm origem no Atendente (LeadCollectorService). Os formulários independentes de lead ficam bloqueados:
 * o shortcode [hub_form] (FormService e FrontendFormService), a rota REST hub/v1/leads e a ação AJAX hub_submit_form
 * não renderizam, não gravam lead, não enviam e-mail, não registram contato no pipeline e não emitem conversão.
 *
 * O shortcode e as rotas continuam registrados de propósito: páginas já publicadas não exibem o texto "[hub_form]"
 * e quem ainda chamar a rota recebe 410 em vez de 404.
 *
 * Reabertura temporária e consciente (rollback controlado), somente por código:
 *   add_filter( 'hub/allow_standalone_lead_forms', '__return_true' );
 */
class LeadCapturePolicy {

	const ALLOW_FILTER    = 'hub/allow_standalone_lead_forms';
	const BLOCKED_CODE    = 'standalone_form_disabled';
	const BLOCKED_MESSAGE = 'Este formulário foi desativado. A captação de contatos é feita pelo atendente do site.';

	/**
	 * Formulários independentes de lead podem capturar? Padrão: não.
	 *
	 * @return bool
	 */
	public static function standalone_forms_allowed() {
		return true === apply_filters( self::ALLOW_FILTER, false );
	}
}
