/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-gsc-keywords-sidebar.js)
Fetches GSC keywords via admin-ajax and renders with DOM APIs (textContent, no innerHTML for API data).
*/
(function () {
	"use strict";

	function clearWrap(wrap) {
		while (wrap.firstChild) {
			wrap.removeChild(wrap.firstChild);
		}
	}

	function appendMessageParagraph(wrap, text, className) {
		var p = document.createElement("p");
		p.className = className || "mev-gsc-sidebar-text";
		p.textContent = text || "";
		wrap.appendChild(p);
	}

	document.addEventListener("DOMContentLoaded", function () {
		var cfg = typeof meyvoraGscSidebar === "undefined" ? {} : meyvoraGscSidebar;
		var wrap = document.getElementById("mev-gsc-keywords-wrap");
		if (!wrap || !cfg.ajaxUrl) {
			return;
		}
		var postId = wrap.getAttribute("data-post-id");
		var pageUrl = wrap.getAttribute("data-url");
		if (!postId || !pageUrl) {
			return;
		}

		var fd = new FormData();
		fd.append("action", "meyvora_gsc_keywords");
		fd.append("nonce", cfg.nonce || "");
		fd.append("post_id", postId);
		fd.append("url", pageUrl);

		fetch(cfg.ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				clearWrap(wrap);
				var L = cfg.i18n || {};
				if (res.success && res.data && res.data.rows) {
					var rows = res.data.rows;
					if (!rows.length) {
						appendMessageParagraph(wrap, L.empty || "", "mev-gsc-sidebar-text");
						return;
					}
					var ul = document.createElement("ul");
					ul.className = "mev-gsc-keywords-list";
					var max = Math.min(10, rows.length);
					for (var i = 0; i < max; i++) {
						var row = rows[i];
						var li = document.createElement("li");
						var qspan = document.createElement("span");
						qspan.className = "mev-gsc-kw-query";
						var q = row.keys && row.keys[0] != null ? String(row.keys[0]) : "";
						qspan.textContent = q;
						var mspan = document.createElement("span");
						mspan.className = "mev-gsc-kw-meta";
						var clicks = row.clicks || 0;
						var impr = row.impressions || 0;
						var pos =
							row.position !== undefined && row.position !== null
								? ", pos. " + Number(row.position).toFixed(1)
								: "";
						mspan.textContent = clicks + " clicks, " + impr + " impr." + pos;
						li.appendChild(qspan);
						li.appendChild(document.createTextNode(" "));
						li.appendChild(mspan);
						ul.appendChild(li);
					}
					wrap.appendChild(ul);
				} else {
					appendMessageParagraph(wrap, L.error || "", "mev-gsc-sidebar-text");
				}
			})
			.catch(function () {
				clearWrap(wrap);
				var L = cfg.i18n || {};
				appendMessageParagraph(wrap, L.error || "", "mev-gsc-sidebar-text");
			});
	});
})();
