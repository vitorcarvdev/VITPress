<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pasta de trabalho dos backups (uploads, ZIPs temporários, extração e estado da restauração).
 *
 * Preferência: fora da raiz web, no mesmo disco de wp-content (os renames precisam ser atômicos).
 * Se não for possível, fica em wp-content/hub-backups com nome imprevisível e proteção para
 * Apache 2.2/2.4 e IIS; no Nginx o nome imprevisível é a proteção.
 */
class BackupWorkspace {

	const POINTER_HEADER = "<?php exit; ?>\n";

	private $pointer_file;
	private $fallback_base;
	private $outside_base;
	private $document_root;
	private $content_dir;

	/**
	 * @param string      $content_dir   WP_CONTENT_DIR.
	 * @param string|null $outside_base  Pasta candidata fora da raiz web (ex.: dirname(ABSPATH)); null para não tentar.
	 * @param string|null $document_root DOCUMENT_ROOT da requisição atual; sem ele não há como provar que está fora da raiz web.
	 */
	public function __construct( $content_dir, $outside_base = null, $document_root = null ) {
		$this->content_dir   = rtrim( str_replace( '\\', '/', $content_dir ), '/' );
		$this->fallback_base = $this->content_dir . '/hub-backups';
		$this->pointer_file  = $this->fallback_base . '/workspace.php';
		$this->outside_base  = $outside_base ? rtrim( str_replace( '\\', '/', $outside_base ), '/' ) : null;
		$this->document_root = $document_root ? rtrim( str_replace( '\\', '/', $document_root ), '/' ) : null;
	}

	/**
	 * Caminho da pasta de trabalho (com barra final), criando e protegendo na primeira vez.
	 */
	public function path() {
		$current = $this->read_pointer();
		if ( $current && is_dir( $current ) && is_writable( $current ) ) {
			self::protect( $current );
			return $current . '/';
		}

		$name = 'vitpress-work-' . bin2hex( random_bytes( 16 ) );
		$dir  = null;
		if ( $this->can_use_outside() ) {
			$candidate = $this->outside_base . '/.' . $name;
			if ( @mkdir( $candidate, 0700 ) ) {
				$dir = $candidate;
			}
		}
		if ( null === $dir ) {
			self::ensure_dir( $this->fallback_base );
			$candidate = $this->fallback_base . '/' . $name;
			if ( ! @mkdir( $candidate, 0700 ) && ! is_dir( $candidate ) ) {
				throw new \RuntimeException( 'Não foi possível criar a pasta de trabalho dos backups.' );
			}
			$dir = $candidate;
		}

		self::protect( $dir );
		$this->write_pointer( $dir );
		return $dir . '/';
	}

	/**
	 * Pasta de trabalho já registrada no ponteiro (com barra final), sem criar nada.
	 */
	public function existing() {
		$current = $this->read_pointer();
		return $current && is_dir( $current ) ? $current . '/' : null;
	}

	public function is_outside_web_root( $dir ) {
		if ( null === $this->document_root ) {
			return false;
		}
		$real_dir  = self::normalize( realpath( $dir ) ?: $dir );
		$real_root = self::normalize( realpath( $this->document_root ) ?: $this->document_root );
		return 0 !== strpos( $real_dir . '/', $real_root . '/' );
	}

	private function can_use_outside() {
		if ( null === $this->outside_base || null === $this->document_root || ! is_dir( $this->outside_base ) || ! @is_writable( $this->outside_base ) ) {
			return false;
		}
		if ( ! $this->is_outside_web_root( $this->outside_base . '/x' ) ) {
			return false;
		}
		$a = @stat( $this->outside_base );
		$b = @stat( $this->content_dir );
		return $a && $b && $a['dev'] === $b['dev'];
	}

	private function read_pointer() {
		if ( ! is_file( $this->pointer_file ) ) {
			return null;
		}
		$raw = (string) file_get_contents( $this->pointer_file );
		if ( 0 !== strpos( $raw, self::POINTER_HEADER ) ) {
			return null;
		}
		$data = json_decode( substr( $raw, strlen( self::POINTER_HEADER ) ), true );
		return is_array( $data ) && ! empty( $data['dir'] ) ? rtrim( (string) $data['dir'], '/' ) : null;
	}

	private function write_pointer( $dir ) {
		self::ensure_dir( $this->fallback_base );
		self::atomic_write( $this->pointer_file, self::POINTER_HEADER . json_encode( array( 'dir' => $dir ) ) );
	}

	private static function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}
		self::protect( $dir );
	}

	/**
	 * Bloqueia acesso web: Apache 2.4 (Require), Apache 2.2 (Deny), IIS (web.config) e sem listagem.
	 */
	public static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "# VitPress: acesso negado\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! is_file( $path ) || ( '.htaccess' === $name && (string) @file_get_contents( $path ) !== $content ) ) {
				@file_put_contents( $path, $content );
			}
		}
	}

	public static function atomic_write( $path, $content ) {
		$tmp = $path . '.tmp-' . bin2hex( random_bytes( 4 ) );
		if ( false === @file_put_contents( $tmp, $content ) ) {
			return false;
		}
		if ( @rename( $tmp, $path ) ) {
			return true;
		}
		// Windows não sobrescreve no rename quando o destino está aberto por outro processo.
		@unlink( $path );
		$ok = @rename( $tmp, $path );
		if ( ! $ok ) {
			@unlink( $tmp );
		}
		return $ok;
	}

	private static function normalize( $path ) {
		$path = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
		return DIRECTORY_SEPARATOR === '\\' ? strtolower( $path ) : $path;
	}
}
