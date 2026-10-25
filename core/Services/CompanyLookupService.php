<?php
namespace Hub\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço responsável por buscar dados de empresas (CNPJ) em APIs públicas (ex: BrasilAPI).
 * Evita acoplamento direto com a interface.
 */
class CompanyLookupService {

	public function init() {
		add_action( 'wp_ajax_hub_lookup_company', array( $this, 'ajax_lookup_company' ) );
	}

	public function ajax_lookup_company() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sem permissão.' ) );
		}

		check_ajax_referer( 'hub_lookup_company', 'hub_nonce' );

		$cnpj = isset( $_POST['cnpj'] ) ? preg_replace( '/\D/', '', $_POST['cnpj'] ) : '';

		if ( strlen( $cnpj ) !== 14 ) {
			wp_send_json_error( array( 'message' => 'CNPJ inválido.' ) );
		}

		$data = $this->fetch_company_data( $cnpj );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Busca os dados na BrasilAPI.
	 */
	public function fetch_company_data( $cnpj ) {
		$url = 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj;

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', 'Erro ao conectar à API de CNPJ.' );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			return new \WP_Error( 'not_found', 'CNPJ não encontrado ou erro na Receita Federal.' );
		}

		$json = json_decode( $body, true );

		if ( ! $json ) {
			return new \WP_Error( 'invalid_json', 'Resposta inválida da API.' );
		}

		// Mapeia o retorno para os campos do VitPress
		return array(
			'name'         => isset( $json['razao_social'] ) ? $json['razao_social'] : '',
			'zipcode'      => isset( $json['cep'] ) ? $json['cep'] : '',
			'address'      => isset( $json['logradouro'] ) ? $json['logradouro'] : '',
			'number'       => isset( $json['numero'] ) ? $json['numero'] : '',
			'complement'   => isset( $json['complemento'] ) ? $json['complemento'] : '',
			'neighborhood' => isset( $json['bairro'] ) ? $json['bairro'] : '',
			'city'         => isset( $json['municipio'] ) ? $json['municipio'] : '',
			'state'        => isset( $json['uf'] ) ? $json['uf'] : '',
		);
	}
}
