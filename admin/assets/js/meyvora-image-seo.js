/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-image-seo.js)
*/
(function () {
	"use strict";
	var cfg = window.meyvoraImageSeo || {};
	var N = cfg.nonce || "";
	var A = cfg.action || "";
	var U = cfg.ajaxUrl || "";
	var L = cfg.i18n || {};

	function saveRow(row, btn) {
		var id = parseInt(row.dataset.id, 10);
		var alt = row.querySelector(".mev-input-alt").value;
		var title = row.querySelector(".mev-input-title").value;
		row.classList.add("saving");
		btn.disabled = true;
		var fd = new FormData();
		fd.append("action", A);
		fd.append("nonce", N);
		fd.append("post_id", id);
		fd.append("alt", alt);
		fd.append("title", title);
		fetch(U, { method: "POST", body: fd, credentials: "same-origin" })
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				row.classList.remove("saving");
				btn.disabled = false;
				if (res && res.success) {
					btn.classList.add("btn-saved");
					row.classList.add("saved");
					var altInput = row.querySelector(".mev-input-alt");
					if (altInput.value.trim() !== "") {
						altInput.classList.remove("input-missing");
					}
					setTimeout(function () {
						btn.classList.remove("btn-saved");
						row.classList.remove("saved");
					}, 2000);
				} else {
					btn.classList.add("btn-error");
					row.classList.add("error-row");
					setTimeout(function () {
						btn.classList.remove("btn-error");
						row.classList.remove("error-row");
					}, 2500);
				}
			})
			.catch(function () {
				row.classList.remove("saving");
				btn.disabled = false;
				btn.classList.add("btn-error");
				setTimeout(function () {
					btn.classList.remove("btn-error");
				}, 2500);
			});
	}

	document.querySelectorAll(".mev-save-row").forEach(function (btn) {
		btn.addEventListener("click", function () {
			saveRow(btn.closest(".mev-img-row"), btn);
		});
	});

	var selAll = document.getElementById("mev-select-all");
	var saveSel = document.getElementById("mev-save-selected");

	function updateSaveBtn() {
		var checked = document.querySelectorAll(".mev-row-select:checked").length;
		saveSel.disabled = checked === 0;
		saveSel.textContent =
			checked > 0 ? (L.saveSelected || "") + " (" + checked + ")" : L.saveSelected || "";
	}

	if (selAll) {
		selAll.addEventListener("change", function () {
			document.querySelectorAll(".mev-row-select").forEach(function (cb) {
				cb.checked = selAll.checked;
			});
			updateSaveBtn();
		});
	}

	document.querySelectorAll(".mev-row-select").forEach(function (cb) {
		cb.addEventListener("change", updateSaveBtn);
	});

	if (saveSel) {
		saveSel.addEventListener("click", function () {
			document.querySelectorAll(".mev-row-select:checked").forEach(function (cb) {
				var row = cb.closest(".mev-img-row");
				var btn = row ? row.querySelector(".mev-save-row") : null;
				if (row && btn) {
					saveRow(row, btn);
				}
			});
		});
	}
})();
