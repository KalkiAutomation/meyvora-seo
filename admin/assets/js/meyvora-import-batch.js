/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-import-batch.js)
*/
(function ($) {
	"use strict";
	var cfg = window.meyvoraSeoImport || {};
	var batchSize = cfg.batchSize || 100;
	var ajaxurl = typeof window.ajaxurl !== "undefined" ? window.ajaxurl : cfg.ajaxUrl || "";
	var nonce = cfg.nonce || "";

	$("#mev-import-start").on("click", function () {
		var source = $("#mev-import-source").val();
		var dryRun = $("#mev-import-dry-run").prop("checked");
		var deleteAfter = $("#mev-import-delete-after").prop("checked");
		var importRedirects = $("#mev-import-redirects").prop("checked");
		var total = 0;
		var offset = 0;
		var summary = {
			titles: 0,
			descriptions: 0,
			focus_keywords: 0,
			noindex: 0,
			nofollow: 0,
			canonical: 0,
			og_image: 0,
			processed: 0,
			redirects: 0,
		};

		$("#mev-import-summary").hide();
		$("#mev-import-progress").show();
		$("#mev-import-start").prop("disabled", true);

		function runBatch() {
			$.post(ajaxurl, {
				action: "meyvora_seo_import_batch",
				nonce: nonce,
				source: source,
				offset: offset,
				dry_run: dryRun ? 1 : 0,
				delete_after: deleteAfter ? 1 : 0,
				import_redirects: offset === 0 && importRedirects ? 1 : 0,
			})
				.done(function (res) {
					if (!res.success || !res.data) {
						$("#mev-import-progress-text").text(
							"Error: " + (res.data && res.data.message ? res.data.message : "Unknown")
						);
						$("#mev-import-start").prop("disabled", false);
						return;
					}
					var d = res.data;
					if (d.batch_counts) {
						summary.titles += d.batch_counts.titles || 0;
						summary.descriptions += d.batch_counts.descriptions || 0;
						summary.focus_keywords += d.batch_counts.focus_keywords || 0;
						summary.noindex += d.batch_counts.noindex || 0;
						summary.nofollow += d.batch_counts.nofollow || 0;
						summary.canonical += d.batch_counts.canonical || 0;
						summary.og_image += d.batch_counts.og_image || 0;
						summary.processed += d.batch_counts.processed || 0;
					}
					if (d.redirects !== undefined) {
						summary.redirects = d.redirects;
					}
					if (d.total !== undefined) {
						total = d.total;
					}
					offset = d.offset !== undefined ? d.offset : offset + batchSize;
					var pct = total > 0 ? Math.min(100, Math.round((Math.min(offset, total) / total) * 100)) : 100;
					$("#mev-import-progress-fill").css("width", pct + "%");
					var doneCount = total > 0 ? Math.min(offset, total) : summary.processed;
					$("#mev-import-progress-text").text(
						(d.dry_run ? "Dry run: " : "") + "Processed " + doneCount + " of " + (total || summary.processed) + " posts…"
					);
					if (d.done) {
						$("#mev-import-progress-text").text(d.dry_run ? "Dry run complete." : "Import complete.");
						$("#mev-import-progress-fill").css("width", "100%");
						var list = $("#mev-import-summary-list").empty();
						list.append("<li>Titles: " + summary.titles + "</li>");
						list.append("<li>Descriptions: " + summary.descriptions + "</li>");
						list.append("<li>Focus keywords: " + summary.focus_keywords + "</li>");
						list.append("<li>Noindex: " + summary.noindex + "</li>");
						list.append("<li>Canonical: " + summary.canonical + "</li>");
						list.append("<li>OG image: " + summary.og_image + "</li>");
						list.append("<li>Posts processed: " + summary.processed + "</li>");
						if (summary.redirects > 0) {
							list.append("<li>Redirects: " + summary.redirects + "</li>");
						}
						$("#mev-import-summary").show();
						$("#mev-import-start").prop("disabled", false);
						return;
					}
					setTimeout(runBatch, 100);
				})
				.fail(function () {
					$("#mev-import-progress-text").text("Request failed.");
					$("#mev-import-start").prop("disabled", false);
				});
		}
		runBatch();
	});
})(jQuery);
