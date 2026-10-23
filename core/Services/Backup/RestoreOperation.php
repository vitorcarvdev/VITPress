<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uma restauração em disco: pasta própria com o ZIP, a extração, o estado e o diário de movimentações.
 *
 * - restore.lock na pasta de trabalho impede uma segunda restauração enquanto esta não termina.
 * - step.lock (flock) impede a mesma etapa em paralelo e revela etapas interrompidas:
 *   estado "running" com o flock livre significa que o processo morreu no meio.
 */
class RestoreOperation {

	const LOCK_FILE = 'restore.lock';

	private $workspace;
	private $id;
	private $dir;
	private $step_handle;

	private function __construct( $workspace, $id ) {
		$this->workspace = rtrim( $workspace, '/\\' ) . '/';
		$this->id        = $id;
		$this->dir       = $this->workspace . 'op-' . $id . '/';
	}

	/**
	 * Cria a operação e o lock global de forma atômica.
	 *
	 * @return self|string Operação ou mensagem de erro.
	 */
	public static function create( $workspace, array $owner ) {
		$id   = bin2hex( random_bytes( 8 ) );
		$op   = new self( $workspace, $id );
		$lock = @fopen( $op->workspace . self::LOCK_FILE, 'x' );
		if ( ! $lock ) {
			return 'Já existe uma restauração em andamento ou interrompida. Conclua, reverta ou descarte a operação atual antes de iniciar outra.';
		}
		fwrite( $lock, json_encode( array( 'id' => $id, 'owner' => $owner, 'created' => time() ) ) );
		fclose( $lock );
		if ( ! @mkdir( $op->dir, 0700 ) ) {
			@unlink( $op->workspace . self::LOCK_FILE );
			return 'Não foi possível criar a pasta da restauração.';
		}
		BackupWorkspace::protect( $op->dir );
		return $op;
	}

	/**
	 * Operação ativa (a que detém o lock global), se houver.
	 */
	public static function active( $workspace ) {
		$workspace = rtrim( $workspace, '/\\' ) . '/';
		$lock      = $workspace . self::LOCK_FILE;
		if ( ! is_file( $lock ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $lock ), true );
		if ( ! is_array( $data ) || empty( $data['id'] ) || ! preg_match( '/^[a-f0-9]{16}$/', $data['id'] ) ) {
			return null;
		}
		return new self( $workspace, $data['id'] );
	}

	public function id() {
		return $this->id;
	}

	public function dir() {
		return $this->dir;
	}

	public function zip_path() {
		return $this->dir . 'backup.zip';
	}

	public function extract_dir() {
		return $this->dir . 'extract/';
	}

	public function state() {
		$file = $this->dir . 'state.json';
		if ( ! is_file( $file ) ) {
			return array();
		}
		$state = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Mescla e grava o estado de forma atômica, com histórico de etapas.
	 */
	public function update( array $changes, $event = null ) {
		$state = array_merge( $this->state(), $changes, array( 'id' => $this->id, 'updated' => time() ) );
		if ( null !== $event ) {
			$state['history']   = $state['history'] ?? array();
			$state['history'][] = array( 'at' => time(), 'event' => $event );
			$state['history']   = array_slice( $state['history'], -50 );
		}
		if ( ! BackupWorkspace::atomic_write( $this->dir . 'state.json', json_encode( $state ) ) ) {
			throw new \RuntimeException( 'Não foi possível gravar o estado da restauração.' );
		}
		return $state;
	}

	public function journal_append( array $entry ) {
		$line = json_encode( $entry ) . "\n";
		$fh   = @fopen( $this->dir . 'journal.jsonl', 'ab' );
		if ( ! $fh || false === fwrite( $fh, $line ) ) {
			throw new \RuntimeException( 'Não foi possível gravar o diário da restauração.' );
		}
		fflush( $fh );
		fclose( $fh );
	}

	public function journal() {
		$file = $this->dir . 'journal.jsonl';
		if ( ! is_file( $file ) ) {
			return array();
		}
		$entries = array();
		foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	public function journal_reset() {
		@unlink( $this->dir . 'journal.jsonl' );
	}

	/**
	 * Trava de etapa: false se outra requisição está executando uma etapa desta operação.
	 */
	public function acquire_step() {
		$handle = @fopen( $this->dir . 'step.lock', 'c' );
		if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			if ( $handle ) {
				fclose( $handle );
			}
			return false;
		}
		$this->step_handle = $handle;
		return true;
	}

	public function release_step() {
		if ( $this->step_handle ) {
			flock( $this->step_handle, LOCK_UN );
			fclose( $this->step_handle );
			$this->step_handle = null;
		}
	}

	/**
	 * Etapa marcada como em execução cujo processo não existe mais.
	 * Se esta requisição já detém a trava, qualquer etapa marcada é de um processo anterior.
	 */
	public function interrupted_step() {
		$state = $this->state();
		if ( empty( $state['running'] ) ) {
			return null;
		}
		if ( $this->step_handle ) {
			return $state['running'];
		}
		if ( ! $this->acquire_step() ) {
			return null;
		}
		$this->release_step();
		return $state['running'];
	}

	/**
	 * Remove a pasta da operação e libera o lock global. Só deve ser chamado quando nada mais depende dela.
	 */
	public function close() {
		$this->release_step();
		BackupFileMover::delete_path( rtrim( $this->dir, '/' ) );
		$lock = json_decode( (string) @file_get_contents( $this->workspace . self::LOCK_FILE ), true );
		if ( is_array( $lock ) && ( $lock['id'] ?? '' ) === $this->id ) {
			@unlink( $this->workspace . self::LOCK_FILE );
		}
	}
}
