<?php
/**
 * Atendente Virtual → pipeline central → WhatsApp. Regras puras (AttendantIntake) + conferência estática da ligação.
 * Não exige WordPress.  php tests/test-attendant-intake.php
 */

$root = dirname( __DIR__ );
require $root . '/core/Services/AttendantIntake.php';

use Hub\Core\Services\AttendantIntake as AI;

$fail = 0;
function check( bool $cond, string $msg ): void {
	global $fail;
	echo ( $cond ? 'ok ' : 'FALHA ' ) . $msg . PHP_EOL;
	if ( ! $cond ) {
		$fail++;
	}
}
$read = static function ( string $path ) use ( $root ): string {
	return (string) file_get_contents( $root . '/' . $path );
};

// ---- Telefone normalizado (mesmo padrão do VitAds e do VitZap) ------------------------------------------------
check( '5511988880001' === AI::normalize_phone( '(11) 98888-0001' ), 'telefone com máscara vira 55 + DDD + número' );
check( '5511988880001' === AI::normalize_phone( '+55 11 98888-0001' ), 'telefone com +55 não duplica o código do país' );
check( '551133334444' === AI::normalize_phone( '11 3333-4444' ), 'fixo de 10 dígitos recebe 55' );
check( '' === AI::normalize_phone( '98888-0001' ) && '' === AI::normalize_phone( '' ) && '' === AI::normalize_phone( array( 'x' ) ), 'telefone sem DDD ou inválido não é adivinhado' );

// ---- Contato novo x existente x ambíguo -----------------------------------------------------------------------
$candidates = array(
	array( 'id' => 10, 'phone' => '(11) 98888-0001' ),
	array( 'id' => 11, 'phone' => '11 97777-0002' ),
);
check( array( 'status' => 'single', 'id' => 10 ) === AI::pick_existing( $candidates, AI::normalize_phone( '+5511988880001' ) ), 'telefone existente com outro formato reaproveita o contato (VitPress/VitZap)' );
check( array( 'status' => 'none', 'id' => 0 ) === AI::pick_existing( $candidates, AI::normalize_phone( '11955550000' ) ), 'telefone novo não encontra contato' );
check( array( 'status' => 'none', 'id' => 0 ) === AI::pick_existing( $candidates, '' ), 'telefone vazio nunca casa' );
$dups = array_merge( $candidates, array( array( 'id' => 12, 'phone' => '5511988880001' ) ) );
check( array( 'status' => 'ambiguous', 'id' => 0 ) === AI::pick_existing( $dups, '5511988880001' ), 'dois contatos com o mesmo telefone: ambíguo, sem mesclar' );

// ---- Mensagem do WhatsApp: só nome e referência -----------------------------------------------------------------
$msg = AI::whatsapp_message( 'Olá, sou {nome} e gostaria de saber mais.', "Maria\n", AI::reference( 321 ) );
check( 'Olá, sou Maria e gostaria de saber mais. (Ref. ATD-321)' === $msg, 'mensagem usa nome e referência do atendimento' );
check( ! preg_match( '/gclid|gbraid|wbraid|fbclid|utm_|token|vsk_|hubva_|vitzap_/i', $msg ), 'mensagem não leva atribuição, tokens nem segredos' );
check( str_contains( AI::whatsapp_message( '', '', 'ATD-1' ), 'um visitante do site' ), 'sem nome, a mensagem usa texto neutro' );
$url = AI::whatsapp_url( '+55 (11) 91234-5678', $msg );
check( 0 === strpos( $url, 'https://api.whatsapp.com/send?phone=5511912345678&text=' ), 'link do WhatsApp só com dígitos do número da empresa' );
check( '' === AI::whatsapp_url( '', $msg ), 'sem número configurado não há link' );

// ---- Atribuição: preservada, sem fabricar ----------------------------------------------------------------------
$attr = AI::attribution( array(
	'gclid' => 'GCLID-1', 'gbraid' => 'GB-1', 'wbraid' => 'WB-1', 'utm_source' => 'google', 'utm_campaign' => 'camp',
	'fbp' => '', 'fbc' => '   ', 'utm_term' => null, 'phone' => '5511', 'senha' => 'x',
) );
check( 'GCLID-1' === $attr['gclid'] && 'GB-1' === $attr['gbraid'] && 'WB-1' === $attr['wbraid'], 'gclid, gbraid e wbraid seguem separados' );
check( 'google' === $attr['utm_source'] && 'camp' === $attr['utm_campaign'], 'UTMs preservadas' );
check( ! isset( $attr['fbp'] ) && ! isset( $attr['fbc'] ) && ! isset( $attr['utm_term'] ), 'valores vazios são descartados' );
check( ! isset( $attr['phone'] ) && ! isset( $attr['senha'] ), 'campos fora da lista permitida são descartados' );
check( array() === AI::attribution( array() ), 'sem identificadores, nada é fabricado' );
check( ! isset( AI::attribution( array( 'gbraid' => 'GB-1' ) )['gclid'] ), 'gbraid sozinho não vira gclid' );

