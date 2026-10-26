<?php
namespace Hub\Core\Services\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importação histórica ÚNICA e automática dos leads antigos para o pipeline do VitAds.
 *
 * Roda sozinha pelo WP-Cron (intervalo "hub_five_minutes", o mesmo do PipelinePullWorker), sem WP-CLI, SSH ou tela:
 * - sites com a integração VitAds já configurada começam no primeiro ciclo depois da atualização para a 1.9.4;
 * - sites sem integração ficam em "pending" e começam sozinhos assim que a integração é salva (gancho em hub_settings) ou no
 *   próximo ciclo;
 * - cada ciclo faz poucos lotes de até 50 leads, com limite de tempo, e devolve o controle (nunca uma requisição longa);
 * - o cursor é gravado a cada lote; falha temporária (rede, 5xx, 401/403, 404, 409, 503) vira "failed_recoverable" com espera
 *   crescente e retoma do cursor, sem duplicar (a rota do VitAds é idempotente);
 * - uma trava (a mesma do comando manual) impede dois processos no mesmo lote;
 * - quando o último lote é processado grava a marca permanente `hub_historical_import_done`; a partir daí nada reinicia a
 *   importação (nem reativação do plugin, nem novo token). O comando manual `wp vitpress backfill-leads` continua disponível
 *   como ferramenta avançada e usa outro cursor.
 *
 * A sincronização contínua (PipelineContactSync::register_lead, push_after_human, PipelinePullWorker) não passa por aqui e
 * não depende deste estado. Qualquer exceção deste fluxo é capturada: nunca derruba o site nem a captação de leads.
 */
class HistoricalImport {

	const HOOK        = 'hub_historical_import_tick';
	const OPTION      = 'hub_historical_import';
	const OPTION_DONE = 'hub_historical_import_done';

	const PENDING   = 'pending';
	const RUNNING   = 'running';
	const COMPLETED = 'completed';
	const FAILED    = 'failed_recoverable';

