<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escrita e leitura do database.sql dos backups.
 * Não usa esc_sql(): ele troca "%" por um marcador válido só na requisição atual.
 *
 * A leitura aceita somente as formas exatas que o gerador escreve:
 *   DROP TABLE IF EXISTS `t`;
 *   CREATE TABLE `t` ( ... ) opções;      (saída de SHOW CREATE TABLE, sem chaves estrangeiras)
 *   INSERT INTO `t` VALUES ('v',NULL,...);   (uma linha, só literais entre aspas ou NULL)
 */
class BackupSql {

	const ESCAPING_MARKER = 'literal';

	/** Marcador de esc_sql() gravado por backups gerados antes de sql_escaping=literal. */
	const LEGACY_PLACEHOLDER_PATTERN = '/\{[0-9a-f]{64}\}/';

	const TABLE_NAME = '[A-Za-z0-9_$]{1,64}';

	const CREATE_BODY_LINE = '/^  (`|PRIMARY KEY |UNIQUE KEY |KEY |FULLTEXT KEY |SPATIAL KEY |CONSTRAINT `[^`]+` CHECK )/';

	const CREATE_TAIL = '/^\)(?:\s+(?:DEFAULT\s+)?[A-Z_]+=(?:\'(?:[^\'\\\\]|\\\\.|\'\')*\'|[A-Za-z0-9_]+))*\s*;$/';

	public static function quote( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		$escaped = strtr(
			(string) $value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
		return "'" . $escaped . "'";
	}

	public static function insert_statement( $table, array $row ) {
		return 'INSERT INTO `' . $table . '` VALUES (' . implode( ',', array_map( array( __CLASS__, 'quote' ), $row ) ) . ');';
	}

	/**
	 * Lê o dump e devolve as instruções completas, uma por vez.
	 *
	 * @param resource $handle
	 * @return \Generator<string>
	 */
	public static function statements( $handle ) {
		$query = '';
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
				continue;
			}
			if ( '' !== $query && preg_match( '/^(DROP |CREATE |INSERT )/', $line ) ) {
				yield rtrim( $query );
				$query = '';
			}
			$query .= $line;
			if ( ';' === substr( $trimmed, -1 ) ) {
				yield rtrim( $query );
				$query = '';
			}
		}
		if ( '' !== trim( $query ) ) {
			yield rtrim( $query );
		}
	}

	/**
	 * Confere a instrução contra as formas aceitas.
	 *
	 * @return array{type:string,table:string}|array{error:string}
	 */
	public static function classify( $statement ) {
		$name = self::TABLE_NAME;

		if ( preg_match( '/^DROP TABLE IF EXISTS `(' . $name . ')`;$/', $statement, $m ) ) {
			return array( 'type' => 'DROP TABLE IF EXISTS', 'table' => $m[1] );
		}

		if ( 0 === strpos( $statement, 'INSERT INTO `' ) ) {
			if ( false === strpos( $statement, "\n" ) && preg_match( '/^INSERT INTO `(' . $name . ')` VALUES /', $statement, $m ) && self::is_value_list( $statement, strlen( $m[0] ) ) ) {
				return array( 'type' => 'INSERT INTO', 'table' => $m[1] );
			}
			return array( 'error' => 'INSERT fora do formato gerado pelo backup.' );
		}

		if ( preg_match( '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=|DEFINER\s*=|SQL\s+SECURITY|VIEW\s)/i', $statement ) ) {
			return array( 'error' => 'O backup contém views, que esta versão do VitPress não restaura.' );
		}

		if ( preg_match( '/^CREATE TABLE `(' . $name . ')` \(\n/', $statement, $m ) ) {
			if ( preg_match( '/\bFOREIGN\s+KEY\b|\bREFERENCES\s+`/i', $statement ) ) {
				return array( 'error' => 'O backup contém tabelas com chaves estrangeiras (ex.: ' . $m[1] . '), que esta versão do VitPress não restaura.' );
			}
			$lines = explode( "\n", $statement );
			$tail  = array_pop( $lines );
			array_shift( $lines );
			if ( ! $lines || ! preg_match( self::CREATE_TAIL, $tail ) ) {
				return array( 'error' => 'CREATE TABLE fora do formato gerado pelo backup (' . $m[1] . ').' );
			}
			foreach ( $lines as $line ) {
				if ( ! preg_match( self::CREATE_BODY_LINE, $line ) ) {
					return array( 'error' => 'CREATE TABLE fora do formato gerado pelo backup (' . $m[1] . ').' );
				}
			}
			return array( 'type' => 'CREATE TABLE', 'table' => $m[1] );
		}

		return array( 'error' => 'Instrução não suportada no database.sql.' );
	}

	/**
	 * Varre "(v,v,...);" a partir de $pos, aceitando só literais entre aspas e NULL. Linear, sem regex.
	 */
	public static function is_value_list( $s, $pos ) {
		$len = strlen( $s );
		if ( $pos >= $len || '(' !== $s[ $pos ] ) {
			return false;
		}
		$pos++;
		while ( true ) {
			if ( 0 === substr_compare( $s, 'NULL', $pos, 4 ) ) {
				$pos += 4;
			} elseif ( $pos < $len && "'" === $s[ $pos ] ) {
				$pos++;
				while ( true ) {
					$pos += strcspn( $s, "'\\", $pos );
					if ( $pos >= $len ) {
						return false;
					}
					if ( '\\' === $s[ $pos ] ) {
						$pos += 2;
						continue;
					}
					$pos++;
					break;
				}
			} else {
				return false;
			}
			if ( $pos >= $len ) {
				return false;
			}
			if ( ',' === $s[ $pos ] ) {
				$pos++;
				continue;
			}
			return ')' === $s[ $pos ] && $pos + 2 === $len && ';' === $s[ $pos + 1 ];
		}
	}

	/**
	 * Nome da tabela no destino: troca só o prefixo antigo pelo atual.
	 */
	public static function target_table( $table, $old_prefix, $new_prefix ) {
		if ( '' !== $old_prefix && $old_prefix !== $new_prefix && 0 === strpos( $table, $old_prefix ) ) {
			return $new_prefix . substr( $table, strlen( $old_prefix ) );
		}
		return $table;
	}

	/**
	 * Reescreve somente o nome da tabela no cabeçalho de uma instrução já classificada.
	 */
	public static function rewrite_header( $statement, array $classified, $new_table ) {
		$header = $classified['type'] . ' `' . $classified['table'] . '`';
		return $classified['type'] . ' `' . $new_table . '`' . substr( $statement, strlen( $header ) );
	}

	/**
	 * Conta os marcadores {64 hex} em uma instrução INSERT.
	 *
	 * @param array<string,int> $counts
	 */
	public static function count_placeholders( $statement, array &$counts ) {
		if ( preg_match_all( self::LEGACY_PLACEHOLDER_PATTERN, $statement, $m ) ) {
			foreach ( $m[0] as $token ) {
				$counts[ $token ] = ( $counts[ $token ] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * Token dominante: o mais frequente, estritamente acima do segundo. Empate significa ambíguo (null).
	 *
	 * @param array<string,int> $counts
	 */
	public static function dominant_placeholder( array $counts ) {
		if ( ! $counts ) {
			return null;
		}
		arsort( $counts );
		$values = array_values( $counts );
		if ( isset( $values[1] ) && $values[1] >= $values[0] ) {
			return null;
		}
		return array_key_first( $counts );
	}

	public static function repair_placeholder( $statement, $token ) {
		return str_replace( $token, '%', $statement );
	}
}
