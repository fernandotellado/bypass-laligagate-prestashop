/**
 * Bypass LaLigaGate - JavaScript de back office (PrestaShop)
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */
(function () {
	'use strict';

	var btnTest      = document.getElementById('blg-btn-test');
	var btnCheck     = document.getElementById('blg-btn-check');
	var btnOff       = document.getElementById('blg-btn-proxy-off');
	var btnOn        = document.getElementById('blg-btn-proxy-on');
	var authSelect   = document.getElementById('blg-field-auth-type');
	var testStatus   = document.getElementById('blg-test-status');
	var actionStatus = document.getElementById('blg-action-status');
	var allButtons   = [btnTest, btnCheck, btnOff, btnOn];

	function setButtonsDisabled(d) {
		allButtons.forEach(function (b) { if (b) { b.disabled = d; } });
	}

	function showMsg(el, msg, type) {
		if (!el) { return; }
		el.textContent = msg;
		el.className = 'ayudawp-blg-action-msg ayudawp-blg-action-msg--visible';
		if (type === 'success') { el.classList.add('blg-msg-success'); }
		else if (type === 'warning') { el.classList.add('blg-msg-warning'); }
		else if (type === 'error' || type === 'danger') { el.classList.add('blg-msg-error'); }
	}

	function val(id) {
		var el = document.getElementById(id);
		return el ? el.value : '';
	}

	function buildUrl(action) {
		var sep = window.ayudawpBlg.ajaxUrl.indexOf('?') === -1 ? '?' : '&';
		return window.ayudawpBlg.ajaxUrl + sep + 'ajax=1&action=' + encodeURIComponent(action) + '&token=' + encodeURIComponent(window.ayudawpBlg.token);
	}

	function ajaxPost(action, sendCreds, callback) {
		setButtonsDisabled(true);
		var data = new FormData();
		if (sendCreds) {
			data.append('auth_type', val('blg-field-auth-type'));
			data.append('cf_email', val('blg-field-email'));
			data.append('cf_api_key', val('blg-field-apikey'));
			data.append('cf_zone_id', val('blg-field-zoneid'));
		}
		fetch(buildUrl(action), {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		})
			.then(function (r) {
				return r.text().then(function (text) {
					try {
						return JSON.parse(text);
					} catch (e) {
						var snippet = (text || '').replace(/\s+/g, ' ').slice(0, 220);
						return {
							success: false,
							data: { message: 'Respuesta no JSON (HTTP ' + r.status + '): ' + snippet }
						};
					}
				});
			})
			.then(function (res) { callback(res); })
			.catch(function (e) { callback({ success: false, data: { message: 'Error de red: ' + e.message } }); })
			.finally(function () { setButtonsDisabled(false); });
	}

	function updateStatus(d) {
		var b = document.getElementById('blg-status-blocked');
		var p = document.getElementById('blg-status-bypass');
		var l = document.getElementById('blg-status-lastcheck');
		var n = document.getElementById('blg-status-nextcheck');
		if (b && d.blocked !== undefined) {
			var isB = d.blocked === 'SI';
			b.innerHTML = '<span class="blg-badge ' + (isB ? 'blg-badge-danger' : 'blg-badge-ok') + '">' + (isB ? 'SI' : 'NO') + '</span>';
		}
		if (p && d.bypass !== undefined) {
			var isP = d.bypass === 'SI';
			p.innerHTML = '<span class="blg-badge ' + (isP ? 'blg-badge-warning' : 'blg-badge-ok') + '">' + (isP ? 'SI' : 'NO') + '</span>';
		}
		if (l && d.lastCheck) { l.textContent = d.lastCheck; }
		if (n && d.nextCheck) { n.textContent = d.nextCheck; }
	}

	function refreshDns(html) {
		var w = document.getElementById('blg-dns-records');
		if (w && html) { w.innerHTML = html; bindSelectAll(); }
	}

	if (btnTest) {
		btnTest.addEventListener('click', function (e) {
			e.preventDefault();
			showMsg(testStatus, 'Conectando y cargando DNS...', '');
			ajaxPost('TestAndLoad', true, function (r) {
				showMsg(testStatus, (r.data && r.data.message) || 'Sin respuesta', r.success ? 'success' : 'error');
				if (r.success && r.data && r.data.html) { refreshDns(r.data.html); }
			});
		});
	}

	if (btnCheck) {
		btnCheck.addEventListener('click', function (e) {
			e.preventDefault();
			showMsg(actionStatus, 'Comprobando...', '');
			ajaxPost('ManualCheck', false, function (r) {
				if (r.success) {
					var type = 'success';
					if (r.data.blocked === 'SI') { type = 'danger'; }
					else if (r.data.bypass === 'SI') { type = 'warning'; }
					showMsg(actionStatus, r.data.message, type);
					updateStatus(r.data);
					if (r.data.html) { refreshDns(r.data.html); }
				} else {
					showMsg(actionStatus, (r.data && r.data.message) || 'Error', 'error');
				}
			});
		});
	}

	if (btnOff) {
		btnOff.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm('Esto desactivará el proxy (DNS Only) en los registros seleccionados.\nEl cron automático NO lo cambiará hasta que pulses "Restaurar proxy ON".\n\n¿Continuar?')) { return; }
			showMsg(actionStatus, 'Desactivando proxy...', '');
			ajaxPost('ForceProxyOff', false, function (r) {
				if (r.success) {
					var hasErr = r.data.message && r.data.message.indexOf('Error') !== -1;
					showMsg(actionStatus, r.data.message, hasErr ? 'error' : 'warning');
					updateStatus({ bypass: r.data.bypass || 'SI' });
					if (r.data.html) { refreshDns(r.data.html); }
				} else {
					showMsg(actionStatus, (r.data && r.data.message) || 'Error', 'error');
				}
			});
		});
	}

	if (btnOn) {
		btnOn.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm('Esto reactivará el proxy (CDN) y el control automático del cron.\n\n¿Continuar?')) { return; }
			showMsg(actionStatus, 'Restaurando proxy...', '');
			ajaxPost('ForceProxyOn', false, function (r) {
				if (r.success) {
					var hasErr = r.data.message && r.data.message.indexOf('Error') !== -1;
					showMsg(actionStatus, r.data.message, hasErr ? 'error' : 'success');
					updateStatus({ bypass: r.data.bypass || 'NO' });
					if (r.data.html) { refreshDns(r.data.html); }
				} else {
					showMsg(actionStatus, (r.data && r.data.message) || 'Error', 'error');
				}
			});
		});
	}

	function toggleAuth() {
		if (!authSelect) { return; }
		var t = authSelect.value === 'token';
		[['blg-row-email', !t], ['blg-help-apikey-global', !t], ['blg-help-apikey-token', t],
		['blg-help-auth-global', !t], ['blg-help-auth-token', t]].forEach(function (p) {
			var el = document.getElementById(p[0]);
			if (el) { el.style.display = p[1] ? '' : 'none'; }
		});
		var lbl = document.getElementById('blg-label-apikey');
		if (lbl) { lbl.textContent = t ? 'API Token' : 'Global API Key'; }
	}
	if (authSelect) { authSelect.addEventListener('change', toggleAuth); toggleAuth(); }

	function bindSelectAll() {
		var sa = document.getElementById('blg-select-all');
		if (sa) {
			sa.addEventListener('change', function () {
				document.querySelectorAll('.ayudawp-blg-dns-table tbody input[type="checkbox"]')
					.forEach(function (cb) { cb.checked = sa.checked; });
			});
		}
	}
	bindSelectAll();
})();
