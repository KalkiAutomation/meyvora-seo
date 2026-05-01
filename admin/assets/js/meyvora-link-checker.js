/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Link Checker: Fix modal and AJAX replace broken link.
 *
 * @package Meyvora_SEO
 */

(function () {
	"use strict";

	var cfg = typeof meyvoraLinkChecker !== "undefined" ? meyvoraLinkChecker : {};
	var ajaxUrl = cfg.ajaxUrl || "";
	var nonce = cfg.nonce || "";
	var i18n = cfg.i18n || {};

	var modal = document.getElementById("meyvora-link-checker-modal");
	var oldUrlEl = document.getElementById("meyvora-link-checker-old-url");
	var newUrlInput = document.getElementById("meyvora-link-checker-new-url");
	var saveBtn = modal && modal.querySelector(".meyvora-link-checker-modal-save");
	var cancelBtn = modal && modal.querySelector(".meyvora-link-checker-modal-cancel");
	var backdrop = modal && modal.querySelector(".mev-modal-backdrop");
	var currentCheckId = null;
	var currentRow = null;

	function openModal(checkId, oldUrl, rowEl) {
		currentCheckId = checkId;
		currentRow = rowEl;
		if (oldUrlEl) oldUrlEl.textContent = oldUrl;
		if (newUrlInput) {
			newUrlInput.value = "";
			newUrlInput.placeholder = "https://";
		}
		if (modal) modal.style.display = "";
		if (newUrlInput) newUrlInput.focus();
	}

	function closeModal() {
		currentCheckId = null;
		currentRow = null;
		if (modal) modal.style.display = "none";
	}

	function doFix() {
		if (!currentCheckId || !newUrlInput || !saveBtn) return;
		var newUrl = newUrlInput.value.trim();
		if (!newUrl) return;

		saveBtn.disabled = true;

		var formData = new FormData();
		formData.append("action", "meyvora_seo_link_checker_fix");
		formData.append("nonce", nonce);
		formData.append("check_id", currentCheckId);
		formData.append("new_url", newUrl);

		fetch(ajaxUrl, {
			method: "POST",
			body: formData,
			credentials: "same-origin",
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (data) {
				saveBtn.disabled = false;
				closeModal();
				if (data.success) {
					if (typeof window.mevToast === "function") {
						window.mevToast("Link fixed!", "success");
					}
					if (currentRow && currentRow.parentNode) {
						currentRow.parentNode.removeChild(currentRow);
					}
					// If no rows left, reload to show empty state
					var tbody = document.querySelector(".meyvora-link-checker-page tbody");
					if (tbody && tbody.querySelectorAll("tr").length === 0) {
						window.location.reload();
					}
				} else {
					alert((data.data && data.data.message) || i18n.error || "Something went wrong.");
				}
			})
			.catch(function () {
				saveBtn.disabled = false;
				alert(i18n.error || "Something went wrong.");
			});
	}

	document.addEventListener("DOMContentLoaded", function () {
		document.querySelectorAll(".meyvora-link-checker-fix").forEach(function (btn) {
			btn.addEventListener("click", function () {
				var checkId = parseInt(btn.getAttribute("data-check-id"), 10);
				var oldUrl = btn.getAttribute("data-old-url") || "";
				var row = btn.closest("tr");
				openModal(checkId, oldUrl, row);
			});
		});

		if (saveBtn) saveBtn.addEventListener("click", doFix);
		if (cancelBtn) cancelBtn.addEventListener("click", closeModal);
		if (backdrop) backdrop.addEventListener("click", closeModal);
	});
})();
