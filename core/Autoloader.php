<?php
namespace Hub\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader PSR-4 para o Plugin VitPress.
 */
class Autoloader {

	/**
	 * Registra o autoloader na pilha SPL.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Carrega o arquivo da classe correspondente.
	 *
	 * @param string $class Nome qualificado da classe.
	 */
	public static function autoload( $class ) {
		$prefix   = 'Hub\\';
		$base_dir = HUB_PATH;

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );

		// Mapeamento de diretórios conhecidos (Linux Case-Sensitivity Fix)
		$parts = explode( '\\', $relative_class );
		
		if ( empty( $parts ) ) {
			return;
		}

		// A primeira parte (ex: Core, Admin, Modules) deve ser sempre minúscula no sistema de arquivos
		$first_dir = strtolower( $parts[0] );
		$parts[0]  = $first_dir;
		
		$relative_path = implode( '/', $parts );
		$file_path = '';

		if ( in_array( $first_dir, array( 'core', 'profiles', 'modules', 'admin' ), true ) ) {
			$file_path = $base_dir . $relative_path . '.php';
		} else {
			$file_path = $base_dir . 'core/' . $relative_path . '.php';
		}

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}
