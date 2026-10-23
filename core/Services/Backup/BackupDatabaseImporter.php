<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importa o database.sql em tabelas temporárias (hubrs…) e troca as tabelas reais em um único RENAME TABLE.
 * As tabelas anteriores ficam como hubold… até a confirmação, para que a troca possa ser revertida.
 *
 * $db precisa expor: query(string): bool, last_error(): string, tables(): string[], value(string): ?string.
 */
class BackupDatabaseImporter {

	const MAX_TABLE_NAME    = 64;
	const LOCK_WAIT_SECONDS = 30;

	private $db;
	private $token;
	private $rename_error = '';

	public function __construct( $db, $token = null ) {
		$this->db    = $db;
		$this->token = $token ?? bin2hex( random_bytes( 3 ) );
	}

	public function token() {
		return $this->token;
	}

	/**
	 * Confere o dump inteiro sem executar nada.
	 *
	 * @return array{ok:bool,error:string,placeholder:?string,statements:int}
	 */
	public function inspect( $sql_path, $repair_legacy_placeholders ) {
		$handle = @fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			return array( 'ok' => false, 'error' => 'Falha ao ler o arquivo de banco de dados.', 'placeholder' => null, 'statements' => 0 );
		}
		$count  = 0;
		$tables = 0;
		$counts = array();
		foreach ( BackupSql::statements( $handle ) as $statement ) {
			$count++;
			$classified = BackupSql::classify( $statement );
			if ( isset( $classified['error'] ) ) {
				fclose( $handle );
				return array( 'ok' => false, 'error' => $classified['error'] . " (instrução {$count}). Nada foi alterado.", 'placeholder' => null, 'statements' => $count );
			}
			if ( 'CREATE TABLE' === $classified['type'] ) {
				$tables++;
			} elseif ( $repair_legacy_placeholders && 'INSERT INTO' === $classified['type'] ) {
				BackupSql::count_placeholders( $statement, $counts );
			}
		}
		fclose( $handle );

