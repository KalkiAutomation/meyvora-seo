/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Meyvora SEO — Bulk Editor: inline edit, char counters, Save All, Apply Template, Export CSV.
 *
 * @package Meyvora_SEO
 */

(function () {
	'use strict';

	var config = typeof meyvoraBulkEditor !== 'undefined' ? meyvoraBulkEditor : {};
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	var saveAction = config.saveAction || 'meyvora_seo_bulk_editor_save';
	var exportAction = config.exportAction || 'meyvora_seo_bulk_editor_export';
	var titleMax = parseInt(config.titleMax, 10) || 60;
	var descMax = parseInt(config.descMax, 10) || 160;
	var i18n = config.i18n || {};

	var dirty = {}; // post_id -> { title, description }

	function updateDirtyChip() {
		var count = Object.keys(dirty).length;
		var chip = document.getElementById('mev-dirty-chip');
		var num  = document.getElementById('mev-dirty-count');
		if (!chip || !num) return;
		chip.style.display = count > 0 ? 'inline-flex' : 'none';
		num.textContent = count;
	}

	function getRowData(row) {
		var id = row.getAttribute('data-post-id');
		var titleCell = row.querySelector('.column-seo-title');
		var descCell = row.querySelector('.column-meta-desc');
		var focusCell = row.querySelector('.column-focus-keyword .mev-cell-value');
		var title = titleCell ? (titleCell.querySelector('.mev-cell-value') || titleCell).textContent.trim() : '';
		var desc = descCell ? (descCell.querySelector('.mev-cell-value') || descCell).textContent.trim() : '';
		var focus_keyword = focusCell ? (focusCell.textContent || '').trim() : '';
		return { post_id: id ? parseInt(id, 10) : 0, title: title, description: desc, focus_keyword: focus_keyword };
	}

	function setRowValue(row, field, value) {
		var cell = row.querySelector('.column-seo-title');
		if (field === 'description') cell = row.querySelector('.column-meta-desc');
		if (field === 'focus_keyword') cell = row.querySelector('.column-focus-keyword');
		if (!cell) return;
		var valEl = cell.querySelector('.mev-cell-value');
		if (valEl) valEl.textContent = value;
		updateCounter(cell, value);
	}

	function updateCounter(cell, text) {
		var max = parseInt(cell.getAttribute('data-max'), 10) || 60;
		var len = (text || '').length;
		var counter = cell.querySelector('.mev-cell-counter');
		if (!counter) return;
		counter.textContent = len + ' / ' + max;
		counter.className = 'mev-cell-counter';
		if (len > max) counter.classList.add('mev-char-over');
		else if (len >= max - 10) counter.classList.add('mev-char-warn');
		else counter.classList.add('mev-char-ok');
	}

	function initCounters() {
		var rows = document.querySelectorAll('.mev-bulk-row');
		rows.forEach(function (row) {
			var titleCell = row.querySelector('.column-seo-title .mev-cell-value');
			var descCell = row.querySelector('.column-meta-desc .mev-cell-value');
			var focusCell = row.querySelector('.column-focus-keyword .mev-cell-value');
			if (titleCell) updateCounter(titleCell.parentElement, titleCell.textContent);
			if (descCell) updateCounter(descCell.parentElement, descCell.textContent);
			if (focusCell) updateCounter(focusCell.parentElement, focusCell.textContent);
		});
	}

	function startEdit(cell) {
		if (cell.classList.contains('mev-editing')) return;
		var field = cell.getAttribute('data-field');
		var max = parseInt(cell.getAttribute('data-max'), 10) || 60;
		var valEl = cell.querySelector('.mev-cell-value');
		var value = valEl ? valEl.textContent : '';
		var isDesc = field === 'description';
		cell.classList.add('mev-editing');
		var input = document.createElement(isDesc ? 'textarea' : 'input');
		input.type = isDesc ? 'textarea' : 'text';
		input.className = 'mev-cell-input';
		input.value = value;
		input.setAttribute('maxlength', max);
		if (isDesc) input.rows = 2;
		valEl.style.display = 'none';
		cell.insertBefore(input, valEl.nextSibling);
		input.focus();
		input.addEventListener('blur', function onBlur() {
			finishEdit(cell, input, valEl);
			input.removeEventListener('blur', onBlur);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !isDesc) {
				e.preventDefault();
				input.blur();
			}
			if (e.key === 'Escape') {
				input.value = value;
				input.blur();
			}
		});
	}

	function finishEdit(cell, input, valEl) {
		var value = input.value;
		var row = cell.closest('.mev-bulk-row');
		var postId = row ? row.getAttribute('data-post-id') : null;
		cell.classList.remove('mev-editing');
		input.remove();
		valEl.style.display = '';
		valEl.textContent = value;
		updateCounter(cell, value);
		if (postId) {
			var id = parseInt(postId, 10);
			var field = cell.getAttribute('data-field');
			if (!dirty[id]) dirty[id] = getRowData(row);
			if (field === 'title') dirty[id].title = value;
			else if (field === 'description') dirty[id].description = value;
			else if (field === 'focus_keyword') dirty[id].focus_keyword = value;
		}
		updateDirtyChip();
	}

	function initInlineEdit() {
		document.querySelectorAll('.mev-editable-cell').forEach(function (cell) {
			cell.addEventListener('click', function (e) {
				if (cell.classList.contains('mev-editing')) return;
				if (e.target.classList.contains('mev-cell-input')) return;
				startEdit(cell);
			});
		});
	}

	function selectAll(checked) {
		document.querySelectorAll('.mev-bulk-row-cb').forEach(function (cb) {
			cb.checked = checked;
		});
	}

	function getSelectedIds() {
		var ids = [];
		document.querySelectorAll('.mev-bulk-row-cb:checked').forEach(function (cb) {
			ids.push(parseInt(cb.value, 10));
		});
		return ids;
	}

	function collectDirtyRows() {
		var rows = [];
		Object.keys(dirty).forEach(function (id) {
			id = parseInt(id, 10);
			var row = document.querySelector('.mev-bulk-row[data-post-id="' + id + '"]');
			var data = row ? getRowData(row) : { title: '', description: '', focus_keyword: '' };
			if (dirty[id]) {
				if (dirty[id].title !== undefined) data.title = dirty[id].title;
				if (dirty[id].description !== undefined) data.description = dirty[id].description;
				if (dirty[id].focus_keyword !== undefined) data.focus_keyword = dirty[id].focus_keyword;
			}
			rows.push({ post_id: id, title: data.title, description: data.description, focus_keyword: data.focus_keyword });
		});
		return rows;
	}

	function saveAll() {
		var payload = collectDirtyRows();
		if (payload.length === 0) {
			alert('No changes to save. Edit a cell first.');
			return;
		}
		var btn = document.getElementById('mev-bulk-save');
		if (btn) btn.disabled = true;
		var formData = new FormData();
		formData.append('action', saveAction);
		formData.append('nonce', nonce);
		formData.append('rows', JSON.stringify(payload));
		fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (btn) btn.disabled = false;
				if (res.success) {
					dirty = {};
					updateDirtyChip();
					if (typeof window.mevToast === 'function') {
						window.mevToast('Saved!', 'success');
					} else if (typeof res.data !== 'undefined' && res.data.updated !== undefined) {
						alert((i18n.saved || 'Saved') + ' — ' + res.data.updated + ' updated.');
					} else {
						alert(i18n.saved || 'Saved');
					}
				} else {
					if (typeof window.mevToast === 'function') {
						window.mevToast('Save failed', 'error');
					} else {
						alert(i18n.error || 'Error saving.');
					}
				}
			})
			.catch(function () {
				if (btn) btn.disabled = false;
				if (typeof window.mevToast === 'function') {
					window.mevToast('Save failed', 'error');
				} else {
					alert(i18n.error || 'Error saving.');
				}
			});
	}

	function applyTemplate() {
		var sel = document.getElementById('mev-apply-template');
		var tpl = sel ? sel.value : '';
		if (!tpl) return;
		var siteName = (typeof meyvoraBulkEditor !== 'undefined' && meyvoraBulkEditor.siteName) ? meyvoraBulkEditor.siteName : '';
		var ids = getSelectedIds();
		var rows = document.querySelectorAll('.mev-bulk-row');
		var applyToAll = ids.length === 0;
		rows.forEach(function (row) {
			if (!applyToAll && ids.indexOf(parseInt(row.getAttribute('data-post-id'), 10)) === -1) return;
			var postTitle = row.getAttribute('data-post-title') || '';
			var value = tpl
				.replace(/\{post_title\}/g, postTitle)
				.replace(/\{site_name\}/g, siteName);
			var titleCell = row.querySelector('.column-seo-title');
			if (titleCell) {
				var valEl = titleCell.querySelector('.mev-cell-value');
				if (valEl) valEl.textContent = value;
				updateCounter(titleCell, value);
				var id = parseInt(row.getAttribute('data-post-id'), 10);
				if (!dirty[id]) dirty[id] = getRowData(row);
				dirty[id].title = value;
			}
		});
	}

	function exportCsv() {
		var ids = getSelectedIds();
		if (ids.length === 0) {
			alert('Select at least one row.');
			return;
		}
		var formData = new FormData();
		formData.append('action', exportAction);
		formData.append('nonce', nonce);
		formData.append('ids', JSON.stringify(ids));
		fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res.success || !res.data || !res.data.csv) {
					alert('Export failed.');
					return;
				}
				var blob = new Blob([res.data.csv], { type: 'text/csv;charset=utf-8;' });
				var a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				a.download = 'meyvora-seo-bulk-export.csv';
				a.click();
				URL.revokeObjectURL(a.href);
			})
			.catch(function () { alert('Export failed.'); });
	}

	function aiFill(btn) {
		var row = btn.closest('.mev-bulk-row');
		if (!row) return;
		var postId = parseInt(btn.getAttribute('data-post-id'), 10);
		if (!postId) return;
		var aiConfig = typeof meyvoraSeoAi !== 'undefined' ? meyvoraSeoAi : {};
		var aiUrl = aiConfig.ajaxUrl || ajaxUrl;
		var aiNonce = aiConfig.nonce || '';
		var aiAction = 'meyvora_seo_ai_request';
		var settingsUrl = (typeof meyvoraBulkEditor !== 'undefined' && meyvoraBulkEditor.settingsUrl) ? meyvoraBulkEditor.settingsUrl : '';

		var origLabel = btn.textContent;
		btn.disabled = true;
		btn.textContent = '\u22EE';
		btn.classList.add('mev-ai-loading');

		function postAi(actionType) {
			var fd = new FormData();
			fd.append('action', aiAction);
			fd.append('nonce', aiNonce);
			fd.append('action_type', actionType);
			fd.append('post_id', postId);
			return fetch(aiUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
		}

		postAi('generate_title')
			.then(function (res) {
				if (!res.success || (res.data && res.data.code === 'no_api_key')) {
					throw res;
				}
				var title = (res.data && res.data.options && res.data.options[0]) ? res.data.options[0] : '';
				if (title) {
					var titleCell = row.querySelector('td[data-field="title"]');
					if (titleCell) {
						var valEl = titleCell.querySelector('.mev-cell-value');
						if (valEl) valEl.textContent = title;
						updateCounter(titleCell, title);
						titleCell.classList.add('mev-cell--dirty');
						var id = postId;
						if (!dirty[id]) dirty[id] = getRowData(row);
						dirty[id].title = title;
					}
				}
				return postAi('generate_description');
			})
			.then(function (res) {
				if (!res.success || (res.data && res.data.code === 'no_api_key')) {
					throw res;
				}
				var desc = (res.data && res.data.options && res.data.options[0]) ? res.data.options[0] : '';
				if (desc) {
					var descCell = row.querySelector('td[data-field="description"]');
					if (descCell) {
						var valEl = descCell.querySelector('.mev-cell-value');
						if (valEl) valEl.textContent = desc;
						updateCounter(descCell, desc);
						descCell.classList.add('mev-cell--dirty');
						var id = postId;
						if (!dirty[id]) dirty[id] = getRowData(row);
						dirty[id].description = desc;
					}
				}
				updateDirtyChip();
				btn.disabled = false;
				btn.classList.remove('mev-ai-loading');
				btn.textContent = '\u2713';
				setTimeout(function () {
					btn.textContent = origLabel;
				}, 2000);
			})
			.catch(function (res) {
				btn.disabled = false;
				btn.classList.remove('mev-ai-loading');
				btn.textContent = origLabel;
				if (res && res.data && res.data.code === 'no_api_key' && settingsUrl) {
					var wrap = btn.parentElement;
					if (wrap) {
						var link = document.createElement('a');
						link.href = settingsUrl;
						link.className = 'mev-ai-settings-link';
						link.textContent = (typeof meyvoraBulkEditor !== 'undefined' && meyvoraBulkEditor.i18n && meyvoraBulkEditor.i18n.settings) ? meyvoraBulkEditor.i18n.settings : 'Settings';
						btn.style.display = 'none';
						wrap.appendChild(link);
					}
				} else {
					alert((typeof meyvoraBulkEditor !== 'undefined' && meyvoraBulkEditor.i18n && meyvoraBulkEditor.i18n.error) ? meyvoraBulkEditor.i18n.error : 'Error');
				}
			});
	}

	function initAiFill() {
		var table = document.getElementById('mev-bulk-editor-table');
		if (!table) return;
		table.addEventListener('click', function (e) {
			var btn = e.target.closest('.mev-ai-fill');
			if (!btn || btn.disabled) return;
			e.preventDefault();
			aiFill(btn);
		});
	}

	function init() {
		initCounters();
		initInlineEdit();
		initAiFill();
		var selectAllEl = document.getElementById('mev-bulk-select-all');
		if (selectAllEl) {
			selectAllEl.addEventListener('change', function () { selectAll(this.checked); });
		}
		var saveBtn = document.getElementById('mev-bulk-save');
		if (saveBtn) saveBtn.addEventListener('click', saveAll);
		var applyBtn = document.getElementById('mev-bulk-apply-template');
		if (applyBtn) applyBtn.addEventListener('click', applyTemplate);
		var exportBtn = document.getElementById('mev-bulk-export');
		if (exportBtn) exportBtn.addEventListener('click', exportCsv);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
