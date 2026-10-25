<?php
namespace Hub\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PluginProtectionService
 *
 * Controla quem pode gerenciar plugins no WordPress.
 * Quando ativado, apenas os usuários configurados em
 * hub_settings['plugin_protection']['admins'] (array de IDs)
 * poderão instalar, atualizar, ativar, desativar ou remover plugins.
 *
 * Arquitetura:
 *  - Segurança baseada exclusivamente em capabilities do WordPress.
 *  - Sem JavaScript ou CSS para esconder elementos.
 *  - Orientada a eventos (do_action) para baixo acoplamento.
 *  - Registra todas as ações no hub_history.
 */
class PluginProtectionService {

	/** @var array Configurações de proteção de plugins */
	private $config = [];

	/** @var string Capabilities de plugins a serem restringidas */
	private $plugin_caps = [
		'install_plugins',
		'update_plugins',
		'activate_plugins',
		'deactivate_plugins',
		'delete_plugins',
		'edit_plugins',
	];

	/**
	 * Construtor — carrega configurações.
	 */
	public function __construct() {
		$settings     = get_option( 'hub_settings', [] );
		$this->config = isset( $settings['plugin_protection'] ) && is_array( $settings['plugin_protection'] )
			? $settings['plugin_protection']
			: [ 'enabled' => false, 'admins' => [] ];
	}

	/**
	 * Registra os hooks do WordPress.
	 */
	public function init() {
		if ( ! $this->isProtectionEnabled() ) {
			return;
		}

		// Filtra capabilities — núcleo da proteção.
		add_filter( 'user_has_cap', [ $this, 'filterPluginCapabilities' ], 10, 3 );

		// Remove o menu Plugins para não-autorizados.
		add_action( 'admin_menu', [ $this, 'removePluginMenuIfNotAuthorized' ], 999 );

		// Bloqueia acesso direto às telas de plugins.
		add_action( 'current_screen', [ $this, 'blockDirectPluginScreens' ] );
	}

	// -------------------------------------------------------------------------
	// Consultas de estado
	// -------------------------------------------------------------------------

	/**
	 * Verifica se a proteção está ativada.
	 *
	 * @return bool
	 */
	public function isProtectionEnabled(): bool {
		return ! empty( $this->config['enabled'] );
	}

	/**
	 * Retorna o array de IDs de administradores autorizados.
	 *
	 * @return int[]
	 */
	public function getAuthorizedAdminIds(): array {
		return array_map( 'intval', (array) ( $this->config['admins'] ?? [] ) );
	}

