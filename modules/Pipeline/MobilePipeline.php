<?php
namespace Hub\Modules\Pipeline;

use Hub\Core\Services\Integrations\VitAdsPortal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Página /pipeline, fora do wp-admin. Leitura dos leads e troca de etapa.
 */
class MobilePipeline {

	const QUERY_VAR = 'vitpress_pipeline';

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'keep_pipeline_url' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	public static function register_rewrites() {
		add_rewrite_rule( '^pipeline/?$', 'index.php?' . self::QUERY_VAR . '=board', 'top' );
		add_rewrite_rule( '^pipeline/manifest\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
		add_rewrite_rule( '^pipeline/sw\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
	}

	public static function maybe_flush_rewrites() {
		if ( '1' === get_option( 'vitpress_pipeline_rewrite' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'vitpress_pipeline_rewrite', '1' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function keep_pipeline_url( $redirect ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}
		return $redirect;
	}

	public static function register_rest() {
		register_rest_route(
			'hub/v1',
			'/pipeline/stage',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_change_stage' ),
				'permission_callback' => array( __CLASS__, 'can_manage_pipeline' ),
				'args'                => array(
					'lead_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'stage'   => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public static function can_manage_pipeline() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public static function rest_change_stage( \WP_REST_Request $request ) {
		$result = PipelineModule::change_stage(
			(int) $request->get_param( 'lead_id' ),
			(string) $request->get_param( 'stage' ),
			'vitpress_mobile'
		);

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				$status > 0 ? $status : 400
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'lead'    => $result,
			),
			200
		);
	}

	public static function dispatch() {
		$mode = get_query_var( self::QUERY_VAR );
		if ( ! $mode ) {
			return;
		}

		if ( 'manifest' === $mode ) {
			self::send_manifest();
		}
		if ( 'sw' === $mode ) {
			self::send_service_worker();
		}
		if ( 'board' === $mode ) {
			self::maybe_redirect_to_portal();
			self::send_board();
		}
	}

	/**
	 * Acesso humano à página /pipeline/: vai para o login do portal do VitAds (302, reversível).
	 * Só GET/HEAD e só com o site ligado ao VitAds. O manifest, o service worker e a API REST continuam respondendo,
	 * então instalações antigas do app não quebram e a sincronização de etapas não depende desta página.
	 */
	private static function maybe_redirect_to_portal() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! VitAdsPortal::legacy_redirect_enabled() ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_redirect( VitAdsPortal::login_url(), 302, 'VitPress' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
	private static function send_board() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		if ( $path && substr( untrailingslashit( $path ), -9 ) === '/pipeline' && substr( $path, -1 ) !== '/' ) {
			wp_safe_redirect( home_url( '/pipeline/' ) );
			exit;
		}

		if ( ! is_user_logged_in() ) {
			self::send_login_shell();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Pipeline</title><body><p>Sem permissão para ver o Pipeline.</p></body></html>';
			exit;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: private, no-store' );

		\Hub\Core\Services\Integrations\PipelineContactSync::pull_board();
		$stages = PipelineModule::get_stage_definitions();
		$cards  = self::load_cards();
		$nonce  = wp_create_nonce( 'wp_rest' );
		$endpoint = rest_url( 'hub/v1/pipeline/stage' );
		$css = self::asset_url( 'assets/css/pipeline-mobile.css' );
		$js  = self::asset_url( 'assets/js/pipeline-mobile.js' );
		$manifest = self::same_origin_path( home_url( '/pipeline/manifest.webmanifest' ) );
		$sw = self::same_origin_path( home_url( '/pipeline/sw.js' ) );
		$scope = self::pipeline_scope();
		$icon = self::asset_url( 'assets/icons/pipeline-icon-192.png' );
		$marketing_line = \Hub\Core\Services\Integrations\VitAdsMarketing::mobile_line();

		require HUB_PATH . 'modules/Pipeline/views/mobile-pipeline.php';
		exit;
	}

	public static function load_cards() {
		$table = \Hub\Core\Database::table( 'leads' );
		$db    = \Hub\Core\Database::db();
		$rows  = $db->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 500" );
		$cards = array();
		if ( empty( $rows ) ) {
			return $cards;
		}
		foreach ( $rows as $row ) {
			$cards[] = PipelineModule::format_lead_card( $row );
		}
		return $cards;
	}

	private static function send_login_shell() {
		$login = wp_login_url( home_url( '/pipeline/' ) );
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<link rel="manifest" href="' . esc_url( self::same_origin_path( home_url( '/pipeline/manifest.webmanifest' ) ) ) . '">';
		echo '<title>Pipeline</title></head><body>';
		echo '<p><a href="' . esc_url( $login ) . '">Entrar</a></p>';
		echo '<script>location.replace(' . wp_json_encode( $login ) . ');</script>';
		echo '</body></html>';
		exit;
	}

	private static function pipeline_scope() {
		$path = (string) wp_parse_url( home_url( '/pipeline/' ), PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/pipeline/';
		}
		return trailingslashit( $path );
	}

	private static function same_origin_path( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}
		return $path;
	}

	private static function send_manifest() {
		$scope    = self::pipeline_scope();
		$icon_192 = self::same_origin_path( HUB_URL . 'assets/icons/pipeline-icon-192.png' );
		$icon_512 = self::same_origin_path( HUB_URL . 'assets/icons/pipeline-icon-512.png' );
		$manifest = array(
			'id'               => $scope,
			'name'             => 'VitPress',
			'short_name'       => 'Pipeline',
			'start_url'        => $scope,
			'scope'            => $scope,
			'display'          => 'standalone',
			'background_color' => '#f4f7f6',
			'theme_color'      => '#1b4d3e',
			'icons'            => array(
				array(
					'src'     => $icon_192,
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => $icon_512,
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => $icon_192,
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
				array(
					'src'     => $icon_512,
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $manifest );
		exit;
	}

	private static function send_service_worker() {
		$shell = array(
			self::same_origin_path( self::asset_url( 'assets/css/pipeline-mobile.css' ) ),
			self::same_origin_path( self::asset_url( 'assets/js/pipeline-mobile.js' ) ),
			self::same_origin_path( HUB_URL . 'assets/icons/pipeline-icon-192.png' ),
			self::same_origin_path( HUB_URL . 'assets/icons/pipeline-icon-512.png' ),
		);
		$cache = 'vitpress-pipeline-shell-v2';
		$body  = 'const CACHE=' . wp_json_encode( $cache ) . ';const SHELL=' . wp_json_encode( $shell ) . ';' . self::service_worker_runtime();

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . wp_parse_url( home_url( '/pipeline/' ), PHP_URL_PATH ) );
		echo $body;
		exit;
	}

	private static function service_worker_runtime() {
		return <<<'JS'
self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (cache) {
    return cache.addAll(SHELL).catch(function () {});
  }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (key) { return key !== CACHE; }).map(function (key) { return caches.delete(key); }));
  }));
  self.clients.claim();
});
self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;
  var url = new URL(request.url);
  if (url.pathname.indexOf('/wp-json/') !== -1) return;
  if (url.pathname.indexOf('/pipeline/sw.js') !== -1) return;
  if (url.pathname.indexOf('/pipeline/manifest.webmanifest') !== -1) return;
  if (request.mode === 'navigate' || /\/pipeline\/?$/.test(url.pathname)) return;
  var shellHit = SHELL.indexOf(request.url) !== -1 || SHELL.indexOf(url.origin + url.pathname) !== -1;
  if (!shellHit) return;
  event.respondWith(caches.open(CACHE).then(function (cache) {
    return cache.match(request).then(function (cached) {
      var fetched = fetch(request).then(function (response) {
        if (response && response.ok) cache.put(request, response.clone());
        return response;
      }).catch(function () { return cached; });
      return cached || fetched;
    });
  }));
});
JS;
	}

	public static function asset_url( $relative ) {
		$path = HUB_PATH . ltrim( $relative, '/' );
		$ver  = file_exists( $path ) ? filemtime( $path ) : '1';
		return HUB_URL . ltrim( $relative, '/' ) . '?ver=' . $ver;
	}
}
