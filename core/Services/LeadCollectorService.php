<?php
namespace Hub\Core\Services;

use Hub\Core\Database;
use Hub\Core\Services\Integrations\PipelineContactSync;
use Hub\Core\Services\Integrations\VitAdsEventService;
use Hub\Core\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço central do Captador de Leads Conversacional.
 */
class LeadCollectorService {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Enqueue scripts & styles no frontend somente se o captador estiver ativo
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Renderiza o container no footer
		add_action( 'wp_footer', array( $this, 'render_collector_container' ) );

		// Registra rotas REST
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Retorna as configurações completas do Captador com valores padrão.
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'              => false,
			'attendant_name'       => 'Larissa',
			'attendant_role'       => 'Especialista em Projetos',
			'attendant_avatar_id'  => 0,
			'primary_color'        => '#1b4d3e',
			'position'             => 'right',
			'badge_delay'          => 10,
			'auto_open_delay'      => 20,
			'auto_open_enabled'    => true,
			'excluded_pages'       => array(),
			'notification_emails'  => '',
			'final_message'        => 'Muito obrigado, {nome}! Recebemos suas informações e nossa equipe entrará em contato com você em breve.',
			'show_privacy_policy'  => true,
			'flow'                 => self::get_default_flow(),
		);

		$saved = get_option( 'hub_lead_collector', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = wp_parse_args( $saved, $defaults );

		if ( empty( $merged['flow'] ) || ! is_array( $merged['flow'] ) ) {
			$merged['flow'] = self::get_default_flow();
		} else {
			// Auto-migração: se o fluxo salvo contiver o passo antigo de botões (step_contact_choice), atualiza para WhatsApp direto
			$has_legacy_choice = false;
			foreach ( $merged['flow'] as $step ) {
				if ( isset( $step['id'] ) && 'step_contact_choice' === $step['id'] ) {
					$has_legacy_choice = true;
					break;
				}
			}
			if ( $has_legacy_choice ) {
				$merged['flow'] = self::get_default_flow();
				if ( ! empty( $saved ) ) {
					$saved['flow'] = $merged['flow'];
					update_option( 'hub_lead_collector', $saved );
				}
			}
		}

		return $merged;
	}

	/**
	 * Retorna o fluxo padrão inicial recomendado.
	 */
	public static function get_default_flow() {
		return array(
			array(
				'id'          => 'step_name',
				'message'     => 'Olá! 👋 Você está a um passo de conhecer nossos serviços. Qual é o seu nome?',
				'type'        => 'text',
				'mapping'     => 'name',
				'placeholder' => 'Digite seu nome...',
				'required'    => true,
				'options'     => array(),
				'next_step_id'=> 'step_phone',
			),
			array(
				'id'          => 'step_phone',
				'message'     => 'Prazer, {nome}! Qual é o seu WhatsApp com DDD para podermos conversar?',
				'type'        => 'phone',
				'mapping'     => 'phone',
				'placeholder' => '(00) 00000-0000',
				'required'    => true,
				'options'     => array(),
				'next_step_id'=> 'step_need',
			),
			array(
				'id'          => 'step_need',
				'message'     => 'Perfeito, {nome}! Agora me conta: qual serviço você está procurando e como podemos ajudar?',
				'type'        => 'textarea',
				'mapping'     => 'notes',
				'placeholder' => 'Conte brevemente sobre o seu projeto...',
				'required'    => true,
				'options'     => array(),
			),
		);
	}

	/**
	 * Verifica se a página atual está na lista de exclusão.
	 */
	private function is_page_excluded( $settings ) {
		if ( ! empty( $settings['excluded_pages'] ) && is_array( $settings['excluded_pages'] ) ) {
			$current_id = get_queried_object_id();
			if ( $current_id && in_array( $current_id, $settings['excluded_pages'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Carrega os arquivos CSS e JS apenas quando ativado.
	 */
	public function enqueue_frontend_assets() {
		if ( is_admin() ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		
		if ( $this->is_page_excluded( $settings ) ) {
			return;
		}

		// CSS do Captador
		wp_enqueue_style(
			'hub-collector-css',
			HUB_URL . 'assets/css/hub-collector.css',
			array(),
			HUB_VERSION
		);

		// JS do Captador
		$collector_js  = HUB_PATH . 'assets/js/hub-collector.js';
		$collector_ver = HUB_VERSION . '.' . ( file_exists( $collector_js ) ? filemtime( $collector_js ) : '1' );
		wp_enqueue_script(
			'hub-collector-js',
			HUB_URL . 'assets/js/hub-collector.js',
			array(),
			$collector_ver,
			true
		);

		// Obtém a URL do avatar através do attachment ID nativo
		$avatar_url = '';
		if ( ! empty( $settings['attendant_avatar_id'] ) ) {
			$img = wp_get_attachment_image_src( (int) $settings['attendant_avatar_id'], 'thumbnail' );
			if ( $img && ! empty( $img[0] ) ) {
				$avatar_url = $img[0];
			}
		}

		// URL da Política de Privacidade
		$privacy_url = get_privacy_policy_url();

		// Localização dos dados para o front-end
		$config = array(
			'api_url'             => rest_url( 'hub/v1/collector/submit' ),
			'redirected_url'      => rest_url( 'hub/v1/collector/redirected' ),
			'attendant_name'      => esc_html( $settings['attendant_name'] ),
			'attendant_role'      => esc_html( $settings['attendant_role'] ),
			'attendant_avatar_url'=> esc_url( $avatar_url ),
			'primary_color'       => sanitize_hex_color( $settings['primary_color'] ) ?: '#1b4d3e',
			'position'            => in_array( $settings['position'], array( 'left', 'right' ), true ) ? $settings['position'] : 'right',
			'badge_delay'         => absint( $settings['badge_delay'] ),
			'auto_open_delay'     => absint( $settings['auto_open_delay'] ),
			'auto_open_enabled'   => ! empty( $settings['auto_open_enabled'] ),
			'final_message'       => esc_html( $settings['final_message'] ),
			'show_privacy_policy' => ! empty( $settings['show_privacy_policy'] ),
			'privacy_policy_url'  => esc_url( $privacy_url ),
			'flow'                => $settings['flow'],
		);

		wp_localize_script( 'hub-collector-js', 'HubCollectorConfig', $config );
	}

	/**
	 * Renderiza o elemento raiz do Captador no rodapé.
	 */
	public function render_collector_container() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( $this->is_page_excluded( $settings ) ) {
			return;
		}
		echo '<div id="hub-lead-collector-root"></div>';
	}

	/**
	 * Registra a rota REST pública de submissão do Captador.
	 */
	public function register_rest_routes() {
		register_rest_route( 'hub/v1', '/collector/submit', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_rest_submission' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'hub/v1', '/collector/redirected', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_rest_redirected' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Processa a submissão das respostas do Captador via REST API.
	 */
	public function handle_rest_submission( \WP_REST_Request $request ) {
		// 1. Anti-Spam: Honeypot Check
		$honeypot = $request->get_param( 'hub_collector_hp' );
		if ( ! empty( $honeypot ) ) {
			return new \WP_REST_Response( array( 'success' => true, 'message' => 'Recebido com sucesso!' ), 200 );
		}

		// 2. Anti-Spam: Tempo Mínimo de Preenchimento (mínimo 2 segundos)
		$time_spent = absint( $request->get_param( 'time_spent' ) );
		if ( $time_spent < 2 ) {
			return new \WP_REST_Response( array( 'success' => true, 'message' => 'Recebido com sucesso!' ), 200 );
		}

		// 3. Idempotência via UUID de Submissão
		$submission_uuid = sanitize_key( $request->get_param( 'submission_uuid' ) );
		if ( empty( $submission_uuid ) ) {
			$submission_uuid = wp_generate_uuid4();
		}

		$lock_key = 'hub_col_uuid_' . $submission_uuid;
		$existing_lead_id = get_transient( $lock_key );
		if ( $existing_lead_id ) {
			// Mesma submissão repetida: nunca cria outro lead. Se o pipeline ainda não confirmou, tenta confirmar de novo.
			return $this->finish_submission( (int) $existing_lead_id, $submission_uuid, $request, (bool) get_transient( 'hub_col_new_' . $submission_uuid ), true );
		}

		// 4. Extração e mapeamento dos dados
		$answers = $request->get_param( 'answers' );
		if ( ! is_array( $answers ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Respostas inválidas.' ), 400 );
		}

		$name        = '';
		$email       = '';
		$phone       = '';
		$notes       = '';
		$extra_data  = array();

		foreach ( $answers as $step_id => $item ) {
			$val     = isset( $item['value'] ) ? sanitize_text_field( wp_unslash( $item['value'] ) ) : '';
			$mapping = isset( $item['mapping'] ) ? sanitize_key( $item['mapping'] ) : 'custom';
			$label   = isset( $item['label'] ) ? sanitize_text_field( wp_unslash( $item['label'] ) ) : $step_id;

			if ( 'name' === $mapping ) {
				$name = $val;
			} elseif ( 'phone' === $mapping ) {
				$phone = $val;
			} elseif ( 'email' === $mapping ) {
				$email = sanitize_email( $val );
			} elseif ( 'notes' === $mapping ) {
				$notes = $val;
			} else {
				$extra_data[] = array(
					'question' => $label,
					'answer'   => $val,
				);
			}
		}

		// Validação mínima de nome ou contato
		if ( empty( $name ) && empty( $phone ) && empty( $email ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Preencha ao menos seu nome ou forma de contato.' ), 400 );
		}

		// 5. Coleta dados de Atribuição do Tracker existente
		$tracking_data = Tracker::get_current_tracking_data();
		$created_at    = current_time( 'mysql' );
		$initial_stage = array_key_first( \Hub\Modules\Pipeline\PipelineModule::get_stage_definitions() );

		$custom_payload = array(
			'captador_submission_uuid' => $submission_uuid,
			'captador_answers'         => $extra_data,
			'captured_at'              => current_time( 'mysql' ),
		);

		$lead_data = array(
			'name'         => ! empty( $name ) ? $name : 'Interessado do Site',
			'email'        => $email,
			'phone'        => $phone,
			'source'       => 'captador_site',
			'stage'        => $initial_stage,
			'stage_updated_at' => $created_at,
			'stage_source' => 'vitpress_attendant',
			'notes'        => $notes,
			'custom_data'  => wp_json_encode( $custom_payload, JSON_UNESCAPED_UNICODE ),
			
			// Atribuição Tracker nativa
			'gclid'        => $tracking_data['gclid'] ?? '',
			'gbraid'       => $tracking_data['gbraid'] ?? '',
			'wbraid'       => $tracking_data['wbraid'] ?? '',
			'fbclid'       => $tracking_data['fbclid'] ?? '',
			'utm_source'   => $tracking_data['utm_source'] ?? '',
			'utm_medium'   => $tracking_data['utm_medium'] ?? '',
			'utm_campaign' => $tracking_data['utm_campaign'] ?? '',
			'utm_term'     => $tracking_data['utm_term'] ?? '',
			'utm_content'  => $tracking_data['utm_content'] ?? '',
			
			'first_touch_gclid'        => $tracking_data['first_touch_gclid'] ?? '',
			'first_touch_fbclid'       => $tracking_data['first_touch_fbclid'] ?? '',
			'first_touch_utm_source'   => $tracking_data['first_touch_utm_source'] ?? '',
			'first_touch_utm_medium'   => $tracking_data['first_touch_utm_medium'] ?? '',
			'first_touch_utm_campaign' => $tracking_data['first_touch_utm_campaign'] ?? '',
			'first_touch_utm_term'     => $tracking_data['first_touch_utm_term'] ?? '',
			'first_touch_utm_content'  => $tracking_data['first_touch_utm_content'] ?? '',
			
			'session_id'           => $tracking_data['session_id'] ?? '',
			'external_referrer'    => $tracking_data['external_referrer'] ?? '',
			'first_visit_datetime' => $tracking_data['first_visit_datetime'] ?? null,
			'landing_url'          => $tracking_data['landing_url'] ?? '',
			'landing_path'         => $tracking_data['landing_path'] ?? '',
			'landing_query'        => $tracking_data['landing_query'] ?? '',
			'conversion_page'      => sanitize_text_field( wp_unslash( $request->get_param( 'conversion_page' ) ?? '' ) ),
			'pageviews_count'      => isset( $tracking_data['pageviews_count'] ) ? absint( $tracking_data['pageviews_count'] ) : 1,
			'time_to_conversion'   => $time_spent,
			
			'ad_user_data_consent' => sanitize_text_field( wp_unslash( $request->get_param( 'ad_user_data_consent' ) ?? 'unknown' ) ),
			'consent_source'       => 'captador_site',
			
			'user_agent'        => $tracking_data['user_agent'] ?? '',
			'client_ip_address' => $tracking_data['client_ip_address'] ?? '',
			'_fbp'              => $tracking_data['_fbp'] ?? '',
			'_fbc'              => $tracking_data['_fbc'] ?? '',
			'device_type'       => $tracking_data['device_type'] ?? 'Desktop',
			'browser'           => $tracking_data['browser'] ?? 'Desconhecido',
			'os'                => $tracking_data['os'] ?? 'Desconhecido',
			
			'created_at'   => $created_at,
			'updated_at'   => $created_at,
		);

		global $wpdb;
		$wpdb->suppress_errors( true );

		// 6. Contato já existente (mesmo telefone normalizado)? Reaproveita sem criar outro lead nem mudar a etapa.
		$match   = $this->find_existing_lead( $phone );
		$is_new  = true;
		$lead_id = 0;
		if ( 'single' === $match['status'] ) {
			$is_new  = false;
			$lead_id = (int) $match['id'];
			$this->complement_existing_lead( $lead_id, $lead_data, $notes );
		} else {
			$table_leads = Database::table( 'leads' );
			$inserted    = Database::db()->insert( $table_leads, $lead_data );

			if ( ! $inserted ) {
				$db_error = Database::db()->last_error ?: 'Falha desconhecida no banco de dados.';
				return new \WP_REST_Response( array( 'success' => false, 'message' => 'Erro ao salvar no sistema: ' . $db_error ), 500 );
			}
			$lead_id = (int) Database::db()->insert_id;
			Database::log_history( 'lead', $lead_id, 'created', 'ambiguous' === $match['status']
				? 'Lead capturado pelo Atendente Virtual. Telefone igual ao de mais de um contato: não foi mesclado, revise manualmente.'
				: 'Lead capturado automaticamente pelo Atendente Virtual do site.' );
		}

		// 7. Grava lock de idempotência (1 hora). O lead fica salvo mesmo que o pipeline falhe; a repetição só reconfirma.
		set_transient( $lock_key, $lead_id, HOUR_IN_SECONDS );
		set_transient( 'hub_col_new_' . $submission_uuid, $is_new ? 1 : 0, HOUR_IN_SECONDS );
		set_transient( 'hub_col_ctx_' . $submission_uuid, array(
			'attribution' => AttendantIntake::attribution( $this->vitads_lead_context( $request, $lead_data, $tracking_data ) + array( 'fbclid' => (string) ( $tracking_data['fbclid'] ?? '' ) ) ),
			'consent'     => (string) $lead_data['ad_user_data_consent'],
			'context'     => $this->vitads_lead_context( $request, $lead_data, $tracking_data ),
			// Só o necessário para o aviso à equipe na repetição; IP, user agent e demais campos técnicos não ficam aqui.
			'lead_data'   => array_intersect_key( $lead_data, array_flip( array( 'name', 'email', 'phone', 'notes' ) ) ),
			'extra'       => $extra_data,
		), HOUR_IN_SECONDS );

		return $this->finish_submission( $lead_id, $submission_uuid, $request, $is_new, false );
	}

	/**
	 * Confirma o registro no pipeline e só então libera o WhatsApp. Falha => 503 com nova tentativa segura
	 * (a mesma submissão reaproveita o lead já salvo e a mesma chave de idempotência no VitAds).
	 */
	private function finish_submission( $lead_id, $submission_uuid, \WP_REST_Request $request, $is_new, $is_retry ) {
		$ctx = get_transient( 'hub_col_ctx_' . $submission_uuid );
		if ( ! is_array( $ctx ) ) {
			$ctx = array( 'attribution' => array(), 'consent' => 'unknown', 'context' => array(), 'lead_data' => array(), 'extra' => array() );
		}

		$confirmed = get_transient( 'hub_col_ok_' . $submission_uuid );
		if ( ! is_array( $confirmed ) ) {
			$interaction = AttendantIntake::interaction( $submission_uuid, $ctx['attribution'], $ctx['consent'], gmdate( 'Y-m-d\TH:i:s\Z' ) );
			$sync        = PipelineContactSync::confirm_attendant_lead( $lead_id, $interaction, $is_new );
			if ( 'unavailable' === $sync['status'] ) {
				return new \WP_REST_Response( array(
					'success'              => false,
					'retry'                => true,
					'attendance_completed' => true,
					'pipeline'             => 'unavailable',
					'conversation_started' => false,
					'message'              => 'Recebemos suas respostas, mas não conseguimos concluir o registro agora. Toque em “Tentar novamente” para continuar com segurança, sem duplicar seu atendimento.',
				), 503 );
			}
			$confirmed = array( 'status' => $sync['status'], 'existing' => ! empty( $sync['existing'] ), 'created' => ! empty( $sync['created'] ) );
			set_transient( 'hub_col_ok_' . $submission_uuid, $confirmed, HOUR_IN_SECONDS );

			// Efeitos que só valem uma vez, depois da confirmação. FORM_SUBMIT é idempotente por lead (event_id).
			if ( $is_new ) {
				// O contexto guardado é anterior à confirmação: acrescenta o vínculo que o VitAds acabou de devolver.
				$event_context = is_array( $ctx['context'] ) ? $ctx['context'] : array();
				if ( ! empty( $sync['shared_contact_id'] ) ) {
					$event_context['shared_contact_id'] = $sync['shared_contact_id'];
				}
				VitAdsEventService::send_form_submit( $lead_id, $event_context );
				do_action( 'hub/lead_created', $lead_id );
			}
			self::send_team_notification( $lead_id, $ctx['lead_data'], $ctx['extra'] );
		}

		$lead = Database::db()->get_row( Database::db()->prepare( 'SELECT name FROM ' . Database::table( 'leads' ) . ' WHERE id = %d', (int) $lead_id ) );
		$name = $lead ? (string) $lead->name : '';
		$forms = ( get_option( 'hub_settings', array() )['forms'] ?? array() );
		$ref   = AttendantIntake::reference( $lead_id );
		$wa    = AttendantIntake::whatsapp_url(
			isset( $forms['whatsapp'] ) ? $forms['whatsapp'] : '',
			AttendantIntake::whatsapp_message( isset( $forms['whatsapp_msg'] ) ? $forms['whatsapp_msg'] : '', 'Interessado do Site' === $name ? '' : $name, $ref )
		);

		return new \WP_REST_Response( array_merge(
			AttendantIntake::stages( $confirmed['status'], $wa ),
			array(
				'success'          => true,
				'lead_id'          => (int) $lead_id,
				'event_id'         => 'LEAD-' . (int) $lead_id,
				'contact_existing' => ! $is_new || ! empty( $confirmed['existing'] ),
				'reference'        => $ref,
				'whatsapp_url'     => $wa,
				'message'          => $is_retry && ! $is_new ? 'Contato já registrado anteriormente.' : 'Informações recebidas com sucesso!',
			)
		), 200 );
	}

	/**
	 * Candidato único com o mesmo telefone normalizado (comparação em PHP com a regra BR de 55 + DDD).
	 *
	 * @return array{status:string,id:int}
	 */
	private function find_existing_lead( $phone ) {
		$normalized = AttendantIntake::normalize_phone( $phone );
		if ( '' === $normalized ) {
			return array( 'status' => 'none', 'id' => 0 );
		}
		$db       = Database::db();
		$table    = Database::table( 'leads' );
		$stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+',''),'.','')";
		$rows     = $db->get_results( $db->prepare( "SELECT id, phone FROM {$table} WHERE phone <> '' AND RIGHT({$stripped}, 8) = %s ORDER BY id ASC LIMIT 50", substr( $normalized, -8 ) ) );
		$cands    = array();
		foreach ( (array) $rows as $row ) {
			$cands[] = array( 'id' => (int) $row->id, 'phone' => (string) $row->phone );
		}
		return AttendantIntake::pick_existing( $cands, $normalized );
	}

	/**
	 * Complementa só o que está vazio no lead existente; etapa, origem e atribuição anteriores são preservadas.
	 *
	 * @param array<string,mixed> $lead_data
	 */
	private function complement_existing_lead( $lead_id, array $lead_data, $notes ) {
		$db    = Database::db();
		$table = Database::table( 'leads' );
		$row   = $db->get_row( $db->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $lead_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$fill = array();
		if ( '' === trim( (string) ( $row['name'] ?? '' ) ) || 'Interessado do Site' === $row['name'] ) {
			if ( 'Interessado do Site' !== $lead_data['name'] ) {
				$fill['name'] = $lead_data['name'];
			}
		}
		foreach ( array( 'email', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', '_fbp', '_fbc' ) as $field ) {
			if ( '' === trim( (string) ( $row[ $field ] ?? '' ) ) && '' !== trim( (string) ( $lead_data[ $field ] ?? '' ) ) ) {
				$fill[ $field ] = $lead_data[ $field ];
			}
		}
		if ( $fill ) {
			$fill['updated_at'] = current_time( 'mysql' );
			$db->update( $table, $fill, array( 'id' => (int) $lead_id ) );
		}
		$detail = '' !== trim( (string) $notes ) ? ' Necessidade informada: ' . mb_substr( (string) $notes, 0, 300 ) : '';
		Database::log_history( 'lead', (int) $lead_id, 'interaction', 'Nova interação pelo Atendente Virtual do site (contato já existente; etapa preservada).' . $detail );
	}

	/**
	 * Registra no histórico que o visitante foi levado ao WhatsApp. Não prova que a mensagem foi enviada.
	 */
	public function handle_rest_redirected( \WP_REST_Request $request ) {
		$uuid    = sanitize_key( $request->get_param( 'submission_uuid' ) );
		$lead_id = '' !== $uuid ? (int) get_transient( 'hub_col_uuid_' . $uuid ) : 0;
		if ( $lead_id > 0 && ! get_transient( 'hub_col_redir_' . $uuid ) ) {
			set_transient( 'hub_col_redir_' . $uuid, 1, HOUR_IN_SECONDS );
			Database::log_history( 'lead', $lead_id, 'whatsapp_redirect', 'Visitante encaminhado ao WhatsApp pelo Atendente Virtual. Isto não confirma que a mensagem foi enviada.' );
		}
		return new \WP_REST_Response( array( 'success' => true, 'conversation_started' => false ), 200 );
	}

	/**
	 * Contexto do FORM_SUBMIT já aceito pelo VitAdsEventService.
	 *
	 * @param array<string,mixed> $lead_data
	 * @param array<string,mixed> $tracking_data
	 * @return array<string,mixed>
	 */
	private function vitads_lead_context( \WP_REST_Request $request, array $lead_data, array $tracking_data ) {
		$pick = static function ( $key ) use ( $tracking_data ) {
			$current = isset( $tracking_data[ $key ] ) ? trim( (string) $tracking_data[ $key ] ) : '';
			if ( '' !== $current ) {
				return $current;
			}
			$first = isset( $tracking_data[ 'first_touch_' . $key ] ) ? trim( (string) $tracking_data[ 'first_touch_' . $key ] ) : '';
			return $first;
		};

		$path = sanitize_text_field( wp_unslash( $request->get_param( 'conversion_page' ) ?? '' ) );

		return array(
			'name'             => $lead_data['name'] ?? '',
			'email'            => $lead_data['email'] ?? '',
			'phone'            => $lead_data['phone'] ?? '',
			'event_source_url' => '' !== $path ? esc_url_raw( home_url( $path ) ) : home_url( '/' ),
			'utm_source'       => $pick( 'utm_source' ),
			'utm_medium'       => $pick( 'utm_medium' ),
			'utm_campaign'     => $pick( 'utm_campaign' ),
			'utm_content'      => $pick( 'utm_content' ),
			'utm_term'         => $pick( 'utm_term' ),
			'gclid'            => $pick( 'gclid' ),
			'wbraid'           => $pick( 'wbraid' ),
			'gbraid'           => $pick( 'gbraid' ),
			'fbp'              => isset( $tracking_data['_fbp'] ) ? (string) $tracking_data['_fbp'] : '',
			'fbc'              => isset( $tracking_data['_fbc'] ) ? (string) $tracking_data['_fbc'] : '',
			'ad_user_data_consent' => $lead_data['ad_user_data_consent'] ?? 'unknown',
		);
	}

	/**
	 * Envia e-mail de notificação para os destinatários configurados.
	 * Não bloqueia ou desfaz a criação do lead em caso de falha no e-mail.
	 */
	public static function send_team_notification( $lead_id, $lead_data, $extra_data = array() ) {
		$settings   = self::get_settings();
		$emails_str = isset( $settings['notification_emails'] ) ? trim( $settings['notification_emails'] ) : '';

		// 1. Destinatários: se vazio, usa o e-mail do administrador do WordPress
		if ( ! empty( $emails_str ) ) {
			$recipients = array_filter( array_map( 'trim', explode( ',', $emails_str ) ), 'is_email' );
		} else {
			$admin_email = get_option( 'admin_email' );
			$recipients  = is_email( $admin_email ) ? array( $admin_email ) : array();
		}

		if ( empty( $recipients ) ) {
			Database::log_history( 'lead', $lead_id, 'notification_skipped', 'Notificação por e-mail ignorada: nenhum e-mail de destinatário válido configurado.' );
			return;
		}

		$name  = ! empty( $lead_data['name'] ) ? esc_html( $lead_data['name'] ) : 'Não informado';
		$phone = ! empty( $lead_data['phone'] ) ? esc_html( $lead_data['phone'] ) : 'Não informado';
		$email = ! empty( $lead_data['email'] ) ? esc_html( $lead_data['email'] ) : 'Não informado';
		$notes = ! empty( $lead_data['notes'] ) ? nl2br( esc_html( $lead_data['notes'] ) ) : 'Não informada';

		// Formata link de WhatsApp direto se houver telefone
		$clean_phone = preg_replace( '/\D/', '', $phone );
		$wa_link     = '';
		if ( strlen( $clean_phone ) >= 10 ) {
			if ( strlen( $clean_phone ) <= 11 && strpos( $clean_phone, '55' ) !== 0 ) {
				$clean_phone = '55' . $clean_phone;
			}
			$wa_link = 'https://wa.me/' . $clean_phone;
		}

		$pipeline_url = admin_url( 'admin.php?page=hub-pipeline' );
		$site_name    = get_bloginfo( 'name' ) ?: 'VitPress';

		$subject = "[Novo Interessado] {$lead_data['name']} - Atendente Virtual";

		// Corpo HTML Elegante
		$body = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #1d2327; }
.card { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.header { background: #1b4d3e; color: #ffffff; padding: 24px 28px; }
.header h1 { margin: 0 0 6px; font-size: 20px; font-weight: 700; color: #ffffff; }
.header p { margin: 0; font-size: 13px; color: #b7e0d2; }
.content { padding: 28px; }
.info-row { margin-bottom: 16px; border-bottom: 1px solid #f0f0f1; padding-bottom: 12px; }
.info-row:last-child { border-bottom: none; }
.info-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #646970; margin-bottom: 4px; font-weight: 600; }
.info-val { font-size: 15px; color: #1d2327; font-weight: 500; word-break: break-word; }
.badge { display: inline-block; background: #e7f3ee; color: #1b4d3e; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
.btn-cta { display: inline-block; background: #1b4d3e; color: #ffffff !important; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 700; font-size: 14px; margin-top: 20px; text-align: center; }
.btn-wa { display: inline-block; background: #25d366; color: #ffffff !important; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-weight: 600; font-size: 13px; margin-left: 10px; }
.footer { background: #f6f7f7; padding: 16px 28px; font-size: 11px; color: #8c8f94; text-align: center; border-top: 1px solid #dcdcde; }
</style>
</head>
<body>
<div class="card">
	<div class="header">
		<h1>Novo Contato Recebido! 🎉</h1>
		<p>Captado pelo Atendente Virtual em ' . esc_html( $site_name ) . '</p>
	</div>
	<div class="content">
		<div class="info-row">
			<div class="info-label">Nome do Interessado</div>
			<div class="info-val"><strong>' . $name . '</strong></div>
		</div>
		<div class="info-row">
			<div class="info-label">WhatsApp / Telefone</div>
			<div class="info-val">
				' . $phone . '
				' . ( $wa_link ? '<a href="' . esc_url( $wa_link ) . '" class="btn-wa" target="_blank">Chamar no WhatsApp ➔</a>' : '' ) . '
			</div>
		</div>
		' . ( ! empty( $lead_data['email'] ) ? '
		<div class="info-row">
			<div class="info-label">E-mail</div>
			<div class="info-val"><a href="mailto:' . esc_attr( $lead_data['email'] ) . '" style="color:#1b4d3e;text-decoration:none;">' . $email . '</a></div>
		</div>' : '' ) . '
		<div class="info-row">
			<div class="info-label">Necessidade / Serviço Procurado</div>
			<div class="info-val" style="background:#f8f9fa;padding:12px;border-radius:6px;border-left:3px solid #1b4d3e;">' . $notes . '</div>
		</div>';

		if ( ! empty( $extra_data ) ) {
			$body .= '<div class="info-row"><div class="info-label">Respostas Complementares</div><div class="info-val">';
			foreach ( $extra_data as $ans ) {
				$body .= '<p style="margin:4px 0;"><strong>' . esc_html( $ans['question'] ) . ':</strong> ' . esc_html( $ans['answer'] ) . '</p>';
			}
			$body .= '</div></div>';
		}

		$body .= '
		<div style="text-align: center; margin-top: 24px;">
			<a href="' . esc_url( $pipeline_url ) . '" class="btn-cta" target="_blank">Visualizar no Pipeline Central</a>
		</div>
	</div>
	<div class="footer">
		Mensagem automática gerada pelo VitPress • ' . date_i18n( 'd/m/Y \à\s H:i' ) . '
	</div>
</div>
</body>
</html>';

		// Remetente e Cabeçalhos
		$hub_settings = get_option( 'hub_settings', array() );
		$ses          = isset( $hub_settings['ses'] ) ? $hub_settings['ses'] : array();
		$from_email   = ! empty( $ses['from_email'] ) ? $ses['from_email'] : get_option( 'admin_email' );
		$from_name    = ! empty( $ses['from_name'] ) ? $ses['from_name'] : $site_name;

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		if ( ! empty( $lead_data['email'] ) && is_email( $lead_data['email'] ) ) {
			$headers[] = 'Reply-To: ' . $lead_data['email'];
		}

		// Captura de erro em tempo real via wp_mail_failed
		$mail_error = null;
		$error_catcher = function( $wp_error ) use ( &$mail_error ) {
			if ( is_wp_error( $wp_error ) ) {
				$mail_error = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $error_catcher );

		try {
			$sent = wp_mail( $recipients, $subject, $body, $headers );
			remove_action( 'wp_mail_failed', $error_catcher );

			if ( $sent ) {
				Database::log_history( 'lead', $lead_id, 'notification_sent', 'Notificação enviada com sucesso para: ' . implode( ', ', $recipients ) );
			} else {
				$err_msg = $mail_error ? $mail_error : 'Falha desconhecida no disparo do wp_mail.';
				Database::log_history( 'lead', $lead_id, 'notification_error', 'Falha no envio de notificação por e-mail: ' . $err_msg );
			}
		} catch ( \Exception $e ) {
			remove_action( 'wp_mail_failed', $error_catcher );
			Database::log_history( 'lead', $lead_id, 'notification_error', 'Exceção ao enviar notificação de e-mail: ' . $e->getMessage() );
		}
	}
}