<?php
namespace Hub\Core\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validação única dos ZIPs de backup, usada pelo upload em blocos, pelo upload direto e antes da restauração.
 *
 * Formatos aceitos:
 * - vitpress_v1: manifest.json (generator VitPress ou Hub VitAgencia, backup_schema 1) + database.sql + wp-content/.
 * - hub_legacy_content: backup antigo do Hub VitAgência só com o conteúdo de wp-content na raiz,
 *   sem manifesto e sem banco. Permite apenas restauração de arquivos.
 */
class BackupArchiveValidator {

	const FORMAT_VITPRESS         = 'vitpress_v1';
	const FORMAT_LEGACY_CONTENT   = 'hub_legacy_content';
	const SUPPORTED_SCHEMA        = 1;
	const ACCEPTED_GENERATORS     = array( 'VitPress', 'Hub VitAgencia' );
	const LEGACY_CONTENT_MARKERS  = array( 'plugins', 'themes', 'uploads' );
	const LEGACY_FORBIDDEN_ROOTS  = array( 'wp-admin', 'wp-includes', 'wp-content', 'wp-config.php', 'manifest.json', 'database.sql' );
	const FREE_SPACE_MARGIN       = 1.1;

	/**
	 * @param string     $path       Caminho do ZIP.
	 * @param float|null $free_bytes Espaço livre no destino; null não verifica.
	 * @return array{ok:bool,error:string,format:string,manifest:array,uncompressed:int,entries:int}
	 */
	public static function validate( $path, $free_bytes = null ) {
		$result = array(
			'ok'           => false,
			'error'        => '',
			'format'       => '',
			'manifest'     => array(),
			'uncompressed' => 0,
			'entries'      => 0,
		);

		if ( ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = 'A extensão ZipArchive não está ativa neste servidor.';
			return $result;
		}
		if ( ! is_file( $path ) || filesize( $path ) === 0 ) {
			$result['error'] = 'Arquivo de backup ausente ou vazio.';
			return $result;
		}

		$zip    = new \ZipArchive();
		$opened = $zip->open( $path, \ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			$result['error'] = 'Arquivo .zip corrompido, incompleto ou em formato inválido.';
			return $result;
		}

		$names = array();
		$total = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				$zip->close();
				$result['error'] = 'Não foi possível ler o índice do ZIP.';
				return $result;
			}
			$name = (string) $stat['name'];
			if ( ! self::is_safe_entry_name( $name ) ) {
				$zip->close();
				$result['error'] = 'Arquivo ZIP rejeitado por segurança: contém caminho inseguro.';
				return $result;
			}
			if ( self::is_symlink_entry( $zip, $i ) ) {
				$zip->close();
				$result['error'] = 'Arquivo ZIP rejeitado por segurança: contém link simbólico.';
				return $result;
			}
			$names[] = $name;
			$total  += (int) $stat['size'];
		}
		$result['entries']      = count( $names );
		$result['uncompressed'] = $total;

		if ( in_array( 'manifest.json', $names, true ) ) {
			$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
			$zip->close();
			$error = self::check_vitpress( $manifest, $names );
			if ( '' !== $error ) {
				$result['error'] = $error;
				return $result;
			}
			$result['format']   = self::FORMAT_VITPRESS;
			$result['manifest'] = $manifest;
		} else {
			$zip->close();
			$error = self::check_legacy_content( $names );
			if ( '' !== $error ) {
				$result['error'] = $error;
				return $result;
			}
			$result['format'] = self::FORMAT_LEGACY_CONTENT;
		}

		if ( null !== $free_bytes && $total * self::FREE_SPACE_MARGIN > $free_bytes ) {
			$result['format'] = '';
			$result['error']  = 'Espaço em disco insuficiente para extrair o backup.';
			return $result;
		}

		$result['ok'] = true;
		return $result;
	}

	/**
	 * Confere tamanho e CRC32 de cada arquivo extraído com o índice do ZIP.
	 * ZipArchive::extractTo() pode concluir mesmo com dado corrompido.
	 *
	 * @return string Vazio quando tudo confere; senão a mensagem de erro.
	 */
	public static function verify_extracted( $zip_path, $extract_dir ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return 'Não foi possível reabrir o ZIP para conferir a extração.';
		}
		$extract_dir = rtrim( $extract_dir, '/\\' ) . '/';
		$error       = '';
		for ( $i = 0; $i < $zip->numFiles && '' === $error; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = (string) $stat['name'];
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}
			$file = $extract_dir . $name;
			clearstatcache( true, $file );
			if ( ! is_file( $file ) || filesize( $file ) !== (int) $stat['size'] ) {
				$error = 'Extração incompleta: ' . $name;
			} elseif ( hash_file( 'crc32b', $file ) !== sprintf( '%08x', $stat['crc'] & 0xFFFFFFFF ) ) {
				$error = 'Arquivo corrompido dentro do backup: ' . $name;
			}
		}
		$zip->close();
		return $error;
	}

	public static function is_safe_entry_name( $name ) {
		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) ) {
			return false;
		}
		if ( '/' === $name[0] || preg_match( '/^[A-Za-z]:/', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment || '.' === $segment ) {
				return false;
			}
		}
		return true;
	}

	private static function is_symlink_entry( \ZipArchive $zip, $index ) {
		$opsys = 0;
		$attr  = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
			return false;
		}
		return \ZipArchive::OPSYS_UNIX === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 );
	}

	private static function check_vitpress( $manifest, array $names ) {
		if ( ! is_array( $manifest ) || empty( $manifest['generator'] ) || ! in_array( $manifest['generator'], self::ACCEPTED_GENERATORS, true ) ) {
			return 'O manifesto é inválido ou o ZIP não foi gerado pelo VitPress.';
		}
		if ( ! isset( $manifest['backup_schema'] ) || (int) $manifest['backup_schema'] !== self::SUPPORTED_SCHEMA || ! is_numeric( $manifest['backup_schema'] ) ) {
			return 'Esquema de backup desconhecido ou não suportado por esta versão do VitPress.';
		}
		if ( ! in_array( 'database.sql', $names, true ) ) {
			return 'O arquivo database.sql não foi encontrado dentro do backup.';
		}
		foreach ( $names as $name ) {
			if ( 'manifest.json' !== $name && 'database.sql' !== $name && 0 !== strpos( $name, 'wp-content/' ) ) {
				return 'O backup contém arquivos fora da estrutura esperada (manifest.json, database.sql, wp-content/).';
			}
		}
		return '';
	}

	private static function check_legacy_content( array $names ) {
		$roots = array();
		foreach ( $names as $name ) {
			$roots[ explode( '/', $name )[0] ] = true;
		}
		foreach ( self::LEGACY_FORBIDDEN_ROOTS as $forbidden ) {
			if ( isset( $roots[ $forbidden ] ) ) {
				return 'Manifesto não encontrado. O arquivo não é um backup reconhecido do VitPress nem de versões anteriores (Hub VitAgência, nome anterior do VitPress).';
			}
		}
		foreach ( self::LEGACY_CONTENT_MARKERS as $marker ) {
			if ( isset( $roots[ $marker ] ) ) {
				return '';
			}
		}
		return 'Manifesto não encontrado. O arquivo não é um backup reconhecido do VitPress nem de versões anteriores (Hub VitAgência, nome anterior do VitPress).';
	}
}
