/**
 * Hub Visitor Tracking Script (Vanilla JS - Zero Dependencies)
 * Captura parÃ¢metros de UTMs, IDs de cliques, referenciador, visualizaÃ§Ãµes de pÃ¡gina e dados estruturados da primeira visita.
 */
(function() {
	'use strict';

	var operationalParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
	var marketingParams = ['gclid', 'gbraid', 'wbraid', 'fbclid'];
	
	var operationalInitialized = false;
	var marketingInitialized = false;

	function getQueryParam(name) {
		var match = RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
		return match && decodeURIComponent(match[1].replace(/\+/g, ' '));
	}

	function setCookie(name, value, days) {
		var expires = "";
		if (days) {
			var date = new Date();
			date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
			expires = "; expires=" + date.toUTCString();
		}
		document.cookie = name + "=" + (value || "") + expires + "; path=/; SameSite=Lax";
	}

	function getCookie(name) {
		var nameEQ = name + "=";
		var ca = document.cookie.split(';');
		for (var i = 0; i < ca.length; i++) {
			var c = ca[i];
			while (c.charAt(0) === ' ') c = c.substring(1, c.length);
			if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
		}
		return null;
	}

	function generateUUID() {
		var d = new Date().getTime();
		var uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
			var r = (d + Math.random()*16)%16 | 0;
			d = Math.floor(d/16);
			return (c=='x' ? r : (r&0x3|0x8)).toString(16);
		});
		return uuid;
	}

	// Atribuição operacional não pode carregar identificadores de clique sem consentimento.
	function stripClickIds(value) {
		if (!value || value.indexOf('?') === -1) return value;
		var hashAt = value.indexOf('#');
		var hash = hashAt === -1 ? '' : value.slice(hashAt);
		var url = hashAt === -1 ? value : value.slice(0, hashAt);
		var queryAt = url.indexOf('?');
		var kept = url.slice(queryAt + 1).split('&').filter(function(pair) {
			return pair !== '' && marketingParams.indexOf(pair.split('=')[0].toLowerCase()) === -1;
		});
		return url.slice(0, queryAt) + (kept.length ? '?' + kept.join('&') : '') + hash;
	}

	function processParams(paramsList, firstTouchByCookie) {
		// O cookie de sessão já existe quando os parâmetros são processados (o rastreador o cria antes) e, para
		// identificadores de clique, o consentimento pode chegar depois. O primeiro toque é, portanto, o primeiro valor
		// observado de cada parâmetro, não "a sessão é nova".
		paramsList.forEach(function(param) {
			var val = getQueryParam(param);
			if (val && !getCookie('hub_first_touch_' + param)) {
				setCookie('hub_first_touch_' + param, val, 30);
			}
		});
		paramsList.forEach(function(param) {
			var val = getQueryParam(param);
			if (val) {
				setCookie('hub_' + param, val, 30);
			}
		});
	}

	function initOperationalTracker() {
		if (operationalInitialized) return;
		operationalInitialized = true;

		var pageviews = parseInt(getCookie('hub_pageviews_count')) || 0;
		setCookie('hub_pageviews_count', pageviews + 1, 30);

		if (!getCookie('hub_session_id')) {
			setCookie('hub_session_id', generateUUID(), 30);
			setCookie('hub_first_visit_datetime', new Date().toISOString(), 30);
			var ref = document.referrer || '';
			setCookie('hub_external_referrer', stripClickIds(ref), 30);
			setCookie('hub_landing_url', stripClickIds(window.location.href), 30);
			setCookie('hub_landing_path', window.location.pathname, 30);
			setCookie('hub_landing_query', stripClickIds(window.location.search), 30);
		}

		processParams(operationalParams);
	}

		function initMarketingTracker() {
		if (marketingInitialized) return;
		marketingInitialized = true;
		processParams(marketingParams, true);
		loadMetaPixel();
	}

	window.hubPixelInitialized = false;
	window.hubPageViewSent = false;

	function loadMetaPixel() {
		if (typeof hubTrackerConfig === 'undefined' || !hubTrackerConfig.meta_enabled || !hubTrackerConfig.meta_pixel_id) {
			return;
		}

		if (window.hubPixelInitialized) {
			return; // Previne dupla inicialização
		}
		window.hubPixelInitialized = true;

		if (hubTrackerConfig.meta_debug) {
			console.log('[HUB META] Iniciando carregamento do Meta Pixel...');
		}

		if (typeof fbq !== 'undefined' && fbq.loaded) {
			if (hubTrackerConfig.meta_debug) {
				console.warn('[HUB META] AVISO: Existe uma implementação anterior do Meta Pixel carregada na página. Ela precisa ser removida.');
			}
			// Como o Pixel já existe, o Hub não deve recriar o snippet base, mas apenas tentar usar a instância existente.
		} else {
			// Injeta a biblioteca do Meta Pixel
			!function(f,b,e,v,n,t,s)
			{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
			n.callMethod.apply(n,arguments):n.queue.push(arguments)};
			if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
			n.queue=[];t=b.createElement(e);t.async=!0;
			t.src=v;s=b.getElementsByTagName(e)[0];
			s.parentNode.insertBefore(t,s)}(window, document,'script',
			'https://connect.facebook.net/en_US/fbevents.js');
		}

		// Garante que o PageView não seja disparado duas vezes
		if (!window.hubPageViewSent) {
			window.hubPageViewSent = true;
			if (hubTrackerConfig.meta_debug) {
				console.log('[HUB META] Inicializando fbq("init", ' + hubTrackerConfig.meta_pixel_id + ') e disparando PageView.');
			}
			fbq('init', hubTrackerConfig.meta_pixel_id);
			fbq('track', 'PageView');
		}
	}

	// AtribuiÃ§Ã£o Operacional (Base Legal: LegÃ­timo Interesse) inicia imediatamente
	initOperationalTracker();

	// IntegraÃ§Ã£o com ConsentManager para parÃ¢metros de Marketing/Publicidade
	if (window.HubConsent) {
		if (window.HubConsent.hasConsent()) {
			initMarketingTracker();
		} else {
			document.addEventListener('hub:consent-updated', function(e) {
				if (e.detail && e.detail.status === 'granted') {
					initMarketingTracker();
				}
			});
		}
	}

})();