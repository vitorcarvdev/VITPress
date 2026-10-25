<?php
namespace Hub\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conector VCSIS Connect para atualizações automáticas externas e sincronização de versão.
 * Funciona de forma resiliente e desacoplada do ecossistema WordPress.org.
 */
class Connect {

	/**
	 * Instância única.
	 *
	 * @var Connect|null
	 */
	private static $instance = null;

	/**
	 * URL da API de atualização.
	 *
	 * @var string
	 */
	private $api_url = '';

	/**
	 * Versão atual do plugin.
	 *
	 * @var string
	 */
	private $version = '1.0.0';

	/**
	 * Construtor.
	 */
	private function __construct() {
		$config = HUB_PATH . 'config/config.php';
		if ( file_exists( $config ) ) {
			$cfg = require $config;
			$this->api_url = isset( $cfg['vcsis_connect_url'] ) ? $cfg['vcsis_connect_url'] : '';
			$this->version = isset( $cfg['version'] ) ? $cfg['version'] : '1.0.0';
		}
	}

	/**
	 * Singleton.
	 *
	 * @return Connect
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Inicializa os filtros nativos do WordPress para verificação de atualização.
	 */
	public function init() {
		if ( empty( $this->api_url ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Hook que intercepta a busca por novas versões de plugins.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function check_for_updates( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote_info = $this->fetch_remote_info();
		if ( $remote_info && version_compare( $this->version, $remote_info->new_version, '<' ) ) {
			$res              = new \stdClass();
			$res->slug        = 'hub';
			$res->plugin      = 'hub/hub.php';
			$res->new_version = $remote_info->new_version;
			$res->url         = isset( $remote_info->url ) ? $remote_info->url : '';
			$res->package     = isset( $remote_info->download_url ) ? $remote_info->download_url : '';
			$res->icons       = array( 'default' => HUB_URL . 'assets/css/icon.png' );
			$res->banners     = array();

			$transient->response['hub/hub.php'] = $res;
		}

		return $transient;
	}

	/**
	 * Fornece detalhes de modais de informação do plugin no WP Admin.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return object|false
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'hub' !== $args->slug ) {
			return $result;
		}

		$remote_info = $this->fetch_remote_info();
		if ( ! $remote_info ) {
			return $result;
		}

		$res                = new \stdClass();
		$res->name          = 'VitPress - Gestão Inteligente';
		$res->slug          = 'hub';
		$res->version       = $remote_info->new_version;
		$res->author        = '<a href="https://vcsis.com.br">VCSIS Agência</a>';
		$res->homepage      = 'https://vcsis.com.br';
		$res->requires      = '5.8';
		$res->tested        = '6.7';
		$res->requires_php  = '7.4';
		$res->download_link = isset( $remote_info->download_url ) ? $remote_info->download_url : '';
		$res->sections      = array(
			'description' => 'Sistema leve e nativo do WordPress para gestão de clientes, leads, agenda e pipeline.',
			'changelog'   => isset( $remote_info->changelog ) ? $remote_info->changelog : 'Melhorias de estabilidade e performance.',
		);

		return $res;
	}

	/**
	 * Faz a requisição externa segura à API VCSIS Connect.
	 *
	 * @return object|false
	 */
	private function fetch_remote_info() {
		$cache = get_transient( 'hub_vcsis_connect_check' );
		if ( false !== $cache ) {
			return json_decode( $cache );
		}

		$response = wp_remote_get(
			$this->api_url,
			array(
				'timeout'   => 5,
				'headers'   => array( 'Accept' => 'application/json' ),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return false;
		}

		set_transient( 'hub_vcsis_connect_check', $body, 12 * HOUR_IN_SECONDS );
		return json_decode( $body );
	}
}
