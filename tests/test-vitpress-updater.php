<?php
/**
 * Testes locais do atualizador VitPress. Não carregado pelo WordPress.
 */

$state = [
    'options' => [],
    'writes' => [],
    'transients' => [],
    'calls' => 0,
    'bodies' => [],
    'downloads' => [],
    'remote' => null,
    'download_file' => '',
];

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/vitpress-wp/' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

class WP_Error {
    public $code;
    public $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key ) {
    global $state;
    return $state['options'][ $key ] ?? false;
}
function update_option( $key, $value ) {
    global $state;
    $state['options'][ $key ] = $value;
    $state['writes'][] = $key;
    return true;
}
function wp_generate_uuid4() {
    return '12345678-1234-4234-8234-123456789abc';
}
function wp_parse_url( $url, $component = -1 ) {
    return parse_url( $url, $component );
}
function site_url() {
    return 'https://cliente.exemplo.com';
}
function get_plugin_data( $file ) {
    return [ 'Version' => '1.9.1', 'Name' => 'VitPress' ];
}
function is_plugin_active( $file ) {
    return $file === 'hub-vitagencia/hub.php';
}
function get_transient( $key ) {
    global $state;
    return $state['transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl ) {
    global $state;
    $state['transients'][ $key ] = $value;
    return true;
}
function delete_transient( $key ) {
    global $state;
    unset( $state['transients'][ $key ] );
    return true;
}
function wp_remote_post( $url, $args ) {
    global $state;
    $state['calls']++;
    $state['bodies'][] = $args['body'];
    $state['last_ssl'] = $args['sslverify'] ?? null;
    $state['last_url'] = $url;
    return $state['remote'];
}
function wp_remote_retrieve_response_code( $response ) {
    return is_array( $response ) ? (int) $response['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
    return is_array( $response ) ? (string) $response['body'] : '';
}
function wp_strip_all_tags( $text ) {
    return strip_tags( (string) $text );
}
function wpautop( $text ) {
    return (string) $text;
}
function trailingslashit( $value ) {
    return rtrim( (string) $value, '/\\' ) . '/';
}
function untrailingslashit( $value ) {
    return rtrim( (string) $value, '/\\' );
}
function download_url( $url ) {
    global $state;
    $state['downloads'][] = $url;
    return $state['download_file'];
}

require dirname( __DIR__ ) . '/core/class-hub-vitagencia-updater.php';

$wp_version = '6.7';
$pass = 0;
$fail = 0;

function check( $ok, $message ) {
    global $pass, $fail;
    if ( $ok ) {
        $pass++;
        echo "PASS  {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$message}\n";
}

function reset_state() {
    global $state;
    $state['options'] = [];
    $state['writes'] = [];
    $state['transients'] = [];
    $state['calls'] = 0;
    $state['bodies'] = [];
    $state['downloads'] = [];
    $state['remote'] = null;
    $state['download_file'] = '';
}

function updater() {
    $updater = new Hub_Vitagencia_Updater();
    $updater->init_updater();
    return $updater;
}

function remote( $data, $code = 200 ) {
    global $state;
    $state['remote'] = [
        'code' => $code,
        'body' => is_string( $data ) ? $data : json_encode( $data ),
    ];
}

function transient_with( $checked = true ) {
    $transient = new stdClass();
    $transient->checked = $checked ? [ 'hub-vitagencia/hub.php' => '1.9.1' ] : [];
    $transient->response = [];
    $transient->no_update = [];
    return $transient;
}

$root = dirname( __DIR__ );
$header = file_get_contents( $root . '/hub.php' );
$config = file_get_contents( $root . '/config/config.php' );
$updater_source = file_get_contents( $root . '/core/class-hub-vitagencia-updater.php' );

check( preg_match( '/^\s*\*\s*Version:\s*1\.9\.1\s*$/m', $header ) === 1, 'cabeçalho identifica VitPress 1.9.1' );
check( strpos( $header, "define( 'HUB_VERSION', '1.9.1' );" ) !== false, 'constante HUB_VERSION é 1.9.1' );
check( strpos( $config, "'version'             => '1.9.1'" ) !== false, 'metadado interno de versão é 1.9.1' );
check( strpos( $header, 'Plugin Name: VitPress' ) !== false, 'nome oficial permanece VitPress' );

reset_state();
$one = updater();
$two = updater();
check( $state['options']['hub_vitagencia_install_uuid'] === '12345678-1234-4234-8234-123456789abc', 'UUID é gravado uma vez' );
check( count( $state['writes'] ) === 1, 'UUID não é recriado na verificação seguinte' );

reset_state();
remote( [ 'update_available' => false ] );
$result = updater()->check_for_updates( transient_with() );
check( empty( $result->response['hub-vitagencia/hub.php'] ), 'versão igual publicada não oferece atualização' );
check( isset( $result->no_update['hub-vitagencia/hub.php'] ), 'versão igual fica em no_update' );
check( $state['bodies'][0]['current_version'] === '1.9.1', 'check envia a versão instalada 1.9.1' );
check( $state['bodies'][0]['uuid'] === '12345678-1234-4234-8234-123456789abc', 'check envia o UUID estável' );
check( $state['bodies'][0]['domain'] === 'cliente.exemplo.com', 'check envia o domínio' );
check( $state['bodies'][0]['wordpress_version'] === '6.7', 'check envia a versão do WordPress' );
check( $state['bodies'][0]['php_version'] === phpversion(), 'check envia a versão do PHP' );
check( $state['bodies'][0]['plugin_status'] === 'active', 'check envia plugin_status' );
check( ! isset( $state['bodies'][0]['channel'] ), 'o canal continua decidido no VitAds' );

reset_state();
$package = 'https://vitads.vitagencia.com.br/api/v1/vitpress/updates/download?token=abc123';
remote( [
    'update_available' => true,
    'version' => '1.9.2',
    'requires_wp' => '5.8',
    'requires_php' => '8.2',
    'changelog' => "Correção\n<script>alert(1)</script>",
    'package_url' => $package,
    'sha256' => hash( 'sha256', 'pacote' ),
] );
$result = updater()->check_for_updates( transient_with() );
$offer = $result->response['hub-vitagencia/hub.php'] ?? null;
check( is_object( $offer ) && $offer->plugin === 'hub-vitagencia/hub.php', 'atualização usa hub-vitagencia/hub.php' );
check( is_object( $offer ) && $offer->new_version === '1.9.2' && $offer->package === $package, 'WordPress recebe versão e package_url exata' );
check( is_object( $offer ) && $offer->requires === '5.8' && $offer->requires_php === '8.2', 'metadados requires_wp e requires_php são aplicados' );
check( empty( $state['transients']['hub_vitagencia_vitads_check']['package_url'] ), 'token de download não fica no cache' );

reset_state();
remote( [
    'update_available' => true,
    'version' => '1.9.3',
    'package_url' => $package,
    'changelog' => 'Canal de teste',
] );
$test_channel = updater()->check_for_updates( transient_with() );
check( ( $test_channel->response['hub-vitagencia/hub.php']->new_version ?? '' ) === '1.9.3', 'resposta de canal test entra na atualização padrão' );

reset_state();
$state['remote'] = new WP_Error( 'http_request_failed', 'falha' );
$before = transient_with();
$down = updater()->check_for_updates( $before );
check( empty( $down->response ) && empty( $down->no_update ), 'API indisponível não altera o transient nem gera fatal' );
$calls = $state['calls'];
updater()->check_for_updates( transient_with() );
check( $state['calls'] === $calls, 'falha recente não dispara nova chamada' );

reset_state();
remote( '{nao-json' );
$invalid = updater()->check_for_updates( transient_with() );
check( empty( $invalid->response ) && empty( $invalid->no_update ), 'JSON inválido não oferece atualização' );

reset_state();
$file = tempnam( sys_get_temp_dir(), 'vp' );
file_put_contents( $file, 'pacote-valido' );
$state['download_file'] = $file;
remote( [
    'update_available' => true,
    'version' => '1.9.1',
    'package_url' => $package,
    'sha256' => hash( 'sha256', 'pacote-valido' ),
] );
$downloaded = updater()->download_package( false, $package, null, [ 'plugin' => 'hub-vitagencia/hub.php' ] );
check( $downloaded === $file && $state['downloads'] === [ $package ], 'download usa exatamente a package_url e confere o SHA-256' );

reset_state();
$bad = tempnam( sys_get_temp_dir(), 'vp' );
file_put_contents( $bad, 'trocado' );
$state['download_file'] = $bad;
remote( [
    'update_available' => true,
    'version' => '1.9.1',
    'package_url' => $package,
    'sha256' => hash( 'sha256', 'pacote-valido' ),
] );
$rejected = updater()->download_package( false, $package, null, [ 'plugin' => 'hub-vitagencia/hub.php' ] );
check( is_wp_error( $rejected ) && ! is_file( $bad ), 'SHA-256 divergente bloqueia o pacote' );

$ok_dir = sys_get_temp_dir() . '/hub-vitagencia';
$bad_dir = sys_get_temp_dir() . '/vitpress';
@mkdir( $ok_dir );
@mkdir( $bad_dir );
file_put_contents( $ok_dir . '/hub.php', '<?php' );
file_put_contents( $bad_dir . '/hub.php', '<?php' );
$guard = new Hub_Vitagencia_Updater();
check( $guard->guard_extracted_source( $ok_dir, '', null, [ 'plugin' => 'hub-vitagencia/hub.php' ] ) === $ok_dir, 'ZIP extraído continua em hub-vitagencia/hub.php' );
check( is_wp_error( $guard->guard_extracted_source( $bad_dir, '', null, [ 'plugin' => 'hub-vitagencia/hub.php' ] ) ), 'pasta vitpress/ é recusada' );
check( strpos( $updater_source, 'deactivate_plugins' ) === false, 'o atualizador não desativa o plugin' );
check( strpos( $updater_source, 'hub_settings' ) === false, 'o atualizador não altera configurações existentes' );

reset_state();
remote( 'https://vitads.vitagencia.com.br/api/v1/vitpress/updates/download?token=abc123', 200 );
$state['remote'] = [ 'code' => 200, 'body' => 'nao-json' ];
$ref = new ReflectionClass( 'Hub_Vitagencia_Updater' );
$http = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty( 'api_url' );
$prop->setAccessible( true );
$prop->setValue( $http, 'http://vitads.vitagencia.com.br/api/v1/vitpress/updates/check' );
$uuid = $ref->getProperty( 'uuid' );
$uuid->setAccessible( true );
$uuid->setValue( $http, '12345678-1234-4234-8234-123456789abc' );
$domain = $ref->getProperty( 'domain' );
$domain->setAccessible( true );
$domain->setValue( $http, 'cliente.exemplo.com' );
$method = $ref->getMethod( 'request_update_data' );
$method->setAccessible( true );
check( $method->invoke( $http ) === null && $state['calls'] === 0, 'URL sem HTTPS não é consultada' );
check( strpos( $updater_source, "'sslverify' => true" ) !== false && strpos( $updater_source, 'sslverify' . " => false" ) === false, 'verificação SSL permanece ligada' );

reset_state();
remote( [
    'update_available' => true,
    'version' => '1.9.1',
    'package_url' => $package,
    'changelog' => 'Detalhe',
] );
$details = updater()->plugins_api_handler( false, 'plugin_information', (object) [ 'slug' => 'hub-vitagencia' ] );
check( is_object( $details ) && $details->name === 'VitPress' && $details->version === '1.9.1', 'tela de detalhes recebe VitPress e a versão nova' );
check( is_object( $details ) && strpos( $details->sections['changelog'], '12345678-1234-4234-8234-123456789abc' ) === false, 'a tela de detalhes não expõe o UUID' );

$zip_path = dirname( $root ) . '/vitpress-v1.9.1.zip';
if ( is_file( $zip_path ) ) {
    unlink( $zip_path );
}
$zip = new ZipArchive();
$opened = $zip->open( $zip_path, ZipArchive::CREATE );
check( $opened === true, 'empacotamento do ZIP inicia' );
if ( $opened === true ) {
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
    foreach ( $iterator as $file_info ) {
        $full = $file_info->getPathname();
        $relative = substr( $full, strlen( $root ) + 1 );
        $relative = str_replace( '\\', '/', $relative );
        if ( strpos( $relative, 'tests/' ) === 0 || strpos( $relative, '.git/' ) === 0 ) {
            continue;
        }
        if ( $file_info->isFile() ) {
            $zip->addFile( $full, 'hub-vitagencia/' . $relative );
        }
    }
    $zip->close();
    $check_zip = new ZipArchive();
    $check_zip->open( $zip_path );
    $names = [];
    for ( $i = 0; $i < $check_zip->numFiles; $i++ ) {
        $names[] = $check_zip->getNameIndex( $i );
    }
    $check_zip->close();
    $parallel = array_filter( $names, function ( $name ) {
        return strpos( $name, 'vitpress/' ) === 0;
    } );
    check( in_array( 'hub-vitagencia/hub.php', $names, true ), 'ZIP contém hub-vitagencia/hub.php' );
    check( $parallel === [], 'ZIP não cria a pasta vitpress/' );
    $main = file_get_contents( 'zip://' . $zip_path . '#hub-vitagencia/hub.php' );
    check( strpos( $main, 'Version: 1.9.1' ) !== false, 'hub.php dentro do ZIP está na versão 1.9.1' );
}

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
exit( $fail > 0 ? 1 : 0 );
