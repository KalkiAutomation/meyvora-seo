/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-rank-tracker-page.js)
*/
(function () {
	"use strict";
	var cfg = window.meyvoraRankTrackerPage || {};

	document.getElementById("mev-rank-track-now")?.addEventListener("click", function () {
		var btn = this;
		btn.disabled = true;
		btn.textContent = cfg.i18n?.running || "…";
		var fd = new FormData();
		fd.append("action", cfg.runAction || "");
		fd.append("nonce", cfg.runNonce || "");
		fetch(typeof ajaxurl !== "undefined" ? ajaxurl : cfg.ajaxUrl || "", {
			method: "POST",
			body: fd,
			credentials: "same-origin",
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				btn.disabled = false;
				btn.textContent = res && res.success ? cfg.i18n?.done || "" : cfg.i18n?.trackNow || "";
				if (res && res.success) {
					location.reload();
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = cfg.i18n?.trackNow || "";
			});
	});

	document.getElementById("meyvora-rank-tracker-run-now")?.addEventListener("click", function () {
		var btn = this;
		var url = btn.getAttribute("data-url");
		var nonce = btn.getAttribute("data-nonce");
		if (!url || !nonce) {
			return;
		}
		btn.disabled = true;
		btn.textContent = cfg.i18n?.running || "…";
		var fd = new FormData();
		fd.append("action", cfg.manualRunAction || "meyvora_seo_rank_tracker_manual_run");
		fd.append("nonce", nonce);
		fetch(url, { method: "POST", body: fd, credentials: "same-origin" })
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				btn.disabled = false;
				btn.textContent = cfg.i18n?.runNow || "";
				var msgOk = cfg.i18n?.runComplete || "";
				var msgErr = cfg.i18n?.error || "";
				if (res && res.success) {
					alert(res.data && res.data.message ? res.data.message : msgOk);
					location.reload();
				} else {
					alert(res.data && res.data.message ? res.data.message : msgErr);
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = cfg.i18n?.runNow || "";
				alert(cfg.i18n?.error || "");
			});
	});
})();