		if ( 0 === $tables ) {
			return array( 'ok' => false, 'error' => 'O database.sql não contém tabelas.', 'placeholder' => null, 'statements' => $count );
		}
		$placeholder = null;
		if ( $counts ) {
			$placeholder = BackupSql::dominant_placeholder( $counts );
			if ( null === $placeholder ) {
				return array( 'ok' => false, 'error' => 'O backup antigo contém marcadores de escape ambíguos e não pode ser corrigido com segurança. Nada foi alterado.', 'placeholder' => null, 'statements' => $count );
			}
		}
		return array( 'ok' => true, 'error' => '', 'placeholder' => $placeholder, 'statements' => $count );
	}

	/**
	 * Executa o dump em tabelas temporárias. As tabelas reais não são tocadas.
	 *
	 * @return array{ok:bool,error:string,staged:array<string,string>,statements:int}
	 */
	public function stage( $sql_path, $old_prefix, $new_prefix, $repair_legacy_placeholders ) {
		$inspection = $this->inspect( $sql_path, $repair_legacy_placeholders );
		if ( ! $inspection['ok'] ) {
			return array( 'ok' => false, 'error' => $inspection['error'], 'staged' => array(), 'statements' => 0 );
		}
		$placeholder = $inspection['placeholder'];

		$handle = @fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			return $this->fail( 'Falha ao ler o arquivo de banco de dados.', array() );
		}

		$staged = array();
		$count  = 0;
		$this->db->query( 'SET foreign_key_checks = 0' );

		foreach ( BackupSql::statements( $handle ) as $statement ) {
			$count++;
			$classified = BackupSql::classify( $statement );
			if ( isset( $classified['error'] ) ) {
				fclose( $handle );
				return $this->fail( $classified['error'] . " (instrução {$count}). O banco atual não foi alterado.", $staged );
			}
			$target = BackupSql::target_table( $classified['table'], $old_prefix, $new_prefix );
			if ( ! isset( $staged[ $target ] ) ) {
				$staged[ $target ] = $this->staging_name( 'hubrs', $target );
			}
			$sql = BackupSql::rewrite_header( $statement, $classified, $staged[ $target ] );
			if ( null !== $placeholder && 'INSERT INTO' === $classified['type'] ) {
				$sql = BackupSql::repair_placeholder( $sql, $placeholder );
			}
			if ( false === $this->db->query( $sql ) ) {
				fclose( $handle );
				return $this->fail(
					'Erro ao importar a tabela ' . $target . " (instrução {$count}): " . self::safe_error( $this->db->last_error() ) . '. O banco atual não foi alterado.',
					$staged
				);
			}
		}
		fclose( $handle );
		$this->db->query( 'SET foreign_key_checks = 1' );

		return array( 'ok' => true, 'error' => '', 'staged' => $staged, 'statements' => $count );
	}

	/**
	 * Plano da troca, para ser gravado antes de executar e permitir reversão depois de uma interrupção.
	 *
	 * @param array<string,string> $staged tabela final => tabela temporária
	 * @return array{staged:array<string,string>,retired:array<string,string>,created:string[]}
	 */
	public function plan( array $staged ) {
		$existing = array_flip( $this->db->tables() );
		$retired  = array();
		$created  = array();
		foreach ( $staged as $target => $stage ) {
			if ( isset( $existing[ $target ] ) ) {
				$retired[ $target ] = $this->staging_name( 'hubold', $target );
			} else {
				$created[] = $target;
			}
		}
		return array( 'staged' => $staged, 'retired' => $retired, 'created' => $created );
	}

	/**
	 * Troca as tabelas em um único RENAME TABLE, com limite de espera por locks. As antigas ficam como hubold….
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function swap( array $plan ) {
		if ( empty( $plan['staged'] ) ) {
			return array( 'ok' => false, 'error' => 'Nenhuma tabela preparada para ativação.' );
		}
		$existing = array_flip( $this->db->tables() );
		foreach ( $plan['staged'] as $target => $stage ) {
			if ( ! isset( $existing[ $stage ] ) ) {
				return array( 'ok' => false, 'error' => 'As tabelas preparadas não foram encontradas. O banco atual não foi alterado.' );
			}
			$was_retired = isset( $plan['retired'][ $target ] );
			if ( $was_retired !== isset( $existing[ $target ] ) || ( $was_retired && isset( $existing[ $plan['retired'][ $target ] ] ) ) ) {
				return array( 'ok' => false, 'error' => 'As tabelas do site mudaram desde a preparação. O banco atual não foi alterado.' );
			}
		}

		$renames = array();
		foreach ( $plan['staged'] as $target => $stage ) {
			if ( isset( $plan['retired'][ $target ] ) ) {
				$renames[] = "`{$target}` TO `{$plan['retired'][ $target ]}`";
			}
			$renames[] = "`{$stage}` TO `{$target}`";
		}

		$ok = $this->rename_with_timeout( $renames );
		return $ok
			? array( 'ok' => true, 'error' => '' )
			: array( 'ok' => false, 'error' => 'Falha ao ativar as tabelas restauradas: ' . self::safe_error( $this->rename_error ) . '. O banco atual não foi alterado.' );
	}

	/**
	 * Situação da troca conforme as tabelas existentes: 'staged' (não trocou), 'swapped' (trocou) ou 'mixed'.
	 */
	public function swap_status( array $plan ) {
		$existing = array_flip( $this->db->tables() );
		$staged   = 0;
		$swapped  = 0;
		foreach ( $plan['staged'] as $target => $stage ) {
			$old = $plan['retired'][ $target ] ?? null;
			if ( isset( $existing[ $stage ] ) && ( null === $old || ! isset( $existing[ $old ] ) ) && ( null !== $old ? isset( $existing[ $target ] ) : ! isset( $existing[ $target ] ) ) ) {
				$staged++;
			} elseif ( ! isset( $existing[ $stage ] ) && isset( $existing[ $target ] ) && ( null === $old || isset( $existing[ $old ] ) ) ) {
				$swapped++;
			}
		}
		$total = count( $plan['staged'] );
		if ( $staged === $total ) {
			return 'staged';
		}
		if ( $swapped === $total ) {
			return 'swapped';
		}
		return 'mixed';
	}

	/**
	 * Desfaz a troca: tabelas restauradas voltam para hubrs… e as hubold… voltam aos nomes reais.
	 * Idempotente: se a troca não aconteceu, não faz nada.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function unswap( array $plan ) {
		$status = $this->swap_status( $plan );
		if ( 'staged' === $status ) {
			return array( 'ok' => true, 'error' => '' );
		}
		if ( 'mixed' === $status ) {
			return array( 'ok' => false, 'error' => 'As tabelas estão em um estado inesperado; nenhuma tabela foi removida.' );
		}
		$renames = array();
		foreach ( $plan['staged'] as $target => $stage ) {
			$renames[] = "`{$target}` TO `{$stage}`";
			if ( isset( $plan['retired'][ $target ] ) ) {
				$renames[] = "`{$plan['retired'][ $target ]}` TO `{$target}`";
			}
		}
		if ( ! $this->rename_with_timeout( $renames ) ) {
			return array( 'ok' => false, 'error' => 'Falha ao reverter as tabelas: ' . self::safe_error( $this->rename_error ) . '. Nenhuma tabela foi removida.' );
		}
		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Após a confirmação: remove as tabelas antigas.
	 *
	 * @return string[] Tabelas que não puderam ser removidas.
	 */
	public function drop_retired( array $plan ) {
		return $this->drop( array_values( $plan['retired'] ?? array() ) );
	}

	/**
	 * Remove tabelas temporárias (hubrs…) que não pertencem a uma operação ativa.
	 * Tabelas hubold… nunca são removidas aqui: podem conter a única cópia do banco anterior.
	 */
	public function discard_orphans() {
		$orphans = preg_grep( '/^hubrs[0-9a-f]{6}_/', $this->db->tables() );
		$this->drop( array_values( $orphans ) );
		return count( $orphans );
	}

	/**
	 * @param array<string,string> $staged
	 * @return string[] Tabelas que não puderam ser removidas.
	 */
	public function discard( array $staged ) {
		return $this->drop( array_values( $staged ) );
	}

	/**
	 * Remove todas as tabelas temporárias deste token, inclusive as de uma preparação interrompida
	 * antes de o estado ser gravado.
	 *
	 * @return string[] Tabelas que não puderam ser removidas.
	 */
	public function discard_token() {
		return $this->drop( array_values( preg_grep( '/^hubrs' . preg_quote( $this->token, '/' ) . '_/', $this->db->tables() ) ) );
	}

	public function staging_name( $kind, $target ) {
		$name = $kind . $this->token . '_' . $target;
		return strlen( $name ) <= self::MAX_TABLE_NAME ? $name : $kind . $this->token . '_' . md5( $target );
	}

	/**
	 * Mensagens do MySQL podem citar trechos dos dados ("near '...'"); só a parte técnica é mantida.
	 */
	public static function safe_error( $error ) {
		$error = (string) $error;
		$cut   = stripos( $error, " near '" );
		if ( false !== $cut ) {
			$error = substr( $error, 0, $cut );
		}
		$error = preg_replace( "/'[^']*'/", "'…'", $error );
		return '' === trim( $error ) ? 'erro desconhecido' : substr( $error, 0, 160 );
	}

	private function rename_with_timeout( array $renames ) {
		$previous = $this->db->value( 'SELECT @@SESSION.lock_wait_timeout' );
		$this->db->query( 'SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS );
		$this->db->query( 'SET foreign_key_checks = 0' );
		$ok                 = false !== $this->db->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
		$this->rename_error = $ok ? '' : $this->db->last_error();
		$this->db->query( 'SET foreign_key_checks = 1' );
		if ( null !== $previous && ctype_digit( (string) $previous ) ) {
			$this->db->query( 'SET SESSION lock_wait_timeout = ' . (int) $previous );
		}
		return $ok;
	}

	private function drop( array $tables ) {
		$failed = array();
		$this->db->query( 'SET foreign_key_checks = 0' );
		foreach ( $tables as $table ) {
			if ( false === $this->db->query( "DROP TABLE IF EXISTS `{$table}`" ) ) {
				$failed[] = $table;
			}
		}
		$this->db->query( 'SET foreign_key_checks = 1' );
		return $failed;
	}

	private function fail( $message, array $staged ) {
		$this->discard( $staged );
		return array( 'ok' => false, 'error' => $message, 'staged' => array(), 'statements' => 0 );
	}
}
