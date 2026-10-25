<?php
namespace Hub\Core;

use Hub\Profiles\AbstractProfile;
use Hub\Profiles\HealthProfile;
use Hub\Profiles\EngineeringProfile;
use Hub\Profiles\AccountingProfile;
use Hub\Profiles\OtherBusinessProfile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gerenciador dos Perfis de Segmento no VitPress.
 */
class ProfileManager {

	/**
	 * Instância única.
	 *
	 * @var ProfileManager|null
	 */
	private static $instance = null;

	/**
	 * Perfis disponíveis.
	 *
	 * @var array<string, AbstractProfile>
	 */
	private $profiles = array();

	/**
	 * Perfil ativo no momento.
	 *
	 * @var AbstractProfile|null
	 */
	private $active_profile = null;

	/**
	 * Construtor privado.
	 */
	private function __construct() {
		$this->register_default_profiles();
		$this->load_active_profile();
	}

	/**
	 * Retorna a instância Singleton.
	 *
	 * @return ProfileManager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registra os perfis nativos do VitPress.
	 */
	private function register_default_profiles() {
		$this->register_profile( new HealthProfile() );
		$this->register_profile( new EngineeringProfile() );
		$this->register_profile( new AccountingProfile() );
		$this->register_profile( new OtherBusinessProfile() );
	}

	/**
	 * Registra um perfil na coleção.
	 *
	 * @param AbstractProfile $profile Instância do perfil.
	 */
	public function register_profile( AbstractProfile $profile ) {
		$this->profiles[ $profile->get_id() ] = $profile;
	}

	/**
	 * Carrega o perfil selecionado pelo usuário no banco de dados.
	 */
	private function load_active_profile() {
		$profile_id = Database::get_setting( 'active_profile', '' );

		if ( ! empty( $profile_id ) && isset( $this->profiles[ $profile_id ] ) ) {
			$this->active_profile = $this->profiles[ $profile_id ];
		}
	}

	/**
	 * Verifica se o usuário já realizou o onboarding e escolheu o perfil.
	 *
	 * @return bool
	 */
	public function is_profile_selected() {
		return null !== $this->active_profile;
	}

	/**
	 * Retorna o perfil ativo ou o primeiro perfil padrão caso ainda não selecionado.
	 *
	 * @return AbstractProfile
	 */
	public function get_active_profile() {
		if ( null === $this->active_profile ) {
			// Retorna o perfil de Saúde como padrão visual até a definição no Onboarding
			return isset( $this->profiles['saude'] ) ? $this->profiles['saude'] : reset( $this->profiles );
		}
		return $this->active_profile;
	}

	/**
	 * Grava a escolha do perfil.
	 *
	 * @param string $profile_id ID do perfil.
	 * @return bool
	 */
	public function set_active_profile( $profile_id ) {
		if ( isset( $this->profiles[ $profile_id ] ) ) {
			Database::set_setting( 'active_profile', sanitize_key( $profile_id ) );
			$this->active_profile = $this->profiles[ $profile_id ];
			return true;
		}
		return false;
	}

	/**
	 * Retorna todos os perfis registrados.
	 *
	 * @return array<string, AbstractProfile>
	 */
	public function get_profiles() {
		return $this->profiles;
	}
}
