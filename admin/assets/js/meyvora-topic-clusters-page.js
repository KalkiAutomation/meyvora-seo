/*
== Source Code == https://github.com/KalkiAutomation/meyvora-seo (admin/assets/js/meyvora-topic-clusters-page.js)
*/
(function () {
	"use strict";
	var cfg = window.meyvoraTopicClusters || {};
	var clusters = cfg.clusters || [];
	var L = cfg.i18n || {};

	function searchPosts(q, cb) {
		var xhr = new XMLHttpRequest();
		var url =
			cfg.ajaxUrl +
			"?action=meyvora_seo_cluster_search_posts&nonce=" +
			encodeURIComponent(cfg.nonce || "") +
			(q ? "&s=" + encodeURIComponent(q) : "");
		xhr.open("GET", url);
		xhr.onload = function () {
			try {
				var r = JSON.parse(xhr.responseText);
				if (r.success && r.data && r.data.posts) {
					cb(r.data.posts);
				} else {
					cb([]);
				}
			} catch (e) {
				cb([]);
			}
		};
		xhr.onerror = function () {
			cb([]);
		};
		xhr.send();
	}

	function showPillarResults(list) {
		var el = document.getElementById("mev-pillar-results");
		el.innerHTML = "";
		if (list.length === 0) {
			el.style.display = "none";
			return;
		}
		list.forEach(function (p) {
			var div = document.createElement("div");
			div.setAttribute("role", "button");
			div.tabIndex = 0;
			div.style.cssText =
				"padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--mev-border);";
			div.textContent = p.title;
			div.onclick = function () {
				document.getElementById("mev-pillar-id").value = p.id;
				document.getElementById("mev-pillar-search").value = p.title;
				document.getElementById("mev-pillar-selected").textContent = "ID: " + p.id;
				el.style.display = "none";
			};
			el.appendChild(div);
		});
		el.style.display = "block";
	}

	var clusterSelectedIds = [];

	function showClusterResults(list, selectedIds) {
		var el = document.getElementById("mev-cluster-results");
		el.innerHTML = "";
		var ids = selectedIds || [];
		list = list.filter(function (p) {
			return ids.indexOf(p.id) === -1;
		});
		if (list.length === 0) {
			el.style.display = "none";
			return;
		}
		list.forEach(function (p) {
			var div = document.createElement("div");
			div.setAttribute("role", "button");
			div.tabIndex = 0;
			div.style.cssText =
				"padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--mev-border);";
			div.textContent = p.title;
			div.onclick = function () {
				ids.push(p.id);
				renderClusterTags(ids);
				document.getElementById("mev-cluster-search").value = "";
				el.style.display = "none";
			};
			el.appendChild(div);
		});
		el.style.display = "block";
	}

	function renderClusterTags(ids) {
		clusterSelectedIds = ids.slice();
		var wrap = document.getElementById("mev-cluster-selected");
		wrap.innerHTML = "";
		ids.forEach(function (id) {
			var span = document.createElement("span");
			span.style.cssText =
				"display:inline-flex;align-items:center;gap:4px;padding:4px 8px;background:var(--mev-gray-100);border-radius:6px;font-size:12px;";
			span.textContent = "ID " + id;
			var btn = document.createElement("button");
			btn.type = "button";
			btn.textContent = "×";
			btn.style.cssText = "background:none;border:none;cursor:pointer;padding:0 2px;font-size:14px;";
			btn.onclick = function () {
				clusterSelectedIds = clusterSelectedIds.filter(function (x) {
					return x !== id;
				});
				renderClusterTags(clusterSelectedIds);
			};
			span.appendChild(btn);
			wrap.appendChild(span);
		});
	}

	var pillarSearch = document.getElementById("mev-pillar-search");
	var pillarResults = document.getElementById("mev-pillar-results");
	if (pillarSearch) {
		var pillarTimer;
		pillarSearch.addEventListener("input", function () {
			clearTimeout(pillarTimer);
			var q = this.value.trim();
			if (q.length < 2) {
				pillarResults.style.display = "none";
				return;
			}
			pillarTimer = setTimeout(function () {
				searchPosts(q, showPillarResults);
			}, 250);
		});
		pillarSearch.addEventListener("blur", function () {
			setTimeout(function () {
				pillarResults.style.display = "none";
			}, 150);
		});
	}

	var clusterSearch = document.getElementById("mev-cluster-search");
	var clusterResults = document.getElementById("mev-cluster-results");
	if (clusterSearch) {
		var clusterTimer;
		clusterSearch.addEventListener("input", function () {
			clearTimeout(clusterTimer);
			var q = this.value.trim();
			clusterTimer = setTimeout(function () {
				searchPosts(q, function (list) {
					showClusterResults(list, clusterSelectedIds);
				});
			}, 250);
		});
		clusterSearch.addEventListener("blur", function () {
			setTimeout(function () {
				clusterResults.style.display = "none";
			}, 150);
		});
	}

	var addBtn = document.getElementById("mev-cluster-add-btn");
	if (addBtn) {
		addBtn.addEventListener("click", function () {
			var name = document.getElementById("mev-cluster-name").value.trim();
			var pillarId = parseInt(document.getElementById("mev-pillar-id").value, 10) || 0;
			if (!name || pillarId <= 0) {
				alert(L.enterNameAndPillar || "");
				return;
			}
			clusters.push({ name: name, pillar_id: pillarId, cluster_ids: clusterSelectedIds.slice() });
			clusterSelectedIds = [];
			renderClusterTags([]);
			document.getElementById("mev-cluster-name").value = "";
			document.getElementById("mev-pillar-id").value = "";
			document.getElementById("mev-pillar-search").value = "";
			document.getElementById("mev-pillar-selected").textContent = "";
			saveClusters();
		});
	}

	function saveClusters() {
		document.getElementById("mev-clusters-json").value = JSON.stringify(clusters);
		var fd = new FormData();
		fd.append("action", "meyvora_seo_cluster_save");
		fd.append("nonce", cfg.nonce || "");
		fd.append("clusters", JSON.stringify(clusters));
		var xhr = new XMLHttpRequest();
		xhr.open("POST", cfg.ajaxUrl || "");
		xhr.onload = function () {
			try {
				var r = JSON.parse(xhr.responseText);
				if (r.success) {
					location.reload();
				} else {
					alert(r.data && r.data.message ? r.data.message : L.saveFailed || "");
				}
			} catch (e) {
				alert(L.saveFailed || "");
			}
		};
		xhr.send(fd);
	}

	document.querySelectorAll(".mev-cluster-analyse-btn").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var idx = this.getAttribute("data-index");
			var panel = document.getElementById("mev-analysis-" + idx);
			if (panel) {
				panel.style.display = panel.style.display === "none" ? "block" : "none";
			}
		});
	});

	document.querySelectorAll(".mev-cluster-remove-btn").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var idx = parseInt(this.getAttribute("data-index"), 10);
			if (isNaN(idx) || !confirm(L.confirmRemove || "")) {
				return;
			}
			clusters.splice(idx, 1);
			saveClusters();
		});
	});
})();
