/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-redirects-tabs.js)
*/
(function () {
	"use strict";
	var tabs = document.querySelectorAll(".mev-rdrtab");
	tabs.forEach(function (btn) {
		btn.addEventListener("click", function () {
			tabs.forEach(function (b) {
				b.style.background = "none";
				b.style.color = "var(--mev-gray-500)";
				b.style.fontWeight = "500";
				b.style.boxShadow = "none";
				b.classList.remove("active");
			});
			this.style.background = "var(--mev-surface)";
			this.style.color = "var(--mev-gray-900)";
			this.style.fontWeight = "600";
			this.style.boxShadow = "var(--mev-shadow-sm)";
			this.classList.add("active");
			document.querySelectorAll('[id^="mev-rdr-"]').forEach(function (p) {
				p.style.display = "none";
			});
			var target = document.getElementById(btn.dataset.target);
			if (target) {
				target.style.display = "block";
			}
		});
	});
})();
