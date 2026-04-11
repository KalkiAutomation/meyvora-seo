(function () {
	'use strict';
	var TOAST_ID = 'mev-toast-container';
	function getContainer() {
		var el = document.getElementById(TOAST_ID);
		if (el) {
			return el;
		}
		el = document.createElement('div');
		el.id = TOAST_ID;
		el.setAttribute('aria-live', 'polite');
		el.setAttribute('aria-atomic', 'true');
		el.style.cssText = [
			'position:fixed;bottom:24px;right:24px;z-index:99999;',
			'display:flex;flex-direction:column;gap:8px;',
			'pointer-events:none;',
		].join('');
		document.body.appendChild(el);
		return el;
	}
	window.mevToast = function (message, type, durationMs) {
		var container = getContainer();
		var toast = document.createElement('div');
		type = type || 'success';
		durationMs = durationMs || 3000;
		var bg =
			type === 'success' ? '#059669' : type === 'error' ? '#dc2626' : '#4338ca';
		toast.style.cssText = [
			'background:' + bg + ';color:#fff;',
			'padding:10px 18px;border-radius:6px;font-size:13px;',
			'box-shadow:0 4px 12px rgba(0,0,0,0.15);',
			'pointer-events:auto;max-width:320px;',
			'opacity:0;transform:translateY(8px);',
			'transition:opacity 0.2s ease,transform 0.2s ease;',
		].join('');
		toast.textContent = message;
		container.appendChild(toast);
		requestAnimationFrame(function () {
			requestAnimationFrame(function () {
				toast.style.opacity = '1';
				toast.style.transform = 'translateY(0)';
			});
		});
		setTimeout(function () {
			toast.style.opacity = '0';
			toast.style.transform = 'translateY(8px)';
			setTimeout(function () {
				if (toast.parentNode) {
					toast.parentNode.removeChild(toast);
				}
			}, 220);
		}, durationMs);
	};
})();
