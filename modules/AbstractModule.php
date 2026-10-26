<?php
namespace Hub\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe Abstrata Base para os Módulos do VitPress.
 */
abstract class AbstractModule {

	/**
	 * Identificador único do módulo.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Título do módulo para exibição em menus/títulos.
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Slug utilizado na URL e registros de menu do WP Admin.
	 *
	 * @return string
	 */
	public function get_menu_slug() {
		return 'hub-' . $this->get_id();
	}

	/**
	 * Ordem no menu administrativo.
	 *
	 * @return int
	 */
	public function get_order() {
		return 10;
	}

	/**
	 * Método executado para registrar hooks, ações e rotas do módulo.
	 */
	abstract public function init();

	/**
	 * Método para renderizar o conteúdo da página do módulo no WP Admin.
	 */
	abstract public function render();
}
