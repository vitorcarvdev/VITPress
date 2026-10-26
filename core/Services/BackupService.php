<?php
namespace Hub\Core\Services;

use Hub\Core\Services\Backup\BackupArchiveValidator;
use Hub\Core\Services\Backup\BackupDatabaseImporter;
use Hub\Core\Services\Backup\BackupFileMover;
use Hub\Core\Services\Backup\BackupSql;
use Hub\Core\Services\Backup\BackupWorkspace;
use Hub\Core\Services\Backup\RecoveryEndpoint;
use Hub\Core\Services\Backup\RecoveryPreflight;
use Hub\Core\Services\Backup\RestoreOperation;
use Hub\Core\Services\Backup\RestoreRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço de Backups e Migração.
 * Apenas sob demanda. Nenhum backup fica armazenado no servidor.
 */
class BackupService {

	private $temp_dir;
	private $uploads_dir;
	private $workspace_dir;
	private $legacy_temp_dir;
	private $legacy_db_dir;
	private $legacy_full_dir;
	private $cache_flush_pending = '';

	public function __construct() {
		$this->legacy_temp_dir = WP_CONTENT_DIR . '/hub-backups/temp/';
		$this->legacy_db_dir   = WP_CONTENT_DIR . '/hub-backups/database/';
		$this->legacy_full_dir = WP_CONTENT_DIR . '/hub-backups/full/';

		$this->init_hooks();
		$this->flush_cache_after_recovery();
	}

	/**
	 * restore-recovery.php não carrega o cache de objetos; ele deixa um marcador que só é apagado depois de uma
	 * limpeza conferida. Se o site usava cache persistente na troca, a limpeza precisa acontecer com esse cache
	 * ativo de novo: até lá o marcador fica e o painel avisa.
	 */
	private function flush_cache_after_recovery() {
		$marker = WP_CONTENT_DIR . RecoveryEndpoint::FLUSH_MARKER;
		if ( ! is_file( $marker ) ) {
			return;
		}
		$data       = json_decode( (string) @file_get_contents( $marker ), true );
		$persistent = is_array( $data ) && ! empty( $data['persistent'] );
		$external   = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		if ( $persistent && ! $external ) {
			$this->cache_flush_pending = 'disabled';
		} elseif ( self::verified_cache_flush() ) {
			@unlink( $marker );
			return;
		} else {
			$this->cache_flush_pending = 'failed';
		}
		static $noticed = false;
		if ( ! $noticed ) {
			$noticed = true;
			add_action( 'admin_notices', array( $this, 'cache_flush_notice' ) );
		}
	}

