<?php
/**
 * (1) Nome oficial VitPress sem remover identificadores legados necessários à compatibilidade;
 * (2) captação por formulário independente bloqueada no ponto de captação (REST e AJAX) e no shortcode,
 *     com o Atendente como origem do FORM_SUBMIT.
 * Isolado: não carrega o WordPress, não usa banco e não faz HTTP/e-mail (tudo simulado).
 *   php tests/test-vitpress-nome-e-captacao.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HUB_PATH', dirname( __DIR__ ) . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );

	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hub-test-nome-captacao.log' );

	$GLOBALS['hub_t']       = array();
	$GLOBALS['hub_options'] = array();
	$GLOBALS['hub_filters'] = array();
	$GLOBALS['hub_hooks']   = array();
	$GLOBALS['wpdb']        = new class() {
		public function suppress_errors( $flag = true ) {}
	};

	function hub_reset_counters() {
		$GLOBALS['hub_t'] = array( 'inserts' => 0, 'register' => 0, 'posts' => array(), 'mails' => array(), 'actions' => array(), 'transients' => 0, 'nonce' => 0 );
	}
	hub_reset_counters();

	class HubTestJsonExit extends \Exception {
		public $data;
		public $status;
		public function __construct( $data, $status ) {
			parent::__construct( 'json_exit' );
			$this->data   = $data;
			$this->status = $status;
		}
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
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
	class WP_REST_Server {
		const CREATABLE = 'POST';
	}

	function get_option( $name, $default = false ) {
		return $GLOBALS['hub_options'][ $name ] ?? $default;
	}
	function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['hub_filters'][ $tag ][] = $callback;
		$GLOBALS['hub_hooks'][]           = array( 'filter', $tag );
	}
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['hub_filters'][ $tag ] ?? array() as $callback ) {
			$value = call_user_func( $callback, $value, ...$args );
		}
		return $value;
	}
	function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['hub_hooks'][] = array( 'action', $tag );
	}
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['hub_hooks'][] = array( 'shortcode', $tag );
	}
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['hub_hooks'][] = array( 'route', $namespace . $route );
	}
	function do_action( $tag, ...$args ) {
		$GLOBALS['hub_t']['actions'][] = $tag;
	}
	function __return_true() {
		return true;
	}
	function wp_enqueue_script( $handle ) {}
	function wp_nonce_field( $a, $b ) {}
	function check_ajax_referer( $a, $b ) {
		$GLOBALS['hub_t']['nonce']++;
	}
	function wp_send_json_error( $data = null, $status = null ) {
		throw new \HubTestJsonExit( $data, $status );
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
	function wp_unslash( $value ) {
		return $value;
	}
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function wp_get_referer() {
		return false;
	}
	function home_url( $path = '' ) {
		return 'https://cliente.example' . $path;
	}
	function current_time( $type ) {
		return '2026-01-01 10:00:00';
	}
	function get_bloginfo( $what ) {
		return 'Site de teste';
	}
	function wp_generate_password( $length = 12, $special = true ) {
		return str_repeat( 'a', $length );
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['hub_t']['transients']++;
		return true;
	}
	function wp_mail( $to, $subject, $body ) {
		$GLOBALS['hub_t']['mails'][] = $to;
		return true;
	}
	function is_wp_error( $value ) {
		return false;
	}
	function wp_remote_post( $url, $args ) {
		$GLOBALS['hub_t']['posts'][] = array( 'url' => $url, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) );
		return array( 'response' => array( 'code' => 202 ), 'body' => '{}' );
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
		public $insert_id = 0;
		public function insert( $table, $data ) {
			$GLOBALS['hub_t']['inserts']++;
			$this->insert_id = 36;
			return 1;
		}
	}
	class Database {
		public static function db() {
			static $db;
			$db = $db ?? new FakeDb();
			return $db;
		}
		public static function table( $name ) {
			return 'wp_hub_' . $name;
		}
		public static function log_history() {}
		public static function get_setting( $key, $default = array() ) {
			return $default;
		}
	}
	class Tracker {
		public static function get_current_tracking_data() {
			return array();
		}
	}
}

namespace Hub\Modules\Pipeline {
	class PipelineModule {
		public static function get_stage_definitions() {
			return array( 'presentation' => array( 'label' => 'Apresentação' ) );
		}
	}
}

namespace Hub\Core\Services\Integrations {
	/** Dublê: conta o registro e devolve um vínculo fixo (o real foi testado em test-form-submit-shared-contact.php). */
	class PipelineContactSync {
		public static function register_lead( $lead_id ) {
			$GLOBALS['hub_t']['register']++;
			return '269f9b7f-3294-43b3-8ecc-45ca6560d888';
		}
	}
}

