/**
 * JavaScript Nativo para o Plugin VitPress (WordPress Admin)
 */
(function($) {
	"use strict";

	$(document).ready(function() {

		// Onboarding Perfil Selection
		$(".hub-profile-option input[type=\"radio\"]").on("change", function() {
			$(".hub-profile-option").removeClass("active");
			$(this).closest(".hub-profile-option").addClass("active");
		});

		$(".hub-profile-option input[type=\"radio\"]:checked").closest(".hub-profile-option").addClass("active");

		// Pipeline: Modal de Detalhes do Lead (Somente Leitura)
		$(document).on("click", ".hub-lead-card", function(e) {
			var rawData = $(this).attr("data-lead");
			if (!rawData) return;

			var card = null;
			try {
				card = JSON.parse(rawData);
			} catch (err) {
				return;
			}

			if (!card) return;

			// Cores dos Estágios
			var stageColors = {
				'presentation': '#64b5f6',
				'negotiating': '#00a884',
				'won': '#4caf50',
				'lost': '#fbc02d',
				'disqualified': '#f44336'
			};

			var stageColor = stageColors[card.stage] || '#64748b';

			$("#hub-modal-lead-name").text(card.name || 'Lead');
			$("#hub-btn-delete-lead").attr("data-id", card.id);
			$("#hub-modal-lead-stage-badge")
				.text(card.stage_label || 'Novo Interessado')
				.css({
					'background-color': stageColor,
					'color': '#ffffff'
				});

			$("#hub-modal-lead-date").text(card.formatted_date + " (" + card.relative_age + ")");
			$("#hub-modal-phone").text(card.formatted_phone || '-');
			$("#hub-modal-email").text(card.email || '-');

			$("#hub-modal-att-status")
				.text(card.attendance ? card.attendance.status_text : '-')
				.css('color', card.attendance ? card.attendance.color : '#1e293b');

			$("#hub-modal-att-count").text(
				card.attendance && card.attendance.badge_text
					? card.attendance.badge_text
					: (card.attempts_count + " tentativa(s)")
			);

			$("#hub-modal-source").text(card.source_label || 'Direto');

			var campaignInfo = [];
			if (card.tracking) {
				if (card.tracking.utm_campaign) campaignInfo.push("Campanha: " + card.tracking.utm_campaign);
				if (card.tracking.utm_source) campaignInfo.push("Origem: " + card.tracking.utm_source);
				if (card.tracking.utm_medium) campaignInfo.push("Mídia: " + card.tracking.utm_medium);
				if (card.tracking.device_type) campaignInfo.push("Disp: " + card.tracking.device_type);
				if (card.tracking.gclid) campaignInfo.push("GCLID: Sim");
				if (card.tracking.fbclid) campaignInfo.push("FBCLID: Sim");
			}
			$("#hub-modal-campaign").text(campaignInfo.length > 0 ? campaignInfo.join(" • ") : '-');

			// Respostas do Atendente Virtual
			var $captadorWrap = $("#hub-modal-captador-wrap");
			var $captadorList = $("#hub-modal-captador-list");
			$captadorList.empty();

			if (card.captador_answers && Object.keys(card.captador_answers).length > 0) {
				for (var stepKey in card.captador_answers) {
					var ans = card.captador_answers[stepKey];
					if (ans && ans.question) {
						var $row = $('<div class="hub-modal-captador-item"></div>');
						$row.append('<div class="hub-captador-q">💬 ' + $("<div>").text(ans.question).html() + '</div>');
						$row.append('<div class="hub-captador-a">↳ <strong>' + $("<div>").text(ans.value || '-').html() + '</strong></div>');
						$captadorList.append($row);
					}
				}
				$captadorWrap.show();
			} else {
				$captadorWrap.hide();
			}

			// Observações
			var $notesWrap = $("#hub-modal-notes-wrap");
			if (card.notes && card.notes.trim() !== "") {
				$("#hub-modal-notes").text(card.notes);
				$notesWrap.show();
			} else {
				$notesWrap.hide();
			}

			// Detalhes Comerciais (Perda ou Venda)
			var $commercialWrap = $("#hub-modal-commercial-wrap");
			var $commercialInfo = $("#hub-modal-commercial-info");
			$commercialInfo.empty();

			if (card.stage === "perdido" && card.lost_reason) {
				$commercialInfo.html('<span style="color:#dc2626; font-weight:600;">Motivo da Perda:</span> ' + $("<div>").text(card.lost_reason).html());
				$commercialWrap.show();
			} else if (card.stage === "cliente" && card.revenue_value > 0) {
				$commercialInfo.html('<span style="color:#16a34a; font-weight:600;">Valor Fechado:</span> R$ ' + card.revenue_value.toLocaleString('pt-BR', { minimumFractionDigits: 2 }));
				$commercialWrap.show();
			} else {
				$commercialWrap.hide();
			}

			$("#hub-lead-details-modal").css("display", "flex");
		});

		function closeLeadDetailsModal() {
			$("#hub-lead-details-modal").hide();
		}

		$("#hub-btn-close-lead-modal, #hub-btn-modal-footer-close").on("click", closeLeadDetailsModal);

		$("#hub-btn-delete-lead").on("click", function() {
			var leadId = $(this).attr("data-id");
			if (!leadId) return;

			if (!confirm("Excluir este lead?\n\nEle será removido do Pipeline. Esta ação deve ser usada apenas para registros de teste, duplicados ou criados incorretamente.")) {
				return;
			}

			var $btn = $(this);
			var originalText = $btn.text();
			$btn.text("Excluindo...").prop("disabled", true);

			if (typeof HubAdmin === "undefined" || !HubAdmin.ajax_url || !HubAdmin.nonce) {
				alert("Não foi possível excluir este lead. Recarregue a página e tente novamente.");
				$btn.text(originalText).prop("disabled", false);
				return;
			}

			$.ajax({
				url: HubAdmin.ajax_url,
				type: "POST",
				dataType: "json",
				timeout: 20000,
				data: {
					action: "hub_delete_lead",
					security: HubAdmin.nonce,
					lead_id: leadId
				},
				success: function(res) {
					if (res && res.success) {
						closeLeadDetailsModal();
						window.location.reload();
						return;
					}
					$btn.text(originalText).prop("disabled", false);
					var message = "Não foi possível excluir o lead.";
					if (res && typeof res.data === "string" && res.data) {
						message = res.data;
					}
					alert(message);
				},
				error: function() {
					$btn.text(originalText).prop("disabled", false);
					alert("Não foi possível excluir o lead. Tente novamente.");
				}
			});
		});

		$("#hub-lead-details-modal").on("click", function(e) {
			if ($(e.target).hasClass("hub-details-modal-overlay")) {
				closeLeadDetailsModal();
			}
		});

		$(document).on("keydown", function(e) {
			if (e.key === "Escape" && $("#hub-lead-details-modal").is(":visible")) {
				closeLeadDetailsModal();
			}
		});

	});

})(jQuery);