	/**
	 * Verifica se o usuário atual está na lista de autorizados.
	 *
	 * @return bool
	 */
	public function currentUserIsAuthorized(): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		return in_array( $user_id, $this->getAuthorizedAdminIds(), true );
	}

	// -------------------------------------------------------------------------
	// Filtro de Capabilities (núcleo da segurança)
	// -------------------------------------------------------------------------

	/**
	 * Remove capabilities de gerenciamento de plugins para usuários não autorizados.
	 *
	 * @param array $allcaps   Todas as capabilities concedidas ao usuário.
	 * @param array $caps      Capabilities requeridas pela verificação atual.
	 * @param array $args      Argumentos extras da verificação (cap, user_id, object_id).
	 * @return array
	 */
	public function filterPluginCapabilities( array $allcaps, array $caps, array $args ): array {
		if ( $this->currentUserIsAuthorized() ) {
			return $allcaps;
		}

		foreach ( $this->plugin_caps as $cap ) {
			if ( isset( $allcaps[ $cap ] ) ) {
				$allcaps[ $cap ] = false;
			}
		}

		return $allcaps;
	}

	// -------------------------------------------------------------------------
	// Remoção de Menu
	// -------------------------------------------------------------------------

	/**
	 * Remove o menu e submenus de Plugins para usuários não autorizados.
	 */
	public function removePluginMenuIfNotAuthorized() {
		if ( $this->currentUserIsAuthorized() ) {
			return;
		}

		remove_menu_page( 'plugins.php' );
		remove_submenu_page( 'plugins.php', 'plugins.php' );
		remove_submenu_page( 'plugins.php', 'plugin-install.php' );
		remove_submenu_page( 'plugins.php', 'plugin-editor.php' );
	}

	// -------------------------------------------------------------------------
	// Bloqueio de Telas Diretas
	// -------------------------------------------------------------------------

	/**
	 * Bloqueia acesso direto às telas de administração de plugins.
	 * Usa get_current_screen() — mais confiável que REQUEST_URI.
	 *
	 * @param \WP_Screen $screen
	 */
	public function blockDirectPluginScreens( $screen ) {
		if ( $this->currentUserIsAuthorized() ) {
			return;
		}

		$blocked_screens = [ 'plugins', 'plugin-install', 'plugin-editor', 'update' ];

		if ( ! in_array( $screen->id, $blocked_screens, true ) ) {
			return;
		}

		$user_id    = get_current_user_id();
		$user       = get_userdata( $user_id );
		$user_login = $user ? $user->user_login : 'desconhecido';

		// Registra no histórico.
		$this->logHistory(
			sprintf(
				'Usuário "%s" (ID %d) tentou acessar o gerenciamento de plugins. Acesso bloqueado pela Proteção de Plugins.',
				$user_login,
				$user_id
			),
			'plugin_access_denied',
			$user_id
		);

		// Dispara evento genérico de segurança.
		do_action( 'hub/security_event', [
			'type'      => 'plugin_access_denied',
			'user_id'   => $user_id,
			'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
			'timestamp' => current_time( 'mysql' ),
		] );

		// Dispara evento específico de acesso negado.
		do_action( 'hub/plugin_access_denied', $user_id );

		wp_die(
			__(
				'O gerenciamento de plugins está protegido pelo VitPress. Apenas os administradores autorizados podem instalar, atualizar, ativar, desativar ou remover plugins. Caso necessite dessa permissão, entre em contato com o administrador responsável.',
				'hub'
			),
			__( 'Acesso Negado — VitPress', 'hub' ),
			[ 'response' => 403, 'back_link' => true ]
		);
	}

	// -------------------------------------------------------------------------
	// Histórico
	// -------------------------------------------------------------------------

	/**
	 * Grava uma entrada na tabela hub_history.
	 *
	 * @param string $description Descrição legível do evento.
	 * @param string $action      Identificador do tipo de ação (snake_case).
	 * @param int    $user_id     ID do usuário envolvido.
	 */
	public function logHistory( string $description, string $action = 'plugin_protection', int $user_id = 0 ) {
		global $wpdb;

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$wpdb->insert(
			$wpdb->prefix . 'hub_history',
			[
				'entity_type' => 'security',
				'entity_id'   => 0,
				'action'      => $action,
				'description' => $description,
				'user_id'     => $user_id,
				'created_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%s', '%s', '%d', '%s' ]
		);
	}

	// -------------------------------------------------------------------------
	// Salvar configurações (chamado pela view de settings)
	// -------------------------------------------------------------------------

	/**
	 * Valida e persiste as configurações de proteção de plugins.
	 * Registra histórico e dispara eventos adequados.
	 *
	 * @param bool  $enabled    Se a proteção deve ser ativada.
	 * @param int[] $admin_ids  Array de IDs de usuários autorizados.
	 * @return array{saved: bool, error: string}
	 */
	public function saveSettings( bool $enabled, array $admin_ids ): array {
		// Valida IDs — todos devem ser usuários existentes.
		foreach ( $admin_ids as $id ) {
			if ( ! get_userdata( (int) $id ) ) {
				return [
					'saved' => false,
					'error' => sprintf( 'O usuário com ID %d não foi encontrado. Configuração não salva.', $id ),
				];
			}
		}

		$settings                    = get_option( 'hub_settings', [] );
		$previous                    = $settings['plugin_protection'] ?? [ 'enabled' => false, 'admins' => [] ];
		$settings['plugin_protection'] = [
			'enabled' => $enabled,
			'admins'  => array_map( 'intval', $admin_ids ),
		];
		update_option( 'hub_settings', $settings );

		$current_user    = wp_get_current_user();
		$current_user_id = (int) $current_user->ID;

		// Detecta mudanças e registra histórico + eventos.
		$prev_enabled = ! empty( $previous['enabled'] );
		$prev_admins  = array_map( 'intval', (array) ( $previous['admins'] ?? [] ) );

		if ( $enabled !== $prev_enabled ) {
			if ( $enabled ) {
				$this->logHistory(
					sprintf( 'Administrador "%s" ativou a Proteção de Plugins.', $current_user->user_login ),
					'plugin_protection_enabled',
					$current_user_id
				);
				do_action( 'hub/plugin_protection_enabled' );
			} else {
				$this->logHistory(
					sprintf( 'Administrador "%s" desativou a Proteção de Plugins.', $current_user->user_login ),
					'plugin_protection_disabled',
					$current_user_id
				);
				do_action( 'hub/plugin_protection_disabled' );
			}
		}

		$sorted_new  = $admin_ids;
		$sorted_prev = $prev_admins;
		sort( $sorted_new );
		sort( $sorted_prev );

		if ( $sorted_new !== $sorted_prev ) {
			$logins = [];
			foreach ( $admin_ids as $id ) {
				$u = get_userdata( (int) $id );
				if ( $u ) {
					$logins[] = $u->user_login . ' (ID ' . $id . ')';
				}
			}
			$this->logHistory(
				sprintf(
					'Administrador "%s" alterou os administradores autorizados para: %s.',
					$current_user->user_login,
					implode( ', ', $logins ) ?: 'nenhum'
				),
				'plugin_admins_changed',
				$current_user_id
			);
		}

		return [ 'saved' => true, 'error' => '' ];
	}
}
