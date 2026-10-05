<?php
namespace Hub\Modules\Persons;

use Hub\Modules\AbstractModule;
use Hub\Modules\Pipeline\PipelineModule;
use Hub\Core\Database;
use Hub\Core\ProfileManager;
use Hub\Admin\ListTables\PersonsListTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Módulo de Gestão de Leads.
 */
class PersonsModule extends AbstractModule {

	public function get_id() {
		return 'persons';
	}

	public function get_title() {
		return 'Leads';
	}

	public function get_menu_slug() {
		return 'hub-persons';
	}

	public function get_order() {
		return 2;
	}

	public function init() {
		add_action( 'admin_post_hub_save_person', array( $this, 'handle_save_person' ) );
	}

	public function render() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$profile = ProfileManager::get_instance()->get_active_profile();

		if ( 'delete' === $action && $id > 0 ) {
			check_admin_referer( 'delete_person_' . $id );
			Database::db()->delete( Database::table( 'leads' ), array( 'id' => $id ), array( '%d' ) );
			// Opcional: remover empresa vinculada?
			Database::db()->delete( Database::table( 'clients' ), array( 'lead_id' => $id ), array( '%d' ) );
			echo '<div class="notice notice-success is-dismissible"><p>Registro excluído com sucesso!</p></div>';
			$action = 'list';
		}

		if ( 'new' === $action || 'edit' === $action ) {
			$lead = null;
			$company = null;
			if ( $id > 0 ) {
				$lead = Database::db()->get_row( Database::db()->prepare( "SELECT * FROM " . Database::table( 'leads' ) . " WHERE id = %d", $id ) );
				$company = Database::db()->get_row( Database::db()->prepare( "SELECT * FROM " . Database::table( 'clients' ) . " WHERE lead_id = %d", $id ) );
			}
			require_once HUB_PATH . 'admin/views/persons-form.php';
			return;
		}

		$list_table = new PersonsListTable();
		$list_table->prepare_items();

