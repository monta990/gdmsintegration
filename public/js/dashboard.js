/* GDMS Integration dashboard behavior. Loaded by GLPI's nonce-aware script hook. */
(function () {
    'use strict';

    function startDashboard() {
        var dataElement = document.getElementById('gdms-dashboard-data');
        if (!dataElement) return;

        var DATA;
        try {
            DATA = JSON.parse(dataElement.value || dataElement.textContent || '{}');
        } catch (error) {
            if (window.console && console.error) console.error('GDMS dashboard data is invalid JSON', error);
            return;
        }
        window.GDMS_DASHBOARD_DATA = DATA;


(function () {
    'use strict';

    var STR = DATA.strings;

    // Escape untrusted values for both HTML text nodes and quoted attributes.
    // Keep one implementation shared by the dynamic modal/table renderers.
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function(character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[character];
        });
    }

    // Tooltips use GLPI's native initTooltips() implementation.

    // Byte formatter
    function fmtBytes(b) {
        if (b >= 1073741824) return (b/1073741824).toFixed(1)+' GB';
        if (b >= 1048576)    return (b/1048576).toFixed(1)+' MB';
        if (b >= 1024)       return Math.round(b/1024)+' KB';
        return b+' B';
    }

    function fmtTime(s) { return s >= 60 ? Math.floor(s/60)+'m '+(s%60)+'s' : s+'s'; }

    function doSync(source) {
        if (gdmsSyncing) return;
        gdmsSyncing = true;
        var icon = document.getElementById('gdms-refresh-icon');
        var status = document.getElementById('gdms-sync-status');
        var button = document.getElementById('gdms-refresh-btn');
        if (button) button.disabled = true;
        if (status) {
            status.textContent = source === 'initial-load' ? STR.syncing : '';
            status.classList.remove('text-danger');
        }
        if (icon) icon.style.cssText = 'animation:spin .8s linear infinite;display:inline-block;';
        var syncUrl = new URL(AJAX_URL, window.location.href);
        syncUrl.searchParams.set('source', source || 'button');
        fetch(syncUrl.toString(), {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': (typeof window.glpiGetNewCSRFToken === 'function'
                    ? window.glpiGetNewCSRFToken()
                    : (document.querySelector('meta[property="glpi:csrf_token"]') || document.createElement('meta')).getAttribute('content') || '')
            },
            credentials: 'same-origin'
        })
            .then(function(r) {
                return r.text().then(function(text) {
                    var data = {};
                    try { data = text ? JSON.parse(text) : {}; } catch (e) {}
                    if (!r.ok || data.success !== true) {
                        throw new Error(data.error || data.message || ('HTTP ' + r.status));
                    }
                    return data;
                });
            })
            .then(function(data) {
                if (data.completed === false) {
                    gdmsSyncing = false;
                    if (button) button.disabled = false;
                    if (icon) icon.style.animation = '';
                    if (status) {
                        status.textContent = data.busy ? STR.syncing : STR.reqFailed;
                        status.classList.remove('text-danger');
                    }
                    return;
                }
                window.location.reload();
            })
            .catch(function(error) {
                gdmsSyncing = false;
                if (button) button.disabled = false;
                if (icon) icon.style.animation = '';
                if (status) {
                    status.textContent = error && error.message
                        ? error.message
                        : 'Synchronization failed. Check the plugin log and try again.';
                    status.classList.add('text-danger');
                }
                if (window.console && console.error) console.error('GDMS sync failed', error);
            });
    }

    if (DATA.showTopology) {
    (function initTopology() {
        var container = document.getElementById('gdms-network');
        if (!container) return;

        function build() {
            if (!window.vis || typeof window.vis.DataSet !== 'function' || typeof window.vis.Network !== 'function') {
                return false;
            }
            try {
                var nodes = new window.vis.DataSet(DATA.nodes);
                var edges = new window.vis.DataSet(DATA.edges);
                var data = { nodes: nodes, edges: edges };
                var options = {
                    nodes: { shape: 'dot', size: 14, font: { color: '#fff', size: 12 } },
                    edges: { arrows: 'to', color: { color: '#aaa', highlight: '#007bff' } },
                    physics: { stabilization: { iterations: 150 } },
                    interaction: { hover: true }
                };
                new window.vis.Network(container, data, options);
                return true;
            } catch (e) {
                if (window.console && console.error) console.error('GDMS topology initialization failed', e);
                return false;
            }
        }

        if (build()) return;
        var attempts = 0;
        var timer = window.setInterval(function () {
            if (build() || ++attempts >= 20) window.clearInterval(timer);
        }, 100);
    })();
    }

    var AJAX_URL           = DATA.urls.sync;
    var REFRESH_S          = DATA.refreshInterval;
    var gdmsSyncing        = false;
    var gdmsCountdown      = REFRESH_S;
    var countEl            = document.getElementById('gdms-refresh-countdown');
    var btn                = document.getElementById('gdms-refresh-btn');

    if (btn) btn.addEventListener('click', function(){ gdmsCountdown = REFRESH_S; doSync('button'); });

    // Synchronize on dashboard entry only when this entity has never synchronized
    // or its configured refresh interval has elapsed. Fresh entities do not get
    // another full cloud sync on every visit. The server-side entity
    // lock remains the final guard against parallel tabs/users/cron workers.
    if (DATA.needsAutoSync) {
        if (btn) btn.disabled = true;
        var initialStatus = document.getElementById('gdms-sync-status');
        if (initialStatus) initialStatus.textContent = STR.syncing;
        window.setTimeout(function() {
            doSync('initial-load');
        }, 350);
    }

    setInterval(function() {
        if (gdmsSyncing) return;
        if (countEl) countEl.textContent = fmtTime(gdmsCountdown);
        if (--gdmsCountdown < 0) {
            gdmsCountdown = REFRESH_S;
            // Preserve automatic synchronization: the async request keeps the UI
            // responsive, while the entity lock prevents concurrent Cron/button/tab
            // syncs from running the same cloud workload at once. A successful sync
            // reloads the dashboard; failures are surfaced in the status text.
            if (!document.hidden) doSync('auto-refresh');
        }
    }, 1000);

    // ── Firmware update check ─────────────────────────────────────────────────
    var FW_URL             = DATA.urls.fw_check;
    var FW_UPGRADE_URL     = DATA.urls.fw_upgrade;
    var FW_CHECK_ALL_URL   = DATA.urls.fw_check_all;
    var FW_UPGRADE_GDMS_URL= DATA.urls.fw_upgrade_gdms;

    var modalHtml = `
<div class="modal fade" id="gdmsFwModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:min(540px,94vw);">
    <div class="modal-content">
      <div class="modal-header py-3">
        <h5 class="modal-title">
          <i class="ti ti-arrow-up-circle text-warning me-2"></i>
          ${STR.fwUpdateTitle}
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="gdmsFwModalBody">
        <div class="d-flex align-items-center gap-2">
          <div class="spinner-border spinner-border-sm" role="status"></div>
          <span>${STR.fwChecking}</span>
        </div>
      </div>
      <div class="modal-footer flex-column align-items-stretch gap-2 pt-2">
        <div id="gdmsFwScheduleRow" style="display:none;">
          <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-nowrap small fw-semibold" for="gdmsFwDatetime">${STR.fwScheduleFor}:</label>
            <div class="btn-group flex-grow-1 flatpickr" id="gdmsFwFlatpickrWrap">
              <input type="text" class="form-control form-control-sm rounded-start ps-2"
                     id="gdmsFwDatetime" data-input placeholder="dd/mm/yyyy hh:mm" autocomplete="off">
              <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle
                      title="${STR.fwShowPicker}" data-bs-toggle="tooltip">
                <i class="ti ti-calendar-time"></i>
              </button>
            </div>
          </div>
          <small class="text-muted d-block mt-1">${STR.fwLeaveEmpty}</small>
        </div>
        <div class="d-flex flex-column gap-2 w-100">
          <button type="button" class="btn btn-success text-white w-100" id="gdmsFwAsapBtn" style="display:none;">
            <i class="ti ti-bolt me-1"></i>${STR.applyAsap}
          </button>
          <button type="button" class="btn btn-warning text-dark w-100" id="gdmsFwScheduleBtn" style="display:none;">
            <i class="ti ti-calendar-check me-1"></i>${STR.schedUpdate}
          </button>
          <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">
            ${STR.close}
          </button>
        </div>
      </div>
    </div>
  </div>
</div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    initTooltips(document.querySelector('#gdmsFwFlatpickrWrap'));
    document.getElementById('gdmsFwModal').addEventListener('hide.bs.modal', function() { if (document.activeElement) document.activeElement.blur(); });

    var fwData = {};

    setTimeout(function() {
        fetch(FW_CHECK_ALL_URL, { credentials: 'same-origin' })
            .then(function(r) {
                    return r.text().then(function(text) {
                        var resp = null;
                        try { resp = text ? JSON.parse(text) : {}; } catch (e) {}
                        if (!r.ok) {
                            var detail = (resp && (resp.error || resp.message || resp.detail)) || text || ('HTTP ' + r.status);
                            throw new Error(String(detail));
                        }
                        return resp || {};
                    });
                })
            .then(function(list) {
                if (!Array.isArray(list)) return;
                list.forEach(function(item) {
                    fwData[item.mac] = item;
                    var status = item.firmwareStatus || (item.hasUpdate ? 'update_available' : 'unverified');
                    var statusLabel = item.firmwareStatusLabel || STR.firmwareUnverified;
                    if (item.firmwareCheckError) statusLabel += ' (' + item.firmwareCheckError + ')';
                    document.querySelectorAll('small.gdms-fw-badge[data-mac="' + item.mac + '"]').forEach(function(el) {
                        el.setAttribute('title', statusLabel);
                        el.setAttribute('data-bs-original-title', statusLabel);
                    });
                    document.querySelectorAll('span.gdms-fw-badge[data-mac="' + item.mac + '"]').forEach(function(el) {
                        var icon = el.querySelector('i');
                        if (!icon) return;
                        if (status === 'up_to_date') {
                            el.style.display = 'none';
                            return;
                        }
                        el.style.display = 'inline';
                        if (status === 'update_available') {
                            icon.className = 'ti ti-arrow-up-circle text-warning ms-1';
                        } else if (status === 'beta_installed' || status === 'ahead_of_stable') {
                            icon.className = 'ti ti-info-circle text-info ms-1';
                        } else {
                            icon.className = 'ti ti-alert-triangle text-warning ms-1';
                        }
                        el.setAttribute('title', statusLabel);
                        el.setAttribute('data-bs-original-title', statusLabel);
                    });
                });
            })
            .catch(function() {});
    }, 7000);

    document.addEventListener('click', function(e) {
        var badge = e.target.closest('.gdms-fw-badge');
        if (!badge) return;
        var mac     = badge.getAttribute('data-mac');
        var current = badge.getAttribute('data-current');
        var model   = badge.getAttribute('data-model') || '';
        var devName = badge.getAttribute('data-name') || '';
        var devIp   = badge.getAttribute('data-ip')   || '';
        var info    = fwData[mac] || {};
        var isGwn   = info.isGwn || /^GWN|^GSS/i.test(model);
        var official    = info.official    || null;
        var officialUrl = info.officialUrl || '';

        var body     = document.getElementById('gdmsFwModalBody');
        var schedBtn = document.getElementById('gdmsFwScheduleBtn');
        var asapBtn  = document.getElementById('gdmsFwAsapBtn');
        var schedRow = document.getElementById('gdmsFwScheduleRow');
        var dtInput  = document.getElementById('gdmsFwDatetime');
        var fpWrap   = document.getElementById('gdmsFwFlatpickrWrap');

        var firmwareStatus = info.firmwareStatus || (info.hasUpdate ? 'update_available' : 'unverified');
        var statusLabel = info.firmwareStatusLabel || STR.firmwareUnverified;
        var mustBlockDowngrade = firmwareStatus === 'beta_installed' || firmwareStatus === 'ahead_of_stable';
        var canSelectFirmware = Boolean(official && info.hasUpdate && info.canScheduleUpdate === true && !mustBlockDowngrade);
        var versionRows = '';
        if (canSelectFirmware) {
            versionRows += '<tr><td class="pe-2"><label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;">'
              + '<input type="radio" id="gdmsFwVersionRadio" name="gdmsFwVersion" class="form-check-input mt-0" checked>'
              + '<code class="text-warning">' + esc(official) + '</code>'
              + ' <span class="badge bg-success ms-1">' + STR.officialFw + '</span>'
              + '</label></td></tr>';
        }

        var macUpper  = mac.toUpperCase();
        var macIpRow  = devName
            ? '<tr><th class="text-muted fw-normal">' + STR.deviceName + '</th><td>' + esc(devName) + '</td></tr>'
            : '';
        if (devIp) {
            macIpRow += '<tr><th class="text-muted fw-normal">' + STR.fwPrivateIp + '</th>'
              + '<td><a href="http://' + esc(devIp) + '/" target="_blank" rel="noopener" class="font-monospace">' + esc(devIp) + '</a></td></tr>';
        }
        var macCell = '<code id="gdmsFwMacCode" style="cursor:pointer;" title="' + STR.deviceMac + '" data-bs-toggle="tooltip">' + esc(macUpper) + '</code>';
        var statusDetails = '<tr><th class="text-muted fw-normal w-50">' + esc(STR.firmwareStatus) + '</th><td>' + esc(statusLabel)
          + (info.firmwareCheckError ? '<div class="small text-warning mt-1">' + esc(info.firmwareCheckError) + '</div>' : '')
          + (info.betaVersion ? '<div class="small text-muted mt-1">' + esc(STR.betaChannelPublished) + ': <code>' + esc(info.betaVersion) + '</code></div>' : '')
          + '</td></tr>';
        var selectVersionRow = versionRows
            ? '<tr><th class="text-muted fw-normal align-top pt-2">' + STR.selectVersion + '</th><td><table class="mb-0">' + versionRows + '</table></td></tr>'
            : '';
        body.innerHTML = '<table class="table table-sm mb-0">'
          + macIpRow
          + '<tr><th class="text-muted fw-normal w-50">' + STR.curFw + '</th><td><code>' + esc(current) + '</code></td></tr>'
          + statusDetails
          + selectVersionRow
          + '<tr><th class="text-muted fw-normal">' + STR.deviceMac + '</th>'
          + '<td>' + macCell + ' <small class="text-muted ms-1" id="gdmsFwMacCopiedMsg" style="display:none;">' + STR.fwMacCopied + '</small></td></tr>'
          + '</table>'
          + '<div class="alert alert-warning mt-3 mb-0 py-2 small"><i class="ti ti-alert-triangle me-1"></i>' + STR.rebootWarning + '</div>'
          + '<div class="alert alert-info mt-2 mb-0 py-2 small"><i class="ti ti-info-circle me-1"></i>'
            + STR.gdmsVersionNote
            + ' <a href="https://www.grandstream.com/support/firmware" target="_blank" rel="noopener" class="ms-1">'
            + STR.fwPageLbl + ' <i class="ti ti-external-link" style="font-size:.8em;"></i></a></div>';

        // Assign the scraped URL through the DOM API, never through HTML string parsing.
        // This keeps quotes and markup characters in the value inert.
        var firmwareRadio = body.querySelector('#gdmsFwVersionRadio');
        if (firmwareRadio) {
            firmwareRadio.value = String(official || '');
            firmwareRadio.setAttribute('data-url', String(officialUrl || ''));
        }

        var _macEl = document.getElementById('gdmsFwMacCode');
        initTooltips(document.getElementById('gdmsFwModalBody'));
        if (_macEl) {
            _macEl.addEventListener('click', function() {
                navigator.clipboard && navigator.clipboard.writeText(macUpper).then(function() {
                    var _msg = document.getElementById('gdmsFwMacCopiedMsg');
                    if (_msg) { _msg.style.display = 'inline'; setTimeout(function(){ _msg.style.display = 'none'; }, 1500); }
                });
            });
        }

        if (!fpWrap._fp) {
            var _fpNow = new Date();
            var _fpInput = document.getElementById('gdmsFwDatetime');
            var _fpToggle = fpWrap.querySelector('[data-toggle]');
            fpWrap._fp = flatpickr(_fpInput, {
                enableTime:    true,
                dateFormat:    'd/m/Y H:i',
                time_24hr:     true,
                minDate:       new Date(Date.now() + 5 * 60 * 1000),
                defaultHour:   _fpNow.getHours(),
                defaultMinute: _fpNow.getMinutes(),
                locale: typeof getFlatPickerLocale === 'function'
                    ? getFlatPickerLocale(DATA.fpLang, DATA.fpRegion)
                    : { firstDayOfWeek: 1 },
                onReady: function(sel, str, fp) {
                    var c = fp.calendarContainer;
                    if (!c) return;
                    var ms = c.querySelector('.flatpickr-monthDropdown-months'); if (ms && !ms.name) ms.name = 'gdms_fp_month';
                    var yr = c.querySelector('.cur-year');                       if (yr && !yr.name) yr.name = 'gdms_fp_year';
                    var hr = c.querySelector('.flatpickr-hour');                 if (hr && !hr.name) hr.name = 'gdms_fp_hour';
                    var mn = c.querySelector('.flatpickr-minute');               if (mn && !mn.name) mn.name = 'gdms_fp_minute';
                }
            });
            fpWrap._fp.setDate(new Date(Date.now() + 5 * 60 * 1000), false);
            if (_fpToggle) _fpToggle.addEventListener('click', function() { fpWrap._fp.open(); });
        } else {
            var _fpNow2 = new Date();
            fpWrap._fp.set('defaultHour',   _fpNow2.getHours());
            fpWrap._fp.set('defaultMinute', _fpNow2.getMinutes());
            fpWrap._fp.set('minDate', new Date(Date.now() + 5 * 60 * 1000));
            fpWrap._fp.clear();
        }
        // Scheduling is allowed only when the backend verified a newer stable
        // version and an actionable path. Unverified sources remain review-only.
        var hasVersion = canSelectFirmware;
        schedRow.style.display = hasVersion ? '' : 'none';
        asapBtn.style.display  = hasVersion ? 'inline-block' : 'none';
        schedBtn.style.display = hasVersion ? 'inline-block' : 'none';
        asapBtn.disabled  = false;
        schedBtn.disabled = false;
        asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i>' + STR.applyAsap;
        schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i>' + STR.schedUpdate;

        function getSelectedVersion() {
            var radio = body.querySelector('input[name="gdmsFwVersion"]:checked');
            return radio ? (radio.value || '') : '';
        }
        function getSelectedUrl() {
            var radio = body.querySelector('input[name="gdmsFwVersion"]:checked');
            return radio ? (radio.getAttribute('data-url') || '') : '';
        }
        function doUpgrade(scheduleMs) {
            var version = getSelectedVersion();
            var selUrl  = getSelectedUrl();
            // A beta/ahead-of-stable installation must never be offered an automatic
            // stable downgrade. Unknown or unverified firmware metadata is review-only.
            if (mustBlockDowngrade || !hasVersion) return;
            // GWN upgrade/add selects the latest repository firmware server-side;
            // it therefore does not require a version or download URL.
            if (!isGwn && !version && !selUrl) return;
            var activeBtn = scheduleMs > 0 ? schedBtn : asapBtn;
            asapBtn.disabled = true; schedBtn.disabled = true;
            activeBtn.innerHTML = '<i class="ti ti-loader me-1" style="animation:spin .8s linear infinite;display:inline-block;"></i>' + STR.scheduling;

            var csrfValue = (typeof window.glpiGetNewCSRFToken === 'function')
                ? window.glpiGetNewCSRFToken()
                : (document.querySelector('meta[property="glpi:csrf_token"]') || document.createElement('meta')).getAttribute('content') || '';

            var fetchHeaders = { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrfValue };
            var fetchUrl, fetchBody;

            if (isGwn) {
                var formData = new FormData();
                formData.append('macs', JSON.stringify([mac.replace(/:/g, '').toUpperCase()]));
                if (scheduleMs > 0) formData.append('scheduleTimeMs', scheduleMs);
                fetchUrl = FW_UPGRADE_URL; fetchBody = formData;
            } else {
                var formData2 = new FormData();
                formData2.append('mac', mac);
                formData2.append('version', version);
                var selUrl = getSelectedUrl();
                if (selUrl) formData2.append('downloadUrl', selUrl);
                if (scheduleMs > 0) formData2.append('scheduleMs', scheduleMs);
                fetchUrl = FW_UPGRADE_GDMS_URL; fetchBody = formData2;
            }

            fetch(fetchUrl, { method: 'POST', credentials: 'same-origin', headers: fetchHeaders, body: fetchBody })
                .then(function(r) {
                    return r.text().then(function(text) {
                        var resp = null;
                        try { resp = text ? JSON.parse(text) : {}; } catch (e) {}
                        if (!r.ok) {
                            var detail = (resp && (resp.error || resp.message || resp.detail)) || text || ('HTTP ' + r.status);
                            throw new Error(String(detail));
                        }
                        return resp || {};
                    });
                })
                .then(function(resp) {
                    if (resp.error) {
                        var errDiv = document.createElement('div');
                        errDiv.className = 'alert alert-danger mt-2 mb-0 py-2 small';
                        errDiv.textContent = resp.error;
                        errDiv.insertAdjacentHTML('afterbegin', '<i class="ti ti-circle-x me-1"></i> ');
                        body.appendChild(errDiv);
                        asapBtn.disabled = false; schedBtn.disabled = false;
                        asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i>' + STR.applyAsap;
                        schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i>' + STR.schedUpdate;
                    } else {
                        var ok = document.createElement('div');
                        ok.className = 'alert alert-success mt-2 mb-0 py-2 small';
                        ok.innerHTML = '<i class="ti ti-check-circle me-1"></i>' + STR.schedOk;
                        body.appendChild(ok);
                        asapBtn.style.display = 'none'; schedBtn.style.display = 'none';
                        schedRow.style.display = 'none';
                        document.querySelectorAll('small.gdms-fw-badge[data-mac="' + mac + '"]')
                            .forEach(function(el) { el.textContent = STR.upgrading; el.style.cursor = 'default'; el.classList.remove('gdms-fw-badge'); });
                        document.querySelectorAll('span.gdms-fw-badge[data-mac="' + mac + '"]')
                            .forEach(function(el) { el.style.display = 'none'; });
                    }
                })
                .catch(function(err) {
                    var connErr = document.createElement('div');
                    connErr.className = 'alert alert-danger mt-2 mb-0 py-2 small';
                    connErr.textContent = err && err.message ? err.message : STR.reqFailed;
                    body.appendChild(connErr);
                    asapBtn.disabled = false; schedBtn.disabled = false;
                    asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i>' + STR.applyAsap;
                    schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i>' + STR.schedUpdate;
                });
        }

        asapBtn.onclick  = function() { doUpgrade(0); };
        schedBtn.onclick = function() {
            var sel = fpWrap._fp && fpWrap._fp.selectedDates[0];
            if (!sel || sel.getTime() <= Date.now()) { doUpgrade(0); return; }
            doUpgrade(sel.getTime());
        };

        var modalEl = document.getElementById('gdmsFwModal');
        if (typeof bootstrap !== 'undefined') {
            new bootstrap.Modal(modalEl).show();
        } else if (typeof $ !== 'undefined') {
            $(modalEl).modal('show');
        }
    });

    // ── WAN port status ─────────────────────────────────────────────────────
    var PORTS_URL = DATA.urls.ports;

    var portSpeeds = {0:'—', 1:'10M '+STR.hdx, 2:'10M '+STR.fdx, 3:'100M '+STR.hdx, 4:'100M '+STR.fdx, 5:'1G '+STR.fdx, 6:'1G '+STR.fdx, 7:'10G '+STR.fdx};
    var wanTypeNames = {0:STR.wanDhcp, 1:STR.wanStatic, 2:STR.wanPppoe, 3:STR.wanPptp, 4:STR.wanL2tp};
    var connectNames = {0:STR.disconnected, 1:STR.online};

    function gatewayUrl(value) {
        var gateway = String(value == null ? '' : value).trim();
        if (!gateway) return '';

        // Only create links for literal IP addresses; never trust a supplied URL or hostname.
        var ipv4Parts = gateway.split('.');
        if (ipv4Parts.length === 4 && ipv4Parts.every(function(part) {
            return /^(0|[1-9]\d{0,2})$/.test(part) && Number(part) <= 255;
        })) {
            return 'http://' + gateway + '/';
        }

        var ipv6 = gateway;
        if (ipv6.charAt(0) === '[' && ipv6.charAt(ipv6.length - 1) === ']') {
            ipv6 = ipv6.slice(1, -1);
        }
        if (ipv6.indexOf(':') === -1 || !/^[0-9a-f:.]+$/i.test(ipv6)) return '';

        try {
            return new URL('http://[' + ipv6 + ']/').href;
        } catch (e) {
            return '';
        }
    }

    function fmtDuration(secs) {
        if (!secs) return '—';
        var d = Math.floor(secs / 86400), h = Math.floor((secs % 86400) / 3600), m = Math.floor((secs % 3600) / 60);
        var r = '';
        if (d) r += d + 'd ';
        if (h) r += h + 'h ';
        r += m + 'm';
        return r.trim() || '<1m';
    }

    var portModal = document.createElement('div');
    portModal.className = 'modal fade'; portModal.id = 'gdmsPortModal';
    portModal.setAttribute('tabindex', '-1'); portModal.setAttribute('aria-hidden', 'true');
    portModal.innerHTML =
      '<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">' +
      '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-network me-2 text-primary"></i> ' +
      STR.portStatus +
      ' <small class="text-muted ms-2" id="gdmsPortModalDevice"></small></h5>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
      '<div class="modal-body" id="gdmsPortModalBody"><div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div></div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
      STR.close + '</button></div></div></div>';
    document.body.appendChild(portModal);
    portModal.addEventListener('hide.bs.modal', function() { if (document.activeElement) document.activeElement.blur(); });

    var portData = {};

    setTimeout(function() {
        fetch(PORTS_URL, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                portData = data || {};
                Object.keys(portData).forEach(function(mac) {
                    var ports = portData[mac];
                    if (!Array.isArray(ports) || !ports.length) return;
                    var container = document.querySelector('.gdms-wan-ports[data-mac="' + mac + '"]');
                    if (!container) return;

                    container.innerHTML = '';
                    ports.forEach(function(p) {
                        var isWan = p.role == 1;
                        var linkUp = p.link == 1;
                        var color;
                        if (isWan) {
                            if (!linkUp)                  color = '#6c757d';
                            else if (p.connectStatus === 1) color = '#28a745';
                            else if (p.connectStatus === 0) color = '#fd7e14';
                            else                          color = '#ffc107';
                        } else {
                            color = linkUp ? '#20c997' : '#6c757d';
                        }
                        var label = p.silk || p.name || (isWan ? 'WAN' : 'LAN');
                        var wanLabel = p.wanName ? ' — ' + p.wanName : '';
                        var portLabel = p.name && p.name !== p.silk ? ' (' + p.name + ')' : '';
                        var dot = document.createElement('span');
                        var dotStatus;
                        if (!linkUp) {
                            dotStatus = ' — ' + STR.linkDown;
                        } else if (isWan) {
                            dotStatus = p.connectStatus === 1 ? ' — ' + STR.online
                                      : p.connectStatus === 0 ? ' — ' + STR.noInternet
                                      : ' — ' + STR.linkUp;
                        } else {
                            dotStatus = ' — ' + STR.linkUp;
                        }
                        dot.setAttribute('title', label + portLabel + wanLabel + dotStatus);
                        dot.setAttribute('data-bs-toggle', 'tooltip');
                        
                        dot.style.cssText = 'display:inline-block;width:9px;height:9px;border-radius:50%;background:' + color + ';cursor:pointer;flex-shrink:0' + (isWan ? ';outline:1px solid rgba(255,255,255,.3)' : '');
                        container.appendChild(dot);
                    });
                    initTooltips(container);
                });
            })
            .catch(function() {});
    }, 3000);

    document.addEventListener('click', function(e) {
        var container = e.target.closest('.gdms-wan-ports');
        if (!container) return;
        var mac   = container.getAttribute('data-mac');
        var ports = portData[mac];
        if (!ports) return;

        var row   = container.closest('tr');
        var dName = row ? (row.querySelector('td a') || row.querySelector('td')).textContent.trim() : mac;
        document.getElementById('gdmsPortModalDevice').textContent = dName;

        var body  = document.getElementById('gdmsPortModalBody');
        if (!ports.length) { body.innerHTML = '<p class="text-muted text-center">' + STR.noPorts + '</p>'; }
        else {
            var ulBytes   = parseInt(container.getAttribute('data-upload')    || '0', 10);
            var dlBytes   = parseInt(container.getAttribute('data-download')   || '0', 10);
            var firstSeen = container.getAttribute('data-first-seen') || '';
            var lastSeen  = container.getAttribute('data-last-seen')  || '';
            var infoHtml = '';
            if (ulBytes > 0 || dlBytes > 0 || firstSeen || lastSeen) {
                infoHtml = '<div class="border-bottom pb-2 mb-3 small">';
                if (ulBytes > 0 || dlBytes > 0) {
                    infoHtml += '<div class="fw-semibold text-muted mb-1"><i class="ti ti-chart-bar me-1"></i>' + esc(STR.netUsage) + '</div>';
                    infoHtml += '<div class="d-flex gap-4 mb-2">';
                    infoHtml += '<span><span class="text-muted me-1">' + esc(STR.upload) + ':</span><span class="text-success fw-semibold"> ↑ ' + esc(fmtBytes(ulBytes)) + '</span></span>';
                    infoHtml += '<span><span class="text-muted me-1">' + esc(STR.download) + ':</span><span class="text-info fw-semibold"> ↓ ' + esc(fmtBytes(dlBytes)) + '</span></span>';
                    infoHtml += '</div>';
                }
                if (firstSeen || lastSeen) {
                    infoHtml += '<div class="d-flex flex-wrap gap-3 text-muted">';
                    if (firstSeen) infoHtml += '<span><i class="ti ti-calendar-plus me-1"></i><span class="me-1">' + esc(STR.firstSeen) + ':</span>' + esc(firstSeen.slice(0,16)) + '</span>';
                    if (lastSeen)  infoHtml += '<span><i class="ti ti-calendar-check me-1"></i><span class="me-1">' + esc(STR.lastSeen) + ':</span>' + esc(lastSeen.slice(0,16)) + '</span>';
                    infoHtml += '</div>';
                }
                infoHtml += '</div>';
            }
            var legendItems = [
                {color:'#28a745', label:STR.wanOnline},
                {color:'#fd7e14', label:STR.wanNoInet},
                {color:'#ffc107', label:STR.wanUnknown},
                {color:'#20c997', label:STR.lanUp},
                {color:'#6c757d', label:STR.linkDown},
            ];
            var legend = '<div class="d-flex flex-wrap gap-3 mb-3 small border-bottom pb-2">';
            legendItems.forEach(function(li) {
                legend += '<span style="display:inline-flex;align-items:center;gap:5px">'
                        + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + li.color + ';outline:1px solid rgba(128,128,128,.35);flex-shrink:0"></span>'
                        + '<span>' + esc(li.label) + '</span>'
                        + '</span>';
            });
            legend += '</div>';
            var html = infoHtml + legend + '<div class="row g-2">';
            ports.forEach(function(p) {
                var isWan  = p.role == 1;
                var linkUp = p.link == 1;
                var cs     = (p.connectStatus !== undefined) ? p.connectStatus : -1;
                var statusColor, statusIcon, statusText;
                if (isWan) {
                    if (!linkUp)  { statusColor='secondary'; statusIcon='circle-x';     statusText=STR.linkDown; }
                    else if(cs==1){ statusColor='success';   statusIcon='circle-check'; statusText=STR.online; }
                    else if(cs==0){ statusColor='warning';   statusIcon='alert-circle'; statusText=STR.noInternet; }
                    else          { statusColor='warning';   statusIcon='alert-circle'; statusText=STR.linkUp; }
                } else {
                    statusColor = linkUp ? 'info'      : 'secondary';
                    statusIcon  = linkUp ? 'circle-check' : 'circle-x';
                    statusText  = linkUp ? STR.linkUp  : STR.linkDown;
                }
                var label    = p.silk || (isWan ? 'WAN' : 'LAN');
                var portName = (p.name && p.name !== p.silk) ? p.name : '';
                html += '<div class="col-md-6"><div class="card border-' + statusColor + ' h-100">';
                html += '<div class="card-header bg-transparent border-' + statusColor + ' d-flex justify-content-between align-items-center py-2">';
                html += '<strong class="text-' + statusColor + '"><i class="ti ti-' + statusIcon + ' me-1"></i>' + esc(label);
                if (portName) html += ' <small class="fw-normal opacity-75">(' + esc(portName) + ')</small>';
                html += '</strong>';
                if (p.wanName) html += '<small class="text-muted ms-2">' + esc(p.wanName) + '</small>';
                html += '</div><div class="card-body py-2 small"><dl class="row mb-0">';
                if (isWan) {
                    html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.connection) + '</dt><dd class="col-7">' + esc(statusText) + '</dd>';
                    if (p.ip) html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.ipAddress) + '</dt><dd class="col-7"><code>' + esc(p.ip) + '</code></dd>';
                    if (p.wanType !== undefined && p.wanType >= 0) html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.wanTypeLbl) + '</dt><dd class="col-7">' + esc(wanTypeNames[p.wanType] || '—') + '</dd>';
                    if (p.gateway) {
                        var gatewayValue = String(p.gateway);
                        var gatewayHref = gatewayUrl(gatewayValue);
                        var gatewayHtml = '<code>' + esc(gatewayValue) + '</code>';
                        if (gatewayHref) {
                            gatewayHtml = '<a href="' + esc(gatewayHref) + '" target="_blank" rel="noopener noreferrer" class="link-primary text-decoration-none" aria-label="' + esc(STR.gateway + ' ' + gatewayValue) + '">'
                                + '<code>' + esc(gatewayValue) + '</code>'
                                + '<i class="ti ti-external-link ms-1 small" aria-hidden="true"></i></a>';
                        }
                        html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.gateway) + '</dt><dd class="col-7">' + gatewayHtml + '</dd>';
                    }
                    if (p.firstDns || p.secondDns) {
                        var dnsVal = [p.firstDns, p.secondDns].filter(Boolean).join(' / ');
                        html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.dns) + '</dt><dd class="col-7"><code>' + esc(dnsVal) + '</code></dd>';
                    }
                    if (p.wamMac) html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.wanMac) + '</dt><dd class="col-7"><code>' + esc(p.wamMac) + '</code></dd>';
                    if (p.portIpv6) html += '<dt class="col-5 text-muted fw-normal">IPv6</dt><dd class="col-7"><code style="font-size:.8em;word-break:break-all;">' + esc(p.portIpv6) + '</code></dd>';
                    if (p.connectDuration) html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.connectedFor) + '</dt><dd class="col-7">' + esc(fmtDuration(p.connectDuration)) + '</dd>';
                    if (p.txBytes || p.rxBytes) {
                        html += '<dt class="col-5 text-muted fw-normal">↑ ' + esc(STR.upload) + '</dt><dd class="col-7 text-success">' + esc(fmtBytes(p.txBytes || 0)) + '</dd>';
                        html += '<dt class="col-5 text-muted fw-normal">↓ ' + esc(STR.download) + '</dt><dd class="col-7 text-info">' + esc(fmtBytes(p.rxBytes || 0)) + '</dd>';
                    }
                    if (p.txPackets || p.rxPackets) {
                        html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.txrxPkts) + '</dt>'
                             + '<dd class="col-7 small text-nowrap">'
                             + '<span class="text-success">↑' + esc((p.txPackets || 0).toLocaleString()) + '</span>'
                             + ' <span class="text-info">↓' + esc((p.rxPackets || 0).toLocaleString()) + '</span>'
                             + '</dd>';
                    }
                } else {
                    html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.statusLbl) + '</dt><dd class="col-7">' + esc(statusText) + '</dd>';
                    if (linkUp) {
                        html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.negotiatedSpeed) + '</dt><dd class="col-7 fw-semibold text-success">' + esc(portSpeeds[p.speed] || '—') + '</dd>';
                    }
                    if (p.customName) html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.portLabel) + '</dt><dd class="col-7">' + esc(p.customName) + '</dd>';
                    if (p.desc)       html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.description) + '</dt><dd class="col-7">' + esc(p.desc) + '</dd>';
                }
                html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.linkSpeed) + '</dt><dd class="col-7">' + esc(portSpeeds[p.speed] || '—') + '</dd>';
                if (p.type) html += '<dt class="col-5 text-muted fw-normal">' + esc(STR.portType) + '</dt><dd class="col-7">' + esc(p.type) + '</dd>';
                html += '</dl></div></div></div>';
            });
            html += '</div>';
            body.innerHTML = html;
        }

        var bsModal = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(portModal) : null;
        if (bsModal) bsModal.show();
        else if (typeof $ !== 'undefined') $(portModal).modal('show');
    });

    // ── Network stats modal ────────────────────────────────────────
    var netModal = document.createElement('div');
    netModal.className = 'modal fade'; netModal.id = 'gdmsNetModal';
    netModal.setAttribute('tabindex', '-1'); netModal.setAttribute('aria-hidden', 'true');
    netModal.innerHTML =
      '<div class="modal-dialog modal-sm"><div class="modal-content">' +
      '<div class="modal-header py-2"><i class="ti ti-network me-2 text-primary"></i>' +
      '<h6 class="modal-title mb-0 fw-semibold" id="gdmsNetModalTitle"></h6>' +
      '<button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button></div>' +
      '<div class="modal-body p-0" id="gdmsNetModalBody"></div>' +
      '<div class="modal-footer py-2 justify-content-end">' +
      '<button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">' +
      STR.close + '</button></div></div></div>';
    document.body.appendChild(netModal);
    netModal.addEventListener('hide.bs.modal', function() { if (document.activeElement) document.activeElement.blur(); });

    var LBL_ONLINE  = STR.lbl_online;
    var LBL_OFFLINE = STR.lbl_offline;
    var LBL_TOTAL   = STR.lbl_total;
    var LBL_ROUTER  = STR.lbl_router;
    var LBL_SWITCH  = STR.lbl_switch;
    var LBL_AP      = STR.lbl_ap;
    var LBL_CLIENTS = STR.lbl_clients;
    var LBL_PHONES  = STR.lbl_phones;

    document.addEventListener('click', function(e) {
        var link = e.target.closest('.gdms-net-link');
        if (!link) return;
        var raw = link.getAttribute('data-net');
        if (!raw) return;
        var nd;
        try { nd = JSON.parse(raw); } catch(ex) { return; }

        document.getElementById('gdmsNetModalTitle').textContent = nd.name;

        var rows = [
            { icon: 'ti ti-sitemap', label: LBL_ROUTER, on: nd.router_on, off: nd.router_off },
            { icon: 'ti ti-server',  label: LBL_SWITCH, on: nd.switch_on, off: nd.switch_off },
            { icon: 'ti ti-wifi',    label: LBL_AP,     on: nd.ap_on,     off: nd.ap_off     },
            { icon: 'ti ti-phone',   label: LBL_PHONES, on: nd.phone_on,  off: nd.phone_off  },
        ];
        rows = rows.filter(function(r){ return (r.on + r.off) > 0; });

        var html = '<ul class="list-group list-group-flush">';
        rows.forEach(function(r) {
            var total = r.on + r.off;
            var pct   = total > 0 ? Math.round(r.on / total * 100) : null;
            var barClass = (pct === null) ? 'bg-secondary' : (pct >= 80 ? 'bg-success' : (pct >= 50 ? 'bg-warning' : 'bg-danger'));
            html += '<li class="list-group-item px-3 py-2">';
            html += '<div class="d-flex align-items-center justify-content-between mb-1">';
            html += '<span><i class="' + r.icon + ' me-2 text-primary" style="width:14px;"></i><strong>' + r.label + '</strong></span>';
            html += '<span class="small text-muted">';
            if (total === 0) {
                html += '<span class="badge text-bg-secondary opacity-50">0</span>';
            } else {
                html += '<span class="badge bg-success me-1">' + r.on + ' ' + LBL_ONLINE + '</span>';
                if (r.off > 0) html += '<span class="badge bg-danger">' + r.off + ' ' + LBL_OFFLINE + '</span>';
            }
            html += '</span></div>';
            if (total > 0) {
                html += '<div class="progress" style="height:4px;border-radius:2px;"><div class="progress-bar ' + barClass + '" style="width:' + (pct||0) + '%;"></div></div>';
                html += '<div class="text-end text-muted" style="font-size:.7rem;margin-top:2px;">' + LBL_TOTAL + ': ' + total + '</div>';
            }
            html += '</li>';
        });
        html += '<li class="list-group-item px-3 py-2 d-flex align-items-center justify-content-between">';
        html += '<span><i class="ti ti-users me-2 text-warning" style="width:14px;"></i><strong>' + LBL_CLIENTS + '</strong></span>';
        html += '<span class="badge bg-warning text-dark fs-6">' + nd.clients + '</span></li>';
        if (nd.upload_bytes > 0 || nd.download_bytes > 0) {
            html += '<li class="list-group-item px-3 py-2"><div class="fw-semibold text-muted mb-1 small"><i class="ti ti-chart-bar me-1"></i>' + STR.netTraffic + '</div>';
            html += '<div class="d-flex gap-3 small"><span class="text-success">↑ ' + fmtBytes(nd.upload_bytes) + '</span><span class="text-info">↓ ' + fmtBytes(nd.download_bytes) + '</span></div></li>';
        }
        html += '</ul>';
        document.getElementById('gdmsNetModalBody').innerHTML = html;

        var bsNet = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(netModal) : null;
        if (bsNet) bsNet.show();
        else if (typeof $ !== 'undefined') $(netModal).modal('show');
    });

    // ── WiFi Clients modal ───────────────────────────────────────────────────
    var CLIENTS_URL = DATA.urls.clients;

    var clientModal = document.createElement('div');
    clientModal.className = 'modal fade'; clientModal.id = 'gdmsClientModal';
    clientModal.setAttribute('tabindex', '-1'); clientModal.setAttribute('aria-hidden', 'true');
    clientModal.innerHTML =
      '<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">' +
      '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-users me-2 text-info"></i>' +
      STR.connectedClients +
      ' <small class="text-muted ms-2" id="gdmsClientModalDevice"></small></h5>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
      '<div class="modal-body p-0" id="gdmsClientModalBody"><div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div></div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
      STR.close + '</button></div></div></div>';
    document.body.appendChild(clientModal);
    clientModal.addEventListener('hide.bs.modal', function() { if (document.activeElement) document.activeElement.blur(); });

    document.addEventListener('click', function(e) {
        var badge = e.target.closest('.gdms-clients-badge');
        if (!badge) return;
        var networkId = badge.getAttribute('data-network-id');
        var apMac     = badge.getAttribute('data-mac') || '';
        var devName   = badge.getAttribute('data-name') || apMac;
        document.getElementById('gdmsClientModalDevice').textContent = devName;
        var body = document.getElementById('gdmsClientModalBody');
        body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';

        var bsCli = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(clientModal) : null;
        if (bsCli) bsCli.show(); else if (typeof $ !== 'undefined') $(clientModal).modal('show');

        var url = CLIENTS_URL + '&network_id=' + encodeURIComponent(networkId) + '&mac=' + encodeURIComponent(apMac);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { var partial = r.headers.get('X-GDMS-Result-Complete') === '0'; return r.json().then(function(data){ return {data:data, partial:partial}; }); })
            .then(function(resp) {
                var list = resp.data;
                if (!Array.isArray(list) || !list.length) {
                    if (resp.partial) { body.innerHTML = '<p class="text-warning text-center py-3">' + STR.clientsPartialRetry + '</p>'; return; }
                    body.innerHTML = '<p class="text-muted text-center py-3">' + STR.noClients + '</p>';
                    return;
                }
                var html = (resp.partial ? '<div class="alert alert-warning py-2 px-3 m-2 small">' + STR.clientsPartialNotice + '</div>' : '') + '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>'
                    + '<th class="ps-3">' + STR.hostname + '</th>'
                    + '<th>IP</th><th>MAC</th>'
                    + '<th>' + STR.band + ' / SSID</th>'
                    + '<th>' + STR.signal + '</th>'
                    + '<th>' + STR.txrx + '</th>'
                    + '</tr></thead><tbody>';
                list.forEach(function(c) {
                    var rssiColor = c.rssi <= -76 ? 'text-danger' : (c.rssi <= -60 ? 'text-warning' : 'text-success');
                    html += '<tr><td class="ps-3 fw-semibold">' + esc(c.hostname || c.mac) + '</td>'
                        + '<td><code class="small">' + esc(c.ip) + '</code></td>'
                        + '<td><code class="small">' + esc(c.mac) + '</code></td>'
                        + '<td><small>' + esc(c.band) + (c.ssid ? ' — ' + esc(c.ssid) : '') + '</small></td>'
                        + '<td class="' + rssiColor + ' fw-semibold">' + (c.rssi ? c.rssi + ' dBm' : '—') + '</td>'
                        + '<td class="small text-nowrap">'
                            + (c.txRate ? '<span class="text-success">↑' + c.txRate + 'M</span> ' : '')
                            + (c.rxRate ? '<span class="text-info">↓' + c.rxRate + 'M</span>' : '')
                        + '</td></tr>';
                });
                html += '</tbody></table></div>';
                body.innerHTML = html;
            })
            .catch(function() { body.innerHTML = '<p class="text-danger text-center py-3">' + STR.reqFailed + '</p>'; });
    });

    // ── SIP Phone detail modal ───────────────────────────────────────────────
    var sipModal = document.createElement('div');
    sipModal.className = 'modal fade'; sipModal.id = 'gdmsSipModal';
    sipModal.setAttribute('tabindex', '-1'); sipModal.setAttribute('aria-hidden', 'true');
    sipModal.innerHTML =
      '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
      '<div class="modal-header py-2"><h5 class="modal-title"><i class="ti ti-phone me-2 text-primary"></i>' +
      '<span id="gdmsSipModalTitle"></span></h5>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
      '<div class="modal-body" id="gdmsSipModalBody"></div>' +
      '<div class="modal-footer py-2">' +
      '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">' + STR.close + '</button>' +
      '</div></div></div>';
    document.body.appendChild(sipModal);
    sipModal.addEventListener('hide.bs.modal', function() {
        if (document.activeElement) document.activeElement.blur();
    });

    document.addEventListener('click', function(e) {
        var dot = e.target.closest('.gdms-sip-dot');
        if (!dot) return;
        var sip       = dot.getAttribute('data-sip')       || '';
        var dnd       = parseInt(dot.getAttribute('data-dnd')    || '0', 10);
        var sync      = parseInt(dot.getAttribute('data-sync')   || '0', 10);
        var syncMsg   = dot.getAttribute('data-sync-msg')  || '';
        var task      = parseInt(dot.getAttribute('data-task')   || '0', 10);
        var ext       = dot.getAttribute('data-extension') || '';
        var sipAccounts = [];
        try { sipAccounts = JSON.parse(dot.getAttribute('data-sip-accounts') || '[]') || []; } catch (e) { sipAccounts = []; }
        var lastSeen  = dot.getAttribute('data-last-seen') || '';
        var privIp    = dot.getAttribute('data-private-ip')|| '';
        var pubIp     = dot.getAttribute('data-public-ip') || '';
        var site      = dot.getAttribute('data-site')      || '';
        var devName   = dot.getAttribute('data-name')      || '';
        var pbxName   = dot.getAttribute('data-pbx-name')  || '';
        var pbxIp     = dot.getAttribute('data-pbx-ip')    || '';
        var pbxUrl    = dot.getAttribute('data-pbx-url')   || '';
        var mac       = dot.getAttribute('data-mac')        || '';

        document.getElementById('gdmsSipModalTitle').textContent = devName;

        var registered = sip === 'registered';
        var sipColor   = registered ? '#28a745' : '#dc3545';
        var sipLabel   = registered ? STR.sipRegistered : STR.sipUnregistered;

        var rows = [];
        rows.push(['<i class="ti ti-phone-call me-1"></i>' + STR.sipStatusLbl,
            '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' + sipColor + ';outline:1px solid rgba(128,128,128,.3);margin-right:5px;"></span><strong style="color:' + sipColor + '">' + esc(sipLabel) + '</strong>']);
        if (sipAccounts.length) {
            var accountRows = '<div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>' + esc(STR.extension) + '</th><th>' + esc(STR.pbxUcm) + '</th><th>' + esc(STR.sipStatusLbl) + '</th></tr></thead><tbody>';
            sipAccounts.forEach(function(account) {
                var accountStatus = String(account.status || '').toLowerCase();
                var isRegistered = /^(1|true|up|normal|registered|online|ok)$/.test(accountStatus);
                var accountLabel = isRegistered ? STR.sipRegistered : (account.status ? STR.sipUnregistered : '—');
                var accountServer = String(account.server || '').trim();
                var accountPbxName = String(account.pbx_name || '').trim();
                var accountPbxUrl = String(account.pbx_url || '').trim();
                var accountPbxIpUrl = String(account.pbx_ip_url || '').trim();
                var accountPbxCell = '<code>' + esc(accountServer || '—') + '</code>';

                if (accountPbxName) {
                    var accountPbxTitle = accountPbxUrl
                        ? '<a href="' + esc(accountPbxUrl) + '" target="_blank" rel="noopener noreferrer" class="fw-semibold text-decoration-none">' + esc(accountPbxName) + ' <i class="ti ti-external-link opacity-50" style="font-size:.75em;"></i></a>'
                        : '<span class="fw-semibold">' + esc(accountPbxName) + '</span>';
                    var accountPbxAddress = accountPbxIpUrl
                        ? '<a href="' + esc(accountPbxIpUrl) + '" target="_blank" rel="noopener noreferrer" class="small font-monospace text-muted text-decoration-none">' + esc(account.pbx_ip || accountServer) + ' <i class="ti ti-external-link opacity-50" style="font-size:.7em;"></i></a>'
                        : '<span class="small font-monospace text-muted">' + esc(account.pbx_ip || accountServer) + '</span>';
                    accountPbxCell = '<span class="d-inline-flex flex-column">' + accountPbxTitle + accountPbxAddress + '</span>';
                }

                accountRows += '<tr><td><code>' + esc(account.extension || account.index || '—') + '</code></td><td>' + accountPbxCell + '</td><td><span class="badge ' + (isRegistered ? 'text-bg-success' : 'text-bg-secondary') + '">' + esc(accountLabel) + '</span></td></tr>';
            });
            accountRows += '</tbody></table></div>';
            rows.push(['<i class="ti ti-list-details me-1"></i>' + esc(STR.sipAccounts || 'SIP Accounts'), accountRows]);
        } else if (ext) {
            rows.push(['<i class="ti ti-hash me-1"></i>' + STR.extension, '<code>' + esc(ext) + '</code>']);
        }
        if (site)     rows.push(['<i class="ti ti-building me-1"></i>' + STR.site, esc(site)]);
        if (mac)      rows.push(['<i class="ti ti-fingerprint me-1"></i>' + STR.macLbl,
            '<code class="gdms-copy" style="cursor:pointer;" data-copy="' + esc(mac) + '" title="' + STR.copyMac + '" data-bs-toggle="tooltip">' + esc(mac) + '</code>']);
        if (privIp)   rows.push(['<i class="ti ti-network me-1"></i>' + STR.fwPrivateIp,
            '<a href="http://' + esc(privIp) + '" target="_blank" rel="noopener"><code>' + esc(privIp) + '</code></a>']);
        if (pubIp)    rows.push(['<i class="ti ti-world me-1"></i>' + STR.publicIpLbl,
            '<a href="https://www.whois.com/whois/' + esc(pubIp) + '" target="_blank" rel="noopener"><code>' + esc(pubIp) + '</code></a>']);
        if (lastSeen) rows.push(['<i class="ti ti-clock me-1"></i>' + STR.lastSeenCap, esc(lastSeen)]);
        // The SIP account table now carries the PBX name and management links
        // per extension. Keep this summary row only for legacy/single-extension
        // records without an account list, where there is no table to show it.
        if (!sipAccounts.length && pbxIp) rows.push(['<i class="ti ti-cpu me-1"></i>' + STR.pbxUcm,
            '<a href="http://' + esc(pbxIp) + '" target="_blank" rel="noopener"><code>' + esc(pbxIp) + '</code></a>'
            + (pbxName ? ' ' + (pbxUrl ? '<a href="' + esc(pbxUrl) + '" target="_blank" rel="noopener" class="text-muted small text-decoration-none">(' + esc(pbxName) + ' <i class="ti ti-external-link opacity-50" style="font-size:.8em;"></i>)</a>' : '<span class="text-muted small">(' + esc(pbxName) + ')</span>') : '')]);
        rows.push(['<i class="ti ti-bell-off me-1"></i>' + STR.dndLbl,
            dnd ? '<span class="badge text-bg-danger">' + STR.active + '</span>'
                : '<span class="badge border" style="color:inherit;background:transparent">' + STR.off + '</span>']);
        rows.push(['<i class="ti ti-refresh me-1"></i>' + STR.synchronized,
            sync ? '<span class="badge text-bg-success">' + STR.yes + '</span>'
                 : '<span class="badge bg-warning text-dark">' + STR.no + '</span>']);
        if (!sync && syncMsg) rows.push(['<i class="ti ti-alert-triangle me-1 text-warning"></i>' + STR.syncError, '<small class="text-danger">' + esc(syncMsg) + '</small>']);
        rows.push(['<i class="ti ti-calendar me-1"></i>' + STR.scheduledTask,
            task ? '<span class="badge bg-warning text-dark">' + STR.pending + '</span>'
                 : '<span class="badge border" style="color:inherit;background:transparent">' + STR.none + '</span>']);

        var html = '<dl class="row mb-0 small">';
        rows.forEach(function(r) {
            html += '<dt class="col-sm-5 text-muted fw-normal">' + r[0] + '</dt><dd class="col-sm-7 mb-1">' + r[1] + '</dd>';
        });
        html += '</dl>';
        html += '<div class="border-top pt-2 mt-1">' +
            '<div class="alert alert-danger py-2 px-3 mb-2 small"><i class="ti ti-alert-octagon me-1"></i><strong>' + esc(STR.factoryResetBtn) + ':</strong> ' + esc(STR.factoryResetWarning) + '</div>' +
            '<div class="d-flex justify-content-end mb-2">' +
            '<button type="button" class="btn btn-danger btn-sm" id="gdmsSipFactoryResetBtn"><i class="ti ti-trash me-1"></i>' + STR.factoryResetBtn + '</button>' +
            '</div>' +
            '<div class="d-flex align-items-center gap-2 border-top pt-2">' +
            '<span class="me-auto small"><i class="ti ti-alert-triangle text-warning me-1"></i>' + STR.rebootConfirm + '</span>' +
            '<button type="button" class="btn btn-danger btn-sm" id="gdmsSipRebootBtn"><i class="ti ti-refresh me-1"></i>' + STR.rebootBtn + '</button>' +
            '</div>' +
            '</div>';
        document.getElementById('gdmsSipModalBody').innerHTML = html;
        initTooltips(document.getElementById('gdmsSipModalBody'));

        var _rb = document.getElementById('gdmsSipRebootBtn');
        _rb._rs = 'idle';
        _rb.className = 'btn btn-danger btn-sm';
        _rb.innerHTML = '<i class="ti ti-refresh me-1"></i>' + STR.rebootBtn;
        _rb.disabled = false;

        _rb.onclick = function() {
            if (_rb._rs === 'loading') return;
            if (_rb._rs === 'idle') {
                _rb._rs = 'confirm';
                _rb.className = 'btn btn-warning text-dark btn-sm';
                _rb.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>' + esc(STR.rebootConfirmYes);
                var _t = setTimeout(function() {
                    if (_rb._rs === 'confirm') {
                        _rb._rs = 'idle';
                        _rb.className = 'btn btn-danger btn-sm';
                        _rb.innerHTML = '<i class="ti ti-refresh me-1"></i>' + STR.rebootBtn;
                    }
                }, 4000);
                _rb._cancelTimer = _t;
                return;
            }
            clearTimeout(_rb._cancelTimer);
            _rb._rs = 'loading';
            _rb.disabled = true;
            _rb.innerHTML = '<i class="ti ti-loader" style="animation:spin .8s linear infinite;display:inline-block;"></i> ' + STR.rebooting;
            var _csrf = typeof window.glpiGetNewCSRFToken === 'function'
                ? window.glpiGetNewCSRFToken()
                : (document.querySelector('meta[property="glpi:csrf_token"]') || document.createElement('meta')).getAttribute('content') || '';
            var _fd = new FormData(); _fd.append('mac', mac);
            fetch(REBOOT_GDMS_URL, { method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','X-Glpi-Csrf-Token':_csrf}, body:_fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    _rb._rs = 'idle'; _rb.className = 'btn btn-danger btn-sm';
                    _rb.innerHTML = '<i class="ti ti-refresh me-1"></i>' + STR.rebootBtn; _rb.disabled = false;
                    var badge = document.createElement('div');
                    badge.className = 'alert ' + (resp.error ? 'alert-danger' : 'alert-success') + ' py-1 px-2 mb-2 small';
                    badge.textContent = resp.error || STR.rebootOk;
                    document.getElementById('gdmsSipModalBody').prepend(badge);
                })
                .catch(function() {
                    _rb._rs = 'idle'; _rb.className = 'btn btn-danger btn-sm';
                    _rb.innerHTML = '<i class="ti ti-refresh me-1"></i>' + STR.rebootBtn; _rb.disabled = false;
                });
        };

        var _fr = document.getElementById('gdmsSipFactoryResetBtn');
        if (_fr) {
            _fr._rs = 'idle';
            _fr.onclick = function() {
                if (_fr._rs === 'loading') return;
                if (_fr._rs === 'idle') {
                    _fr._rs = 'confirm';
                    _fr.className = 'btn btn-warning text-dark btn-sm';
                    _fr.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>' + esc(STR.factoryResetConfirmYes);
                    _fr._t = setTimeout(function() {
                        if (_fr._rs === 'confirm') {
                            _fr._rs = 'idle';
                            _fr.className = 'btn btn-danger btn-sm';
                            _fr.innerHTML = '<i class="ti ti-trash me-1"></i>' + STR.factoryResetBtn;
                        }
                    }, 6000);
                    return;
                }
                clearTimeout(_fr._t);
                _fr._rs = 'loading';
                _fr.disabled = true;
                _fr.innerHTML = '<i class="ti ti-loader" style="animation:spin .8s linear infinite;display:inline-block;"></i> ' + STR.factoryResetting;
                var _csrf2 = typeof window.glpiGetNewCSRFToken === 'function'
                    ? window.glpiGetNewCSRFToken()
                    : (document.querySelector('meta[property="glpi:csrf_token"]') || document.createElement('meta')).getAttribute('content') || '';
                var _fd2 = new FormData(); _fd2.append('mac', mac);
                fetch(FACTORY_RESET_GDMS_URL, { method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','X-Glpi-Csrf-Token':_csrf2}, body:_fd2 })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        _fr._rs = 'idle'; _fr.className = 'btn btn-danger btn-sm';
                        _fr.innerHTML = '<i class="ti ti-trash me-1"></i>' + STR.factoryResetBtn; _fr.disabled = false;
                        var badge = document.createElement('div');
                        badge.className = 'alert ' + (resp.error ? 'alert-danger' : 'alert-success') + ' py-1 px-2 mb-2 small';
                        badge.textContent = resp.error || STR.factoryResetOk;
                        document.getElementById('gdmsSipModalBody').prepend(badge);
                    })
                    .catch(function() {
                        _fr._rs = 'idle'; _fr.className = 'btn btn-danger btn-sm';
                        _fr.innerHTML = '<i class="ti ti-trash me-1"></i>' + STR.factoryResetBtn; _fr.disabled = false;
                    });
            };
        }

        var bsSip = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(sipModal) : null;
        if (bsSip) bsSip.show();
        else if (typeof $ !== 'undefined') $(sipModal).modal('show');
    });

    // ── Cloud Alerts panel ────────────────────────────────────────────────────
    var REBOOT_GDMS_URL        = DATA.urls.reboot_gdms;
    var FACTORY_RESET_GDMS_URL = DATA.urls.factory_reset_gdms;
    var ALERTS_URL  = DATA.urls.alerts;
    var alertsBody = document.getElementById('gdms-alerts-body');
    if (alertsBody) {
        alertsBody.addEventListener('click', function(e) {
            var btn = e.target.closest('.gdms-alert-dismiss');
            if (!btn) return;
            e.preventDefault();
            var row = btn.closest('tr');
            if (!row) return;

            // The tooltip is managed by GLPI's native initTooltips().
            // Hide its active instance before removing the button from the DOM;
            // otherwise Bootstrap can leave the floating tooltip orphaned.
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                var tooltip = bootstrap.Tooltip.getInstance(btn);
                if (tooltip) {
                    tooltip.hide();
                }
            }
            btn.blur();

            row.style.display = 'none';

            var visibleRows = alertsBody.querySelectorAll('tbody tr:not([style*="display: none"])');
            if (!visibleRows.length) {
                alertsBody.innerHTML = '<p class="text-muted text-center py-2 small mb-0">' + STR.noAlerts + '</p>';
            }
        });

        setTimeout(function() {
            // Always request the live cloud-backed list. The cache-buster also
            // protects against shared hosting/reverse proxies that cache GETs.
            var alertsRequestUrl = ALERTS_URL + (ALERTS_URL.indexOf('?') === -1 ? '?' : '&') + '_gdms_alerts_ts=' + Date.now();
            fetch(alertsRequestUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
            })
                .then(function(r) { var partial = r.headers.get('X-GDMS-Result-Complete') === '0'; return r.json().then(function(data){ return {data:data, partial:partial}; }); })
                .then(function(resp) {
                    var list = resp.data;
                    if (!Array.isArray(list) || !list.length) {
                        if (resp.partial) { alertsBody.innerHTML = '<p class="text-warning text-center py-2 small mb-0">' + STR.alertsPartialRetry + '</p>'; return; }
                        alertsBody.innerHTML = '<p class="text-muted text-center py-2 small mb-0">' + STR.noAlerts + '</p>';
                        return;
                    }
var sevColor = {'critical':'danger','warning':'warning','medium':'warning','info':'info','low':'secondary','error':'danger'};
                    var html = (resp.partial ? '<div class="alert alert-warning py-2 px-3 m-2 small">' + STR.alertsPartialNotice + '</div>' : '') + '<div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle"><thead><tr>'
                      + '<th class="ps-3" style="white-space:nowrap">' + STR.alertTime + '</th>'
                      + '<th>' + STR.alertSev + '</th>'
                      + '<th>' + STR.alertDevice + '</th>'
                      + '<th>' + STR.alertMsg + '</th>'
                      + '<th></th>'
                      + '</tr></thead><tbody>';
                    list.slice(0, 50).forEach(function(a) {
                        var sev = String(a.severity || 'info').toLowerCase();
                        var cls = sevColor[sev] || 'secondary';
                        var ct  = parseInt(a.createTime || 0);
                        var ts  = ct ? new Date(ct).toLocaleString() : '—';
                        var desc = String(a.description || a.alertType || '');
                        var extras = [];
                        if (a.category) extras.push(STR.alertCategory + ': ' + a.category);
                        if (a.reason)   extras.push(STR.alertReason   + ': ' + a.reason);
                        if (a.port_id)  extras.push('Port: ' + a.port_id);
                        var extraHtml = extras.length
                            ? '<br><span class="text-muted" style="font-size:.8em;">' + extras.map(esc).join(' · ') + '</span>'
                            : '';
                        var devName = String(a.deviceName || a.deviceMac || '—');
                        html += '<tr data-alert-id="' + esc(a.id) + '">'
                          + '<td class="ps-3 text-nowrap small text-muted">' + esc(ts) + '</td>'
                          + '<td><span class="badge bg-' + cls + ' text-' + (cls === 'warning' ? 'dark' : 'white') + '">' + esc(sev) + '</span></td>'
                          + '<td class="small text-nowrap">' + esc(devName) + '</td>'
                          + '<td class="small">' + esc(desc) + extraHtml + '</td>'
                          + '<td class="text-end pe-2"><button type="button" class="btn btn-sm btn-link p-0 text-muted gdms-alert-dismiss" data-alert-id="' + esc(a.id) + '" title="' + esc(STR.alertDismiss) + '" data-bs-toggle="tooltip" data-bs-trigger="hover"><i class="ti ti-x"></i></button></td>'
                          + '</tr>';
                    });
                    html += '</tbody></table></div>';
                    alertsBody.innerHTML = html;
                    initTooltips(alertsBody);

                    var chevron = document.querySelector('.gdms-alerts-chevron');
                    var collapse = document.getElementById('gdms-alerts-collapse');
                    if (chevron && collapse && collapse.classList.contains('show')) {
                        chevron.style.transform = 'rotate(180deg)';
                    }
                })
                .catch(function() {
                    if (alertsBody) alertsBody.innerHTML = '<p class="text-danger text-center py-2">' + STR.alertsError + '</p>';
                });
        }, 1500);

        document.getElementById('gdms-alerts-card') && document.getElementById('gdms-alerts-card').addEventListener('show.bs.collapse', function() {
            var chevron = document.querySelector('.gdms-alerts-chevron');
            if (chevron) chevron.style.transform = 'rotate(180deg)';
        });
        document.getElementById('gdms-alerts-card') && document.getElementById('gdms-alerts-card').addEventListener('hide.bs.collapse', function() {
            var chevron = document.querySelector('.gdms-alerts-chevron');
            if (chevron) chevron.style.transform = '';
        });
    }

    // ── Copy-on-click ─────────────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var el = e.target.closest('.gdms-copy');
        if (!el) return;
        var txt = el.getAttribute('data-copy') || el.textContent;
        navigator.clipboard && navigator.clipboard.writeText(txt);
    });

})();


(function() {
    'use strict';

    var hc = document.getElementById('gdms-history-chart');
    if (!hc) return;

    var chartLabels   = DATA.chartLabels;
    var chartDatasets = DATA.chartDatasets;
    var chartInstance = null;
    var attempts = 0;
    var maxAttempts = 80;

    function isDarkTheme() {
        var theme = document.documentElement.getAttribute('data-bs-theme');
        if (theme) return theme === 'dark';
        var bg = window.getComputedStyle(document.body).backgroundColor || '';
        var rgb = bg.match(/\d+/g);
        return !!(rgb && parseInt(rgb[0], 10) < 100);
    }

    function renderHistoryChart() {
        if (!document.body.contains(hc)) return;

        var ec = window.echarts;
        if (!ec || typeof ec.init !== 'function') {
            if (attempts++ < maxAttempts) {
                window.setTimeout(renderHistoryChart, 100);
            }
            return;
        }

        try {
            if (chartInstance && typeof chartInstance.dispose === 'function') {
                chartInstance.dispose();
            }

            chartInstance = ec.init(hc, isDarkTheme() ? 'dark' : null, { renderer: 'svg' });
            chartInstance.setOption({
                backgroundColor: 'transparent',
                tooltip: {
                    trigger: 'axis',
                    formatter: function(params) {
                        if (!params || !params.length) return '';
                        var s = '<b>' + params[0].axisValue + '</b><br/>';
                        params.forEach(function(p) {
                            if (p.value === null || p.value === undefined) return;
                            s += '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + p.color + ';margin-right:5px;"></span>'
                               + p.seriesName + ': ' + p.value + '%<br/>';
                        });
                        return s;
                    }
                },
                legend: { bottom: 0, type: 'scroll', textStyle: { fontSize: 11 } },
                grid: { top: 12, right: 16, bottom: 40, left: 48, containLabel: false },
                xAxis: { type: 'category', data: chartLabels, axisLabel: { fontSize: 11 }, axisLine: { lineStyle: { opacity: .3 } }, splitLine: { show: false } },
                yAxis: { type: 'value', min: 0, max: 100, axisLabel: { formatter: '{value}%', fontSize: 11 }, splitLine: { lineStyle: { opacity: .15 } } },
                series: chartDatasets.map(function(ds) {
                    return { name: ds.label, type: 'line', data: ds.data, smooth: true, connectNulls: true, symbolSize: 4,
                        lineStyle: { color: ds.borderColor, width: 2 }, itemStyle: { color: ds.borderColor },
                        areaStyle: { color: ds.borderColor, opacity: .08 } };
                })
            });
        } catch (e) {
            if (attempts++ < maxAttempts) {
                window.setTimeout(renderHistoryChart, 250);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderHistoryChart, { once: true });
    } else {
        renderHistoryChart();
    }

    var resizeTimer = null;
    window.addEventListener('resize', function() {
        if (!chartInstance || typeof chartInstance.resize !== 'function') return;
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function() {
            try { chartInstance.resize(); } catch (e) {}
        }, 50);
    });
})();


(function () {
    var tbl = document.getElementById('gdms-device-table');
    if (!tbl) return;
    var tbody   = tbl.querySelector('tbody');
    var resetBtn = document.getElementById('gdms-sort-reset');
    var sortCol = null;
    var sortAsc = true;
    var numCols = ['type', 'status', 'clients', 'avail', 'sla'];
    var groupOrder = { network: 0, pbx: 1, phone: 2, other: 3 };

    function refreshGroupSeparators(rows) {
        var previous = null;
        rows.forEach(function(row) {
            var group = row.dataset.group || 'other';
            row.classList.toggle('gdms-group-start', group !== previous);
            previous = group;
        });
    }

    function applySort(col, asc) {
        sortCol = col; sortAsc = asc;
        tbl.querySelectorAll('th.gdms-sortable .gdms-sort-icon').forEach(function (ic) {
            ic.textContent = '⇅'; ic.className = 'gdms-sort-icon opacity-40';
        });
        var activeTh = tbl.querySelector('th[data-col="' + col + '"]');
        if (activeTh) {
            var icon = activeTh.querySelector('.gdms-sort-icon');
            if (icon) { icon.textContent = asc ? '↑' : '↓'; icon.className = 'gdms-sort-icon'; }
        }
        var rows = Array.from(tbody.querySelectorAll('tr[data-original-index]'));
        rows.sort(function (a, b) {
            var ag = groupOrder[a.dataset.group || 'other'] ?? 99;
            var bg = groupOrder[b.dataset.group || 'other'] ?? 99;
            if (ag !== bg) return ag - bg;
            var av = a.dataset[col] || '', bv = b.dataset[col] || '', cmp;
            cmp = numCols.indexOf(col) !== -1 ? parseFloat(av) - parseFloat(bv) : av.localeCompare(bv);
            return asc ? cmp : -cmp;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
        refreshGroupSeparators(rows);
        if (resetBtn) resetBtn.style.display = '';
    }

    (function () {
        var p = new URLSearchParams(location.search);
        var c = p.get('sort'), d = p.get('dir');
        if (c && tbl.querySelector('th[data-col="' + c + '"]')) applySort(c, d !== 'desc');
        else refreshGroupSeparators(Array.from(tbody.querySelectorAll('tr[data-original-index]')));
    })();

    tbl.querySelectorAll('th.gdms-sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = th.dataset.col;
            var asc = sortCol === col ? !sortAsc : true;
            applySort(col, asc);
            var url = new URL(location.href);
            url.searchParams.set('sort', col); url.searchParams.set('dir', asc ? 'asc' : 'desc');
            history.replaceState(null, '', url);
        });
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            sortCol = null;
            tbl.querySelectorAll('th.gdms-sortable .gdms-sort-icon').forEach(function (ic) {
                ic.textContent = '⇅'; ic.className = 'gdms-sort-icon opacity-40';
            });
            var rows = Array.from(tbody.querySelectorAll('tr[data-original-index]'));
            rows.sort(function (a, b) { return +a.dataset.originalIndex - +b.dataset.originalIndex; });
            rows.forEach(function (r) { tbody.appendChild(r); });
            refreshGroupSeparators(rows);
            resetBtn.style.display = 'none';
            var url = new URL(location.href);
            url.searchParams.delete('sort'); url.searchParams.delete('dir');
            history.replaceState(null, '', url);
        });
    }
})();


(function() {
    var filterApp = document.getElementById('gdms-filter-app');
    if (!filterApp || typeof window.Vue === 'undefined' || typeof window.Vue.createApp !== 'function') return;
    filterApp.style.removeProperty('display');
    window.Vue.createApp({
        data: function() {
            return {
                search: '',
                statusFilter: 'all',
                typeFilter: 'all',
                strAll:     DATA.strings.filterAll,
                strOnline:  DATA.strings.filterOnline,
                strOffline: DATA.strings.filterOffline,
                strNetwork: DATA.strings.filterNetwork,
                strPhone:   DATA.strings.filterPhone,
                strDevice:  DATA.strings.filterDevice,
            };
        },
        watch: {
            search:       'applyFilters',
            statusFilter: 'applyFilters',
            typeFilter:   'applyFilters'
        },
        methods: {
            applyFilters: function() {
                var s = this.search.toLowerCase();
                var sf = this.statusFilter;
                var tf = this.typeFilter;
                document.querySelectorAll('#gdms-device-table tbody tr[data-name]').forEach(function(row) {
                    var show = true;
                    if (s && !row.dataset.name.includes(s) && !row.dataset.model.includes(s) && !row.dataset.network.includes(s)) show = false;
                    if (sf === 'online'  && row.dataset.status !== '0') show = false;
                    if (sf === 'offline' && row.dataset.status === '0') show = false;
                    if (tf === 'phone'   && row.dataset.type !== '1') show = false;
                    if (tf === 'network' && row.dataset.type === '1') show = false;
                    row.style.display = show ? '' : 'none';
                });
            }
        }
    }).mount('#gdms-filter-app');
})();

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startDashboard, { once: true });
    } else {
        startDashboard();
    }
})();
