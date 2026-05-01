/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-audit-expand.js)
*/
(function () {
	"use strict";
	var DURATION_MS = 350;

	function mevToggleDetail(pid) {
		var detailBody = document.getElementById("mev-detail-body-" + pid);
		var btn = document.querySelector('.mev-row-expand-btn[data-pid="' + pid + '"]');
		if (!detailBody) {
			return;
		}
		var isOpen = detailBody.classList.contains("is-open");

		if (isOpen) {
			var startHeight = detailBody.scrollHeight;
			detailBody.style.height = startHeight + "px";
			detailBody.style.padding = "14px 20px";
			detailBody.offsetHeight;
			detailBody.classList.remove("is-open");
			detailBody.style.height = "0";
			detailBody.style.padding = "0 20px";
			detailBody.addEventListener(
				"transitionend",
				function onCloseEnd(e) {
					if (e.propertyName !== "height") {
						return;
					}
					detailBody.removeEventListener("transitionend", onCloseEnd);
					detailBody.style.height = "";
					detailBody.style.padding = "";
				},
				{ once: true }
			);
		} else {
			detailBody.classList.add("is-open");
			detailBody.style.height = "0";
			detailBody.style.padding = "0 20px";
			detailBody.offsetHeight;
			var contentHeight = detailBody.scrollHeight;
			var endHeight = contentHeight + 28;
			detailBody.style.height = endHeight + "px";
			detailBody.style.padding = "14px 20px";
			detailBody.addEventListener(
				"transitionend",
				function onOpenEnd(e) {
					if (e.propertyName !== "height") {
						return;
					}
					detailBody.removeEventListener("transitionend", onOpenEnd);
					detailBody.style.height = "auto";
					detailBody.style.padding = "14px 20px";
				},
				{ once: true }
			);
		}

		if (btn) {
			btn.style.transform = isOpen ? "rotate(0deg)" : "rotate(90deg)";
			btn.style.color = isOpen ? "" : "var(--mev-primary)";
		}
		var dataRow = document.querySelector('.mev-audit-row[data-pid="' + pid + '"]');
		if (dataRow) {
			dataRow.classList.toggle("is-expanded", !isOpen);
		}
	}
	window.mevToggleDetail = mevToggleDetail;

	document.addEventListener("DOMContentLoaded", function () {
		document.querySelectorAll(".mev-audit-row").forEach(function (row) {
			row.addEventListener("click", function (e) {
				if (e.target.tagName === "A" || e.target.tagName === "INPUT") {
					return;
				}
				var pid = this.dataset.pid;
				if (pid) {
					mevToggleDetail(pid);
				}
			});
		});
	});
})();
