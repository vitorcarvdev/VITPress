(function () {
	var config = window.vitpressPipeline || {};
	var chips = document.getElementById('vp-chips');
	var cards = document.getElementById('vp-cards');
	var empty = document.getElementById('vp-empty');
	var search = document.getElementById('vp-search');
	var listScreen = document.getElementById('vp-list-screen');
	var detail = document.getElementById('vp-detail');
	var installBox = document.getElementById('vp-install');
	var installBtn = document.getElementById('vp-install-btn');
	var installText = document.getElementById('vp-install-text');
	var current = null;
	var deferredPrompt = null;

	function activeStage() {
		var chip = chips.querySelector('.vp-chip.is-active');
		return chip ? chip.getAttribute('data-stage') : 'presentation';
	}

	function applyFilter() {
		var stage = activeStage();
		var term = (search.value || '').trim().toLowerCase();
		var visible = 0;
		cards.querySelectorAll('.vp-card').forEach(function (card) {
			var matchesStage = card.getAttribute('data-stage') === stage;
			var matchesTerm = !term || (card.getAttribute('data-search') || '').indexOf(term) !== -1;
			var show = matchesStage && matchesTerm;
			card.hidden = !show;
			if (show) visible += 1;
		});
		empty.hidden = visible !== 0;
	}

	chips.addEventListener('click', function (event) {
		var chip = event.target.closest('.vp-chip');
		if (!chip) return;
		chips.querySelectorAll('.vp-chip').forEach(function (item) {
			var on = item === chip;
			item.classList.toggle('is-active', on);
			item.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		applyFilter();
	});
	search.addEventListener('input', applyFilter);

	function openCard(button) {
		current = button.closest('.vp-card');
		document.getElementById('vp-detail-name').textContent = button.getAttribute('data-name') || '';
		document.getElementById('vp-detail-phone').textContent = button.getAttribute('data-phone') || '';
		document.getElementById('vp-detail-email').textContent = button.getAttribute('data-email') || '';
		var wa = button.getAttribute('data-wa') || '';
		var waWrap = document.getElementById('vp-detail-wa-wrap');
		var waLink = document.getElementById('vp-detail-wa');
		if (wa) {
			waLink.href = 'https://wa.me/' + wa;
			waWrap.hidden = false;
		} else {
			waWrap.hidden = true;
		}
		var stage = button.getAttribute('data-stage');
		document.querySelectorAll('#vp-stage-options input').forEach(function (input) {
			input.checked = input.value === stage;
		});
		document.getElementById('vp-error').hidden = true;
		listScreen.hidden = true;
		detail.hidden = false;
	}

	cards.addEventListener('click', function (event) {
		var button = event.target.closest('.vp-open');
		if (!button) return;
		openCard(button);
	});

	document.getElementById('vp-back').addEventListener('click', function () {
		detail.hidden = true;
		listScreen.hidden = false;
		current = null;
	});

	document.getElementById('vp-stage-form').addEventListener('submit', function (event) {
		event.preventDefault();
		if (!current) return;
		var button = current.querySelector('.vp-open');
		var selected = document.querySelector('#vp-stage-options input:checked');
		var error = document.getElementById('vp-error');
		error.hidden = true;
		if (!selected) return;
		var save = document.getElementById('vp-save');
		save.disabled = true;
		fetch(config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify({
				lead_id: parseInt(button.getAttribute('data-id'), 10),
				stage: selected.value
			})
		}).then(function (response) {
			return response.json().then(function (body) {
				return { ok: response.ok, body: body };
			});
		}).then(function (result) {
			if (!result.ok || !result.body || !result.body.success) {
				error.textContent = (result.body && result.body.message) || 'Não foi possível salvar a etapa.';
				error.hidden = false;
				return;
			}
			var stage = result.body.lead.stage;
			current.setAttribute('data-stage', stage);
			button.setAttribute('data-stage', stage);
			applyFilter();
			detail.hidden = true;
			listScreen.hidden = false;
			current = null;
		}).catch(function () {
			error.textContent = 'Não foi possível salvar a etapa.';
			error.hidden = false;
		}).finally(function () {
			save.disabled = false;
		});
	});

	window.addEventListener('beforeinstallprompt', function (event) {
		event.preventDefault();
		deferredPrompt = event;
		installText.textContent = 'Adicionar à tela inicial';
		installBox.hidden = false;
	});

	installBtn.addEventListener('click', function () {
		if (!deferredPrompt) return;
		deferredPrompt.prompt();
		deferredPrompt.userChoice.finally(function () {
			deferredPrompt = null;
			installBox.hidden = true;
		});
	});

	if (!window.matchMedia('(display-mode: standalone)').matches && !window.navigator.standalone) {
		window.setTimeout(function () {
			if (deferredPrompt || installBox.hidden === false) return;
			installText.textContent = 'No navegador, use o menu e escolha Adicionar à tela inicial.';
			installBtn.hidden = true;
			installBox.hidden = false;
		}, 1500);
	}

	if ('serviceWorker' in navigator && config.sw && window.location.protocol !== 'file:') {
		navigator.serviceWorker.register(config.sw, { scope: config.scope }).catch(function () {});
	}

	applyFilter();
})();
