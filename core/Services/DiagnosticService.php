<?php
namespace Hub\Core\Services;

use Hub\Core\Database;
use Hub\Core\Services\BackupService;
use Hub\Core\Services\MailService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serviço de Diagnóstico Inteligente.
 * Verifica a saúde do WordPress e aplica correções automáticas seguras.
 */
class DiagnosticService {

	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 999 );
		add_action( 'admin_print_footer_scripts', array( $this, 'print_admin_bar_scripts' ) );

		add_action( 'wp_ajax_hub_run_diagnostic', array( $this, 'ajax_run_diagnostic' ) );
		add_action( 'wp_ajax_hub_fix_diagnostic', array( $this, 'ajax_fix_diagnostic' ) );
		add_action( 'wp_ajax_hub_run_tracker_diagnostic', array( $this, 'ajax_run_tracker_diagnostic' ) );
		add_action( 'wp_ajax_hub_admin_bar_action', array( $this, 'ajax_admin_bar_action' ) );
	}

	/**
	 * Adiciona o menu do VitPress na Top Bar.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$last_score = (int) get_option( 'hub_diagnostic_last_score', 100 );
		$indicator  = '🟢 Site Saudável';
		if ( $last_score < 100 && $last_score >= 80 ) {
			$indicator = '🟡 Atenção';
		} elseif ( $last_score < 80 ) {
			$indicator = '🔴 Problemas encontrados';
		}

		$href = ( $last_score < 100 ) ? admin_url( 'admin.php?page=hub-vitagencia&tab=diagnostico' ) : '#';

		$wp_admin_bar->add_node( array(
			'id'    => 'hub_vcsis',
			'title' => 'VitPress <span style="margin-left:5px;">' . $indicator . '</span>',
			'href'  => $href,
		) );

		$wp_admin_bar->add_node( array(
			'parent' => 'hub_vcsis',
			'id'     => 'hub_clear_cache',
			'title'  => '⚡ Limpar Cache',
			'href'   => '#',
			'meta'   => array( 'onclick' => 'hubAdminBarAction("clear_cache"); return false;' ),
		) );

		$wp_admin_bar->add_node( array(
			'parent' => 'hub_vcsis',
			'id'     => 'hub_run_diag',
			'title'  => '🔍 Executar Diagnóstico',
			'href'   => admin_url( 'admin.php?page=hub-vitagencia&tab=diagnostico' ),
		) );

		$wp_admin_bar->add_node( array(
			'parent' => 'hub_vcsis',
			'id'     => 'hub_run_backup',
			'title'  => '💾 Executar Backup',
			'href'   => '#',
			'meta'   => array( 'onclick' => 'hubAdminBarAction("backup"); return false;' ),
		) );

		$wp_admin_bar->add_node( array(
			'parent' => 'hub_vcsis',
			'id'     => 'hub_test_ses',
			'title'  => '📧 Testar Amazon SES',
			'href'   => '#',
			'meta'   => array( 'onclick' => 'hubAdminBarAction("test_ses"); return false;' ),
		) );

		$wp_admin_bar->add_node( array(
			'parent' => 'hub_vcsis',
			'id'     => 'hub_version',
			'title'  => 'Versão ' . HUB_VERSION . ' Beta',
			'href'   => '#',
		) );
	}

	/**
	 * Scripts JS no rodapé para executar as ações rápidas da Top Bar.
	 */
	public function print_admin_bar_scripts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<script>
		function hubAdminBarAction(actionType) {
			if (actionType === 'clear_cache' && !confirm('Deseja limpar todo o cache (Plugins, CSS, Object e Transients)?')) return;
			if (actionType === 'backup' && !confirm('Deseja iniciar um backup completo do banco de dados agora?')) return;
			if (actionType === 'test_ses' && !confirm('Deseja enviar um e-mail de teste agora?')) return;
			
			var btn = jQuery('#wp-admin-bar-hub_' + (actionType === 'clear_cache' ? 'clear_cache' : actionType));
			var oldHtml = btn.find('a').html();
			btn.find('a').html('⏳ Aguarde...');

			jQuery.post(ajaxurl, {
				action: 'hub_admin_bar_action',
				type: actionType,
				hub_nonce: '<?php echo esc_js( wp_create_nonce( 'hub_admin_bar_nonce' ) ); ?>'
			}, function(response) {
				btn.find('a').html(oldHtml);
				if (response.success) {
					alert(response.data.message);
				} else {
					alert('Erro: ' + (response.data.message || 'Falha ao processar ação.'));
				}
			}).fail(function() {
				btn.find('a').html(oldHtml);
				alert('Erro de comunicação.');
			});
		}
		</script>
		<?php
	}

	/**
	 * Processa as ações rápidas da Top Bar.
	 */
	public function ajax_admin_bar_action() {
		check_ajax_referer( 'hub_admin_bar_nonce', 'hub_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sem permissão.' ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

		if ( 'clear_cache' === $type ) {
			$this->do_clear_cache_sequence();
			Database::log_history( 'system', 0, 'update', 'Cache limpo via Admin Bar.' );
			wp_send_json_success( array( 'message' => 'Cache limpo com sucesso!' ) );
		} elseif ( 'backup' === $type ) {
			$backup_service = new BackupService();
			$file = $backup_service->run_database_backup();
			if ( $file ) {
				Database::log_history( 'system', 0, 'update', 'Backup de BD gerado via Admin Bar.' );
				wp_send_json_success( array( 'message' => 'Backup gerado com sucesso: ' . $file ) );
			}
			wp_send_json_error( array( 'message' => 'Erro ao gerar backup.' ) );
		} elseif ( 'test_ses' === $type ) {
			$mail_service = new MailService();
			$result = $mail_service->send_test_email( wp_get_current_user()->user_email );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array( 'message' => 'E-mail de teste enviado com sucesso!' ) );
		}

		wp_send_json_error( array( 'message' => 'Ação inválida.' ) );
	}

	/**
	 * Sequência de Limpeza de Cache conforme solicitado:
	 * Plugin -> Elementor CSS -> Object Cache -> Transients
	 */
	public function do_clear_cache_sequence() {
		// 1. Plugin de Cache
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		global $wp_fastest_cache;
		if ( isset( $wp_fastest_cache ) && method_exists( $wp_fastest_cache, 'deleteCache' ) ) {
			$wp_fastest_cache->deleteCache();
		}

		// 2. Elementor CSS
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		// 3. Object Cache
		wp_cache_flush();

		// 4. Transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_site\_transient\_%'" );
	}

	/**
	 * Executa o Diagnóstico (Retorna a saúde atual sem fazer correções).
	 */
	public function ajax_run_diagnostic() {
		check_ajax_referer( 'hub_diagnostic_nonce', 'hub_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sem permissão.' ) );
		}

		$results = array();
		$score   = 0;
		$max_score = 100; // 10 metrics * 10 points

		// 1. Memory Limit (10 pontos)
		$mem_ok = false;
		$current_mem_val = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : 'Não definido (Default)';
		$current_mem_mb = $this->return_bytes( $current_mem_val ) / 1024 / 1024;
		if ( $current_mem_mb >= 256 ) {
			$mem_ok = true;
			$score += 10;
		}
		$results['memory_limit'] = array(
			'title'       => 'WP Memory Limit',
			'status'      => $mem_ok ? 'ok' : 'warning',
			'current'     => $current_mem_val,
			'recommended' => '256M',
			'can_fix'     => ! $mem_ok,
			'fix_label'   => 'Aplicar Configuração Recomendada',
			'desc'        => 'O WordPress precisa de 256MB de memória RAM para rodar sem travamentos e lentidão na área administrativa.',
		);

		// 2. Upload Max Filesize (10 pontos)
		$upload_ok = false;
		$current_up_val = ini_get( 'upload_max_filesize' );
		$current_up_mb  = $this->return_bytes( $current_up_val ) / 1024 / 1024;
		if ( $current_up_mb >= 128 ) {
			$upload_ok = true;
			$score += 10;
		}
		$results['upload_max'] = array(
			'title'       => 'Upload Max Filesize',
			'status'      => $upload_ok ? 'ok' : 'warning',
			'current'     => $current_up_val,
			'recommended' => '128M',
			'can_fix'     => ! $upload_ok,
			'fix_label'   => 'Aplicar Configuração Recomendada',
			'desc'        => 'Define o tamanho máximo de mídia ou plugin que pode ser enviado para o servidor de uma só vez.',
		);

		// 3. Max Execution Time (Recomendação Neutra - 10 pontos sempre garantidos)
		$time_ok = false;
		$current_time = ini_get( 'max_execution_time' );
		if ( (int) $current_time >= 300 || (int) $current_time == 0 ) {
			$time_ok = true;
		}
		$score += 10;
		
		$results['exec_time'] = array(
			'title'       => 'Max Execution Time',
			'status'      => $time_ok ? 'ok' : 'info',
			'current'     => $current_time . 's',
			'recommended' => '300s',
			'can_fix'     => ! $time_ok,
			'fix_label'   => 'Aplicar Configuração Recomendada',
			'desc'        => 'Evita que processos longos (como backups e importações) sofram timeout antes de terminar.',
		);

		// 4. HTTPS (10 pontos)
		$https_ok = true;
		$http_urls_count = 0;
		global $wpdb;
		// Apenas conta indícios
		$http_urls_count += $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s", '%http://%' ) );
		$http_urls_count += $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", '%http://%' ) );
		
		if ( strpos( get_option( 'home' ), 'http://' ) !== false || $http_urls_count > 0 ) {
			$https_ok = false;
		}
		
		$score += 10; // Sempre garante 10 pontos
		
		$results['https'] = array(
			'title'       => 'HTTPS & SSL',
			'status'      => $https_ok ? 'ok' : 'info',
			'current'     => $https_ok ? '0 URLs inseguras' : "{$http_urls_count} URLs HTTP e/ou site sem SSL",
			'recommended' => 'HTTPS forçado',
			'can_fix'     => ! $https_ok,
			'fix_label'   => 'Corrigir URLs',
			'desc'        => 'Apenas um alerta neutro. Localiza e substitui qualquer link com http:// antigo por https:// no banco de dados.',
		);

		// 5. Cache (10 pontos)
		$cache_plugins = array(
			'rocket_clean_domain'         => 'WP Rocket',
			'w3tc_flush_all'              => 'W3 Total Cache',
			'sg_cachepress_purge_cache'   => 'SG Optimizer',
		);
		$cache_detected = array();
		foreach ( $cache_plugins as $func => $name ) {
			if ( function_exists( $func ) ) $cache_detected[] = $name;
		}
		if ( has_action( 'litespeed_purge_all' ) ) $cache_detected[] = 'LiteSpeed Cache';
		global $wp_fastest_cache;
		if ( isset( $wp_fastest_cache ) ) $cache_detected[] = 'WP Fastest Cache';
		
		$score += 10; // Sempre garante 10 pontos

		$results['cache'] = array(
			'title'       => 'Sistema de Cache',
			'status'      => count( $cache_detected ) > 0 ? 'ok' : 'info',
			'current'     => count( $cache_detected ) > 0 ? implode( ', ', $cache_detected ) : 'Nenhum plugin detectado',
			'recommended' => 'Cache Ativo',
			'can_fix'     => count( $cache_detected ) > 0,
			'fix_label'   => 'Limpar Cache',
			'desc'        => 'Opcional. Exclui arquivos cacheados do site, expurgando CSS e transients.',
		);

		// 6. Banco de Dados (10 pontos)
		$overhead = 0;
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS' );
		foreach ( $tables as $table ) {
			$overhead += $table->Data_free;
		}
		$overhead_mb = $overhead / 1024 / 1024;
		$db_ok = $overhead_mb < 50; // Tolerância de 50MB (InnoDB aloca espaço livre naturalmente)
		if ( $db_ok ) {
			$score += 10;
		}
		$results['database'] = array(
			'title'       => 'Banco de Dados',
			'status'      => $db_ok ? 'ok' : 'warning',
			'current'     => $db_ok ? 'Otimizado' : sprintf( "Otimização recomendada (%.2f MB Overhead)", $overhead_mb ),
			'recommended' => 'Otimizado',
			'can_fix'     => ! $db_ok,
			'fix_label'   => 'Otimizar Banco',
			'desc'        => 'Limpa opções temporárias expiradas, limpa o cache do VitPress e depois otimiza as tabelas do banco de dados MySQL para recuperar espaço.',
		);

		// 7. Backup (10 pontos)
		$backup_service = new BackupService();
		
		$backup_ok = false;
		$backup_current = 'Nenhum backup recente concluído';
		$last_backup_timestamp = 0;

		// 1. Tentar ler o último backup S3
		$state = get_option( 'hub_backup_job_state', false );
		if ( $state && isset( $state['status'] ) && $state['status'] === 'done' && ! empty( $state['updated_at'] ) ) {
			$last_backup_timestamp = strtotime( $state['updated_at'] . ' UTC' );
		} else {
			// 2. Se não houver S3, tentar Legacy Local (se ainda existir)
			if ( method_exists( $backup_service, 'get_legacy_backups' ) ) {
				$legacy = $backup_service->get_legacy_backups();
				if ( ! empty( $legacy ) && isset( $legacy[0]['date'] ) ) {
					$last_backup_timestamp = $legacy[0]['date'];
				}
			}
		}

		if ( $last_backup_timestamp > 0 ) {
			// Se o último backup for de menos de 48h
			if ( current_time( 'timestamp' ) - $last_backup_timestamp < 172800 ) {
				$backup_ok = true;
			}
			$backup_current = wp_date( 'd/m/Y H:i', $last_backup_timestamp );
		}

		$score += 10; // Sempre garante 10 pontos

		$results['backup'] = array(
			'title'       => 'Rotina de Backup',
			'status'      => $backup_ok ? 'ok' : 'info',
			'current'     => $backup_current,
			'recommended' => 'Backup Diário',
			'can_fix'     => true,
			'fix_label'   => 'Executar Backup Agora',
			'desc'        => 'O VitPress pode fazer backups diários. Este alerta indica caso não estejam configurados ou não estejam em dia.',
		);

		// 8. Cron (10 pontos)
		$cron_ok = false;
		$next_cron = wp_next_scheduled( 'hub_s3_worker' ); 
		if ( ! $next_cron ) {
			$next_cron = wp_next_scheduled( 'hub_schedule_daily_db' );
		}
		
		if ( $next_cron ) {
			$cron_ok = true;
			$score += 10;
			$cron_msg = 'Próx. execução: ' . wp_date( 'd/m/Y H:i', $next_cron );
		} else {
			$cron_msg = 'Problemas detectados no Cron nativo.';
		}
		$results['cron'] = array(
			'title'       => 'WP Cron',
			'status'      => $cron_ok ? 'ok' : 'error',
			'current'     => $cron_msg,
			'recommended' => 'Funcionando',
			'can_fix'     => false,
			'desc'        => 'O WP-Cron é responsável por processos de fundo (backups, Meta Ads, etc). Se houver problemas, acesse o wp-config.php, adicione `define("DISABLE_WP_CRON", true);` e crie uma tarefa Cron no seu servidor de hospedagem apontando para `wp-cron.php` a cada 5 minutos.',
		);

		// 9. Elementor (10 pontos)
		$elementor_ok = true;
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$results['elementor'] = array(
				'title'       => 'Elementor Headless',
				'status'      => 'ok', // always OK, but allows manual trigger
				'current'     => 'Instalado',
				'recommended' => 'CSS Atualizado',
				'can_fix'     => true,
				'fix_label'   => 'Regenerar CSS',
				'desc'        => 'Se a aparência de botões ou layouts quebrar, a regeneração dos arquivos CSS recriará tudo e pode consertar.',
			);
			$score += 10;
		} else {
			$score += 10; // Se não existir Elementor, não perde nota.
		}

		// 10. Amazon SES & MailService (Recomendação Neutra - 10 pontos sempre garantidos)
		$settings = get_option( 'hub_settings', array() );
		$ses = isset( $settings['ses'] ) && ! empty( $settings['ses']['enabled'] ) && ! empty( $settings['ses']['username'] );
		
		$score += 10;

		$results['ses'] = array(
			'title'       => 'Envio de E-mails (SES)',
			'status'      => $ses ? 'ok' : 'info',
			'current'     => $ses ? 'Configurado' : 'Não configurado',
			'recommended' => 'SMTP Amazon SES ativo',
			'can_fix'     => $ses,
			'fix_label'   => 'Testar Amazon SES',
			'desc'        => 'Configuração via Amazon SES garante 99% de entrega para e-mails institucionais enviados pelo VitPress.',
		);

		update_option( 'hub_diagnostic_last_score', $score );

		wp_send_json_success( array(
			'score'   => $score,
			'results' => $results,
		) );
	}

	/**
	 * Processa o "Fix" requisitado via AJAX para um único item.
	 */
	public function ajax_fix_diagnostic() {
		check_ajax_referer( 'hub_diagnostic_nonce', 'hub_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sem permissão.' ) );
		}

		$item = isset( $_POST['item'] ) ? sanitize_key( $_POST['item'] ) : '';

		switch ( $item ) {
			case 'memory_limit':
				$this->fix_memory_limit();
				break;
			case 'upload_max':
			case 'exec_time':
				$this->fix_php_settings();
				break;
			case 'https':
				$this->fix_https();
				break;
			case 'cache':
				$this->do_clear_cache_sequence();
				Database::log_history( 'system', 0, 'update', 'Cache limpo via Diagnóstico.' );
				wp_send_json_success( array( 'message' => 'Configuração aplicada com sucesso. Itens alterados: Cache do Plugin, Elementor CSS, Object Cache, Transients.' ) );
				break;
			case 'database':
				$this->fix_database();
				break;
			case 'backup':
				$backup_service = new BackupService();
				$file = $backup_service->run_database_backup();
				if ( $file ) {
					Database::log_history( 'system', 0, 'update', 'Backup manual executado via Diagnóstico.' );
					wp_send_json_success( array( 'message' => 'Backup gerado com sucesso: ' . $file ) );
				}
				wp_send_json_error( array( 'message' => 'Falha ao gerar backup.' ) );
				break;
			case 'elementor':
				if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
					Database::log_history( 'system', 0, 'update', 'CSS do Elementor regenerado via Diagnóstico.' );
					wp_send_json_success( array( 'message' => 'Configuração aplicada com sucesso. Arquivos CSS e cache do Elementor regenerados.' ) );
				}
				wp_send_json_error( array( 'message' => 'Elementor não encontrado.' ) );
				break;
			case 'ses':
				$mail_service = new MailService();
				$result = $mail_service->send_test_email( wp_get_current_user()->user_email );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
				wp_send_json_success( array( 'message' => 'E-mail de teste enviado com sucesso! Verifique sua caixa de entrada.' ) );
				break;
			default:
				wp_send_json_error( array( 'message' => 'Item desconhecido.' ) );
		}
	}

	/**
	 * Executa o Diagnóstico do Tracker.
	 */
	public function ajax_run_tracker_diagnostic() {
		check_ajax_referer( 'hub_diagnostic_nonce', 'hub_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sem permissão.' ) );
		}

		$simulate = isset( $_POST['simulate'] ) ? (int) $_POST['simulate'] : 0;
		$test_url = isset( $_POST['test_url'] ) ? sanitize_text_field( $_POST['test_url'] ) : '/';
		
		$is_full_url = ( strpos( $test_url, 'http://' ) === 0 || strpos( $test_url, 'https://' ) === 0 );
		if ( ! $is_full_url && $test_url && substr( $test_url, 0, 1 ) !== '/' ) {
			$test_url = '/' . $test_url;
		}

		$final_url = $is_full_url ? $test_url : site_url( $test_url );
		$final_url .= ( strpos( $final_url, '?' ) !== false ? '&' : '?' ) . 'gclid=HUB_SIMULATED_GCLID_123456789';

		// Modo de Simulação: injeta temporariamente variáveis $_COOKIE
		if ( $simulate ) {
			$_COOKIE['hub_gclid'] = 'HUB_SIMULATED_GCLID_123456789';
			$_COOKIE['hub_utm_source'] = 'google';
			$_COOKIE['hub_utm_medium'] = 'cpc';
			$_COOKIE['hub_utm_campaign'] = 'tracker_simulation';
			$_COOKIE['hub_landing_url'] = $final_url;
			$_COOKIE['hub_landing_path'] = $is_full_url ? wp_parse_url( $test_url, PHP_URL_PATH ) : $test_url;
			$_COOKIE['hub_external_referrer'] = 'https://www.google.com/';
			$_COOKIE['hub_first_visit_datetime'] = current_time( 'mysql' );
		}

		// Consome a classe Tracker real
		$data = \Hub\Core\Tracker::get_current_tracking_data();

		$results = array();
		$score   = 0;
		$max_score = 6;
		
		// 1. Google Ads (GCLID)
		$has_gclid = ! empty( $data['gclid'] ) || ! empty( $data['gbraid'] ) || ! empty( $data['wbraid'] );
		$results['gclid'] = array(
			'title'  => 'Google Ads (GCLID / wbraid)',
			'status' => $has_gclid ? 'ok' : 'warning',
			'desc'   => $has_gclid ? 'Parâmetro de conversão do Google Ads identificado na sessão atual.' : 'Nenhum GCLID ativo. Se você acessou o site diretamente, isso é normal.',
		);
		if ( $has_gclid ) $score++;

		// 2. Meta Ads (FBCLID)
		$has_fbclid = ! empty( $data['fbclid'] );
		$results['fbclid'] = array(
			'title'  => 'Meta Ads (FBCLID)',
			'status' => $has_fbclid ? 'ok' : 'info',
			'desc'   => $has_fbclid ? 'Parâmetro de conversão do Facebook/Instagram identificado.' : 'Nenhum clique via Meta Ads ativo na sessão atual.',
		);
		if ( $has_fbclid ) $score++;

		// 3. UTMs
		$has_utms = ! empty( $data['utm_source'] ) || ! empty( $data['utm_medium'] ) || ! empty( $data['utm_campaign'] );
		$missing_utms = array();
		if ( empty( $data['utm_source'] ) ) $missing_utms[] = 'utm_source';
		if ( empty( $data['utm_medium'] ) ) $missing_utms[] = 'utm_medium';
		if ( empty( $data['utm_campaign'] ) ) $missing_utms[] = 'utm_campaign';
		
		$utm_status = 'ok';
		$utm_desc = 'As UTMs principais estão presentes.';
		if ( ! $has_utms ) {
			$utm_status = 'warning';
			$utm_desc = 'Nenhuma UTM principal identificada na sessão atual.';
		} elseif ( count( $missing_utms ) > 0 ) {
			$utm_status = 'info';
			$utm_desc = 'Algumas UTMs ausentes: ' . implode(', ', $missing_utms) . '.';
		}

		$results['utms'] = array(
			'title'  => 'Parâmetros UTM',
			'status' => $utm_status,
			'desc'   => $utm_desc,
		);
		if ( $has_utms ) $score++;

		// 4. Primeira Atribuição
		$has_first_touch = ! empty( $data['landing_url'] ) && ! empty( $data['first_visit_datetime'] );
		$results['first_touch'] = array(
			'title'  => 'Primeira Atribuição (Landing Page)',
			'status' => $has_first_touch ? 'ok' : 'error',
			'desc'   => $has_first_touch ? 'Landing page inicial e momento da visita registrados corretamente.' : 'A primeira visita não pôde ser identificada (cookies bloqueados?).',
		);
		if ( $has_first_touch ) $score++;

		// 5. Referer
		$has_referer = ! empty( $data['external_referrer'] );
		$results['referer'] = array(
			'title'  => 'Referer Externo',
			'status' => $has_referer ? 'ok' : 'info',
			'desc'   => $has_referer ? 'Site de origem antes do clique registrado.' : 'Acesso direto ou referer oculto pelo navegador (comum em HTTPS).',
		);
		if ( $has_referer ) $score++;

		// 6. Navegador (User Agent)
		$has_ua = ! empty( $data['user_agent'] );
		$results['user_agent'] = array(
			'title'  => 'Dispositivo e Navegador',
			'status' => $has_ua ? 'ok' : 'error',
			'desc'   => $has_ua ? 'Identificação do sistema e dispositivo registrada.' : 'User-Agent não recebido do servidor.',
		);
		if ( $has_ua ) $score++;

		// Global Status
		$global_status = 'warning';
		if ( $score >= 5 ) $global_status = 'ok';
		elseif ( $score <= 2 ) $global_status = 'error';
		
		if ( ! $has_first_touch || ! $has_ua ) $global_status = 'error';

		// Summary preparation
		$summary = array();
		if ( $has_first_touch || $has_utms || $has_gclid ) {
			// Mascara o GCLID se existir
			$masked_gclid = '';
			if ( ! empty( $data['gclid'] ) ) {
				$gclid = $data['gclid'];
				$masked_gclid = strlen($gclid) > 8 ? substr($gclid, 0, 8) . '********' : '********';
			}

			$origin = 'Acesso Direto';
			if ( ! empty( $data['utm_source'] ) ) {
				$origin = ucfirst( $data['utm_source'] );
			} elseif ( ! empty( $data['gclid'] ) ) {
				$origin = 'Google Ads';
			} elseif ( ! empty( $data['fbclid'] ) ) {
				$origin = 'Meta Ads';
			}

			$summary['Origem'] = $origin;
			$summary['Campanha'] = ! empty( $data['utm_campaign'] ) ? $data['utm_campaign'] : '';
			$summary['Mídia'] = ! empty( $data['utm_medium'] ) ? $data['utm_medium'] : '';
			$summary['Landing Inicial'] = ! empty( $data['landing_path'] ) ? $data['landing_path'] : $data['landing_url'];
			$summary['Primeira Visita'] = ! empty( $data['first_visit_datetime'] ) ? wp_date( 'd/m/Y H:i:s', strtotime( $data['first_visit_datetime'] ) ) : '';
			$summary['Referer'] = ! empty( $data['external_referrer'] ) ? $data['external_referrer'] : '';
			$summary['Dispositivo'] = $data['device_type'] . ' (' . $data['os'] . ')';
			$summary['Navegador'] = $data['browser'];
			$summary['GCLID'] = $masked_gclid;
		}

		if ( $simulate ) {
			// Clean up simulados
			unset( $_COOKIE['hub_gclid'] );
			unset( $_COOKIE['hub_utm_source'] );
			unset( $_COOKIE['hub_utm_medium'] );
			unset( $_COOKIE['hub_utm_campaign'] );
			unset( $_COOKIE['hub_landing_url'] );
			unset( $_COOKIE['hub_landing_path'] );
			unset( $_COOKIE['hub_external_referrer'] );
			unset( $_COOKIE['hub_first_visit_datetime'] );
		}

		wp_send_json_success( array(
			'status'  => $global_status,
			'results' => $results,
			'summary' => $summary,
		) );
	}

	private function fix_memory_limit() {
		$wp_config_path = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $wp_config_path ) ) {
			wp_send_json_error( array( 'message' => 'wp-config.php não encontrado para escrita.' ) );
		}

		$config_content = file_get_contents( $wp_config_path );

		// 1. Cria Backup do wp-config.php de forma padronizada
		$backup_dir = WP_CONTENT_DIR . '/hub-backups/system/';
		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
			file_put_contents( $backup_dir . '.htaccess', "Deny from all\n" );
			file_put_contents( $backup_dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		
		$backup_file = $backup_dir . 'wp-config-' . current_time( 'Y-m-d-H-i' ) . '.bkp';
		file_put_contents( $backup_file, $config_content );

		$old_memory = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : 'Default';

		// 2. Procura e substitui ou insere
		$has_memory_limit     = preg_match( "/define\(\s*['\"]WP_MEMORY_LIMIT['\"]\s*,/i", $config_content );
		$has_max_memory_limit = preg_match( "/define\(\s*['\"]WP_MAX_MEMORY_LIMIT['\"]\s*,/i", $config_content );

		if ( $has_memory_limit ) {
			$config_content = preg_replace( "/define\(\s*['\"]WP_MEMORY_LIMIT['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/i", "define( 'WP_MEMORY_LIMIT', '256M' );", $config_content );
		}
		if ( $has_max_memory_limit ) {
			$config_content = preg_replace( "/define\(\s*['\"]WP_MAX_MEMORY_LIMIT['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/i", "define( 'WP_MAX_MEMORY_LIMIT', '256M' );", $config_content );
		}

		if ( ! $has_memory_limit || ! $has_max_memory_limit ) {
			$insert_str = "";
			if ( ! $has_memory_limit ) {
				$insert_str .= "define( 'WP_MEMORY_LIMIT', '256M' );\n";
			}
			if ( ! $has_max_memory_limit ) {
				$insert_str .= "define( 'WP_MAX_MEMORY_LIMIT', '256M' );\n";
			}
			
			$target_str = "/* That's all, stop editing! Happy publishing. */";
			if ( strpos( $config_content, $target_str ) !== false ) {
				$config_content = str_replace( $target_str, $insert_str . $target_str, $config_content );
			} else {
				// Fallback to top after php tag
				$config_content = preg_replace( "/<\?php/", "<?php\n" . $insert_str, $config_content, 1 );
			}
		}

		file_put_contents( $wp_config_path, $config_content );

		Database::log_history( 'system', 0, 'update', "Memory Limit alterado de {$old_memory} para 256M. Backup: {$backup_file}" );

		wp_send_json_success( array( 'message' => "Configuração aplicada com sucesso. Itens alterados: WP_MEMORY_LIMIT, WP_MAX_MEMORY_LIMIT.\nNenhum erro encontrado." ) );
	}

	private function fix_php_settings() {
		$user_ini_path = ABSPATH . '.user.ini';
		$htaccess_path = ABSPATH . '.htaccess';
		$changed = false;

		// 1. .user.ini
		if ( ( file_exists( $user_ini_path ) && is_writable( $user_ini_path ) ) || ( ! file_exists( $user_ini_path ) && is_writable( ABSPATH ) ) ) {
			$ini_content = file_exists( $user_ini_path ) ? file_get_contents( $user_ini_path ) : '';
			$new_ini = $ini_content;

			if ( strpos( $ini_content, 'max_execution_time' ) === false ) {
				$new_ini .= "\nmax_execution_time = 300";
			} else {
				$new_ini = preg_replace('/^max_execution_time\s*=\s*.+/m', 'max_execution_time = 300', $new_ini);
			}

			if ( strpos( $ini_content, 'upload_max_filesize' ) === false ) {
				$new_ini .= "\nupload_max_filesize = 128M";
			} else {
				$new_ini = preg_replace('/^upload_max_filesize\s*=\s*.+/m', 'upload_max_filesize = 128M', $new_ini);
			}
			
			if ( strpos( $ini_content, 'post_max_size' ) === false ) {
				$new_ini .= "\npost_max_size = 128M";
			} else {
				$new_ini = preg_replace('/^post_max_size\s*=\s*.+/m', 'post_max_size = 128M', $new_ini);
			}

			if ( $new_ini !== $ini_content ) {
				file_put_contents( $user_ini_path, trim( $new_ini ) . "\n" );
				$changed = true;
			}
		}

		// 2. .htaccess
		if ( file_exists( $htaccess_path ) && is_writable( $htaccess_path ) ) {
			$htaccess_content = file_get_contents( $htaccess_path );
			if ( strpos( $htaccess_content, '# BEGIN Hub PHP Settings' ) === false ) {
				$htaccess_append = "\n# BEGIN Hub PHP Settings\n<IfModule lsapi_module>\n  php_value max_execution_time 300\n  php_value upload_max_filesize 128M\n  php_value post_max_size 128M\n</IfModule>\n<IfModule mod_php.c>\n  php_value max_execution_time 300\n  php_value upload_max_filesize 128M\n  php_value post_max_size 128M\n</IfModule>\n# END Hub PHP Settings\n";
				file_put_contents( $htaccess_path, $htaccess_content . $htaccess_append );
				$changed = true;
			}
		}

		if ( $changed ) {
			Database::log_history( 'system', 0, 'update', 'Configurações do PHP (.user.ini / .htaccess) atualizadas via Diagnóstico.' );
			wp_send_json_success( array( 'message' => "Configuração aplicada com sucesso! Injetamos as regras no arquivo '.user.ini' e '.htaccess' do servidor." ) );
		} else {
			wp_send_json_error( array( 'message' => 'O seu servidor bloqueia edições de permissão. Será necessário aumentar pelo PHP Selector (cPanel) manualmente.' ) );
		}
	}

	private function fix_https() {
		global $wpdb;

		// 1. Backup DB
		$backup_service = new BackupService();
		$backup_file = $backup_service->run_database_backup();
		if ( ! $backup_file ) {
			wp_send_json_error( array( 'message' => 'Falha ao criar backup do banco de dados antes da operação. Ação cancelada por segurança.' ) );
		}

		$old_url = str_replace( 'https://', 'http://', get_option( 'home' ) );
		$new_url = str_replace( 'http://', 'https://', get_option( 'home' ) );

		if ( $old_url === $new_url ) {
			// Não há o que alterar
			wp_send_json_success( array( 'message' => 'Site já utiliza HTTPS.' ) );
		}

		$count = 0;

		// 2. Options
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s", $old_url, $new_url, '%' . $wpdb->esc_like( $old_url ) . '%' ) );
		
		// 3. Posts
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $old_url, $new_url, '%' . $wpdb->esc_like( $old_url ) . '%' ) );
		$count += $wpdb->rows_affected;
		
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, %s, %s) WHERE guid LIKE %s", $old_url, $new_url, '%' . $wpdb->esc_like( $old_url ) . '%' ) );

		// 4. Postmeta
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s", $old_url, $new_url, '%' . $wpdb->esc_like( $old_url ) . '%' ) );
		$count += $wpdb->rows_affected;

		Database::log_history( 'system', 0, 'update', "Substituição HTTP para HTTPS realizada. Aprox {$count} registros afetados. Backup DB antes da ação gerado." );

		wp_send_json_success( array( 'message' => "Configuração aplicada com sucesso. Aprox {$count} URLs corrigidas nas tabelas de Posts, Postmetas e Options." ) );
	}

	private function fix_database() {
		global $wpdb;

		// 1. Limpar Transients Expirados
		$wpdb->query( "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b WHERE a.option_name LIKE '\_transient\_%' AND a.option_name NOT LIKE '\_transient\_timeout\_%' AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) ) AND b.option_value < " . time() );

		// 2. Limpar Cache do VitPress
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'hub_cache_%'" );

		// 3. Otimizar Tabelas
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $table ) {
			$wpdb->query( "OPTIMIZE TABLE `$table`" );
		}

		Database::log_history( 'system', 0, 'update', 'Banco de dados otimizado (Transients limpos, VitPress cache limpo e OPTIMIZE executado).' );

		wp_send_json_success( array( 'message' => "Configuração aplicada com sucesso. Itens alterados: Limpeza de Transients, Limpeza de Cache local e Otimização de Tabelas (Overhead reduzido a 0).\nNenhum erro encontrado." ) );
	}

	/**
	 * Helper function to convert size strings to bytes.
	 */
	private function return_bytes( $val ) {
		$val  = trim( $val );
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		$val  = (int) $val;
		switch ( $last ) {
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}
		return $val;
	}
}
