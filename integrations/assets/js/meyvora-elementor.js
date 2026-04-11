/**
 * Meyvora SEO – Elementor editor: score badge, floating SEO panel, analyse & save.
 *
 * @package Meyvora_SEO
 */

(function () {
	'use strict';

	var config = typeof meyvoraSeoElementor !== 'undefined' ? meyvoraSeoElementor : {};
	var i18n = config.i18n || {};
	function t(key) {
		return i18n[key] || key;
	}

	function getPostId() {
		if (config.postId && config.postId > 0) return config.postId;
		if (typeof elementor !== 'undefined' && elementor.config && elementor.config.document && elementor.config.document.id) {
			return elementor.config.document.id;
		}
		return 0;
	}

	function runElementorAnalyze(callback) {
		var postId = getPostId();
		if (!postId) {
			if (callback) callback(null);
			return;
		}
		if (typeof wp !== 'undefined' && wp.ajax && wp.ajax.post) {
			wp.ajax.post(config.analyzeAction || 'meyvora_seo_elementor_analyze', {
				post_id: postId,
				nonce: config.nonce || ''
			}).then(function (data) {
				if (callback) callback(data);
			}).fail(function () {
				if (callback) callback(null);
			});
		} else {
			var fd = new FormData();
			fd.append('action', config.analyzeAction || 'meyvora_seo_elementor_analyze');
			fd.append('nonce', config.nonce || '');
			fd.append('post_id', postId);
			fetch(config.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) { if (callback) callback(data); })
				.catch(function () { if (callback) callback(null); });
		}
	}

	function scoreColor(score) {
		if (score == null || isNaN(score)) return '#9ca3af';
		if (score >= 80) return '#059669';
		if (score >= 50) return '#d97706';
		return '#dc2626';
	}

	function scoreRingSvg(score) {
		var s = score != null && !isNaN(score) ? Math.min(100, Math.max(0, Math.round(score))) : 0;
		var color = scoreColor(s);
		var r = 36;
		var c = 2 * Math.PI * r;
		var offset = c - (s / 100) * c;
		return '<svg width="80" height="80" viewBox="0 0 80 80" style="display:block;">' +
			'<circle cx="40" cy="40" r="' + r + '" fill="none" stroke="#e5e7eb" stroke-width="6"/>' +
			'<circle cx="40" cy="40" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="6" stroke-dasharray="' + c + '" stroke-dashoffset="' + offset + '" transform="rotate(-90 40 40)" stroke-linecap="round"/>' +
			'<text x="40" y="44" text-anchor="middle" font-size="18" font-weight="700" fill="' + color + '">' + (s > 0 ? s : '—') + '</text>' +
			'</svg>';
	}

	function escapeHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function checkIcon(status) {
		if (status === 'pass') return { char: '✓', color: '#059669' };
		if (status === 'warning') return { char: '!', color: '#d97706' };
		return { char: '✗', color: '#dc2626' };
	}

	function buildPanel() {
		var panel = document.getElementById('meyvora-elementor-panel');
		if (panel) return panel;

		panel = document.createElement('div');
		panel.id = 'meyvora-elementor-panel';
		panel.style.cssText = 'position:fixed;right:12px;top:80px;width:280px;z-index:99999;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.12);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px;overflow:hidden;display:block;';
		panel.setAttribute('data-mev-collapsed', '0');

		var header = document.createElement('div');
		header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f9fafb;border-bottom:1px solid #e5e7eb;cursor:move;user-select:none;';
		header.innerHTML = '<span style="font-weight:600;color:#111827;">&#8801; ' + escapeHtml(t('panelTitle')) + '</span>' +
			'<span style="display:flex;gap:4px;">' +
			'<button type="button" id="mev-el-panel-toggle" style="border:none;background:transparent;cursor:pointer;padding:2px 6px;font-size:14px;color:#6b7280;" title="Collapse">&#9660;</button>' +
			'<button type="button" id="mev-el-panel-close" style="border:none;background:transparent;cursor:pointer;padding:2px 6px;font-size:16px;line-height:1;color:#6b7280;" title="Close">&times;</button>' +
			'</span>';
		panel.appendChild(header);

		var body = document.createElement('div');
		body.id = 'mev-el-panel-body';
		body.style.cssText = 'padding:12px;max-height:70vh;overflow-y:auto;';

		var scoreWrap = document.createElement('div');
		scoreWrap.style.cssText = 'display:flex;align-items:center;gap:12px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;';
		scoreWrap.innerHTML = '<div id="mev-el-score-ring">' + scoreRingSvg(null) + '</div>' +
			'<div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">' + escapeHtml(t('score')) + '</div><div id="mev-el-score-text" style="font-size:14px;color:#374151;">' + escapeHtml(t('noData')) + '</div></div>';
		body.appendChild(scoreWrap);

		var focusLabel = document.createElement('label');
		focusLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;color:#374151;margin-bottom:4px;';
		focusLabel.textContent = t('focusKw');
		body.appendChild(focusLabel);
		var focusInput = document.createElement('input');
		focusInput.type = 'text';
		focusInput.id = 'mev-el-focus-keyword';
		focusInput.placeholder = t('focusKw');
		(function () {
			var raw = (config.existingKeyword || '').trim();
			if (raw.charAt(0) === '[') {
				try {
					var arr = JSON.parse(raw);
					if (Array.isArray(arr) && arr.length) raw = typeof arr[0] === 'string' ? arr[0] : String(arr[0]);
				} catch (e) {}
			}
			focusInput.value = raw;
		})();
		focusInput.style.cssText = 'width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;margin-bottom:12px;';
		body.appendChild(focusInput);

		var descLabel = document.createElement('label');
		descLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;color:#374151;margin-bottom:4px;';
		descLabel.textContent = t('metaDesc');
		body.appendChild(descLabel);
		var descInput = document.createElement('textarea');
		descInput.id = 'mev-el-meta-desc';
		descInput.rows = 3;
		descInput.placeholder = t('metaDesc');
		descInput.value = config.existingDesc || '';
		descInput.style.cssText = 'width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;resize:vertical;margin-bottom:12px;';
		body.appendChild(descInput);

		var btnRow = document.createElement('div');
		btnRow.style.cssText = 'display:flex;gap:8px;margin-bottom:14px;';
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button';
		saveBtn.id = 'mev-el-save';
		saveBtn.textContent = t('save');
		saveBtn.style.cssText = 'padding:8px 14px;border:none;border-radius:6px;background:#7c3aed;color:#fff;font-size:13px;cursor:pointer;';
		var analyzeBtn = document.createElement('button');
		analyzeBtn.type = 'button';
		analyzeBtn.id = 'mev-el-analyze';
		analyzeBtn.textContent = t('analyze');
		analyzeBtn.style.cssText = 'padding:8px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;color:#374151;font-size:13px;cursor:pointer;';
		btnRow.appendChild(saveBtn);
		btnRow.appendChild(analyzeBtn);
		body.appendChild(btnRow);

		var checksTitle = document.createElement('div');
		checksTitle.style.cssText = 'font-size:11px;font-weight:600;color:#374151;margin-bottom:8px;';
		checksTitle.textContent = 'Checks:';
		body.appendChild(checksTitle);
		var checksList = document.createElement('div');
		checksList.id = 'mev-el-checks';
		checksList.style.cssText = 'font-size:12px;color:#4b5563;';
		checksList.innerHTML = '<span style="color:#9ca3af;">' + escapeHtml(t('noData')) + '</span>';
		body.appendChild(checksList);

		panel.appendChild(body);
		document.body.appendChild(panel);

		function updateScore(data) {
			var ringEl = document.getElementById('mev-el-score-ring');
			var textEl = document.getElementById('mev-el-score-text');
			if (!ringEl || !textEl) return;
			var score = data && data.data && data.data.score != null ? data.data.score : null;
			var status = data && data.data && data.data.status ? data.data.status : '';
			ringEl.innerHTML = scoreRingSvg(score);
			textEl.textContent = score != null ? score + '/100' : t('noData');
			textEl.style.color = score != null ? scoreColor(score) : '#6b7280';
			var results = data && data.data && data.data.results ? data.data.results : [];
			var listEl = document.getElementById('mev-el-checks');
			if (!listEl) return;
			if (results.length === 0) {
				listEl.innerHTML = '<span style="color:#9ca3af;">' + escapeHtml(t('noData')) + '</span>';
				return;
			}
			var html = '';
			for (var i = 0; i < results.length; i++) {
				var r = results[i];
				var icon = checkIcon(r.status || 'fail');
				html += '<div style="margin-bottom:6px;display:flex;align-items:flex-start;gap:6px;">' +
					'<span style="color:' + icon.color + ';flex-shrink:0;">' + icon.char + '</span>' +
					'<span>' + escapeHtml(r.label || r.id || '') + '</span>' +
					'</div>';
			}
			listEl.innerHTML = html;
		}

		function doSave() {
			var postId = getPostId();
			if (!postId || !config.ajaxUrl || !config.nonce) return;
			var fd = new FormData();
			fd.append('action', config.saveAction || 'meyvora_seo_elementor_save_meta');
			fd.append('nonce', config.nonce);
			fd.append('post_id', postId);
			fd.append('focus_keyword', focusInput.value);
			fd.append('meta_description', descInput.value);
			saveBtn.disabled = true;
			saveBtn.textContent = '…';
			fetch(config.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					saveBtn.disabled = false;
					saveBtn.textContent = t('save');
					if (res && res.success) {
						runElementorAnalyze(function (data) {
							updateScore(data);
							if (typeof injectScoreBadge === 'function' && data && data.success && data.data) {
								injectScoreBadge(data.data.score, data.data.status);
							}
						});
					}
				})
				.catch(function () {
					saveBtn.disabled = false;
					saveBtn.textContent = t('save');
				});
		}

		function doAnalyze() {
			analyzeBtn.disabled = true;
			analyzeBtn.textContent = '…';
			runElementorAnalyze(function (data) {
				analyzeBtn.disabled = false;
				analyzeBtn.textContent = t('analyze');
				updateScore(data);
				if (typeof injectScoreBadge === 'function' && data && data.success && data.data) {
					injectScoreBadge(data.data.score, data.data.status);
				}
			});
		}

		saveBtn.addEventListener('click', doSave);
		analyzeBtn.addEventListener('click', doAnalyze);

		var toggleBtn = document.getElementById('mev-el-panel-toggle');
		var closeBtn = document.getElementById('mev-el-panel-close');
		toggleBtn.addEventListener('click', function (e) {
			e.preventDefault();
			var collapsed = panel.getAttribute('data-mev-collapsed') === '1';
			panel.setAttribute('data-mev-collapsed', collapsed ? '0' : '1');
			body.style.display = collapsed ? 'block' : 'none';
			toggleBtn.textContent = collapsed ? '\u9660' : '\u9650';
			toggleBtn.title = collapsed ? 'Collapse' : 'Expand';
		});
		closeBtn.addEventListener('click', function (e) {
			e.preventDefault();
			panel.style.display = 'none';
		});

		var drag = { active: false, startX: 0, startY: 0, startRight: 0, startTop: 0 };
		header.addEventListener('mousedown', function (e) {
			if (e.target.tagName === 'BUTTON') return;
			drag.active = true;
			drag.startX = e.clientX;
			drag.startY = e.clientY;
			var rect = panel.getBoundingClientRect();
			drag.startRight = window.innerWidth - rect.right;
			drag.startTop = rect.top;
			document.addEventListener('mousemove', onMouseMove);
			document.addEventListener('mouseup', onMouseUp);
		});
		function onMouseMove(e) {
			if (!drag.active) return;
			panel.style.right = (drag.startRight + drag.startX - e.clientX) + 'px';
			panel.style.left = 'auto';
			panel.style.top = (drag.startTop + e.clientY - drag.startY) + 'px';
		}
		function onMouseUp() {
			drag.active = false;
			document.removeEventListener('mousemove', onMouseMove);
			document.removeEventListener('mouseup', onMouseUp);
		}

		runElementorAnalyze(function (data) {
			updateScore(data);
			if (typeof injectScoreBadge === 'function' && data && data.success && data.data) {
				injectScoreBadge(data.data.score, data.data.status);
			}
		});

		return panel;
	}

	function injectScoreBadge(score, status) {
		var existing = document.getElementById('meyvora-elementor-score-badge');
		if (existing) existing.remove();
		var bar = document.querySelector('.elementor-editor-status-bar');
		if (!bar) return;
		var badge = document.createElement('div');
		badge.id = 'meyvora-elementor-score-badge';
		badge.className = 'meyvora-elementor-badge meyvora-elementor-badge--' + (status || 'poor');
		badge.title = 'SEO Score';
		badge.textContent = 'SEO: ' + (score != null ? score : '—');
		badge.style.cssText = 'margin-left:8px;padding:2px 6px;border-radius:3px;font-size:11px;cursor:pointer;';
		badge.addEventListener('click', function () {
			var panel = document.getElementById('meyvora-elementor-panel');
			if (panel) {
				panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
			} else {
				buildPanel();
			}
		});
		bar.appendChild(badge);
	}

	function onElementorSave() {
		runElementorAnalyze(function (data) {
			if (data && data.success && data.data) {
				injectScoreBadge(data.data.score, data.data.status);
				var panel = document.getElementById('meyvora-elementor-panel');
				if (panel && panel.style.display !== 'none') {
					var ringEl = document.getElementById('mev-el-score-ring');
					var textEl = document.getElementById('mev-el-score-text');
					var listEl = document.getElementById('mev-el-checks');
					if (ringEl) ringEl.innerHTML = scoreRingSvg(data.data.score);
					if (textEl) { textEl.textContent = data.data.score + '/100'; textEl.style.color = scoreColor(data.data.score); }
					if (listEl && data.data.results && data.data.results.length) {
						var html = '';
						for (var i = 0; i < data.data.results.length; i++) {
							var r = data.data.results[i];
							var icon = checkIcon(r.status || 'fail');
							html += '<div style="margin-bottom:6px;display:flex;align-items:flex-start;gap:6px;">' +
								'<span style="color:' + icon.color + ';flex-shrink:0;">' + icon.char + '</span>' +
								'<span>' + escapeHtml(r.label || r.id || '') + '</span></div>';
						}
						listEl.innerHTML = html;
					}
				}
			}
		});
	}

	function onPreviewLoaded() {
		runElementorAnalyze(function (data) {
			if (data && data.success && data.data) {
				injectScoreBadge(data.data.score, data.data.status);
			}
		});
		buildPanel();
	}

	if (typeof elementor !== 'undefined') {
		elementor.on('editor/init', function () {
			onPreviewLoaded();
		});
		elementor.on('document:afterSave', function () {
			onElementorSave();
		});
	}
	if (typeof jQuery !== 'undefined') {
		jQuery(window).on('elementor/editor/after_save', function () {
			onElementorSave();
		});
		jQuery(window).on('elementor/preview/loaded', function () {
			onPreviewLoaded();
		});
	}
})();
