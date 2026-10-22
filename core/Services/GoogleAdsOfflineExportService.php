<?php
namespace Hub\Core\Services;

use Hub\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço responsável por gerenciar a exportação manual de compras offline
 * em CSV para o Google Ads, incluindo a tela e o processamento de lotes.
 */
class GoogleAdsOfflineExportService {

	public function init() {
		// Hook para processar o download do CSV
		add_action( 'admin_post_hub_download_offline_conversions', array( $this, 'process_csv_download' ) );
	}

	/**
	 * Processa o request de download (Pending ou Re-export de Batch).
	 */
	public function process_csv_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}

		check_admin_referer( 'hub_export_offline_conversions', 'hub_nonce' );

		wp_die( 'A exportação CSV de compras offline foi desativada. As conversões seguem pelo VitAds.' );

		$action = isset( $_POST['export_action'] ) ? sanitize_text_field( $_POST['export_action'] ) : 'pending';
		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( $_POST['batch_id'] ) : '';

		if ( 'pending' === $action ) {
			$this->export_pending_conversions();
		} elseif ( 'reexport' === $action && ! empty( $batch_id ) ) {
			$this->export_batch_conversions( $batch_id );
		} else {
			wp_die( 'Ação inválida.' );
		}
	}

	/**
	 * Exporta as conversões pendentes, gerando um novo Batch ID.
	 */
	private function export_pending_conversions() {
		$table = Database::table( 'google_ads_offline_conversions' );
		
		// Busca os pendentes que tenham GCLID válido
		$sql = "SELECT * FROM {$table} WHERE status = 'pending' AND gclid IS NOT NULL AND gclid != '' ORDER BY id ASC";
		$results = Database::db()->get_results( $sql, ARRAY_A );

		if ( empty( $results ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=google-ads-export&message=no_pending' ) );
			exit;
		}

		// Cria Batch ID único
		$batch_id = 'LOTE-' . date('Ymd-His') . '-' . wp_generate_password( 4, false, false );
		$exported_at = current_time( 'mysql' );

		// Atualiza o banco marcando como exportado
		$ids = wp_list_pluck( $results, 'id' );
		$ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		
		$update_sql = Database::db()->prepare(
			"UPDATE {$table} SET status = 'exported', export_batch_id = %s, exported_at = %s WHERE id IN ($ids_placeholder)",
			array_merge( array( $batch_id, $exported_at ), $ids )
		);
		Database::db()->query( $update_sql );

		// Gera o CSV na saída (para todos os registros recém atualizados)
		$this->output_csv( $results, $batch_id );
	}

	/**
	 * Re-exporta um lote existente.
	 */
	private function export_batch_conversions( $batch_id ) {
		$table = Database::table( 'google_ads_offline_conversions' );
		
		$sql = Database::db()->prepare( "SELECT * FROM {$table} WHERE export_batch_id = %s ORDER BY id ASC", $batch_id );
		$results = Database::db()->get_results( $sql, ARRAY_A );

		if ( empty( $results ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=google-ads-export&message=batch_not_found' ) );
			exit;
		}

		$this->output_csv( $results, $batch_id );
	}

	/**
	 * Formata os resultados num CSV compatível com o Google Ads e força o download.
	 */
	private function output_csv( $records, $batch_id ) {
		$filename = 'google-ads-offline-conversions-' . strtolower( $batch_id ) . '.csv';

		// Headers para download HTTP
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		
		// BOM UTF-8 essencial para Google Ads / Excel
		fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

		// Cabeçalho Exato Exigido
		fputcsv( $output, array(
			'Google Click ID',
			'Conversion Name',
			'Conversion Time',
			'Conversion Value',
			'Conversion Currency'
		) );

		foreach ( $records as $row ) {
			// Sanitização básica para CSV Injection (fórmulas)
			$gclid = ( strpos( $row['gclid'], '=' ) === 0 || strpos( $row['gclid'], '+' ) === 0 || strpos( $row['gclid'], '-' ) === 0 || strpos( $row['gclid'], '@' ) === 0 ) ? "'" . $row['gclid'] : $row['gclid'];
			$conversion_name = $row['conversion_name'];
			
			// Valor numérico formatado com ponto decimal
			$value = number_format( (float) $row['conversion_value'], 2, '.', '' );

			fputcsv( $output, array(
				$gclid,
				$conversion_name,
				$row['conversion_time'],
				$value,
				$row['conversion_currency']
			) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Retorna as estatísticas para a tela administrativa.
	 */
	public function get_stats() {
		$table = Database::table( 'google_ads_offline_conversions' );

		$pending = (int) Database::db()->get_var( "SELECT COUNT(id) FROM {$table} WHERE status = 'pending' AND gclid IS NOT NULL AND gclid != ''" );
		$skipped = (int) Database::db()->get_var( "SELECT COUNT(id) FROM {$table} WHERE status = 'skipped'" );
		
		$sql_batches = "SELECT export_batch_id, MAX(exported_at) as exported_at, COUNT(id) as total_items FROM {$table} WHERE export_batch_id IS NOT NULL AND export_batch_id != '' GROUP BY export_batch_id ORDER BY exported_at DESC LIMIT 10";
		$recent_batches = Database::db()->get_results( $sql_batches, ARRAY_A );

		return array(
			'pending' => $pending,
			'skipped' => $skipped,
			'recent_batches' => $recent_batches,
		);
	}
}
