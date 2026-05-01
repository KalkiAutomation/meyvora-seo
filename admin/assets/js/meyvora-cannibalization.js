/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Keyword Cannibalization: Run Scan and Set as Primary.
 *
 * @package Meyvora_SEO
 */

(function () {
	"use strict";

	var cfg = typeof meyvoraCannibalization !== "undefined" ? meyvoraCannibalization : {};
	var ajaxUrl = cfg.ajaxUrl || "";
	var nonce = cfg.nonce || "";
	var i18n = cfg.i18n || {};

	function getScanButtons() {
		var main = document.getElementById("mev-cannibalization-run-scan");
		var list = [];
		if (main) {
			list.push(main);
		}
		document.querySelectorAll(".mev-run-cannibalization-scan").forEach(function (b) {
			if (list.indexOf(b) === -1) {
				list.push(b);
			}
		});
		return list;
	}

	function runScan() {
		var btns = getScanButtons();
		if (!btns.length) {
			return;
		}
		var scanLabel = i18n.scanning || "Scanning…";
		btns.forEach(function (btn) {
			btn.disabled = true;
			btn.textContent = scanLabel;
		});

		var formData = new FormData();
		formData.append("action", "meyvora_seo_cannibalization_scan");
		formData.append("nonce", nonce);

		fetch(ajaxUrl, {
			method: "POST",
			body: formData,
			credentials: "same-origin",
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (data) {
				var reset = i18n.runScan || "Run Scan";
				btns.forEach(function (b) {
					b.disabled = false;
					b.textContent = reset;
				});
				if (data.success && data.data && data.data.redirect) {
					window.location.href = data.data.redirect;
				} else {
					alert(data.data && data.data.message ? data.data.message : i18n.error || "Something went wrong.");
				}
			})
			.catch(function () {
				var reset = i18n.runScan || "Run Scan";
				btns.forEach(function (b) {
					b.disabled = false;
					b.textContent = reset;
				});
				alert(i18n.error || "Something went wrong.");
			});
	}

	function setPrimary(postId, keyword, buttonEl) {
		if (!postId || !keyword || !buttonEl) return;
		buttonEl.disabled = true;

		var formData = new FormData();
		formData.append("action", "meyvora_seo_cannibalization_set_primary");
		formData.append("nonce", nonce);
		formData.append("post_id", postId);
		formData.append("keyword", keyword);

		fetch(ajaxUrl, {
			method: "POST",
			body: formData,
			credentials: "same-origin",
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (data) {
				if (data.success) {
					window.location.reload();
				} else {
					buttonEl.disabled = false;
					alert(data.data && data.data.message ? data.data.message : i18n.error || "Something went wrong.");
				}
			})
			.catch(function () {
				buttonEl.disabled = false;
				alert(i18n.error || "Something went wrong.");
			});
	}

	document.addEventListener("DOMContentLoaded", function () {
		getScanButtons().forEach(function (b) {
			b.addEventListener("click", runScan);
		});

		document.querySelectorAll(".mev-set-primary").forEach(function (btn) {
			btn.addEventListener("click", function () {
				var postId = parseInt(btn.getAttribute("data-post-id"), 10);
				var keyword = btn.getAttribute("data-keyword") || "";
				setPrimary(postId, keyword, btn);
			});
		});
	});
})();
