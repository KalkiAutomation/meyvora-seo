/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-metabox-advanced.js)
*/
(function () {
	"use strict";
	var nosnippet = document.getElementById("meyvora_seo_nosnippet");
	var maxSnippet = document.getElementById("meyvora_seo_max_snippet");
	if (nosnippet && maxSnippet) {
		function toggle() {
			maxSnippet.disabled = nosnippet.checked;
		}
		nosnippet.addEventListener("change", toggle);
	}

	var cfg = window.meyvoraSeoMetaboxAdvanced || {};
	var ajaxUrl = cfg.ajaxUrl || window.ajaxurl || "/wp-admin/admin-ajax.php";
	var errFallback = cfg.i18n && cfg.i18n.genericError ? cfg.i18n.genericError : "Error";

	document.addEventListener("click", function (e) {
		var btn = e.target.closest("[data-ab-action]");
		if (!btn) {
			return;
		}
		var action = btn.dataset.abAction;
		var postId = btn.dataset.postId;
		var nonce = btn.dataset.nonce;
		var adoptVariant = btn.dataset.adoptVariant || "a";
		var ajaxAction = action === "switch" ? "meyvora_seo_ab_switch" : "meyvora_seo_ab_stop";
		btn.disabled = true;
		var fd = new FormData();
		fd.append("action", ajaxAction);
		fd.append("nonce", nonce);
		fd.append("post_id", postId);
		if (action === "stop") {
			fd.append("adopt_variant", adoptVariant);
		}
		fetch(ajaxUrl, {
			method: "POST",
			body: fd,
			credentials: "same-origin",
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				if (res.success) {
					alert(res.data.message);
					window.location.reload();
				} else {
					btn.disabled = false;
					alert(res.data && res.data.message ? res.data.message : errFallback);
				}
			})
			.catch(function () {
				btn.disabled = false;
				alert(errFallback);
			});
	});
})();
