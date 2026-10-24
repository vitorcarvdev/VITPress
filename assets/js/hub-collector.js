/**
 * VitPress - Captador de Leads Conversacional (Vanilla JS)
 */
(function () {
	'use strict';

	var config = window.HubCollectorConfig || null;
	if (!config) {
		return;
	}

	var SESSION_KEY = 'hub_collector_session';
	var SESSION_MAX_AGE = 2 * 60 * 60 * 1000; // 2 horas

	// --- Utilitários ---
	function generateUUID() {
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0,
				v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	function getTimeString() {
		var now = new Date();
		var hours = String(now.getHours()).padStart(2, '0');
		var minutes = String(now.getMinutes()).padStart(2, '0');
		return hours + ':' + minutes;
	}

	function escapeHtml(text) {
		if (!text) return '';
		return String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	// --- Gerenciamento de Sessão (sessionStorage com expiração) ---
	function getSession() {
		try {
			var raw = sessionStorage.getItem(SESSION_KEY);
			if (raw) {
				var data = JSON.parse(raw);
				if (data.lastActivity && Date.now() - data.lastActivity < SESSION_MAX_AGE) {
					return data;
				}
			}
		} catch (e) {}

		var newSession = {
			uuid: generateUUID(),
			startedAt: Date.now(),
			lastActivity: Date.now(),
			isOpen: false,
			hasClosedManually: false,
			completed: false,
			leadId: null,
			answers: {},
			currentStepId: config.flow && config.flow[0] ? config.flow[0].id : null,
			history: [],
		};
		saveSession(newSession);
		return newSession;
	}

	function saveSession(session) {
		session.lastActivity = Date.now();
		try {
			sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
		} catch (e) {}
	}

	var session = getSession();

	// --- Interpolação de Variáveis ---
	function interpolate(text) {
		if (!text) return '';
		var name = '';
		for (var k in session.answers) {
			if (session.answers[k].mapping === 'name' && session.answers[k].value) {
				name = session.answers[k].value.trim();
				break;
			}
		}
		return text.replace(/\{nome\}/gi, name || '');
	}

	// --- Construção do DOM ---
	var root = document.getElementById('hub-lead-collector-root');
	if (!root) {
		root = document.createElement('div');
		root.id = 'hub-lead-collector-root';
		document.body.appendChild(root);
	}
	root.className = 'position-' + (config.position || 'right');
	if (config.primary_color) {
		root.style.setProperty('--hub-col-primary', config.primary_color);
	}

	// 1. Launcher (Botão Flutuante)
	var launcherWrap = document.createElement('div');
	launcherWrap.className = 'hub-collector-launcher-wrap';

	var launcherBtn = document.createElement('button');
	launcherBtn.className = 'hub-collector-launcher';
	launcherBtn.setAttribute('aria-label', 'Abrir chat de atendimento');

	if (config.attendant_avatar_url) {
		var avatarImg = document.createElement('img');
		avatarImg.className = 'hub-collector-launcher-avatar';
		avatarImg.src = config.attendant_avatar_url;
		avatarImg.alt = config.attendant_name || 'Atendente';
		launcherBtn.appendChild(avatarImg);
	} else {
		launcherBtn.innerHTML =
			'<svg class="hub-collector-launcher-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>';
	}
	launcherWrap.appendChild(launcherBtn);
	root.appendChild(launcherWrap);

	// 2. Janela do Chat
	var chatWindow = document.createElement('div');
	chatWindow.className = 'hub-collector-window hidden';

	// Header
	var header = document.createElement('div');
	header.className = 'hub-collector-header';
	header.innerHTML =
		'<div class="hub-collector-attendant-info">' +
		'  <div class="hub-collector-avatar-wrap">' +
		(config.attendant_avatar_url
			? '<img class="hub-collector-avatar" src="' + escapeHtml(config.attendant_avatar_url) + '" alt="' + escapeHtml(config.attendant_name) + '">'
			: '<div class="hub-collector-avatar" style="display:flex;align-items:center;justify-content:center;color:#fff;font-weight:bold;">' + escapeHtml(config.attendant_name ? config.attendant_name.charAt(0) : 'H') + '</div>') +
		'    <span class="hub-collector-online-dot"></span>' +
		'  </div>' +
		'  <div>' +
		'    <div class="hub-collector-attendant-name">' + escapeHtml(config.attendant_name || 'Larissa') + '</div>' +
		'    <div class="hub-collector-attendant-role">' + escapeHtml(config.attendant_role || 'Especialista em Projetos') + '</div>' +
		'  </div>' +
		'</div>' +
		'<div class="hub-collector-actions">' +
		'  <button type="button" class="hub-collector-btn-ctrl hub-btn-minimize" aria-label="Minimizar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="4" y1="12" x2="20" y2="12"></line></svg></button>' +
		'  <button type="button" class="hub-collector-btn-ctrl hub-btn-close" aria-label="Fechar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>' +
		'</div>';
	chatWindow.appendChild(header);

	// Body (Mensagens)
	var body = document.createElement('div');
	body.className = 'hub-collector-body';
	chatWindow.appendChild(body);

	// Footer (Input & Envio)
	var footer = document.createElement('div');
	footer.className = 'hub-collector-footer';
	chatWindow.appendChild(footer);

	root.appendChild(chatWindow);

	// --- Funções de Renderização das Mensagens ---
	function scrollToBottom() {
		setTimeout(function () {
			body.scrollTop = body.scrollHeight;
		}, 50);
	}

	function appendBotMessage(text, callback) {
		var typing = document.createElement('div');
		typing.className = 'hub-collector-typing';
		typing.innerHTML = '<span class="hub-collector-dot"></span><span class="hub-collector-dot"></span><span class="hub-collector-dot"></span>';
		body.appendChild(typing);
		scrollToBottom();

		var roleEl = chatWindow.querySelector('.hub-collector-attendant-role');
		var originalRole = roleEl ? roleEl.textContent : '';
		if (roleEl) {
			roleEl.textContent = 'digitando...';
		}

		setTimeout(function () {
			if (typing.parentNode) {
				typing.parentNode.removeChild(typing);
			}
			if (roleEl) {
				roleEl.textContent = originalRole;
			}

			var row = document.createElement('div');
			row.className = 'hub-collector-msg-row bot';
			row.innerHTML =
				'<div class="hub-collector-bubble">' +
				escapeHtml(interpolate(text)).replace(/\n/g, '<br>') +
				'<span class="hub-collector-time">' + getTimeString() + '</span>' +
				'</div>';
			body.appendChild(row);
			scrollToBottom();

			session.history.push({ sender: 'bot', text: text, time: getTimeString() });
			saveSession(session);

			if (callback) callback();
		}, 4000);
	}

	function appendUserMessage(text) {
		var row = document.createElement('div');
		row.className = 'hub-collector-msg-row user';
		row.innerHTML =
			'<div class="hub-collector-bubble">' +
			escapeHtml(text).replace(/\n/g, '<br>') +
			'<span class="hub-collector-time">' + getTimeString() + ' <span class="hub-collector-checks">✓✓</span></span>' +
			'</div>';
		body.appendChild(row);
		scrollToBottom();

		session.history.push({ sender: 'user', text: text, time: getTimeString() });
		saveSession(session);
	}

	// --- Renderiza o Controle de Entrada no Footer baseado no Passo Ativo ---
	function renderFooterControl(step) {
		footer.innerHTML = '';
		if (!step || session.completed) {
			renderCompletedFooter();
			return;
		}

		if (step.type === 'single_choice' || step.type === 'boolean') {
			var optionsWrap = document.createElement('div');
			optionsWrap.className = 'hub-collector-options-wrap';

			var opts = step.options || [];
			if (step.type === 'boolean' && opts.length === 0) {
				opts = [
					{ label: 'Sim', value: 'Sim' },
					{ label: 'Não', value: 'Não' },
				];
			}

			opts.forEach(function (opt) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'hub-collector-opt-btn';
				btn.textContent = opt.label;
				btn.addEventListener('click', function () {
					handleAnswer(step, opt.value, opt.next_step_id);
				});
				optionsWrap.appendChild(btn);
			});

			footer.appendChild(optionsWrap);
		} else {
			var inputRow = document.createElement('div');
			inputRow.className = 'hub-collector-input-row';

			var inputEl;
			if (step.type === 'textarea') {
				inputEl = document.createElement('textarea');
				inputEl.className = 'hub-collector-input hub-collector-textarea';
				inputEl.rows = 2;
			} else {
				inputEl = document.createElement('input');
				inputEl.type = step.type === 'email' ? 'email' : (step.type === 'phone' ? 'tel' : 'text');
				inputEl.className = 'hub-collector-input';
			}

			inputEl.placeholder = step.placeholder || 'Digite sua mensagem...';
			inputEl.required = !!step.required;

			// Máscara para Telefone
			if (step.type === 'phone') {
				inputEl.addEventListener('input', function (e) {
					var v = e.target.value.replace(/\D/g, '');
					if (v.length > 11) v = v.substring(0, 11);
					if (v.length > 6) {
						e.target.value = '(' + v.substring(0, 2) + ') ' + v.substring(2, 7) + '-' + v.substring(7);
					} else if (v.length > 2) {
						e.target.value = '(' + v.substring(0, 2) + ') ' + v.substring(2);
					} else if (v.length > 0) {
						e.target.value = '(' + v;
					}
				});
			}

			var sendBtn = document.createElement('button');
			sendBtn.type = 'button';
			sendBtn.className = 'hub-collector-send-btn';
			sendBtn.innerHTML = '<svg style="width:18px;height:18px;fill:#fff;margin-left:2px;" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

			function submitInput() {
				var val = inputEl.value.trim();
				if (!val && step.required) {
					inputEl.focus();
					return;
				}
				if (step.type === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
					alert('Por favor, informe um e-mail válido.');
					inputEl.focus();
					return;
				}
				handleAnswer(step, val, step.next_step_id);
			}

			sendBtn.addEventListener('click', submitInput);
			inputEl.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) {
					e.preventDefault();
					submitInput();
				}
			});

			inputRow.appendChild(inputEl);
			inputRow.appendChild(sendBtn);
			footer.appendChild(inputRow);

			setTimeout(function () {
				inputEl.focus();
			}, 300);
		}

		// LGPD Privacy Notice
		if (config.show_privacy_policy && config.privacy_policy_url) {
			var privacyEl = document.createElement('div');
			privacyEl.className = 'hub-collector-privacy-note';
			privacyEl.innerHTML = 'Ao enviar, você concorda com nossa <a href="' + escapeHtml(config.privacy_policy_url) + '" target="_blank" rel="noopener">Política de Privacidade</a>.';
			footer.appendChild(privacyEl);
		}
	}

	function renderCompletedFooter() {
		footer.innerHTML =
			'<div class="hub-collector-completed-box">' +
			'  <div>✓ Atendimento concluído! Nossa equipe entrará em contato em breve.</div>' +
			(session.whatsappUrl
				? '  <button type="button" class="hub-collector-btn-whatsapp" style="margin-top:6px;background:var(--hub-col-primary);border:0;color:#ffffff;padding:6px 14px;border-radius:12px;font-size:12px;cursor:pointer;font-weight:600;">Continuar no WhatsApp</button>'
				: '') +
			'  <button type="button" class="hub-collector-btn-restart" style="margin-top:6px;background:#ffffff;border:1px solid var(--hub-col-primary);color:var(--hub-col-primary);padding:4px 12px;border-radius:12px;font-size:11.5px;cursor:pointer;font-weight:600;">Iniciar Novo Atendimento</button>' +
			'</div>';

		var restartBtn = footer.querySelector('.hub-collector-btn-restart');
		if (restartBtn) {
			restartBtn.addEventListener('click', function () {
				try {
					sessionStorage.removeItem(SESSION_KEY);
				} catch (e) {}
				session = getSession();
				body.innerHTML = '';
				footer.innerHTML = '';
				openChat();
			});
		}
	}

	// --- Processamento de Respostas e Avanço do Fluxo ---
	function handleAnswer(step, value, explicitNextStepId) {
		appendUserMessage(value);

		session.answers[step.id] = {
			value: value,
			mapping: step.mapping || 'custom',
			label: step.message,
		};
		saveSession(session);

		footer.innerHTML = ''; // Limpa footer enquanto processa

		var nextStep = null;
		if (explicitNextStepId) {
			nextStep = config.flow.find(function (s) {
				return s.id === explicitNextStepId;
			});
		}

		if (!nextStep) {
			var currentIndex = config.flow.findIndex(function (s) {
				return s.id === step.id;
			});
			if (currentIndex >= 0 && currentIndex + 1 < config.flow.length) {
				nextStep = config.flow[currentIndex + 1];
			}
		}

		if (nextStep) {
			session.currentStepId = nextStep.id;
			saveSession(session);
			appendBotMessage(nextStep.message, function () {
				renderFooterControl(nextStep);
			});
		} else {
			// Fim do fluxo -> Submeter Lead ao Hub
			submitLead();
		}
	}

	// --- Envio Final para REST API ---
	// A URL vai para o lead e o VitAds; identificadores de clique só seguem pelos cookies com consentimento.
	function pageUrlWithoutClickIds() {
		var url = window.location.origin + window.location.pathname;
		var kept = window.location.search.replace(/^\?/, '').split('&').filter(function(pair) {
			return pair !== '' && ['gclid', 'gbraid', 'wbraid', 'fbclid'].indexOf(pair.split('=')[0].toLowerCase()) === -1;
		});
		return url + (kept.length ? '?' + kept.join('&') : '');
	}

	function submitLead() {
		var typing = document.createElement('div');
		typing.className = 'hub-collector-typing';
		typing.innerHTML = '<span class="hub-collector-dot"></span><span class="hub-collector-dot"></span><span class="hub-collector-dot"></span>';
		body.appendChild(typing);
		scrollToBottom();

		var payload = {
			submission_uuid: session.uuid,
			time_spent: Math.max(1, Math.floor((Date.now() - session.startedAt) / 1000)),
			answers: session.answers,
			conversion_page: pageUrlWithoutClickIds(),
			hub_collector_hp: '',
			ad_user_data_consent: window.HubConsent && typeof window.HubConsent.getStatus === 'function' ? window.HubConsent.getStatus() : 'unknown',
		};

		footer.innerHTML = '';

		// A mesma session.uuid vai em toda tentativa: o servidor e o VitAds tratam a repetição como a mesma submissão.
		fetch(config.api_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload),
		})
			.then(function (res) {
				return res.json().then(function (data) {
					return { ok: res.ok, data: data || {} };
				}, function () {
					return { ok: false, data: {} };
				});
			})
			.then(function (result) {
				if (typing.parentNode) {
					typing.parentNode.removeChild(typing);
				}
				var data = result.data;

				// Falha de registro: nada de falso sucesso nem redirecionamento. O visitante pode tentar de novo.
				if (!result.ok || !data.success) {
					showSubmitFailure(data.message);
					return;
				}

				session.completed = true;
				session.leadId = data.lead_id || null;
				session.whatsappUrl = data.redirect_allowed && data.whatsapp_url ? data.whatsapp_url : '';
				saveSession(session);

				if (badgeTimer) clearTimeout(badgeTimer);
				if (autoOpenTimer) clearTimeout(autoOpenTimer);

				// Lead novo conta como conversão no navegador; contato que já existia (nova interação) não conta de novo.
				if (!data.contact_existing) {
					if (typeof window.fbq === 'function' && data.event_id) {
						window.fbq('track', 'Lead', {}, { eventID: data.event_id });
					}
					if (typeof window.gtag === 'function') {
						window.gtag('event', 'generate_lead', { value: 0, currency: 'BRL' });
					}
				}

				appendBotMessage(config.final_message || 'Muito obrigado! Recebemos suas informações.', function () {
					renderCompletedFooter();
					if (session.whatsappUrl) {
						scheduleWhatsAppRedirect(session.whatsappUrl);
					}
				});
			})
			.catch(function () {
				if (typing.parentNode) {
					typing.parentNode.removeChild(typing);
				}
				showSubmitFailure();
			});
	}

	function showSubmitFailure(message) {
		appendBotMessage(
			message || 'Não conseguimos concluir o registro agora. Suas respostas estão guardadas neste chat; toque em “Tentar novamente”.',
			function () {
				footer.innerHTML =
					'<div class="hub-collector-completed-box">' +
					'  <button type="button" class="hub-collector-btn-retry" style="background:var(--hub-col-primary);border:0;color:#ffffff;padding:6px 14px;border-radius:12px;font-size:12px;cursor:pointer;font-weight:600;">Tentar novamente</button>' +
					'</div>';
				var retryBtn = footer.querySelector('.hub-collector-btn-retry');
				if (retryBtn) {
					retryBtn.addEventListener('click', function () {
						submitLead();
					});
				}
			}
		);
	}

	// O redirecionamento só é oferecido depois que o registro foi confirmado. Ele NÃO prova que a mensagem foi enviada.
	function goToWhatsApp(url) {
		try {
			if (config.redirected_url && navigator.sendBeacon) {
				navigator.sendBeacon(config.redirected_url, new Blob([JSON.stringify({ submission_uuid: session.uuid })], { type: 'application/json' }));
			}
		} catch (e) {}
		window.location.href = url;
	}

	function scheduleWhatsAppRedirect(url) {
		var btn = footer.querySelector('.hub-collector-btn-whatsapp');
		if (btn) {
			btn.addEventListener('click', function () {
				goToWhatsApp(url);
			});
		}
		setTimeout(function () {
			if (session.completed && !session.redirected) {
				session.redirected = true;
				saveSession(session);
				goToWhatsApp(url);
			}
		}, 3000);
	}

	// --- Variáveis de Timer ---
	var badgeTimer = null;
	var autoOpenTimer = null;

	// --- Controle de Abertura / Fechamento ---
	function openChat() {
		session.isOpen = true;
		saveSession(session);
		chatWindow.classList.remove('hidden');

		// Remove badge se presente
		var badge = launcherBtn.querySelector('.hub-collector-badge');
		if (badge) badge.remove();

		// Se o histórico estiver vazio, inicia a primeira pergunta
		if (session.history.length === 0 && config.flow && config.flow.length > 0) {
			var firstStep = config.flow[0];
			session.currentStepId = firstStep.id;
			saveSession(session);
			appendBotMessage(firstStep.message, function () {
				renderFooterControl(firstStep);
			});
		} else {
			// Restaura histórico
			body.innerHTML = '';
			session.history.forEach(function (msg) {
				var row = document.createElement('div');
				row.className = 'hub-collector-msg-row ' + msg.sender;
				row.innerHTML =
					'<div class="hub-collector-bubble">' +
					escapeHtml(msg.text).replace(/\n/g, '<br>') +
					'<span class="hub-collector-time">' + (msg.time || '') + (msg.sender === 'user' ? ' <span class="hub-collector-checks">✓✓</span>' : '') + '</span>' +
					'</div>';
				body.appendChild(row);
			});

			var currentStep = config.flow.find(function (s) {
				return s.id === session.currentStepId;
			}) || config.flow[0];

			renderFooterControl(currentStep);
			scrollToBottom();
		}
	}

	function closeChat() {
		session.isOpen = false;
		session.hasClosedManually = true;
		saveSession(session);
		chatWindow.classList.add('hidden');

		if (badgeTimer) clearTimeout(badgeTimer);
		if (autoOpenTimer) clearTimeout(autoOpenTimer);
	}

	// --- Event Listeners ---
	launcherBtn.addEventListener('click', function () {
		if (chatWindow.classList.contains('hidden')) {
			openChat();
		} else {
			closeChat();
		}
	});

	header.querySelector('.hub-btn-minimize').addEventListener('click', closeChat);
	header.querySelector('.hub-btn-close').addEventListener('click', closeChat);

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && session.isOpen) {
			closeChat();
		}
	});

	// --- Comportamento Progressivo de Timers (JavaScript Local) ---
	if (!session.completed && !session.hasClosedManually) {
		// T = badge_delay (ex: 10s)
		if (config.badge_delay > 0) {
			badgeTimer = setTimeout(function () {
				if (!session.isOpen && !launcherBtn.querySelector('.hub-collector-badge')) {
					var badge = document.createElement('span');
					badge.className = 'hub-collector-badge';
					badge.textContent = '1';
					launcherBtn.appendChild(badge);
				}
			}, config.badge_delay * 1000);
		}

		// T = auto_open_delay (ex: 20s)
		if (config.auto_open_enabled && config.auto_open_delay > 0) {
			autoOpenTimer = setTimeout(function () {
				// Re-verifica no callback por segurança
				var currentSession = getSession();
				if (!currentSession.isOpen && !currentSession.hasClosedManually && !currentSession.completed) {
					openChat();
				}
			}, config.auto_open_delay * 1000);
		}
	}

	// Restaura estado se já estava aberto antes do reload/navegação
	if (session.isOpen) {
		openChat();
	}
})();