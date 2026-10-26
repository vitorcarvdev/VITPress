<?php
namespace Hub\Core\Services\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portal do cliente no VitAds: destino do acesso humano ao pipeline antigo.
 * O pipeline deixou de ser um app do WordPress; os leads continuam sendo registrados aqui e sincronizados com o VitAds.
 */
class VitAdsPortal {

	const DEFAULT_LOGIN_URL = 'https://vitads.vitagencia.com.br/portal/login';

	/**
	 * O site está ligado ao VitAds (mesma condição da sincronização de contatos e etapas)?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$config = VitAdsEventService::config();
		return ! empty( $config['enabled'] ) && '' !== $config['token'] && '' !== $config['endpoint'];
	}

	/**
	 * URL do login do portal. Instalações com endpoint próprio (homologação) usam o mesmo host dele.
	 *
	 * @return string
	 */
	public static function login_url() {
		$url    = self::DEFAULT_LOGIN_URL;
		$config = VitAdsEventService::config();
		if ( VitAdsEventService::DEFAULT_ENDPOINT !== $config['endpoint'] ) {
			$parts = wp_parse_url( $config['endpoint'] );
			if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) && in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
				$url = $parts['scheme'] . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . '/portal/login';
			}
		}
		return esc_url_raw( (string) apply_filters( 'hub_vitads_portal_login_url', $url ) );
	}

	/**
	 * O acesso humano ao pipeline antigo (/pipeline/, item de menu e link de e-mail) deve ir para o portal?
	 * Só quando o site está ligado ao VitAds; sem a ligação o portal não teria os leads. Filtrável para desligar em emergência.
	 * Nunca é consultado por REST, sincronização ou integração de leads.
	 *
	 * @return bool
	 */
	public static function legacy_redirect_enabled() {
		return (bool) apply_filters( 'hub_pipeline_legacy_redirect', self::is_connected() );
	}
}
