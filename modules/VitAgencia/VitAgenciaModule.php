<?php
namespace Hub\Modules\VitAgencia;

use Hub\Modules\AbstractModule;
use Hub\Core\Services\Licensing\LocalLicenseProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Módulo da Área Restrita VitAgência.
 * Engloba configurações de segurança avançadas, licença e credenciais de IA.
 */
class VitAgenciaModule extends AbstractModule {

	public function get_id() {
		return 'vitagencia';
	}

	public function get_title() {
		return 'Área VitAgência';
	}

	public function get_menu_slug() {
		return 'hub-vitagencia';
	}

	public function get_order() {
		return 99; // Fica no final
	}

	public function get_capability() {
		return 'hub_vitagencia_manage_internal';
	}

	public function init() {
		add_action( 'admin_post_hub_authorize_license', array( $this, 'handle_authorize_license' ) );
		add_action( 'admin_post_hub_request_license', array( $this, 'handle_request_license' ) );
		add_action( 'admin_post_hub_revoke_license', array( $this, 'handle_revoke_license' ) );
		add_action( 'admin_post_hub_save_vitagencia', array( $this, 'handle_save_vitagencia' ) );
		add_action( 'admin_post_hub_save_s3_backup', array( $this, 'handle_save_s3_backup' ) );
		add_action( 'admin_post_hub_save_vitzap_integration', array( $this, 'handle_save_vitzap_integration' ) );
		add_action( 'admin_post_hub_save_vitads', array( $this, 'handle_save_vitads' ) );
	}