namespace {
	require HUB_PATH . 'core/Services/Integrations/VitAdsEventService.php';
	require HUB_PATH . 'core/Services/LeadCapturePolicy.php';
	require HUB_PATH . 'core/Services/FrontendFormService.php';
	require HUB_PATH . 'core/Services/FormService.php';
}

namespace NomeCaptacaoTest {

	use Hub\Core\Services\FormService;
	use Hub\Core\Services\FrontendFormService;
	use Hub\Core\Services\LeadCapturePolicy;

	$failed = 0;
	function check( $condition, $message ) {
		global $failed;
		echo ( $condition ? 'ok ' : 'FALHA ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failed++;
		}
	}
	function untouched() {
		$t = $GLOBALS['hub_t'];
		return 0 === $t['inserts'] && 0 === $t['register'] && array() === $t['posts'] && array() === $t['mails'] && array() === $t['actions'] && 0 === $t['transients'];
	}

	$GLOBALS['hub_options']['hub_settings'] = array(
		'forms'  => array( 'receive_email' => 'dest@example.test', 'whatsapp' => '5581900000000', 'send_confirm' => false ),
		'vitads' => array( 'enabled' => true, 'endpoint' => 'https://vitads.test/api/v1/events', 'token' => 'hubva_teste', 'routing_key' => 'cliente-teste' ),
	);
	$valid_request = function () {
		return new \WP_REST_Request( array( 'hub_name' => 'Fulano Teste', 'hub_email' => 'fulano@example.test', 'hub_phone' => '11900000001', 'ad_user_data_consent' => 'granted' ) );
	};

	// ------------------------------------------------------------ 1. Padrão: formulários independentes BLOQUEADOS
	check( false === LeadCapturePolicy::standalone_forms_allowed(), 'política: formulários independentes não são permitidos por padrão' );

	$front = new FrontendFormService();
	hub_reset_counters();
	$res = $front->handle_rest_submission( $valid_request() );
	check( 410 === $res->status && false === $res->data['success'] && LeadCapturePolicy::BLOCKED_CODE === $res->data['code'], 'REST hub/v1/leads com dados válidos: 410 (standalone_form_disabled)' );
	check( untouched(), 'REST bloqueado: nenhum lead gravado, nenhum registro no pipeline, nenhum FORM_SUBMIT, nenhum e-mail, nenhum hub/lead_created' );

	check( '' === $front->render_form_shortcode( array() ), 'FrontendFormService: [hub_form] não renderiza o formulário' );
	$legacy = new FormService();
	check( '' === $legacy->render_shortcode(), 'FormService (legado): [hub_form] não renderiza o formulário' );

	$_POST = array( 'hub_name' => 'Fulano', 'hub_email' => 'f@example.test', 'hub_phone' => '11900000001', 'hub_nonce' => 'x' );
	hub_reset_counters();
	$thrown = null;
	try {
		$legacy->handle_submit();
	} catch ( \HubTestJsonExit $e ) {
		$thrown = $e;
	}
	check( null !== $thrown && 410 === $thrown->status && LeadCapturePolicy::BLOCKED_CODE === ( $thrown->data['code'] ?? '' ), 'AJAX hub_submit_form (legado): 410 antes de qualquer processamento' );
	check( untouched() && 0 === $GLOBALS['hub_t']['nonce'], 'AJAX bloqueado: nada gravado/enviado/registrado e nem o nonce é consumido' );

