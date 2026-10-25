<?php
namespace Hub\Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Perfil de Segmento: Outros Negócios
 * Destinado a qualquer outro tipo de negócio não coberto especificamente.
 */
class OtherBusinessProfile extends AbstractProfile {

	public function get_id() {
		return 'outros';
	}

	public function get_name() {
		return 'Outros Negócios';
	}

	public function get_description() {
		return 'Segmento genérico preparado para adaptar-se a qualquer tipo de negócio B2B ou B2C.';
	}

	public function get_icon() {
		return 'dashicons-portfolio';
	}

	public function get_labels() {
		return array(
			'client_singular' => 'Cliente',
			'client_plural'   => 'Clientes',
			'lead_singular'   => 'Lead',
			'lead_plural'     => 'Leads',
			'event_singular'  => 'Agendamento',
			'event_plural'    => 'Agendamentos',
			'pipeline_title'  => 'Pipeline',
			'conversions'     => 'Conversões',
			'new_client'      => 'Novo Cliente',
			'new_lead'        => 'Novo Lead',
			'new_event'       => 'Novo Agendamento',
		);
	}

	public function get_statuses() {
		return array(
			'lead'   => array(
				'novo'          => 'Novo Contato',
				'em_atendimento' => 'Em Atendimento',
				'convertido'    => 'Convertido em Cliente',
				'arquivado'     => 'Arquivado',
			),
			'client' => array(
				'ativo'     => 'Ativo',
				'inativo'   => 'Inativo',
			),
			'event'  => array(
				'agendado'  => 'Agendado',
				'confirmado' => 'Confirmado',
				'realizado' => 'Realizado',
				'cancelado' => 'Cancelado',
			),
		);
	}

	public function get_custom_fields( $entity = 'client' ) {
		// Sem campos extras nativos para "Outros", usa apenas a estrutura base do VitPress
		return array();
	}
}