		require_once HUB_PATH . 'admin/views/persons-list.php';
	}

	public function handle_save_person() {
		try {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}

		check_admin_referer( 'hub_save_person_nonce', 'hub_nonce' );

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
		$stage   = isset( $_POST['stage'] ) ? sanitize_key( $_POST['stage'] ) : 'presentation';
		$status  = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'novo';
		$source  = isset( $_POST['source'] ) ? sanitize_text_field( $_POST['source'] ) : 'direto';
		$notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

		$profile      = ProfileManager::get_instance()->get_active_profile();
		$profile_type = $profile ? $profile->get_id() : 'saude';

		// Captura dados de rastreamento (Google Ads / Meta Ads / UTMs)
		$tracking_data = \Hub\Core\Tracker::get_current_tracking_data();

		$gclid        = isset( $_POST['gclid'] ) && '' !== $_POST['gclid'] ? sanitize_text_field( $_POST['gclid'] ) : $tracking_data['gclid'];
		$gbraid       = isset( $_POST['gbraid'] ) && '' !== $_POST['gbraid'] ? sanitize_text_field( $_POST['gbraid'] ) : $tracking_data['gbraid'];
		$wbraid       = isset( $_POST['wbraid'] ) && '' !== $_POST['wbraid'] ? sanitize_text_field( $_POST['wbraid'] ) : $tracking_data['wbraid'];
		$fbclid       = isset( $_POST['fbclid'] ) && '' !== $_POST['fbclid'] ? sanitize_text_field( $_POST['fbclid'] ) : $tracking_data['fbclid'];
		$utm_source   = isset( $_POST['utm_source'] ) && '' !== $_POST['utm_source'] ? sanitize_text_field( $_POST['utm_source'] ) : $tracking_data['utm_source'];
		$utm_medium   = isset( $_POST['utm_medium'] ) && '' !== $_POST['utm_medium'] ? sanitize_text_field( $_POST['utm_medium'] ) : $tracking_data['utm_medium'];
		$utm_campaign = isset( $_POST['utm_campaign'] ) && '' !== $_POST['utm_campaign'] ? sanitize_text_field( $_POST['utm_campaign'] ) : $tracking_data['utm_campaign'];
		$utm_content  = isset( $_POST['utm_content'] ) && '' !== $_POST['utm_content'] ? sanitize_text_field( $_POST['utm_content'] ) : $tracking_data['utm_content'];
		$utm_term     = isset( $_POST['utm_term'] ) && '' !== $_POST['utm_term'] ? sanitize_text_field( $_POST['utm_term'] ) : $tracking_data['utm_term'];
		
		$revenue_value = isset( $_POST['revenue_value'] ) ? floatval( $_POST['revenue_value'] ) : 0.00;

		$first_touch_gclid        = isset( $_POST['first_touch_gclid'] ) && '' !== $_POST['first_touch_gclid'] ? sanitize_text_field( $_POST['first_touch_gclid'] ) : $tracking_data['first_touch_gclid'] ?? '';
		$first_touch_fbclid       = isset( $_POST['first_touch_fbclid'] ) && '' !== $_POST['first_touch_fbclid'] ? sanitize_text_field( $_POST['first_touch_fbclid'] ) : $tracking_data['first_touch_fbclid'] ?? '';
		$first_touch_utm_source   = isset( $_POST['first_touch_utm_source'] ) && '' !== $_POST['first_touch_utm_source'] ? sanitize_text_field( $_POST['first_touch_utm_source'] ) : $tracking_data['first_touch_utm_source'] ?? '';
		$first_touch_utm_medium   = isset( $_POST['first_touch_utm_medium'] ) && '' !== $_POST['first_touch_utm_medium'] ? sanitize_text_field( $_POST['first_touch_utm_medium'] ) : $tracking_data['first_touch_utm_medium'] ?? '';
		$first_touch_utm_campaign = isset( $_POST['first_touch_utm_campaign'] ) && '' !== $_POST['first_touch_utm_campaign'] ? sanitize_text_field( $_POST['first_touch_utm_campaign'] ) : $tracking_data['first_touch_utm_campaign'] ?? '';
		$first_touch_utm_content  = isset( $_POST['first_touch_utm_content'] ) && '' !== $_POST['first_touch_utm_content'] ? sanitize_text_field( $_POST['first_touch_utm_content'] ) : $tracking_data['first_touch_utm_content'] ?? '';
		$first_touch_utm_term     = isset( $_POST['first_touch_utm_term'] ) && '' !== $_POST['first_touch_utm_term'] ? sanitize_text_field( $_POST['first_touch_utm_term'] ) : $tracking_data['first_touch_utm_term'] ?? '';

		$session_id           = $tracking_data['session_id'] ?? '';
		$external_referrer    = $tracking_data['external_referrer'] ?? '';
		$first_visit_datetime = $tracking_data['first_visit_datetime'] ?? null;
		$landing_url          = $tracking_data['landing_url'] ?? '';
		$landing_path         = $tracking_data['landing_path'] ?? '';
		$landing_query        = $tracking_data['landing_query'] ?? '';
		$conversion_page      = 'painel_interno'; // Como está sendo salvo manualmente via admin
		$pageviews_count      = isset( $tracking_data['pageviews_count'] ) ? absint( $tracking_data['pageviews_count'] ) : 1;
		$time_to_conversion   = $tracking_data['time_to_conversion'] ?? 0;
		$user_agent           = $tracking_data['user_agent'] ?? '';
		$device_type          = $tracking_data['device_type'] ?? 'Desktop';
		$browser              = $tracking_data['browser'] ?? 'Desconhecido';
		$os                   = $tracking_data['os'] ?? 'Desconhecido';

		$data = array(
			'profile_type' => $profile_type,
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'stage'        => $stage,
			'status'       => $status,
			'source'       => $source,
			'gclid'        => $gclid,
			'gbraid'       => $gbraid,
			'wbraid'       => $wbraid,
			'fbclid'       => $fbclid,
			'utm_source'   => $utm_source,
			'utm_medium'   => $utm_medium,
			'utm_campaign' => $utm_campaign,
			'utm_content'  => $utm_content,
			'utm_term'     => $utm_term,
			
			'first_touch_gclid'        => $first_touch_gclid,
			'first_touch_fbclid'       => $first_touch_fbclid,
			'first_touch_utm_source'   => $first_touch_utm_source,
			'first_touch_utm_medium'   => $first_touch_utm_medium,
			'first_touch_utm_campaign' => $first_touch_utm_campaign,
			'first_touch_utm_content'  => $first_touch_utm_content,
			'first_touch_utm_term'     => $first_touch_utm_term,
			
			'session_id'           => $session_id,
			'external_referrer'    => $external_referrer,
			'first_visit_datetime' => $first_visit_datetime,
			'landing_url'          => $landing_url,
			'landing_path'         => $landing_path,
			'landing_query'        => $landing_query,
			'conversion_page'      => $conversion_page,
			'pageviews_count'      => $pageviews_count,
			'time_to_conversion'   => $time_to_conversion,
			'user_agent'           => $user_agent,
			'device_type'          => $device_type,
			'browser'              => $browser,
			'os'                   => $os,
			'notes'        => $notes,
			'revenue_value'=> $revenue_value,
		);

		$canonical_stage = PipelineModule::normalize_stage( $stage );
		$data['stage']   = $canonical_stage;
		$previous_stage  = '';
		if ( $id > 0 ) {
			$previous_stage = (string) Database::db()->get_var(
				Database::db()->prepare( 'SELECT stage FROM ' . Database::table( 'leads' ) . ' WHERE id = %d', $id )
			);
			unset( $data['stage'] );
		}

		if ( $id > 0 ) {
			// Não sobrescreve os dados de tracking iniciais ao editar
			unset($data['first_touch_gclid'], $data['first_touch_fbclid'], $data['first_touch_utm_source'], $data['first_touch_utm_medium'], $data['first_touch_utm_campaign'], $data['first_touch_utm_content'], $data['first_touch_utm_term']);
			unset($data['session_id'], $data['external_referrer'], $data['first_visit_datetime'], $data['landing_url'], $data['landing_path'], $data['landing_query'], $data['conversion_page']);
			
			Database::db()->update( Database::table( 'leads' ), $data, array( 'id' => $id ) );
			Database::log_history( 'lead', $id, 'edit', 'Lead atualizado.' );
		} else {
			Database::db()->insert( Database::table( 'leads' ), $data );
			$id = Database::db()->insert_id;
			Database::log_history( 'lead', $id, 'create', 'Novo Lead cadastrado.' );
		}

		if ( $id > 0 && PipelineModule::normalize_stage( $previous_stage ) !== $canonical_stage ) {
			PipelineModule::change_stage( $id, $canonical_stage, 'vitpress_admin', $previous_stage );
		}

		// Lógica de Upsert da Empresa (se os campos da empresa forem enviados e o lead for cliente)
		if ( 'won' === $canonical_stage && isset( $_POST['company_name'] ) ) {
			$company_data = array(
				'lead_id'       => $id,
				'profile_type'  => $profile_type,
				'name'          => sanitize_text_field( wp_unslash( $_POST['company_name'] ) ),
				'document_type' => isset( $_POST['document_type'] ) ? sanitize_key( $_POST['document_type'] ) : 'cnpj',
				'document'      => isset( $_POST['document'] ) ? sanitize_text_field( wp_unslash( $_POST['document'] ) ) : '',
				'zipcode'       => isset( $_POST['zipcode'] ) ? sanitize_text_field( wp_unslash( $_POST['zipcode'] ) ) : '',
				'address'       => isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '',
				'number'        => isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( $_POST['number'] ) ) : '',
				'complement'    => isset( $_POST['complement'] ) ? sanitize_text_field( wp_unslash( $_POST['complement'] ) ) : '',
				'neighborhood'  => isset( $_POST['neighborhood'] ) ? sanitize_text_field( wp_unslash( $_POST['neighborhood'] ) ) : '',
				'city'          => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
				'state'         => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '',
			);

			$existing_company = Database::db()->get_var( Database::db()->prepare( "SELECT id FROM " . Database::table( 'clients' ) . " WHERE lead_id = %d", $id ) );

			if ( $existing_company ) {
				Database::db()->update( Database::table( 'clients' ), $company_data, array( 'id' => $existing_company ) );
			} else {
				// Fallback info if empty
				$company_data['email'] = $email;
				$company_data['phone'] = $phone;
				$company_data['status'] = 'ativo';
				$company_data['created_at'] = current_time( 'mysql' );
				Database::db()->insert( Database::table( 'clients' ), $company_data );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hub-persons&message=saved' ) );
		exit;
		} catch (\Throwable $e) {
			file_put_contents( WP_CONTENT_DIR . '/hub-fatal.log', $e->getMessage() . "\n" . $e->getTraceAsString() );
			wp_die('Fatal error: ' . $e->getMessage());
		}
	}
}