	// ------------------------------------------------------------ 2. Compatibilidade: pontos de entrada continuam registrados
	$GLOBALS['hub_hooks'] = array();
	new FormService();
	$front->init();
	$front->register_rest_routes();
	$hooks = array_map( static fn ( $h ) => $h[0] . ':' . $h[1], $GLOBALS['hub_hooks'] );
	check( in_array( 'shortcode:hub_form', $hooks, true ), 'o shortcode hub_form segue registrado (páginas antigas não exibem "[hub_form]" no texto)' );
	check( in_array( 'route:hub/v1/leads', $hooks, true ), 'a rota hub/v1/leads segue registrada (responde 410, não 404)' );
	check( in_array( 'action:wp_ajax_nopriv_hub_submit_form', $hooks, true ) && in_array( 'action:wp_ajax_hub_submit_form', $hooks, true ), 'as ações AJAX seguem registradas (respondem 410)' );
	check( in_array( 'action:init', $hooks, true ), 'a página /obrigado-contato e o template de agradecimento seguem como antes (finalidade legítima preservada)' );

	// ------------------------------------------------------------ 3. Reabertura consciente por filtro: o comportamento antigo volta inteiro (um só FORM_SUBMIT)
	add_filter( LeadCapturePolicy::ALLOW_FILTER, '__return_true' );
	check( true === LeadCapturePolicy::standalone_forms_allowed(), 'filtro hub/allow_standalone_lead_forms reabre a captação (somente por código)' );
	hub_reset_counters();
	$res = $front->handle_rest_submission( $valid_request() );
	$t   = $GLOBALS['hub_t'];
	check( 200 === $res->status && true === $res->data['success'] && 36 === $res->data['lead_id'], 'reaberto: o formulário volta a funcionar como antes' );
	check( 1 === $t['inserts'] && 1 === $t['register'] && 1 === count( $t['posts'] ) && 1 === count( $t['mails'] ) && array( 'hub/lead_created' ) === $t['actions'], 'reaberto: um lead, um registro no pipeline, UM FORM_SUBMIT, um e-mail, um hub/lead_created (sem duplicidade)' );
	check( 'hubva:36:form_submit' === ( $t['posts'][0]['body']['event_id'] ?? '' ) && '269f9b7f-3294-43b3-8ecc-45ca6560d888' === ( $t['posts'][0]['body']['shared_contact_id'] ?? '' ), 'reaberto: event_id estável e vínculo do contato presentes' );
	$GLOBALS['hub_filters'] = array();
	hub_reset_counters();
	$res = $front->handle_rest_submission( $valid_request() );
	check( 410 === $res->status && untouched(), 'sem o filtro volta a bloquear' );

