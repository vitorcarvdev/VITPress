<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move o conteúdo extraído para wp-content sem apagar nada antes da confirmação.
 *
 * Cada movimentação é registrada no diário antes de acontecer. O item existente é renomeado para
 * ".vitpress-old-<operação>-<nome>" na mesma pasta (mantém a extensão e fica oculto das listas do WordPress)
 * e só é apagado em finalize(), depois que o administrador confirma a restauração.
 * rollback() usa apenas o diário e o que existe em disco, então funciona depois de uma interrupção.
 *
 * Na raiz (wp-content) os diretórios são percorridos; dentro deles cada item é trocado inteiro, exceto nas
 * raízes de mesclagem ($merge_roots, ex.: uploads no backup antigo), onde só os arquivos presentes no backup
 * são trocados e o que existe apenas no destino continua no lugar.
 * Drop-ins e mu-plugins do backup não são ativados: vão para $deferred_root, fora do alcance do WordPress.
 */
class BackupFileMover {

	const DEFERRED_ROOT_ITEMS = array(
		'mu-plugins',
		'advanced-cache.php',
		'object-cache.php',
		'db.php',
		'db-error.php',
		'install.php',
		'maintenance.php',
		'php-error.php',
		'fatal-error-handler.php',
		'sunrise.php',
		'blog-deleted.php',
		'blog-inactive.php',
		'blog-suspended.php',
	);

	private $protected_plugins;
	private $protected_roots;
	private $merge_roots;
	private $op_id;
	private $journal;
	private $deferred_root;
	private $errors   = array();
	private $moved    = 0;
	private $deferred = array();

	/**
	 * @param string[] $protected_plugins Pastas em plugins/ que nunca são trocadas (o próprio VitPress).
	 * @param string   $op_id             Identificador da operação (nome das cópias antigas).
	 * @param callable $journal           Recebe cada entrada antes de a movimentação acontecer; deve lançar exceção se não gravar.
	 * @param string   $deferred_root     Pasta protegida para drop-ins e mu-plugins do backup.
	 * @param string[] $protected_roots   Itens na raiz de wp-content que nunca são trocados.
	 * @param string[] $merge_roots       Pastas na raiz de wp-content mescladas arquivo a arquivo.
	 */
	public function __construct( array $protected_plugins, $op_id, callable $journal, $deferred_root, array $protected_roots = array( 'hub-backups' ), array $merge_roots = array() ) {
		$this->protected_plugins = array_values( array_unique( array_filter( $protected_plugins ) ) );
		$this->protected_roots   = $protected_roots;
		$this->merge_roots       = $merge_roots;
		$this->op_id             = preg_replace( '/[^a-f0-9]/', '', (string) $op_id );
		$this->journal           = $journal;
		$this->deferred_root     = rtrim( str_replace( '\\', '/', $deferred_root ), '/' );
	}

	/**
	 * @return array{ok:bool,moved:int,errors:string[],deferred:string[]}
	 */
	public function move( $source_root, $target_root ) {
		$this->errors   = array();
		$this->moved    = 0;
		$this->deferred = array();
		$source_root    = rtrim( str_replace( '\\', '/', $source_root ), '/' );
		$target_root    = rtrim( str_replace( '\\', '/', $target_root ), '/' );

		if ( ! is_dir( $source_root ) ) {
			return $this->result( array( 'Diretório extraído não encontrado.' ) );
		}
		if ( ! is_dir( $target_root ) || ! is_writable( $target_root ) ) {
			return $this->result( array( 'wp-content não está gravável.' ) );
		}

		$this->move_level( $source_root, $target_root, '' );
		return $this->result( $this->errors );
	}

	private function result( array $errors ) {
		return array( 'ok' => ! $errors, 'moved' => $this->moved, 'errors' => $errors, 'deferred' => $this->deferred );
	}

