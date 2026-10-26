<?php
namespace Hub\Core\Services\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mantém as etapas dos leads atualizadas a partir do VitAds mesmo que ninguém abra o pipeline antigo.
 * Usa o WP-Cron e o intervalo "hub_five_minutes" que o VitPress já registra (BackupService).
 */
class PipelinePullWorker {

	const HOOK = 'hub_pipeline_pull_worker';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	public function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hub_five_minutes', self::HOOK );
		}
	}

	public function run() {
		PipelineContactSync::pull_cycle();
	}

	/** Chamado na desativação do plugin. */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