	// ------------------------------------------------------------ 4. Atendente continua sendo a origem; sem duplicidade
	$root      = dirname( __DIR__ );
	$collector = (string) file_get_contents( $root . '/core/Services/LeadCollectorService.php' );
	$frontend  = (string) file_get_contents( $root . '/core/Services/FrontendFormService.php' );
	$formsvc   = (string) file_get_contents( $root . '/core/Services/FormService.php' );
	check( false === strpos( $collector, 'LeadCapturePolicy' ), 'o Atendente NÃO passa pela política dos formulários independentes' );
	check( 1 === substr_count( $collector, 'VitAdsEventService::send_form_submit(' ) && strpos( $collector, 'if ( $is_new )' ) < strpos( $collector, 'VitAdsEventService::send_form_submit(' ), 'o Atendente continua emitindo o FORM_SUBMIT, uma vez, só para lead novo' );
	check( false !== strpos( $collector, "'/collector/submit'" ) && false !== strpos( $collector, "'/collector/redirected'" ), 'as rotas do Atendente seguem registradas' );
	$callers = 0;
	$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/core', \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$callers += substr_count( (string) file_get_contents( $file->getPathname() ), 'VitAdsEventService::send_form_submit(' );
		}
	}
	check( 2 === $callers, 'só dois pontos emitem FORM_SUBMIT: o Atendente e o formulário independente (este, bloqueado)' );
	check( strpos( $frontend, 'standalone_forms_allowed()' ) < strpos( $frontend, 'process_rest_submission( $request )' ), 'FrontendFormService: o bloqueio vem antes do processamento' );
	check( substr_count( $frontend, 'standalone_forms_allowed()' ) >= 2 && substr_count( $formsvc, 'standalone_forms_allowed()' ) >= 2, 'os dois serviços bloqueiam renderização e submissão' );
	check( strpos( $frontend, 'standalone_forms_allowed()' ) < strpos( $frontend, 'ob_start()' ) && strpos( $formsvc, 'standalone_forms_allowed()' ) < strpos( $formsvc, 'ob_start()' ), 'o bloqueio vem antes de montar o HTML do formulário (não é só esconder na interface)' );

	// ------------------------------------------------------------ 5. Nome oficial: cabeçalho e textos
	$header = (string) file_get_contents( $root . '/hub.php' );
	check( 1 === preg_match( '/^\s*\*\s*Plugin Name:\s*VitPress\s*$/m', $header ), 'cabeçalho: Plugin Name é VitPress' );
	check( 1 === preg_match( '/^\s*\*\s*Description:.*$/m', $header, $d ) && false === strpos( $d[0], 'Hub' ), 'cabeçalho: a descrição não cita Hub' );
	check( false !== strpos( $header, '@package VitPress' ), 'cabeçalho: @package VitPress' );

	// Nenhuma menção nova a "Hub" como palavra (fora de namespaces/classes e das exceções documentadas).
	$allowed_fragments = array(
		"'Hub VitAgencia'",                          // valor do campo generator nos backups antigos (compatibilidade; nunca exibido)
		"'Hub VitAgência Admin'", "'Hub VitAgência', 'hub_connector'", // rótulos enviados ao Pipeline Central (contrato externo; nunca exibidos neste site)
		'generator VitPress ou Hub VitAgencia',      // comentário que documenta o valor acima
		'# BEGIN Hub PHP Settings', '# END Hub PHP Settings', // marcador já gravado no .htaccess dos sites
	);
	$offenders = array();
	$scan      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $scan as $file ) {
		$path = str_replace( '\\', '/', $file->getPathname() );
		if ( ! in_array( $file->getExtension(), array( 'php', 'js', 'css' ), true ) || false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/.git' ) ) {
			continue;
		}
		foreach ( file( $path ) as $n => $line ) {
			if ( ! preg_match( '/(?<![\\\\\w\/])Hub(?![\w\\\\])/', $line ) ) {
				continue;
			}
			if ( preg_match( '/namespace Hub|^\s*use Hub|\\\\Hub\\\\|Hub\\\\\\\\|Hub\\\\[A-Z]/', $line ) ) {
				continue;
			}
			foreach ( $allowed_fragments as $fragment ) {
				if ( false !== strpos( $line, $fragment ) ) {
					continue 2;
				}
			}
			$offenders[] = str_replace( $root . '/', '', $path ) . ':' . ( $n + 1 );
		}
	}
	check( array() === $offenders, 'nenhuma menção a "Hub" como nome do produto restou' . ( $offenders ? ' -> ' . implode( ', ', array_slice( $offenders, 0, 8 ) ) : '' ) );

	// ------------------------------------------------------------ 6. Identificadores legados PRESERVADOS (compatibilidade com sites e VitAds)
	$read = static function ( $rel ) use ( $root ) {
		return (string) file_get_contents( $root . '/' . $rel );
	};
	$legacy_ids = array(
		'hub.php'                                              => array( "Text Domain: hub", "define( 'HUB_VERSION'", "define( 'HUB_PATH'" ),
		'core/Services/Integrations/VitAdsEventService.php'    => array( "'hubva:' . \$lead_id . ':form_submit'", "'hub_settings'", "'FORM_SUBMIT'" ),
		'core/Services/LeadCollectorService.php'               => array( "'hub/v1'", "'hub_lead_collector'", "'hub/lead_created'" ),
		'core/Services/FrontendFormService.php'                => array( "'hub_form'", "'hub/v1', '/leads'", "'hub/lead_created'" ),
		'core/Services/FormService.php'                        => array( "'hub_form'", "'wp_ajax_nopriv_hub_submit_form'", "'wp_ajax_hub_submit_form'" ),
		'core/Services/Backup/BackupArchiveValidator.php'      => array( "'Hub VitAgencia'", 'hub_legacy_content' ),
		'core/Services/DiagnosticService.php'                  => array( '# BEGIN Hub PHP Settings' ),
		'core/Services/Integrations/HubPipelineConnector.php'  => array( "'hub_connector'", "'Hub Form Submission'" ),
		'core/Installer.php'                                   => array( "'hub_leads'", "'hub_db_version'", "'hub_authorized_agency_user'", 'hub_vitagencia_manage_internal' ),
		'core/class-hub-vitagencia-updater.php'                => array( 'hub-vitagencia/hub.php' ),
		'core/Services/BackupService.php'                      => array( "'hub_schedule_daily_db'", "'hub_s3_worker'" ),
	);
	foreach ( $legacy_ids as $rel => $needles ) {
		$src     = $read( $rel );
		$missing = array_filter( $needles, static fn ( $n ) => false === strpos( $src, $n ) );
		check( array() === $missing, "legado preservado em {$rel}" . ( $missing ? ' - faltando: ' . implode( ', ', $missing ) : '' ) );
	}
	check( 1 === preg_match( "/const ACCEPTED_GENERATORS\s*=\s*array\(\s*'VitPress',\s*'Hub VitAgencia'\s*\)/", $read( 'core/Services/Backup/BackupArchiveValidator.php' ) ), 'backups do VitPress e do Hub VitAgência continuam aceitos' );
	check( false !== strpos( $read( 'core/Services/BackupService.php' ), 'VitPress Database Backup' ) && false === strpos( $read( 'core/Services/BackupService.php' ), '-- Hub Database Backup' ), 'cabeçalho dos novos dumps usa VitPress (os leitores não dependem dele)' );

	// ------------------------------------------------------------ 7. "Página de Conversão Limpa" removida; o que o Atendente usa foi mantido
	$trafego = $read( 'admin/views/vitagencia-trafego.php' );
	$atend_view = $read( 'admin/views/atendente.php' );
	$toolsmod = $read( 'modules/Tools/ToolsModule.php' );
	$module  = $read( 'modules/VitAgencia/VitAgenciaModule.php' );
	check( ! file_exists( $root . '/templates/page-clean-conversion.php' ), 'template da Página de Conversão Limpa removido' );
	check( file_exists( $root . '/templates/page-clean-thank-you.php' ) && false !== strpos( $formsvc, "is_page( 'obrigado-contato' )" ), 'o template e a página /obrigado-contato seguem como antes' );
	check( false === strpos( $formsvc, 'hub_generate_page' ) && false === strpos( $formsvc, 'handle_generate_page' ) && false === strpos( $formsvc, "is_page( 'novo-lead' )" ), 'gerador de página e override do /novo-lead removidos do código' );
	$gone = array( 'Página de Conversão Limpa', 'Gerar Página', '[hub_form]', 'forms[receive_email]', 'forms[send_confirm]', 'forms[show_testimonial]', 'forms[testimonial_', 'hub_generate_page' );
	$left = array_filter( $gone, static fn ( $s ) => false !== strpos( $trafego, $s ) );
	check( array() === $left, 'aba Marketing não exibe mais gerador, shortcode, e-mail interno, confirmação nem depoimento' . ( $left ? ' - restou: ' . implode( ', ', $left ) : '' ) );
	check( false === strpos( $trafego, 'forms[whatsapp]' ) && false !== strpos( $atend_view, 'name="forms[whatsapp]"' ) && false !== strpos( $atend_view, 'name="forms[whatsapp_msg]"' ), 'WhatsApp de destino e mensagem ficam na página do Atendente (não mais na aba Marketing)' );
	check( false !== strpos( $collector, "\$forms['whatsapp']" ) && false !== strpos( $collector, "\$forms['whatsapp_msg']" ), 'o Atendente continua lendo forms.whatsapp e forms.whatsapp_msg' );
	$geral = $read( 'admin/views/vitagencia-geral.php' );
	check( 1 === substr_count( $geral, 'type="checkbox"' ) && false !== strpos( $geral, 'Ativar integração VitAds, VitZap e Pipeline' ) && false === strpos( $geral, 'hub_crm_enabled' ) && false === strpos( $geral, 'marketing_pipeline_enabled' ), 'aba Geral tem uma única chave de ativação (VitAds, VitZap e Pipeline)' );
	check( false !== strpos( $module, "\$settings['marketing_pipeline_enabled'] = ! empty( \$_POST['vitads_enabled'] )" ) && false !== strpos( $module, "\$settings['crm_enabled'] = ( ! empty( \$_POST['vitads_enabled'] )" ),'a chave única grava vitads.enabled, marketing_pipeline_enabled e crm_enabled juntos' );
	$backup = $read( 'admin/views/vitagencia-backup.php' );
	check( 1 === substr_count( $backup, '<h3>BACKUP</h3>' ) && false === strpos( $backup, '<h3>BACKUP COMPLETO</h3>' ) && false === strpos( $backup, '<h3>BANCO DE DADOS</h3>' ), 'aba Backup tem um único título BACKUP' );
	check( false !== strpos( $backup, 'name="action" value="hub_run_backup_full"' ) && false !== strpos( $backup, 'name="action" value="hub_run_backup_db"' ) && strpos( $backup, 'hub_run_backup_full' ) < strpos( $backup, 'hub_run_backup_db' ) && false !== strpos( $backup, 'display: flex' ), 'os botões de backup completo e de banco de dados ficam lado a lado, com as mesmas ações' );
	$perfil = $read( 'admin/views/tools-perfil.php' );
	check( false === strpos( $perfil, 'hub_crm_enabled' ) && false === strpos( $perfil, "'crm_enabled'] =" ), 'Ferramentas > Perfil não tem mais o interruptor de CRM nem grava crm_enabled' );
	check( false !== strpos( $module, "! empty( \$license_status['is_valid'] )" ), 'a chave única só liga os módulos comerciais com licença válida' );
	$vitag = $read( 'admin/views/vitagencia.php' );
	check( false === strpos( $trafego, 'collector_notification_emails' ) && false !== strpos( $atend_view, 'name="collector[notification_emails]"' ), 'o e-mail de notificação é o campo da própria página do Atendente (a aba Marketing não tem cópia)' );
	check( false !== strpos( $toolsmod, '\'notification_emails\'  => sanitize_text_field( $raw[\'notification_emails\']' ) && false !== strpos( $collector, '$settings[\'notification_emails\']' ) && false === strpos( $module, 'collector_notification_emails' ), 'o e-mail é gravado em hub_lead_collector.notification_emails, que send_team_notification() lê' );
	check( false !== strpos( $toolsmod, 'error=not_persisted' ) && false !== strpos( $toolsmod, "wp_cache_delete( 'alloptions', 'options' )" ) && false !== strpos( $atend_view, 'Diagnóstico do salvamento' ) && false !== strpos( $atend_view, 'not_persisted' ), 'Atendente: confere o que ficou gravado no banco, limpa o cache de opções e mostra diagnóstico (banco x cache)' );
	check( false !== strpos( $trafego, 'Conversões e Marketing' ) && false !== strpos( $trafego, 'Salvar Configurações de Tráfego' ), 'bloco Conversões e Marketing e o botão de salvar seguem na aba' );
	check( false !== strpos( $toolsmod, '$forms        = isset( $hub_settings[\'forms\'] ) && is_array( $hub_settings[\'forms\'] )' ) && false !== strpos( $toolsmod, 'error=incomplete' ) && false === strpos( $toolsmod, '\'receive_email\' => sanitize_email' ), 'salvar o Atendente mescla em forms (só WhatsApp/mensagem mudam) e um formulário incompleto não apaga as configurações' );

	if ( $failed > 0 ) {
		echo "RESULTADO: {$failed} falha(s)\n";
		exit( 1 );
	}
	echo "RESULTADO: nome VitPress e captação pelo atendente ok\n";
}
