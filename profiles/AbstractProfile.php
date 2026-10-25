<?php
namespace Hub\Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe Abstrata Base para Perfis de Segmento no VitPress.
 */
abstract class AbstractProfile {

	/**
	 * Identificador único do perfil (ex: 'saude', 'engenharia', 'contabilidade').
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Nome exibido do perfil.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Descrição curta do público-alvo.
	 *
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * Ícone Dashicon para o menu.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'dashicons-businesswoman';
	}

	/**
	 * Nomenclaturas personalizadas para o segmento.
	 *
	 * @return array
	 */
	abstract public function get_labels();

	/**
	 * Estágios do Pipeline Comercial (Padrão para todo o VitPress).
	 * Representa o funil de aquisição de clientes.
	 *
	 * @return array Array de slugs e títulos dos estágios.
	 */
	public function get_pipeline_stages() {
		return \Hub\Modules\Pipeline\PipelineModule::get_canonical_stages();
	}

	/**
	 * Statuses disponíveis para Leads, Clientes e Agenda.
	 *
	 * @return array
	 */
	abstract public function get_statuses();

	/**
	 * Campos adicionais específicos do perfil para cada módulo.
	 *
	 * @param string $entity 'lead', 'client', 'event'.
	 * @return array
	 */
	public function get_custom_fields( $entity = 'client' ) {
		return array();
	}

	/**
	 * Formata um rótulo específico com base no dicionário do perfil.
	 *
	 * @param string $key Chave de rótulo.
	 * @param string $default Valor padrão caso não configurado.
	 * @return string
	 */
	public function get_label( $key, $default = '' ) {
		$labels = $this->get_labels();
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $default;
	}
}
