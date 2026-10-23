<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Máquina de estados da restauração.
 *
 *   start    -> prepared   (ZIP movido para a pasta da operação, extraído e conferido)
 *   database -> staged     (dump importado em tabelas hubrs… e já ajustado: prefixos e URLs; site intacto)
 *   switch   -> switched   (em uma requisição: manutenção, arquivos com diário, RENAME, conferência de leitura)
 *   confirm  -> encerrada  (apaga cópias antigas, tabelas hubold…, extração e ZIP)
 *   revert   -> staged     (desfaz banco e arquivos; idempotente)
 *   discard  -> encerrada  (só antes da troca)
 *
 * Cada etapa grava o estado antes e depois. Uma etapa marcada como em execução cujo processo morreu
 * só oferece ações de recuperação explícitas.
 *
 * A troca só começa com um link de recuperação já emitido e válido: se o PHP morrer no meio dela,
 * esse link (restore-recovery.php, sem carregar o WordPress) é o caminho para reverter.
 */
class RestoreRunner {

	const MAINTENANCE_MARKER = 'vitpress-restore';
	const RECOVERY_TTL       = 172800;

	private $op;
	private $db;
	private $options;

	/**
	 * @param RestoreOperation $op
	 * @param object           $db      Adaptador (ver BackupDatabaseImporter).
	 * @param array            $options content_dir, maintenance_file, protected_plugins, deferred_root, new_prefix,
	 *                                  post_process(array $state, array $tables): array{ok:bool,error:string} sobre as tabelas hubrs…,
	 *                                  verify_swapped(array $state): array{ok:bool,error:string} só leitura, após o RENAME,
	 *                                  flush_cache(): void, checkpoint(string $name): void (testes), disable_uploads_htaccess (bool),
	 *                                  recovery_ttl (segundos), preflight(): array{ok:bool,error:string} antes da troca
	 *                                  (o ponto de recuperação precisa estar comprovadamente utilizável),
	 *                                  object_cache (bool, cache de objetos persistente em uso na troca).
	 */
	public function __construct( RestoreOperation $op, $db, array $options ) {
		$this->op      = $op;
		$this->db      = $db;
		$this->options = array_merge(
			array(
				'protected_plugins'        => array(),
				'post_process'             => null,
				'verify_swapped'           => null,
				'flush_cache'              => null,
				'checkpoint'               => null,
				'disable_uploads_htaccess' => false,
				'recovery_ttl'             => self::RECOVERY_TTL,
				'preflight'                => null,
				'object_cache'             => false,
			),
			$options
		);
	}

	/**
	 * Opções do executor que não dependem do WordPress carregado, para o ponto de entrada de recuperação.
	 */
	public static function environment( array $options ) {
		return array_intersect_key( $options, array_flip( array( 'content_dir', 'maintenance_file', 'protected_plugins', 'deferred_root', 'new_prefix', 'disable_uploads_htaccess' ) ) );
	}

	public function operation() {
		return $this->op;
	}

