/**
 * Meyvora SEO – Google-style SERP preview: real-time desktop & mobile, pixel progress bars.
 * Binds to #meyvora_seo_title, #meyvora_seo_description, #meyvora_seo_canonical.
 *
 * @package Meyvora_SEO
 */

(function () {
	'use strict';

	var DESKTOP_TITLE_PX = 600;
	var DESKTOP_TITLE_CHAR = 60;
	var DESKTOP_DESC_PX = 920;
	var DESKTOP_DESC_CHAR = 160;
	var MOBILE_TITLE_PX = 480;
	var MOBILE_TITLE_CHAR = 50;
	var MOBILE_DESC_PX = 680;
	var MOBILE_DESC_CHAR = 120;

	function $(id) {
		return document.getElementById(id);
	}

	function getTitle() {
		var el = $('meyvora_seo_title');
		var postTitle = document.querySelector('#title');
		return (el && el.value.trim()) ? el.value.trim() : (postTitle ? postTitle.value.trim() : '');
	}

	function getDesc() {
		var el = $('meyvora_seo_description');
		return el ? el.value.trim() : '';
	}

	function getUrl() {
		var el = $('meyvora_seo_canonical');
		var container = document.querySelector('.mev-serp-container');
		var defaultUrl = container ? container.getAttribute('data-initial-url') : '';
		return (el && el.value.trim()) ? el.value.trim() : (defaultUrl || '');
	}

	function breadcrumbFromUrl(url) {
		if (!url) return '';
		try {
			var a = document.createElement('a');
			a.href = url;
			var host = (a.hostname || '').replace(/^www\./, '');
			var path = (a.pathname || '/').replace(/^\/|\/$/g, '');
			var segments = path ? path.split('/').filter(Boolean) : [];
			return host + (segments.length ? ' › ' + segments.join(' › ') : '');
		} catch (e) {
			return url;
		}
	}

	function progressColor(len, maxChar, warnChar) {
		if (len <= maxChar) return 'green';
		if (len <= (warnChar || maxChar + 15)) return 'amber';
		return 'red';
	}

	function updateProgressBar(barEl, labelEl, len, maxChar, maxPx, mode) {
		if (!barEl) return;
		var pct = Math.min(100, (len / maxChar) * 100);
		barEl.style.width = pct + '%';
		var color = progressColor(len, maxChar, maxChar + 15);
		barEl.classList.remove('mev-serp-bar--green', 'mev-serp-bar--amber', 'mev-serp-bar--red');
		barEl.classList.add('mev-serp-bar--' + color);
		if (labelEl) {
			var approxPx = Math.round((len / maxChar) * maxPx);
			labelEl.textContent = len + ' / ' + maxChar + ' chars · ~' + approxPx + ' / ' + maxPx + 'px';
		}
	}

	function updateSerpPreview() {
		var title = getTitle();
		var desc = getDesc();
		var url = getUrl();
		var breadcrumb = breadcrumbFromUrl(url);

		var titleLen = title.length;
		var descLen = desc.length;

		// Desktop
		['desktop', 'mobile'].forEach(function (mode) {
			var isDesktop = mode === 'desktop';
			var titleChar = isDesktop ? DESKTOP_TITLE_CHAR : MOBILE_TITLE_CHAR;
			var titlePx = isDesktop ? DESKTOP_TITLE_PX : MOBILE_TITLE_PX;
			var descChar = isDesktop ? DESKTOP_DESC_CHAR : MOBILE_DESC_CHAR;
			var descPx = isDesktop ? DESKTOP_DESC_PX : MOBILE_DESC_PX;

			var titleEl = $('mev-serp-' + mode + '-title');
			var descEl = $('mev-serp-' + mode + '-desc');
			var breadcrumbEl = $('mev-serp-' + mode + '-breadcrumb');
			var titleBar = $('mev-serp-' + mode + '-title-bar');
			var descBar = $('mev-serp-' + mode + '-desc-bar');
			var titleLabel = $('mev-serp-' + mode + '-title-label');
			var descLabel = $('mev-serp-' + mode + '-desc-label');

			if (titleEl) titleEl.textContent = title || '';
			if (descEl) descEl.textContent = desc || '';
			if (breadcrumbEl) breadcrumbEl.textContent = breadcrumb || '';

			updateProgressBar(titleBar, titleLabel, titleLen, titleChar, titlePx, mode);
			updateProgressBar(descBar, descLabel, descLen, descChar, descPx, mode);
		});
	}

	function switchSerpMode(mode) {
		var desktop = $('mev-serp-desktop');
		var mobile = $('mev-serp-mobile');
		var buttons = document.querySelectorAll('.mev-serp-mode');
		if (!desktop || !mobile) return;
		buttons.forEach(function (b) {
			var isActive = b.getAttribute('data-mode') === mode;
			b.classList.toggle('is-active', isActive);
			b.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});
		if (mode === 'desktop') {
			desktop.classList.add('is-active');
			desktop.hidden = false;
			mobile.classList.remove('is-active');
			mobile.hidden = true;
		} else {
			mobile.classList.add('is-active');
			mobile.hidden = false;
			desktop.classList.remove('is-active');
			desktop.hidden = true;
		}
	}

	function init() {
		var container = document.querySelector('.mev-serp-container');
		if (!container) return;

		var titleEl = $('meyvora_seo_title');
		var descEl = $('meyvora_seo_description');
		var canonicalEl = $('meyvora_seo_canonical');
		var postTitleEl = document.querySelector('#title');

		function bind(el, events) {
			if (!el) return;
			events.forEach(function (ev) {
				el.addEventListener(ev, updateSerpPreview);
			});
		}
		bind(titleEl, ['input', 'change']);
		bind(descEl, ['input', 'change']);
		bind(canonicalEl, ['input', 'change']);
		bind(postTitleEl, ['input', 'change']);

		container.querySelectorAll('.mev-serp-mode').forEach(function (btn) {
			btn.addEventListener('click', function () {
				switchSerpMode(btn.getAttribute('data-mode'));
			});
		});

		updateSerpPreview();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
