/**
 * Redirect chain scan and flatten UI (Meyvora SEO).
 */
(function() {
	'use strict';

	var cfg = typeof meyvoraRedirectsChain !== 'undefined' ? meyvoraRedirectsChain : {};
	var ajaxUrl = cfg.ajax_url || '';
	var nonce = cfg.nonce || '';
	var actionScan = cfg.action_scan || 'meyvora_seo_chain_scan';
	var actionFlattenAll = cfg.action_flatten_all || 'meyvora_seo_chain_flatten_all';
	var actionFlattenOne = cfg.action_flatten_one || 'meyvora_seo_chain_flatten_one';

	function request(action, data, done) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		if (data) {
			Object.keys(data).forEach(function(k) {
				body.append(k, data[k]);
			});
		}
		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function(r) { return r.json(); })
			.then(done)
			.catch(function(err) {
				done({ success: false, data: { message: err && err.message ? err.message : 'Request failed' } });
			});
	}

	function escapeHtml(s) {
		var div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML;
	}

	function showModal(open) {
		var el = document.getElementById('mev-chain-modal');
		if (!el) return;
		el.style.display = open ? 'flex' : 'none';
	}

	function setListContent(html) {
		var list = document.getElementById('mev-chain-list');
		if (list) list.innerHTML = html;
	}

	function setListLoading(loading) {
		var wrap = document.getElementById('mev-chain-list-wrap');
		if (!wrap) return;
		var loader = wrap.querySelector('.mev-chain-loading');
		var list = document.getElementById('mev-chain-list');
		if (loader) loader.style.display = loading ? 'block' : 'none';
		if (list) list.style.display = loading ? 'none' : 'block';
		if (list && loading) list.innerHTML = '';
	}

	function renderChains(chains) {
		if (!chains || chains.length === 0) {
			return '<p class="mev-chain-empty">' + escapeHtml((cfg.i18n && cfg.i18n.no_chains) || 'No redirect chains found.') + '</p>';
		}
		var html = '';
		chains.forEach(function(c) {
			var chain = c.chain || [];
			var chainLabel = chain.map(function(u) { return escapeHtml(u); }).join(' → ');
			var sourceId = c.source_id;
			var finalTarget = c.final_target || '';
			var hops = typeof c.hops === 'number' ? c.hops : (chain.length - 1);
			html += '<div class="mev-chain-row" data-source-id="' + escapeHtml(String(sourceId)) + '" data-final-target="' + escapeHtml(finalTarget) + '">';
			html += '<div class="mev-chain-path">' + chainLabel + '</div>';
			html += '<div class="mev-chain-meta">' + escapeHtml((cfg.i18n && cfg.i18n.hops) ? cfg.i18n.hops.replace('%d', hops) : hops + ' hop(s)') + '</div>';
			html += '<button type="button" class="mev-btn mev-btn--secondary mev-btn--sm mev-chain-flatten-one">' + (cfg.i18n && cfg.i18n.flatten ? cfg.i18n.flatten : 'Flatten') + '</button>';
			html += '</div>';
		});
		return html;
	}

	function onFlattenOneClick(e) {
		var btn = e.target && e.target.closest && e.target.closest('.mev-chain-flatten-one');
		if (!btn) return;
		var row = btn.closest('.mev-chain-row');
		if (!row) return;
		var sourceId = row.getAttribute('data-source-id');
		var finalTarget = row.getAttribute('data-final-target');
		if (!sourceId || !finalTarget) return;
		btn.disabled = true;
		request(actionFlattenOne, { source_id: sourceId, final_target: finalTarget }, function(res) {
			btn.disabled = false;
			if (res && res.success) {
				row.remove();
				var list = document.getElementById('mev-chain-list');
				if (list && list.querySelectorAll('.mev-chain-row').length === 0) {
					list.innerHTML = '<p class="mev-chain-empty">' + (escapeHtml((cfg.i18n && cfg.i18n.no_chains) || 'No redirect chains found.')) + '</p>';
				}
			} else {
				alert(res && res.data && res.data.message ? res.data.message : 'Failed to flatten');
			}
		});
	}

	function init() {
		var btnScan = document.getElementById('mev-chain-scan-btn');
		var btnFlattenAll = document.getElementById('mev-chain-flatten-all-btn');
		var btnClose = document.getElementById('mev-chain-modal-close');
		var listEl = document.getElementById('mev-chain-list');

		if (listEl) {
			listEl.addEventListener('click', onFlattenOneClick);
		}

		if (btnScan) {
			btnScan.addEventListener('click', function() {
				showModal(true);
				setListLoading(true);
				setListContent('');
				request(actionScan, {}, function(res) {
					setListLoading(false);
					if (res && res.success && res.data && res.data.chains) {
						setListContent(renderChains(res.data.chains));
					} else {
						setListContent('<p class="mev-chain-empty">' + escapeHtml(res && res.data && res.data.message ? res.data.message : 'Scan failed') + '</p>');
					}
				});
			});
		}

		if (btnFlattenAll) {
			btnFlattenAll.addEventListener('click', function() {
				btnFlattenAll.disabled = true;
				request(actionFlattenAll, {}, function(res) {
					btnFlattenAll.disabled = false;
					if (res && res.success && res.data) {
						var flattened = res.data.flattened != null ? res.data.flattened : 0;
						var errors = res.data.errors != null ? res.data.errors : 0;
						var msg = (cfg.i18n && cfg.i18n.flattened_all) ? cfg.i18n.flattened_all.replace('%1$d', flattened).replace('%2$d', errors) : 'Flattened: ' + flattened + ', Errors: ' + errors;
						alert(msg);
						// Re-scan to refresh list
						setListLoading(true);
						request(actionScan, {}, function(r) {
							setListLoading(false);
							if (r && r.success && r.data && r.data.chains) {
								setListContent(renderChains(r.data.chains));
							} else {
								setListContent(renderChains([]));
							}
						});
					} else {
						alert(res && res.data && res.data.message ? res.data.message : 'Flatten all failed');
					}
				});
			});
		}

		if (btnClose) {
			btnClose.addEventListener('click', function() {
				showModal(false);
			});
		}

		// Close modal on overlay click
		var modal = document.getElementById('mev-chain-modal');
		if (modal) {
			modal.addEventListener('click', function(e) {
				if (e.target === modal) showModal(false);
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