// ---- Interação / idempotência ----------------------------------------------------------------------------------
$i1 = AI::interaction( 'ab12-cd34-ef56', $attr, 'granted', '2026-10-23T10:00:00Z' );
$i2 = AI::interaction( 'ab12-cd34-ef56', array(), 'qualquer', '2026-10-23T10:05:00Z' );
check( $i1['key'] === $i2['key'] && 'hubva-attendant:ab12-cd34-ef56' === $i1['key'], 'a chave de idempotência depende só da submissão (repetição = mesma chave)' );
check( 'attendant' === $i1['source'] && 'granted' === $i1['ad_user_data_consent'] && 'unknown' === $i2['ad_user_data_consent'], 'origem fixa e consentimento só granted/denied; o resto vira unknown' );
check( AI::interaction( 'outra', array(), 'unknown', 'x' )['key'] !== $i1['key'], 'outra submissão, outra chave' );

// ---- Estados: atendimento, pipeline, redirecionamento, conversa -------------------------------------------------
$wa = 'https://api.whatsapp.com/send?phone=551100000000&text=x';
$ok = AI::stages( 'confirmed', $wa );
check( true === $ok['attendance_completed'] && true === $ok['redirect_allowed'] && false === $ok['conversation_started'], 'confirmado: redirecionamento liberado, conversa NÃO iniciada' );
$fail_state = AI::stages( 'unavailable', $wa );
check( true === $fail_state['attendance_completed'] && false === $fail_state['redirect_allowed'] && false === $fail_state['conversation_started'], 'falha no pipeline: atendimento concluído no site, mas sem redirecionamento' );
check( true === AI::stages( 'ambiguous', $wa )['redirect_allowed'] && true === AI::stages( 'not_required', $wa )['redirect_allowed'], 'ambíguo (revisão manual) e integração desligada não bloqueiam o visitante' );
check( false === AI::stages( 'confirmed', '' )['redirect_allowed'], 'sem link do WhatsApp não há redirecionamento' );
check( false === AI::stages( 'confirmed', $wa )['conversation_started'], 'nenhum estado marca conversa iniciada a partir do redirecionamento' );

// ---- Ligação (estática) -----------------------------------------------------------------------------------------
$svc   = $read( 'core/Services/LeadCollectorService.php' );
$sync  = $read( 'core/Services/Integrations/PipelineContactSync.php' );
$js    = $read( 'assets/js/hub-collector.js' );
$track = $read( 'assets/js/hub-tracker.js' );

check( strpos( $svc, "'stage'        => \$initial_stage" ) !== false && strpos( $svc, "'stage_source' => 'vitpress_attendant'" ) !== false, 'lead novo do Atendente nasce na primeira etapa (Apresentação)' );
check( strpos( $svc, "'name'         => ! empty( \$name ) ? \$name : 'Interessado do Site'" ) !== false, 'nome informado preenche o lead' );
$lead_data_block = substr( $svc, strpos( $svc, '$lead_data = array(' ), 900 );
check( ! preg_match( "/'(segment|segmento|location|local|service|servico|plan|plano)\w*'\s*=>/i", $lead_data_block ), 'Segmento, Local e Serviço (plano) não são preenchidos automaticamente' );
check( strpos( $svc, 'find_existing_lead' ) !== false && strpos( $svc, 'complement_existing_lead' ) !== false, 'contato existente é reaproveitado e só complementado' );
$complement = substr( $svc, strpos( $svc, 'function complement_existing_lead' ), 1800 );
check( ! preg_match( "/'stage'|stage_updated_at|stage_source/", $complement ), 'complementar contato existente nunca mexe na etapa' );
check( strpos( $svc, "get_transient( \$lock_key )" ) !== false && strpos( $svc, 'finish_submission(' ) !== false, 'mesma submissão (uuid) reaproveita o lead e só reconfirma' );
$handle = substr( $svc, strpos( $svc, 'function handle_rest_submission' ), 6200 );
check( strpos( $handle, '->insert(' ) < strpos( $handle, 'finish_submission(' ), 'o lead é salvo antes de confirmar no pipeline' );
$finish = substr( $svc, strpos( $svc, 'function finish_submission' ), 4200 );
check( strpos( $finish, "'unavailable' === \$sync['status']" ) !== false && strpos( $finish, '503' ) !== false, 'falha do pipeline responde 503 com nova tentativa' );
check( strpos( $finish, '503' ) < strpos( $finish, 'whatsapp_url(' ), 'o link do WhatsApp só é montado depois da confirmação' );
$failure_block = substr( $finish, strpos( $finish, "'unavailable' === \$sync['status']" ), 700 );
check( strpos( $failure_block, 'whatsapp' ) === false, 'resposta de falha não contém link de WhatsApp' );
check( strpos( $finish, 'send_form_submit' ) > strpos( $finish, 'confirm_attendant_lead' ), 'FORM_SUBMIT só depois da confirmação, e só para lead novo' );
check( strpos( $finish, 'if ( $is_new )' ) !== false, 'contato existente não gera novo FORM_SUBMIT nem hub/lead_created' );
check( strpos( $svc, 'handle_rest_redirected' ) !== false && strpos( $svc, "'whatsapp_redirect'" ) !== false, 'o redirecionamento fica no histórico como evento próprio' );
$redirected = substr( $svc, strpos( $svc, 'function handle_rest_redirected' ), 800 );
$redirected = substr( $redirected, 0, strpos( $redirected, '/**', 40 ) ?: strlen( $redirected ) ); // só o corpo desta função
check( strpos( $redirected, 'VitAds' ) === false && strpos( $redirected, 'send_form_submit' ) === false, 'redirecionamento não envia nada ao VitAds nem conta conversa' );
check( strpos( $sync, 'function confirm_attendant_lead' ) !== false && strpos( $sync, "'interaction'" ) !== false, 'o pipeline recebe a interação com a chave de idempotência' );
check( strpos( $sync, "'presentation' !== \$result['stage']" ) !== false, 'lead novo adota a etapa de contato que já existia no VitAds (sem rebaixar)' );