	/**
	 * Cria a operação a partir de um ZIP já validado.
	 *
	 * @param array $check   Resultado de BackupArchiveValidator::validate().
	 * @param array $context owner, dest_url, env (ver environment()).
	 * @return array{ok:bool,error:string,op:?RestoreOperation}
	 */
	public static function start( $workspace, $zip_source, array $check, array $context ) {
		$op = RestoreOperation::create( $workspace, $context['owner'] ?? array() );
		if ( is_string( $op ) ) {
			return array( 'ok' => false, 'error' => $op, 'op' => null );
		}
		$op->acquire_step();
		$files_only = BackupArchiveValidator::FORMAT_LEGACY_CONTENT === $check['format'];
		$manifest   = $check['manifest'] ?? array();
		$env        = (array) ( $context['env'] ?? array() );
		if ( $env ) {
			$env['deferred_root'] = rtrim( str_replace( '\\', '/', $workspace ), '/' ) . '/restored-' . $op->id();
		}
		$op->update(
			array(
				'phase'      => 'starting',
				'running'    => 'start',
				'format'     => $check['format'],
				'files_only' => $files_only,
				'dest_url'   => (string) ( $context['dest_url'] ?? '' ),
				'old_prefix' => ! empty( $manifest['table_prefix'] ) ? (string) $manifest['table_prefix'] : 'wp_',
				'repair_sql' => ! $files_only && 'VitPress' === ( $manifest['generator'] ?? '' ) && BackupSql::ESCAPING_MARKER !== ( $manifest['sql_escaping'] ?? '' ),
				'manifest'   => $manifest,
				'source'     => basename( $zip_source ),
				'env'        => self::environment( $env ),
			),
			'start:begin'
		);

		$fail = function ( $message ) use ( $op, $zip_source ) {
			if ( is_file( $op->zip_path() ) && ! file_exists( $zip_source ) ) {
				@rename( $op->zip_path(), $zip_source );
			}
			$op->close();
			return array( 'ok' => false, 'error' => $message . ' Nada foi alterado no site.', 'op' => null );
		};

		$checkpoint = $context['checkpoint'] ?? null;
		if ( ! @rename( $zip_source, $op->zip_path() ) ) {
			return $fail( 'Não foi possível mover o backup para a pasta da restauração.' );
		}
		if ( is_callable( $checkpoint ) ) {
			$checkpoint( 'start:zip_moved' );
		}
		if ( ! @mkdir( $op->extract_dir(), 0700 ) ) {
			return $fail( 'Não foi possível criar a pasta de extração.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $op->zip_path() ) ) {
			return $fail( 'Erro ao abrir o ZIP.' );
		}
		$extracted = $zip->extractTo( $op->extract_dir() );
		$zip->close();
		if ( ! $extracted ) {
			return $fail( 'Falha ao extrair o backup (arquivo corrompido ou sem espaço em disco).' );
		}
		if ( is_callable( $checkpoint ) ) {
			$checkpoint( 'start:extracted' );
		}
		$integrity = BackupArchiveValidator::verify_extracted( $op->zip_path(), $op->extract_dir() );
		if ( '' !== $integrity ) {
			return $fail( $integrity . '.' );
		}
		@unlink( $op->zip_path() );

		$op->update(
			array(
				'phase'   => 'prepared',
				'running' => null,
				'next'    => $files_only ? 'switch' : 'database',
			),
			'start:done'
		);
		$op->release_step();
		return array( 'ok' => true, 'error' => '', 'op' => $op );
	}

	/**
	 * Ações permitidas no estado atual.
	 */
	public function allowed_actions() {
		$state = $this->op->state();
		$phase = $state['phase'] ?? '';

		$interrupted = $this->op->interrupted_step();
		if ( null !== $interrupted ) {
			switch ( $interrupted ) {
				case 'start':
				case 'database':
				case 'discard':
					return array( 'discard' );
				case 'switch':
				case 'revert':
					return array( 'revert' );
				case 'confirm':
					return array( 'confirm' );
			}
			return array();
		}
		if ( ! empty( $state['running'] ) ) {
			return array();
		}

		switch ( $phase ) {
			case 'prepared':
				return $state['files_only'] ? array( 'switch', 'discard' ) : array( 'database', 'discard' );
			case 'staged':
				return array( 'switch', 'discard' );
			case 'switched':
				return array( 'confirm', 'revert' );
			case 'revert_failed':
				return array( 'revert' );
		}
		return array();
	}

	/**
	 * @return array{ok:bool,error:string,phase:string,closed:bool}
	 */
	public function run( $action ) {
		if ( ! $this->op->acquire_step() ) {
			return $this->result( false, 'Outra etapa desta restauração está em execução. Aguarde e atualize a página.' );
		}
		try {
			if ( ! in_array( $action, $this->allowed_actions(), true ) ) {
				return $this->result( false, 'Ação não permitida no estado atual da restauração.' );
			}
			$this->op->update( array( 'running' => $action, 'last_error' => '' ), $action . ':begin' );

			switch ( $action ) {
				case 'database':
					return $this->do_database();
				case 'switch':
					return $this->do_switch();
				case 'confirm':
					return $this->do_confirm();
				case 'revert':
					return $this->do_revert();
				case 'discard':
					return $this->do_discard();
			}
			return $this->result( false, 'Ação desconhecida.' );
		} catch ( \Throwable $e ) {
			$state = $this->op->state();
			if ( 'switch' === ( $state['running'] ?? '' ) ) {
				return $this->rollback_switch( 'Erro inesperado durante a troca.' );
			}
			$this->safe_update( array( 'running' => null, 'last_error' => 'Erro inesperado na etapa.' ), $action . ':error' );
			return $this->result( false, 'Erro inesperado na etapa.' );
		} finally {
			$this->op->release_step();
		}
	}

	private function do_database() {
		$state    = $this->op->state();
		$importer = $this->importer();
		$importer->discard_token();
		$this->checkpoint( 'database:begin' );

		$sql = $this->op->extract_dir() . 'database.sql';
		if ( ! is_file( $sql ) ) {
			$this->op->update( array( 'running' => null, 'last_error' => 'database.sql não encontrado.' ), 'database:error' );
			return $this->result( false, 'database.sql não encontrado na extração.' );
		}
		$staged = $importer->stage( $sql, $state['old_prefix'], $this->options['new_prefix'], ! empty( $state['repair_sql'] ) );
		if ( ! $staged['ok'] ) {
			$this->op->update( array( 'running' => null, 'last_error' => $staged['error'] ), 'database:error' );
			return $this->result( false, $staged['error'] );
		}
		$this->checkpoint( 'database:staged' );

		// Prefixos e URLs são ajustados nas tabelas temporárias: logo após o RENAME o banco ativo já está correto.
		if ( is_callable( $this->options['post_process'] ) ) {
			$tables = array();
			foreach ( array( 'options', 'users', 'usermeta', 'posts', 'postmeta' ) as $name ) {
				$tables[ $name ] = $staged['staged'][ $this->options['new_prefix'] . $name ] ?? null;
			}
			$post = call_user_func( $this->options['post_process'], $state, $tables );
			if ( empty( $post['ok'] ) ) {
				$message = 'Falha no ajuste do banco restaurado: ' . ( $post['error'] ?? 'erro desconhecido' ) . '. O site não foi alterado.';
				$importer->discard( $staged['staged'] );
				$this->op->update( array( 'running' => null, 'last_error' => $message ), 'database:error' );
				return $this->result( false, $message );
			}
		}
		$this->checkpoint( 'database:post_processed' );
		$this->op->update( array( 'phase' => 'staged', 'running' => null, 'staged' => $staged['staged'], 'next' => 'switch' ), 'database:done' );
		// O dump (com dados pessoais) não é mais necessário: a reversão usa as tabelas, não o arquivo.
		@unlink( $sql );
		return $this->result( true, '' );
	}

	private function do_switch() {
		$state = $this->op->state();
		if ( ! $this->recovery_ready( $state ) ) {
			$this->op->update( array( 'running' => null ), 'switch:no_recovery' );
			return $this->result( false, 'Gere e guarde o link de recuperação antes de aplicar a restauração.' );
		}
		if ( is_callable( $this->options['preflight'] ) ) {
			$preflight = call_user_func( $this->options['preflight'] );
			if ( empty( $preflight['ok'] ) ) {
				$message = 'A restauração não foi aplicada: ' . ( $preflight['error'] ?? 'o ponto de recuperação não pôde ser verificado' ) . ' O site não foi alterado.';
				$this->op->update( array( 'running' => null, 'last_error' => $message ), 'switch:preflight_failed' );
				return $this->result( false, $message );
			}
		}
		$this->op->journal_reset();

		$plan = null;
		if ( empty( $state['files_only'] ) ) {
			$plan = $this->importer()->plan( $state['staged'] ?? array() );
		}
		$this->op->update(
			array(
				'plan'                => $plan,
				'db_swap'             => 'pending',
				'maintenance_created' => ! file_exists( $this->options['maintenance_file'] ),
				'recovery_expires'    => time() + (int) $this->options['recovery_ttl'],
				'object_cache'        => (bool) $this->options['object_cache'],
			),
			'switch:plan'
		);
		$this->checkpoint( 'switch:planned' );

		if ( $this->op->state()['maintenance_created'] ) {
			@file_put_contents( $this->options['maintenance_file'], '<?php $upgrading = ' . time() . '; // ' . self::MAINTENANCE_MARKER . "\n" );
		}
		$this->flush_cache();

		$mover  = $this->mover();
		$source = $state['files_only'] ? rtrim( $this->op->extract_dir(), '/' ) : $this->op->extract_dir() . 'wp-content';
		if ( is_dir( $source ) ) {
			$moved = $mover->move( $source, $this->options['content_dir'] );
			$this->op->update( array( 'deferred' => $moved['deferred'] ), 'switch:files' );
			if ( ! $moved['ok'] ) {
				return $this->rollback_switch( 'Falha ao copiar arquivos: ' . implode( ' | ', array_slice( $moved['errors'], 0, 3 ) ) );
			}
		}
		$this->checkpoint( 'switch:files_moved' );

		if ( $this->options['disable_uploads_htaccess'] ) {
			$htaccess = $this->options['content_dir'] . '/uploads/.htaccess';
			if ( is_file( $htaccess ) ) {
				$this->op->journal_append( array( 'a' => 'move', 'src' => $htaccess, 'dst' => $htaccess . '.vitpress-off-' . $this->op->id(), 'aside' => null ) );
				@rename( $htaccess, $htaccess . '.vitpress-off-' . $this->op->id() );
			}
		}

		if ( null !== $plan ) {
			$this->op->update( array( 'db_swap' => 'started' ), 'switch:rename:begin' );
			$this->checkpoint( 'switch:before_rename' );
			$swap = $this->importer()->swap( $plan );
			if ( ! $swap['ok'] ) {
				return $this->rollback_switch( $swap['error'] );
			}
			$this->checkpoint( 'switch:after_rename' );
			$this->op->update( array( 'db_swap' => 'done' ), 'switch:rename:done' );
			$this->flush_cache();

			$verify = is_callable( $this->options['verify_swapped'] ) ? call_user_func( $this->options['verify_swapped'], $this->op->state() ) : array( 'ok' => true, 'error' => '' );
			if ( empty( $verify['ok'] ) ) {
				return $this->rollback_switch( 'O banco ativado não passou na conferência: ' . ( $verify['error'] ?? 'erro desconhecido' ) . '.' );
			}
			$this->checkpoint( 'switch:verified' );
		}

		$this->flush_cache();
		$this->remove_maintenance();
		$this->op->update( array( 'phase' => 'switched', 'running' => null, 'next' => 'confirm' ), 'switch:done' );
		return $this->result( true, '' );
	}

	private function rollback_switch( $message ) {
		$reverted = $this->revert_changes();
		if ( '' !== $reverted ) {
			$this->safe_update( array( 'phase' => 'revert_failed', 'running' => null, 'last_error' => $message . ' A reversão também falhou: ' . $reverted ), 'switch:revert_failed' );
			return $this->result( false, $message . ' A reversão automática falhou (' . $reverted . '). Nada foi apagado; use "Reverter" para tentar de novo.' );
		}
		$state = $this->op->state();
		$this->safe_update( array( 'phase' => $state['files_only'] ? 'prepared' : 'staged', 'running' => null, 'last_error' => $message ), 'switch:rolled_back' );
		return $this->result( false, $message . ' O site foi mantido como estava.' );
	}

	/**
	 * Desfaz banco e arquivos. Retorna '' em caso de sucesso ou a descrição do problema.
	 */
	private function revert_changes() {
		$state = $this->op->state();
		$this->checkpoint( 'revert:begin' );
		if ( ! empty( $state['plan'] ) ) {
			$unswap = $this->importer()->unswap( $state['plan'] );
			if ( ! $unswap['ok'] ) {
				return $unswap['error'];
			}
			$this->safe_update( array( 'db_swap' => 'pending' ), 'revert:database' );
		}
		$this->checkpoint( 'revert:database_done' );
		$errors = BackupFileMover::rollback( $this->op->journal() );
		if ( $errors ) {
			return implode( ' | ', array_slice( $errors, 0, 3 ) );
		}
		$this->op->journal_reset();
		$this->flush_cache();
		$this->remove_maintenance();
		return '';
	}

	private function do_revert() {
		$reverted = $this->revert_changes();
		if ( '' !== $reverted ) {
			$this->op->update( array( 'phase' => 'revert_failed', 'running' => null, 'last_error' => $reverted ), 'revert:failed' );
			return $this->result( false, 'Falha ao reverter: ' . $reverted . ' Nada foi apagado.' );
		}
		$state = $this->op->state();
		$this->op->update( array( 'phase' => $state['files_only'] ? 'prepared' : 'staged', 'running' => null, 'plan' => null, 'recovery_hash' => null, 'recovery_expires' => null ), 'revert:done' );
		return $this->result( true, '' );
	}

	private function do_confirm() {
		$state  = $this->op->state();
		$failed = BackupFileMover::finalize( $this->op->journal() );
		$this->checkpoint( 'confirm:files_finalized' );
		if ( ! empty( $state['plan'] ) ) {
			$failed = array_merge( $failed, $this->importer()->drop_retired( $state['plan'] ) );
		}
		if ( $failed ) {
			$this->op->update( array( 'running' => null, 'last_error' => 'Algumas cópias antigas não puderam ser removidas.' ), 'confirm:partial' );
			return $this->result( false, 'Restauração mantida, mas ' . count( $failed ) . ' cópia(s) antiga(s) não puderam ser removidas. Tente confirmar novamente.' );
		}
		$this->remove_maintenance();
		$this->op->close();
		return $this->result( true, '', true );
	}

	private function do_discard() {
		$state    = $this->op->state();
		$importer = $this->importer();
		$failed   = array_merge( $importer->discard( $state['staged'] ?? array() ), $importer->discard_token() );
		if ( $failed ) {
			$this->op->update( array( 'running' => null, 'last_error' => 'Algumas tabelas temporárias não puderam ser removidas.' ), 'discard:partial' );
			return $this->result( false, 'Algumas tabelas temporárias não puderam ser removidas. Tente descartar novamente.' );
		}
		$this->checkpoint( 'discard:tables_dropped' );
		$this->op->close();
		return $this->result( true, '', true );
	}

	/**
	 * Emite (ou substitui) o link de recuperação. Só antes da troca ou com a troca aplicada, nunca no meio de uma etapa:
	 * depois de uma interrupção vale o link emitido antes dela.
	 *
	 * @return array{ok:bool,error:string,token:string}
	 */
	public function issue_recovery_token() {
		if ( ! $this->op->acquire_step() ) {
			return array( 'ok' => false, 'error' => 'Outra etapa desta restauração está em execução. Aguarde e atualize a página.', 'token' => '' );
		}
		try {
			$state = $this->op->state();
			$phase = $state['phase'] ?? '';
			$ready = 'staged' === $phase || 'switched' === $phase || ( 'prepared' === $phase && ! empty( $state['files_only'] ) );
			if ( ! empty( $state['running'] ) || ! $ready ) {
				return array( 'ok' => false, 'error' => 'O link de recuperação não pode ser gerado no estado atual da restauração.', 'token' => '' );
			}
			$token = bin2hex( random_bytes( 32 ) );
			$this->op->update( array( 'recovery_hash' => hash( 'sha256', $token ), 'recovery_expires' => time() + (int) $this->options['recovery_ttl'] ), 'recovery:issued' );
			return array( 'ok' => true, 'error' => '', 'token' => $token );
		} finally {
			$this->op->release_step();
		}
	}

	/**
	 * Token de 64 hexadecimais, emitido para esta operação e ainda válido. Reverter apaga o hash (uso único);
	 * confirmar encerra a operação.
	 */
	public function verify_recovery_token( $token ) {
		$state = $this->op->state();
		$hash  = (string) ( $state['recovery_hash'] ?? '' );
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) || '' === $hash || (int) ( $state['recovery_expires'] ?? 0 ) <= time() ) {
			return false;
		}
		return hash_equals( $hash, hash( 'sha256', $token ) );
	}

	private function recovery_ready( array $state ) {
		return ! empty( $state['recovery_hash'] ) && (int) ( $state['recovery_expires'] ?? 0 ) > time();
	}

	private function importer() {
		return new BackupDatabaseImporter( $this->db, substr( $this->op->id(), 0, 6 ) );
	}

	private function mover() {
		$op = $this->op;
		return new BackupFileMover(
			$this->options['protected_plugins'],
			$op->id(),
			function ( array $entry ) use ( $op ) {
				$op->journal_append( $entry );
				$this->checkpoint( 'switch:journal' );
			},
			$this->options['deferred_root'],
			array( 'hub-backups' ),
			// O backup antigo não traz o banco: o destino mantém os anexos cadastrados, então uploads é mesclado.
			! empty( $op->state()['files_only'] ) ? array( 'uploads' ) : array()
		);
	}

	private function remove_maintenance() {
		$state = $this->op->state();
		$file  = $this->options['maintenance_file'];
		if ( ! empty( $state['maintenance_created'] ) && is_file( $file ) && false !== strpos( (string) file_get_contents( $file ), self::MAINTENANCE_MARKER ) ) {
			@unlink( $file );
		}
	}

	private function flush_cache() {
		if ( is_callable( $this->options['flush_cache'] ) ) {
			call_user_func( $this->options['flush_cache'] );
		}
	}

	private function checkpoint( $name ) {
		if ( is_callable( $this->options['checkpoint'] ) ) {
			call_user_func( $this->options['checkpoint'], $name );
		}
	}

	private function safe_update( array $changes, $event ) {
		try {
			$this->op->update( $changes, $event );
		} catch ( \Throwable $e ) {
			// Estado anterior continua em disco; a próxima requisição verá a etapa como interrompida.
		}
	}

	private function result( $ok, $error, $closed = false ) {
		$state = $closed ? array() : $this->op->state();
		return array(
			'ok'     => (bool) $ok,
			'error'  => (string) $error,
			'phase'  => $closed ? 'closed' : ( $state['phase'] ?? '' ),
			'closed' => $closed,
		);
	}
}
