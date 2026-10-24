<?php
/**
 * Conferência estática da ponte de estágio. Não exige WordPress.
 * php tests/test-pipeline-contact-sync.php
 */

$root = dirname(__DIR__);
$fail = 0;

function check(bool $cond, string $msg): void
{
	global $fail;
	echo ($cond ? 'ok ' : 'FALHA ') . $msg . PHP_EOL;
	if (!$cond) {
		$fail++;
	}
}

$read = static function (string $path) use ($root): string {
	return (string) file_get_contents($root . '/' . $path);
};

$installer = $read('core/Installer.php');
$stage = $read('modules/Pipeline/PipelineModule.php');
$sync = $read('core/Services/Integrations/PipelineContactSync.php');
$form = $read('core/Services/FrontendFormService.php');
$legacy = $read('core/Services/FormService.php');
$collector = $read('core/Services/LeadCollectorService.php');
$mobile = $read('modules/Pipeline/MobilePipeline.php');

check(str_contains($installer, 'shared_contact_id varchar(36) NULL'), 'coluna shared_contact_id no schema');
check(str_contains($stage, "'vitads_sync'"), 'change_stage aceita vitads_sync');
check(str_contains($stage, "array( 'vitpress_admin', 'vitpress_mobile' )"), 'somente admin e mobile disparam o envio');
check(str_contains($stage, 'PipelineContactSync::push_after_human'), 'mudança humana envia o estágio');
check(!str_contains($sync, 'LEAD_QUALIFIED') && !str_contains($sync, 'SALE') && !str_contains($sync, 'FORM_SUBMIT'), 'sincronização não gera evento comercial');
check(str_contains($sync, 'register_lead') && str_contains($form, 'PipelineContactSync::register_lead') && str_contains($legacy, 'PipelineContactSync::register_lead') && str_contains($collector, 'PipelineContactSync::confirm_attendant_lead'), 'formulários pedem o vínculo depois de salvar; o Atendente confirma no pipeline antes de liberar o WhatsApp');
check(strpos($form, '->insert(') < strpos($form, 'PipelineContactSync::register_lead'), 'falha do vínculo não antecede o salvamento do formulário');
check(strpos($collector, '->insert(') < strpos($collector, 'PipelineContactSync::confirm_attendant_lead'), 'o lead do Atendente é salvo antes da confirmação no pipeline (falha do VitAds não o perde)');
check(str_contains($sync, 'pull_board') && str_contains($stage, 'pull_board') && str_contains($mobile, 'pull_board'), 'Pipeline e PWA puxam o estado em lote');
check(str_contains($sync, 'catch ( \Throwable $e )'), 'falha do VitAds não derruba o lead');

if ($fail > 0) {
	echo "RESULTADO: {$fail} falha(s)\n";
	exit(1);
}
echo "RESULTADO: vitpress ok\n";
