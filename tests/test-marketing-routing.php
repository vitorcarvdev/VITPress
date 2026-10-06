<?php
/**
 * Roteamento comercial pelo VitAds. Não chama Google nem Meta.
 * php tests/test-marketing-routing.php
 */

$root = dirname( __DIR__ );
$read = static function ( $path ) use ( $root ) {
	return (string) file_get_contents( $root . '/' . $path );
};

$fail = 0;
$check = static function ( $cond, $msg ) use ( &$fail ) {
	echo ( $cond ? 'ok ' : 'FALHA ' ) . $msg . PHP_EOL;
	if ( ! $cond ) {
		$fail++;
	}
};

$form      = $read( 'core/Services/FrontendFormService.php' );
$collector = $read( 'core/Services/LeadCollectorService.php' );
$events    = $read( 'core/Services/Integrations/VitAdsEventService.php' );
$thanks    = $read( 'templates/page-clean-thank-you.php' );
$traffic   = $read( 'admin/views/vitagencia-trafego.php' );
$admin     = $read( 'admin/views/vitagencia.php' );
$save      = $read( 'modules/VitAgencia/VitAgenciaModule.php' );
$meta      = $read( 'core/Services/Conversions/MetaConversionsProvider.php' );
$retry     = $read( 'core/Services/Conversions/ConversionRetryWorker.php' );
$queue     = $read( 'core/Services/GoogleAdsOfflineQueueService.php' );
$export    = $read( 'core/Services/GoogleAdsOfflineExportService.php' );
$tracker   = $read( 'core/Tracker.php' );
$license   = $read( 'core/Admin.php' );
$pipeline  = $read( 'modules/Pipeline/PipelineModule.php' );

$check( str_contains( $form, "stage_source' => 'vitpress_form'" ) && str_contains( $form, 'get_stage_definitions()' ), 'formulário continua no estágio inicial do pipeline' );
$check( str_contains( $collector, "stage_source' => 'vitpress_attendant'" ) && str_contains( $collector, 'get_stage_definitions()' ), 'atendente continua no estágio inicial do pipeline' );
$check( str_contains( $pipeline, "'presentation'" ), 'pipeline mantém apresentação' );
$check( str_contains( $form, 'VitAdsEventService::send_form_submit(' ), 'formulário envia FORM_SUBMIT' );
$check( str_contains( $collector, 'VitAdsEventService::send_form_submit(' ), 'atendente envia FORM_SUBMIT' );
$check( strpos( $form, 'insert(' ) < strpos( $form, 'send_form_submit(' ), 'falha do VitAds não antecede o salvamento do formulário' );
$check( strpos( $collector, '->insert(' ) < strpos( $collector, 'send_form_submit(' ), 'falha do VitAds não antecede o salvamento do atendente' );
$check( str_contains( $events, "'event_id'    => 'hubva:' . \$lead_id . ':form_submit'" ), 'event_id estável de FORM_SUBMIT' );
$check( str_contains( $events, "'event_type'  => 'FORM_SUBMIT'" ) && str_contains( $events, 'wp_remote_post' ), 'FORM_SUBMIT usa o endpoint VitAds já existente' );
$check( str_contains( $events, 'gclid' ) && str_contains( $events, 'gbraid' ) && str_contains( $events, 'wbraid' ) && str_contains( $events, 'utm_source' ), 'payload conserva clique e UTM' );
$check( str_contains( $form, "'gclid'" ) && str_contains( $collector, "'gclid'" ) && str_contains( $collector, "'landing_url'" ), 'tracking continua gravado no lead' );
$check( ! str_contains( $thanks, 'googletagmanager.com' ) && ! str_contains( $thanks, "gtag('event', 'conversion'" ), 'snippet Google legado não renderiza' );
$check( str_contains( $queue, "public function enqueue_offline_conversion( \$lead_id ) {\n\t\treturn;" ), 'fila CSV não executa' );
$check( str_contains( $export, 'A exportação CSV de compras offline foi desativada' ), 'download CSV não executa' );
$check( str_contains( $meta, "public function is_configured() {\n\t\treturn false;" ) && str_contains( $retry, "private function retry_meta( \$row, \$now ) {\n\t\treturn;" ), 'Meta CAPI direta não executa' );
$check( str_contains( $tracker, "'meta_enabled'  => false" ), 'pixel Meta não é injetado' );
$check( ! str_contains( $traffic, 'Google Ads — Conversão de Contato' ) && ! str_contains( $traffic, 'Google Ads — Compras offline por CSV' ) && ! str_contains( $traffic, 'Meta Ads - Conversions API' ), 'três blocos saíram da interface' );
$check( str_contains( $traffic, 'Conversões e Marketing' ) && str_contains( $traffic, 'VitAds conectado' ) && str_contains( $traffic, 'gerenciadas centralmente pelo VitAds' ), 'bloco VitAds substitui os emissores' );
$check( ! str_contains( $admin, 'Exportar Google Ads' ), 'aba de CSV saiu do menu' );
$check( str_contains( $save, "isset( \$_POST['meta_ads'] )" ) && str_contains( $save, "isset( \$_POST['integrations']['google_ads_offline'] )" ) && ! str_contains( $save, 'unset( $settings[\'meta_ads\'] )' ), 'options antigas não são apagadas ao salvar a aba' );
$check( str_contains( $license, 'LocalLicenseProvider' ) && str_contains( $license, 'isValid()' ), 'licença do Atendente permanece' );
$check( ! str_contains( $events, 'graph.facebook.com' ) && ! str_contains( $events, 'googletagmanager' ), 'FORM_SUBMIT não chama Google nem Meta' );

if ( $fail > 0 ) {
	echo "RESULTADO: {$fail} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: roteamento comercial ok\n";
