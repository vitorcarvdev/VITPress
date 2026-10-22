window.HubConsent = (function() {
	var cookieName = 'hub_consent_preferences';
	var validStatuses = ['granted', 'denied'];
	// Gravados pelo hub-tracker.js só com consentimento; não podem sobreviver a uma recusa.
	var marketingCookies = ['gclid', 'gbraid', 'wbraid', 'fbclid'];

	function setCookie(name, value, days) {
		var expires = "";
		if (days) {
			var date = new Date();
			date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
			expires = "; expires=" + date.toUTCString();
		}
		document.cookie = name + "=" + encodeURIComponent(JSON.stringify(value)) + expires + "; path=/; SameSite=Lax";
	}

	function getCookie(name) {
		var nameEQ = name + "=";
		var ca = document.cookie.split(';');
		for (var i = 0; i < ca.length; i++) {
			var c = ca[i];
			while (c.charAt(0) === ' ') c = c.substring(1, c.length);
			if (c.indexOf(nameEQ) === 0) {
				try {
					return JSON.parse(decodeURIComponent(c.substring(nameEQ.length, c.length)));
				} catch (e) {
					return null;
				}
			}
		}
		return null;
	}

	function removeCookie(name) {
		document.cookie = name + '=; Max-Age=-99999999; path=/';
	}

	function clearMarketingCookies() {
		marketingCookies.forEach(function(param) {
			removeCookie('hub_' + param);
			removeCookie('hub_first_touch_' + param);
		});
	}

	var state = getCookie(cookieName);

	var consentApi = {
		getStatus: function() {
			return state && validStatuses.indexOf(state.status) !== -1 ? state.status : 'unknown';
		},
		hasConsent: function() {
			return this.getStatus() === 'granted';
		},
		update: function(status) {
			if (validStatuses.indexOf(status) === -1) {
				return;
			}
			state = {
				status: status,
				timestamp: new Date().getTime()
			};
			setCookie(cookieName, state, 365);
			if (status !== 'granted') {
				clearMarketingCookies();
			}
			
			// Atualiza GCM v2
			if (typeof gtag === 'function') {
				gtag('consent', 'update', {
					'ad_storage': status === 'granted' ? 'granted' : 'denied',
					'analytics_storage': status === 'granted' ? 'granted' : 'denied',
					'ad_user_data': status === 'granted' ? 'granted' : 'denied',
					'ad_personalization': status === 'granted' ? 'granted' : 'denied'
				});
			}

			// Dispara evento para módulos como o Tracker saberem que atualizou (mesmo sem reload)
			var event = new CustomEvent('hub:consent-updated', { detail: { status: status } });
			document.dispatchEvent(event);
		},
		reset: function() {
			removeCookie(cookieName);
			clearMarketingCookies();
			state = null;
			window.location.reload();
		},
		open: function() {}
	};

	document.addEventListener('DOMContentLoaded', function() {
		var overlay = document.getElementById('hub-consent-banner');
		if (!overlay) {
			return;
		}
		var modal = overlay.querySelector('.hub-consent-modal');
		var btnAccept = document.getElementById('hub-consent-accept');
		var btnReject = document.getElementById('hub-consent-reject');
		var btnManage = document.getElementById('hub-consent-manage');
		var hiddenSiblings = [];
		var returnFocus = null;

		function isOpen() {
			return !overlay.hidden;
		}

		function lockBackground() {
			Array.prototype.forEach.call(document.body.children, function(el) {
				if (el === overlay || el.tagName === 'SCRIPT' || el.hasAttribute('inert')) {
					return;
				}
				el.setAttribute('inert', '');
				el.setAttribute('aria-hidden', 'true');
				hiddenSiblings.push(el);
			});
			document.documentElement.classList.add('hub-consent-open');
		}

		function unlockBackground() {
			hiddenSiblings.forEach(function(el) {
				el.removeAttribute('inert');
				el.removeAttribute('aria-hidden');
			});
			hiddenSiblings = [];
			document.documentElement.classList.remove('hub-consent-open');
		}

		function syncManageButton() {
			if (btnManage) {
				btnManage.hidden = isOpen() || consentApi.getStatus() === 'unknown';
			}
		}

		function open() {
			if (isOpen()) {
				return;
			}
			returnFocus = document.activeElement;
			overlay.hidden = false;
			lockBackground();
			syncManageButton();
			// Foco no diálogo, não em um botão: nenhuma opção fica pré-selecionada.
			modal.focus();
		}

		function close() {
			overlay.hidden = true;
			unlockBackground();
			syncManageButton();
			if (returnFocus && typeof returnFocus.focus === 'function' && document.body.contains(returnFocus)) {
				returnFocus.focus();
			}
			returnFocus = null;
		}

		function choose(status) {
			var previous = consentApi.getStatus();
			consentApi.update(status);
			close();
			// Tags e pixel já carregados com consentimento só param recarregando a página.
			if (previous === 'granted' && status !== 'granted') {
				window.location.reload();
			}
		}

		consentApi.open = open;

		btnAccept.addEventListener('click', function() {
			choose('granted');
		});
		btnReject.addEventListener('click', function() {
			choose('denied');
		});
		if (btnManage) {
			btnManage.addEventListener('click', open);
		}

		overlay.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				// Sem escolha válida, o modal só fecha por uma das duas opções.
				if (consentApi.getStatus() !== 'unknown') {
					e.preventDefault();
					close();
				}
				return;
			}
			if (e.key !== 'Tab') {
				return;
			}
			var focusable = Array.prototype.filter.call(
				modal.querySelectorAll('button, a[href]'),
				function(el) { return !el.hidden && el.offsetParent !== null; }
			);
			if (!focusable.length) {
				return;
			}
			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (e.shiftKey && (document.activeElement === first || document.activeElement === modal)) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		});

		// Links ou botões externos (ex.: na política de privacidade) reabrem as preferências.
		document.addEventListener('click', function(e) {
			var target = e.target && e.target.closest ? e.target.closest('.hub-reset-consent') : null;
			if (target) {
				e.preventDefault();
				open();
			}
		});

		// Na página da política o visitante precisa conseguir lê-la antes de escolher.
		if (consentApi.getStatus() === 'unknown' && !overlay.hasAttribute('data-hub-policy-page')) {
			open();
		} else if (btnManage) {
			btnManage.hidden = false;
		}
	});

	// Se já houver consentimento na carga da página, re-atesta para o Google Tags disparar corretamente
	if (consentApi.hasConsent()) {
		if (typeof gtag === 'function') {
			gtag('consent', 'update', {
				'ad_storage': 'granted',
				'analytics_storage': 'granted',
				'ad_user_data': 'granted',
				'ad_personalization': 'granted'
			});
		}
	}

	return consentApi;
})();
