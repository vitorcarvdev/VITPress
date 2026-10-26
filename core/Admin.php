<?php
namespace Hub\Core;

use Hub\Core\Services\Licensing\LocalLicenseProvider;
use Hub\Core\Services\PluginProtectionService;
use Hub\Core\Services\Integrations\VitAdsPortal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gerenciador da Interface do WordPress Admin (Menus, Submenus, Estilos e Scripts).
 */
class Admin {

	/**
	 * Instância única.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Construtor.
	 */
	private function __construct() {}

	/**
	 * Singleton.
	 *
	 * @return Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registra os hooks de interface no admin_menu e admin_enqueue_scripts.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'ensure_vcsis_capabilities' ) );
		add_action( 'admin_menu', array( $this, 'register_menu_structure' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_pipeline' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_hub_save_profile', array( $this, 'handle_save_profile' ) );
		
		add_filter( 'admin_footer_text', array( $this, 'render_admin_footer' ) );
		add_action( 'admin_notices', array( '\Hub\Core\Services\Integrations\VitAdsMarketing', 'render_admin_notice' ) );

		// Inicializa o serviço de Proteção de Plugins
		$plugin_protection = new PluginProtectionService();
		$plugin_protection->init();
	}

	/**
	 * Garante que o usuário vcsis sempre tenha a permissão, caso o banco não tenha atualizado.
	 */
	public function ensure_vcsis_capabilities() {
		$current_user = wp_get_current_user();
		if ( $current_user && 'vcsis' === $current_user->user_login ) {
			if ( ! $current_user->has_cap( 'hub_vitagencia_manage_internal' ) ) {
				$current_user->add_cap( 'hub_vitagencia_manage_internal' );
			}
		}
	}

	/**
	 * Registra a estrutura do menu principal e submenus nativos do WordPress.
	 */
	public function register_menu_structure() {
		$profile_manager = ProfileManager::get_instance();
		$active_profile  = $profile_manager->get_active_profile();
		$module_manager  = ModuleManager::get_instance();

		$icon_slug = 'dashicons-image-filter';

		$main_slug = 'hub';
		if ( $profile_manager->is_profile_selected() ) {
			$main_slug = $module_manager->is_active('indicators') ? 'hub-indicadores' : 'hub-support';
		}

		// Menu Principal: VitPress
		add_menu_page(
			'VitPress',
			'VitPress',
			'manage_options',
			$main_slug,
			$profile_manager->is_profile_selected() ? '' : array( $this, 'render_onboarding_page' ),
			$icon_slug,
			25
		);

		if ( $profile_manager->is_profile_selected() ) {
			if ( $module_manager->is_active('indicators') ) {
				// Renomeia o primeiro submenu gerado automaticamente para 'Indicadores'
				add_submenu_page(
					$main_slug,
					'Indicadores',
					'Indicadores',
					'manage_options',
					$main_slug,
					array( $module_manager->get_module('indicators'), 'render' )
				);
			} else {
				// Se indicadores não existir, o primeiro submenu é o Suporte
				add_submenu_page(
					$main_slug,
					'Pedir Suporte',
					'Pedir Suporte',
					'manage_options',
					$main_slug,
					array( $module_manager->get_module('support'), 'render' )
				);
			}
		}

		// Se o perfil ainda não foi selecionado, redireciona o fluxo para o Onboarding
		if ( ! $profile_manager->is_profile_selected() ) {
			add_submenu_page(
				'hub',
				'Boas-vindas ao VitPress',
				'Escolha seu Perfil',
				'manage_options',
				'hub',
				array( $this, 'render_onboarding_page' )
			);
			return;
		}

		// Submenu 2: Pipeline (Visualização Somente Leitura)
		if ( $module_manager->is_active( 'pipeline' ) ) {
			// Com o site ligado ao VitAds o item some do menu (a página continua registrada e redireciona ao portal).
			add_submenu_page(
				VitAdsPortal::legacy_redirect_enabled() ? '' : $main_slug,
				'Pipeline',
				'Pipeline',
				'manage_options',
				'hub-pipeline',
				array( $module_manager->get_module( 'pipeline' ), 'render' )
			);
		}

		// Submenu 3: Atendente Virtual (Captador de Leads). Sem licença o item some do menu,
		// mas a página continua registrada para bloquear o acesso direto com a mensagem padrão.
		add_submenu_page(
			( new LocalLicenseProvider() )->isValid() ? $main_slug : '',
			'Atendente',
			'Atendente',
			'manage_options',
			'hub-atendente',
			array( $this, 'render_atendente_page' )
		);

		// Adiciona Submenus Dinâmicos baseados nos Módulos e Perfis
		// Onda 1: Ocultação visual da interface do cliente para módulos redundantes
		$hidden_modules = array( 'pipeline', 'persons', 'companies', 'agenda', 'tools', 'dashboard' );
		$modules = $module_manager->get_modules();

		foreach ( $modules as $id => $module ) {
			if ( 'dashboard' === $id || 'indicators' === $id || in_array( $id, $hidden_modules, true ) ) {
				continue; // Dashboard e indicadores já tratados na primeira posição. Módulos redundantes ocultados na Onda 1.
			}

			if ( ! $module_manager->is_active( $id ) ) {
				continue;
			}

			if ( 'support' === $id && ! $module_manager->is_active('indicators') ) {
				continue; // Suporte já foi renderizado como o item principal
			}

			$title = $this->get_custom_module_title( $id, $module->get_title(), $active_profile );

			add_submenu_page(
				$main_slug,
				$title,
				$title,
				'manage_options',
				$module->get_menu_slug(),
				array( $module, 'render' )
			);
		}
	}

