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
		// Chave única: liga/desliga VitAds, Marketing e Pipeline e Pipeline do VitZap juntos.
		$settings['marketing_pipeline_enabled'] = ! empty( $_POST['vitads_enabled'] );
		// Os módulos comerciais continuam exigindo licença ativa (em localhost ela é automática).
		$license_status          = ( new LocalLicenseProvider() )->getStatus();
		$settings['crm_enabled'] = ( ! empty( $_POST['vitads_enabled'] ) && ! empty( $license_status['is_valid'] ) ) ? 1 : 0;
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
				wp_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=emails&error=' . $msg ) );
				exit;
			}
			
			$client->deleteObject($key);
			wp_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=emails&message=s3_success' ) );
			exit;
		}

		wp_redirect( admin_url( 'admin.php?page=hub-vitagencia&tab=emails&message=s3_saved' ) );
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

	/**
	 * Texto (em blocos do editor) da página criada por "Modelo VitAgência" e usada em "Usar Modelo VitAgência".
	 */
	private function privacy_policy_template() {
		$site_name   = esc_html( get_bloginfo( 'name' ) );
		// Contato: primeiro e-mail válido do campo "E-mail de Notificação" do Atendente; sem ele, o e-mail do administrador do WordPress.
		$collector   = \Hub\Core\Services\LeadCollectorService::get_settings();
		$admin_email = '';
		foreach ( explode( ',', isset( $collector['notification_emails'] ) ? (string) $collector['notification_emails'] : '' ) as $candidate ) {
			$candidate = sanitize_email( trim( $candidate ) );
			if ( '' !== $candidate && is_email( $candidate ) ) {
				$admin_email = $candidate;
				break;
			}
		}
		if ( '' === $admin_email ) {
			$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		$contact     = '' !== $admin_email
			? '<a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>'
			: 'os canais de contato disponibilizados neste site';
		$date_update = date_i18n( 'd/m/Y' );

		$complement = "\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Quem trata os seus dados</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Os dados são tratados por <strong>{$site_name}</strong>. Para qualquer assunto sobre privacidade, fale conosco em {$contact}.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Seus direitos</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Nos termos da Lei nº 13.709/2018 (Lei Geral de Proteção de Dados Pessoais), você pode solicitar, quando aplicável:</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>confirmação de que tratamos os seus dados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>acesso aos dados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>correção de dados incompletos, inexatos ou desatualizados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>exclusão dos dados tratados com o seu consentimento;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>informações sobre com quem os dados são compartilhados;</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>revogação do consentimento a qualquer momento.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Para exercer esses direitos, entre em contato por {$contact}.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Alterar sua escolha sobre cookies</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Você pode rever a sua escolha a qualquer momento. Ao redefinir, uma nova escolha poderá ser feita.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p><button class=\"hub-reset-consent button\">Redefinir Consentimento de Cookies</button></p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Última atualização: {$date_update}</p>\n<!-- /wp:paragraph -->";

		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Consentimento para Uso e Compartilhamento de Dados</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Ao aceitar esta política, você declara estar ciente de que as informações fornecidas durante sua navegação, interação ou solicitação de contato poderão ser utilizadas para atendimento, comunicação, análise de resultados e melhoria dos nossos serviços.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Você também concorda que essas informações poderão ser compartilhadas com ferramentas e plataformas de marketing, publicidade e análise, com a finalidade de mensurar resultados de campanhas, personalizar anúncios, identificar interesses e apresentar conteúdos e ofertas relevantes.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>O tratamento e o compartilhamento dos dados serão realizados para as finalidades informadas, respeitando a legislação aplicável e as medidas de proteção pertinentes.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Ao clicar em <strong>“Aceito”</strong>, você confirma que leu e compreendeu estas informações e concorda com o uso e o compartilhamento dos seus dados para as finalidades descritas.</p>\n<!-- /wp:paragraph -->" . $complement;
	}

	public function handle_save_vitagencia() {
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

				$policy_content = $this->privacy_policy_template();

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
			        
    				$policy_content = $this->privacy_policy_template();
    			        
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
			// Área de atuação (perfil de uso): substitui a antiga tela de escolha ao ativar o plugin.
			if ( ! empty( $_POST['hub_active_profile'] ) ) {
				\Hub\Core\ProfileManager::get_instance()->set_active_profile( sanitize_key( wp_unslash( $_POST['hub_active_profile'] ) ) );
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
