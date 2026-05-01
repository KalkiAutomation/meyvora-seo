/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-settings-page.js)
Settings tabs, Local SEO geo helper, Slack test, opening hours serialization, System Info copy.
*/
(function ($) {
	"use strict";

	function initTabs() {
		var hash = location.hash ? location.hash.replace("#", "") : "tab-general";
		$(".meyvora-seo-tab-pane").hide();
		$("#" + hash).show();
		$(".mev-settings-nav-item").removeClass("active").filter('[data-tab="' + hash + '"]').addClass("active");
		$(".mev-settings-nav-item").on("click", function (e) {
			e.preventDefault();
			var t = $(this).data("tab");
			location.hash = t;
			$(".meyvora-seo-tab-pane").hide();
			$("#" + t).show();
			$(".mev-settings-nav-item").removeClass("active");
			$(this).addClass("active");
		});
	}

	function initGeo(cfg) {
		if (!cfg || !cfg.geocodingUrlTemplate) {
			return;
		}
		document.querySelectorAll(".mev-geo-coords-from-address-btn").forEach(function (btn) {
			btn.addEventListener("click", function () {
				var street =
					(document.getElementById("meyvora_seo_schema_lb_street") || document.getElementById("schema_lb_street") || {}).value || "";
				var city =
					(document.getElementById("meyvora_seo_schema_lb_locality") || document.getElementById("schema_lb_locality") || {}).value || "";
				var region =
					(document.getElementById("meyvora_seo_schema_lb_region") || document.getElementById("schema_lb_region") || {}).value || "";
				var country =
					(document.getElementById("meyvora_seo_schema_lb_country") || document.getElementById("schema_lb_country") || {}).value ||
					"";
				var addr = [street, city, region, country].filter(Boolean).join(", ");
				var g = cfg.i18nGeo || {};
				if (!addr) {
					alert(g.fillAddressFirst || "");
					return;
				}
				var url = cfg.geocodingUrlTemplate + encodeURIComponent(addr);
				fetch(url)
					.then(function (r) {
						return r.json();
					})
					.then(function (d) {
						if (d && d[0]) {
							var lat = parseFloat(d[0].lat).toFixed(6);
							var lng = parseFloat(d[0].lon).toFixed(6);
							var elLat = document.getElementById("schema_lb_lat") || document.getElementById("meyvora_seo_schema_lb_lat");
							var elLng = document.getElementById("schema_lb_lng") || document.getElementById("meyvora_seo_schema_lb_lng");
							if (elLat) {
								elLat.value = lat;
							}
							if (elLng) {
								elLng.value = lng;
							}
							alert((g.foundPrefix || "") + lat + ", " + lng + "\n" + (g.saveHint || ""));
						} else {
							alert(g.notFound || "");
						}
					})
					.catch(function () {
						alert(g.failed || "");
					});
			});
		});
	}

	function initSlack(cfg) {
		if (!cfg || !cfg.slackInputId) {
			return;
		}
		var btn = document.getElementById("mev-test-slack-webhook");
		var input = document.getElementById(cfg.slackInputId);
		var result = document.getElementById("mev-test-slack-result");
		if (!btn || !input) {
			return;
		}
		var L = cfg.i18nSlack || {};
		btn.addEventListener("click", function () {
			var url = (input.value || "").trim();
			result.textContent = "";
			if (!url) {
				result.textContent = L.enterUrl || "";
				result.style.color = "var(--mev-warning)";
				return;
			}
			btn.disabled = true;
			var fd = new FormData();
			fd.append("action", cfg.slackAjaxAction || "meyvora_seo_test_slack_webhook");
			fd.append("nonce", cfg.slackNonce || "");
			fd.append("slack_url", url);
			fetch(cfg.ajaxUrl || "", { method: "POST", body: fd, credentials: "same-origin" })
				.then(function (r) {
					return r.json();
				})
				.then(function (data) {
					btn.disabled = false;
					if (data.success) {
						result.textContent = L.sent || "";
						result.style.color = "var(--mev-success)";
					} else {
						result.textContent =
							data.data && data.data.message ? data.data.message : L.failed || "";
						result.style.color = "var(--mev-danger)";
					}
				})
				.catch(function () {
					btn.disabled = false;
					result.textContent = L.requestFailed || "";
					result.style.color = "var(--mev-danger)";
				});
		});
	}

	function initOpeningHours(cfg) {
		if (!cfg || !cfg.openingHoursHiddenId || !$.isArray(cfg.openingDays)) {
			return;
		}
		var wrap = document.querySelector(".mev-opening-hours-wrap");
		if (!wrap) {
			return;
		}
		var hidden = document.getElementById(cfg.openingHoursHiddenId);
		if (!hidden) {
			return;
		}
		var days = cfg.openingDays.slice();
		function serialize() {
			var out = [];
			wrap.querySelectorAll("tbody tr").forEach(function (tr, i) {
				var day = days[i];
				var openCb = tr.querySelector(".mev-hours-open");
				var openInp = tr.querySelector(".mev-hours-open-time");
				var closeInp = tr.querySelector(".mev-hours-close-time");
				out.push({
					day: day,
					closed: openCb ? !openCb.checked : true,
					open: openInp ? openInp.value : "09:00",
					close: closeInp ? closeInp.value : "17:00",
				});
			});
			hidden.value = JSON.stringify(out);
		}
		function toggleRow(openCb) {
			var tr = openCb.closest("tr");
			var openInp = tr.querySelector(".mev-hours-open-time");
			var closeInp = tr.querySelector(".mev-hours-close-time");
			if (openCb.checked) {
				openInp.disabled = false;
				closeInp.disabled = false;
			} else {
				openInp.disabled = true;
				closeInp.disabled = true;
			}
			serialize();
		}
		wrap.querySelectorAll(".mev-hours-open").forEach(function (cb) {
			cb.addEventListener("change", function () {
				toggleRow(cb);
			});
		});
		wrap.querySelectorAll(".mev-hours-open-time, .mev-hours-close-time").forEach(function (inp) {
			inp.addEventListener("change", serialize);
		});
	}

	function initSystemInfoCopy() {
		var cp = document.getElementById("meyvora-copy-system-info");
		var ta = document.getElementById("meyvora-system-info");
		if (!cp || !ta) {
			return;
		}
		cp.addEventListener("click", function () {
			ta.select();
			ta.setSelectionRange(0, 99999);
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(ta.value);
			}
		});
	}

	$(function () {
		initTabs();
		var cfg = window.meyvoraSeoSettings || {};
		initGeo(cfg);
		initSlack(cfg);
		initOpeningHours(cfg);
		initSystemInfoCopy();
	});
})(jQuery);
