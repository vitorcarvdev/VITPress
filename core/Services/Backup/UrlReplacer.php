<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Troca da URL do site de origem pela do destino dentro de textos do banco.
 *
 * Cobre todas as formas em que a URL aparece gravada: simples (http e https), com barras escapadas de JSON
 * (http:\/\/...), codificada (http%3A%2F%2F...) e sem protocolo (//host/...). Dados serializados do PHP são lidos
 * sem instanciar objetos, e o tamanho de cada texto (s:N) é recalculado, inclusive quando há serializado dentro de
 * serializado. Sem dependência do WordPress: pode ser testada isoladamente.
 */
class UrlReplacer {

	/** Depois da URL não pode continuar um nome de host/caminho (evita trocar /blog dentro de /blog2 ou :80 em :8080). */
	const BOUNDARY = '(?![A-Za-z0-9_-]|\.[A-Za-z0-9]|:\d)';

	/** @var string Origem sem protocolo (host + porta + caminho). */
	private $old_base;
	/** @var string */
	private $new_base;
	/** @var string URL completa do destino. */
	private $new_url;
	/** @var string Host (e porta) da origem, para descartar rápido textos sem a URL. */
	private $host = '';
	/** @var array<string,string> padrão em minúsculas => substituição */
	private $map = array();
	/** @var string */
	private $regex = '';

	/**
	 * @param string $old_url URL do site de origem (ex.: http://localhost/projetos/site).
	 * @param string $new_url URL do site de destino (ex.: https://cliente.com.br).
	 */
	public function __construct( $old_url, $new_url ) {
		$this->old_base = self::base( $old_url );
		$this->new_url  = rtrim( (string) $new_url, '/' );
		$this->new_base = self::base( $this->new_url );
		$this->build();
	}

	private static function base( $url ) {
		return rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', trim( (string) $url ) ), '/' );
	}

	private static function esc( $text ) {
		return str_replace( '/', '\\/', $text );
	}

	private function build() {
		$old        = $this->old_base;
		$new        = $this->new_url;
		$host       = strstr( $old, '/', true );
		$this->host = false === $host ? $old : $host;
		if ( '' === $old || '' === $this->new_base ) {
			return;
		}
		$pairs = array();
		foreach ( array( 'http', 'https' ) as $scheme ) {
			$pairs[ "{$scheme}://{$old}" ]                              = $new;
			$pairs[ self::esc( "{$scheme}://{$old}" ) ]                 = self::esc( $new );
			$pairs[ rawurlencode( "{$scheme}://{$old}" ) ]              = rawurlencode( $new );
		}
		$relative = array(
			"//{$old}"                  => "//{$this->new_base}",
			self::esc( "//{$old}" )     => self::esc( "//{$this->new_base}" ),
		);

		foreach ( $pairs as $search => $replace ) {
			$this->map[ strtolower( $search ) ] = $replace;
		}
		foreach ( $relative as $search => $replace ) {
			$this->map[ strtolower( $search ) ] = $replace;
		}

		$full = array_keys( $pairs );
		$rel  = array_keys( $relative );
		usort( $full, static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		$quote       = static function ( $s ) {
			return preg_quote( $s, '~' );
		};
		$this->regex = '~(?:(?:' . implode( '|', array_map( $quote, $full ) ) . ')|(?<![:\w])(?:' . implode( '|', array_map( $quote, $rel ) ) . '))' . self::BOUNDARY . '~i';
	}

	/**
	 * Pedaços do endereço de origem para filtrar linhas no banco (LIKE): só host e porta, sem o caminho, porque o
	 * caminho aparece com barras escapadas em JSON e não bateria.
	 *
	 * @return string[]
	 */
	public function needles() {
		if ( '' === $this->host ) {
			return array();
		}
		return array_values( array_unique( array( $this->host, rawurlencode( $this->host ) ) ) );
	}

	public function is_active() {
		return '' !== $this->regex;
	}

	/**
	 * Texto comum (HTML, JSON, URL simples).
	 */
	public function replace_string( $text ) {
		if ( ! $this->is_active() || ( false === stripos( $text, $this->host ) && false === stripos( $text, rawurlencode( $this->host ) ) ) ) {
			return $text;
		}
		$map = $this->map;
		return preg_replace_callback(
			$this->regex,
			static function ( $m ) use ( $map ) {
				return $map[ strtolower( $m[0] ) ];
			},
			$text
		);
	}

	/**
	 * Valor de coluna: texto comum ou dado serializado (inclusive aninhado).
	 */
	public function replace_value( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( self::looks_serialized( $value ) ) {
			$pos = 0;
			try {
				$out = $this->walk( $value, $pos );
				if ( $pos === strlen( $value ) ) {
					return $out;
				}
			} catch ( \UnexpectedValueException $e ) {
				// Serializado corrompido na origem: segue como texto comum (o tamanho já estava errado).
			}
		}
		return $this->replace_string( $value );
	}

	public static function looks_serialized( $value ) {
		return 1 === preg_match( '/^(?:N;|[bid]:[^;]+;|s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:")/s', $value );
	}

	/**
	 * Lê um valor serializado a partir de $pos devolvendo o texto já com as trocas; avança $pos.
	 */
	private function walk( $s, &$pos ) {
		$type = $s[ $pos ] ?? '';
		switch ( $type ) {
			case 'N':
				$pos += 2;
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
				$end = strpos( $s, ';', $pos );
				if ( false === $end ) {
					throw new \UnexpectedValueException( 'escalar sem fim' );
				}
				$piece = substr( $s, $pos, $end - $pos + 1 );
				$pos   = $end + 1;
				return $piece;
			case 'r':
			case 'R':
				if ( ! preg_match( '/\G[rR]:\d+;/', $s, $m, 0, $pos ) ) {
					throw new \UnexpectedValueException( 'referência inválida' );
				}
				$pos += strlen( $m[0] );
				return $m[0];
			case 's':
				return $this->walk_string( $s, $pos, true );
			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
					throw new \UnexpectedValueException( 'array inválido' );
				}
				$pos += strlen( $m[0] );
				$out  = $m[0];
				for ( $i = 0, $n = (int) $m[1]; $i < $n; $i++ ) {
					$out .= $this->walk( $s, $pos ) . $this->walk( $s, $pos );
				}
				return $out . $this->expect( $s, $pos, '}' );
			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $s, $m, 0, $pos ) ) {
					throw new \UnexpectedValueException( 'objeto inválido' );
				}
				$class_len = (int) $m[1];
				$head_len  = strlen( $m[0] );
				$class     = substr( $s, $pos + $head_len, $class_len );
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $pos + $head_len + $class_len ) ) {
					throw new \UnexpectedValueException( 'objeto inválido' );
				}
				$out  = substr( $s, $pos, $head_len ) . $class . $m2[0];
				$pos += $head_len + $class_len + strlen( $m2[0] );
				for ( $i = 0, $n = (int) $m2[1]; $i < $n; $i++ ) {
					$out .= $this->walk_string_key( $s, $pos ) . $this->walk( $s, $pos );
				}
				return $out . $this->expect( $s, $pos, '}' );
			case 'C':
			case 'E':
				// Dados próprios da classe / enum: copiados sem alteração.
				if ( ! preg_match( '/\G' . $type . ':(\d+):"/', $s, $m, 0, $pos ) ) {
					throw new \UnexpectedValueException( 'tipo especial inválido' );
				}
				$class_len = (int) $m[1];
				$after     = $pos + strlen( $m[0] ) + $class_len;
				if ( 'E' === $type ) {
					$end   = $after + 2;
					$piece = substr( $s, $pos, $end - $pos );
					$pos   = $end;
					return $piece;
				}
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $after ) ) {
					throw new \UnexpectedValueException( 'objeto C inválido' );
				}
				$end   = $after + strlen( $m2[0] ) + (int) $m2[1] + 1;
				$piece = substr( $s, $pos, $end - $pos );
				$pos   = $end;
				return $piece;
		}
		throw new \UnexpectedValueException( 'tipo desconhecido' );
	}

	/**
	 * Chave de propriedade de objeto: copiada como está (nomes de propriedades protegidas/privadas têm bytes nulos).
	 */
	private function walk_string_key( $s, &$pos ) {
		return $this->walk_string( $s, $pos, false );
	}

	private function walk_string( $s, &$pos, $replace ) {
		if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $pos ) ) {
			// Chaves de objeto também podem ser inteiras (i:N;).
			return $this->walk( $s, $pos );
		}
		$len   = (int) $m[1];
		$start = $pos + strlen( $m[0] );
		if ( $start + $len + 2 > strlen( $s ) || '";' !== substr( $s, $start + $len, 2 ) ) {
			throw new \UnexpectedValueException( 'tamanho de texto inválido' );
		}
		$text = substr( $s, $start, $len );
		$pos  = $start + $len + 2;
		if ( $replace ) {
			$text = $this->replace_value( $text );
		}
		return 's:' . strlen( $text ) . ':"' . $text . '";';
	}

	private function expect( $s, &$pos, $char ) {
		if ( ( $s[ $pos ] ?? '' ) !== $char ) {
			throw new \UnexpectedValueException( 'fim esperado' );
		}
		$pos++;
		return $char;
	}
}
