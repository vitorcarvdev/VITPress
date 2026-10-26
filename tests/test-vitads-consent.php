<?php
/**
 * Consentimento ad_user_data no FORM_SUBMIT enviado ao VitAds.
 * Isolado: não carrega o WordPress, não usa banco e não faz HTTP (wp_remote_post é simulado).
 * php tests/test-vitads-consent.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HUB_PATH', dirname( __DIR__ ) . '/' );

	$GLOBALS['hub_test_options'] = array();
	$GLOBALS['hub_test_posts']   = array();

	function get_option( $name, $default = false ) {
		return $GLOBALS['hub_test_options'][ $name ] ?? $default;
	}
	function esc_url_raw( $url ) {
		return (string) $url;
	}
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
	function sanitize_email( $value ) {
		return trim( (string) $value );
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
	function home_url( $path = '' ) {
		return 'https://cliente.example' . $path;
	}
	function wp_get_referer() {
		return false;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function is_wp_error( $value ) {
		return false;
	}
	function wp_remote_post( $url, $args ) {
		$GLOBALS['hub_test_posts'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
		return array( 'response' => array( 'code' => 202 ), 'body' => '{}' );
	}
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'];
	}
	function wp_remote_retrieve_body( $response ) {
		return $response['body'];
	}

	class WP_REST_Request {
		private $params;
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}

	require HUB_PATH . 'core/Services/Integrations/VitAdsEventService.php';
	require HUB_PATH . 'core/Services/FrontendFormService.php';
	require HUB_PATH . 'core/Services/LeadCollectorService.php';
}

namespace HubConsentTest {

	use Hub\Core\Services\FrontendFormService;
	use Hub\Core\Services\Integrations\VitAdsEventService;
	use Hub\Core\Services\LeadCollectorService;

	$failed = 0;
	function check( $condition, $message ) {
		global $failed;
		echo ( $condition ? 'ok ' : 'FALHA ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failed++;
		}
	}

	function enable_vitads( $enabled = true ) {
		$GLOBALS['hub_test_options']['hub_settings'] = array(
			'vitads' => array(
				'enabled'     => $enabled,
				'endpoint'    => 'https://vitads.test/api/v1/events',
				'token'       => 'hubva_teste',
				'routing_key' => 'cliente-teste',
			),
		);
	}

	function sent_for( array $lead ) {
		$GLOBALS['hub_test_posts'] = array();
		VitAdsEventService::send_form_submit( 77, $lead );
		return $GLOBALS['hub_test_posts'];
	}

	// Normalização.
	$cases = array(
		array( 'granted', 'granted' ),
		array( ' Granted ', 'granted' ),
		array( 'denied', 'denied' ),
		array( 'DENIED', 'denied' ),
		array( 'unknown', 'unknown' ),
		array( 'UNKNOWN', 'unknown' ),
		array( '', 'unknown' ),
		array( null, 'unknown' ),
		array( 'yes', 'unknown' ),
		array( 'GRANTED_MAYBE', 'unknown' ),
		array( true, 'unknown' ),
		array( 1, 'unknown' ),
		array( array( 'granted' ), 'unknown' ),
	);
	$all = true;
	foreach ( $cases as $case ) {
		$all = $all && VitAdsEventService::normalize_consent( $case[0] ) === $case[1];
	}
	check( $all, 'normalize_consent: só granted/denied explícitos; ausente ou inválido vira unknown' );

	// Envio: consentimento preservado e nunca promovido a granted.
	enable_vitads();
	$base = array(
		'name'             => 'Lead Teste',
		'email'            => 'lead@example.com',
		'phone'            => '(11) 90000-0000',
		'event_source_url' => 'https://cliente.example/contato',
		'utm_source'       => 'google',
		'utm_medium'       => 'cpc',
		'utm_campaign'     => 'campanha',
		'utm_content'      => 'anuncio',
		'utm_term'         => 'termo',
		'gclid'            => 'GCLID_T',
		'wbraid'           => 'WBRAID_T',
		'gbraid'           => 'GBRAID_T',
		'fbp'              => 'fb.1.1.1',
		'fbc'              => 'fb.1.1.abc',
	);
	foreach ( array( 'granted' => 'granted', 'denied' => 'denied', 'unknown' => 'unknown', 'GRANTED_MAYBE' => 'unknown', 'yes' => 'unknown' ) as $input => $expected ) {
		$posts = sent_for( $base + array( 'ad_user_data_consent' => $input ) );
		check( count( $posts ) === 1 && ( $posts[0]['body']['ad_user_data_consent'] ?? null ) === $expected, "envio com '{$input}' transmite '{$expected}'" );
	}
	$missing = sent_for( $base );
	check( count( $missing ) === 1 && ( $missing[0]['body']['ad_user_data_consent'] ?? null ) === 'unknown', 'consentimento ausente transmite unknown, nunca granted' );

	// Demais campos preservados.
	$body = sent_for( $base + array( 'ad_user_data_consent' => 'granted' ) )[0]['body'] ?? array();
	$expected_keys = array( 'event_id', 'event_type', 'routing_key', 'occurred_at', 'ad_user_data_consent', 'event_source_url', 'name', 'email', 'phone',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'wbraid', 'gbraid', 'fbp', 'fbc' );
	$keys = array_keys( $body );
	sort( $keys );
	sort( $expected_keys );
	check( $keys === $expected_keys, 'evento mantém todos os campos anteriores e só acrescenta ad_user_data_consent' );
	check( 'hubva:77:form_submit' === ( $body['event_id'] ?? '' ) && 'FORM_SUBMIT' === ( $body['event_type'] ?? '' ) && 'cliente-teste' === ( $body['routing_key'] ?? '' ),
		'event_id, tipo e routing_key inalterados' );
	check( '11900000000' === ( $body['phone'] ?? '' ) && 'lead@example.com' === ( $body['email'] ?? '' ), 'telefone e e-mail no formato atual' );
	check( 'GCLID_T' === ( $body['gclid'] ?? '' ) && 'GBRAID_T' === ( $body['gbraid'] ?? '' ) && 'WBRAID_T' === ( $body['wbraid'] ?? '' )
		&& 'google' === ( $body['utm_source'] ?? '' ) && 'termo' === ( $body['utm_term'] ?? '' ), 'UTMs e click IDs preservados' );
	check( 'https://vitads.test/api/v1/events' === ( $GLOBALS['hub_test_posts'][0]['url'] ?? '' ), 'endpoint configurado continua sendo usado' );

	enable_vitads( false );
	check( array() === sent_for( $base + array( 'ad_user_data_consent' => 'granted' ) ), 'integração desligada continua sem envio' );

	// Formulário principal: contexto leva o consentimento armazenado.
	$tracking = array( 'utm_source' => 'google', 'gclid' => 'GCLID_F', 'gbraid' => '', 'wbraid' => 'WBRAID_F', '_fbp' => 'fbp_f', '_fbc' => '' );
	$form     = ( new \ReflectionClass( FrontendFormService::class ) )->newInstanceWithoutConstructor();
	$form_ctx = new \ReflectionMethod( FrontendFormService::class, 'vitads_lead_context' );
	$form_ctx->setAccessible( true );
	$request  = new \WP_REST_Request( array( 'conversion_page' => '/contato' ) );
	foreach ( array( 'granted', 'denied', 'unknown' ) as $stored ) {
		$ctx = $form_ctx->invoke( $form, $request, 'Lead', 'lead@example.com', '11900000000', $tracking, $stored );
		check( $stored === ( $ctx['ad_user_data_consent'] ?? null ), "formulário: contexto leva o consentimento armazenado '{$stored}'" );
	}
	$ctx_default = $form_ctx->invoke( $form, $request, 'Lead', 'lead@example.com', '11900000000', $tracking );
	check( 'unknown' === ( $ctx_default['ad_user_data_consent'] ?? null ), 'formulário: sem consentimento informado o contexto fica unknown' );
	check( 'GCLID_F' === $ctx_default['gclid'] && 'WBRAID_F' === $ctx_default['wbraid'] && 'google' === $ctx_default['utm_source']
		&& 'fbp_f' === $ctx_default['fbp'] && 'https://cliente.example/contato' === $ctx_default['event_source_url'],
		'formulário: demais campos do contexto inalterados' );
	$form_src = (string) file_get_contents( HUB_PATH . 'core/Services/FrontendFormService.php' );
	check( false !== strpos( $form_src, "\$tracking_data, \$data['ad_user_data_consent'] )" ), 'formulário: usa o valor gravado no lead ($data)' );

	// Atendente: contexto leva o consentimento armazenado.
	$collector     = ( new \ReflectionClass( LeadCollectorService::class ) )->newInstanceWithoutConstructor();
	$collector_ctx = new \ReflectionMethod( LeadCollectorService::class, 'vitads_lead_context' );
	$collector_ctx->setAccessible( true );
	$lead_data     = array( 'name' => 'Lead', 'email' => 'lead@example.com', 'phone' => '11900000000' );
	foreach ( array( 'granted', 'denied', 'unknown' ) as $stored ) {
		$ctx = $collector_ctx->invoke( $collector, $request, $lead_data + array( 'ad_user_data_consent' => $stored ), $tracking );
		check( $stored === ( $ctx['ad_user_data_consent'] ?? null ), "atendente: contexto leva o consentimento armazenado '{$stored}'" );
	}
	$ctx_missing = $collector_ctx->invoke( $collector, $request, $lead_data, $tracking );
	check( 'unknown' === ( $ctx_missing['ad_user_data_consent'] ?? null ), 'atendente: consentimento ausente no lead fica unknown' );
	check( 'GCLID_F' === $ctx_missing['gclid'] && 'google' === $ctx_missing['utm_source'] && 'lead@example.com' === $ctx_missing['email'],
		'atendente: demais campos do contexto inalterados' );

	// Atendente no navegador: a expressão real de hub-collector.js.
	$js = (string) file_get_contents( HUB_PATH . 'assets/js/hub-collector.js' );
	check( 1 === preg_match( '/ad_user_data_consent:\s*(.+),\r?\n/', $js, $m ), 'hub-collector.js define ad_user_data_consent' );
	$expr = $m[1] ?? '';
	check( false === strpos( $expr, "'granted'" ) && false === strpos( $expr, '.status' ), 'hub-collector.js não presume granted nem lê .status' );
	$node = trim( (string) shell_exec( 'node -v 2>&1' ) );
	if ( 0 === strpos( $node, 'v' ) ) {
		$tmp    = tempnam( sys_get_temp_dir(), 'hubc' );
		$script = $tmp . '.js';
		file_put_contents( $script, 'const cases = {'
			. '"sem banner": {},'
			. '"granted": { HubConsent: { getStatus: () => "granted" } },'
			. '"denied": { HubConsent: { getStatus: () => "denied" } },'
			. '"sem resposta": { HubConsent: { getStatus: () => "unknown" } },'
			. '"objeto sem getStatus": { HubConsent: { status: "granted" } }'
			. '};'
			. 'const out = {};'
			. 'for (const [k, window] of Object.entries(cases)) { out[k] = (' . $expr . '); }'
			. 'process.stdout.write(JSON.stringify(out));' );
		$out = json_decode( (string) shell_exec( 'node ' . escapeshellarg( $script ) ), true );
		@unlink( $script );
		@unlink( $tmp );
		check( is_array( $out )
			&& 'unknown' === $out['sem banner']
			&& 'granted' === $out['granted']
			&& 'denied' === $out['denied']
			&& 'unknown' === $out['sem resposta']
			&& 'unknown' === $out['objeto sem getStatus'],
			'hub-collector.js: sem banner ou sem getStatus envia unknown; com banner envia o estado real' );
	} else {
		echo "ignorado: node indisponível para avaliar hub-collector.js\n";
	}

	$collector_src = (string) file_get_contents( HUB_PATH . 'core/Services/LeadCollectorService.php' );
	check( false !== strpos( $collector_src, "filemtime( \$collector_js )" ), 'hub-collector.js com versão por data do arquivo (sem mudar HUB_VERSION)' );

	if ( $failed > 0 ) {
		echo "RESULTADO: {$failed} falha(s)\n";
		exit( 1 );
	}
	echo "RESULTADO: consentimento vitads ok\n";
}
