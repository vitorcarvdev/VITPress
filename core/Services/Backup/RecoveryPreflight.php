<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verificações compartilhadas entre o painel e restore-recovery.php: onde está o wp-config.php, se ele pode ser
 * carregado sem inicializar o WordPress, se há cache de objetos persistente e a resposta assinada da sondagem.
 *
 * A sondagem prova que quem respondeu é este ponto de entrada, lendo a pasta de trabalho deste site:
 * o painel grava o hash de um desafio e uma chave (validade curta, uso único) e exige um HMAC da resposta.
 */
class RecoveryPreflight {

	const PROBE_FILE       = 'recovery-probe.json';
	const PROBE_TTL        = 120;
	const SIGNATURE_HEADER = 'X-VitPress-Recovery-Probe';
	const WP_LOADED_TEXT   = 'O WordPress foi inicializado pelo wp-config.php';
	const CONFIG_FAILED_TEXT = 'O wp-config.php não pôde ser carregado sem o WordPress';

	const CACHE_CONSTANTS = array(
		'WP_REDIS_HOST',
		'WP_REDIS_SERVERS',
		'WP_REDIS_CLUSTER',
		'WP_REDIS_SENTINEL',
		'WP_REDIS_CONFIG',
		'WP_REDIS_PATH',
		'WP_REDIS_SCHEME',
		'WP_REDIS_PORT',
		'WP_REDIS_DATABASE',
		'MEMCACHED_HOST',
		'WP_MEMCACHED_SERVERS',
	);

	/**
	 * wp-config.php que restore-recovery.php carrega: o primeiro encontrado subindo a partir da pasta de plugins.
	 */
	public static function locate_config( $plugin_dir ) {
		$dir = dirname( rtrim( str_replace( '\\', '/', $plugin_dir ), '/' ) );
		for ( $i = 0; $i < 6; $i++, $dir = dirname( $dir ) ) {
			if ( is_file( $dir . '/wp-config.php' ) ) {
				return $dir . '/wp-config.php';
			}
		}
		return null;
	}

	/**
	 * wp-config.php que o próprio WordPress usa (mesma regra de wp-load.php).
	 */
	public static function wordpress_config( $abspath ) {
		$abspath = rtrim( str_replace( '\\', '/', $abspath ), '/' );
		if ( is_file( $abspath . '/wp-config.php' ) ) {
			return $abspath . '/wp-config.php';
		}
		$parent = dirname( $abspath );
		if ( is_file( $parent . '/wp-config.php' ) && ! is_file( $parent . '/wp-settings.php' ) ) {
			return $parent . '/wp-config.php';
		}
		return null;
	}

