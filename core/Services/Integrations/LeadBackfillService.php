<?php
namespace Hub\Core\Services\Integrations;

use Hub\Core\Database;
use Hub\Modules\Pipeline\PipelineModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importação dos leads antigos do VitPress para o pipeline do VitAds (backfill).
 *
 * Operação manual e controlada (WP-CLI: `wp vitpress backfill-leads`). Nunca roda sozinha, nunca por WP-Cron.
 *
 * Garantias:
 * - Dry-run por padrão: só grava com `execute`. O dry-run classifica os leads localmente e pergunta ao VitAds o que ele faria
 *   (a rota do VitAds com dry_run=true não grava nada).
 * - Não gera conversão: usa a rota POST /pipeline/contacts/backfill, que não registra marco comercial, evento, entrega nem interação.
 *   Este código também não dispara nenhum hook de conversão do VitPress (só grava o vínculo shared_contact_id).
 * - Não duplica: a identidade é a do VitAds (shared_contact_id, depois telefone único do cliente). Telefone repetido entre dois
 *   leads do VitPress vira "ambiguous" (o primeiro, de menor id, é importado; os demais ficam no relatório de falhas).
 * - Não inventa dados: lead sem telefone, ou fora de "Apresentação" sem data da etapa, não é enviado e é contado como ignorado.
 *   Em "Apresentação" sem data da etapa usa-se a data de criação do lead (a etapa inicial vale desde que o lead chegou).
 * - O VitAds vence: contato que já existe lá não tem etapa, histórico nem perfil já preenchido alterados.
 * - Retomável e seguro para bases grandes: lotes de até 50 leads por id crescente, cursor gravado a cada lote, trava de execução
 *   única, limites de lotes/tempo, pausa entre lotes e interrupção após falhas seguidas (o cursor é mantido).
 * - Privacidade: só vai o perfil mínimo (nome, e-mail, rótulo de origem, data de recebimento) ao VitAds do próprio cliente
 *   (token do site). O registro de falhas guarda apenas id do lead e um código — nunca nome, telefone ou e-mail.
 */
class LeadBackfillService {

	const OPTION_STATE    = 'hub_backfill_state';
	/** Cursor da importação histórica automática (HistoricalImport); separado do estado do comando manual. */
	const OPTION_AUTO_CURSOR = 'hub_historical_import_cursor';
	const OPTION_FAILURES = 'hub_backfill_failures';
	const OPTION_LOCK     = 'hub_backfill_lock';
	const BATCH_MAX       = 50;
	const LOCK_TTL        = 900;
	const MAX_FAILURES    = 500;
	const ABORT_AFTER     = 3;

