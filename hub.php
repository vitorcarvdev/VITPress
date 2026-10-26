<?php
/**
 * Plugin Name: VitPress
 * Plugin URI: https://vcsis.com.br/hub
 * Description: VitPress — central de gestão com atendente virtual, Pipeline, conversões, indicadores, automações, suporte e ferramentas.
 * Version: 1.9.2
 * Author: VitAgência + VCSIS
 * Author URI: https://vitagencia.com.br
 * Text Domain: hub
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.2
 *
 * @package VitPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// 1. Definição das Constantes Principais
define( 'HUB_VERSION', '1.9.2' );
define( 'HUB_FILE', __FILE__ );
define( 'HUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'HUB_URL', plugin_dir_url( __FILE__ ) );

// 2. Registro do Autoloader PSR-4 Interno
require_once HUB_PATH . 'core/Autoloader.php';
\Hub\Core\Autoloader::register();

/**
 * Hook de Ativação do Plugin (Instalação e Migração do Banco de Dados)
 */
function activate_hub_plugin() {
	\Hub\Core\Installer::install();
}
register_activation_hook( __FILE__, 'activate_hub_plugin' );

/**
 * Hook de Desativação
 */
function deactivate_hub_plugin() {
	wp_clear_scheduled_hook( 'hub_google_ads_polling' );
	// Ações de limpeza temporária se necessário
}
register_deactivation_hook( __FILE__, 'deactivate_hub_plugin' );

// Remove agendamento antigo da Data Manager API
function hub_clear_legacy_google_ads_polling() {
	wp_clear_scheduled_hook( 'hub_google_ads_polling' );
}
add_action( 'init', 'hub_clear_legacy_google_ads_polling' );

/**
 * Bootstrapping da Aplicação VitPress Core
 */
function hub_init() {
	\Hub\Core\Installer::maybe_upgrade();
	// Carrega as traduções
	load_plugin_textdomain( 'hub', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Inicializa Gerenciador de Perfis
	\Hub\Core\ProfileManager::get_instance();

	// Inicializa Módulos Independentes
	\Hub\Core\ModuleManager::get_instance()->init_modules();

	// Inicializa Serviços Globais
	new \Hub\Core\Services\MailService();
	new \Hub\Core\Services\BackupService();
	new \Hub\Core\Jobs\S3BackupWorker();
	new \Hub\Core\Services\FormService();
	(new \Hub\Core\Services\PrivacyService())->init();
	new \Hub\Core\Services\DiagnosticService();

	// Inicializa Conector VCSIS Connect (Atualizações Automáticas)
	\Hub\Core\Connect::get_instance()->init();

    // Inicializa Central Privada de Atualizações
    require_once HUB_PATH . 'core/class-hub-vitagencia-updater.php';

	// Inicializa o Tracker de Rastreamento no Frontend
	\Hub\Core\Tracker::get_instance()->init();

	// Inicializa Formulário de Captura do Frontend (Shortcode)
	$frontend_form = new \Hub\Core\Services\FrontendFormService();
	$frontend_form->init();

	// Inicializa Captador de Leads Conversacional (Frontend & API)
	\Hub\Core\Services\LeadCollectorService::get_instance()->init();

	// Inicializa Busca de CNPJ
	$company_lookup = new \Hub\Core\Services\CompanyLookupService();
	$company_lookup->init();

	// Integrações de Conversões Comerciais (Meta Ads)
	\Hub\Core\Services\Conversions\ConversionEventService::get_instance()->init();
	\Hub\Core\Services\Conversions\ConversionRetryWorker::get_instance()->init();

	// Inicializa Conector com o Pipeline Central VCSis
	\Hub\Core\Services\Integrations\HubPipelineConnector::get_instance()->init();

	// Fila de Exportação Offline (CSV) para o Google Ads
	$offline_queue = new \Hub\Core\Services\GoogleAdsOfflineQueueService();
	$offline_queue->init();

	$offline_export = new \Hub\Core\Services\GoogleAdsOfflineExportService();
	$offline_export->init();

	// Inicializa recursos de segurança e otimização do WordPress
	(new \Hub\Core\Services\WordPress\DisableCommentsService())->init();
	(new \Hub\Core\Services\WordPress\HideLoginService())->init();

	// Se estiver no WP Admin, inicializa a Interface do WordPress Admin
	if ( is_admin() ) {
		\Hub\Core\Admin::get_instance()->init();
	}
}
add_action( 'plugins_loaded', 'hub_init' );