	/**
	 * Inclusões no wp-config.php que impedem carregá-lo sem o WordPress. Só a forma padrão
	 * "require_once ABSPATH . 'wp-settings.php'" é aceita; o ponto de entrada aponta ABSPATH para um arquivo vazio.
	 *
	 * @return string[] Problemas encontrados (vazio quando compatível).
	 */
	public static function analyze_config( $code ) {
		$problems = array();
		$tokens   = @token_get_all( (string) $code );
		$count    = count( $tokens );
		$includes = array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_EVAL === $token[0] ) {
				$problems[] = 'uso de eval(), que não pode ser verificado';
				continue;
			}
			if ( ! is_array( $token ) || ! in_array( $token[0], $includes, true ) ) {
				continue;
			}
			$expr  = '';
			$depth = 0;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$part = $tokens[ $j ];
				if ( is_array( $part ) ) {
					if ( T_CLOSE_TAG === $part[0] ) {
						break;
					}
					if ( ! in_array( $part[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
						$expr .= $part[1];
					}
					continue;
				}
				if ( '(' === $part ) {
					$depth++;
				} elseif ( ')' === $part ) {
					if ( 0 === $depth ) {
						break;
					}
					$depth--;
				} elseif ( ( ';' === $part || ',' === $part ) && 0 === $depth ) {
					break;
				}
				$expr .= $part;
			}
			$norm = strtolower( str_replace( '"', "'", $expr ) );
			while ( preg_match( '/^\((.*)\)$/s', $norm, $m ) ) {
				$norm = $m[1];
			}
			$shown = substr( $expr, 0, 80 );
			if ( preg_match( '/wp-(settings|load|blog-header)\.php/', $norm ) ) {
				if ( "abspath.'wp-settings.php'" !== $norm ) {
					$problems[] = 'carrega o WordPress por um caminho fixo (' . $shown . ')';
				}
			} elseif ( false !== strpos( $norm, 'abspath' ) ) {
				$problems[] = 'inclui um arquivo relativo a ABSPATH (' . $shown . ')';
			}
		}
		return array_values( array_unique( $problems ) );
	}

	/**
	 * Cache de objetos persistente. O WordPress só usa Redis/Memcached pelo drop-in object-cache.php;
	 * constantes sem o drop-in não ativam o cache, mas são informadas.
	 *
	 * @return array{active:bool,hints:string[]}
	 */
	public static function object_cache( $content_dir ) {
		$hints = array();
		foreach ( self::CACHE_CONSTANTS as $constant ) {
			if ( defined( $constant ) ) {
				$hints[] = $constant;
			}
		}
		foreach ( array( 'memcached_servers', 'redis_server' ) as $global ) {
			if ( ! empty( $GLOBALS[ $global ] ) ) {
				$hints[] = '$' . $global;
			}
		}
		return array(
			'active' => is_file( rtrim( str_replace( '\\', '/', $content_dir ), '/' ) . '/object-cache.php' ),
			'hints'  => $hints,
		);
	}

	/**
	 * Lado do painel: grava o desafio. Retorna o desafio e a chave para conferir a resposta.
	 *
	 * @return array{challenge:string,key:string}|null
	 */
	public static function create_probe( $workspace ) {
		$challenge = bin2hex( random_bytes( 32 ) );
		$key       = bin2hex( random_bytes( 32 ) );
		$data      = json_encode( array( 'hash' => hash( 'sha256', $challenge ), 'key' => $key, 'expires' => time() + self::PROBE_TTL ) );
		if ( ! BackupWorkspace::atomic_write( rtrim( $workspace, '/\\' ) . '/' . self::PROBE_FILE, $data ) ) {
			return null;
		}
		return array( 'challenge' => $challenge, 'key' => $key );
	}

	public static function discard_probe( $workspace ) {
		@unlink( rtrim( $workspace, '/\\' ) . '/' . self::PROBE_FILE );
	}

	/**
	 * Lado do ponto de entrada: só responde a um desafio gravado pelo painel e ainda válido (e o consome).
	 * Os fatos (inclusive a conexão ao banco) só são levantados depois de validar o desafio.
	 *
	 * @param callable $facts Retorna os fatos relatados ao painel.
	 * @return array{status:int,body:string,signature:string}
	 */
	public static function respond_probe( $workspace, $challenge, callable $facts ) {
		$denied = array( 'status' => 404, 'body' => '', 'signature' => '' );
		if ( ! $workspace || ! is_string( $challenge ) || ! preg_match( '/^[a-f0-9]{64}$/', $challenge ) ) {
			return $denied;
		}
		$file = rtrim( $workspace, '/\\' ) . '/' . self::PROBE_FILE;
		$data = json_decode( (string) @file_get_contents( $file ), true );
		if ( ! is_array( $data ) || empty( $data['hash'] ) || empty( $data['key'] ) || (int) ( $data['expires'] ?? 0 ) <= time() ) {
			return $denied;
		}
		if ( ! hash_equals( (string) $data['hash'], hash( 'sha256', $challenge ) ) ) {
			return $denied;
		}
		@unlink( $file );
		$body = json_encode( array( 'facts' => (array) call_user_func( $facts ) ) );
		return array( 'status' => 200, 'body' => $body, 'signature' => self::sign( $data['key'], $challenge, $body ) );
	}

	/**
	 * Lado do painel: confere a assinatura e devolve os fatos relatados, ou null.
	 */
	public static function verify_probe( $key, $challenge, $body, $signature ) {
		if ( ! is_string( $signature ) || '' === $signature || ! hash_equals( self::sign( $key, $challenge, (string) $body ), $signature ) ) {
			return null;
		}
		$data = json_decode( (string) $body, true );
		return is_array( $data ) && is_array( $data['facts'] ?? null ) ? $data['facts'] : null;
	}

	private static function sign( $key, $challenge, $body ) {
		return hash_hmac( 'sha256', 'vitpress-recovery-probe|' . $challenge . '|' . $body, (string) $key );
	}

	/**
	 * Sinais de que o WordPress foi carregado neste processo.
	 */
	public static function wordpress_loaded() {
		return defined( 'WPINC' ) || function_exists( 'add_action' ) || function_exists( 'wp' );
	}

	public static function same_path( $a, $b ) {
		$norm = function ( $p ) {
			$p = rtrim( str_replace( '\\', '/', (string) ( realpath( (string) $p ) ?: $p ) ), '/' );
			return DIRECTORY_SEPARATOR === '\\' ? strtolower( $p ) : $p;
		};
		return '' !== (string) $a && $norm( $a ) === $norm( $b );
	}
}
