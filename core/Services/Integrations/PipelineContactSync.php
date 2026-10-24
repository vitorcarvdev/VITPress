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

	public static function register_lead( $lead_id ) {
		try {
			$lead = self::lead( $lead_id );
			if ( ! $lead || ! self::enabled() ) {
				return;
			}
			$body = self::request( 'POST', '/pipeline/contact/resolve', self::resolve_payload( $lead ) );
			self::store_link( $lead, $body );
		} catch ( \Throwable $e ) {
			error_log( '[HUB PIPELINE] resolve_failed' );
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
			$ids = array();
			foreach ( $rows as $row ) {
				$ids[] = (string) $row->shared_contact_id;
			}
			$body = self::request( 'POST', '/pipeline/contacts/pull', array( 'shared_contact_ids' => $ids ) );
			if ( ! is_array( $body ) ) {
				return;
			}
			$by_shared = array();
			foreach ( $rows as $row ) {
				$by_shared[ (string) $row->shared_contact_id ] = (int) $row->id;
			}
			foreach ( $body as $remote ) {
				if ( ! is_array( $remote ) || empty( $remote['shared_contact_id'] ) ) {
					continue;
				}
				$lead_id = $by_shared[ (string) $remote['shared_contact_id'] ] ?? 0;
				if ( $lead_id > 0 ) {
					self::apply_remote( $lead_id, $remote );
				}
			}
		} catch ( \Throwable $e ) {
			error_log( '[HUB PIPELINE] pull_failed' );
		}
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
		return array(
			'local_id'          => (string) $lead->id,
			'phone'             => VitAdsEventService::normalize_phone( $lead->phone ?? '' ),
			'shared_contact_id' => ! empty( $lead->shared_contact_id ) ? (string) $lead->shared_contact_id : '',
			'stage'             => PipelineModule::normalize_stage( $lead->stage ),
			'stage_updated_at'  => self::mysql_to_utc( $lead->stage_updated_at ),
			'stage_source'      => ! empty( $lead->stage_source ) ? (string) $lead->stage_source : 'vitpress_form',
		);
	}

	/**
	 * @param object               $lead
	 * @param array<string, mixed>|null $body
	 */
	private static function store_link( $lead, $body ) {
		if ( ! is_array( $body ) || empty( $body['shared_contact_id'] ) || ! empty( $body['ambiguous'] ) ) {
			return;
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
	}

	/**
	 * @param array<string, mixed> $remote
	 */
	private static function apply_remote( $lead_id, array $remote ) {
		if ( empty( $remote['stage'] ) || empty( $remote['stage_updated_at'] ) ) {
			return;
		}
		$lead = self::lead( $lead_id );
		if ( ! $lead ) {
			return;
		}
		$remote_mysql = self::utc_to_mysql( (string) $remote['stage_updated_at'] );
		$local_stamp  = ! empty( $lead->stage_updated_at ) ? (string) $lead->stage_updated_at : '';
		$remote_stage = PipelineModule::normalize_stage( (string) $remote['stage'] );
		$local_stage  = PipelineModule::normalize_stage( $lead->stage );
		if ( $remote_stage === $local_stage || $remote_mysql < $local_stamp ) {
			return;
		}
		PipelineModule::change_stage( $lead_id, $remote_stage, 'vitads_sync', $lead->stage, $remote_mysql );
	}

	private static function lead( $lead_id ) {
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return null;
		}
		$table = Database::table( 'leads' );
		return Database::db()->get_row( Database::db()->prepare( "SELECT id, phone, stage, stage_updated_at, stage_source, shared_contact_id FROM {$table} WHERE id = %d", $lead_id ) );
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