	public function handle_save_vitads() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( 'Acesso Negado.' );
		}
		check_admin_referer( 'hub_save_vitads', 'hub_nonce' );

		$settings = get_option( 'hub_settings', array() );
		$current  = isset( $settings['vitads'] ) && is_array( $settings['vitads'] ) ? $settings['vitads'] : array();

		$endpoint = isset( $_POST['vitads_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['vitads_endpoint'] ) ) : '';
		if ( '' === $endpoint || 0 !== strpos( $endpoint, 'https://' ) ) {
			$endpoint = \Hub\Core\Services\Integrations\VitAdsEventService::DEFAULT_ENDPOINT;
		}

		$token = isset( $_POST['vitads_token'] ) ? sanitize_text_field( wp_unslash( $_POST['vitads_token'] ) ) : '';
		if ( '' === $token ) {
			$token = isset( $current['token'] ) ? (string) $current['token'] : '';
		}

		$routing_key = isset( $_POST['vitads_routing_key'] )
			? \Hub\Core\Services\Integrations\VitAdsEventService::normalize_routing_key( wp_unslash( $_POST['vitads_routing_key'] ) )
			: '';

		$settings['vitads'] = array(
			'enabled'     => ! empty( $_POST['vitads_enabled'] ),
			'endpoint'    => $endpoint,
			'token'       => $token,
			'routing_key' => $routing_key,
		);
		$settings['marketing_pipeline_enabled'] = ! empty( $_POST['marketing_pipeline_enabled'] );
		update_option( 'hub_settings', $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&message=vitads_saved' ) );
		exit;
	}

	public function handle_save_vitzap_integration() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( 'Acesso Negado.' );
		}
		check_admin_referer( 'hub_save_vitzap_integration', 'hub_nonce' );

		$selected_uuid = isset( $_POST['vitzap_license_uuid'] ) ? sanitize_text_field( $_POST['vitzap_license_uuid'] ) : '';

		if ( empty( $selected_uuid ) ) {
			// Modo isolado: Apenas limpa a opÃ§Ã£o para representar a ausÃªncia de vÃ­nculo (null/empty).
			delete_option( 'vcsis_pipeline_default_tenant_id' );
		} else {
			global $wpdb;
			$table_lic = $wpdb->prefix . 'vitcore_vitzap_licenses';
			
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_lic}'" ) === $table_lic ) {
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM {$table_lic} WHERE uuid = %s LIMIT 1", $selected_uuid ) );
				if ( $exists ) {
					update_option( 'vcsis_pipeline_default_tenant_id', $exists );
				} else {
					wp_die( 'A licenÃ§a selecionada Ã© invÃ¡lida ou nÃ£o existe no banco de dados.' );
				}
			} else {
				wp_die( 'O mÃ³dulo VitZap nÃ£o estÃ¡ instalado neste servidor.' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&message=vitzap_integration_saved' ) );
		exit;
	}

	public function handle_save_s3_backup() {
		if ( ! current_user_can( $this->get_capability() ) ) wp_die();
		check_admin_referer( 'hub_s3_backup_nonce', 'hub_nonce' );

		$settings = get_option( 'hub_settings', [] );
		$s3_post = $_POST['s3'] ?? [];

		$current_secret = $settings['s3']['secret_key'] ?? '';
		$new_secret = sanitize_text_field( $s3_post['secret_key'] ?? '' );
		if ( empty( $new_secret ) ) {
			$new_secret = $current_secret;
		}

		$settings['s3'] = [
			'enabled'        => !empty($s3_post['enabled']),
			'access_key'     => sanitize_text_field( $s3_post['access_key'] ?? '' ),
			'secret_key'     => $new_secret,
			'region'         => sanitize_text_field( $s3_post['region'] ?? 'sa-east-1' ),
			'bucket'         => sanitize_text_field( $s3_post['bucket'] ?? '' ),
			'retention_db'   => absint( $s3_post['retention_db'] ?? 30 ),
			'retention_full' => absint( $s3_post['retention_full'] ?? 4 ),
		];

		update_option( 'hub_settings', $settings );

		if ( isset( $_POST['submit_action'] ) && $_POST['submit_action'] === 'test' ) {
			// Executar teste
			$client = new \Hub\Core\Services\S3Client( $settings['s3']['access_key'], $new_secret, $settings['s3']['region'], $settings['s3']['bucket'] );
			$tmp_file = wp_tempnam('hub_s3_test');
			file_put_contents($tmp_file, "S3 Connection Test Successful.\n");
			$key = 'hub-vitagencia/test-connection.txt';
			
			$res = $client->putObject($key, $tmp_file);
			unlink($tmp_file);

			if ( is_wp_error( $res ) ) {
				$msg = urlencode( 'Erro no teste: ' . $res->get_error_message() );
				wp_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=backup&error=' . $msg ) );
				exit;
			}
			
			$client->deleteObject($key);
			wp_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=backup&message=s3_success' ) );
			exit;
		}

		wp_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=backup&message=s3_saved' ) );
		exit;
	}

	public function render() {
		// Proteção baseada em capability
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( 'Acesso Negado. Área restrita à equipe VitAgência.' );
		}

		$license_provider = new LocalLicenseProvider();
		$license_status   = $license_provider->getStatus();
		$settings         = get_option( 'hub_settings', [] );

		require_once HUB_PATH . 'admin/views/vitagencia.php';
	}

	public function handle_authorize_license() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( 'Acesso Negado.' );
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
		if ( ! $token ) {
			wp_die( 'Token ausente.' );
		}

		$license_provider = new LocalLicenseProvider();
		$result = $license_provider->authorize( $token );

		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message() );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&message=license_authorized' ) );
		exit;
	}

	public function handle_request_license() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( 'Acesso Negado.' );
		}

		check_admin_referer( 'hub_request_license', 'hub_nonce' );

		$license_provider = new LocalLicenseProvider();
		$result = $license_provider->requestAuthorization();

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&error=' . urlencode( $result->get_error_message() ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&message=license_requested' ) );
		exit;
	}

	public function handle_revoke_license() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( 'Acesso Negado.' );
		}

		check_admin_referer( 'hub_revoke_license', 'hub_nonce' );

		$license_provider = new LocalLicenseProvider();
		$license_provider->revokeAuthorization();

		// Força desativar o CRM e módulos vinculados também
		$settings = get_option( 'hub_settings', [] );
		$settings['crm_enabled'] = 0;
		update_option( 'hub_settings', $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&message=license_revoked' ) );
		exit;
	}

	public function handle_save_vitagencia() {
		file_put_contents(WP_CONTENT_DIR . '/hub-debug-save.log', 'POST Data: ' . print_r($_POST, true) . PHP_EOL, FILE_APPEND); file_put_contents(WP_CONTENT_DIR . '/hub-debug-save.log', 'Start handle_save_vitagencia at ' . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( 'Acesso Negado.' );
		}

		check_admin_referer( 'hub_save_vitagencia', 'hub_nonce' );

		$settings = get_option( 'hub_settings', [] );
		$tab = isset( $_POST['vitagencia_tab'] ) ? sanitize_key( $_POST['vitagencia_tab'] ) : 'geral';

		if ( 'geral' === $tab && isset( $_POST['hub_crm_toggle'] ) ) {
			$settings['crm_enabled'] = ! empty( $_POST['hub_crm_enabled'] ) ? 1 : 0;
			unset( $settings['gemini'] );
			update_option( 'hub_settings', $settings );
		} elseif ( 'lgpd' === $tab ) {
			if ( isset( $_POST['create_privacy_policy'] ) ) {
				$site_name = get_bloginfo('name');
				$site_url = site_url();
				$admin_email = get_option('admin_email');
				$date_update = date_i18n('d/m/Y');

				$policy_content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">POLÍTICA DE PRIVACIDADE</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>A sua privacidade é importante para nós. Esta Política de Privacidade explica, de forma geral, como as informações fornecidas ou coletadas durante a utilização deste site podem ser tratadas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">DADOS QUE PODEMOS COLETAR</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Durante a utilização deste site, poderão ser coletadas informações fornecidas voluntariamente pelo usuário, como nome, telefone, WhatsApp, endereço de e-mail e demais dados enviados através de formulários, solicitações de contato ou outros meios disponibilizados no site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Também poderão ser coletadas determinadas informações técnicas relacionadas à navegação, como tipo de navegador, dispositivo utilizado, páginas acessadas, origem do acesso, data e horário da visita e informações relacionadas à interação com o site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Quando autorizado pelo visitante, o site também poderá registrar informações relacionadas à origem de campanhas, incluindo parâmetros UTM, GCLID e outros identificadores utilizados para mensuração de publicidade e desempenho.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">FINALIDADE DA COLETA</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>As informações coletadas poderão ser utilizadas para:</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>responder solicitações de contato;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>prestar os serviços solicitados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>dar continuidade ao atendimento;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>melhorar a experiência de utilização do site;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>analisar acessos e desempenho;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>medir resultados de campanhas e ações de marketing, quando aplicável;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>manter a segurança e o funcionamento adequado do site;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>cumprir obrigações legais ou regulatórias quando necessário.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">COOKIES E TECNOLOGIAS SEMELHANTES</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Este site poderá utilizar cookies e tecnologias semelhantes para permitir seu funcionamento e, quando autorizado, medir a origem dos acessos e o desempenho de campanhas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Ao acessar o site, o visitante poderá escolher entre aceitar ou recusar a utilização de tecnologias não essenciais.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Enquanto nenhuma escolha for realizada, os recursos não essenciais gerenciados pelo Hub permanecerão em estado restrito.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Caso o visitante aceite, poderão ser utilizados recursos de rastreamento e mensuração compatíveis com essa escolha.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Caso recuse, os mecanismos próprios do Hub não deverão persistir identificadores publicitários, GCLID ou parâmetros UTM para fins de mensuração de campanhas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>A preferência escolhida poderá ser armazenada no dispositivo do visitante para evitar que a solicitação de consentimento seja apresentada repetidamente.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">DADOS FORNECIDOS ATRAVÉS DE FORMULÁRIOS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Ao preencher e enviar um formulário disponível neste site, o usuário fornece voluntariamente as informações solicitadas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Esses dados poderão incluir nome, telefone, WhatsApp, e-mail e outras informações necessárias para o atendimento.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Quando houver consentimento e essas informações estiverem disponíveis, o sistema também poderá registrar dados relacionadas à origem do acesso ou campanha responsável pelo contato.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Os dados poderão ser utilizados para responder à solicitação, prestar informações sobre produtos ou serviços, entrar em contato com o usuário e manter o histórico necessário para continuidade do atendimento.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">FERRAMENTAS DE TERCEIROS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Este site poderá utilizar serviços fornecidos por terceiros para funcionalidades como análise de acesso, publicidade, comunicação, formulários, mapas, vídeos incorporados e outros recursos.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Quando utilizadas, essas plataformas poderão processar informações de acordo com suas próprias políticas de privacidade e termos de utilização.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Entre essas ferramentas poderão estar, quando aplicável, serviços oferecidos pelo Google, Meta e outros fornecedores utilizados na operação do site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">COMPARTILHAMENTO DE DADOS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Os dados pessoais não serão comercializados.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Informações poderão ser compartilhadas com prestadores de serviços e plataformas necessárias para a operação do site, execução dos serviços, atendimento ou mensuração de resultados, sempre dentro das finalidades aplicáveis.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Também poderá ocorrer compartilhamento quando necessário para cumprimento de obrigação legal, regulatória ou determinação de autoridade competente.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">ARMAZENAMENTO E SEGURANÇA</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>São adotadas medidas técnicas e administrativas razoáveis para proteger as informações tratadas contra acessos não autorizados, perda, alteração, divulgação ou utilização inadequada.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Os dados serão mantidos pelo período necessário para atender às finalidades para as quais foram coletados e às obrigações legais aplicáveis.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">DIREITOS DO TITULAR</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Nos termos da Lei nº 13.709/2018 — Lei Geral de Proteção de Dados Pessoais (LGPD) — o titular poderá solicitar informações e exercer os direitos aplicáveis ao tratamento de seus dados pessoais, incluindo, quando cabível:</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>confirmação da existência de tratamento;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>acesso aos dados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>correção de informações;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>atualização de dados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>exclusão quando aplicável;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>informações sobre compartilhamento;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>revogação de consentimento quando aplicável.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n<!-- wp:paragraph -->\n<p>As solicitações poderão ser realizadas através dos canais de contato disponibilizados neste site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">ALTERAÇÃO DO CONSENTIMENTO</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>O visitante poderá posteriormente redefinir sua escolha relacionada aos cookies e tecnologias de rastreamento através do recurso disponibilizado pelo site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Ao redefinir o consentimento, uma nova escolha poderá ser realizada.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p><button class=\"hub-reset-consent button\">Redefinir Consentimento de Cookies</button></p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">LINKS EXTERNOS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Este site poderá conter links para páginas e serviços de terceiros.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Não somos responsáveis pelas práticas de privacidade adotadas por sites externos, sendo recomendável que o usuário consulte suas respectivas políticas de privacidade.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">ALTERAÇÕES DESTA POLÍTICA</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Esta Política de Privacidade poderá ser atualizada sempre que necessário para refletir mudanças no funcionamento do site, nos serviços utilizados ou nas normas aplicáveis.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Recomendamos a consulta periódica desta página.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Última atualização: {$date_update}</p>\n<!-- /wp:paragraph -->";

				// Substituir campos dinâmicos, se o usuário mandou no texto. (Por segurança e simplicidade já injetamos os dinâmicos).

				$page_id = wp_insert_post( array(
					'post_title'   => 'Política de Privacidade',
					'post_content' => $policy_content,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => 'politica-de-privacidade',
				) );

				if ( $page_id && ! is_wp_error( $page_id ) ) {
					update_option('wp_page_for_privacy_policy', $page_id);
					if ( ! isset( $settings['privacy'] ) ) {
						$settings['privacy'] = array();
					}
					$settings['privacy']['policy_page'] = $page_id;
					
					if (empty($settings['privacy']['banner_text'])) {
					    $settings['privacy']['banner_text'] = 'Utilizamos cookies para medir o desempenho de nossas campanhas. Saiba mais em nossa Política de Privacidade.';
					}
					
					update_option( 'hub_settings', $settings );
				} else {
                    $error_msg = is_wp_error( $page_id ) ? $page_id->get_error_message() : 'Falha desconhecida ao inserir no banco (ID retornado: ' . intval($page_id) . '). Verifique se o MySQL rejeitou o texto.';
                    wp_die( 'Erro ao criar a página de Política de Privacidade: ' . $error_msg );
                }
				
				wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=lgpd&message=saved' ) );
				exit;
				
			} elseif ( isset($_POST['replace_privacy_policy']) ) {
			    $page_id = get_option('wp_page_for_privacy_policy');
			    if ($page_id) {
			        $date_update = date_i18n('d/m/Y');
			        
    				$policy_content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">POLÍTICA DE PRIVACIDADE</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>A sua privacidade é importante para nós. Esta Política de Privacidade explica, de forma geral, como as informações fornecidas ou coletadas durante a utilização deste site podem ser tratadas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">DADOS QUE PODEMOS COLETAR</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Durante a utilização deste site, poderão ser coletadas informações fornecidas voluntariamente pelo usuário, como nome, telefone, WhatsApp, endereço de e-mail e demais dados enviados através de formulários, solicitações de contato ou outros meios disponibilizados no site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Também poderão ser coletadas determinadas informações técnicas relacionadas à navegação, como tipo de navegador, dispositivo utilizado, páginas acessadas, origem do acesso, data e horário da visita e informações relacionadas à interação com o site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Quando autorizado pelo visitante, o site também poderá registrar informações relacionadas à origem de campanhas, incluindo parâmetros UTM, GCLID e outros identificadores utilizados para mensuração de publicidade e desempenho.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">FINALIDADE DA COLETA</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>As informações coletadas poderão ser utilizadas para:</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>responder solicitações de contato;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>prestar os serviços solicitados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>dar continuidade ao atendimento;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>melhorar a experiência de utilização do site;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>analisar acessos e desempenho;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>medir resultados de campanhas e ações de marketing, quando aplicável;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>manter a segurança e o funcionamento adequado do site;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>cumprir obrigações legais ou regulatórias quando necessário.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">COOKIES E TECNOLOGIAS SEMELHANTES</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Este site poderá utilizar cookies e tecnologias semelhantes para permitir seu funcionamento e, quando autorizado, medir a origem dos acessos e o desempenho de campanhas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Ao acessar o site, o visitante poderá escolher entre aceitar ou recusar a utilização de tecnologias não essenciais.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Enquanto nenhuma escolha for realizada, os recursos não essenciais gerenciados pelo Hub permanecerão em estado restrito.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Caso o visitante aceite, poderão ser utilizados recursos de rastreamento e mensuração compatíveis com essa escolha.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Caso recuse, os mecanismos próprios do Hub não deverão persistir identificadores publicitários, GCLID ou parâmetros UTM para fins de mensuração de campanhas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>A preferência escolhida poderá ser armazenada no dispositivo do visitante para evitar que a solicitação de consentimento seja apresentada repetidamente.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">DADOS FORNECIDOS ATRAVÉS DE FORMULÁRIOS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Ao preencher e enviar um formulário disponível neste site, o usuário fornece voluntariamente as informações solicitadas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Esses dados poderão incluir nome, telefone, WhatsApp, e-mail e outras informações necessárias para o atendimento.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Quando houver consentimento e essas informações estiverem disponíveis, o sistema também poderá registrar dados relacionadas à origem do acesso ou campanha responsável pelo contato.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Os dados poderão ser utilizados para responder à solicitação, prestar informações sobre produtos ou serviços, entrar em contato com o usuário e manter o histórico necessário para continuidade do atendimento.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">FERRAMENTAS DE TERCEIROS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Este site poderá utilizar serviços fornecidos por terceiros para funcionalidades como análise de acesso, publicidade, comunicação, formulários, mapas, vídeos incorporados e outros recursos.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Quando utilizadas, essas plataformas poderão processar informações de acordo com suas próprias políticas de privacidade e termos de utilização.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Entre essas ferramentas poderão estar, quando aplicável, serviços oferecidos pelo Google, Meta e outros fornecedores utilizados na operação do site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">COMPARTILHAMENTO DE DADOS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Os dados pessoais não serão comercializados.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Informações poderão ser compartilhadas com prestadores de serviços e plataformas necessárias para a operação do site, execução dos serviços, atendimento ou mensuração de resultados, sempre dentro das finalidades aplicáveis.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Também poderá ocorrer compartilhamento quando necessário para cumprimento de obrigação legal, regulatória ou determinação de autoridade competente.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">ARMAZENAMENTO E SEGURANÇA</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>São adotadas medidas técnicas e administrativas razoáveis para proteger as informações tratadas contra acessos não autorizados, perda, alteração, divulgação ou utilização inadequada.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Os dados serão mantidos pelo período necessário para atender às finalidades para as quais foram coletados e às obrigações legais aplicáveis.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">DIREITOS DO TITULAR</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Nos termos da Lei nº 13.709/2018 — Lei Geral de Proteção de Dados Pessoais (LGPD) — o titular poderá solicitar informações e exercer os direitos aplicáveis ao tratamento de seus dados pessoais, incluindo, quando cabível:</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>confirmação da existência de tratamento;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>acesso aos dados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>correção de informações;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>atualização de dados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>exclusão quando aplicável;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>informações sobre compartilhamento;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>revogação de consentimento quando aplicável.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n<!-- wp:paragraph -->\n<p>As solicitações poderão ser realizadas através dos canais de contato disponibilizados neste site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">ALTERAÇÃO DO CONSENTIMENTO</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>O visitante poderá posteriormente redefinir sua escolha relacionada aos cookies e tecnologias de rastreamento através do recurso disponibilizado pelo site.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Ao redefinir o consentimento, uma nova escolha poderá ser realizada.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p><button class=\"hub-reset-consent button\">Redefinir Consentimento de Cookies</button></p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">LINKS EXTERNOS</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Este site poderá conter links para páginas e serviços de terceiros.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Não somos responsáveis pelas práticas de privacidade adotadas por sites externos, sendo recomendável que o usuário consulte suas respectivas políticas de privacidade.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">ALTERAÇÕES DESTA POLÍTICA</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Esta Política de Privacidade poderá ser atualizada sempre que necessário para refletir mudanças no funcionamento do site, nos serviços utilizados ou nas normas aplicáveis.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Recomendamos a consulta periódica desta página.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Última atualização: {$date_update}</p>\n<!-- /wp:paragraph -->";
    			        
    			        wp_update_post([
    			            'ID' => $page_id,
    			            'post_content' => $policy_content,
    			            'post_status'  => 'publish'
    			        ]);
    			        
    			        if ( ! isset( $settings['privacy'] ) ) {
    						$settings['privacy'] = array();
    					}
    					$settings['privacy']['policy_page'] = $page_id;
    					update_option( 'hub_settings', $settings );
			    }
			    wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=lgpd&message=saved' ) );
				exit;
			} else {
				$settings['privacy'] = array(
					'banner_enabled' => isset( $_POST['privacy_banner_enabled'] ) ? 1 : 0,
					'banner_text'    => isset( $_POST['privacy_banner_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['privacy_banner_text'] ) ) : 'Utilizamos cookies para medir o desempenho de nossas campanhas. Saiba mais em nossa Política de Privacidade.',
					'policy_page'    => isset( $_POST['privacy_policy_page'] ) ? intval( $_POST['privacy_policy_page'] ) : 0,
				);
				update_option( 'hub_settings', $settings );
				if ( ! empty( $settings['privacy']['policy_page'] ) ) {
				    update_option('wp_page_for_privacy_policy', $settings['privacy']['policy_page']);
				}
			}
		} elseif ( 'seguranca' === $tab ) {
			if ( isset( $_POST['wordpress'] ) ) {
				$settings['wordpress'] = array(
					'disable_comments' => ! empty( $_POST['wordpress']['disable_comments'] ),
					'hide_login'       => ! empty( $_POST['wordpress']['hide_login'] ),
				);
			} else {
				$settings['wordpress'] = array(
					'disable_comments' => false,
					'hide_login'       => false,
				);
			}

			if ( isset( $_POST['plugin_protection_enabled'] ) ) {
				$settings['plugin_protection'] = array(
					'enabled' => true,
					'admins'  => isset( $_POST['plugin_protection_admins'] ) ? array_map( 'intval', $_POST['plugin_protection_admins'] ) : array(),
				);
			} else {
				$settings['plugin_protection'] = array(
					'enabled' => false,
					'admins'  => array(),
				);
			}
			update_option( 'hub_settings', $settings );
		} elseif ( 'trafego' === $tab ) {
			if ( isset( $_POST['forms'] ) ) {
				$settings['forms'] = array(
					'whatsapp'      => preg_replace( '/\D/', '', sanitize_text_field( $_POST['forms']['whatsapp'] ) ),
					'whatsapp_msg'  => sanitize_text_field( $_POST['forms']['whatsapp_msg'] ),
					'receive_email' => sanitize_email( $_POST['forms']['receive_email'] ),
					'send_confirm'  => ! empty( $_POST['forms']['send_confirm'] ),
					'show_testimonial' => ! empty( $_POST['forms']['show_testimonial'] ),
					'testimonial_name' => sanitize_text_field( $_POST['forms']['testimonial_name'] ),
					'testimonial_text' => sanitize_textarea_field( $_POST['forms']['testimonial_text'] ),
				);
			}

			if ( isset( $_POST['integrations']['google_ads_contact_snippet'] ) ) {
				$snippet = wp_unslash( $_POST['integrations']['google_ads_contact_snippet'] );
				if ( preg_match( '/(AW-[0-9]+)\/([a-zA-Z0-9_\-]+)/', $snippet, $matches ) ) {
					if ( ! isset( $settings['integrations'] ) ) {
						$settings['integrations'] = array();
					}
					$settings['integrations']['google_ads_contact'] = array(
						'conversion_id'    => $matches[1],
						'conversion_label' => $matches[2],
					);
				} elseif ( empty( trim( $snippet ) ) ) {
					// Permite apagar a configuração se enviar vazio
					if ( isset( $settings['integrations']['google_ads_contact'] ) ) {
						unset( $settings['integrations']['google_ads_contact'] );
					}
				}
			}
			
			if ( isset( $_POST['meta_ads'] ) ) {
				$settings['meta_ads'] = array(
					'enabled' => isset( $_POST['meta_ads']['enabled'] ) ? 1 : 0,
					'pixel_id' => sanitize_text_field( $_POST['meta_ads']['pixel_id'] ),
					'access_token' => sanitize_text_field( $_POST['meta_ads']['access_token'] ),
					'debug_mode' => isset( $_POST['meta_ads']['debug_mode'] ) ? 1 : 0,
					'test_event_code' => sanitize_text_field( $_POST['meta_ads']['test_event_code'] ),
				);
			}

			update_option( 'hub_settings', $settings );
		} elseif ( 'tracker' === $tab ) {
			if ( isset( $_POST['integrations'] ) ) {
				if ( ! isset( $settings['integrations'] ) ) {
					$settings['integrations'] = array();
				}
				
				$settings['integrations']['google_ads_contact'] = array(
					'conversion_id'    => sanitize_text_field( $_POST['integrations']['google_ads_contact']['conversion_id'] ),
					'conversion_label' => sanitize_text_field( $_POST['integrations']['google_ads_contact']['conversion_label'] ),
				);
				
				$settings['integrations']['meta_pixel'] = array(
					'pixel_id' => sanitize_text_field( $_POST['integrations']['meta_pixel']['pixel_id'] ),
				);
				
				$settings['integrations']['google_analytics'] = array(
					'measurement_id' => sanitize_text_field( $_POST['integrations']['google_analytics']['measurement_id'] ),
				);
				
				update_option( 'hub_settings', $settings );
			}
		} elseif ( 'google-ads-export' === $tab ) {
			if ( isset( $_POST['integrations']['google_ads_offline'] ) ) {
				if ( ! isset( $settings['integrations'] ) ) {
					$settings['integrations'] = array();
				}
				
				$settings['integrations']['google_ads_offline'] = array(
					'conversion_name' => sanitize_text_field( $_POST['integrations']['google_ads_offline']['conversion_name'] ),
					'currency'        => sanitize_text_field( $_POST['integrations']['google_ads_offline']['currency'] ),
				);
				
				update_option( 'hub_settings', $settings );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=' . $tab . '&message=saved' ) );
		exit;
	}
}