	public function cache_flush_notice() {
		if ( ! $this->cache_flush_pending || ! current_user_can( 'hub_vitagencia_manage_internal' ) ) {
			return;
		}
		$text = 'disabled' === $this->cache_flush_pending
			? 'Restauração: o cache de objetos persistente estava desativado durante a recuperação. Limpe-o pela hospedagem antes de reativá-lo; ao reativar, o VitPress limpa e confere o cache automaticamente.'
			: 'Restauração: o cache de objetos persistente não pôde ser limpo e conferido. Limpe-o pela hospedagem (ou wp cache flush); este aviso some quando a limpeza for confirmada.';
		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Limpa o cache de objetos e confere: uma chave gravada antes não pode mais ser lida do armazenamento.
	 */
	public static function verified_cache_flush() {
		if ( ! function_exists( 'wp_cache_flush' ) || ! function_exists( 'wp_cache_set' ) || ! function_exists( 'wp_cache_get' ) ) {
			return false;
		}
		$key   = 'flush-check-' . bin2hex( random_bytes( 6 ) );
		$check = 'v' . bin2hex( random_bytes( 6 ) );
		if ( ! wp_cache_set( $key, $check, 'vitpress_restore' ) || $check !== wp_cache_get( $key, 'vitpress_restore', true ) ) {
			return false;
		}
		if ( ! wp_cache_flush() ) {
			return false;
		}
		$found = null;
		$value = wp_cache_get( $key, 'vitpress_restore', true, $found );
		return false === $value && ! $found;
	}

	/**
	 * Limpeza pedida pelo executor da restauração com o WordPress carregado; se não puder ser conferida,
	 * fica o marcador para nova tentativa e aviso no painel.
	 */
	public function flush_restore_cache() {
		if ( ! self::verified_cache_flush() ) {
			RecoveryEndpoint::write_flush_marker( WP_CONTENT_DIR, '', function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() );
		}
	}

	private function init_hooks() {
		add_action( 'admin_init', array( $this, 'cleanup_abandoned_backups' ) );
		
		// Remover cron antigo, caso exista na transição
		add_action( 'init', array( $this, 'unschedule_legacy_crons' ) );

		add_action( 'admin_post_hub_run_backup_db', array( $this, 'handle_manual_db_backup' ) );
		add_action( 'admin_post_hub_run_backup_full', array( $this, 'handle_manual_full_backup' ) );
		add_action( 'admin_post_hub_upload_backup', array( $this, 'handle_upload_backup' ) );
		add_action( 'wp_ajax_hub_restore_chunk', array( $this, 'ajax_restore_chunk' ) );
		add_action( 'wp_ajax_hub_upload_backup_chunk', array( $this, 'ajax_upload_chunk' ) );
		
		// Ações para legacy
		add_action( 'admin_post_hub_download_legacy', array( $this, 'handle_download_legacy' ) );
		add_action( 'admin_post_hub_delete_legacy', array( $this, 'handle_delete_legacy' ) );

		// S3 Cron triggers
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
		add_action( 'init', array( $this, 'schedule_crons' ) );
		add_action( 'hub_schedule_daily_db', array( $this, 'schedule_s3_db_backup' ) );
		add_action( 'hub_schedule_weekly_full', array( $this, 'schedule_s3_full_backup' ) );
	}

	public function add_cron_intervals( $schedules ) {
		$schedules['hub_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'A cada 5 minutos (VitPress)',
		);
		$schedules['hub_weekly'] = array(
			'interval' => 604800,
			'display'  => 'Semanalmente (VitPress)',
		);
		return $schedules;
	}

	public function schedule_crons() {
		if ( ! wp_next_scheduled( 'hub_schedule_daily_db' ) ) {
			wp_schedule_event( strtotime('02:00:00'), 'daily', 'hub_schedule_daily_db' );
		}
		if ( ! wp_next_scheduled( 'hub_schedule_weekly_full' ) ) {
			wp_schedule_event( strtotime('next sunday 03:00:00'), 'hub_weekly', 'hub_schedule_weekly_full' );
		}
		if ( ! wp_next_scheduled( 'hub_s3_worker' ) ) {
			wp_schedule_event( time(), 'hub_five_minutes', 'hub_s3_worker' );
		}
	}

	public function unschedule_legacy_crons() {
		$crons = array( 'hub_database_backup_event', 'hub_full_backup_event' );
		foreach ( $crons as $cron ) {
			$timestamp = wp_next_scheduled( $cron );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $cron );
			}
		}
	}

	public function schedule_s3_db_backup() {
		$settings = get_option( 'hub_settings', [] );
		if ( empty( $settings['s3']['enabled'] ) ) return;

		$state = get_option( 'hub_backup_job_state', false );
		if ( $state && in_array( $state['status'], ['pending_generation', 'init_upload', 'uploading', 'completing'] ) ) {
			return; // Job running
		}
		
		update_option('hub_backup_job_state', [
			'status' => 'pending_generation',
			'type'   => 'db',
			'start'  => current_time('mysql')
		]);
	}

	public function schedule_s3_full_backup() {
		$settings = get_option( 'hub_settings', [] );
		if ( empty( $settings['s3']['enabled'] ) ) return;

		$state = get_option( 'hub_backup_job_state', false );
		if ( $state && in_array( $state['status'], ['pending_generation', 'init_upload', 'uploading', 'completing'] ) ) {
			return; // Job running
		}
		
		update_option('hub_backup_job_state', [
			'status' => 'pending_generation',
			'type'   => 'full',
			'start'  => current_time('mysql')
		]);
	}

	/**
	 * Pasta de trabalho protegida (fora da raiz web quando possível).
	 */
	private function workspace_dir() {
		if ( null === $this->workspace_dir ) {
			$document_root       = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : null;
			$outside             = defined( 'HUB_BACKUP_DIR' ) ? (string) HUB_BACKUP_DIR : dirname( rtrim( ABSPATH, '/\\' ) );
			$this->workspace_dir = ( new BackupWorkspace( WP_CONTENT_DIR, $outside, $document_root ) )->path();
		}
		return $this->workspace_dir;
	}

	private function prepare_temp_dir() {
		$base              = $this->workspace_dir();
		$this->temp_dir    = $base . 'tmp/';
		$this->uploads_dir = $base . 'uploads/';
		foreach ( array( $this->temp_dir, $this->uploads_dir ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			BackupWorkspace::protect( rtrim( $dir, '/' ) );
		}
	}

	/**
	 * Remove arquivos temporários com mais de 1 hora. Pastas de operação (op-*) e o lock da restauração
	 * nunca são removidos aqui: só confirmar, reverter ou descartar encerra uma restauração.
	 */
	public function cleanup_abandoned_backups() {
		$protection = array( '.htaccess', 'index.php', 'index.html', 'web.config' );
		$limit      = time() - 3600;

		$this->prepare_temp_dir();
		foreach ( array( $this->temp_dir, $this->uploads_dir ) as $dir ) {
			foreach ( (array) glob( $dir . '*' ) as $file ) {
				if ( is_file( $file ) && ! in_array( basename( $file ), $protection, true ) && filemtime( $file ) < $limit ) {
					@unlink( $file );
				}
			}
		}

		// Pasta temporária pública das versões anteriores: ZIPs, extração e estado antigos.
		if ( is_dir( $this->legacy_temp_dir ) ) {
			foreach ( (array) glob( $this->legacy_temp_dir . '*' ) as $path ) {
				if ( in_array( basename( $path ), $protection, true ) || filemtime( $path ) >= $limit ) {
					continue;
				}
				BackupFileMover::delete_path( $path );
			}
			BackupWorkspace::protect( rtrim( $this->legacy_temp_dir, '/' ) );
		}
	}

	private function generate_raw_sql() {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		
		$tmp_file = wp_tempnam( 'hub_db_dump' );
		$handle   = fopen( $tmp_file, 'w' );
		
		fwrite( $handle, "-- VitPress Database Backup (Raw SQL)\n" );
		fwrite( $handle, "-- Gerado em: " . current_time( 'mysql' ) . "\n" );
		fwrite( $handle, '-- sql_escaping: ' . BackupSql::ESCAPING_MARKER . "\n\n" );

		foreach ( $tables as $table ) {
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
			if ( ! empty( $create_table[1] ) ) {
				fwrite( $handle, "DROP TABLE IF EXISTS `$table`;\n" );
				fwrite( $handle, $create_table[1] . ";\n\n" );

				$rows = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_N );
				if ( $rows ) {
					foreach ( $rows as $row ) {
						fwrite( $handle, BackupSql::insert_statement( $table, $row ) . "\n" );
					}
					fwrite( $handle, "\n" );
				}
			}
		}

		fclose( $handle );
		return $tmp_file;
	}

	public function generate_db_file() {
		$this->cleanup_abandoned_backups();
		$this->prepare_temp_dir();
		
		$tmp_sql = $this->generate_raw_sql();
		$filename = sanitize_title( get_bloginfo( 'name' ) ) . '-db-' . current_time( 'Y-m-d_H-i-s' ) . '.sql';
		$filepath = $this->temp_dir . $filename;
		rename( $tmp_sql, $filepath );
		
		return $filepath;
	}

	public function generate_full_zip_file() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'no_zip', 'A extensão ZipArchive não está ativa neste servidor.' );
		}

		$this->cleanup_abandoned_backups();
		$this->prepare_temp_dir();

		$filename = 'backup-' . sanitize_title( get_bloginfo( 'name' ) ) . '-' . current_time( 'Y-m-d_H-i-s' ) . '.zip';
		$filepath = $this->temp_dir . $filename;

		$zip = new \ZipArchive();
		if ( $zip->open( $filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			return new \WP_Error( 'zip_failed', 'Erro ao criar arquivo ZIP.' );
		}

		// 1. Database
		$tmp_sql = $this->generate_raw_sql();
		$zip->addFile( $tmp_sql, 'database.sql' );

		// 2. Manifest
		global $wpdb;
		$settings = get_option( 'hub_settings', [] );
		$manifest = [
			'generator'        => 'VitPress',
			'backup_schema'    => BackupArchiveValidator::SUPPORTED_SCHEMA,
			'sql_escaping'     => BackupSql::ESCAPING_MARKER,
			'plugin_version'   => defined('HUB_VERSION') ? HUB_VERSION : '1.7.0',
			'wp_version'       => $GLOBALS['wp_version'],
			'php_version'      => PHP_VERSION,
			'original_url'     => site_url(),
			'table_prefix'     => $wpdb->prefix,
			'created_at'       => current_time( 'mysql' ),
			'uuid'             => $settings['installation_uuid'] ?? '',
		];
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		// 3. WP-Content
		$source = realpath( WP_CONTENT_DIR );
		$files  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		// Diretórios a ignorar
		$exclude_paths = array();
		$hub_backups_dir = realpath( WP_CONTENT_DIR . '/hub-backups' );
		if ( $hub_backups_dir ) $exclude_paths[] = $hub_backups_dir;
		$cache_dir = realpath( WP_CONTENT_DIR . '/cache' );
		if ( $cache_dir ) $exclude_paths[] = $cache_dir;

		foreach ( $files as $file ) {
			$realpath = $file->getRealPath();

			// Verifica se o arquivo está dentro de alguma pasta ignorada
			$skip = false;
			foreach ( $exclude_paths as $ex_path ) {
				if ( strpos( $realpath, $ex_path ) === 0 ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$relative_path = substr( $realpath, strlen( $source ) + 1 );
			$relative_path = str_replace( '\\', '/', $relative_path );

			if ( $file->isDir() ) {
				$zip->addEmptyDir( 'wp-content/' . $relative_path );
			} elseif ( $file->isFile() ) {
				$zip->addFile( $realpath, 'wp-content/' . $relative_path );
			}
		}

		$zip->close();
		@unlink( $tmp_sql );

		return $filepath;
	}

	public function handle_manual_db_backup() {
		if ( ! current_user_can( 'hub_vitagencia_manage_internal' ) ) wp_die( 'Acesso negado.' );
		check_admin_referer( 'hub_run_backup', 'hub_nonce' );

		$filepath = $this->generate_db_file();
		if ( is_wp_error( $filepath ) ) wp_die( $filepath->get_error_message() );

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/sql' );
		header( 'Content-Disposition: attachment; filename="' . basename( $filepath ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $filepath ) );
		readfile( $filepath );
		
		@unlink( $filepath );
		exit;
	}

	public function handle_manual_full_backup() {
		if ( ! current_user_can( 'hub_vitagencia_manage_internal' ) ) wp_die( 'Acesso negado.' );
		check_admin_referer( 'hub_run_backup', 'hub_nonce' );

		$filepath = $this->generate_full_zip_file();
		if ( is_wp_error( $filepath ) ) wp_die( $filepath->get_error_message() );

		// Envia por streaming e deleta em seguida
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $filepath ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $filepath ) );
		readfile( $filepath );

		@unlink( $filepath );
		exit;
	}

		public function ajax_upload_chunk() {
		check_ajax_referer( 'hub_upload_backup', 'hub_nonce' );
		if ( ! current_user_can( 'hub_vitagencia_manage_internal' ) ) { @ob_end_clean(); wp_send_json_error( 'Sem permissão.' ); }

		$this->prepare_temp_dir();

		if ( empty( $_FILES['chunk'] ) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ) { @ob_end_clean(); wp_send_json_error( 'Erro ao enviar o bloco do arquivo.' ); }

		$filename     = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
		$chunk_index  = intval( $_POST['chunk_index'] ?? -1 );
		$total_chunks = intval( $_POST['total_chunks'] ?? 0 );
		$chunk_size   = self::chunk_size();
		$chunk_bytes  = (int) $_FILES['chunk']['size'];

		if ( $total_chunks < 1 || $chunk_index < 0 || $chunk_index >= $total_chunks ) {
			@ob_end_clean(); wp_send_json_error( 'Bloco fora da sequência esperada.' );
		}
		$is_last = $chunk_index === $total_chunks - 1;
		if ( $chunk_bytes < 1 || $chunk_bytes > $chunk_size || ( ! $is_last && $chunk_bytes !== $chunk_size ) ) {
			@ob_end_clean(); wp_send_json_error( 'Tamanho de bloco inesperado. Recarregue a página e envie o arquivo novamente.' );
		}

		$safe_filename = 'chunk-' . hash_hmac( 'sha256', get_current_user_id() . '|' . $filename . '|' . $total_chunks, wp_salt( 'auth' ) ) . '.part';
		$destination   = $this->uploads_dir . $safe_filename;

		if ( 0 === $chunk_index ) {
			if ( file_exists( $destination ) ) {
				unlink( $destination );
			}
			$free = @disk_free_space( $this->uploads_dir );
			if ( false !== $free && (float) $total_chunks * $chunk_size * 2 > $free ) {
				@ob_end_clean(); wp_send_json_error( 'Espaço em disco insuficiente para receber e extrair este backup.' );
			}
		} else {
			clearstatcache( true, $destination );
			if ( ! file_exists( $destination ) || filesize( $destination ) !== $chunk_index * $chunk_size ) {
				@unlink( $destination );
				@ob_end_clean(); wp_send_json_error( 'Blocos recebidos fora de ordem ou incompletos. Envie o arquivo novamente.' );
			}
		}

		$out = fopen( $destination, 0 === $chunk_index ? 'wb' : 'ab' );
		$in  = $out ? fopen( $_FILES['chunk']['tmp_name'], 'rb' ) : false;
		if ( ! $out || ! $in ) {
			if ( $out ) {
				fclose( $out );
			}
			@unlink( $destination );
			@ob_end_clean(); wp_send_json_error( 'Falha ao escrever arquivo no servidor.' );
		}
		$written = stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		if ( $written !== $chunk_bytes ) {
			@unlink( $destination );
			@ob_end_clean(); wp_send_json_error( 'Falha ao gravar o bloco no servidor.' );
		}

		if ( $is_last ) {
			$check = BackupArchiveValidator::validate( $destination );
			if ( ! $check['ok'] ) {
				@unlink( $destination );
				@ob_end_clean(); wp_send_json_error( $check['error'] );
			}

			$final_name = self::new_restore_file_name();
			if ( ! @rename( $destination, $this->uploads_dir . $final_name ) ) {
				@unlink( $destination );
				@ob_end_clean(); wp_send_json_error( 'Falha ao preparar o arquivo para restauração.' );
			}

			@ob_end_clean(); wp_send_json_success( array( 'redirect' => admin_url( "admin.php?page=hub-vitagencia&tab=backup&restore_file={$final_name}" ) ) );
		}

		@ob_end_clean(); wp_send_json_success( array( 'message' => 'Chunk recebido.' ) );
	}

	public function handle_upload_backup() {
		if ( ! current_user_can( 'hub_vitagencia_manage_internal' ) ) wp_die();
		check_admin_referer( 'hub_upload_backup', 'hub_nonce' );

		$this->cleanup_abandoned_backups();
		$this->prepare_temp_dir();

		if ( empty( $_FILES['hubbackup_file'] ) || $_FILES['hubbackup_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_die( 'Erro no upload do arquivo.' );
		}

		$tmp_name = $_FILES['hubbackup_file']['tmp_name'];

		$check = BackupArchiveValidator::validate( $tmp_name );
		if ( ! $check['ok'] ) {
			wp_die( esc_html( $check['error'] ) );
		}

		$filename    = self::new_restore_file_name();
		$destination = $this->uploads_dir . $filename;

		if ( ! move_uploaded_file( $tmp_name, $destination ) ) {
			wp_die( 'Falha ao preparar o arquivo para restauração.' );
		}

		wp_safe_redirect( admin_url( "admin.php?page=hub-vitagencia&tab=backup&restore_file={$filename}" ) );
		exit;
	}

	const MAX_CHUNK_SIZE  = 8388608;
	const MIN_CHUNK_SIZE  = 262144;
	const CHUNK_OVERHEAD  = 65536;

	/**
	 * Tamanho do bloco de upload: cabe em upload_max_filesize e, com a sobrecarga do formulário, em post_max_size.
	 */
	public static function chunk_size_for( $upload_max, $post_max ) {
		$limits = array( self::MAX_CHUNK_SIZE );
		if ( $upload_max > 0 ) {
			$limits[] = (int) $upload_max;
		}
		if ( $post_max > 0 ) {
			$limits[] = (int) $post_max - self::CHUNK_OVERHEAD;
		}
		return max( self::MIN_CHUNK_SIZE, min( $limits ) );
	}

	public static function chunk_size() {
		return self::chunk_size_for(
			wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
			wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) )
		);
	}

	private static function new_restore_file_name() {
		return 'upload-restore-' . bin2hex( random_bytes( 16 ) ) . '.zip';
	}

	private static function is_restore_file_name( $file ) {
		return 1 === preg_match( '/^upload-restore-[a-f0-9]{32}\.zip$/', (string) $file );
	}

	/**
	 * Formato e validade do arquivo enviado, para a tela decidir o que oferecer.
	 */
	public function inspect_restore_file( $file ) {
		$this->prepare_temp_dir();
		$file = basename( (string) $file );
		if ( ! self::is_restore_file_name( $file ) || ! file_exists( $this->uploads_dir . $file ) ) {
			return array( 'ok' => false, 'error' => 'Arquivo de backup temporário não encontrado ou expirado. Envie novamente.', 'format' => '' );
		}
		return BackupArchiveValidator::validate( $this->uploads_dir . $file );
	}

	/**
	 * Restauração ativa (em andamento, interrompida ou aguardando confirmação), para a tela.
	 *
	 * @return array{id:string,phase:string,files_only:bool,allowed:string[],interrupted:?string,last_error:string,deferred:string[],updated:int}|null
	 */
	public function restore_status() {
		$this->prepare_temp_dir();
		$op = RestoreOperation::active( $this->workspace_dir() );
		if ( ! $op ) {
			return null;
		}
		$state  = $op->state();
		$runner = $this->restore_runner( $op );
		return array(
			'id'          => $op->id(),
			'phase'       => (string) ( $state['phase'] ?? '' ),
			'files_only'  => ! empty( $state['files_only'] ),
			'allowed'     => $runner->allowed_actions(),
			'interrupted' => $op->interrupted_step(),
			'last_error'  => (string) ( $state['last_error'] ?? '' ),
			'deferred'    => (array) ( $state['deferred'] ?? array() ),
			'updated'     => (int) ( $state['updated'] ?? 0 ),
			'object_cache' => RecoveryPreflight::object_cache( WP_CONTENT_DIR )['active'],
		);
	}

	private function restore_environment( $op_id ) {
		global $wpdb;
		return array(
			'content_dir'              => rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ),
			'maintenance_file'         => ABSPATH . '.maintenance',
			'protected_plugins'        => $this->protected_plugin_dirs(),
			'deferred_root'            => $this->workspace_dir() . 'restored-' . $op_id,
			'new_prefix'               => $wpdb->prefix,
			'disable_uploads_htaccess' => true,
		);
	}

	private function restore_runner( RestoreOperation $op ) {
		return new RestoreRunner(
			$op,
			$this->wpdb_adapter(),
			array_merge(
				$this->restore_environment( $op->id() ),
				array(
					'post_process'   => array( $this, 'post_process_restored' ),
					'verify_swapped' => array( $this, 'verify_swapped' ),
					'flush_cache'    => array( $this, 'flush_restore_cache' ),
					'preflight'      => array( $this, 'recovery_preflight' ),
					'object_cache'   => wp_using_ext_object_cache(),
					'recovery_ttl'   => defined( 'HUB_RESTORE_RECOVERY_TTL' ) ? max( 600, (int) HUB_RESTORE_RECOVERY_TTL ) : RestoreRunner::RECOVERY_TTL,
				)
			)
		);
	}

	/**
	 * Antes de emitir o link e de aplicar a restauração: o ponto de recuperação precisa funcionar sem o WordPress.
	 * Qualquer item que não possa ser comprovado bloqueia a troca.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function recovery_preflight() {
		$fail = function ( $message ) {
			return array( 'ok' => false, 'error' => 'O ponto de recuperação não pôde ser comprovado: ' . $message );
		};
		$wp_config = RecoveryPreflight::wordpress_config( ABSPATH );
		$ep_config = RecoveryPreflight::locate_config( HUB_PATH );
		if ( ! $wp_config || ! $ep_config || ! RecoveryPreflight::same_path( $wp_config, $ep_config ) ) {
			return $fail( 'ele não encontraria o mesmo wp-config.php usado pelo WordPress.' );
		}
		$problems = RecoveryPreflight::analyze_config( (string) @file_get_contents( $wp_config ) );
		if ( $problems ) {
			return $fail( 'o wp-config.php não pode ser carregado sem inicializar o WordPress — ' . implode( '; ', $problems ) . '. Use a forma padrão: require_once ABSPATH . \'wp-settings.php\';' );
		}
		if ( file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
			return $fail( 'o drop-in wp-content/db.php personaliza a conexão com o banco, e o ponto de recuperação usa a conexão padrão do wp-config.php. Desative-o durante a restauração.' );
		}
		if ( wp_using_ext_object_cache() && ! self::verified_cache_flush() ) {
			return $fail( 'o cache de objetos persistente não pôde ser limpo e conferido.' );
		}
		return $this->probe_recovery_endpoint( $fail );
	}

	/**
	 * Requisição local ao próprio restore-recovery.php, que precisa devolver a resposta assinada com o desafio desta
	 * verificação e relatar que carregou o wp-config.php sem o WordPress, achou esta pasta de trabalho e o banco.
	 */
	private function probe_recovery_endpoint( callable $fail ) {
		$workspace = $this->workspace_dir();
		$probe     = RecoveryPreflight::create_probe( $workspace );
		if ( ! $probe ) {
			return $fail( 'não foi possível preparar a verificação na pasta de trabalho.' );
		}
		$response = wp_remote_get(
			HUB_URL . 'restore-recovery.php?probe=' . $probe['challenge'],
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		RecoveryPreflight::discard_probe( $workspace );
		if ( is_wp_error( $response ) ) {
			return $fail( 'a requisição local de verificação falhou (' . $response->get_error_message() . '). O servidor precisa conseguir acessar o próprio site.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( false !== strpos( $body, RecoveryPreflight::WP_LOADED_TEXT ) ) {
			return $fail( 'o wp-config.php inicializa o WordPress (e os plugins) quando é carregado pelo ponto de recuperação.' );
		}
		if ( false !== strpos( $body, RecoveryPreflight::CONFIG_FAILED_TEXT ) ) {
			return $fail( 'o wp-config.php não termina de carregar fora do WordPress (ele tenta inicializar o WordPress por outro caminho ou depende de arquivos dele).' );
		}
		$signature = wp_remote_retrieve_header( $response, RecoveryPreflight::SIGNATURE_HEADER );
		$facts     = RecoveryPreflight::verify_probe( $probe['key'], $probe['challenge'], $body, is_string( $signature ) ? $signature : '' );
		if ( 200 !== $code || null === $facts ) {
			return $fail( 'a resposta não trouxe a assinatura do VitPress (HTTP ' . $code . '). O acesso direto a ' . HUB_URL . 'restore-recovery.php pode estar bloqueado pela hospedagem.' );
		}
		if ( ! empty( $facts['wordpress_loaded'] ) ) {
			return $fail( 'o WordPress foi carregado dentro do ponto de recuperação.' );
		}
		if ( ! RecoveryPreflight::same_path( (string) ( $facts['config'] ?? '' ), RecoveryPreflight::wordpress_config( ABSPATH ) ) ) {
			return $fail( 'ele carregou outro wp-config.php.' );
		}
		if ( ! RecoveryPreflight::same_path( (string) ( $facts['content_dir'] ?? '' ), WP_CONTENT_DIR ) || empty( $facts['workspace'] ) ) {
			return $fail( 'ele não encontrou a pasta wp-content ou a pasta de trabalho deste site.' );
		}
		if ( empty( $facts['database'] ) ) {
			return $fail( 'ele não conseguiu conectar ao banco com as credenciais do wp-config.php.' );
		}
		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Link de recuperação: o token vai no fragmento (#k=…), que não chega ao servidor nem aos logs de acesso.
	 */
	private function recovery_url( RestoreOperation $op, $token ) {
		return HUB_URL . 'restore-recovery.php?op=' . $op->id() . '#k=' . $token;
	}

	private function protected_plugin_dirs() {
		return array( basename( rtrim( str_replace( '\\', '/', HUB_PATH ), '/' ) ), 'hub-vitagencia' );
	}
	

	private function recursive_unserialize_replace( $data, $search_arr, $replace_arr ) {
		if ( is_string( $data ) ) {
			return str_replace( $search_arr, $replace_arr, $data );
		} elseif ( is_array( $data ) ) {
			$new_data = array();
			foreach ( $data as $key => $value ) {
				$new_data[ $key ] = $this->recursive_unserialize_replace( $value, $search_arr, $replace_arr );
			}
			return $new_data;
		} elseif ( is_object( $data ) ) {
			$new_data = clone $data;
			foreach ( $data as $key => $value ) {
				$new_data->$key = $this->recursive_unserialize_replace( $value, $search_arr, $replace_arr );
			}
			return $new_data;
		}
		return $data;
	}

	private function hub_safe_replace( $string, $search_arr, $replace_arr ) {
		if ( is_serialized( $string ) ) {
			$unserialized = @unserialize( $string );
			if ( $unserialized !== false ) {
				$replaced = $this->recursive_unserialize_replace( $unserialized, $search_arr, $replace_arr );
				return serialize( $replaced );
			}
		}
		return str_replace( $search_arr, $replace_arr, $string );
	}

	/**
	 * @param array<string,?string> $names Tabelas lógicas (options, users, usermeta, posts, postmeta) => nome real.
	 */
	private function run_full_database_replace( $old_url, $new_url, array $names ) {
		global $wpdb;
		$old_http = str_replace('https://', 'http://', $old_url);
		$old_https = str_replace('http://', 'https://', $old_url);
		$search_arr = [ $old_https, $old_http, str_replace('/', '\\/', $old_https), str_replace('/', '\\/', $old_http) ];
		$replace_arr = [ $new_url, $new_url, str_replace('/', '\\/', $new_url), str_replace('/', '\\/', $new_url) ];

		$columns = [
			'options'  => [ 'id' => 'option_id', 'column' => 'option_value' ],
			'postmeta' => [ 'id' => 'meta_id', 'column' => 'meta_value' ],
			'posts'    => [ 'id' => 'ID', 'column' => 'post_content' ],
			'usermeta' => [ 'id' => 'umeta_id', 'column' => 'meta_value' ],
			'users'    => [ 'id' => 'ID', 'column' => 'user_url' ],
		];
		$tables = [];
		foreach ( $columns as $name => $col_data ) {
			if ( ! empty( $names[ $name ] ) ) {
				$tables[ $names[ $name ] ] = $col_data;
			}
		}

		foreach ( $tables as $table => $col_data ) {
			$id_col = $col_data['id'];
			$val_col = $col_data['column'];
			$domain_only = str_replace(['https://', 'http://'], '', $old_url);
			$query = $wpdb->prepare( "SELECT {$id_col}, {$val_col} FROM {$table} WHERE {$val_col} LIKE %s", '%' . $wpdb->esc_like($domain_only) . '%' );
			$rows = $wpdb->get_results( $query );
			if ( '' !== $wpdb->last_error ) {
				return "leitura de {$table}";
			}
			if ( $rows ) {
				foreach ( $rows as $row ) {
					$old_val = $row->$val_col;
					$new_val = $this->hub_safe_replace( $old_val, $search_arr, $replace_arr );
					if ( $old_val !== $new_val && false === $wpdb->update( $table, [ $val_col => $new_val ], [ $id_col => $row->$id_col ] ) ) {
						return "atualização de {$table}";
					}
				}
			}
		}
		$elementor = $wpdb->query( $wpdb->prepare( "INSERT INTO `{$names['options']}` (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", 'elementor_css_print_method', 'internal' ) );
		$css       = empty( $names['postmeta'] ) ? 0 : $wpdb->query( $wpdb->prepare( "DELETE FROM `{$names['postmeta']}` WHERE meta_key = %s", '_elementor_css' ) );
		if ( false === $elementor || false === $css ) {
			return 'ajustes do Elementor';
		}
		return '';
	}

	/**
	 * Ajustes do banco restaurado (prefixos, home/siteurl, URLs em conteúdo, metadados e users.user_url), feitos nas
	 * tabelas temporárias antes da troca. Cada consulta é conferida; qualquer falha descarta a preparação.
	 *
	 * @param array<string,?string> $tables options, users, usermeta, posts, postmeta => tabela temporária.
	 * @return array{ok:bool,error:string}
	 */
	public function post_process_restored( array $state, array $tables ) {
		global $wpdb;
		$fail = function ( $what ) {
			return array( 'ok' => false, 'error' => $what . ' (' . BackupDatabaseImporter::safe_error( $GLOBALS['wpdb']->last_error ) . ')' );
		};
		if ( empty( $tables['options'] ) ) {
			return array( 'ok' => false, 'error' => 'o backup não contém a tabela de opções com o prefixo deste site' );
		}
		$options = $tables['options'];

		$manifest   = (array) ( $state['manifest'] ?? array() );
		$old_prefix = (string) ( $state['old_prefix'] ?? $wpdb->prefix );

		if ( $old_prefix !== $wpdb->prefix ) {
			$renames = array(
				array( 'options', 'option_name', 'user_roles' ),
				array( 'usermeta', 'meta_key', 'capabilities' ),
				array( 'usermeta', 'meta_key', 'user_level' ),
				array( 'usermeta', 'meta_key', 'dashboard_quick_press_last_post_id' ),
			);
			foreach ( $renames as list( $name, $column, $key ) ) {
				if ( empty( $tables[ $name ] ) ) {
					continue;
				}
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE `{$tables[ $name ]}` SET {$column} = %s WHERE {$column} = %s", $wpdb->prefix . $key, $old_prefix . $key ) ) ) {
					return $fail( 'ajuste de prefixo' );
				}
			}
		}

		$new_url = (string) ( $state['dest_url'] ?? '' );
		if ( '' === $new_url ) {
			return array( 'ok' => false, 'error' => 'URL do site de destino desconhecida' );
		}
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE `{$options}` SET option_value = %s WHERE option_name = 'home' OR option_name = 'siteurl'", $new_url ) );
		if ( false === $updated ) {
			return $fail( 'atualização da URL do site' );
		}
		$check = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$options}` WHERE option_name IN ('home','siteurl') AND option_value = %s", $new_url ) );
		if ( 2 !== $check ) {
			return array( 'ok' => false, 'error' => 'o banco restaurado não tem as opções home/siteurl esperadas' );
		}

		if ( ! empty( $manifest['original_url'] ) && $manifest['original_url'] !== $new_url ) {
			$problem = $this->run_full_database_replace( $manifest['original_url'], $new_url, $tables );
			if ( '' !== $problem ) {
				return $fail( 'substituição de URL: ' . $problem );
			}
			$old_http  = str_replace( 'https://', 'http://', $manifest['original_url'] );
			$old_https = str_replace( 'http://', 'https://', $manifest['original_url'] );
			if ( ! empty( $tables['posts'] ) ) {
				foreach ( array( $old_http, $old_https ) as $old ) {
					if ( false === $wpdb->query( $wpdb->prepare( "UPDATE `{$tables['posts']}` SET guid = replace(guid, %s, %s)", $old, $new_url ) ) ) {
						return $fail( 'atualização de GUIDs' );
					}
				}
			}
			if ( ! empty( $tables['users'] ) ) {
				$left = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tables['users']}` WHERE user_url = %s OR user_url = %s OR user_url LIKE %s OR user_url LIKE %s", $old_http, $old_https, $wpdb->esc_like( $old_http ) . '/%', $wpdb->esc_like( $old_https ) . '/%' ) );
				if ( null === $left || '' !== $wpdb->last_error ) {
					return $fail( 'conferência de users.user_url' );
				}
				if ( 0 !== (int) $left ) {
					return array( 'ok' => false, 'error' => 'users.user_url ainda aponta para o endereço de origem' );
				}
			}
		}
		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Conferência só de leitura logo após o RENAME: o banco ativo precisa ter home/siteurl deste site.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function verify_swapped( array $state ) {
		global $wpdb;
		$url   = (string) ( $state['dest_url'] ?? '' );
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ('home','siteurl') AND option_value = %s", $url ) );
		if ( null === $count || '' !== $wpdb->last_error ) {
			return array( 'ok' => false, 'error' => 'leitura das opções (' . BackupDatabaseImporter::safe_error( $wpdb->last_error ) . ')' );
		}
		return 2 === (int) $count ? array( 'ok' => true, 'error' => '' ) : array( 'ok' => false, 'error' => 'home/siteurl não correspondem a este site' );
	}

	/**
	 * Ações da restauração. Nenhuma etapa começa sozinha: a tela envia uma ação explícita por vez.
	 * start (arquivo enviado) -> database -> switch -> confirm | revert; discard antes da troca.
	 */
	public function ajax_restore_chunk() {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '1024M' );
		@ignore_user_abort( true );
		ob_start();
		check_ajax_referer( 'hub_restore_chunk', 'hub_nonce' );
		if ( ! current_user_can( 'hub_vitagencia_manage_internal' ) ) { @ob_end_clean(); wp_send_json_error( 'Sem permissão.' ); }

		$action = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$this->prepare_temp_dir();

		if ( 'start' === $action ) {
			$file     = isset( $_POST['file'] ) ? basename( sanitize_text_field( wp_unslash( $_POST['file'] ) ) ) : '';
			$filepath = $this->uploads_dir . $file;
			if ( ! self::is_restore_file_name( $file ) || ! file_exists( $filepath ) ) { @ob_end_clean(); wp_send_json_error( 'Arquivo de backup temporário não encontrado.' ); }
			if ( RestoreOperation::active( $this->workspace_dir() ) ) {
				@ob_end_clean(); wp_send_json_error( 'Já existe uma restauração em andamento ou interrompida. Conclua, reverta ou descarte a operação atual.' );
			}
			( new BackupDatabaseImporter( $this->wpdb_adapter() ) )->discard_orphans();

			$free  = @disk_free_space( $this->workspace_dir() );
			$check = BackupArchiveValidator::validate( $filepath, false === $free ? null : (float) $free );
			if ( ! $check['ok'] ) { @ob_end_clean(); wp_send_json_error( $check['error'] ); }
			if ( BackupArchiveValidator::FORMAT_LEGACY_CONTENT === $check['format'] && empty( $_POST['confirm_files_only'] ) ) {
				@ob_end_clean(); wp_send_json_error( 'Este backup antigo do Hub VitAgência (nome anterior do VitPress) contém apenas arquivos. Confirme a restauração somente dos arquivos para continuar.' );
			}

			$started = RestoreRunner::start(
				$this->workspace_dir(),
				$filepath,
				$check,
				array( 'owner' => array( 'user' => get_current_user_id() ), 'dest_url' => site_url(), 'env' => $this->restore_environment( '' ) )
			);
			if ( ! $started['ok'] ) { @ob_end_clean(); wp_send_json_error( $started['error'] ); }
			@ob_end_clean(); wp_send_json_success( $this->restore_response( $started['op'], array( 'ok' => true, 'phase' => 'prepared', 'closed' => false ) ) );
		}

		$op = RestoreOperation::active( $this->workspace_dir() );
		if ( ! $op ) { @ob_end_clean(); wp_send_json_error( 'Nenhuma restauração em andamento.' ); }
		if ( 'recovery_link' === $action ) {
			$preflight = $this->recovery_preflight();
			if ( ! $preflight['ok'] ) { @ob_end_clean(); wp_send_json_error( $preflight['error'] ); }
			$issued = $this->restore_runner( $op )->issue_recovery_token();
			if ( ! $issued['ok'] ) { @ob_end_clean(); wp_send_json_error( $issued['error'] ); }
			@ob_end_clean(); wp_send_json_success( array( 'recovery_url' => $this->recovery_url( $op, $issued['token'] ) ) );
		}
		if ( ! in_array( $action, array( 'database', 'switch', 'confirm', 'revert', 'discard' ), true ) ) {
			@ob_end_clean(); wp_send_json_error( 'Ação inválida.' );
		}
		if ( in_array( $action, array( 'confirm', 'revert' ), true ) && wp_using_ext_object_cache() && ! self::verified_cache_flush() ) {
			@ob_end_clean(); wp_send_json_error( 'O cache de objetos persistente não pôde ser limpo e conferido; nada foi alterado. Limpe-o pela hospedagem (ou wp cache flush) e tente de novo.' );
		}
		$result = $this->restore_runner( $op )->run( $action );
		if ( ! $result['ok'] ) { @ob_end_clean(); wp_send_json_error( $result['error'] ); }
		@ob_end_clean(); wp_send_json_success( $this->restore_response( $op, $result ) );
	}

	private function restore_response( RestoreOperation $op, array $result ) {
		$response = array( 'phase' => $result['phase'], 'closed' => ! empty( $result['closed'] ) );
		if ( 'switched' === $result['phase'] ) {
			$response['login_url'] = wp_login_url( admin_url( 'admin.php?page=hub-vitagencia&tab=backup' ) );
		}
		return $response;
	}

	private function wpdb_adapter() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		return new class( $wpdb ) {
			private $wpdb;
			public function __construct( $wpdb ) {
				$this->wpdb = $wpdb;
			}
			public function query( $sql ) {
				// Colunas binárias geram bytes fora do UTF-8 que o wpdb recusaria; o MySQL ainda valida o charset de cada coluna.
				$this->wpdb->check_current_query = false;
				return false !== $this->wpdb->query( $sql );
			}
			public function last_error() {
				return $this->wpdb->last_error;
			}
			public function tables() {
				return (array) $this->wpdb->get_col( 'SHOW TABLES' );
			}
			public function value( $sql ) {
				$value = $this->wpdb->get_var( $sql );
				return null === $value ? null : (string) $value;
			}
		};
	}

	// -------------------------------------------------------------------------------- //
	// LEGACY BACKUPS (Exclusão da arquitetura antiga)
	// -------------------------------------------------------------------------------- //

	public function get_legacy_backups() {
		$files = [];
		foreach ( array( $this->legacy_db_dir, $this->legacy_full_dir ) as $dir ) {
			if ( file_exists( $dir ) ) {
				$found = glob( $dir . '*.*' );
				if ( $found ) {
					foreach ( $found as $f ) {
						if ( in_array( pathinfo( $f, PATHINFO_EXTENSION ), array('htaccess','php') ) ) continue;
						$files[] = array(
							'path' => $f,
							'name' => basename( $f ),
							'size' => size_format( filesize( $f ) ),
							'date' => filemtime( $f )
						);
					}
				}
			}
		}
		usort( $files, function($a, $b) { return $b['date'] - $a['date']; } );
		return $files;
	}

	public function handle_download_legacy() {
		if ( ! current_user_can( 'hub_vitagencia_manage_internal' ) ) wp_die();
		check_admin_referer( 'hub_legacy_action', 'hub_nonce' );

		$file_name = sanitize_text_field( $_POST['file_name'] ?? '' );
		$file_path = '';

		// Busca nas pastas legacy se o arquivo existe e o nome bate
		foreach ( array( $this->legacy_db_dir, $this->legacy_full_dir ) as $dir ) {
			$check = $dir . basename( $file_name );
			if ( file_exists( $check ) ) {
				$file_path = $check;
				break;
			}
		}

		if ( ! $file_path ) wp_die( 'Arquivo legacy não encontrado.' );

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		readfile( $file_path );
		exit;
	}

	public function handle_delete_legacy() {
		if ( ! current_user_can( 'hub_vitagencia_manage_internal' ) ) wp_die();
		check_admin_referer( 'hub_legacy_action', 'hub_nonce' );

		$file_name = sanitize_text_field( $_POST['file_name'] ?? '' );

		if ( $file_name === 'ALL' ) {
			foreach ( array( $this->legacy_db_dir, $this->legacy_full_dir ) as $dir ) {
				if ( file_exists( $dir ) ) {
					$found = glob( $dir . '*.*' );
					if ( $found ) {
						foreach ( $found as $f ) {
							if ( in_array( pathinfo( $f, PATHINFO_EXTENSION ), array('htaccess','php') ) ) continue;
							@unlink( $f );
						}
					}
				}
			}
		} else {
			foreach ( array( $this->legacy_db_dir, $this->legacy_full_dir ) as $dir ) {
				$check = $dir . basename( $file_name );
				if ( file_exists( $check ) ) {
					@unlink( $check );
					break;
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=backup&message=legacy_deleted' ) );
		exit;
	}
}
