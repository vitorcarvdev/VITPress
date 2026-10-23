<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Confirmar ou reverter uma restauração sem carregar o WordPress: nenhum plugin, mu-plugin ou drop-in do site
 * é executado, e o .maintenance não se aplica. Usado por restore-recovery.php.
 *
 * Só age com o token de recuperação emitido antes da troca (comparado pelo hash, com validade, uso único).
 * O token chega no corpo do POST; o link traz o token no fragmento (#k=…), que o navegador não envia ao servidor.
 *
 * Com cache de objetos persistente ativo (object-cache.php), confirmar e reverter ficam bloqueados: daqui não há
 * como limpar esse cache de forma comprovada, e agir com ele ativo misturaria dados do banco anterior e do restaurado.
 */
class RecoveryEndpoint {

	const FLUSH_MARKER = '/hub-backups/.flush-cache';

	private $workspace;
	private $content_dir;
	private $db_factory;
	private $object_cache;

	/**
	 * @param string|null $workspace    Pasta de trabalho registrada no ponteiro.
	 * @param string      $content_dir  wp-content deste site.
	 * @param callable    $db_factory   Retorna o adaptador do banco (ver BackupDatabaseImporter) ou null.
	 * @param array       $object_cache RecoveryPreflight::object_cache().
	 */
	public function __construct( $workspace, $content_dir, callable $db_factory, array $object_cache = array() ) {
		$this->workspace    = $workspace;
		$this->content_dir  = rtrim( str_replace( '\\', '/', (string) $content_dir ), '/' );
		$this->db_factory   = $db_factory;
		$this->object_cache = $object_cache + array( 'active' => false, 'hints' => array() );
	}

	/**
	 * @param array{method:string,op:string,token:string,action:string} $request
	 * @return array{status:int,body:string,form:bool} body em HTML já escapado.
	 */
	public function handle( array $request ) {
		$id = (string) ( $request['op'] ?? '' );
		$op = ( $this->workspace && preg_match( '/^[a-f0-9]{16}$/', $id ) ) ? RestoreOperation::active( $this->workspace ) : null;
		if ( ! $op || ! hash_equals( $op->id(), $id ) ) {
			return $this->page( 404, '<p>Nenhuma restauração aguardando ação neste site.</p>' );
		}
		$env = (array) ( $op->state()['env'] ?? array() );
		if ( empty( $env['content_dir'] ) || ! self::same_path( $env['content_dir'], $this->content_dir ) ) {
			return $this->page( 409, '<p>Esta restauração não pertence a este site.</p>' );
		}
		if ( 'POST' !== ( $request['method'] ?? '' ) ) {
			return $this->token_form( $op->id() );
		}

		$token  = (string) ( $request['token'] ?? '' );
		$runner = $this->runner( $op, null, $env );
		if ( ! $runner->verify_recovery_token( $token ) ) {
			usleep( 400000 );
			return $this->page( 403, '<p>Link de recuperação inválido, expirado ou já utilizado.</p>' );
		}

		$action  = (string) ( $request['action'] ?? '' );
		$message = '';
		if ( in_array( $action, array( 'confirm', 'revert' ), true ) ) {
			if ( $this->object_cache['active'] ) {
				return $this->status_page( $op, $runner, $token, 'Nada foi alterado: desative o cache de objetos persistente antes de continuar.', 409 );
			}
			$db = call_user_func( $this->db_factory );
			if ( ! $db ) {
				return $this->page( 503, '<p>Não foi possível conectar ao banco de dados. Nada foi alterado; tente novamente em instantes.</p>' );
			}
			$persistent = ! empty( $op->state()['object_cache'] );
			$result     = $this->runner( $op, $db, $env )->run( $action );
			self::write_flush_marker( $this->content_dir, $op->id(), $persistent );
			if ( $result['ok'] && ! empty( $result['closed'] ) ) {
				return $this->page( 200, '<p>Restauração confirmada. As cópias antigas foram removidas e este link deixou de valer.</p>' );
			}
			if ( $result['ok'] && 'revert' === $action ) {
				return $this->page( 200, '<p>Restauração revertida: o site voltou ao estado anterior e este link deixou de valer. O backup continua preparado no painel.</p>' );
			}
			$message = $result['error'];
		}
		return $this->status_page( $op, $runner, $token, $message );
	}

	private function runner( RestoreOperation $op, $db, array $env ) {
		$persistent = ! empty( $op->state()['object_cache'] );
		$id         = $op->id();
		return new RestoreRunner(
			$op,
			$db,
			array_merge(
				$env,
				array(
					'flush_cache' => function () use ( $id, $persistent ) {
						self::write_flush_marker( $this->content_dir, $id, $persistent );
					},
				)
			)
		);
	}

	/**
	 * O cache não é carregado aqui: o marcador pede ao VitPress uma limpeza conferida na próxima requisição do
	 * WordPress e só é apagado depois dela. "persistent" indica que o site usava cache persistente na troca:
	 * então só uma limpeza feita com esse cache de volta (object-cache.php ativo) remove o marcador.
	 */
	public static function write_flush_marker( $content_dir, $op_id, $persistent ) {
		$file     = rtrim( str_replace( '\\', '/', (string) $content_dir ), '/' ) . self::FLUSH_MARKER;
		$previous = json_decode( (string) @file_get_contents( $file ), true );
		$marker   = array(
			'op'         => (string) $op_id,
			'at'         => time(),
			'persistent' => $persistent || ( is_array( $previous ) && ! empty( $previous['persistent'] ) ),
		);
		@file_put_contents( $file, json_encode( $marker ) );
	}

	private function token_form( $id ) {
		$html = '<p>Confirme ou reverta a restauração deste site. O código de recuperação vem no link recebido ao preparar a troca.</p>'
			. '<form method="post" id="hub-recovery-form">'
			. '<input type="hidden" name="op" value="' . self::e( $id ) . '">'
			. '<input type="hidden" name="restore_action" value="status">'
			. '<p><label>Código de recuperação<br><input type="password" name="token" id="hub-recovery-token" autocomplete="off" size="70" pattern="[a-f0-9]{64}" required></label></p>'
			. '<p><button type="submit">Continuar</button></p></form>';
		return array( 'status' => 200, 'body' => $html, 'form' => true );
	}

	private function status_page( RestoreOperation $op, RestoreRunner $runner, $token, $message, $status = 200 ) {
		$state       = $op->state();
		$interrupted = $op->interrupted_step();
		$allowed     = $this->object_cache['active'] ? array() : array_values( array_intersect( $runner->allowed_actions(), array( 'confirm', 'revert' ) ) );

		if ( 'switch' === $interrupted ) {
			$html = '<p>A troca foi interrompida antes de terminar. Arquivos e banco podem estar misturados; reverta para voltar ao site anterior.</p>';
		} elseif ( null !== $interrupted ) {
			$html = '<p>A etapa "' . self::e( $interrupted ) . '" foi interrompida antes de terminar. Use a ação abaixo para concluir a recuperação.</p>';
		} elseif ( 'switched' === ( $state['phase'] ?? '' ) ) {
			$html = '<p>Restauração aplicada. Confira o site e escolha uma opção.</p>';
		} elseif ( 'revert_failed' === ( $state['phase'] ?? '' ) ) {
			$html = '<p>A reversão anterior falhou. Nada foi apagado; tente reverter novamente.</p>';
		} else {
			$html = '<p>A restauração ainda não foi aplicada. Continue pelo painel do site.</p>';
		}
		if ( '' !== $message ) {
			$html .= '<p><strong>' . self::e( $message ) . '</strong></p>';
		}
		if ( $this->object_cache['active'] ) {
			$html .= '<div><p><strong>Cache de objetos persistente ativo (wp-content/object-cache.php).</strong> Confirmar e reverter estão bloqueados aqui: este ponto de entrada não consegue limpar esse cache de forma comprovada, e continuar com ele ativo misturaria dados do site anterior e do restaurado.</p>'
				. '<ol><li>Desative o cache: renomeie <code>wp-content/object-cache.php</code> (por exemplo, para <code>object-cache.php.desativado</code>) pelo gerenciador de arquivos ou FTP da hospedagem.</li>'
				. '<li>Recarregue este link e escolha a ação.</li>'
				. '<li>Antes de reativar o cache, limpe-o pela hospedagem (ou <code>wp cache flush</code>). Ao reativá-lo, o VitPress limpa e confere o cache na primeira requisição e mostra um aviso no painel até conseguir.</li></ol></div>';
		} elseif ( $this->object_cache['hints'] ) {
			$html .= '<p>Há configuração de Redis/Memcached (' . self::e( implode( ', ', $this->object_cache['hints'] ) ) . '), mas sem <code>object-cache.php</code> o WordPress não usa esse cache. Se ele voltar a ser ativado, limpe-o antes.</p>';
		}
		foreach ( $allowed as $choice ) {
			$label = 'confirm' === $choice ? 'Confirmar e apagar as cópias antigas' : 'Reverter para o site anterior';
			$html .= '<form method="post"><input type="hidden" name="op" value="' . self::e( $op->id() ) . '">'
				. '<input type="hidden" name="token" value="' . self::e( $token ) . '">'
				. '<input type="hidden" name="restore_action" value="' . self::e( $choice ) . '">'
				. '<p><button type="submit">' . self::e( $label ) . '</button></p></form>';
		}
		if ( ! $allowed && ! empty( $state['running'] ) && null === $interrupted ) {
			$html .= '<p>Uma etapa está em execução. Aguarde e recarregue esta página.</p>';
		}
		return $this->page( $status, $html );
	}

	private function page( $status, $html ) {
		return array( 'status' => $status, 'body' => $html, 'form' => false );
	}

	public static function e( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	private static function same_path( $a, $b ) {
		$norm = function ( $p ) {
			$p = rtrim( str_replace( '\\', '/', (string) ( realpath( $p ) ?: $p ) ), '/' );
			return DIRECTORY_SEPARATOR === '\\' ? strtolower( $p ) : $p;
		};
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * Conexão a partir das constantes do wp-config.php, no mesmo formato de DB_HOST que o wpdb aceita
	 * (host, host:porta, host:/socket, [IPv6]:porta).
	 *
	 * @return object|null Adaptador com query(), last_error(), tables() e value().
	 */
	public static function connect( $host, $user, $password, $name, $charset ) {
		if ( ! class_exists( 'mysqli' ) ) {
			return null;
		}
		$port   = null;
		$socket = null;
		$pos    = strpos( (string) $host, ':/' );
		if ( false !== $pos ) {
			$socket = substr( $host, $pos + 1 );
			$host   = substr( $host, 0, $pos );
		}
		$pattern = substr_count( (string) $host, ':' ) > 1 ? '#^(?:\[)?(?P<host>[0-9a-fA-F:]+)(?:\]:(?P<port>[\d]+))?#' : '#^(?P<host>[^:/]*)(?::(?P<port>[\d]+))?#';
		if ( preg_match( $pattern, (string) $host, $m ) ) {
			$host = '' !== $m['host'] ? $m['host'] : 'localhost';
			$port = ! empty( $m['port'] ) ? (int) $m['port'] : null;
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		$mysqli = mysqli_init();
		$flags  = defined( 'MYSQL_CLIENT_FLAGS' ) ? (int) MYSQL_CLIENT_FLAGS : 0;
		if ( ! $mysqli || ! @$mysqli->real_connect( $host, $user, $password, $name, $port, $socket, $flags ) ) {
			return null;
		}
		$mysqli->set_charset( $charset ? $charset : 'utf8mb4' );

		return new class( $mysqli ) {
			private $m;
			public function __construct( \mysqli $m ) {
				$this->m = $m;
			}
			public function query( $sql ) {
				return false !== $this->m->query( $sql );
			}
			public function last_error() {
				return $this->m->error;
			}
			public function tables() {
				$r = $this->m->query( 'SHOW TABLES' );
				return $r ? array_column( $r->fetch_all(), 0 ) : array();
			}
			public function value( $sql ) {
				$r   = $this->m->query( $sql );
				$row = $r ? $r->fetch_row() : null;
				return $row ? (string) $row[0] : null;
			}
		};
	}
}