	/**
	 * @param array<string,mixed> $args execute, batch, max_batches, max_seconds, sleep_ms, restart, local_only, include_linked, after_id.
	 * @return array<string,mixed> status (completed|paused|aborted), reason, counts, skipped, batches, scanned, cursor.
	 */
	public static function run( array $args = array() ) {
		$execute        = ! empty( $args['execute'] );
		$local_only     = ! empty( $args['local_only'] ) && ! $execute;
		$batch          = max( 1, min( self::BATCH_MAX, (int) ( $args['batch'] ?? self::BATCH_MAX ) ) );
		$max_batches    = max( 0, (int) ( $args['max_batches'] ?? 0 ) );
		$max_seconds    = max( 0, (int) ( $args['max_seconds'] ?? 0 ) );
		$sleep_ms       = max( 0, min( 5000, (int) ( $args['sleep_ms'] ?? 200 ) ) );
		$include_linked = ! array_key_exists( 'include_linked', $args ) || ! empty( $args['include_linked'] );
		$restart        = ! empty( $args['restart'] );
		$after_id       = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$http_timeout   = max( 3, min( 30, (int) ( $args['http_timeout'] ?? 20 ) ) );
		$state_option   = ( isset( $args['state_option'] ) && self::OPTION_AUTO_CURSOR === $args['state_option'] ) ? self::OPTION_AUTO_CURSOR : self::OPTION_STATE;

		$result = array(
			'mode'    => $execute ? 'execute' : ( $local_only ? 'local_only' : 'dry_run' ),
			'status'  => 'aborted',
			'reason'  => '',
			'batches' => 0,
			'scanned' => 0,
			'counts'  => array(
				'created'    => 0,
				'existing'   => 0,
				'ambiguous'  => 0,
				'invalid'    => 0,
				'error'      => 0,
				'would_send' => 0,
			),
			'skipped' => array(),
			'cursor'  => 0,
		);

		if ( ! $local_only && ! self::vitads_enabled() ) {
			$result['reason'] = 'vitads_disabled';
			return $result;
		}
		if ( $execute && ! self::acquire_lock() ) {
			$result['reason'] = 'locked';
			return $result;
		}

		try {
			$state = $execute ? self::load_state( $restart, $state_option ) : self::new_state();
			if ( $execute ) {
				// Uma execução real retoma do cursor e dos totais gravados.
				$result['counts']  = array_merge( $result['counts'], $state['counts'] );
				$result['skipped'] = $state['skipped'];
				$result['scanned'] = (int) $state['scanned'];
			}
			$cursor   = max( (int) $state['cursor'], $after_id );
			$started  = time();
			$failures = 0;
			$table    = Database::table( 'leads' );
			$seen_phones = array(); // telefone (md5) -> id do lead que o enviou nesta execução
			$batch_dups  = array();
			$db       = Database::db();

			while ( true ) {
				$db->last_error = '';
				$rows           = $db->get_results(
					$db->prepare(
						"SELECT id, name, email, phone, source, gclid, fbclid, utm_source, utm_medium, created_at, stage, stage_updated_at, stage_source, shared_contact_id FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
						$cursor,
						$batch
					)
				);
				if ( null === $rows || '' !== (string) $db->last_error ) {
					// Erro de banco (conexão, tabela) não é fim da lista: nunca pode concluir a importação.
					$result['reason'] = 'db_error';
					break;
				}
				if ( empty( $rows ) ) {
					$result['status'] = 'completed';
					if ( $execute ) {
						$state['completed_at'] = gmdate( 'c' );
					}
					break;
				}

				// Contagens do lote ficam locais: só entram no resultado quando o lote termina (repetir o lote não conta duas vezes).
				$items       = array();
				$batch_skips = array();
				$batch_seen  = array();
				$batch_dups  = array();
				foreach ( $rows as $row ) {
					$built = self::build_item( $row, $include_linked );
					if ( is_string( $built ) ) {
						$batch_skips[ $built ] = ( $batch_skips[ $built ] ?? 0 ) + 1;
						continue;
					}
					// Dois leads com o mesmo telefone: só o primeiro (menor id) é enviado; os demais vão para revisão.
					$key   = md5( $built['phone'] );
					$owner = $seen_phones[ $key ] ?? $batch_seen[ $key ] ?? null;
					if ( null !== $owner && $owner !== $built['local_id'] ) {
						$batch_skips['duplicate_phone'] = ( $batch_skips['duplicate_phone'] ?? 0 ) + 1;
						$batch_dups[]                   = (int) $built['local_id'];
						continue;
					}
					$batch_seen[ $key ] = $built['local_id'];
					$items[]            = $built;
				}

				$batch_counts = array();
				if ( array() !== $items ) {
					if ( $local_only ) {
						$batch_counts['would_send'] = count( $items );
					} else {
						$response = self::send( array(
							'dry_run'  => ! $execute,
							'contacts' => $items,
						), $http_timeout );
						$code = (int) $response['code'];
						if ( 200 !== $code || ! is_array( $response['body'] ) || empty( $response['body']['results'] ) ) {
							$abort = self::abort_reason( $code );
							if ( 'retry' !== $abort ) {
								$result['reason'] = $abort;
								break;
							}
							$failures++;
							if ( $failures >= self::ABORT_AFTER || ( $max_seconds > 0 && ( time() - $started ) >= $max_seconds ) ) {
								$result['reason'] = 'vitads_unreachable';
								break;
							}
							usleep( 500000 * $failures );
							continue; // repete o mesmo lote (o cursor não andou e nada foi contado)
						}
						$failures = 0;
						foreach ( $response['body']['results'] as $item_result ) {
							self::apply_result( is_array( $item_result ) ? $item_result : array(), $execute, $batch_counts );
						}
					}
				}

				// Lote concluído: soma as contagens e registra os duplicados para revisão.
				$result['scanned'] += count( $rows );
				foreach ( $batch_skips as $reason => $n ) {
					$result['skipped'][ $reason ] = ( $result['skipped'][ $reason ] ?? 0 ) + $n;
				}
				foreach ( $batch_counts as $status => $n ) {
					$result['counts'][ $status ] = ( $result['counts'][ $status ] ?? 0 ) + $n;
				}
				$seen_phones += $batch_seen;
				if ( $execute ) {
					foreach ( $batch_dups as $dup_id ) {
						self::add_failure( $dup_id, 'skipped', 'duplicate_phone' );
					}
				}
				$batch_dups = array();
				$cursor           = (int) end( $rows )->id;
				$result['cursor'] = $cursor;
				$result['batches']++;
				if ( $execute ) {
					$state = self::checkpoint( $state, $cursor, $result, $state_option );
				}
				if ( count( $rows ) < $batch ) {
					$result['status'] = 'completed';
					if ( $execute ) {
						$state['completed_at'] = gmdate( 'c' );
					}
					break;
				}
				if ( ( $max_batches > 0 && $result['batches'] >= $max_batches ) || ( $max_seconds > 0 && ( time() - $started ) >= $max_seconds ) ) {
					$result['status'] = 'paused';
					break;
				}
				if ( $sleep_ms > 0 ) {
					usleep( $sleep_ms * 1000 );
				}
			}
			$result['cursor'] = $cursor;
			if ( $execute ) {
				self::checkpoint( $state, $cursor, $result, $state_option );
			}
		} finally {
			if ( $execute ) {
				self::release_lock();
			}
		}
		return $result;
	}

