<?php
namespace Hub\Core\Cli;

use Hub\Core\Services\Integrations\LeadBackfillService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp vitpress backfill-leads — importa os leads antigos para o pipeline do VitAds.
 * Veja docs/backfill-leads-vitads.md. Sem --execute nada é gravado (dry-run).
 */
class LeadBackfillCommand {

	/**
	 * Importa (ou simula a importação de) leads antigos para o VitAds.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Grava de verdade. Sem esta opção é só simulação (dry-run).
	 *
	 * [--local-only]
	 * : Só classifica localmente, sem consultar o VitAds (apenas em dry-run).
	 *
	 * [--batch=<n>]
	 * : Leads por lote (1 a 50). Padrão 50.
	 *
	 * [--max-batches=<n>]
	 * : Para depois de n lotes (a execução real pode ser retomada). Padrão: sem limite.
	 *
	 * [--max-seconds=<n>]
	 * : Para depois de n segundos. Padrão: sem limite.
	 *
	 * [--sleep-ms=<n>]
	 * : Pausa entre lotes. Padrão 200.
	 *
	 * [--after-id=<id>]
	 * : Começa depois deste id de lead (útil para importar só um intervalo). Padrão 0.
	 *
	 * [--restart]
	 * : Recomeça do primeiro lead (ignora o cursor gravado). Seguro: a importação é idempotente.
	 *
	 * [--skip-linked]
	 * : Não envia leads que já têm vínculo com o VitAds (por padrão eles são enviados só para completar o perfil).
	 *
	 * [--status]
	 * : Mostra o estado da última execução real e sai.
	 *
	 * [--failures]
	 * : Lista as falhas registradas (id do lead e código) e sai.
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Opções.
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['status'] ) ) {
			$status                = LeadBackfillService::status();
			$status['automatic']   = \Hub\Core\Services\Integrations\HistoricalImport::status();
			\WP_CLI::log( wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( ! empty( $assoc_args['failures'] ) ) {
			$fails = LeadBackfillService::failures();
			if ( array() === $fails ) {
				\WP_CLI::log( 'Nenhuma falha registrada.' );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $fails, array( 'lead_id', 'status', 'reason', 'at' ) );
			return;
		}

		$execute = ! empty( $assoc_args['execute'] );
		$result  = LeadBackfillService::run( array(
			'execute'        => $execute,
			'local_only'     => ! empty( $assoc_args['local-only'] ),
			'batch'          => isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 50,
			'max_batches'    => isset( $assoc_args['max-batches'] ) ? (int) $assoc_args['max-batches'] : 0,
			'max_seconds'    => isset( $assoc_args['max-seconds'] ) ? (int) $assoc_args['max-seconds'] : 0,
			'sleep_ms'       => isset( $assoc_args['sleep-ms'] ) ? (int) $assoc_args['sleep-ms'] : 200,
			'restart'        => ! empty( $assoc_args['restart'] ),
			'after_id'       => isset( $assoc_args['after-id'] ) ? (int) $assoc_args['after-id'] : 0,
			'include_linked' => empty( $assoc_args['skip-linked'] ),
		) );

		\WP_CLI::log( 'Modo: ' . $result['mode'] . ( $execute ? '' : ' (nada foi gravado)' ) );
		\WP_CLI::log( sprintf( 'Lotes: %d | leads lidos: %d | último id: %d', $result['batches'], $result['scanned'], $result['cursor'] ) );
		\WP_CLI::log( 'Resultado no VitAds: ' . wp_json_encode( $result['counts'] ) );
		\WP_CLI::log( 'Ignorados no site (não enviados): ' . ( array() === $result['skipped'] ? 'nenhum' : wp_json_encode( $result['skipped'] ) ) );

		if ( 'aborted' === $result['status'] ) {
			\WP_CLI::error( 'Interrompido: ' . $result['reason'] . '. O cursor foi mantido; corrija e rode de novo.' );
		}
		if ( 'paused' === $result['status'] ) {
			\WP_CLI::warning( 'Pausado pelo limite informado. Rode o mesmo comando para continuar do último lote.' );
			return;
		}
		\WP_CLI::success( $execute ? 'Importação concluída.' : 'Simulação concluída. Para gravar, repita com --execute.' );
	}
}