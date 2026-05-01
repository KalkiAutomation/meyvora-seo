/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-internal-links-analysis.js)
*/
(function () {
	"use strict";
	function initLinkFilters() {
		var table = document.getElementById("mev-link-analysis-table");
		var filterBar = document.getElementById("mev-link-filter");
		var searchInput = document.getElementById("mev-link-filter-search");
		if (!table) {
			return;
		}
		var currentTabFilter = "all";
		function applyFilters() {
			var search = searchInput ? searchInput.value.trim().toLowerCase() : "";
			var rows = table.querySelectorAll(".mev-link-row");
			rows.forEach(function (tr) {
				var rowStatus = tr.getAttribute("data-status") || "";
				var rowTitle = (tr.getAttribute("data-title") || "").toLowerCase();
				var showStatus = currentTabFilter === "all" || rowStatus === currentTabFilter;
				var showSearch = !search || rowTitle.indexOf(search) !== -1;
				tr.style.display = showStatus && showSearch ? "" : "none";
			});
		}
		if (filterBar) {
			filterBar.querySelectorAll(".mev-ftab").forEach(function (btn) {
				btn.addEventListener("click", function () {
					filterBar.querySelectorAll(".mev-ftab").forEach(function (b) {
						b.classList.remove("mev-ftab--active");
					});
					this.classList.add("mev-ftab--active");
					currentTabFilter = this.getAttribute("data-filter") || "all";
					applyFilters();
				});
			});
		}
		if (searchInput) {
			searchInput.addEventListener("input", applyFilters);
		}
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initLinkFilters);
	} else {
		initLinkFilters();
	}
})();
