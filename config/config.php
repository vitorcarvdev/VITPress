<?php
/**
 * Hub Plugin Configuration File
 * 
 * Este arquivo armazena configurações técnicas e internas do plugin Hub.
 * Alterado manualmente pelo desenvolvedor / equipe da agência.
 *
 * @package Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

return array(
	// Informações de versão e infraestrutura
	'version'             => '1.9.0',
	'crm_enabled'         => false,
	'vcsis_connect_url'   => 'https://connect.vcsis.com.br/api/v1/updates/hub',
	'vcsis_connect_token' => '',

	// E-mails para Central de Atendimento (Integração com quadros do Trello)
	'support_emails'      => array(
		'marketing' => 'marketing@vcsis.com.br',
		'site'      => 'suporte.site@vcsis.com.br',
		'default'   => 'atendimento@vcsis.com.br',
	),

	// Módulos habilitados por padrão
	'active_modules'      => array(
		'dashboard',
		'persons',
		'companies',
		'agenda',
		'pipeline',
		'indicators',
		'support',
		'tools',
		'vitagencia',
	),

	// Configurações da Google Ads API para Conversões Offline
	'google_ads'          => array(
		'developer_token'      => '',
		'client_id'            => '',
		'client_secret'        => '',
		'refresh_token'        => '',
		'customer_id'          => '',
		'conversion_action_id' => '',
		'enabled'              => false,
	),

	// Configurações internas do sistema
	'db_version'          => '1.0.4',
	'debug'               => false,
);
