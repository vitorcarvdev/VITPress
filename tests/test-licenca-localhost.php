<?php
/**
 * Licença automática somente quando o domínio registrado (host de site_url()) é exatamente "localhost".
 * Isolado: não carrega o WordPress nem usa banco.
 *   php tests/test-licenca-localhost.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['t_site_url'] = 'http://localhost/projetos/site';
	$GLOBALS['t_options']  = array();
	$GLOBALS['t_filters']  = array();

	function site_url() {
		return $GLOBALS['t_site_url'];
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function get_option( $name, $default = false ) {
		return $GLOBALS['t_options'][ $name ] ?? $default;
	}
	function update_option( $name, $value ) {
		$GLOBALS['t_options'][ $name ] = $value;
		return true;
	}
	function wp_generate_uuid4() {
		return '5be985b1-5d41-4751-9947-0ab98f378d63';
	}
	function current_time( $type ) {
		return '2026-01-01 10:00:00';
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'sal-de-teste';
	}
	function add_filter( $tag, $cb ) {
		$GLOBALS['t_filters'][ $tag ][] = $cb;
	}
	function apply_filters( $tag, $value ) {
		foreach ( $GLOBALS['t_filters'][ $tag ] ?? array() as $cb ) {
			$value = call_user_func( $cb, $value );
		}
		return $value;
	}
	function __return_false() {
		return false;
	}

	require dirname( __DIR__ ) . '/core/Services/Licensing/LicenseProviderInterface.php';
	require dirname( __DIR__ ) . '/core/Services/Licensing/LocalLicenseProvider.php';
}

namespace LicencaLocalhostTest {

	use Hub\Core\Services\Licensing\LocalLicenseProvider;

	$failed = 0;
	function check( $condition, $message ) {
		global $failed;
		echo ( $condition ? 'ok ' : 'FALHA ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failed++;
		}
	}
	function status_for( $url ) {
		$GLOBALS['t_site_url'] = $url;
		return ( new LocalLicenseProvider() )->getStatus();
	}

	$s = status_for( 'http://localhost/projetos/sistemas/WPPluginsLocalTeste' );
	check( true === $s['is_valid'] && 'licensed_localhost' === $s['reason'] && 'localhost' === $s['domain'], 'domínio localhost: licença ativada automaticamente' );
	check( ! empty( $s['authorized_at'] ) && ! empty( $s['authorized_by'] ) && ! empty( $s['uuid'] ), 'status traz data, autor e UUID (a tela da licença exibe esses campos)' );
	check( true === ( new LocalLicenseProvider() )->isValid(), 'isValid() verdadeiro em localhost' );
	check( true === status_for( 'http://LOCALHOST:8080/x' )['is_valid'], 'localhost em maiúsculas e com porta também vale' );
	check( empty( $GLOBALS['t_options']['hub_settings']['local_license'] ), 'nada é gravado como licença (a regra é calculada, não persistida)' );

	foreach ( array(
		'domínio comum'              => 'https://joanadeangelis.com.br',
		'subdomínio de localhost'    => 'http://app.localhost/',
		'contém localhost no nome'   => 'http://localhost.exemplo.com.br/',
		'domínio terminando'         => 'http://meulocalhost.com/',
		'IP de loopback'             => 'http://127.0.0.1/site',
		'IPv6 de loopback'           => 'http://[::1]/site',
	) as $label => $url ) {
		$st = status_for( $url );
		check( false === $st['is_valid'] && 'not_licensed' === $st['reason'], "{$label}: continua exigindo licença ({$url})" );
	}

	// O cabeçalho Host da requisição não pode forjar a licença.
	$_SERVER['HTTP_HOST'] = 'localhost';
	check( false === status_for( 'https://cliente.example.com' )['is_valid'], 'cabeçalho Host "localhost" não licencia um site de domínio real' );

	// Interruptor por código.
	add_filter( 'hub/auto_license_localhost', '__return_false' );
	$st = status_for( 'http://localhost/' );
	check( false === $st['is_valid'] && 'not_licensed' === $st['reason'], 'filtro hub/auto_license_localhost = false desliga a regra' );
	$GLOBALS['t_filters'] = array();

	// A licença assinada de domínio real segue funcionando (caminho original intacto).
	$host = 'cliente.example.com';
	$uuid = '5be985b1-5d41-4751-9947-0ab98f378d63';
	$GLOBALS['t_options']['hub_settings'] = array(
		'installation_uuid' => $uuid,
		'local_license'     => array( 'domain' => $host, 'signature' => hash_hmac( 'sha256', $host . '|' . $uuid, 'sal-de-teste' ), 'authorized_at' => '2026-01-01 00:00:00', 'authorized_user' => 'vcsis' ),
	);
	$st = status_for( 'https://' . $host );
	check( true === $st['is_valid'] && 'licensed' === $st['reason'], 'domínio real com assinatura válida continua licenciado como antes' );
	$GLOBALS['t_options']['hub_settings']['local_license']['signature'] = 'assinatura-errada';
	$st = status_for( 'https://' . $host );
	check( false === $st['is_valid'] && 'signature_mismatch' === $st['reason'], 'assinatura inválida em domínio real continua recusada' );

	if ( $failed > 0 ) {
		echo "RESULTADO: {$failed} falha(s)\n";
		exit( 1 );
	}
	echo "RESULTADO: licença automática em localhost ok\n";
}
