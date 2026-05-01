/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Competitor Analysis: fetch competitor URL, display two-column comparison with color coding.
 *
 * @package Meyvora_SEO
 */

(function () {
	"use strict";

	var cfg = typeof meyvoraCompetitor !== "undefined" ? meyvoraCompetitor : {};
	var ajaxUrl = cfg.ajaxUrl || "";
	var nonce = cfg.nonce || "";
	var defaultPostId = (cfg.postId && parseInt(cfg.postId, 10)) || 0;
	var i18n = cfg.i18n || {};
	var lastAnalyzeResult = null;

	function row(label, value, cssClass) {
		var c = cssClass ? " mev-comp-value " + cssClass : " mev-comp-value";
		return '<div class="mev-comp-row"><span class="mev-comp-label">' + escapeHtml(label) + '</span><span class="' + c + '">' + escapeHtml(value) + '</span></div>';
	}

	function escapeHtml(s) {
		if (s === null || s === undefined) return '';
		s = String(s);
		var div = document.createElement("div");
		div.textContent = s;
		return div.innerHTML;
	}

	function renderValue(val) {
		if (val === null || val === undefined || val === "") return "—";
		return String(val);
	}

	function compareNum(compVal, ourVal, higherIsBetter) {
		if (ourVal === null || ourVal === undefined || ourVal === "") return "";
		var c = Number(compVal) || 0;
		var o = Number(ourVal) || 0;
		if (c === o) return "";
		if (higherIsBetter) return c > o ? "mev-stronger" : "mev-weaker";
		return c < o ? "mev-stronger" : "mev-weaker";
	}

	function compareLen(compLen, ourLen) {
		return compareNum(compLen, ourLen, true);
	}

	function buildCompetitorHtml(data) {
		var html = [];
		html.push(row("Title", renderValue(data.title)));
		html.push(row("Meta description", renderValue(data.meta_description)));
		if (data.og && Object.keys(data.og).length) {
			var ogList = "<ul class=\"mev-comp-og-list\">";
			for (var k in data.og) {
				if (data.og.hasOwnProperty(k)) ogList += "<li><strong>" + escapeHtml(k) + "</strong>: " + escapeHtml(String(data.og[k]).substring(0, 200)) + (String(data.og[k]).length > 200 ? "…" : "") + "</li>";
			}
			ogList += "</ul>";
			html.push('<div class="mev-comp-row"><span class="mev-comp-label">OG tags</span><span class="mev-comp-value">' + ogList + "</span></div>");
		} else {
			html.push(row("OG tags", "—"));
		}
		if (data.schema_types && data.schema_types.length) {
			html.push(row("Schema types", data.schema_types.join(", ")));
		} else {
			html.push(row("Schema types", "—"));
		}
		html.push(row("Headings (H1–H4) count", renderValue(data.headings && data.headings.count)));
		if (data.headings && data.headings.first5 && data.headings.first5.length) {
			var list = "<ol class=\"mev-comp-list\">";
			data.headings.first5.forEach(function (h) {
				list += "<li>[" + escapeHtml(h.tag) + "] " + escapeHtml(h.text ? h.text.substring(0, 60) : "") + (h.text && h.text.length > 60 ? "…" : "") + "</li>";
			});
			list += "</ol>";
			html.push('<div class="mev-comp-row"><span class="mev-comp-label">First 5 headings</span><span class="mev-comp-value">' + list + "</span></div>");
		}
		html.push(row("Word count", renderValue(data.word_count)));
		html.push(row("Images total", renderValue(data.images_total)));
		html.push(row("Images with alt", renderValue(data.images_with_alt)));
		if (data.dataforseo) {
			if (data.dataforseo.onpage_score != null) {
				html.push(row("On-Page Score (DataForSEO)", renderValue(data.dataforseo.onpage_score)));
			}
			if (data.dataforseo.top_keywords && data.dataforseo.top_keywords.length) {
				html.push(row("Meta keywords", data.dataforseo.top_keywords.join(", ")));
			}
		}
		return html.join("");
	}

	function buildOursHtml(data) {
		var html = [];
		html.push(row("Title", renderValue(data.title || data.post_title)));
		html.push(row("Meta description", renderValue(data.meta_description)));
		if (data.og && Object.keys(data.og).length) {
			var ogList = "<ul class=\"mev-comp-og-list\">";
			for (var k in data.og) {
				if (data.og.hasOwnProperty(k)) ogList += "<li><strong>" + escapeHtml(k) + "</strong>: " + escapeHtml(String(data.og[k]).substring(0, 200)) + "</li>";
			}
			ogList += "</ul>";
			html.push('<div class="mev-comp-row"><span class="mev-comp-label">OG tags</span><span class="mev-comp-value">' + ogList + "</span></div>");
		} else {
			html.push(row("OG tags", "—"));
		}
		html.push(row("Schema type", renderValue(data.schema_type)));
		html.push(row("Headings (H1–H4) count", renderValue(data.headings_count)));
		html.push(row("Word count", renderValue(data.word_count)));
		html.push(row("Images total", renderValue(data.images_total)));
		html.push(row("Images with alt", renderValue(data.images_with_alt)));
		return html.join("");
	}

	function renderComparison(competitor, ours, competitorUrl, useMetabox) {
		var comp = competitor;
		var our = ours || {};
		var leftId = useMetabox ? "mev-metabox-competitor-data" : "mev-competitor-data";
		var rightId = useMetabox ? "mev-metabox-ours-data" : "mev-ours-data";
		var left = document.getElementById(leftId);
		var right = document.getElementById(rightId);
		if (!left || !right) return;
		left.innerHTML = buildCompetitorHtml(comp);
		right.innerHTML = buildOursHtml(our);

		// Add comparison classes to numeric rows in both columns (optional: re-render with classes)
		var compWord = (comp.word_count && parseInt(comp.word_count, 10)) || 0;
		var ourWord = (our.word_count && parseInt(our.word_count, 10)) || 0;
		var compHead = (comp.headings && comp.headings.count) || 0;
		var ourHead = our.headings_count || 0;
		var compImg = comp.images_total || 0;
		var ourImg = our.images_total || 0;
		var compAlt = comp.images_with_alt || 0;
		var ourAlt = our.images_with_alt || 0;
		// Highlight in competitor column: if competitor has more words, that cell gets mev-stronger (red)
		var wordClassComp = compWord > ourWord ? "mev-stronger" : (compWord < ourWord ? "mev-weaker" : "");
		var wordClassOurs = ourWord > compWord ? "mev-stronger" : (ourWord < compWord ? "mev-weaker" : "");
		// We apply to the value spans; we already rendered so we need to find and add class or re-render with classes.
		// Simpler: add a small script that runs after render to add classes to specific rows by label.
		var rowsLeft = left.querySelectorAll(".mev-comp-row");
		var rowsRight = right.querySelectorAll(".mev-comp-row");
		rowsLeft.forEach(function (r) {
			var lab = r.querySelector(".mev-comp-label");
			var val = r.querySelector(".mev-comp-value");
			if (!lab || !val) return;
			var label = lab.textContent;
			var add = "";
			if (label.indexOf("Word count") >= 0) add = compWord > ourWord ? "mev-stronger" : (compWord < ourWord ? "mev-weaker" : "");
			else if (label.indexOf("Headings") >= 0) add = compHead > ourHead ? "mev-stronger" : (compHead < ourHead ? "mev-weaker" : "");
			else if (label.indexOf("Images total") >= 0) add = compImg > ourImg ? "mev-stronger" : (compImg < ourImg ? "mev-weaker" : "");
			else if (label.indexOf("Images with alt") >= 0) add = compAlt > ourAlt ? "mev-stronger" : (compAlt < ourAlt ? "mev-weaker" : "");
			if (add) val.classList.add(add);
		});
		rowsRight.forEach(function (r) {
			var lab = r.querySelector(".mev-comp-label");
			var val = r.querySelector(".mev-comp-value");
			if (!lab || !val) return;
			var label = lab.textContent;
			var add = "";
			if (label.indexOf("Word count") >= 0) add = ourWord > compWord ? "mev-stronger" : (ourWord < compWord ? "mev-weaker" : "");
			else if (label.indexOf("Headings") >= 0) add = ourHead > compHead ? "mev-stronger" : (ourHead < compHead ? "mev-weaker" : "");
			else if (label.indexOf("Images total") >= 0) add = ourImg > compImg ? "mev-stronger" : (ourImg < compImg ? "mev-weaker" : "");
			else if (label.indexOf("Images with alt") >= 0) add = ourAlt > compAlt ? "mev-stronger" : (ourAlt < compAlt ? "mev-weaker" : "");
			if (add) val.classList.add(add);
		});

		if (useMetabox) {
			document.getElementById("mev-metabox-competitor-results").style.display = "block";
		} else {
			document.getElementById("mev-competitor-display-url").textContent = competitorUrl;
			document.getElementById("mev-ours-display-title").textContent = our.post_title ? "(" + our.post_title.substring(0, 40) + (our.post_title.length > 40 ? "…" : "") + ")" : (our.post_id ? "(ID: " + our.post_id + ")" : "—");
			document.getElementById("mev-competitor-results").style.display = "block";
		}
	}

	function runAnalyze(useMetabox) {
		var urlInput = document.getElementById(useMetabox ? "mev-metabox-competitor-url" : "mev-competitor-url");
		var postSelect = document.getElementById("mev-competitor-post");
		var btn = document.getElementById(useMetabox ? "mev-metabox-competitor-analyze" : "mev-competitor-analyze");
		var errEl = document.getElementById(useMetabox ? "mev-metabox-competitor-error" : "mev-competitor-error");
		if (!urlInput || !btn) return;
		var url = (urlInput.value || "").trim();
		if (!url) {
			if (errEl) { errEl.textContent = "Please enter a competitor URL."; errEl.style.display = "block"; }
			return;
		}
		var postId = defaultPostId;
		if (!useMetabox && postSelect && postSelect.value) postId = parseInt(postSelect.value, 10);
		if (useMetabox) {
			var panel = document.querySelector(".meyvora-seo-panel");
			if (panel && panel.getAttribute("data-post-id")) postId = parseInt(panel.getAttribute("data-post-id"), 10) || 0;
		}
		if (errEl) errEl.style.display = "none";
		btn.disabled = true;
		if (btn.textContent) btn.textContent = i18n.analyzing || "Analyzing…";

		var formData = new FormData();
		formData.append("action", "meyvora_seo_competitor_analyze");
		formData.append("nonce", nonce);
		formData.append("url", url);
		formData.append("post_id", postId);

		fetch(ajaxUrl, {
			method: "POST",
			body: formData,
			credentials: "same-origin",
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				btn.disabled = false;
				btn.textContent = i18n.analyze || "Analyze";
				if (data.success && data.data) {
					lastAnalyzeResult = data.data;
					renderComparison(data.data.competitor, data.data.ours, data.data.url, useMetabox);
				} else {
					if (errEl) {
						errEl.textContent = (data.data && data.data.message) ? data.data.message : (i18n.error || "Something went wrong.");
						errEl.style.display = "block";
					}
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = i18n.analyze || "Analyze";
				if (errEl) {
					errEl.textContent = i18n.error || "Something went wrong.";
					errEl.style.display = "block";
				}
			});
	}

	function runKeywordGap() {
		var urlInput = document.getElementById("mev-competitor-url");
		var btn = document.getElementById("mev-competitor-analyse-gap");
		var errEl = document.getElementById("mev-keyword-gap-error");
		var wrap = document.getElementById("mev-keyword-gap-table-wrap");
		var tbody = document.getElementById("mev-keyword-gap-tbody");
		if (!urlInput || !btn || !tbody) return;
		var url = (urlInput.value || "").trim();
		if (!url) {
			if (errEl) { errEl.textContent = "Please enter a competitor URL first."; errEl.style.display = "block"; }
			return;
		}
		if (errEl) errEl.style.display = "none";
		btn.disabled = true;
		btn.textContent = (cfg.i18n && cfg.i18n.analysingGap) ? cfg.i18n.analysingGap : "Analysing gap…";
		var formData = new FormData();
		formData.append("action", cfg.keywordGapAction || "meyvora_seo_competitor_keyword_gap");
		formData.append("nonce", nonce);
		formData.append("url", url);
		fetch(cfg.ajaxUrl, { method: "POST", body: formData, credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				btn.disabled = false;
				btn.textContent = (cfg.i18n && cfg.i18n.analyseGap) ? cfg.i18n.analyseGap : "Analyse Gap";
				if (data.success && data.data && Array.isArray(data.data.gap)) {
					wrap.style.display = "block";
					var gap = data.data.gap;
					var postChoices = cfg.postChoices || [];
					var editBase = cfg.editPostBaseUrl || "";
					var i18n = cfg.i18n || {};
					tbody.innerHTML = "";
					gap.forEach(function (row) {
						var tr = document.createElement("tr");
						var kw = escapeHtml(row.keyword || "");
						var pos = parseInt(row.competitor_pos, 10) || 0;
						var vol = parseInt(row.competitor_volume, 10) || 0;
						tr.innerHTML =
							"<td><strong>" + kw + "</strong></td>" +
							"<td>" + pos + "</td>" +
							"<td>" + vol + "</td>" +
							"<td><select class=\"mev-gap-post-select\" data-keyword=\"" + escapeHtml(escapeAttr(row.keyword || "")) + "\" style=\"max-width:200px;margin-right:8px;\">" +
							"<option value=\"\">— " + (i18n.setFocusOn || "Set as focus keyword on") + " —</option>" +
							postChoices.map(function (p) {
								return "<option value=\"" + p.id + "\">" + escapeHtml((p.title || "").substring(0, 40)) + (p.title && p.title.length > 40 ? "…" : "") + "</option>";
							}).join("") +
							"</select>" +
							"<a href=\"#\" class=\"mev-gap-set-focus mev-btn mev-btn--sm\" data-keyword=\"" + escapeHtml(escapeAttr(row.keyword || "")) + "\">" + (i18n.setFocusOn || "Set as focus keyword on") + "</a></td>";
						tbody.appendChild(tr);
					});
					// Link: on click get selected post id from same row, build edit URL with keyword param
					tbody.querySelectorAll(".mev-gap-set-focus").forEach(function (a) {
						a.addEventListener("click", function (e) {
							e.preventDefault();
							var tr = this.closest("tr");
							var sel = tr ? tr.querySelector(".mev-gap-post-select") : null;
							var pid = sel && sel.value ? sel.value : "";
							var keyword = (this.getAttribute("data-keyword") || "").replace(/&quot;/g, '"');
							if (!pid || !editBase) return;
							var u = editBase + "?post=" + encodeURIComponent(pid) + "&action=edit&meyvora_focus_keyword=" + encodeURIComponent(keyword);
							window.location.href = u;
						});
					});
				} else {
					if (errEl) {
						errEl.textContent = (data.data && data.data.message) ? data.data.message : ((cfg.i18n && cfg.i18n.error) ? cfg.i18n.error : "Something went wrong.");
						errEl.style.display = "block";
					}
					if (wrap) wrap.style.display = "none";
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = (cfg.i18n && cfg.i18n.analyseGap) ? cfg.i18n.analyseGap : "Analyse Gap";
				if (errEl) { errEl.textContent = (cfg.i18n && cfg.i18n.error) ? cfg.i18n.error : "Something went wrong."; errEl.style.display = "block"; }
			});
	}

	function runContentGap() {
		var btn = document.getElementById("mev-content-gap-analyse");
		var errEl = document.getElementById("mev-content-gap-error");
		var outputWrap = document.getElementById("mev-content-gap-output");
		var missingEl = document.getElementById("mev-content-gap-missing");
		var headingsEl = document.getElementById("mev-content-gap-headings");
		var depthEl = document.getElementById("mev-content-gap-depth");
		var wcNoteEl = document.getElementById("mev-content-gap-wc-note");
		if (!btn || !errEl || !outputWrap) return;
		if (!lastAnalyzeResult) {
			errEl.textContent = "Run Analyse above first, then click Analyse Gap here.";
			errEl.style.display = "block";
			outputWrap.style.display = "none";
			return;
		}
		var ours = lastAnalyzeResult.ours || {};
		var comp = lastAnalyzeResult.competitor || {};
		var title = (ours.title || ours.post_title || "").trim();
		var focusKw = (ours.focus_keyword || "").trim();
		errEl.style.display = "none";
		btn.disabled = true;
		btn.textContent = "Analysing…";
		var formData = new FormData();
		formData.append("action", cfg.aiProxyAction || "meyvora_seo_ai_request");
		formData.append("nonce", cfg.aiNonce || "");
		formData.append("action_type", "competitor_gap");
		formData.append("our_headings", lastAnalyzeResult.our_headings_str || "");
		formData.append("comp_headings", lastAnalyzeResult.comp_headings_str || "");
		formData.append("our_word_count", String(ours.word_count || 0));
		formData.append("comp_word_count", String(comp.word_count || 0));
		formData.append("title", title);
		formData.append("focus_keyword", focusKw);
		fetch(cfg.ajaxUrl || ajaxUrl, { method: "POST", body: formData, credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				btn.disabled = false;
				btn.textContent = "Analyse Gap";
				if (res.success && res.data && res.data.analysis) {
					var a = res.data.analysis;
					var missing = Array.isArray(a.missing_topics) ? a.missing_topics : [];
					var suggested = Array.isArray(a.suggested_headings) ? a.suggested_headings : [];
					missingEl.innerHTML = missing.length ? "<strong>Missing topics</strong><ul class=\"mev-comp-list\" style=\"margin:8px 0 0 18px;\">" + missing.map(function (t) { return "<li>" + escapeHtml(String(t)) + "</li>"; }).join("") + "</ul>" : "<strong>Missing topics</strong><p style=\"margin:8px 0 0;\">None identified.</p>";
					headingsEl.innerHTML = suggested.length ? "<strong>Suggested headings to add</strong> (copyable)<pre style=\"margin:8px 0 0;padding:10px;background:var(--mev-gray-100, #f3f4f6);border-radius:4px;white-space:pre-wrap;font-size:13px;\">" + escapeHtml(suggested.join("\n")) + "</pre>" : "<strong>Suggested headings to add</strong><p style=\"margin:8px 0 0;\">None.</p>";
					depthEl.textContent = (a.depth_gap && String(a.depth_gap).trim()) ? String(a.depth_gap) : "";
					wcNoteEl.textContent = (a.word_count_note && String(a.word_count_note).trim()) ? String(a.word_count_note) : "";
					outputWrap.style.display = "block";
				} else {
					errEl.textContent = (res.data && res.data.message) ? res.data.message : (i18n.error || "Something went wrong.");
					errEl.style.display = "block";
					outputWrap.style.display = "none";
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.textContent = "Analyse Gap";
				errEl.textContent = i18n.error || "Something went wrong.";
				errEl.style.display = "block";
				outputWrap.style.display = "none";
			});
	}

	function escapeAttr(s) {
		return String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
	}

	function loadSnapshotList() {
		var listEl = document.getElementById("mev-snapshot-history-list");
		var detailEl = document.getElementById("mev-snapshot-detail");
		var compareEl = document.getElementById("mev-snapshot-compare");
		var compareBtn = document.getElementById("mev-snapshot-compare-btn");
		if (!listEl) return;
		if (detailEl) detailEl.style.display = "none";
		if (compareEl) compareEl.style.display = "none";
		if (compareBtn) compareBtn.style.display = "none";
		listEl.innerHTML = "<p class=\"description\">" + (cfg.i18n && cfg.i18n.noSnapshots ? cfg.i18n.noSnapshots : "Loading…") + "</p>";
		var formData = new FormData();
		formData.append("action", cfg.snapshotListAction || "meyvora_seo_competitor_snapshot_list");
		formData.append("nonce", nonce);
		fetch(cfg.ajaxUrl || ajaxUrl, { method: "POST", body: formData, credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data.success || !data.data || !data.data.urls) {
					listEl.innerHTML = "<p class=\"description\">" + escapeHtml((cfg.i18n && cfg.i18n.noSnapshots) ? cfg.i18n.noSnapshots : "No snapshots yet.") + "</p>";
					return;
				}
				var urls = data.data.urls;
				if (urls.length === 0) {
					listEl.innerHTML = "<p class=\"description\">" + escapeHtml((cfg.i18n && cfg.i18n.noSnapshots) ? cfg.i18n.noSnapshots : "No snapshots yet.") + "</p>";
					return;
				}
				var i18n = cfg.i18n || {};
				var html = [];
				urls.forEach(function (u) {
					var url = u.url || "";
					var snapshots = u.snapshots || [];
					html.push("<div class=\"mev-history-url-block\" style=\"margin-bottom:20px;\">");
					html.push("<div style=\"font-weight:600;margin-bottom:8px;word-break:break-all;\">" + escapeHtml(url) + "</div>");
					html.push("<ul class=\"mev-snapshot-list\" style=\"margin:0;padding-left:18px;list-style:disc;\">");
					snapshots.forEach(function (s) {
						var id = s.id;
						var at = s.created_at || "";
						html.push("<li style=\"margin-bottom:4px;\"><label style=\"cursor:pointer;\"><input type=\"checkbox\" class=\"mev-snapshot-select\" data-id=\"" + id + "\" style=\"margin-right:6px;\">");
						html.push("<a href=\"#\" class=\"mev-snapshot-link\" data-id=\"" + id + "\">" + escapeHtml(at) + "</a></label></li>");
					});
					html.push("</ul></div>");
				});
				listEl.innerHTML = html.join("");
				listEl.querySelectorAll(".mev-snapshot-link").forEach(function (a) {
					a.addEventListener("click", function (e) {
						e.preventDefault();
						var id = this.getAttribute("data-id");
						if (!id) return;
						var fd = new FormData();
						fd.append("action", cfg.snapshotGetAction || "meyvora_seo_competitor_snapshot_get");
						fd.append("nonce", nonce);
						fd.append("id", id);
						fetch(cfg.ajaxUrl || ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
							.then(function (r) { return r.json(); })
							.then(function (res) {
								if (!res.success || !res.data) return;
								var d = res.data;
								var detailEl = document.getElementById("mev-snapshot-detail");
								if (!detailEl) return;
								detailEl.innerHTML = "<strong>" + (i18n.snapshotAt || "Snapshot at") + " " + escapeHtml(d.created_at || "") + "</strong>" +
									"<div class=\"mev-comp-row\" style=\"margin-top:8px;\"><span class=\"mev-comp-label\">Title</span><span class=\"mev-comp-value\">" + escapeHtml(d.title || "—") + "</span></div>" +
									"<div class=\"mev-comp-row\"><span class=\"mev-comp-label\">" + (i18n.wordCount || "Word count") + "</span><span class=\"mev-comp-value\">" + (d.word_count || 0) + "</span></div>" +
									"<div class=\"mev-comp-row\"><span class=\"mev-comp-label\">" + (i18n.schemaTypes || "Schema types") + "</span><span class=\"mev-comp-value\">" + (Array.isArray(d.schema_types) ? escapeHtml(d.schema_types.join(", ")) : "—") + "</span></div>";
								detailEl.style.display = "block";
							});
					});
				});
				listEl.querySelectorAll(".mev-snapshot-select").forEach(function (cb) {
					cb.addEventListener("change", function () {
						var checked = listEl.querySelectorAll(".mev-snapshot-select:checked");
						if (checked.length > 2) {
							this.checked = false;
							checked = listEl.querySelectorAll(".mev-snapshot-select:checked");
						}
						var compareBtn = document.getElementById("mev-snapshot-compare-btn");
						if (compareBtn) compareBtn.style.display = (checked.length === 2) ? "inline-block" : "none";
					});
				});
			})
			.catch(function () {
				listEl.innerHTML = "<p class=\"description\" style=\"color:var(--mev-danger);\">" + (i18n.error || "Something went wrong.") + "</p>";
			});
	}

	function runSnapshotCompare() {
		var listEl = document.getElementById("mev-snapshot-history-list");
		var compareEl = document.getElementById("mev-snapshot-compare");
		var i18n = cfg.i18n || {};
		if (!listEl || !compareEl) return;
		var checked = listEl.querySelectorAll(".mev-snapshot-select:checked");
		if (checked.length !== 2) {
			compareEl.innerHTML = "<p>" + (i18n.selectTwo || "Select two snapshots to compare.") + "</p>";
			compareEl.style.display = "block";
			return;
		}
		var id1 = checked[0].getAttribute("data-id");
		var id2 = checked[1].getAttribute("data-id");
		var formData = new FormData();
		formData.append("action", cfg.snapshotCompareAction || "meyvora_seo_competitor_snapshot_compare");
		formData.append("nonce", nonce);
		formData.append("id1", id1);
		formData.append("id2", id2);
		fetch(cfg.ajaxUrl || ajaxUrl, { method: "POST", body: formData, credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res.success || !res.data) {
					compareEl.innerHTML = "<p>" + (res.data && res.data.message ? escapeHtml(res.data.message) : (i18n.error || "Error")) + "</p>";
					compareEl.style.display = "block";
					return;
				}
				var changes = res.data.changes || [];
				var html = "<strong>" + (i18n.changes || "Changes") + "</strong>";
				if (changes.length === 0) {
					html += "<p style=\"margin:8px 0 0;\">" + (i18n.noChanges || "No significant changes.") + "</p>";
				} else {
					html += "<ul style=\"margin:8px 0 0;padding-left:18px;\">";
					changes.forEach(function (c) {
						if (c.field === "title") {
							html += "<li>Title: \"" + escapeHtml(String(c.old || "")) + "\" → \"" + escapeHtml(String(c.new || "")) + "\"</li>";
						} else if (c.field === "word_count") {
							html += "<li>Word count: " + (c.old || 0) + " → " + (c.new || 0) + " (" + (c.pct_change || 0) + "% change)</li>";
						} else if (c.field === "h1") {
							html += "<li>H1: \"" + escapeHtml(String(c.old || "")) + "\" → \"" + escapeHtml(String(c.new || "")) + "\"</li>";
						}
					});
					html += "</ul>";
				}
				compareEl.innerHTML = html;
				compareEl.style.display = "block";
			})
			.catch(function () {
				compareEl.innerHTML = "<p style=\"color:var(--mev-danger);\">" + (i18n.error || "Something went wrong.") + "</p>";
				compareEl.style.display = "block";
			});
	}

	document.addEventListener("DOMContentLoaded", function () {
		var btn = document.getElementById("mev-competitor-analyze");
		if (btn) btn.addEventListener("click", function () { runAnalyze(false); });

		var metaboxBtn = document.getElementById("mev-metabox-competitor-analyze");
		if (metaboxBtn) metaboxBtn.addEventListener("click", function () { runAnalyze(true); });

		var gapBtn = document.getElementById("mev-competitor-analyse-gap");
		if (gapBtn) gapBtn.addEventListener("click", function () { runKeywordGap(); });

		var contentGapBtn = document.getElementById("mev-content-gap-analyse");
		if (contentGapBtn) contentGapBtn.addEventListener("click", function () { runContentGap(); });

		// Tabs: Analyze | History
		var navTabs = document.querySelectorAll(".nav-tab-wrapper .nav-tab[data-tab]");
		var tabPanels = document.querySelectorAll(".mev-competitor-tab");
		navTabs.forEach(function (tab) {
			tab.addEventListener("click", function (e) {
				e.preventDefault();
				var t = this.getAttribute("data-tab");
				navTabs.forEach(function (x) { x.classList.remove("nav-tab-active"); });
				tabPanels.forEach(function (p) {
					if ((p.getAttribute("data-tab") || "") === t) {
						p.style.display = "";
					} else {
						p.style.display = "none";
					}
				});
				this.classList.add("nav-tab-active");
				if (t === "history") {
					loadSnapshotList();
				}
			});
		});
		var compareBtn = document.getElementById("mev-snapshot-compare-btn");
		if (compareBtn) compareBtn.addEventListener("click", function () { runSnapshotCompare(); });
	});
})();
