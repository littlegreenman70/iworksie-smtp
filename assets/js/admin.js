/* iWorks.ie SMTP admin. Vanilla JS, no build step. */
(function () {
	'use strict';

	function showPanel(mailer) {
		document.querySelectorAll('[data-iwiesm-panel]').forEach(function (panel) {
			panel.hidden = panel.getAttribute('data-iwiesm-panel') !== mailer;
		});
		document.querySelectorAll('.iwiesm-mailer').forEach(function (label) {
			var input = label.querySelector('input');
			label.classList.toggle('is-selected', !!(input && input.checked));
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var radios = document.querySelectorAll('[data-iwiesm-mailer]');
		radios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				if (radio.checked) {
					showPanel(radio.value);
				}
			});
		});

		document.querySelectorAll('[data-iwiesm-preset]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var host = document.getElementById('iwiesm-smtp_host');
				var port = document.getElementById('iwiesm-smtp_port');
				var enc = document.getElementById('iwiesm-smtp_encryption');
				var auth = document.getElementById('iwiesm-smtp_auth');
				if (host) {
					host.value = btn.getAttribute('data-host') || '';
				}
				if (port) {
					port.value = btn.getAttribute('data-port') || '587';
				}
				if (enc) {
					enc.value = btn.getAttribute('data-enc') || 'tls';
				}
				if (auth) {
					auth.checked = true;
				}
				var user = document.getElementById('iwiesm-smtp_username');
				if (user) {
					user.focus();
				}
			});
		});

		// Keep the port in step with the encryption choice when it is a default.
		var encSelect = document.getElementById('iwiesm-smtp_encryption');
		var portInput = document.getElementById('iwiesm-smtp_port');
		if (encSelect && portInput) {
			encSelect.addEventListener('change', function () {
				var defaults = { tls: '587', ssl: '465', none: '25' };
				if (['25', '465', '587', '2525', ''].indexOf(portInput.value) !== -1) {
					portInput.value = defaults[encSelect.value] || portInput.value;
				}
			});
		}
	});
})();
