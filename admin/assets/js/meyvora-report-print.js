/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-report-print.js)
*/
(function () {
	"use strict";
	var cfg = window.meyvoraReportPrint || {};
	if (cfg.autoPrintPdf) {
		window.addEventListener("load", function () {
			window.print();
		});
		return;
	}
	if (cfg.checkPrintQuery && window.location.search.indexOf("print=1") !== -1) {
		window.print();
	}
})();
