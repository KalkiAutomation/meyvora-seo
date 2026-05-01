/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Network admin SEO overview: refresh AJAX, sort redirect.
 */
(function () {
	'use strict';
	var cfg = window.meyvoraSeoNetwork || {};
	var btn = document.getElementById('meyvora-network-refresh');
	var sortSelect = document.getElementById('meyvora-network-sort');
	if (sortSelect) {
		sortSelect.addEventListener('change', function () {
			var url = new URL(window.location.href);
			url.searchParams.set('sort', this.value);
			window.location.href = url.toString();
		});
	}
	if (!btn || !cfg.ajaxUrl || !cfg.nonce) {
		return;
	}
	var i18n = cfg.i18n || {};
	btn.addEventListener('click', function () {
		btn.disabled = true;
		btn.textContent = i18n.refreshing || '…';
		var formData = new FormData();
		formData.append('action', 'meyvora_seo_network_refresh');
		formData.append('nonce', cfg.nonce);
		fetch(cfg.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				if (res.success) {
					window.location.reload();
				} else {
					btn.disabled = false;
					btn.textContent = i18n.refresh || '';
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = i18n.refresh || '';
			});
	});
})();
