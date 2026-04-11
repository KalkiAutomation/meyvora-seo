/**
 * Meyvora SEO — Internal link suggestions in meta box.
 *
 * @package Meyvora_SEO
 */

(function () {
	'use strict';

	var config = typeof meyvoraInternalLinks !== 'undefined' ? meyvoraInternalLinks : {};
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	var postId = config.postId || 0;
	var i18n = config.i18n || {};

	var panel = document.getElementById('meyvora-link-suggestions-panel');
	var listEl = document.getElementById('meyvora-link-suggestions-items');
	var loadingEl = document.getElementById('meyvora-link-suggestions-loading');

	function hideLoading() {
		if (loadingEl) loadingEl.style.display = 'none';
	}

	function showLoading() {
		if (loadingEl) loadingEl.style.display = '';
		if (listEl) listEl.innerHTML = '';
	}

	function copyToClipboard(text, html) {
		if (navigator.clipboard && window.ClipboardItem && html !== undefined) {
			var blob = new Blob([html], { type: 'text/html' });
			var plainBlob = new Blob([text], { type: 'text/plain' });
			return navigator.clipboard.write([new window.ClipboardItem({ 'text/html': blob, 'text/plain': plainBlob })]);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
		} finally {
			document.body.removeChild(ta);
		}
		return Promise.resolve();
	}

	function renderSuggestions(suggestions) {
		hideLoading();
		if (!listEl) return;
		if (!suggestions || suggestions.length === 0) {
			listEl.innerHTML = '<li class="meyvora-link-suggestions-none">' + (i18n.none || 'No suggestions found.') + '</li>';
			return;
		}
		var html = '';
		for (var i = 0; i < suggestions.length; i++) {
			var s = suggestions[i];
			var title = s.title || '';
			var url = s.url || '';
			var id = s.id || 0;
			html += '<li class="meyvora-link-suggestion-item" data-id="' + id + '" data-url="' + escapeHtml(url) + '" data-title="' + escapeHtml(title) + '">';
			html += '<span class="meyvora-link-suggestion-title">';
			html += '<span class="mev-icon mev-link-icon" aria-hidden="true">' + linkIcon() + '</span> ';
			html += escapeHtml(title);
			html += '</span> ';
			html += '<button type="button" class="button button-small meyvora-insert-internal-link">' + (i18n.insertLink || 'Insert link') + '</button>';
			html += '</li>';
		}
		listEl.innerHTML = html;

		listEl.querySelectorAll('.meyvora-insert-internal-link').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var li = this.closest('.meyvora-link-suggestion-item');
				if (!li) return;
				var url = li.getAttribute('data-url') || '';
				var title = li.getAttribute('data-title') || '';
				var anchor = title || url;
				var inserted = insertLinkIntoEditor(url, anchor);
				var orig = btn.textContent;
				if (inserted) {
					btn.textContent = (i18n.inserted !== undefined && i18n.inserted) ? i18n.inserted : '\u2713 Inserted';
					setTimeout(function () { btn.textContent = orig; }, 1500);
				} else {
					var htmlLink = '<a href="' + escapeHtmlAttr(url) + '">' + escapeHtml(anchor) + '</a>';
					copyToClipboard(url, htmlLink).then(function () {
						btn.textContent = i18n.copied || 'Link copied to clipboard';
						setTimeout(function () { btn.textContent = orig; }, 2000);
					});
				}
			});
		});
	}

	function escapeHtml(s) {
		if (!s) return '';
		var div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML;
	}

	function escapeHtmlAttr(s) {
		if (!s) return '';
		var div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML.replace(/"/g, '&quot;');
	}

	function insertLinkIntoEditor(url, anchor) {
		var linkHtml = '<a href="' + escapeHtmlAttr(url) + '">' + escapeHtml(anchor) + '</a>';
		if (typeof tinymce !== 'undefined' && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) {
			var editor = tinymce.activeEditor;
			if (editor.insertContent) {
				editor.insertContent(linkHtml);
			} else {
				editor.execCommand('mceInsertLink', false, { href: url, text: anchor });
			}
			return true;
		}
		var textarea = document.getElementById('content');
		if (textarea) {
			var start = textarea.selectionStart;
			var end = textarea.selectionEnd;
			var before = textarea.value.substring(0, start);
			var after = textarea.value.substring(end);
			textarea.value = before + linkHtml + after;
			textarea.selectionStart = textarea.selectionEnd = start + linkHtml.length;
			textarea.focus();
			return true;
		}
		return false;
	}

	function linkIcon() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
	}

	function fetchSuggestions() {
		if (!postId || !ajaxUrl || !nonce) {
			hideLoading();
			if (listEl) listEl.innerHTML = '<li class="meyvora-link-suggestions-none">' + (i18n.none || 'No suggestions found.') + '</li>';
			return;
		}
		showLoading();
		var formData = new FormData();
		formData.append('action', 'meyvora_seo_link_suggestions');
		formData.append('nonce', nonce);
		formData.append('post_id', postId);

		fetch(ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res.success && res.data && res.data.suggestions) {
					renderSuggestions(res.data.suggestions);
				} else {
					hideLoading();
					if (listEl) listEl.innerHTML = '<li class="meyvora-link-suggestions-none">' + (i18n.none || 'No suggestions found.') + '</li>';
				}
			})
			.catch(function () {
				hideLoading();
				if (listEl) listEl.innerHTML = '<li class="meyvora-link-suggestions-none">' + (i18n.none || 'No suggestions found.') + '</li>';
			});
	}

	function init() {
		if (!panel) return;
		fetchSuggestions();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
