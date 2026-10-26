<?php
/**
 * Vínculo do FORM_SUBMIT ao contato do pipeline (shared_contact_id).
 * Isolado: não carrega o WordPress, não usa banco e não faz HTTP (wp_remote_post/get são simulados).
 *   php tests/test-form-submit-shared-contact.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HUB_PATH', dirname( __DIR__ ) . '/' );

	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hub-test-shared-contact.log' );

	$GLOBALS['hub_test_options']  = array();
	$GLOBALS['hub_test_posts']    = array(); // tudo que seria enviado (url + corpo)
	$GLOBALS['hub_test_resolve']  = null;    // resposta simulada do /pipeline/contact/resolve

	class WP_Error {
		public $code;
		public function __construct( $code = 'http_request_failed' ) {
			$this->code = $code;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return 'simulado';
		}
	}

	function get_option( $name, $default = false ) {
		return $GLOBALS['hub_test_options'][ $name ] ?? $default;
	}
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
	function sanitize_email( $value ) {
		return trim( (string) $value );
	}
	function esc_url_raw( $url ) {
		return (string) $url;
	}
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
	function is_wp_error( $value ) {
		return $value instanceof \WP_Error;
	}
	function wp_remote_post( $url, $args ) {
		$GLOBALS['hub_test_posts'][] = array( 'url' => $url, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) );
		if ( false !== strpos( $url, '/pipeline/contact/resolve' ) ) {
			$r = $GLOBALS['hub_test_resolve'];
			if ( $r instanceof \Throwable ) {
				throw $r;
			}
			return $r;
		}
		return array( 'response' => array( 'code' => 202 ), 'body' => '{}' ); // /events
	}
	function wp_remote_get( $url, $args ) {
		throw new \RuntimeException( 'GET inesperado no teste' );
	}
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'];
	}
	function wp_remote_retrieve_body( $response ) {
		return $response['body'];
	}
}

namespace Hub\Core {
	class FakeDb {
		public function prepare( $sql, ...$args ) {
			return $sql;
		}
		public function get_row( $sql ) {
			return Database::$lead;
		}
		public function update( $table, $data, $where, $f = null, $wf = null ) {
			Database::$updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
			return 1;
		}
	}
	class Database {
		public static $lead    = null;
		public static $updates = array();
		public static function db() {
			return new FakeDb();
		}
		public static function table( $name ) {
			return 'wp_hub_' . $name;
		}
	}
}

namespace Hub\Modules\Pipeline {
	class PipelineModule {
		public static function normalize_stage( $stage ) {
			return '' !== (string) $stage ? (string) $stage : 'presentation';
		}
		public static function change_stage() {
			$GLOBALS['hub_test_stage_changes'][] = func_get_args();
		}
	}
}

namespace {
	require HUB_PATH . 'core/Services/Integrations/VitAdsEventService.php';
	require HUB_PATH . 'core/Services/Integrations/PipelineContactSync.php';
}

namespace SharedContactTest {

	use Hub\Core\Database;
	use Hub\Core\Services\Integrations\PipelineContactSync;
	use Hub\Core\Services\Integrations\VitAdsEventService;

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

	const UUID = '269f9b7f-3294-43b3-8ecc-45ca6560d888';

	// ------------------------------------------------------------------ 1. normalize_shared_contact_id
	check( UUID === VitAdsEventService::normalize_shared_contact_id( UUID ), 'normalize: UUID válido em minúsculas passa' );
	check( UUID === VitAdsEventService::normalize_shared_contact_id( strtoupper( UUID ) ), 'normalize: UUID em MAIÚSCULAS vira minúsculas' );
	check( UUID === VitAdsEventService::normalize_shared_contact_id( '  ' . UUID . "\n" ), 'normalize: espaços ao redor são removidos' );
	$invalids = array(
		'vazio'              => '',
		'null'               => null,
		'texto'              => 'nao-e-uuid',
		'curto'              => '269f9b7f-3294-43b3-8ecc-45ca6560d88',
		'longo'              => UUID . '0',
		'com chaves'         => '{' . UUID . '}',
		'sem hifens'         => str_replace( '-', '', UUID ),
		'não hexadecimal'    => 'zzzzzzzz-3294-43b3-8ecc-45ca6560d888',
		'array'              => array( UUID ),
		'inteiro'            => 12345,
		'booleano'           => true,
		'objeto'             => new \stdClass(),
		'injeção'            => UUID . "'; DROP TABLE x;--",
	);
	foreach ( $invalids as $label => $value ) {
		check( '' === VitAdsEventService::normalize_shared_contact_id( $value ), "normalize: {$label} => ''" );
	}

	// ------------------------------------------------------------------ 2. payload do FORM_SUBMIT
	enable_vitads();
	function payload_for( array $lead ) {
		$GLOBALS['hub_test_posts'] = array();
		VitAdsEventService::send_form_submit( 77, $lead );
		return $GLOBALS['hub_test_posts'][0]['body'] ?? null;
	}
	$base_lead = array(
		'name' => 'Teste', 'email' => 'teste@example.test', 'phone' => '(11) 90000-0001',
		'utm_source' => 'google', 'gclid' => 'abc', 'ad_user_data_consent' => 'denied',
	);
	$baseline = payload_for( $base_lead );
	check( is_array( $baseline ) && ! array_key_exists( 'shared_contact_id', $baseline ), 'payload SEM o campo quando o contexto não traz shared_contact_id (ausente)' );
	check( 'hubva:77:form_submit' === $baseline['event_id'], 'event_id continua hubva:<id>:form_submit' );

	$with = payload_for( $base_lead + array( 'shared_contact_id' => UUID ) );
	check( UUID === ( $with['shared_contact_id'] ?? null ), 'payload com UUID válido inclui shared_contact_id' );
	$without_key = $with;
	unset( $without_key['shared_contact_id'] );
	check( $without_key === $baseline, 'o resto do payload fica idêntico (consentimento, atribuição, roteamento, event_id)' );

	$upper = payload_for( $base_lead + array( 'shared_contact_id' => strtoupper( UUID ) ) );
	check( UUID === ( $upper['shared_contact_id'] ?? null ), 'payload com UUID em maiúsculas envia em minúsculas' );

	foreach ( array( 'vazio' => '', 'inválido' => 'abc', 'array' => array( UUID ), 'null' => null, 'inteiro' => 7 ) as $label => $value ) {
		$p = payload_for( $base_lead + array( 'shared_contact_id' => $value ) );
		check( is_array( $p ) && ! array_key_exists( 'shared_contact_id', $p ) && $p === $baseline, "payload NÃO leva o campo quando o valor é {$label}" );
	}
	check( 1 === count( $GLOBALS['hub_test_posts'] ), 'exatamente um envio por FORM_SUBMIT (sem retry)' );

	// ------------------------------------------------------------------ 3. register_lead
	function lead_row( $shared = '' ) {
		return (object) array(
			'id' => 36, 'phone' => '11900000001', 'stage' => 'presentation',
			'stage_updated_at' => '', 'stage_source' => 'vitpress_form', 'shared_contact_id' => $shared,
		);
	}
	function http( $code, $body ) {
		return array( 'response' => array( 'code' => $code ), 'body' => is_string( $body ) ? $body : json_encode( $body ) );
	}
	function run_register( $resolve, $lead = null ) {
		$GLOBALS['hub_test_posts']   = array();
		$GLOBALS['hub_test_resolve'] = $resolve;
		Database::$lead              = $lead ?? lead_row();
		Database::$updates           = array();
		return PipelineContactSync::register_lead( 36 );
	}

	$r = run_register( http( 200, array( 'shared_contact_id' => UUID, 'stage' => 'presentation', 'created' => true ) ) );
	check( UUID === $r, 'resolve válido: devolve o shared_contact_id' );
	check( 1 === count( Database::$updates ) && UUID === Database::$updates[0]['data']['shared_contact_id'] && 36 === Database::$updates[0]['where']['id'], 'resolve válido: o vínculo é gravado no lead (como antes)' );
	check( 1 === count( $GLOBALS['hub_test_posts'] ) && false !== strpos( $GLOBALS['hub_test_posts'][0]['url'], '/pipeline/contact/resolve' ), 'resolve válido: uma única chamada ao resolve' );

	$r = run_register( http( 200, array( 'shared_contact_id' => strtoupper( UUID ) ) ) );
	check( UUID === $r, 'resolve com UUID em maiúsculas: devolve em minúsculas' );

	$r = run_register( http( 200, array( 'shared_contact_id' => UUID ) ), lead_row( UUID ) );
	check( UUID === $r && array() === Database::$updates, 'lead já vinculado ao mesmo contato: devolve o id e não regrava' );

	$r = run_register( http( 200, array( 'ambiguous' => true, 'shared_contact_id' => null ) ) );
	check( '' === $r && array() === Database::$updates, 'resolve ambíguo: devolve vazio e não grava' );
	$r = run_register( http( 200, array( 'ambiguous' => true, 'shared_contact_id' => UUID ) ) );
	check( '' === $r && array() === Database::$updates, 'ambíguo mesmo com id na resposta: devolve vazio e não grava' );

	$r = run_register( http( 503, array( 'success' => false, 'error' => 'schema_outdated' ) ) );
	check( '' === $r && array() === Database::$updates, 'resolve 503: devolve vazio e não grava' );
	$r = run_register( http( 401, array( 'error' => 'unauthorized' ) ) );
	check( '' === $r, 'resolve 401: devolve vazio' );
	$r = run_register( new \WP_Error() );
	check( '' === $r && array() === Database::$updates, 'erro de rede (WP_Error): devolve vazio' );
	$r = run_register( http( 200, '<html>nao-json</html>' ) );
	check( '' === $r && array() === Database::$updates, 'resposta que não é JSON: devolve vazio' );
	$r = run_register( http( 200, array( 'linked' => false, 'shared_contact_id' => null ) ) );
	check( '' === $r, 'resposta sem id: devolve vazio' );
	$r = run_register( new \RuntimeException( 'falha inesperada' ) );
	check( '' === $r, 'exceção durante a chamada: capturada, devolve vazio (comportamento atual de falha preservado)' );

	$r = run_register( http( 200, array( 'shared_contact_id' => 'id-que-nao-e-uuid' ) ) );
	check( '' === $r, 'id inválido vindo do servidor: nunca é devolvido ao FORM_SUBMIT' );
	check( 1 === count( Database::$updates ) && 'id-que-nao-e-uuid' === Database::$updates[0]['data']['shared_contact_id'], 'id inválido vindo do servidor: a gravação no lead segue como antes (comportamento preservado)' );

	enable_vitads( false );
	$r = run_register( http( 200, array( 'shared_contact_id' => UUID ) ) );
	check( '' === $r && array() === $GLOBALS['hub_test_posts'], 'integração desligada: vazio e NENHUMA chamada' );
	enable_vitads();
	$GLOBALS['hub_test_resolve'] = http( 200, array( 'shared_contact_id' => UUID ) );
	Database::$lead              = null;
	$GLOBALS['hub_test_posts']   = array();
	check( '' === PipelineContactSync::register_lead( 36 ) && array() === $GLOBALS['hub_test_posts'], 'lead inexistente: vazio e nenhuma chamada' );

	// ------------------------------------------------------------------ 4. propagação nos chamadores (estático) e ordem preservada
	$root      = dirname( __DIR__ );
	$frontend  = (string) file_get_contents( $root . '/core/Services/FrontendFormService.php' );
	$collector = (string) file_get_contents( $root . '/core/Services/LeadCollectorService.php' );
	$sync_src  = (string) file_get_contents( $root . '/core/Services/Integrations/PipelineContactSync.php' );
	$event_src = (string) file_get_contents( $root . '/core/Services/Integrations/VitAdsEventService.php' );

	check( false !== strpos( $frontend, '$shared_contact_id = \Hub\Core\Services\Integrations\PipelineContactSync::register_lead( $lead_id );' ), 'FrontendFormService guarda o retorno de register_lead' );
	check( false !== strpos( $frontend, "\$vitads_context['shared_contact_id'] = \$shared_contact_id;" ), 'FrontendFormService coloca o vínculo no contexto do FORM_SUBMIT' );
	check( false !== strpos( $frontend, "\$tracking_data, \$data['ad_user_data_consent'] )" ), 'a chamada de vitads_lead_context continua idêntica (consentimento inalterado)' );
	check( strpos( $frontend, 'register_lead(' ) < strpos( $frontend, 'VitAdsEventService::send_form_submit(' ), 'ordem preservada: register_lead antes do FORM_SUBMIT' );
	check( strpos( $frontend, "\$vitads_context['shared_contact_id']" ) < strpos( $frontend, 'VitAdsEventService::send_form_submit(' ), 'o vínculo entra no contexto antes do envio' );
	check( strpos( $frontend, '->insert(' ) < strpos( $frontend, 'register_lead(' ), 'ordem preservada: o lead é gravado antes do registro no pipeline' );
	check( false !== strpos( $collector, "\$sync['shared_contact_id']" ) && strpos( $collector, 'confirm_attendant_lead(' ) < strpos( $collector, "\$event_context['shared_contact_id']" ) && strpos( $collector, "\$event_context['shared_contact_id']" ) < strpos( $collector, 'VitAdsEventService::send_form_submit(' ), 'LeadCollectorService usa o id devolvido por confirm_attendant_lead antes de enviar o FORM_SUBMIT' );
	check( false !== strpos( $event_src, "'hubva:' . \$lead_id . ':form_submit'" ), 'event_id não foi alterado' );
	check( false !== strpos( $event_src, 'normalize_shared_contact_id( $lead[' ), 'dispatch() valida o id antes de enviar' );
	check( false === strpos( $sync_src, "'FORM_SUBMIT'" ) && false === strpos( $sync_src, 'LEAD_QUALIFIED' ), 'a ponte de estágio continua sem gerar evento comercial' );
	check( false === strpos( $frontend . $collector . $event_src, 'inherit_contact' ), 'nenhuma mudança de consentimento/atribuição herdados' );

	if ( $failed > 0 ) {
		echo "RESULTADO: {$failed} falha(s)\n";
		exit( 1 );
	}
	echo "RESULTADO: vínculo do FORM_SUBMIT ok\n";
}
