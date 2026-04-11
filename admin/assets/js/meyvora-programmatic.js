/**
 * Meyvora SEO — Programmatic: CSV upload, preview, batch generation with progress.
 *
 * @package Meyvora_SEO
 */

(function ($) {
	'use strict';

	var config = typeof meyvoraProgrammatic !== 'undefined' ? meyvoraProgrammatic : {};
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	var i18n = config.i18n || {};

	var csvKey = null;

	function getDataSourceConfig() {
		var type = $('input[name="mev-prog-source"]:checked').val();
		if (type === 'csv') {
			return { type: 'csv', csv_key: csvKey };
		}
		if (type === 'cpt') {
			var cpt = $('#mev-prog-cpt').val();
			var mappingText = $('#mev-prog-mapping').val().trim();
			var mapping = {};
			if (mappingText) {
				mappingText.split('\n').forEach(function (line) {
					var parts = line.split('=');
					if (parts.length >= 2) {
						mapping[parts[0].trim()] = parts.slice(1).join('=').trim();
					}
				});
			}
			return { type: 'cpt', cpt: cpt, mapping: mapping };
		}
		return {};
	}

	function setProgress(processed, total) {
		var pct = total > 0 ? Math.round(processed / total * 100) : 0;
		$('#mev-prog-ring-fill').css('stroke-dashoffset', 314 - (314 * pct / 100));
		$('#mev-prog-progress-pct').text(pct + '%');
		$('#mev-prog-progress-text').text('Generating ' + processed + ' of ' + total + '…');
	}

	function pollNext() {
		var formData = new FormData();
		formData.append('action', 'meyvora_seo_programmatic_next');
		formData.append('nonce', nonce);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false
		}).done(function (res) {
			if (!res.success || !res.data) {
				$('#mev-prog-progress-wrap').hide();
				$('#mev-prog-generate').prop('disabled', false);
				alert((res.data && res.data.message) || (i18n.error || 'Error'));
				return;
			}
			var d = res.data;
			setProgress(d.processed || 0, d.total || 1);
			if (d.done) {
				$('#mev-prog-progress-wrap').hide();
				$('#mev-prog-generate').prop('disabled', false);
				$('#mev-prog-progress-text').text(i18n.done || 'Done');
				location.reload();
				return;
			}
			setTimeout(pollNext, 350);
		}).fail(function () {
			$('#mev-prog-progress-wrap').hide();
			$('#mev-prog-generate').prop('disabled', false);
			alert(i18n.error || 'Request failed.');
		});
	}

	function startGeneration() {
		var templateId = $('#mev-prog-template').val();
		if (!templateId) {
			alert(i18n.selectTemplate || 'Select a template.');
			return;
		}
		var ds = getDataSourceConfig();
		if (ds.type === 'csv' && !ds.csv_key) {
			alert(i18n.uploadCsv || 'Upload and parse a CSV first.');
			return;
		}
		if (ds.type === 'cpt' && (!ds.cpt || !ds.mapping || Object.keys(ds.mapping).length === 0)) {
			alert('Select a post type and add variable → field mapping.');
			return;
		}

		$('#mev-prog-generate').prop('disabled', true);
		$('#mev-prog-progress-wrap').show();
		setProgress(0, 0);
		$('#mev-prog-progress-text').text(i18n.generating || 'Generating…');

		var formData = new FormData();
		formData.append('action', 'meyvora_seo_programmatic_start');
		formData.append('nonce', nonce);
		formData.append('template_id', templateId);
		formData.append('data_source', JSON.stringify(ds));

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false
		}).done(function (res) {
			if (!res.success || !res.data) {
				$('#mev-prog-progress-wrap').hide();
				$('#mev-prog-generate').prop('disabled', false);
				alert((res.data && res.data.message) || (i18n.error || 'Error'));
				return;
			}
			var total = res.data.total || 0;
			if (total === 0) {
				$('#mev-prog-progress-wrap').hide();
				$('#mev-prog-generate').prop('disabled', false);
				return;
			}
			setProgress(0, total);
			setTimeout(pollNext, 400);
		}).fail(function () {
			$('#mev-prog-progress-wrap').hide();
			$('#mev-prog-generate').prop('disabled', false);
			alert(i18n.error || 'Request failed.');
		});
	}

	$(function () {
		$('input[name="mev-prog-source"]').on('change', function () {
			var v = $(this).val();
			$('#mev-prog-csv-wrap').toggle(v === 'csv');
			$('#mev-prog-cpt-wrap').toggle(v === 'cpt');
		});

		$('#mev-prog-csv-parse').on('click', function () {
			var file = document.getElementById('mev-prog-csv-file').files[0];
			if (!file) {
				$('#mev-prog-csv-status').text('Choose a CSV file first.');
				return;
			}
			$('#mev-prog-csv-status').text(i18n.parsing || 'Parsing…');
			var formData = new FormData();
			formData.append('action', 'meyvora_seo_programmatic_upload_csv');
			formData.append('nonce', nonce);
			formData.append('file', file);

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false
			}).done(function (res) {
				if (res.success && res.data) {
					csvKey = res.data.csv_key;
					var msg = (i18n.rowsFound || '%d rows found').replace('%d', res.data.count);
					$('#mev-prog-csv-status').text(msg).css('color', 'var(--mev-success, green)');
				} else {
					$('#mev-prog-csv-status').text(res.data && res.data.message ? res.data.message : 'Error').css('color', '');
				}
			}).fail(function () {
				$('#mev-prog-csv-status').text('Upload failed.').css('color', '');
			});
		});

		$('#mev-prog-preview').on('click', function () {
			var templateId = $('#mev-prog-template').val();
			if (!templateId) {
				alert(i18n.selectTemplate || 'Select a template.');
				return;
			}
			var ds = getDataSourceConfig();
			if (ds.type === 'csv' && !ds.csv_key) {
				alert(i18n.uploadCsv || 'Upload and parse a CSV first.');
				return;
			}
			if (ds.type === 'cpt' && (!ds.cpt || !ds.mapping || Object.keys(ds.mapping).length === 0)) {
				alert('Select a post type and add variable → field mapping.');
				return;
			}

			$.post(ajaxUrl, {
				action: 'meyvora_seo_programmatic_preview',
				nonce: nonce,
				template_id: templateId,
				data_source: JSON.stringify(ds)
			}).done(function (res) {
				if (res.success && res.data && res.data.preview) {
					var html = '';
					res.data.preview.forEach(function (p) {
						html += '<tr><td>' + (p.title || '').replace(/</g, '&lt;') + '</td><td>' + (p.slug || '').replace(/</g, '&lt;') + '</td><td>' + (p.meta_title || '').replace(/</g, '&lt;') + '</td></tr>';
					});
					$('#mev-prog-preview-tbody').html(html);
					$('#mev-prog-preview-box').show();
				} else {
					$('#mev-prog-preview-box').hide();
					alert(res.data && res.data.message ? res.data.message : 'No preview.');
				}
			}).fail(function () {
				alert(i18n.error || 'Request failed.');
			});
		});

		$('#mev-prog-generate').on('click', startGeneration);

		$(document).on('click', '.mev-prog-delete-group', function () {
			var termId = $(this).data('term-id');
			var count = $(this).data('count');
			var msg = (i18n.confirmDelete || 'Permanently delete all %d pages in this group?').replace('%d', count);
			if (!confirm(msg)) return;
			$.post(ajaxUrl, {
				action: 'meyvora_seo_programmatic_delete_group',
				nonce: nonce,
				term_id: termId
			}).done(function (res) {
				if (res.success) location.reload();
				else alert(res.data && res.data.message ? res.data.message : 'Error');
			}).fail(function () {
				alert(i18n.error || 'Request failed.');
			});
		});
	});
})(jQuery);