	/**
	 * Estado da última execução real (cursor, totais) e quantidade de falhas registradas.
	 *
	 * @return array<string,mixed>
	 */
	public static function status() {
		$state = get_option( self::OPTION_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$fails = get_option( self::OPTION_FAILURES, array() );
		return array(
			'state'    => $state,
			'failures' => is_array( $fails ) ? count( $fails ) : 0,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function failures() {
		$fails = get_option( self::OPTION_FAILURES, array() );
		return is_array( $fails ) ? array_values( $fails ) : array();
	}

	/**
	 * Monta o item enviado ao VitAds, ou devolve o código do motivo de o lead ser ignorado.
	 *
	 * @param object $row
	 * @return array<string,mixed>|string
	 */
	public static function build_item( $row, $include_linked = true ) {
		$phone = VitAdsEventService::normalize_phone( $row->phone ?? '' );
		if ( strlen( $phone ) < 10 ) {
			return 'no_phone';
		}
		$shared = VitAdsEventService::normalize_shared_contact_id( (string) ( $row->shared_contact_id ?? '' ) );
		if ( '' !== $shared && ! $include_linked ) {
			return 'already_linked';
		}
		$stage = PipelineModule::normalize_stage( (string) ( $row->stage ?? '' ) );
		$at    = self::valid_mysql_date( $row->stage_updated_at ?? '' );
		if ( '' === $at ) {
			if ( 'presentation' !== $stage ) {
				return 'stage_without_date'; // não se inventa a data de uma etapa que o lead já deixou
			}
			$at = self::valid_mysql_date( $row->created_at ?? '' );
			if ( '' === $at ) {
				return 'no_date';
			}
		}
		$item = array(
			'local_id'         => (string) $row->id,
			'phone'            => $phone,
			'stage'            => $stage,
			'stage_updated_at' => self::mysql_to_utc( $at ),
		);
		if ( '' !== $shared ) {
			$item['shared_contact_id'] = $shared;
		}
		$source = trim( (string) ( $row->stage_source ?? '' ) );
		if ( '' !== $source ) {
			$item['stage_source'] = $source;
		}
		$contact = PipelineContactSync::contact_profile( $row );
		if ( array() !== $contact ) {
			$item['contact'] = $contact;
		}
		return $item;
	}

	/**
	 * @param array<string,mixed> $item_result
	 * @param array<string,int>   $counts
	 */
	private static function apply_result( array $item_result, $execute, array &$counts ) {
		$status  = isset( $item_result['status'] ) ? (string) $item_result['status'] : 'error';
		$status  = in_array( $status, array( 'created', 'existing', 'ambiguous', 'invalid', 'error' ), true ) ? $status : 'error';
		$lead_id = isset( $item_result['local_id'] ) ? (int) $item_result['local_id'] : 0;
		$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
		if ( $execute && $lead_id > 0 && in_array( $status, array( 'created', 'existing' ), true ) ) {
			$shared = VitAdsEventService::normalize_shared_contact_id( (string) ( $item_result['shared_contact_id'] ?? '' ) );
			if ( '' !== $shared ) {
				$table = Database::table( 'leads' );
				$db    = Database::db();
				$db->query( $db->prepare( "UPDATE {$table} SET shared_contact_id = %s WHERE id = %d AND (shared_contact_id IS NULL OR shared_contact_id = '')", $shared, $lead_id ) );
			}
		}
		if ( $execute && in_array( $status, array( 'ambiguous', 'invalid', 'error' ), true ) ) { // o dry-run não escreve nada, nem no registro de falhas
			self::add_failure( $lead_id, $status, isset( $item_result['reason'] ) ? (string) $item_result['reason'] : '' );
		}
	}

	private static function add_failure( $lead_id, $status, $reason ) {
		$fails   = self::failures();
		$fails[] = array(
			'lead_id' => (int) $lead_id,
			'status'  => $status,
			'reason'  => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( $reason ) ), 0, 40 ),
			'at'      => gmdate( 'c' ),
		);
		if ( count( $fails ) > self::MAX_FAILURES ) {
			$fails = array_slice( $fails, -self::MAX_FAILURES );
		}
		update_option( self::OPTION_FAILURES, $fails, false );
	}

	private static function abort_reason( $code ) {
		if ( 404 === $code ) {
			return 'vitads_unsupported'; // VitAds ainda sem a rota: atualize o VitAds antes
		}
		if ( 401 === $code || 403 === $code ) {
			return 'vitads_unauthorized';
		}
		if ( 409 === $code ) {
			return 'vitads_busy';
		}
		if ( 503 === $code ) {
			return 'vitads_schema_outdated';
		}
		if ( 422 === $code ) {
			return 'vitads_rejected_batch';
		}
		return 'retry'; // rede, 5xx ou corpo inesperado
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{code:int,body:array<string,mixed>|null}
	 */
	private static function send( array $payload, $timeout = 20 ) {
		// Gancho só para testes automatizados (a constante precisa estar definida): em produção nenhum plugin pode interceptar o envio.
		$custom = ( defined( 'HUB_BACKFILL_TEST_TRANSPORT' ) && HUB_BACKFILL_TEST_TRANSPORT ) ? apply_filters( 'hub_vitads_backfill_transport', null, $payload ) : null;
		if ( is_array( $custom ) && isset( $custom['code'] ) ) {
			return array(
				'code' => (int) $custom['code'],
				'body' => isset( $custom['body'] ) && is_array( $custom['body'] ) ? $custom['body'] : null,
			);
		}
		$config   = VitAdsEventService::config();
		$base     = rtrim( (string) preg_replace( '#/events/?$#', '', (string) $config['endpoint'] ), '/' );
		$response = wp_remote_post( $base . '/pipeline/contacts/backfill', array(
			'timeout'     => (int) $timeout,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $config['token'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'        => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'code' => 0, 'body' => null );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => is_array( $decoded ) ? $decoded : null,
		);
	}

	private static function vitads_enabled() {
		$config = VitAdsEventService::config();
		return ! empty( $config['enabled'] ) && '' !== $config['token'] && '' !== $config['endpoint'];
	}

	/** @return array<string,mixed> */
	private static function new_state() {
		return array(
			'run_id'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'bf', true ),
			'started_at' => gmdate( 'c' ),
			'cursor'     => 0,
			'scanned'    => 0,
			'counts'     => array(),
			'skipped'    => array(),
		);
	}

	/** @return array<string,mixed> */
	private static function load_state( $restart, $option = self::OPTION_STATE ) {
		$state = get_option( $option, array() );
		if ( $restart || ! is_array( $state ) || ! isset( $state['cursor'] ) || ! empty( $state['completed_at'] ) ) {
			// Execução nova: a anterior concluída (ou pedida do zero) não é retomada. O reenvio é seguro (idempotente).
			return self::new_state();
		}
		return array_merge( self::new_state(), $state );
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private static function checkpoint( array $state, $cursor, array $result, $option = self::OPTION_STATE ) {
		$state['cursor']     = (int) $cursor;
		$state['scanned']    = (int) $result['scanned'];
		$state['counts']     = $result['counts'];
		$state['skipped']    = $result['skipped'];
		$state['updated_at'] = gmdate( 'c' );
		update_option( $option, $state, false );
		update_option( self::OPTION_LOCK, time(), false );
		return $state;
	}

	private static function acquire_lock() {
		if ( add_option( self::OPTION_LOCK, time(), '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::OPTION_LOCK, 0 );
		if ( $held > 0 && ( time() - $held ) < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::OPTION_LOCK, time(), false ); // trava vencida (execução interrompida)
		return true;
	}

	private static function release_lock() {
		delete_option( self::OPTION_LOCK );
	}

	private static function valid_mysql_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return '';
		}
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value ) ? str_replace( 'T', ' ', $value ) : '';
	}

	private static function mysql_to_utc( $mysql ) {
		try {
			$dt = new \DateTimeImmutable( $mysql, wp_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}
}