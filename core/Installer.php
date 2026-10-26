<?php
namespace Hub\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável pela criação e atualização de tabelas no banco de dados do WordPress.
 */
class Installer {

	/**
	 * Executa as migrações e gravações iniciais de banco de dados.
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Tabela de Leads
		$table_leads = $wpdb->prefix . 'hub_leads';
		$sql_leads   = "CREATE TABLE {$table_leads} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_type varchar(50) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL,
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			status varchar(50) NOT NULL DEFAULT 'novo',
			stage varchar(50) NOT NULL DEFAULT 'lead_inicial',
			source varchar(100) NOT NULL DEFAULT 'direto',
			gclid varchar(255) NULL,
			gbraid varchar(255) NULL,
			wbraid varchar(255) NULL,
			fbclid varchar(255) NULL,
			utm_source varchar(100) NULL,
			utm_medium varchar(100) NULL,
			utm_campaign varchar(100) NULL,
			utm_content varchar(100) NULL,
			utm_term varchar(100) NULL,
			first_touch_gclid varchar(255) NULL,
			first_touch_fbclid varchar(255) NULL,
			first_touch_utm_source varchar(100) NULL,
			first_touch_utm_medium varchar(100) NULL,
			first_touch_utm_campaign varchar(100) NULL,
			first_touch_utm_term varchar(100) NULL,
			first_touch_utm_content varchar(100) NULL,
			ad_user_data_consent varchar(20) NOT NULL DEFAULT 'UNKNOWN',
			consent_source varchar(50) NULL,
			session_id varchar(50) NULL,
			external_referrer text NULL,
			first_visit_datetime datetime NULL,
			landing_url text NULL,
			landing_path text NULL,
			landing_query text NULL,
			conversion_page text NULL,
			pageviews_count int(11) DEFAULT 0 NOT NULL,
			time_to_conversion int(11) DEFAULT 0 NOT NULL,
			user_agent text NULL,
			client_ip_address varchar(50) NULL,
			_fbp varchar(255) NULL,
			_fbc varchar(255) NULL,
			device_type varchar(50) NULL,
			browser varchar(50) NULL,
			os varchar(50) NULL,
			notes text NULL,
			custom_data longtext NULL,
			revenue_value decimal(15,2) DEFAULT 0.00 NOT NULL,
			lost_reason varchar(191) NULL,
			lost_at datetime NULL,
			first_contact_at datetime NULL,
			attempts_count int(11) DEFAULT 0 NOT NULL,
			pipeline_central_id varchar(36) NULL,
			pipeline_sync_status varchar(30) NOT NULL DEFAULT 'pending',
			pipeline_sync_error text NULL,
			pipeline_synced_at datetime NULL,
			stage_updated_at datetime NULL,
			stage_source varchar(30) NULL,
			shared_contact_id varchar(36) NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY profile_type (profile_type),
			KEY status (status),
			KEY stage (stage),
			KEY email (email),
			KEY gclid (gclid),
			KEY pipeline_central (pipeline_central_id),
			KEY pipeline_status (pipeline_sync_status)
		) {$charset_collate};";
		dbDelta( $sql_leads );

		// 2. Tabela de Clientes
		$table_clients = $wpdb->prefix . 'hub_clients';
		$sql_clients   = "CREATE TABLE {$table_clients} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) UNSIGNED NULL,
			profile_type varchar(50) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL,
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			status varchar(50) NOT NULL DEFAULT 'ativo',
			document_type varchar(20) NULL,
			document varchar(50) NULL,
			zipcode varchar(20) NULL,
			address varchar(255) NULL,
			number varchar(50) NULL,
			complement varchar(255) NULL,
			neighborhood varchar(191) NULL,
			city varchar(191) NULL,
			state varchar(50) NULL,
			custom_data longtext NULL,
			notes text NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY profile_type (profile_type),
			KEY status (status),
			KEY email (email)
		) {$charset_collate};";
		dbDelta( $sql_clients );

		// 3. Tabela de Eventos e Agenda
		$table_events = $wpdb->prefix . 'hub_events';
		$sql_events   = "CREATE TABLE {$table_events} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_type varchar(50) NOT NULL DEFAULT '',
			client_id bigint(20) UNSIGNED NULL,
			lead_id bigint(20) UNSIGNED NULL,
			title varchar(255) NOT NULL,
			event_date date NOT NULL,
			event_time time NOT NULL,
			status varchar(50) NOT NULL DEFAULT 'agendado',
			notes text NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY event_date (event_date),
			KEY status (status),
			KEY client_id (client_id)
		) {$charset_collate};";
		dbDelta( $sql_events );

		// 4. Tabela de Configurações
		$table_settings = $wpdb->prefix . 'hub_settings';
		$sql_settings   = "CREATE TABLE {$table_settings} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			setting_key varchar(191) NOT NULL,
			setting_value longtext NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY setting_key (setting_key)
		) {$charset_collate};";
		dbDelta( $sql_settings );

		// 5. Tabela de Histórico (Audit / Timeline log)
		$table_history = $wpdb->prefix . 'hub_history';
		$sql_history   = "CREATE TABLE {$table_history} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type varchar(50) NOT NULL,
			entity_id bigint(20) UNSIGNED NOT NULL,
			action varchar(100) NOT NULL,
			description text NOT NULL,
			user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY entity_index (entity_type, entity_id)
		) {$charset_collate};";

		// 6. Tabela de Conversões
		$table_conversions_log = $wpdb->prefix . 'hub_conversions_log';
		$sql_conversions_log   = "CREATE TABLE {$table_conversions_log} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) UNSIGNED NOT NULL,
			lead_name varchar(191) NOT NULL,
			platform varchar(50) NOT NULL,
			conversion_type varchar(50) NOT NULL,
			status varchar(50) NOT NULL,
			request_payload longtext NULL,
			api_response longtext NULL,
			error_code varchar(191) NULL,
			gclid varchar(191) NULL,
			endpoint varchar(255) NULL,
			http_status varchar(10) NULL,
			conversion_action varchar(191) NULL,
			request_id varchar(191) NULL,
			transaction_id varchar(191) NULL,
			events_accepted int(11) DEFAULT 0 NOT NULL,
			events_rejected int(11) DEFAULT 0 NOT NULL,
			error_message text NULL,
			attempts int(11) DEFAULT 0 NOT NULL,
			next_retry_at datetime NULL,
			processed_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY platform_status (platform, status),
			KEY lead_id (lead_id),
			KEY transaction_id (transaction_id),
			KEY request_status (request_id, status),
			KEY retry_schedule (status, next_retry_at)
		) {$charset_collate};";

		// 6.5. Tabela de Vendas (hub_sales)
		$table_sales = $wpdb->prefix . 'hub_sales';
		$sql_sales   = "CREATE TABLE {$table_sales} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) UNSIGNED NOT NULL,
			service_name varchar(255) NOT NULL,
			value decimal(15,2) DEFAULT 0.00 NOT NULL,
			currency varchar(10) NOT NULL DEFAULT 'BRL',
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id)
		) {$charset_collate};";

		// 7. Tabela de Fila de Conversões Offline (Google Ads CSV)
		$table_offline = $wpdb->prefix . 'hub_google_ads_offline_conversions';
		$sql_offline   = "CREATE TABLE {$table_offline} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) UNSIGNED NOT NULL,
			gclid varchar(255) NULL,
			conversion_name varchar(255) NOT NULL,
			conversion_time datetime NOT NULL,
			conversion_value decimal(15,2) DEFAULT 0.00 NOT NULL,
			conversion_currency varchar(10) NOT NULL DEFAULT 'BRL',
			status varchar(50) NOT NULL DEFAULT 'pending',
			export_batch_id varchar(100) NULL,
			exported_at datetime NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY status (status),
			KEY export_batch_id (export_batch_id),
			UNIQUE KEY lead_conversion (lead_id, conversion_name)
		) {$charset_collate};";

		// 8. Tabela de Tentativas de Contato Comercial (CRM)
		$table_attempts = $wpdb->prefix . 'hub_lead_contact_attempts';
		$sql_attempts   = "CREATE TABLE {$table_attempts} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) UNSIGNED NOT NULL,
			channel varchar(50) NOT NULL,
			stage varchar(50) NOT NULL DEFAULT '',
			attempt_number int(11) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY channel (channel),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_leads );
		$stage_at = $wpdb->get_results( "SHOW COLUMNS FROM {$table_leads} LIKE 'stage_updated_at'" );
		if ( empty( $stage_at ) ) {
			$wpdb->query( "ALTER TABLE {$table_leads} ADD COLUMN stage_updated_at datetime NULL" );
		}
		$stage_source = $wpdb->get_results( "SHOW COLUMNS FROM {$table_leads} LIKE 'stage_source'" );
		if ( empty( $stage_source ) ) {
			$wpdb->query( "ALTER TABLE {$table_leads} ADD COLUMN stage_source varchar(30) NULL" );
		}
		$shared_contact = $wpdb->get_results( "SHOW COLUMNS FROM {$table_leads} LIKE 'shared_contact_id'" );
		if ( empty( $shared_contact ) ) {
			$wpdb->query( "ALTER TABLE {$table_leads} ADD COLUMN shared_contact_id varchar(36) NULL" );
		}
		dbDelta( $sql_clients );
		dbDelta( $sql_events );
		dbDelta( $sql_settings );
		dbDelta( $sql_history );
		dbDelta( $sql_conversions_log );
		dbDelta( $sql_sales );
		dbDelta( $sql_offline );
		dbDelta( $sql_attempts );

		// ==========================================
		// MIGRATION / UPGRADES (v1.7.0 e posteriores)
		// ==========================================

		// 1. Gera UUID único para a instalação, se ainda não existir
		$settings = get_option( 'hub_settings', array() );
		if ( empty( $settings['installation_uuid'] ) ) {
			$settings['installation_uuid'] = wp_generate_uuid4();
			update_option( 'hub_settings', $settings );
		}

		// 2. Localiza o usuário 'vcsis' e atribui a capability restrita
		$vcsis_user = get_user_by( 'login', 'vcsis' );
		if ( $vcsis_user ) {
			$vcsis_user->add_cap( 'hub_vitagencia_manage_internal' );
			update_user_meta( $vcsis_user->ID, 'hub_authorized_agency_user', 1 );
		}

		// 3. Define a data de início da coleta de métricas de atendimento (implantação 1.8.3)
		if ( ! get_option( 'hub_atendimento_start_date' ) ) {
			update_option( 'hub_atendimento_start_date', current_time( 'mysql' ) );
		}

		// Atualiza versão do banco de dados registrada
		$config = HUB_PATH . 'config/config.php';
		if ( file_exists( $config ) ) {
			$cfg = require $config;
			update_option( 'hub_db_version', $cfg['db_version'] ?? '1.0.0' );
		}
	}

	public static function maybe_upgrade() {
		$config = require HUB_PATH . 'config/config.php';
		if ( get_option( 'hub_db_version' ) !== ( $config['db_version'] ?? '' ) ) {
			self::install();
		}
	}
}
