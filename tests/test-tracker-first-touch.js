/**
 * Primeiro toque dos identificadores de clique no hub-tracker.js (sem navegador): node tests/test-tracker-first-touch.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'hub-tracker.js'), 'utf8');
let failed = 0;
function check(cond, msg) {
  console.log((cond ? 'ok ' : 'FALHA ') + msg);
  if (!cond) failed++;
}

function page(jar, search, consent) {
  const listeners = {};
  const doc = {
    referrer: '',
    addEventListener: (name, fn) => { listeners[name] = fn; },
  };
  Object.defineProperty(doc, 'cookie', {
    get: () => Object.keys(jar).map((k) => k + '=' + jar[k]).join('; '),
    set: (value) => {
      const pair = String(value).split(';')[0];
      const at = pair.indexOf('=');
      jar[pair.slice(0, at)] = pair.slice(at + 1);
    },
  });
  const win = {
    location: { search, href: 'https://site.test/pagina' + search, pathname: '/pagina' },
    HubConsent: { hasConsent: () => consent },
  };
  win.window = win;
  const ctx = vm.createContext({ window: win, document: doc, decodeURIComponent, RegExp, Date, Math, parseInt });
  vm.runInContext(source, ctx);
  return { grant: () => listeners['hub:consent-updated'] && listeners['hub:consent-updated']({ detail: { status: 'granted' } }) };
}

// 1. Chegada com consentimento já concedido: gclid atual e primeiro toque gravados.
let jar = {};
page(jar, '?utm_source=google&gclid=G1&gbraid=GB1', true);
check(jar.hub_gclid === 'G1' && jar.hub_first_touch_gclid === 'G1', 'gclid da chegada vira atual e primeiro toque (mesmo com a sessão criada antes)');
check(jar.hub_gbraid === 'GB1' && jar.hub_first_touch_gbraid === 'GB1' && jar.hub_wbraid === undefined, 'gbraid fica separado; wbraid ausente não é fabricado');
check(jar.hub_first_touch_utm_source === 'google', 'UTM operacional continua com primeiro toque na primeira visita');

// 2. Outra página, novo gclid: atual muda, primeiro toque permanece.
page(jar, '?gclid=G2', true);
check(jar.hub_gclid === 'G2' && jar.hub_first_touch_gclid === 'G1', 'novo gclid não sobrescreve o primeiro toque');

// 3. Navegação sem parâmetros preserva tudo.
page(jar, '', true);
check(jar.hub_gclid === 'G2' && jar.hub_first_touch_gclid === 'G1' && jar.hub_first_touch_utm_source === 'google', 'páginas sem parâmetros não apagam a atribuição');

// 4. Sem consentimento: nenhum identificador de clique é gravado; depois do aceite na própria página de chegada, é.
jar = {};
const noConsent = page(jar, '?gclid=G9', false);
check(jar.hub_gclid === undefined && jar.hub_first_touch_gclid === undefined, 'sem consentimento nenhum identificador de clique é gravado');
check(!/gclid/.test(jar.hub_landing_url || '') && !/gclid/.test(jar.hub_landing_query || ''), 'landing_url e landing_query não carregam gclid sem consentimento');
noConsent.grant();
check(jar.hub_gclid === 'G9' && jar.hub_first_touch_gclid === 'G9', 'consentimento dado na página de chegada grava o gclid e o primeiro toque');

// 5. Sem gclid na URL: nada é inventado.
jar = {};
page(jar, '?utm_source=organico', true);
check(jar.hub_gclid === undefined && jar.hub_gbraid === undefined && jar.hub_first_touch_gclid === undefined, 'sem gclid/gbraid/wbraid na URL, nenhum cookie desses é criado');

if (failed) {
  console.log('RESULTADO: ' + failed + ' falha(s)');
  process.exit(1);
}
console.log('RESULTADO: tracker ok');
