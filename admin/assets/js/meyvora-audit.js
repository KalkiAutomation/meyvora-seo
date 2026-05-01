/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Meyvora SEO — Site Audit: Run Now with progress bar and polling.
 *
 * @package Meyvora_SEO
 */

(function () {
	'use strict';

	var config = typeof meyvoraAudit !== 'undefined' ? meyvoraAudit : {};
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	var i18n = config.i18n || {};

	function runAudit() {
		var progressWrap = document.getElementById('mev-audit-progress-wrap');
		var runBtn = document.getElementById('mev-audit-run-now');
		var runBtnEmpty = document.getElementById('mev-audit-run-now-empty');
		if (!progressWrap) return;

		function setRunning(running) {
			progressWrap.style.display = running ? 'flex' : 'none';
			if (runBtn) runBtn.disabled = running;
			if (runBtnEmpty) runBtnEmpty.disabled = running;
		}

		function setProgress(processed, total) {
			var pct = total > 0 ? Math.round(processed / total * 100) : 0;
			var fill = document.getElementById('mev-oring-fill');
			var pctEl = document.getElementById('mev-overlay-pct');
			var txtEl = document.getElementById('mev-audit-progress-text');
			if (fill) fill.style.strokeDashoffset = String(201 - Math.round(2.01 * pct));
			if (pctEl) pctEl.textContent = pct + '%';
			if (txtEl) txtEl.textContent = 'Scanning ' + processed + ' of ' + total + ' posts…';
		}

		function poll() {
			var formData = new FormData();
			formData.append('action', 'meyvora_seo_audit_run');
			formData.append('nonce', nonce);
			formData.append('step', 'next');

			fetch(ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.success || !res.data) {
						setRunning(false);
						return;
					}
					var d = res.data;
					if (d.done) {
						setRunning(false);
						setProgress(d.total || 0, d.total || 0);
						if (d.results) {
							location.reload();
						}
						return;
					}
					setProgress(d.processed || 0, d.total || 1);
					setTimeout(poll, 300);
				})
				.catch(function () {
					setRunning(false);
				});
		}

		setRunning(true);
		setProgress(0, 0);

		var startData = new FormData();
		startData.append('action', 'meyvora_seo_audit_run');
		startData.append('nonce', nonce);
		startData.append('step', 'start');

		fetch(ajaxUrl, {
			method: 'POST',
			body: startData,
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res.success || !res.data) {
					setRunning(false);
					return;
				}
				var total = res.data.total || 0;
				if (total === 0) {
					setRunning(false);
					location.reload();
					return;
				}
				setProgress(0, total);
				setTimeout(poll, 400);
			})
			.catch(function () {
				setRunning(false);
			});
	}

	function initExpand() {
		document.querySelectorAll('.mev-row-expand-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var tr = btn.closest('tr');
				var pid = tr ? tr.getAttribute('data-post-id') : null;
				if (!pid) return;
				var detail = document.getElementById('mev-detail-' + pid);
				if (!detail) return;
				var open = detail.style.display !== 'none';
				detail.style.display = open ? 'none' : 'table-row';
				btn.setAttribute('aria-expanded', String(!open));
				btn.textContent = open ? '\u25B6' : '\u25BC';
				tr.classList.toggle('is-expanded', !open);
			});
		});
	}

	function initFilters() {
		var table = document.getElementById('mev-audit-table');
		var severitySelect = document.getElementById('mev-audit-filter-severity');
		var searchInput = document.getElementById('mev-audit-filter-search');
		if (!table) return;

		function applyFilters() {
			var severity = severitySelect ? severitySelect.value : '';
			var search = searchInput ? searchInput.value.trim().toLowerCase() : '';
			var rows = table.querySelectorAll('.mev-audit-row');
			rows.forEach(function (tr) {
				var issuesData = tr.getAttribute('data-issues');
				var hasWarning = false;
				var hasInfo = false;
				if (issuesData) {
					try {
						var issues = JSON.parse(issuesData);
						issues.forEach(function (i) {
							if (i.severity === 'warning') hasWarning = true;
							if (i.severity === 'info') hasInfo = true;
						});
					} catch (e) {}
				}
				var showSeverity = true;
				if (severity === 'warning') showSeverity = hasWarning;
				if (severity === 'info') showSeverity = hasInfo;

				var title = (tr.querySelector('.column-post a') || {}).textContent || '';
				var showSearch = !search || title.toLowerCase().indexOf(search) !== -1;

				tr.style.display = showSeverity && showSearch ? '' : 'none';
			});
		}

		if (severitySelect) severitySelect.addEventListener('change', applyFilters);
		if (searchInput) searchInput.addEventListener('input', applyFilters);
	}

	function exportCsv() {
		var rows = document.querySelectorAll('.mev-audit-row');
		var lines = [['Post Title', 'Post ID', 'SEO Score', 'Issues', 'Edit URL'].join(',')];
		rows.forEach(function (tr) {
			if (tr.style.display === 'none') return;
			var title = ((tr.querySelector('.column-post a') || {}).textContent || '').trim();
			var pid = tr.getAttribute('data-post-id') || '';
			var score = ((tr.querySelector('.column-score') || {}).textContent || '').trim();
			var issStr = tr.getAttribute('data-issues') || '[]';
			var issArr = [];
			try {
				issArr = JSON.parse(issStr).map(function (i) { return i.label || i.id; });
			} catch (e) {}
			var editHref = ((tr.querySelector('.column-action a') || {}).href || '');
			function esc(s) { return '"' + String(s).replace(/"/g, '""') + '"'; }
			lines.push([esc(title), pid, score, esc(issArr.join('; ')), esc(editHref)].join(','));
		});
		var blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = 'meyvora-audit-' + new Date().toISOString().slice(0, 10) + '.csv';
		a.click();
		URL.revokeObjectURL(a.href);
	}

	function init() {
		var runBtn = document.getElementById('mev-audit-run-now');
		var runBtnEmpty = document.getElementById('mev-audit-run-now-empty');
		if (runBtn) runBtn.addEventListener('click', runAudit);
		if (runBtnEmpty) runBtnEmpty.addEventListener('click', runAudit);
		initFilters();
		initExpand();
		var exportBtn = document.getElementById('mev-audit-export-csv');
		if (exportBtn) exportBtn.addEventListener('click', exportCsv);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