	/** Espera base (segundos) por tipo de falha; dobra a cada falha seguida, até MAX_BACKOFF. */
	const BACKOFF     = array(
		'vitads_unreachable'     => 300,
		'vitads_busy'            => 300,
		'vitads_schema_outdated' => 1800,
		'vitads_unsupported'     => 3600,
		'vitads_unauthorized'    => 21600,
		'vitads_rejected_batch'  => 21600,
		'internal_error'         => 900,
	);
	const MAX_BACKOFF = 21600;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'update_option_hub_settings', array( $this, 'on_settings_saved' ), 10, 0 );
		add_action( 'add_option_hub_settings', array( $this, 'on_settings_saved' ), 10, 0 );
	}

	/** Agenda o ciclo recorrente enquanto a importação não terminou; depois de concluída remove o agendamento. */
	public function schedule() {
		if ( self::is_done() ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hub_five_minutes', self::HOOK );
		}
	}

	/** Integração salva/alterada: libera a espera de falha e antecipa o ciclo (a trava impede execuções concorrentes). */
	public function on_settings_saved() {
		try {
			if ( self::is_done() || ! self::integration_ready() ) {
				return;
			}
			$state = self::state();
			if ( (int) $state['next_retry_at'] > 0 ) {
				$state['next_retry_at'] = 0;
				self::save( $state );
			}
			if ( false === wp_next_scheduled( self::HOOK ) || (int) wp_next_scheduled( self::HOOK ) > time() + 60 ) {
				wp_schedule_single_event( time() + 30, self::HOOK );
			}
		} catch ( \Throwable $e ) {
			error_log( '[HUB IMPORT] settings_hook_failed' );
		}
	}

	/** Ativação/reativação: só garante o registro de estado. Nunca reinicia nem repete nada. */
	public static function on_activation() {
		if ( self::is_done() ) {
			return;
		}
		if ( false === get_option( self::OPTION, false ) ) {
			self::save( self::default_state() );
		}
	}

	/** Desativação: remove só o agendamento; o estado e a marca permanente ficam. */
	public static function on_deactivation() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Ciclo do WP-Cron. Nunca lança exceção. */
	public function tick() {
		try {
			self::run_once();
		} catch ( \Throwable $e ) {
			error_log( '[HUB IMPORT] tick_failed' );
			try {
				$state = self::state();
				self::fail( $state, 'internal_error' );
			} catch ( \Throwable $inner ) {
				error_log( '[HUB IMPORT] state_failed' );
			}
		}
	}

	/**
	 * Um ciclo: no máximo alguns lotes dentro do limite de tempo.
	 *
	 * @return array<string,mixed> ran (bool), status, reason.
	 */
	public static function run_once() {
		if ( self::is_done() ) {
			wp_clear_scheduled_hook( self::HOOK );
			return array( 'ran' => false, 'status' => self::COMPLETED, 'reason' => 'done' );
		}

		$state = self::state();
		if ( ! self::integration_ready() ) {
			$state['status']        = self::PENDING;
			$state['last_error']    = 'waiting_config';
			$state['next_retry_at'] = 0;
			self::save( $state );
			return array( 'ran' => false, 'status' => self::PENDING, 'reason' => 'waiting_config' );
		}

		// Outro cliente/endpoint (token trocado) no meio da importação: recomeça para o novo cliente. É seguro (idempotente).
		$fingerprint = self::fingerprint();
		if ( '' !== $state['integration'] && $state['integration'] !== $fingerprint ) {
			delete_option( LeadBackfillService::OPTION_AUTO_CURSOR );
			$state = array_merge( self::default_state(), array( 'started_at' => gmdate( 'c' ) ) );
		}
		$state['integration'] = $fingerprint;

		if ( (int) $state['next_retry_at'] > time() ) {
			return array( 'ran' => false, 'status' => $state['status'], 'reason' => 'backoff' );
		}

		$limits = apply_filters(
			'hub_historical_import_limits',
			array(
				'batch'        => 50,
				'max_batches'  => 10,
				'max_seconds'  => 20,
				'sleep_ms'     => 100,
				'http_timeout' => 10,
			)
		);
		$result = LeadBackfillService::run(
			array(
				'execute'        => true,
				'state_option'   => LeadBackfillService::OPTION_AUTO_CURSOR,
				'include_linked' => true,
				'batch'          => (int) ( $limits['batch'] ?? 50 ),
				'max_batches'    => (int) ( $limits['max_batches'] ?? 10 ),
				'max_seconds'    => (int) ( $limits['max_seconds'] ?? 20 ),
				'sleep_ms'       => (int) ( $limits['sleep_ms'] ?? 100 ),
				'http_timeout'   => (int) ( $limits['http_timeout'] ?? 10 ),
				'after_id'       => (int) ( $limits['after_id'] ?? 0 ),
			)
		);

		if ( 'locked' === ( $result['reason'] ?? '' ) ) {
			return array( 'ran' => false, 'status' => $state['status'], 'reason' => 'locked' ); // outro processo está no lote
		}

		$state['cursor']  = (int) $result['cursor'];
		$state['scanned'] = (int) $result['scanned'];
		$state['counts']  = $result['counts'];
		$state['skipped'] = $result['skipped'];
		if ( empty( $state['started_at'] ) ) {
			$state['started_at'] = gmdate( 'c' );
		}

		if ( 'completed' === $result['status'] ) {
			// Só aqui, com todos os lotes processados, a importação vira definitiva.
			$state['status']        = self::COMPLETED;
			$state['completed_at']  = gmdate( 'c' );
			$state['last_error']    = '';
			$state['attempts']      = 0;
			$state['next_retry_at'] = 0;
			self::save( $state );
			update_option( self::OPTION_DONE, $state['completed_at'], true );
			delete_option( LeadBackfillService::OPTION_AUTO_CURSOR );
			wp_clear_scheduled_hook( self::HOOK );
			return array( 'ran' => true, 'status' => self::COMPLETED, 'reason' => '' );
		}
		if ( 'paused' === $result['status'] ) {
			$state['status']        = self::RUNNING;
			$state['last_error']    = '';
			$state['attempts']      = 0;
			$state['next_retry_at'] = 0;
			self::save( $state );
			// Ainda há leads: não espera os 5 minutos do ciclo recorrente (a trava impede execuções concorrentes).
			wp_schedule_single_event( time() + 60, self::HOOK );
			return array( 'ran' => true, 'status' => self::RUNNING, 'reason' => '' );
		}

		$reason = (string) ( $result['reason'] ?? 'internal_error' );
		if ( 'vitads_disabled' === $reason ) {
			$state['status']     = self::PENDING;
			$state['last_error'] = 'waiting_config';
			self::save( $state );
			return array( 'ran' => false, 'status' => self::PENDING, 'reason' => 'waiting_config' );
		}
		return self::fail( $state, isset( self::BACKOFF[ $reason ] ) ? $reason : 'internal_error' );
	}

	/**
	 * Estado público, sem token, telefone, nome ou e-mail.
	 *
	 * @return array<string,mixed>
	 */
	public static function status() {
		$state                = self::state();
		$state['done']        = self::is_done();
		$state['integration'] = '' !== $state['integration'] ? 'set' : '';
		return $state;
	}

	public static function is_done() {
		return '' !== (string) get_option( self::OPTION_DONE, '' );
	}

	private static function fail( array $state, $reason ) {
		$attempts               = (int) $state['attempts'] + 1;
		$base                   = (int) ( self::BACKOFF[ $reason ] ?? self::BACKOFF['internal_error'] );
		$delay                  = min( self::MAX_BACKOFF, $base * ( 2 ** min( $attempts - 1, 3 ) ) );
		$state['status']        = self::FAILED;
		$state['last_error']    = $reason;
		$state['attempts']      = $attempts;
		$state['next_retry_at'] = time() + $delay;
		self::save( $state );
		return array( 'ran' => true, 'status' => self::FAILED, 'reason' => $reason );
	}

	private static function integration_ready() {
		$config = VitAdsEventService::config();
		return ! empty( $config['enabled'] ) && '' !== $config['token'] && '' !== $config['endpoint'];
	}

	/** Identifica endpoint+token sem guardar o token (hash curto, irreversível na prática). */
	private static function fingerprint() {
		$config = VitAdsEventService::config();
		return substr( hash( 'sha256', $config['endpoint'] . '|' . $config['token'] ), 0, 16 );
	}

	/** @return array<string,mixed> */
	private static function default_state() {
		return array(
			'status'        => self::PENDING,
			'integration'   => '',
			'cursor'        => 0,
			'scanned'       => 0,
			'counts'        => array(),
			'skipped'       => array(),
			'attempts'      => 0,
			'last_error'    => '',
			'next_retry_at' => 0,
			'started_at'    => '',
			'completed_at'  => '',
		);
	}

	/** @return array<string,mixed> */
	private static function state() {
		$state = get_option( self::OPTION, array() );
		return array_merge( self::default_state(), is_array( $state ) ? $state : array() );
	}

	private static function save( array $state ) {
		$state['updated_at'] = gmdate( 'c' );
		update_option( self::OPTION, $state, false );
	}
}