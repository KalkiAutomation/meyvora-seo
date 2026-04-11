/**
 * Meyvora SEO Setup Wizard — step navigation, save on step change, skip/complete.
 */
(function ($) {
	'use strict';

	var $wrap = $('#mev-wizard');
	if (!$wrap.length) return;

	var ajaxUrl = typeof meyvoraWizard !== 'undefined' ? meyvoraWizard.ajaxUrl : '';
	var nonce = typeof meyvoraWizard !== 'undefined' ? meyvoraWizard.nonce : '';
	var i18n = (typeof meyvoraWizard !== 'undefined' && meyvoraWizard.i18n) ? meyvoraWizard.i18n : {};

	function getStepData(step) {
		var data = {};
		var $step = $wrap.find('.mev-wizard-step[data-step="' + step + '"]');
		if (!$step.length) return data;

		$step.find('input[type="radio"]:checked').each(function () {
			data[$(this).attr('name')] = $(this).val();
		});
		$step.find('input[type="text"], input[type="url"], input[type="hidden"]').each(function () {
			var name = $(this).attr('name');
			if (name) data[name] = $(this).val();
		});
		$step.find('input[type="checkbox"].mev-wizard-switch').each(function () {
			var name = $(this).attr('name');
			if (name) data[name] = $(this).is(':checked');
		});

		return data;
	}

	function saveStep(step, data, callback) {
		if (Object.keys(data).length === 0 && step !== 4) {
			if (callback) callback(true);
			return;
		}
		$.post(ajaxUrl, {
			action: 'meyvora_seo_wizard_save',
			nonce: nonce,
			data: data
		})
			.done(function (r) {
				if (r.success && callback) callback(true);
				else if (callback) callback(false);
			})
			.fail(function () {
				if (callback) callback(false);
			});
	}

	function goToStep(step) {
		var s = parseInt(step, 10);
		$wrap.find('.mev-wizard-step').removeClass('is-active').attr('hidden', true);
		$wrap.find('.mev-wizard-step[data-step="' + s + '"]').addClass('is-active').attr('hidden', false);
		$wrap.find('.mev-wizard-dot').removeClass('is-active').filter('[data-step="' + s + '"]').addClass('is-active');
	}

	// Next
	$wrap.on('click', '.mev-wizard-next', function () {
		var next = $(this).data('next');
		var current = $wrap.find('.mev-wizard-step:not([hidden])').data('step');
		var data = getStepData(current);
		saveStep(current, data, function () {
			goToStep(next);
		});
	});

	// Back
	$wrap.on('click', '.mev-wizard-prev', function () {
		var prev = $(this).data('prev');
		var current = $wrap.find('.mev-wizard-step:not([hidden])').data('step');
		var data = getStepData(current);
		saveStep(current, data, function () {
			goToStep(prev);
		});
	});

	// Step 1: save on radio change
	$wrap.on('change', 'input[name="site_type"]', function () {
		var data = { site_type: $(this).val() };
		saveStep(1, data);
	});

	// Step 2: save on input change / logo change
	$wrap.on('change input', '#mev-wizard-org-name, #mev-wizard-org-logo', function () {
		var data = {
			schema_organization_name: $('#mev-wizard-org-name').val(),
			schema_organization_logo: $('#mev-wizard-org-logo').val() || 0
		};
		saveStep(2, data);
	});

	// Step 3: save on URL blur
	$wrap.on('blur', '.mev-wizard-step[data-step="3"] input[type="url"]', function () {
		var data = getStepData(3);
		saveStep(3, data);
	});

	// Step 4: save on toggle change
	$wrap.on('change', '.mev-wizard-switch', function () {
		var data = getStepData(4);
		saveStep(4, data);
	});

	// Logo picker
	$wrap.on('click', '#mev-wizard-logo-picker', function () {
		var frame = wp.media({
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			if (att && att.id) {
				$('#mev-wizard-org-logo').val(att.id);
				var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : (att.url || '');
				if (!url) url = att.url || '';
				$('#mev-wizard-logo-preview').html('<img src="' + url + '" alt="" />');
				var data = {
					schema_organization_name: $('#mev-wizard-org-name').val(),
					schema_organization_logo: att.id
				};
				saveStep(2, data);
			}
		});
		frame.open();
	});

	// Ping Google
	$wrap.on('click', '#mev-wizard-ping-btn', function () {
		var $btn = $(this);
		var $status = $('#mev-wizard-ping-status');
		$btn.prop('disabled', true);
		$status.text(i18n.pinging || 'Pinging…');
		$.post(ajaxUrl, { action: 'meyvora_seo_wizard_ping', nonce: nonce })
			.done(function (r) {
				$status.text(r.success ? (i18n.pingDone || 'Ping sent.') : (i18n.error || 'Error'));
			})
			.fail(function () {
				$status.text(i18n.error || 'Something went wrong.');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	// Go to Dashboard (step 6) — complete and redirect
	$wrap.on('click', '#mev-wizard-go-dashboard', function (e) {
		e.preventDefault();
		var url = $(this).attr('href');
		$.post(ajaxUrl, { action: 'meyvora_seo_wizard_complete', nonce: nonce })
			.done(function (r) {
				if (r.success && r.data && r.data.redirect) {
					window.location.href = r.data.redirect;
				} else {
					window.location.href = url;
				}
			})
			.fail(function () {
				window.location.href = url;
			});
	});

	// Skip wizard
	$wrap.on('click', '#mev-wizard-skip', function (e) {
		e.preventDefault();
		$.post(ajaxUrl, { action: 'meyvora_seo_wizard_skip', nonce: nonce })
			.done(function (r) {
				if (r.success && r.data && r.data.redirect) {
					window.location.href = r.data.redirect;
				}
			});
	});
})(jQuery);
