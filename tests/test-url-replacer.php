<?php
/**
 * Troca de URL na restauração (UrlReplacer): todas as formas em que a URL é gravada. Sem WordPress, sem banco, sem rede.
 * php tests/test-url-replacer.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/core/Services/Backup/UrlReplacer.php';

use Hub\Core\Services\Backup\UrlReplacer;

$failed = 0;
function check( $condition, $message ) {
	global $failed;
	echo ( $condition ? 'ok ' : 'FALHA ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failed++;
	}
}

$old = 'http://localhost/projetos/sistemas/Site';
$new = 'https://cliente.com.br';
$r   = new UrlReplacer( $old, $new );

// ------------------------------------------------------------------ formas simples
check( 'https://cliente.com.br/pagina' === $r->replace_string( 'http://localhost/projetos/sistemas/Site/pagina' ), 'http simples' );
check( 'https://cliente.com.br/p' === $r->replace_string( 'https://localhost/projetos/sistemas/Site/p' ), 'https da origem também vira a URL do destino' );
check( 'https://cliente.com.br' === $r->replace_string( 'http://localhost/projetos/sistemas/Site' ), 'raiz sem barra final' );
check( 'img https://cliente.com.br/a.png e https://cliente.com.br/b.png' === $r->replace_string( 'img http://localhost/projetos/sistemas/Site/a.png e http://localhost/projetos/sistemas/Site/b.png' ), 'várias ocorrências no mesmo texto' );
check( 'https://cliente.com.br/x' === $r->replace_string( 'HTTP://LOCALHOST/projetos/sistemas/site/x' ), 'maiúsculas e minúsculas' );
check( '<img src="https://cliente.com.br/a.png" srcset="https://cliente.com.br/a-300.png 300w">' === $r->replace_string( '<img src="http://localhost/projetos/sistemas/Site/a.png" srcset="http://localhost/projetos/sistemas/Site/a-300.png 300w">' ), 'HTML com src e srcset' );
check( 'o endereço é https://cliente.com.br.' === $r->replace_string( 'o endereço é http://localhost/projetos/sistemas/Site.' ), 'ponto final da frase não impede a troca' );

// ------------------------------------------------------------------ JSON com barras escapadas (Elementor, opções JSON)
$json_old = '{"url":"http:\/\/localhost\/projetos\/sistemas\/Site\/wp-content\/uploads\/a.png"}';
check( '{"url":"https:\/\/cliente.com.br\/wp-content\/uploads\/a.png"}' === $r->replace_string( $json_old ), 'JSON com barras escapadas e subpasta' );
$decoded = json_decode( $r->replace_string( $json_old ), true );
check( 'https://cliente.com.br/wp-content/uploads/a.png' === ( $decoded['url'] ?? null ), 'o JSON continua válido e a URL decodificada está certa' );

// ------------------------------------------------------------------ codificada e sem protocolo
check( 'https://wa.me/?text=https%3A%2F%2Fcliente.com.br%2Fpagina' === $r->replace_string( 'https://wa.me/?text=http%3A%2F%2Flocalhost%2Fprojetos%2Fsistemas%2FSite%2Fpagina' ), 'URL codificada (%3A%2F%2F)' );
check( 'https://wa.me/?text=https%3A%2F%2Fcliente.com.br' === $r->replace_string( 'https://wa.me/?text=http%3a%2f%2flocalhost%2fprojetos%2fsistemas%2fSite' ), 'URL codificada com hexadecimal minúsculo' );
check( '<a href="//cliente.com.br/rel">' === $r->replace_string( '<a href="//localhost/projetos/sistemas/Site/rel">' ), 'URL sem protocolo (//host)' );
check( '{"u":"\/\/cliente.com.br\/rel"}' === $r->replace_string( '{"u":"\/\/localhost\/projetos\/sistemas\/Site\/rel"}' ), 'URL sem protocolo com barras escapadas' );
check( 'ftp://localhost/projetos/sistemas/Site/x' === $r->replace_string( 'ftp://localhost/projetos/sistemas/Site/x' ), 'outro protocolo não é confundido com URL sem protocolo' );

// ------------------------------------------------------------------ o que NÃO deve ser trocado
check( 'http://localhost/projetos/sistemas/Site2/x' === $r->replace_string( 'http://localhost/projetos/sistemas/Site2/x' ), 'outra pasta com o mesmo começo (Site2) não é tocada' );
check( 'http://localhost:8090/api' === $r->replace_string( 'http://localhost:8090/api' ), 'outro serviço no mesmo host, outra porta, não é tocado' );
check( 'http://localhost/outro-projeto/x' === $r->replace_string( 'http://localhost/outro-projeto/x' ), 'outro projeto no mesmo host não é tocado' );
check( 'https://example.org/x' === $r->replace_string( 'https://example.org/x' ) && 'sem url' === $r->replace_string( 'sem url' ), 'textos sem a URL ficam idênticos' );

// ------------------------------------------------------------------ origem sem subpasta e com porta
$r2 = new UrlReplacer( 'http://127.0.0.1:8092', 'https://x.com.br' );
check( 'https://x.com.br/a' === $r2->replace_string( 'http://127.0.0.1:8092/a' ) && 'http://127.0.0.1:80920/a' === $r2->replace_string( 'http://127.0.0.1:80920/a' ), 'origem com porta: troca a porta exata e não a 80920' );
check( 'https:\/\/x.com.br\/a' === $r2->replace_string( 'http:\/\/127.0.0.1:8092\/a' ), 'origem com porta, JSON escapado' );
check( 'q=https%3A%2F%2Fx.com.br%2Fa' === $r2->replace_string( 'q=http%3A%2F%2F127.0.0.1%3A8092%2Fa' ), 'origem com porta, codificada (a porta tem :)' );

// ------------------------------------------------------------------ serializados
$ser = serialize( array( 'site' => 'http://localhost/projetos/sistemas/Site', 'cfg' => array( 'a' => 'http://localhost/projetos/sistemas/Site/b', 'n' => 5, 'ok' => true, 'z' => null, 'f' => 1.5 ) ) );
$out = $r->replace_value( $ser );
$val = @unserialize( $out );
check( is_array( $val ) && 'https://cliente.com.br' === $val['site'] && 'https://cliente.com.br/b' === $val['cfg']['a'] && 5 === $val['cfg']['n'] && true === $val['cfg']['ok'] && null === $val['cfg']['z'] && 1.5 === $val['cfg']['f'], 'serializado: valores trocados, tipos preservados e tamanhos recalculados' );
$inner = serialize( array( 'u' => 'http://localhost/projetos/sistemas/Site/aninhado', 'n' => 1 ) );
$nested = serialize( array( 'interno' => $inner, 'externo' => 'http://localhost/projetos/sistemas/Site/ext' ) );
$nv     = @unserialize( $r->replace_value( $nested ) );
$niv    = is_array( $nv ) ? @unserialize( $nv['interno'] ) : false;
check( is_array( $nv ) && 'https://cliente.com.br/ext' === $nv['externo'] && is_array( $niv ) && 'https://cliente.com.br/aninhado' === $niv['u'], 'serializado dentro de serializado fica legível nos dois níveis' );
$key = serialize( array( 'http://localhost/projetos/sistemas/Site/k' => 1 ) );
check( array( 'https://cliente.com.br/k' => 1 ) === @unserialize( $r->replace_value( $key ) ), 'URL usada como chave de array também é trocada' );
$acc = serialize( array( 'txt' => 'ação “aspas” http://localhost/projetos/sistemas/Site/ç' ) );
check( array( 'txt' => 'ação “aspas” https://cliente.com.br/ç' ) === @unserialize( $r->replace_value( $acc ) ), 'serializado com acentos (tamanho em bytes)' );

class Cfg {
	protected $url = 'http://localhost/projetos/sistemas/Site/prot';
	private $priv  = 'http://localhost/projetos/sistemas/Site/priv';
	public $pub    = 'http://localhost/projetos/sistemas/Site/pub';
}
$o   = @unserialize( $r->replace_value( serialize( new Cfg ) ) );
$arr = $o ? (array) $o : array();
$vals = array_values( $arr );
check( $o instanceof Cfg && 3 === count( $vals ) && array( 'https://cliente.com.br/prot', 'https://cliente.com.br/priv', 'https://cliente.com.br/pub' ) === $vals, 'objeto com propriedades protegidas e privadas: todas trocadas' );
$ghost = str_replace( 'O:3:"Cfg"', 'O:7:"Ausente"', serialize( new Cfg ) );
$g     = $r->replace_value( $ghost );
check( false !== strpos( $g, 'O:7:"Ausente"' ) && false === strpos( $g, 'localhost' ) && is_object( @unserialize( $g, array( 'allowed_classes' => false ) ) ), 'objeto de classe que não existe no destino: trocado sem instanciar a classe' );
$ref = 'a:2:{i:0;s:41:"http://localhost/projetos/sistemas/Site/r";i:1;R:2;}';
check( 'a:2:{i:0;s:24:"https://cliente.com.br/r";i:1;R:2;}' === $r->replace_value( $ref ), 'referências (R:) preservadas' );
$broken = 's:99:"http://localhost/projetos/sistemas/Site/x";';
check( false !== strpos( $r->replace_value( $broken ), 'https://cliente.com.br/x' ), 'serializado corrompido na origem não derruba a restauração (troca como texto)' );
check( 'texto https://cliente.com.br/x' === $r->replace_value( 'texto http://localhost/projetos/sistemas/Site/x' ) && 5 === $r->replace_value( 5 ) && null === $r->replace_value( null ), 'valores que não são texto passam direto' );

// ------------------------------------------------------------------ filtros para o banco
check( array( 'localhost', 'localhost' ) !== $r->needles() && in_array( 'localhost', $r->needles(), true ), 'filtro do banco usa só o host (o caminho aparece escapado em JSON)' );
check( in_array( '127.0.0.1%3A8092', $r2->needles(), true ) && in_array( '127.0.0.1:8092', $r2->needles(), true ), 'filtro com porta inclui a forma codificada' );
$off = new UrlReplacer( '', 'https://x.com' );
check( ! $off->is_active() && 'http://a/b' === $off->replace_string( 'http://a/b' ), 'sem URL de origem conhecida nada é trocado' );

if ( $failed > 0 ) {
	echo "RESULTADO: {$failed} falha(s)\n";
	exit( 1 );
}
echo "RESULTADO: troca de URL ok\n";