	private function move_level( $src_dir, $dst_dir, $relative ) {
		$items = scandir( $src_dir );
		if ( false === $items ) {
			$this->errors[] = 'Não foi possível ler: ' . ( '' === $relative ? '(raiz)' : $relative );
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || $this->errors ) {
				continue;
			}
			$rel = '' === $relative ? $item : $relative . '/' . $item;
			if ( $this->is_protected( $rel ) ) {
				continue;
			}
			$src = $src_dir . '/' . $item;
			$dst = $dst_dir . '/' . $item;

			if ( '' === $relative && in_array( strtolower( $item ), self::DEFERRED_ROOT_ITEMS, true ) ) {
				if ( ! $this->ensure_dir( $this->deferred_root ) ) {
					return;
				}
				$this->replace( $src, $this->deferred_root . '/' . $item, $rel );
				$this->deferred[] = $item;
				continue;
			}

			if ( is_dir( $src ) && ( '' === $relative || ( ! is_link( $src ) && in_array( explode( '/', $rel )[0], $this->merge_roots, true ) ) ) ) {
				if ( file_exists( $dst ) && ! is_dir( $dst ) ) {
					$this->errors[] = 'Conflito de tipo (arquivo x pasta): ' . $rel;
					return;
				}
				if ( ! $this->ensure_dir( $dst ) ) {
					return;
				}
				$this->move_level( $src, $dst, $rel );
				continue;
			}
			$this->replace( $src, $dst, $rel );
		}
	}

	private function ensure_dir( $dir ) {
		if ( is_dir( $dir ) ) {
			return true;
		}
		call_user_func( $this->journal, array( 'a' => 'mkdir', 'dst' => $dir ) );
		if ( ! @mkdir( $dir, 0755 ) ) {
			$this->errors[] = 'Não foi possível criar a pasta: ' . basename( $dir );
			return false;
		}
		return true;
	}

	private function replace( $src, $dst, $rel ) {
		$aside = ( file_exists( $dst ) || is_link( $dst ) ) ? $this->aside_path( $dst ) : null;
		call_user_func( $this->journal, array( 'a' => 'move', 'src' => $src, 'dst' => $dst, 'aside' => $aside ) );

		if ( null !== $aside && ! @rename( $dst, $aside ) ) {
			$this->errors[] = 'Não foi possível preparar a substituição de: ' . $rel;
			return;
		}
		if ( ! @rename( $src, $dst ) ) {
			$this->errors[] = 'Não foi possível mover: ' . $rel;
			return;
		}
		$this->moved++;
	}

	private function aside_path( $dst ) {
		return dirname( $dst ) . '/.vitpress-old-' . $this->op_id . '-' . basename( $dst );
	}

	/**
	 * Desfaz as movimentações do diário, da última para a primeira, conferindo o que existe em disco.
	 * Idempotente: pode ser chamado de novo depois de uma falha ou interrupção.
	 *
	 * @param array[] $journal
	 * @return string[] Erros (vazio quando tudo voltou ao lugar).
	 */
	public static function rollback( array $journal ) {
		$errors = array();
		foreach ( array_reverse( $journal ) as $entry ) {
			$action = $entry['a'] ?? '';
			if ( 'mkdir' === $action ) {
				$dir = $entry['dst'];
				if ( is_dir( $dir ) && ! @rmdir( $dir ) && count( scandir( $dir ) ) > 2 ) {
					$errors[] = 'Pasta criada pela restauração não está vazia: ' . basename( $dir );
				}
				continue;
			}
			if ( 'move' !== $action ) {
				continue;
			}
			$src   = $entry['src'];
			$dst   = $entry['dst'];
			$aside = $entry['aside'] ?? null;

			if ( null !== $aside && ! self::exists( $aside ) ) {
				// A cópia antiga não chegou a ser criada: o destino ainda é o original.
				continue;
			}
			if ( self::exists( $dst ) ) {
				if ( self::exists( $src ) ) {
					$errors[] = 'Conflito ao desfazer: ' . basename( $dst );
					continue;
				}
				if ( ! @rename( $dst, $src ) ) {
					$errors[] = 'Falha ao desfazer: ' . basename( $dst );
					continue;
				}
			}
			if ( null !== $aside && ! @rename( $aside, $dst ) ) {
				$errors[] = 'Falha ao recolocar o original de: ' . basename( $dst );
			}
		}
		return $errors;
	}

	/**
	 * Depois da confirmação: apaga as cópias antigas registradas no diário.
	 *
	 * @return string[] Caminhos que não puderam ser apagados.
	 */
	public static function finalize( array $journal ) {
		$failed = array();
		foreach ( $journal as $entry ) {
			if ( 'move' === ( $entry['a'] ?? '' ) && ! empty( $entry['aside'] ) && self::exists( $entry['aside'] ) ) {
				if ( ! self::delete_path( $entry['aside'] ) ) {
					$failed[] = basename( $entry['aside'] );
				}
			}
		}
		return $failed;
	}

	private static function exists( $path ) {
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Pastas de plugins que são outra cópia do próprio VitPress (o backup foi feito num site em que a pasta tinha outro
	 * nome, como "VITPress"): reconhecidas pelo arquivo principal. Restaurá-las junto com a pasta em uso carregaria o
	 * plugin duas vezes e derrubaria o site com erro fatal.
	 *
	 * @return string[] Nomes das pastas.
	 */
	public static function detect_plugin_copies( $plugins_dir, $main_file = 'hub.php', $signature = 'function activate_hub_plugin(' ) {
		$found = array();
		$items = is_dir( $plugins_dir ) ? scandir( $plugins_dir ) : false;
		foreach ( (array) $items as $item ) {
			$main = rtrim( $plugins_dir, '/\\' ) . '/' . $item . '/' . $main_file;
			if ( '.' === $item || '..' === $item || is_link( $main ) || ! is_file( $main ) ) {
				continue;
			}
			$head = (string) @file_get_contents( $main, false, null, 0, 32768 );
			if ( false !== strpos( $head, $signature ) ) {
				$found[] = $item;
			}
		}
		return $found;
	}

	private function is_protected( $rel ) {
		$parts = explode( '/', $rel );
		if ( 0 === strpos( end( $parts ), '.vitpress-old-' ) ) {
			return true;
		}
		if ( 1 === count( $parts ) ) {
			return in_array( $parts[0], $this->protected_roots, true );
		}
		return 2 === count( $parts ) && 'plugins' === $parts[0] && in_array( $parts[1], $this->protected_plugins, true );
	}

	public static function delete_path( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			return @unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		foreach ( scandir( $path ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				self::delete_path( $path . '/' . $item );
			}
		}
		return @rmdir( $path );
	}
}
