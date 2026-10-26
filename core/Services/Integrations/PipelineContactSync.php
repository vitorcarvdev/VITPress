<?php
namespace Hub\Core\Services\Integrations;

use Hub\Core\Database;
use Hub\Modules\Pipeline\PipelineModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ponte de estágio VitPress → VitAds. Não envia marco de marketing.
 */
class PipelineContactSync {

	/**
	 * Registra o lead no pipeline do VitAds e grava o vínculo no lead.
	 *
	 * @return string shared_contact_id (UUID em minúsculas) quando o VitAds vinculou o lead; '' em falha,
	 *                ambiguidade, integração indisponível ou resposta sem id válido. Quem chama pode ignorar o retorno.
	 */
	public static function register_lead( $lead_id ) {
		try {
			$lead = self::lead( $lead_id );
			if ( ! $lead || ! self::enabled() ) {
				return '';
			}
			$body = self::request( 'POST', '/pipeline/contact/resolve', self::resolve_payload( $lead ) );
			return self::store_link( $lead, $body );
		} catch ( \Throwable $e ) {
			error_log( '[HUB PIPELINE] resolve_failed' );
			return '';
		}
	}

	/**
	 * Registro SÍNCRONO do Atendente Virtual no pipeline do VitAds. O visitante só segue ao WhatsApp depois deste retorno.
	 *
	 * - confirmed: o VitAds aceitou (contato criado ou já existente; a etapa atual do contato é preservada).
	 * - ambiguous: o VitAds achou mais de um contato possível e não mesclou; o lead fica salvo para revisão manual.
	 * - not_required: integração com o VitAds desligada ou incompleta; o lead está salvo no VitPress.
	 * - unavailable: falha de rede/HTTP; nada foi confirmado e o chamador deve oferecer nova tentativa.
	 *
	 * @param array<string,mixed> $interaction Ver AttendantIntake::interaction().
	 * @param bool                $adopt_stage Lead recém-criado no VitPress: adota a etapa de um contato que já existia no VitAds.
	 * @return array{status:string,created:bool,existing:bool,stage:string,shared_contact_id:string}
	 */
	public static function confirm_attendant_lead( $lead_id, array $interaction, $adopt_stage = false ) {
		$result = array(
			'status'            => 'unavailable',
			'created'           => false,
			'existing'          => false,
			'stage'             => '',
			'shared_contact_id' => '',
		);
		try {
			if ( ! self::enabled() ) {
				$result['status'] = 'not_required';
				return $result;
			}
			$lead = self::lead( $lead_id );
			if ( ! $lead ) {
				return $result;
			}
			$payload                = self::resolve_payload( $lead );
			$payload['interaction'] = $interaction;
			$body                   = self::request( 'POST', '/pipeline/contact/resolve', $payload );
			if ( ! is_array( $body ) ) {
				return $result;
			}
			if ( ! empty( $body['ambiguous'] ) ) {
				$result['status'] = 'ambiguous';
				return $result;
			}
			if ( empty( $body['shared_contact_id'] ) ) {
				return $result;
			}
			$result['status']            = 'confirmed';
			$result['created']           = ! empty( $body['created'] );
			$result['existing']          = ! empty( $body['existing'] );
			$result['stage']             = isset( $body['stage'] ) ? (string) $body['stage'] : '';
			$result['shared_contact_id'] = (string) $body['shared_contact_id'];

			if ( (string) ( $lead->shared_contact_id ?? '' ) !== $result['shared_contact_id'] ) {
				Database::db()->update(
					Database::table( 'leads' ),
					array( 'shared_contact_id' => $result['shared_contact_id'] ),
					array( 'id' => (int) $lead->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
			// Lead novo no VitPress ligado a um contato que o VitAds já tinha: preserva a etapa comercial existente.
			if ( $adopt_stage && $result['existing'] && '' !== $result['stage'] && 'presentation' !== $result['stage'] && ! empty( $body['stage_updated_at'] ) ) {
				PipelineModule::change_stage( (int) $lead->id, PipelineModule::normalize_stage( $result['stage'] ), 'vitads_sync', $lead->stage, self::utc_to_mysql( (string) $body['stage_updated_at'] ) );
			}
		} catch ( \Throwable $e ) {
			error_log( '[HUB PIPELINE] attendant_confirm_failed' );
			$result['status'] = 'unavailable';
		}
		return $result;
	}

	public static function push_after_human( $lead_id ) {
		try {
			$lead = self::lead( $lead_id );
			if ( ! $lead || ! self::enabled() ) {
				return;
			}
			if ( empty( $lead->shared_contact_id ) ) {
				self::register_lead( $lead_id );
				$lead = self::lead( $lead_id );
			}
			if ( ! $lead || empty( $lead->shared_contact_id ) ) {
				return;
			}
			$body = self::request( 'POST', '/pipeline/contact/stage', array(
				'shared_contact_id' => (string) $lead->shared_contact_id,
				'stage'             => PipelineModule::normalize_stage( $lead->stage ),
				'stage_updated_at'  => self::mysql_to_utc( $lead->stage_updated_at ),
				'stage_source'      => (string) $lead->stage_source,
				'local_id'          => (string) $lead->id,
			) );
			if ( is_array( $body ) && empty( $body['accepted'] ) && ! empty( $body['shared_contact_id'] ) ) {
				self::apply_remote( (int) $lead->id, $body );
			}
		} catch ( \Throwable $e ) {
			error_log( '[HUB PIPELINE] stage_failed' );
		}
	}

	public static function pull_board() {
		try {
			if ( ! self::enabled() || get_transient( 'hub_pipeline_pull' ) ) {
				return;
			}
			set_transient( 'hub_pipeline_pull', 1, 30 );

			$table = Database::table( 'leads' );
			$rows  = Database::db()->get_results( "SELECT id, shared_contact_id FROM {$table} WHERE shared_contact_id IS NOT NULL AND shared_contact_id <> '' LIMIT 200" );
			if ( empty( $rows ) ) {
				return;
			}
			self::pull_rows( $rows );
		} catch ( \Throwable $e ) {
			error_log( '[HUB PIPELINE] pull_failed' );
		}
	}

	/**
	 * Atualiza as etapas locais a partir do VitAds SEM depender de ninguém abrir o pipeline antigo (WP-Cron, a cada 5 min).
	 *
	 * Percorre os leads vinculados em páginas (por id, com cursor guardado), no máximo $max_pages por execução, e retoma de
	 * onde parou. Trava contra execuções simultâneas, não grava nada no VitAds (a rota /contacts/pull é só leitura), aplica a
	 * etapa remota somente quando ela é mais nova e com origem vitads_sync (não devolve nada ao VitAds, então não há laço) e
	 * nunca dispara conversão. Falha de rede mantém o cursor para repetir a mesma página na próxima execução.
	 *
	 * @param int $max_pages Páginas por execução.
	 * @return array{status:string,pages:int,applied:int}
	 */
	public static function pull_cycle( $max_pages = 5 ) {
		$summary = array(
			'status'  => 'disabled',
			'pages'   => 0,
			'applied' => 0,
		);
		try {
			if ( ! self::enabled() ) {
				return $summary;
			}
			if ( get_transient( 'hub_pipeline_pull_lock' ) ) {
				$summary['status'] = 'locked';
				return $summary;
			}
			set_transient( 'hub_pipeline_pull_lock', 1, 240 );
			$summary['status'] = 'ok';

			$page_size = max( 1, min( 200, (int) apply_filters( 'hub_pipeline_pull_page_size', 200 ) ) );
			$table     = Database::table( 'leads' );
			$cursor    = (int) get_option( 'hub_pipeline_pull_cursor', 0 );
			$db        = Database::db();

			while ( $summary['pages'] < max( 1, (int) $max_pages ) ) {
				$rows = $db->get_results(
					$db->prepare(
						"SELECT id, shared_contact_id FROM {$table} WHERE id > %d AND shared_contact_id IS NOT NULL AND shared_contact_id <> '' ORDER BY id ASC LIMIT %d",
						$cursor,
						$page_size
					)
				);
				if ( empty( $rows ) ) {
					$cursor = 0; // chegou ao fim: recomeça na próxima execução
					break;
				}
				$result = self::pull_rows( $rows );
				if ( null === $result ) {
					$summary['status'] = 'failed'; // mantém o cursor: a mesma página é repetida depois
					break;
				}
				$summary['pages']++;
				$summary['applied'] += $result;
				$cursor              = (int) end( $rows )->id;
				if ( count( $rows ) < $page_size ) {
					$cursor = 0;
					break;
				}
			}
			update_option( 'hub_pipeline_pull_cursor', $cursor, false );
		} catch ( \Throwable $e ) {
			$summary['status'] = 'failed';
			error_log( '[HUB PIPELINE] pull_cycle_failed' );
		}
		delete_transient( 'hub_pipeline_pull_lock' );
		return $summary;
	}

	/**
	 * Uma página de leads: pede o estado ao VitAds e aplica as etapas mais novas.
	 *
	 * @param array<int,object> $rows Objetos com id e shared_contact_id.
	 * @return int|null Quantidade de etapas aplicadas; null se o VitAds não respondeu.
	 */
	private static function pull_rows( array $rows ) {
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (string) $row->shared_contact_id;
		}
		$body = self::request( 'POST', '/pipeline/contacts/pull', array( 'shared_contact_ids' => $ids ) );
		if ( ! is_array( $body ) ) {
			return null;
		}
		$by_shared = array();
		foreach ( $rows as $row ) {
			$by_shared[ (string) $row->shared_contact_id ] = (int) $row->id;
		}
		$applied = 0;
		foreach ( $body as $remote ) {
			if ( ! is_array( $remote ) || empty( $remote['shared_contact_id'] ) ) {
				continue;
			}
			$lead_id = $by_shared[ (string) $remote['shared_contact_id'] ] ?? 0;
			if ( $lead_id > 0 && self::apply_remote( $lead_id, $remote ) ) {
				$applied++;
			}
		}
		return $applied;
	}
	private static function enabled() {
		$config = VitAdsEventService::config();
		return ! empty( $config['enabled'] ) && $config['token'] !== '' && $config['endpoint'] !== '';
	}

	/**
	 * @param object $lead
	 * @return array<string, string>
	 */
	private static function resolve_payload( $lead ) {
		$payload = array(
			'local_id'          => (string) $lead->id,
			'phone'             => VitAdsEventService::normalize_phone( $lead->phone ?? '' ),
			'shared_contact_id' => ! empty( $lead->shared_contact_id ) ? (string) $lead->shared_contact_id : '',
			'stage'             => PipelineModule::normalize_stage( $lead->stage ),
			'stage_updated_at'  => self::mysql_to_utc( $lead->stage_updated_at ),
			'stage_source'      => ! empty( $lead->stage_source ) ? (string) $lead->stage_source : 'vitpress_form',
		);
		$contact = self::contact_profile( $lead );
		if ( array() !== $contact ) {
			$payload['contact'] = $contact;
		}
		return $payload;
	}

	/**
	 * Perfil mínimo do lead enviado ao VitAds (campo opcional "contact"): quem é, quando chegou e por onde chegou.
	 * Finalidade: o cliente e a equipe identificarem o lead e avaliarem o atendimento no portal. Vai só para o VitAds do
	 * próprio cliente (token do site). Não saem observações, valores, IP, user-agent nem identificadores de clique: a origem
	 * é só o rótulo calculado aqui. Campos vazios não são enviados.
	 *
	 * @param object $lead
	 * @return array<string, string>
	 */
	private static function contact_profile( $lead ) {
		$profile = array();
		$name    = trim( (string) ( $lead->name ?? '' ) );
		if ( '' !== $name ) {
			$profile['name'] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 190 ) : substr( $name, 0, 190 );
		}
		$email = trim( (string) ( $lead->email ?? '' ) );
		if ( '' !== $email && is_email( $email ) ) {
			$profile['email'] = strtolower( $email );
		}
		if ( ! empty( $lead->source ) || ! empty( $lead->utm_source ) || ! empty( $lead->gclid ) || ! empty( $lead->fbclid ) ) {
			$profile['source'] = PipelineModule::format_source_label( $lead );
		}
		if ( ! empty( $lead->created_at ) && '0000-00-00 00:00:00' !== $lead->created_at ) {
			$profile['received_at'] = self::mysql_to_utc( $lead->created_at );
		}
		return $profile;
	}

	/**
	 * @param object               $lead
	 * @param array<string, mixed>|null $body
	 * @return string UUID válido em minúsculas, ou '' quando não há vínculo (nada é gravado nesse caso).
	 */
	private static function store_link( $lead, $body ) {
		if ( ! is_array( $body ) || empty( $body['shared_contact_id'] ) || ! empty( $body['ambiguous'] ) ) {
			return '';
		}
		if ( (string) ( $lead->shared_contact_id ?? '' ) !== (string) $body['shared_contact_id'] ) {
			Database::db()->update(
				Database::table( 'leads' ),
				array( 'shared_contact_id' => (string) $body['shared_contact_id'] ),
				array( 'id' => (int) $lead->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
		self::apply_remote( (int) $lead->id, $body );
		return VitAdsEventService::normalize_shared_contact_id( $body['shared_contact_id'] );
	}

	/**
	 * @param array<string, mixed> $remote
	 * @return bool true se a etapa local mudou.
	 */
	private static function apply_remote( $lead_id, array $remote ) {
		if ( empty( $remote['stage'] ) || empty( $remote['stage_updated_at'] ) ) {
			return false;
		}
		$lead = self::lead( $lead_id );
		if ( ! $lead ) {
			return false;
		}
		$remote_mysql = self::utc_to_mysql( (string) $remote['stage_updated_at'] );
		$local_stamp  = ! empty( $lead->stage_updated_at ) ? (string) $lead->stage_updated_at : '';
		$remote_stage = PipelineModule::normalize_stage( (string) $remote['stage'] );
		$local_stage  = PipelineModule::normalize_stage( $lead->stage );
		if ( $remote_stage === $local_stage || $remote_mysql < $local_stamp ) {
			return false;
		}
		$changed = PipelineModule::change_stage( $lead_id, $remote_stage, 'vitads_sync', $lead->stage, $remote_mysql );
		return is_array( $changed ) && ! empty( $changed['changed'] );
	}

	private static function lead( $lead_id ) {
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return null;
		}
		$table = Database::table( 'leads' );
		return Database::db()->get_row( Database::db()->prepare( "SELECT id, name, email, phone, source, gclid, fbclid, utm_source, utm_medium, created_at, stage, stage_updated_at, stage_source, shared_contact_id FROM {$table} WHERE id = %d", $lead_id ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|null
	 */
	private static function request( $method, $path, array $payload ) {
		$config = VitAdsEventService::config();
		$url    = self::url( $config['endpoint'], $path );
		$args   = array(
			'timeout'     => 5,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $config['token'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $payload );
			$response     = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function url( $endpoint, $path ) {
		$base = preg_replace( '#/events/?$#', '', (string) $endpoint );
		return rtrim( (string) $base, '/' ) . $path;
	}

	private static function mysql_to_utc( $mysql ) {
		$mysql = trim( (string) $mysql );
		if ( '' === $mysql ) {
			return gmdate( 'Y-m-d\TH:i:s\Z' );
		}
		try {
			$dt = new \DateTimeImmutable( $mysql, wp_timezone() );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d\TH:i:s\Z' );
		}
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	private static function utc_to_mysql( $iso ) {
		try {
			$dt = new \DateTimeImmutable( (string) $iso );
		} catch ( \Exception $e ) {
			return current_time( 'mysql' );
		}
		return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}
}
