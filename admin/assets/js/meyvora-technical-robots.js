/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-technical-robots.js)
*/
(function () {
	"use strict";
	var cfg = window.meyvoraTechnicalRobots || {};
	var nonce = cfg.nonce || "";
	var ajaxUrl = cfg.ajaxUrl || "";
	var defaultTemplate = cfg.defaultTemplate || "";
	var L = cfg.i18nTest || {};

	var textarea = document.getElementById("meyvora_robots_txt");
	var preview = document.getElementById("mev-robots-preview");
	var loadBtn = document.getElementById("mev-robots-load-default");
	var testPath = document.getElementById("mev-test-url-path");
	var testBtn = document.getElementById("mev-test-url-btn");
	var testResult = document.getElementById("mev-test-url-result");

	function highlightRobots(txt) {
		var out = "";
		var lines = txt.split("\n");
		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			var m = line.match(/^(User-agent\s*:)(.*)$/i);
			if (m) {
				out += '<span class="mev-robots-ua">' + escapeHtml(m[1]) + "</span>" + escapeHtml(m[2]) + "\n";
				continue;
			}
			m = line.match(/^(Allow|Disallow|Sitemap)\s*:(.*)$/i);
			if (m) {
				out += '<span class="mev-robots-directive">' + escapeHtml(m[1]) + "</span>: " + escapeHtml(m[2].trim()) + "\n";
				continue;
			}
			out += escapeHtml(line) + "\n";
		}
		return out;
	}
	function escapeHtml(s) {
		var div = document.createElement("div");
		div.textContent = s;
		return div.innerHTML;
	}
	function updatePreview() {
		if (!preview || !textarea) {
			return;
		}
		preview.innerHTML = highlightRobots(textarea.value);
	}
	if (textarea) {
		textarea.addEventListener("input", updatePreview);
		updatePreview();
	}
	if (loadBtn && textarea) {
		loadBtn.addEventListener("click", function () {
			textarea.value = defaultTemplate;
			updatePreview();
		});
	}
	var restoreBtn = document.getElementById("meyvora_robots_restore");
	if (restoreBtn) {
		restoreBtn.addEventListener("click", function () {
			if (!confirm(this.getAttribute("data-confirm"))) {
				return;
			}
			var sitemapUrl = this.getAttribute("data-sitemap");
			var defaultContent = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nSitemap: " + sitemapUrl;
			var ta = document.getElementById("meyvora_robots_txt");
			if (ta) {
				ta.value = defaultContent;
			}
			if (preview) {
				updatePreview();
			}
		});
	}
	if (testBtn && testPath && testResult && textarea) {
		testBtn.addEventListener("click", function () {
			var path = testPath.value.trim() || "/";
			var robots = textarea ? textarea.value : "";
			testResult.textContent = "";
			testResult.className = "";
			var formData = new FormData();
			formData.append("action", "meyvora_technical_test_url");
			formData.append("nonce", nonce);
			formData.append("path", path);
			formData.append("robots", robots);
			fetch(ajaxUrl, {
				method: "POST",
				body: formData,
				credentials: "same-origin",
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (res.success && res.data) {
						testResult.textContent = res.data.allowed ? L.allowedLabel || "" : L.disallowedLabel || "";
						testResult.className = res.data.allowed ? "mev-test-allowed" : "mev-test-disallowed";
					} else {
						testResult.textContent = L.errorLabel || "Error";
					}
				})
				.catch(function () {
					testResult.textContent = L.errorLabel || "Error";
				});
		});
	}
})();
