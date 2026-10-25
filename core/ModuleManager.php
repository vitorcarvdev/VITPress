<?php
namespace Hub\Core;

use Hub\Modules\AbstractModule;
use Hub\Modules\Dashboard\DashboardModule;
use Hub\Modules\Persons\PersonsModule;
use Hub\Modules\Companies\CompaniesModule;
use Hub\Modules\Agenda\AgendaModule;
use Hub\Modules\Pipeline\PipelineModule;
use Hub\Modules\Indicators\IndicatorsModule;
use Hub\Modules\Support\SupportModule;
use Hub\Modules\Tools\ToolsModule;
use Hub\Modules\VitAgencia\VitAgenciaModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gerenciador e Carregador Dinâmico de Módulos Independentes do VitPress.
 */
class ModuleManager {

	/**
	 * Instância única.
	 *
	 * @var ModuleManager|null
	 */
	private static $instance = null;

	/**
	 * Módulos registrados.
	 *
	 * @var array<string, AbstractModule>
	 */
	private $modules = array();

	/**
	 * IDs dos módulos ativos.
	 *
	 * @var array<string>
	 */
	private $active_modules = array();

	/**
	 * Construtor privado.
	 */
	private function __construct() {
		$this->register_default_modules();
	}

	/**
	 * Singleton.
	 *
	 * @return ModuleManager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registra os módulos core da aplicação.
	 */
	private function register_default_modules() {
		$this->register_module( new DashboardModule() );
		$this->register_module( new PersonsModule() );
		$this->register_module( new CompaniesModule() );
		$this->register_module( new AgendaModule() );
		$this->register_module( new PipelineModule() );
		$this->register_module( new IndicatorsModule() );
		$this->register_module( new SupportModule() );
		$this->register_module( new ToolsModule() );
		$this->register_module( new VitAgenciaModule() );
	}

	/**
	 * Adiciona um módulo ao repositório.
	 *
	 * @param AbstractModule $module
	 */
	public function register_module( AbstractModule $module ) {
		$this->modules[ $module->get_id() ] = $module;
	}

	/**
	 * Inicializa os módulos configurados no config.php.
	 */
	public function init_modules() {
		$config         = HUB_PATH . 'config/config.php';
		$active_modules = array( 'dashboard', 'persons', 'companies', 'agenda', 'pipeline', 'indicators', 'support', 'tools', 'vitagencia' );
		$crm_enabled    = true;

		if ( file_exists( $config ) ) {
			$cfg = require $config;
			if ( ! empty( $cfg['active_modules'] ) && is_array( $cfg['active_modules'] ) ) {
				$active_modules = $cfg['active_modules'];
			}
			if ( isset( $cfg['crm_enabled'] ) ) {
				$crm_enabled = (bool) $cfg['crm_enabled'];
			}
		}

		// Checa sobreposição no banco de dados
		$settings = get_option( 'hub_settings', array() );
		if ( isset( $settings['crm_enabled'] ) ) {
			$crm_enabled = ! empty( $settings['crm_enabled'] );
		}

		if ( ! $crm_enabled ) {
			$crm_modules = array( 'dashboard', 'persons', 'companies', 'agenda', 'pipeline', 'indicators' );
			$active_modules = array_diff( $active_modules, $crm_modules );
		}

		$this->active_modules = array_values( $active_modules );

		foreach ( $this->active_modules as $mod_id ) {
			if ( isset( $this->modules[ $mod_id ] ) ) {
				$this->modules[ $mod_id ]->init();
			}
		}
	}

	/**
	 * Verifica se o módulo está ativo.
	 *
	 * @param string $id ID do módulo.
	 * @return bool
	 */
	public function is_active( $id ) {
		return in_array( $id, $this->active_modules, true );
	}

	/**
	 * Retorna todos os módulos registrados ordenados.
	 *
	 * @return array<string, AbstractModule>
	 */
	public function get_modules() {
		uasort(
			$this->modules,
			function( $a, $b ) {
				return $a->get_order() <=> $b->get_order();
			}
		);
		return $this->modules;
	}

	/**
	 * Retorna um módulo específico.
	 *
	 * @param string $id ID do módulo.
	 * @return AbstractModule|null
	 */
	public function get_module( $id ) {
		return isset( $this->modules[ $id ] ) ? $this->modules[ $id ] : null;
	}
}