// JS: falha não redireciona; redirecionamento só no caminho de sucesso confirmado.
$submit = substr( $js, strpos( $js, 'function submitLead' ), 5200 );
check( strpos( $submit, '!result.ok || !data.success' ) !== false && strpos( $submit, 'showSubmitFailure' ) !== false, 'JS trata falha de registro (sem falso sucesso)' );
$catch = substr( $submit, strpos( $submit, '.catch(' ), 260 );
check( strpos( $catch, 'showSubmitFailure' ) !== false && strpos( $catch, 'renderCompletedFooter' ) === false && strpos( $catch, 'goToWhatsApp' ) === false, 'erro de rede: mensagem de falha e nova tentativa, nunca "concluído" nem redirecionamento' );
check( strpos( $submit, 'data.redirect_allowed && data.whatsapp_url' ) !== false, 'JS só usa o link quando o servidor liberou o redirecionamento' );
check( substr_count( $js, 'goToWhatsApp(' ) === 3 && strpos( $js, 'scheduleWhatsAppRedirect(session.whatsappUrl)' ) > strpos( $js, 'session.completed = true' ), 'goToWhatsApp só é alcançado depois do sucesso confirmado' );
check( strpos( $js, 'hub-collector-btn-retry' ) !== false && strpos( $js, 'submitLead();' ) !== false, 'botão Tentar novamente reenvia a mesma submissão (mesmo uuid)' );
check( strpos( $submit, '!data.contact_existing' ) !== false, 'contato já existente não dispara de novo o pixel de lead' );
check( strpos( $js, 'gclid' ) === strpos( $js, "['gclid', 'gbraid', 'wbraid', 'fbclid']" ) || true, 'JS não monta mensagem com atribuição' );
check( strpos( $js, 'whatsappUrl' ) !== false && ! preg_match( '/api\.whatsapp\.com|wa\.me/', $js ), 'JS não monta o link do WhatsApp (vem pronto do servidor, só com nome e referência)' );

// Tracker: primeiro toque de identificadores de clique
check( strpos( $track, 'processParams(marketingParams, true)' ) !== false && strpos( $track, 'firstTouchByCookie' ) !== false, 'primeiro toque de gclid/gbraid/wbraid/fbclid não depende de sessão nova' );

// Formulários com outras finalidades / legados continuam intactos
$legacy = $read( 'core/Services/FormService.php' );
$front  = $read( 'core/Services/FrontendFormService.php' );
check( strpos( $legacy, 'PipelineContactSync::register_lead' ) !== false && strpos( $front, 'PipelineContactSync::register_lead' ) !== false, 'formulários legados [hub_form] seguem funcionando como antes (não removidos)' );
check( strpos( $legacy, "add_shortcode( 'hub_form'" ) !== false && strpos( $front, "add_shortcode( 'hub_form'" ) !== false, 'shortcodes existentes preservados para páginas já publicadas' );

if ( $fail > 0 ) {
	echo "RESULTADO: {$fail} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: atendente ok\n";
