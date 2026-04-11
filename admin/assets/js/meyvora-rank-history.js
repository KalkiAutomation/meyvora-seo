/**
 * Post edit screen: interactive rank history (keyword tabs + SVG chart).
 */
(function () {
	'use strict';

	var SERP_LABELS = {
		FEATURED_SNIPPET: '★ Featured Snippet',
		RICH_SNIPPET: '◆ Rich result',
		AMP_BLUE_LINK: '⚡ AMP',
		VIDEO: '▶ Video',
		RECIPE_FEATURE: '🍴 Recipe'
	};

	function cssVar(name, fallback) {
		var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
		return v || fallback;
	}

	function serpBadgesHtml(serp) {
		if (!serp || !String(serp).trim()) {
			return '';
		}
		var parts = String(serp).split(',').map(function (s) {
			return s.trim();
		}).filter(Boolean);
		var primary = cssVar('--mev-primary', '#7c3aed');
		var html = '';
		parts.forEach(function (code) {
			var label = SERP_LABELS[code] || code;
			html +=
				'<span class="mev-rh-serp-badge" style="background:' +
				primary +
				';color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;margin-right:4px;display:inline-block;margin-top:6px;">' +
				escapeHtml(label) +
				'</span>';
		});
		return html;
	}

	function escapeHtml(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function labelIndices(dates) {
		var n = dates.length;
		if (n <= 1) {
			return n === 1 ? [0] : [];
		}
		var out = {}; out[0] = true; out[n - 1] = true;
		var anchor = new Date(dates[0] + 'T12:00:00').getTime();
		var i;
		for (i = 1; i < n - 1; i++) {
			var t = new Date(dates[i] + 'T12:00:00').getTime();
			if ((t - anchor) / 86400000 >= 30) {
				out[i] = true;
				anchor = t;
			}
		}
		return Object.keys(out)
			.map(Number)
			.sort(function (a, b) {
				return a - b;
			});
	}

	function positionColor(pos) {
		if (pos == null || isNaN(pos)) {
			return cssVar('--mev-gray-500', '#6b7280');
		}
		if (pos <= 10) {
			return cssVar('--mev-success', '#059669');
		}
		if (pos <= 20) {
			return cssVar('--mev-warning', '#d97706');
		}
		return cssVar('--mev-danger', '#dc2626');
	}

	function buildChartSvg(history, tooltipHost) {
		var svgW = 280;
		var svgH = 120;
		var padL = 28;
		var padR = 8;
		var padT = 8;
		var padB = 20;
		var plotW = svgW - padL - padR;
		var plotH = svgH - padT - padB;
		var stroke = cssVar('--mev-primary', '#7c3aed');
		var grid = cssVar('--mev-gray-200', '#e5e7eb');
		var muted = cssVar('--mev-gray-500', '#6b7280');
		var n = history.length;
		if (n === 0) {
			return '<p style="color:' + muted + ';font-size:12px;margin:0;">No data</p>';
		}
		var positions = history.map(function (h) {
			return h.position;
		});
		var minP = Math.min.apply(null, positions);
		var maxP = Math.max.apply(null, positions);
		var range = maxP - minP;
		if (range < 0.01) {
			minP = Math.max(1, minP - 2);
			maxP = maxP + 2;
			range = maxP - minP || 1;
		}
		function yForPos(p) {
			return padT + ((p - minP) / range) * plotH;
		}
		function xForIndex(i) {
			return n <= 1 ? padL + plotW / 2 : padL + (i / (n - 1)) * plotW;
		}
		var pts = [];
		var circles = [];
		var hoverRects = [];
		var i;
		for (i = 0; i < n; i++) {
			var x = xForIndex(i);
			var y = yForPos(positions[i]);
			pts.push(x.toFixed(1) + ',' + y.toFixed(1));
			circles.push(
				'<circle class="mev-rh-dot" cx="' +
					x.toFixed(1) +
					'" cy="' +
					y.toFixed(1) +
					'" r="3.5" fill="' +
					stroke +
					'" stroke="#fff" stroke-width="1" data-i="' +
					i +
					'"/>'
			);
			var segW = n <= 1 ? plotW : plotW / (n - 1);
			var half = Math.max(segW / 2, 14);
			var rx = Math.max(padL, x - half);
			var rw = Math.min(svgW - padR - rx, half * 2);
			hoverRects.push(
				'<rect class="mev-rh-hover-rect" x="' +
					rx.toFixed(1) +
					'" y="' +
					padT +
					'" width="' +
					rw.toFixed(1) +
					'" height="' +
					plotH +
					'" fill="transparent" data-date="' +
					String(history[i].date).replace(/"/g, '&quot;') +
					'" data-pos="' +
					positions[i] +
					'"/>'
			);
		}
		var dates = history.map(function (h) {
			return h.date;
		});
		var ticks = labelIndices(dates);
		var xLabels = '';
		ticks.forEach(function (idx) {
			var lx = xForIndex(idx);
			var shortD = dates[idx].slice(5);
			xLabels +=
				'<text x="' +
				lx.toFixed(1) +
				'" y="' +
				(svgH - 4) +
				'" text-anchor="middle" font-size="9" fill="' +
				muted +
				'">' +
				escapeHtml(shortD) +
				'</text>';
		});
		var yTop = padT.toFixed(1);
		var yBot = (padT + plotH).toFixed(1);
		var svg =
			'<svg class="mev-rh-svg" width="' +
			svgW +
			'" height="' +
			svgH +
			'" viewBox="0 0 ' +
			svgW +
			' ' +
			svgH +
			'" style="max-width:100%;display:block;">' +
			'<line x1="' +
			padL +
			'" y1="' +
			yTop +
			'" x2="' +
			padL +
			'" y2="' +
			yBot +
			'" stroke="' +
			grid +
			'"/>' +
			'<line x1="' +
			padL +
			'" y1="' +
			yBot +
			'" x2="' +
			(svgW - padR) +
			'" y2="' +
			yBot +
			'" stroke="' +
			grid +
			'"/>' +
			'<text x="' +
			(padL - 4) +
			'" y="' +
			(padT + 4) +
			'" text-anchor="end" font-size="9" fill="' +
			muted +
			'">' +
			Math.round(minP) +
			'</text>' +
			'<text x="' +
			(padL - 4) +
			'" y="' +
			(padT + plotH) +
			'" text-anchor="end" font-size="9" fill="' +
			muted +
			'">' +
			Math.round(maxP) +
			'</text>' +
			'<polyline fill="none" stroke="' +
			stroke +
			'" stroke-width="2" stroke-linejoin="round" points="' +
			pts.join(' ') +
			'"/>' +
			circles.join('') +
			hoverRects.join('') +
			xLabels +
			'</svg>';

		setTimeout(function () {
			if (!tooltipHost || !tooltipHost.querySelector) {
				return;
			}
			var tip = document.getElementById('mev-rh-tooltip');
			if (!tip) {
				tip = document.createElement('div');
				tip.id = 'mev-rh-tooltip';
				tip.style.cssText =
					'position:fixed;z-index:100000;padding:6px 10px;background:#111827;color:#fff;font-size:12px;border-radius:6px;pointer-events:none;opacity:0;transition:opacity .12s;box-shadow:0 4px 12px rgba(0,0,0,.15);';
				document.body.appendChild(tip);
			}
			var svgEl = tooltipHost.querySelector('.mev-rh-svg');
			if (!svgEl) {
				return;
			}
			svgEl.querySelectorAll('.mev-rh-hover-rect').forEach(function (rect) {
				rect.addEventListener('mouseenter', function (e) {
					tip.textContent =
						rect.getAttribute('data-date') + ' · #' + rect.getAttribute('data-pos');
					tip.style.opacity = '1';
					var r = e.target.getBoundingClientRect();
					tip.style.left = Math.min(window.innerWidth - 160, r.left + r.width / 2 - 50) + 'px';
					tip.style.top = r.top - 36 + 'px';
				});
				rect.addEventListener('mouseleave', function () {
					tip.style.opacity = '0';
				});
				rect.addEventListener('mousemove', function (e) {
					tip.style.left = Math.min(window.innerWidth - 160, e.clientX - 40) + 'px';
					tip.style.top = e.clientY - 40 + 'px';
				});
			});
		}, 0);

		return svg;
	}

	function statsHtml(history) {
		if (!history.length) {
			return '';
		}
		var positions = history.map(function (h) {
			return h.position;
		});
		var best = Math.min.apply(null, positions);
		var sum = positions.reduce(function (a, b) {
			return a + b;
		}, 0);
		var avg = (sum / positions.length).toFixed(1);
		var muted = cssVar('--mev-gray-600', '#4b5563');
		var border = cssVar('--mev-border', '#e5e7eb');
		return (
			'<div class="mev-rh-stats" style="display:flex;gap:16px;margin-top:10px;padding-top:10px;border-top:1px solid ' +
			border +
			';font-size:12px;color:' +
			muted +
			';">' +
			'<div><strong style="color:' +
			cssVar('--mev-gray-800', '#1f2937') +
			';">Best</strong> #' +
			best +
			'</div>' +
			'<div><strong style="color:' +
			cssVar('--mev-gray-800', '#1f2937') +
			';">Average</strong> #' +
			avg +
			'</div>' +
			'</div>'
		);
	}

	function render(panel, keywords, primaryKw) {
		panel.innerHTML = '';
		if (!keywords.length) {
			panel.innerHTML =
				'<p class="mev-rh-empty" style="margin:0;color:' +
				cssVar('--mev-gray-600', '#4b5563') +
				';font-size:13px;">No rank data yet. Set focus keywords and run Track Now on the Rank Tracker page.</p>';
			return;
		}
		var wrap = document.createElement('div');
		wrap.className = 'mev-rh-wrap';
		var tabs = document.createElement('div');
		tabs.className = 'mev-rh-tabs';
		tabs.style.cssText =
			'display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;';
		var detail = document.createElement('div');
		detail.className = 'mev-rh-detail';

		var selected = 0;

		function renderDetail(idx) {
			var kw = keywords[idx];
			if (!kw) {
				return;
			}
			var hist = kw.history || [];
			var cur = kw.current;
			var label =
				kw.keyword === primaryKw
					? ' <span style="font-size:11px;font-weight:600;color:' +
						cssVar('--mev-primary', '#7c3aed') +
						';">(primary)</span>'
					: ' <span style="font-size:11px;color:' +
						cssVar('--mev-gray-500', '#6b7280') +
						';">(secondary)</span>';
			var numStyle =
				'font-size:36px;font-weight:700;line-height:1;margin:4px 0 0;letter-spacing:-0.02em;color:' +
				positionColor(cur) +
				';';
			detail.innerHTML =
				'<div class="mev-rh-kw-title" style="font-size:13px;margin-bottom:4px;word-break:break-word;">' +
				escapeHtml(kw.keyword) +
				label +
				'</div>' +
				'<div class="mev-rh-current-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:' +
				cssVar('--mev-gray-500', '#6b7280') +
				';">Current position</div>' +
				'<div class="mev-rh-current-num" style="' +
				numStyle +
				'">#' +
				(cur != null ? cur : '—') +
				'</div>' +
				'<div class="mev-rh-serp-wrap">' +
				serpBadgesHtml(kw.serp_feature) +
				'</div>' +
				'<div class="mev-rh-chart-wrap" style="margin-top:10px;overflow-x:auto;">' +
				buildChartSvg(hist, detail) +
				'</div>' +
				statsHtml(hist);
		}

		keywords.forEach(function (kw, idx) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'mev-rh-tab';
			var isPri = kw.keyword === primaryKw;
			btn.textContent =
				(kw.keyword.length > 28 ? kw.keyword.slice(0, 26) + '…' : kw.keyword) +
				(isPri ? ' ★' : '');
			btn.style.cssText =
				'cursor:pointer;border:1px solid ' +
				cssVar('--mev-border', '#e5e7eb') +
				';background:' +
				cssVar('--mev-surface-2', '#f9fafb') +
				';border-radius:999px;padding:5px 10px;font-size:12px;color:' +
				cssVar('--mev-gray-700', '#374151') +
				';max-width:100%;text-align:left;';
			if (idx === 0) {
				btn.style.borderColor = cssVar('--mev-primary', '#7c3aed');
				btn.style.background = cssVar('--mev-primary-light', '#ede9fe');
				btn.style.color = cssVar('--mev-primary-hover', '#6d28d9');
			}
			btn.addEventListener('click', function () {
				selected = idx;
				tabs.querySelectorAll('.mev-rh-tab').forEach(function (b, j) {
					if (j === idx) {
						b.style.borderColor = cssVar('--mev-primary', '#7c3aed');
						b.style.background = cssVar('--mev-primary-light', '#ede9fe');
						b.style.color = cssVar('--mev-primary-hover', '#6d28d9');
					} else {
						b.style.borderColor = cssVar('--mev-border', '#e5e7eb');
						b.style.background = cssVar('--mev-surface-2', '#f9fafb');
						b.style.color = cssVar('--mev-gray-700', '#374151');
					}
				});
				renderDetail(idx);
			});
			tabs.appendChild(btn);
		});

		wrap.appendChild(tabs);
		wrap.appendChild(detail);
		panel.appendChild(wrap);
		renderDetail(0);
	}

	function init() {
		var panel = document.getElementById('mev-rank-history-panel');
		if (!panel || panel.getAttribute('data-mev-rh-loaded') === '1') {
			return;
		}
		panel.setAttribute('data-mev-rh-loaded', '1');
		var postId = panel.getAttribute('data-post-id');
		var nonce = panel.getAttribute('data-nonce');
		var ajax = panel.getAttribute('data-ajax');
		if (!postId || !nonce || !ajax) {
			panel.innerHTML =
				'<p style="color:#b32d2e;">Missing configuration.</p>';
			return;
		}
		var fd = new FormData();
		fd.append('action', 'meyvora_seo_rank_history_post');
		fd.append('nonce', nonce);
		fd.append('post_id', postId);
		fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				if (!res || !res.success || !res.data) {
					panel.innerHTML =
						'<p style="color:#b32d2e;margin:0;">Could not load rank history.</p>';
					return;
				}
				render(panel, res.data.keywords || [], res.data.primary_keyword || '');
			})
			.catch(function () {
				panel.innerHTML =
					'<p style="color:#b32d2e;margin:0;">Could not load rank history.</p>';
			});
	}

	function boot() {
		init();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	window.addEventListener('load', boot);
	if (typeof MutationObserver !== 'undefined') {
		var mo = new MutationObserver(function () {
			var el = document.getElementById('mev-rank-history-panel');
			if (el && el.getAttribute('data-mev-rh-loaded') !== '1') {
				init();
			}
		});
		mo.observe(document.documentElement, { childList: true, subtree: true });
		setTimeout(function () {
			mo.disconnect();
		}, 12000);
	}
})();
