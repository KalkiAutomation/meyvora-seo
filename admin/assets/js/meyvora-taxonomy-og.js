/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-taxonomy-og.js)
*/
(function () {
	"use strict";
	var cfg = window.meyvoraTaxonomyOg || {};
	var mediaTitle = cfg.mediaTitle || "Choose";

	document.querySelectorAll(".meyvora-term-og-pick").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var wrap = btn.closest(".form-field, tr");
			var hiddenInput = document.getElementById("meyvora_seo_term_og_image");
			var preview = wrap ? wrap.querySelector(".meyvora-seo-og-image-preview") : null;
			var removeBtn = wrap ? wrap.querySelector(".meyvora-term-og-remove") : null;
			var frame = wp.media({ title: mediaTitle, multiple: false, library: { type: "image" } });
			frame.on("select", function () {
				var att = frame.state().get("selection").first().toJSON();
				if (hiddenInput) {
					hiddenInput.value = att.id;
				}
				if (preview) {
					var src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
					preview.innerHTML = '<img src="' + src + '" style="max-width:150px;display:block;" alt="" />';
				}
				if (removeBtn) {
					removeBtn.style.display = "";
				}
			});
			frame.open();
		});
	});

	document.querySelectorAll(".meyvora-term-og-remove").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var hiddenInput = document.getElementById("meyvora_seo_term_og_image");
			var wrap = btn.closest(".form-field, tr");
			var preview = wrap ? wrap.querySelector(".meyvora-seo-og-image-preview") : null;
			if (hiddenInput) {
				hiddenInput.value = "";
			}
			if (preview) {
				preview.innerHTML = "";
			}
			btn.style.display = "none";
		});
	});
})();
