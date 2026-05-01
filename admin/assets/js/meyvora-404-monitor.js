/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-404-monitor.js)
*/
(function () {
	"use strict";
	var cfg = window.meyvora404Monitor || {};
	var N = cfg.nonce || "";
	var CA = cfg.createAction || "";
	var EA = cfg.exportAction || "";
	var U = cfg.ajaxUrl || "";
	var L = cfg.i18n || {};

	document.getElementById("mev-404-export-csv")?.addEventListener("click", function () {
		window.location.href = U + "?action=" + EA + "&nonce=" + encodeURIComponent(N);
	});

	var searchInput = document.getElementById("mev-404-search");
	if (searchInput) {
		searchInput.addEventListener("input", function () {
			var q = this.value.toLowerCase().trim();
			document.querySelectorAll("#mev-404-table tbody tr").forEach(function (row) {
				row.style.display = !q || (row.dataset.search && row.dataset.search.indexOf(q) !== -1) ? "" : "none";
			});
		});
	}

	document.querySelectorAll(".mev-404-create-redirect").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var form = btn.closest("td").querySelector(".mev-404-inline-form");
			var isOpen = form.classList.contains("open");
			document.querySelectorAll(".mev-404-inline-form.open").forEach(function (f) {
				f.classList.remove("open");
			});
			if (!isOpen) {
				form.classList.add("open");
				form.querySelector(".mev-404-target").focus();
			}
		});
	});

	document.querySelectorAll(".mev-404-cancel-btn").forEach(function (btn) {
		btn.addEventListener("click", function () {
			btn.closest(".mev-404-inline-form").classList.remove("open");
		});
	});

	document.querySelectorAll(".mev-404-submit-btn").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var row = btn.closest("tr");
			var form = btn.closest(".mev-404-inline-form");
			var source = row.dataset.url;
			var target = form.querySelector(".mev-404-target").value.trim();
			var rowId = row.dataset.id;
			if (!target) {
				form.querySelector(".mev-404-target").style.borderColor = "var(--mev-danger)";
				return;
			}
			btn.disabled = true;
			btn.textContent = L.saving || "";
			var fd = new FormData();
			fd.append("action", CA);
			fd.append("nonce", N);
			fd.append("source_url", source);
			fd.append("target_url", target);
			fd.append("404_id", rowId);
			fetch(U, { method: "POST", body: fd, credentials: "same-origin" })
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (res && res.success) {
						row.classList.add("row-fixed");
						form.classList.remove("open");
						setTimeout(function () {
							row.remove();
						}, 1200);
					} else {
						btn.disabled = false;
						btn.textContent = L.add || "";
					}
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = L.add || "";
				});
		});
	});

	document.querySelectorAll(".mev-404-target").forEach(function (input) {
		input.addEventListener("keydown", function (e) {
			if (e.key === "Enter") {
				input.closest(".mev-404-inline-form").querySelector(".mev-404-submit-btn").click();
			}
			if (e.key === "Escape") {
				input.closest(".mev-404-inline-form").classList.remove("open");
			}
		});
		input.addEventListener("input", function () {
			this.style.borderColor = "";
		});
	});
})();