	/**
	 * Retorna o título personalizado do submenu de acordo com o perfil ativo.
	 *
	 * @param string                                   $module_id
	 * @param string                                   $default_title
	 * @param \Hub\Profiles\AbstractProfile $profile
	 * @return string
	 */
	private function get_custom_module_title( $module_id, $default_title, $profile ) {
		if ( ! $profile ) {
			return $default_title;
		}

		switch ( $module_id ) {
			case 'persons':
				return 'Leads';
			case 'companies':
				return $profile->get_label( 'client_plural', 'Empresas' );
			case 'agenda':
				return $profile->get_label( 'event_plural', $default_title );
			case 'pipeline':
				return $profile->get_label( 'pipeline_title', $default_title );
			case 'support':
				return 'Pedir Suporte';
			default:
				return $default_title;
		}
	}

	/**
	 * Quem chegar ao kanban antigo (favorito, link velho) é levado ao portal do VitAds. Só navegação GET/HEAD no wp-admin.
	 */
	public function maybe_redirect_legacy_pipeline() {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'hub-pipeline' !== $page || ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! VitAdsPortal::legacy_redirect_enabled() ) {
			return;
		}
		wp_redirect( VitAdsPortal::login_url(), 302, 'VitPress' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
	/**
	 * Renderiza a página principal (redireciona para Dashboard ou Onboarding).
	 * (Desativado, pois agora redirecionamos para Conversões diretamente).
	 */
	public function render_main_page() {
		// Mantido apenas para compatibilidade legada se necessário
	}

	/**
	 * Renderiza a tela de Boas-vindas / Escolha de Perfil.
	 */
	public function render_onboarding_page() {
		$profiles = ProfileManager::get_instance()->get_profiles();
		require_once HUB_PATH . 'templates/onboarding.php';
	}

	/**
	 * Processa o envio do formulário de escolha de perfil.
	 */
	public function handle_save_profile() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acesso negado.' );
		}

		check_admin_referer( 'hub_save_profile_nonce', 'hub_nonce' );

		$selected_profile = isset( $_POST['profile_type'] ) ? sanitize_key( $_POST['profile_type'] ) : '';

		if ( ProfileManager::get_instance()->set_active_profile( $selected_profile ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hub-indicadores' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hub&error=invalid_profile' ) );
		exit;
	}

	/**
	 * Enfileira os estilos e scripts nativos nas páginas do VitPress.
	 *
	 * @param string $hook
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'hub' ) === false ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'hub-admin-css',
			HUB_URL . 'assets/css/hub-admin.css',
			array(),
			HUB_VERSION
		);

		$hub_admin_js = HUB_PATH . 'assets/js/hub-admin.js';
		$hub_admin_ver = HUB_VERSION . '.' . ( file_exists( $hub_admin_js ) ? filemtime( $hub_admin_js ) : '1' );

		wp_enqueue_script(
			'hub-admin-js',
			HUB_URL . 'assets/js/hub-admin.js',
			array( 'jquery' ),
			$hub_admin_ver,
			true
		);

		wp_localize_script(
			'hub-admin-js',
			'HubAdmin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'hub_admin_nonce' ),
			)
		);
	}

	/**
	 * Renderiza a assinatura no rodapé apenas nas páginas do VitPress.
	 */
	public function render_admin_footer( $text ) {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id, 'hub' ) !== false ) {
			return sprintf(
				'<strong>VitPress</strong> - Versão %s | Desenvolvido por VitAgência + VCSIS',
				HUB_VERSION
			);
		}
		return $text;
	}

	/**
	 * Renderiza a página dedicada de configurações do Atendente Virtual.
	 */
	public function render_atendente_page() {
		if ( ! ( new LocalLicenseProvider() )->isValid() ) {
			wp_die( 'Este recurso requer uma licença VitPress ativa.' );
		}

		require_once HUB_PATH . 'admin/views/atendente.php';
	}
}
