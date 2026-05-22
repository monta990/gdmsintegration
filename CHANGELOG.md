# Changelog — GDMS Integration

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.4.3] — 2026-05-22

### Fixed
- **Accessibility warnings — label/input associations** — all `<label>` elements in the config form now have `for` attributes pointing to their corresponding field `id`. Inputs that lacked an `id` received one. The Entity dropdown heading was changed from `<label>` to `<p>` (no single target field). Eliminates 16 browser accessibility warnings on the config page.
- **Accessibility warnings — dashboard search field** — the Vue filter bar search `<input>` now has `id="gdms-device-search"` and `name="search"`, resolving the "form field has neither id nor name" browser warning.
- **Accessibility warnings — firmware schedule label** — the "Schedule for" label in the firmware upgrade modal now has `for="gdmsFwDatetime"`, linking it to the flatpickr date input.

### Improved
- **vis-network updated to 10.1.0**.

---

## [1.4.2] — 2026-05-20

### Added
- **Factory reset for UC phones/ATAs** — the phone SIP detail modal now includes a Factory Reset button (before the Reboot button) with a prominent danger alert explaining the consequences. The button requires two clicks (second click turns yellow "I understand — Reset now", auto-reverts in 6 s) to prevent accidental execution. Calls GDMS `task/add` with `taskType=2`. Requires `config:UPDATE` permission. -BETA - THIS FEATURE MAY FAIL.

### Fixed
- **Firmware scheduler date/time picker did not update the field after selection** — `altInput: true` combined with `wrap: true` caused flatpickr to insert a hidden secondary input inside the Bootstrap `btn-group`, leaving the visible field blank after picking a date or time. Removed `altInput`/`altFormat`; the original `data-input` field now updates directly with `d/m/Y H:i` format. Schedule submission is unaffected (reads `selectedDates[0]`) - BETA - THIS FEATURE MAY FAIL.
- **GWN device disappears intermittently** — when the GWN Cloud ap/list request for one network timed out, the plugin treated that network as having zero devices and deleted their state records, causing devices to vanish from the dashboard until the next successful sync. `gwnGetDevices()` now returns `false` on any per-network failure, which triggers the existing removal guard so no state is deleted during a partial API failure.
- **Restart devices** — an immediate execution task is scheduled to restart the device. -BETA - THIS FEATURE MAY FAIL.
- **JSON Export** — now export and import correctly.


### Improved
- **Firmware modal — CDN-only devices show "Latest available"** — phones/ATAs (GRP, HT, WP, etc.) have no firmware version page on grandstream.com; the modal now displays "Latest available" instead of the raw CDN filename. Version sent to GDMS task is left blank when only a download URL is known, avoiding the previous incorrect behaviour of sending the current firmware version as the target.
- **Firmware check_all — parallel GWN version fetch** — `check_all` now fetches firmware versions for all GWN networks in a single parallelised `curl_multi` batch instead of one sequential HTTP call per network, matching the behaviour of the existing `check` action. Reduces latency proportionally to the number of configured networks.
- **Twig + Vue 3 frontend** — `front/dashboard.php` and `front/config.form.php` converted to standalone Twig templates (`templates/dashboard.html.twig`, `templates/config_form.html.twig`). PHP data layer and HTML presentation fully separated. Vue 3 filter bar replaces inline JS DOM manipulation. Compatible with GLPI 11 and GLPI 12.

---

## [1.4.1] — 2026-05-13

### Added
- **History import from Excel** — new card in Configuration lets operators restore availability history from a previously exported `gdms_history_*.xlsx` file (renamed from `gdms_disponibilidad_*.xlsx`). Device-days that already have data are skipped (non-destructive). Each imported day generates 100 synthetic records spaced ~14 min apart so the daily online/total ratio exactly reconstructs the original percentage (±1 %).
- **Plugin configuration export** — download all plugin settings as a JSON backup file. An optional checkbox includes API credentials (username, keys, secrets) in the export for full migration scenarios.
- **Plugin configuration import** — restore settings from a previously exported JSON backup. Credentials are only written when the source file explicitly included them; all other imports leave stored secrets unchanged.
- **Firmware modal — device name and private IP** — modal header now shows the device name and its private IP (clickable link) so the operator knows which device they are updating without scrolling the table.
- **Firmware modal — copy MAC with one click** — clicking the MAC code in the modal copies it to the clipboard; shows a brief "Copied!" confirmation.
- **Firmware modal — firmware downloads link** — info note now includes a direct link to grandstream.com/support/firmware that opens in a new tab.
- **Firmware modal — beta-only devices show selectable version** — for GDMS-managed phones whose firmware page only has a beta channel (GRP260x, WP8x6, HT8xxV2, GCC6xx), the available version is now shown as a pre-selected radio button instead of a text note, making the scheduled version clearly visible - BETA - THIS FEATURE MAY FAIL.
- **Topology — phone → PBX edges** — phones are now connected to their UCM/GCC PBX in the vis-network topology graph based on shared network name; lines are drawn automatically with no extra queries.
- **Topology — localised node status** — node tooltips now use the active UI language for "Online"/"Offline" instead of hard-coded English.
- **Firmware update in progress indicator** — after a successful upgrade request the firmware version cell shows "Updating…" instead of going blank; reverts to the real version on the next sync.

### Fixed
- **CSRF double-upgrade failure** — second firmware upgrade in the same session failed with CSRF error. Root cause: GLPI 11 consumes single-use form tokens but preserves `X-Glpi-Csrf-Token` header tokens (`preserve_token: true`). All firmware upgrade fetches now send `X-Requested-With: XMLHttpRequest` + `X-Glpi-Csrf-Token` header; the body token field is removed.

### Improved
- **Sync performance** — eliminated up to 3 DB round-trips per device: device state is loaded once into an in-process PHP cache (`primeCache()`) so `getState()` and `saveStateWithNetwork()` skip per-device `find()` calls; existing topology links are pre-loaded into a PHP set so link deduplication is a hash lookup; all history snapshots are flushed in a single bulk `INSERT` at the end of the loop instead of one per device. Expected reduction: ~35 % fewer queries on a 35-device account.
- **vis-network updated to 10.0.3**.

---

## [1.4.0] — 2026-05-10

### Added
- **Phone SIP status dot** — phones show a colour-coded 9 px dot in the Ports column (green = SIP registered, red = unregistered; dimmed when offline). Clicking opens a SIP detail modal with: SIP status, extension, site, private IP (clickable), public IP (WHOIS link), last seen, PBX/UCM IP, Do Not Disturb, provisioning sync status + error, scheduled task.
- **Phone SIP dot tooltip** — hovering the SIP dot shows a native tooltip: SIP state, extension (if any), Do Not Disturb flag.
- **PBX / UCM in phone modal** — SIP modal shows the UCM/GCC device in the same network as a clickable private IP link with device name. Matched by `siteName`; no extra API call.
- **ATA / HT devices show phone modal** — any device whose model prefix matches the phone list (HT, GRP, GXP, GXV, GXW, WP, DP, GHP, GVC, GSC, GDS) renders the SIP dot and modal regardless of GLPI itemtype (Phone or NetworkEquipment).
- **GDMS provisioning fields synced** — four new fields from `device/list` persisted per device: `dnd`, `is_synchronized`, `sync_failure_msg`, `scheduled_task`.
- **`accountStatus` → SIP status mapping** — `accountStatus` (1 = registered) now mapped to `sip_status`; was previously unpopulated.
- **`lastTime` → last seen fallback** — GDMS `lastTime` string used as `last_seen` when GWN `lastSeen` epoch is absent.
- **IPv4/IPv6 display preference** — new "Private IP display" config setting (IPv4 preferred by default); fallback to other version when preferred is absent; both shown as clickable links when both present.
- **IPv6 addresses clickable** — IPv6 in the Private IP column rendered as `http://[addr]/` links (RFC 2732).
- **Correct IPv4/IPv6 routing at sync** — `privateIp` values containing `:` stored in `ipv6` column instead of `private_ip`.

---

## [1.3.8] — 2026-05-09

### Fixed
- **GLPI 11/12 compatibility** — replaced removed `Html::displayRightError()` with `throw new \Glpi\Exception\Http\AccessDeniedHttpException()` in `front/history_export.php`; compatible with both GLPI 11 and 12.
- **GLPI 11/12 compatibility** — replaced non-existent `Html::forbidden()` with `throw new \Glpi\Exception\Http\AccessDeniedHttpException()` in `front/dashboard.php`; method never existed in either GLPI version.
- **GLPI 12 compatibility** — `Hooks::CSRF_COMPLIANT` registration in `setup.php` now guarded with `defined()` check; constant was removed in GLPI 12 causing a PHP fatal error on plugin load.
- **GLPI 12 compatibility** — replaced `$DB->doQueryOrDie()` with `$DB->doQuery()` in `setup.php` (install/uninstall); method was removed in GLPI 12. Both methods throw on error so behavior is identical across versions.
- **GLPI 11/12 compatibility** — fixed `$rightname` PHP compile error; GLPI 12 added `string` type to `CommonGLPI::$rightname` while GLPI 11 leaves it untyped — PHP requires child type to match parent exactly. Introduced `PluginGdmsintegrationBaseGLPI` and `PluginGdmsintegrationBaseTM` abstract shim classes with conditional type declaration (`GLPI_VERSION >= 12`); all five plugin classes now extend the appropriate shim and inherit `$rightname` without redeclaring it.

---

## [1.3.7] — 2026-05-03

### Security
- **Credential redaction in debug logs** — GWN OAuth token request URL and token response are no longer logged verbatim. Debug output now shows only `appID` and `expires_in`, preventing `client_secret` and `access_token` from appearing in `files/_log/gdmsintegration.log` even when verbose mode is enabled. (OWASP A02/A09)
- **Advisory lock queries use escaped identifiers** — `GET_LOCK`/`RELEASE_LOCK` raw SQL queries in the sync engine now call `$DB->escape()` on the lock name, following defense-in-depth for all raw query parameters. (OWASP A03)
- **Webhook signature mismatch returns 204** — failed HMAC verification no longer returns HTTP 403 (which confirmed the endpoint existed and the signature was checked). Now returns `204 No Content`, removing the oracle useful for probing or brute-forcing. (OWASP A07)
- **Webhook GET handler removed** — the unauthenticated `GET` health-check response that disclosed plugin name and endpoint path has been removed. Non-POST requests now receive `405` with no body. (OWASP A05)
- **Webhook payload logging scoped** — log line now records only `entity`, `mac`, and `status` rather than up to 500 chars of raw JSON payload. (OWASP A09)
- **Firmware endpoint requires READ right** — `firmware.ajax.php` read actions (`check`, `check_all`) now require `Session::checkRight('config', READ)` instead of a plain session check, preventing any authenticated GLPI user from querying firmware status of all devices. (OWASP A01)

---

## [1.3.6] — 2026-04-29

### Added
- **Ticket creation by device type** — new "Ticket creation by device type" config section with five independent toggles: IP Phones (GRP/GXP/GXV/WP), Routers (GWN7001/7002/7003), Switches (GWN7800/GSS), Access Points (GWN76xx), and IP PBX / UCM (UCM/GCC). All enabled by default for backward compatibility. Disabling a toggle suppresses offline incident tickets for that device category; ticket resolution remains unaffected so existing open tickets still auto-close. Translated in es_MX, fr_FR, de_DE.
- **GLPI asset name on tickets** — offline and WAN-down ticket subjects now use the GLPI asset name when the device is already registered in GLPI. Falls back to the GDMS cloud name for unregistered devices.
- **Private IP in offline tickets** — offline incident ticket body now includes the device's private/LAN IP alongside the public IP.
- **Dashboard UX improvements** — device name and Critical SLA banner links now open in a new browser tab; private IP cell is clickable and opens the device's admin page (`http://<private_ip>`) in a new tab; model, MAC, and serial cells copy their value to the clipboard on click (brief ✓ flash confirms the copy).

---

## [1.3.5] — 2026-04-23

### Added
- **Disable WAN port tickets** — new config toggle `wan_tickets_enabled` (enabled by default). When turned off, the plugin stops opening incident tickets for WAN link-down and no-internet events entirely. Debounce timers and port state tracking continue normally so the feature can be re-enabled at any time without side-effects. Resolved tickets are still closed automatically. Active regardless of sync method (cron or manual). Translated in es_MX, fr_FR, de_DE.

---

## [1.3.4] — 2026-04-22

### Fixed
- **False WAN tickets on all ports** — WAN port ticket loop iterated every port in `wan_ports_json` including LAN ports (role=0). LAN link-down events now correctly skipped via `role != 1` guard at the top of the loop.
- **Non-router devices treated as routers** — `$is_gwn_router` used `!$is_gwn_switch` as the only exclusion, so APs (GWN76xx) and any NetworkEquipment with a `networkId` could enter the router WAN port code path. Now uses explicit `preg_match('/^GWN700[123]/i', $gdms_model)` — only GWN7001/7002/7003 trigger WAN port monitoring.

### Added
- **Asset user as ticket requester** — when a GLPI asset has a user assigned (`users_id`), that user is set as requester on auto-generated offline and WAN-down tickets. Takes priority over the entity-level default requester configured in plugin settings.
- **WAN no-internet debounce** — new config option `wan_debounce_seconds` (default 300 s, range 0–3600). When `connectStatus` drops to 0 (internet lost) the plugin waits the configured number of seconds before opening a ticket. Prevents false alerts caused by transient high-latency events that momentarily fail the router's internet reachability test. Physical link-down events (Case A) are never debounced. Timer is stored inside `wan_ports_json` and survives across sync cycles. Setting to 0 restores the previous immediate-open behaviour. Translated in es_MX, fr_FR, de_DE.

---

## [1.3.3] — 2026-04-18

### Added
- **Ticket location** — auto-created offline and WAN tickets inherit `locations_id` from the linked GLPI asset when set.
- **Sortable dashboard table** — Device Name, Type, Model, Network, Status, Clients, Avail. %, and SLA headers are clickable (↑/↓). Click again to reverse direction.
- **Default table order** — network devices (routers, switches, APs) listed before phones; within each group sorted by name.
- **Sort persistence** — active sort column/direction stored in URL query string (`?sort=col&dir=asc`); survives reload and is shareable.
- **Reset sort button** — "Reset sort" button appears in Devices card header when a column sort is active; restores default order.
- **`slaLabel(float $uptime)`** — new static helper on `PluginGdmsintegrationSync`; dashboard now calls `calculateUptime()` only once per device instead of twice.

### Fixed
- Ticket body field labels ("Serial", "Network", "Last uptime", "Detected") were hardcoded in Spanish — now use `__()` and are translated in all locales.
- Empty-row fallback in device table had wrong `colspan="13"` — corrected to 15.

### Changed
- WAN ticket legacy-match code (pre-1.2.8 fallback) removed — only marker-based matching used.
- Ticket urgency now checks device model prefix — only GWN7001/7002/7003 routers get High(4); switches, APs, and phones get Medium(3) as intended.
- Locales updated: 8 new strings — "Last uptime", "Detected", "Reset sort", "DHCP", "Static" (→ localized), "PPPoE", "PPTP", "L2TP". 228 strings total.

### Performance
- Dashboard uptime calculation reduced from N queries (one per device) to 1 batch query via new `calculateUptimeBatch()` method.

### Fixed
- Ticket body "IP" row label was hardcoded — now uses `__('IP', 'gdmsintegration')`.
- WAN type labels in port modal ("DHCP", "Static", "PPPoE", "PPTP", "L2TP") were hardcoded JS strings — now injected from PHP via `__()`.
- "No history" devices (new, never synced) assigned `sla_rank=3` (Critical) — now get rank 4 (N/A) when device is online with zero history, keeping them out of the Critical tier in sort.
- `history_export.php` entity access check — validates `entities_id` against user's accessible entities, not just global config read right.
- `gdmsGetDevices()` now logs a warning when the 50-page / ~5000-device ceiling is hit so the operator knows the list was truncated.

---

## [1.3.2] — 2026-04-15

### Fixed
- **GLPI version constraint** — `plugin_version_gdmsintegration_check()` returned incompatible for GLPI 11.x installs that resolved to a minor version outside the previously declared range. Bumped version to 1.3.2 to clear the constraint conflict.

---

## [1.3.1] — 2026-04-12

### Added
**Backend**
- **ipv6** — IPv6 address (GWN APs, routers)
- **private_ip** — LAN/private IP (GDMS UC phones)
- **sip_extension** — SIP extension number extracted from device/detail lineInfo
- **location** — Physical location/site from GDMS or GWN API

**API extraction**
- **gwnGetDevices** extracts ipv6/ipv6Address and location/site from ap/list response
- **gwnGetAlerts** normalizes basicDataKey → category, detailMap.reason, detailMap.port_id, detailMap.deviceType

**WAN port summary** 9 new fields per port — wanType, gateway, gatewayStatus, firstDns, secondDns, wamMac, portIpv6, isCombo, txPackets/rxPackets

**Sync** passes ipv6, private_ip, sip_extension, location through to saveStateWithNetwork

**Dashboard**
- **IP cell** shows private IP as secondary line + IPv6 below
- **Uptime tooltip** shows Location if set
- **Status cell** shows SIP Extension badge alongside SIP Reg/Unreg
- **Port modal** gateway, DNS (first+second), WAN MAC, IPv6, packet counters
- **Alert table** added Device column, category/reason/port_id shown below description

## [1.3.0] — 2026-04-11

### Added
- **Cloud Alerts panel — dismiss button.** Each alert row in the Cloud Alerts panel now has an × button. Clicking it hides the row immediately (optimistic local dismiss). Alerts reappear on next page load because the GWN Cloud API does not expose a programmatic alert-deletion endpoint — this is expected behaviour and not a bug.
- **Switch LAN port status.** GWN78xx / GSS switches now fetch real-time port state via `/switch/portInfo` during sync and store it in `wan_ports_json`. Port dots and the detail modal on the NOC dashboard display link state, speed, port type (GE/SFP), custom label, description, VLAN, and per-port TX/RX bytes for every switch port. The live-API fallback in `ports.ajax.php` also routes to the switch endpoint when stored state is absent.
- **GWN firmware update badge from cloud data.** During sync, `/upgrade/version` is now called once per GWN network and the latest available firmware is stored in the new `firmware_latest` column. The update badge in the dashboard is shown immediately on page load for any GWN device where the stored firmware differs — no async scraper call needed.
- **WiFi client detail modal.** Clicking the Clients badge on any GWN AP row opens a modal with the real-time client list from `/client/list`: hostname, IP, MAC, band, SSID, RSSI (colour-coded), TX/RX rates.
- **Cloud Alerts panel.** A new "Cloud Alerts" card is shown on the dashboard for GWN-configured entities. Alerts are fetched from `/alert/list` across all managed networks 4 s after page load and displayed in a table (time, severity, device, description).
- **SIP registration status.** For GDMS UC phones, a batch call to `/device/detail` now runs during sync and stores the SIP registration state (`registered` / `unregistered`) in the new `sip_status` column. The Status column in the dashboard shows a small SIP badge for phone rows.

### Fixed
- **Cloud Alerts panel not rendering.** The `alertsBody.innerHTML` assignment was missing after the `forEach` loop that built the table rows. The alerts panel always showed the loading spinner and never displayed the alert table. Fixed by closing the table markup and assigning the result to the container.
- **CSRF error on alert dismiss.** The dismiss endpoint (`alerts-dismiss.ajax.php`) was called without a valid CSRF token, causing GLPI 11's `CheckCsrfListener` to reject every request and log an error. Since GWN Cloud provides no working dismiss API (the `/alert/dismiss` endpoint returns HTTP 404), the server-side call has been removed entirely. Dismiss is now local-only: the row is hidden in the DOM on click. The log noise is eliminated.
- **`$config_data` undefined in `syncDeviceList()`.** The router/switch port-info API calls inside the per-device loop referenced `$config_data` which was never assigned, causing the port-info fetch to silently skip on every sync. Fixed by loading config at the top of `syncDeviceList()` via `getConfigByEntity()`.
- **GDMS UC token fetched on every sync.** Added in-process cache (`$gdmsTokenCache`) mirroring the existing GWN pattern. Token reused for the full process lifetime until 30s before expiry; avoids a redundant OAuth round-trip per sync cycle.
- **Double signature calculation in `gwnGetRouterPortInfo`.** `gwnBuildSignature` was called twice with different timestamps; first result discarded. Removed the dead first call — timestamp and signature now computed once.
- **WAN ticket never opens when port is already down on first sync.** The ticket comparison block was guarded by `!empty($prev_ports_json)`, which completely skipped all ticket logic — including the "first-seen bad port" path — whenever no previous WAN state existed. Removed the outer guard; `$prev_map` is now an empty array when there is no prior state, so every port on first sync correctly falls into the `!$prev_wp` branch and opens a ticket if `link=0` or `connectStatus=0`.

## [1.2.7] — 2026-04-07

### Fixed
- **WAN ticket not created for already-down port.** When a WAN port was already in a failed state (`link=0` or `link=1`+`connectStatus=0`) the first time the plugin synced a device, no ticket was opened because the transition check required a previous state to compare against. Fixed: if no previous port state exists (first sync or newly added port) and the port is already in a bad state, a ticket is opened immediately without requiring a prior-good-state transition.
- **WAN ticket not auto-resolved after first-sync port recovery.** As a consequence of the above, ports that recovered after a first-sync down state had no ticket to resolve. Both are now handled consistently.
- **LAN port modal missing info for active ports.** Active LAN ports showed only "Status: Link up" with no additional data, while inactive ports showed at least speed info. Fixed: active LAN ports now show negotiated link speed (highlighted in green), custom port label (`portCustomName`), and port description (`portDesc`) when available. All LAN ports continue to show link speed, port type (GE/SFP) and status.

## [1.2.6] — 2026-04-04

### Fixed
- **`DBmysqlIterator` raw query error.** GLPI 11 prohibits calling `$DB->request()` with a raw SQL string (`Building and executing raw queries with the DBmysqlIterator::execute() method is prohibited`). The advisory lock calls for ticket idempotency used `$DB->request("SELECT GET_LOCK(...)")` and `$DB->request("SELECT RELEASE_LOCK(...)")`. Replaced with `$DB->doQuery()` + `$DB->fetchAssoc()` which is the correct GLPI 11 API for raw utility queries. Affected: `createOfflineTicket()` and `createWanDownTicket()`.

## [1.2.5] — 2026-04-03

### Added
- **WAN "link up / no internet" ticket.** Detects when a WAN port stays physically connected but loses internet connectivity (`linkStatus=1` + `connectStatus=0`). Opens a separate incident ticket with title suffix "No Internet" and urgency High. Duplicate guard and advisory lock apply equally.
- **WAN ticket auto-resolve.** When a WAN port recovers (link up + internet confirmed), any open WAN ticket for that port is automatically closed with a followup note — same behaviour as device offline/online auto-resolve.
- **Failover note in WAN tickets.** When a WAN port goes down, the ticket body includes "Failover → \<ISP name\>" if another WAN port on the same router has verified internet.
- **Per-port traffic in port modal.** Upload / download byte counters from the GWN `portInfo` aggregate are stored in `wan_ports_json` during sync and displayed in the WAN port cards.
- **Traffic ↑↓ column.** Shows WAN aggregate traffic per device (sum of all WAN port `txBytes`/`rxBytes`). Falls back to device-reported usage for non-router devices. Tooltip clarifies the data source and measurement period.
- **Clients column.** Shows the number of connected wireless clients per device.
- **Last sync timestamp.** Dashboard header shows "Last sync: X min ago" derived from a `last_sync_at` field written to the config table after every successful sync cycle.
- **Critical SLA banner.** A prominent warning banner appears above the device table whenever any device reaches Critical SLA tier and is currently offline, listing each affected device with its uptime percentage.
- **`PluginGdmsintegrationUtils::ensureSchema()`** — single authoritative source for all upgrade-safe `ALTER TABLE … ADD COLUMN IF NOT EXISTS` statements, called from both `setup.php` and `dashboard.php`. No more duplicate ALTER lists.
- **Locales up to date.** `.pot` regenerated from source (172 strings). All `.po` files merged and recompiled. New strings fully translated in `es_MX`; `fr_FR` and `de_DE` retain existing coverage.

### Fixed
- **`$DB` undefined in `syncEntity()`.** `global $DB;` was missing, causing `Call to a member function update() on null` on every cron run and "Sync now" click. Fixed.
- **Clients column invisible in dark theme.** Badge changed from `bg-secondary` to `text-bg-info`.
- **Traffic column showed wrong values.** `upload`/`download` from `ap/list` is wireless client traffic, not WAN throughput. Column now uses WAN port aggregate (`txBytes`/`rxBytes`) which correctly shows hundreds of GB to TB for routers.
- **README sync lifecycle table** — status labels corrected to English ("Online" / "Offline").

### Changed
- **WAN ticket titles** follow the format `[GDMS] <Device> — WAN <Port> (<ISP>): Link Down` or `: No Internet`.
- **Sync log summary** now reports: `Sync summary — entity=0 total=7 removed=1`.
- **State transition log** notes when a device persists offline: `prev=offline → new=offline — no ticket (persists offline)`.
- `markRemovedDevicesOffline()` returns the count of purged devices for use in the log summary.


## [1.2.4] — 2026-04-03

### Fixed
- **Any device not present in the cloud is permanently purged from the plugin on the next sync.** On every sync cycle, any MAC or serial that is no longer returned by the GDMS/GWN API — regardless of when it was added or which plugin version was running when it was created — is deleted from `glpi_plugin_gdmsintegration_devices` and `glpi_plugin_gdmsintegration_history`. This includes devices that were left as "offline" ghosts by previous versions. The device disappears immediately from the dashboard, the uptime chart, and all SLA/availability calculations. The corresponding GLPI asset (NetworkEquipment / Phone) is never touched. If the device is re-added to the cloud in the future, the next sync inserts it fresh and re-links it to the existing GLPI asset via serial / MAC match.
- **No ticket on cloud removal or network change.** Removing a device from the cloud account, moving it between networks, or any other administrative cloud action no longer triggers an incident ticket. Tickets are only created on genuine device reachability transitions (`online → offline`) or WAN port link-down events.
- **Removed `alreadyOffline` hack.** Devices that stay offline across successive syncs no longer re-attempt ticket creation. The existing open-ticket guard is now the sole protection against repeat tickets for persistently offline devices.
- **Idempotent ticket creation (race condition fix).** Two users refreshing the dashboard simultaneously could cause two concurrent syncs to both pass the duplicate-ticket guard and open two tickets for the same offline device. This is now prevented with a per-asset MySQL advisory lock (`GET_LOCK` / `RELEASE_LOCK`) wrapping the guard check and ticket creation atomically. Only the first process acquires the lock; the second detects contention and skips.
- **Ticket entity matches device entity.** Incident tickets (offline and WAN-down) are now opened under the `entities_id` of the GLPI asset, not the sync configuration entity. This ensures the ticket appears in the correct location/branch tree.

## [1.2.3] - 2026-03-29

### Fixed

- **Removed devices stay visible on dashboard** — `syncEntity()` now collects all MAC addresses returned by the API in a given cycle (`$seen_macs`). After both GWN and GDMS batches complete, `markRemovedDevicesOffline()` queries the plugin DB for any device whose MAC was not present in the cycle, marks it `offline`, writes a history entry, and opens an incident ticket if it was previously `online`. This handles device deletion from GWN Cloud / GDMS without any manual intervention in GLPI.

- **TIMESTAMP DST crash on cron** — all `date('Y-m-d H:i:s')` calls in `sync.class.php`, `dashboard.php`, and `history_export.php` replaced with `gmdate()`. On hosting environments with `Europe/London` timezone (and any DST-observing timezone), the MySQL 1299 *Invalid TIMESTAMP value* warning fired every cron run on the DST transition day when local times 01:00–01:59 don't exist. Using UTC avoids the gap entirely.

### Changed

- **ECharts 5 replaces Chart.js** — the availability history chart now uses GLPI's bundled ECharts 5 library (`lib/echarts.js`) instead of the previously self-hosted Chart.js 4.5.1. Automatically adapts to GLPI's dark/light theme via `data-bs-theme`. Removed `front/chartjs.php`, `js/chart.umd.min.js` and the corresponding stateless route.

- **GLPI's native Flatpickr replaces bundled copy** — the firmware schedule datetime picker now uses GLPI's own Flatpickr 4.6 instance (loaded via `Html::requireJs('flatpickr')`). Removed `front/flatpickr.php`, `front/flatpickrcss.php`, `js/flatpickr.min.js`, `css/flatpickr.min.css` and their stateless routes. The `css/` directory has been removed entirely.

- **Cron frequency reduced to 10 minutes** — the `syncDevices` automatic action is now registered and force-updated to run every 10 minutes on install/upgrade (previously 30 minutes).

---

## [1.2.2] - 2026-03-28

### Fixed

- **Firmware modal title size** — the modal header was rendered at a smaller size than other modals due to a spurious `fs-6` Bootstrap class. Removed; title now uses the standard `h5` size consistent with the rest of the UI.

- **`taskType` corrected for GDMS firmware upgrade** — `task/add` was being sent with `taskType: 2` (Factory Reset) instead of `taskType: 1` (Upgrade), causing GDMS to reject the request with `reset task not support ucm`. All GDMS upgrade tasks now use `taskType: 1`.

- **Button text colors in firmware modal** — `btn-success` now carries an explicit `text-white` class and `btn-warning` carries `text-dark` to prevent dark-theme overrides from making text invisible. The beta version code color was also changed from a hardcoded hex to a CSS variable (`--bs-warning-text`) so it adapts to the active GLPI theme.

- **Action buttons stacked vertically** — the three action buttons in the firmware modal footer (Apply now, Schedule, Close) are now arranged vertically with full width (`w-100`) so that translated labels are never truncated.

- **Datetime picker replaced with Flatpickr** — the native `datetime-local` input (which renders differently in every browser and has no time picker on some platforms) is replaced with Flatpickr 4.6.13 served locally from `js/flatpickr.min.js` and `css/flatpickr.min.css` (same stateless-route pattern as Chart.js and vis-network). The picker shows a calendar + 24 h clock, enforces a minimum date of now + 5 minutes, and adapts its color scheme to the active GLPI theme via CSS variable overrides.

### Changed

- **GDMS firmware upgrade modal — version display** — for GDMS-managed devices (UCM / GCC / GRP / WP / HT), the beta firmware version is now shown as informational text only rather than a selectable radio button. A blue alert note explains that GDMS applies the latest firmware available in its own repository regardless of the version passed in the API call. GWN devices retain the full radio-button selector for both official and beta versions.

- **Locale strings** — added `GDMS managed` and the repository note string to all five locales (es_MX, fr_FR, de_DE, en_US, en_GB).

---

## [1.2.1] - 2026-03-28 - NOT RELEASED

### Added

- **Firmware updates for all device types** — the firmware check and upgrade flow now covers every Grandstream device family in the dashboard, not only GWN routers. This applies uniformly to:
  - **GWN routers, switches, and APs** — version data comes from the GWN Cloud API (`/upgrade/version`), same as before.
  - **UCM / GCC PBX appliances** — version data scraped from `grandstream.com/support/firmware` (UCM6300/UCM62xx/UCM61xx/UCM6510/GCC601x/GCC602x pages).
  - **GRP IP phones** — version data scraped from the GRP260x firmware page.
  - **GXV video phones** — version data scraped from the GXV34xx firmware page.
  - **WP Wi-Fi phones** — version data scraped from the WP8x6 firmware page.
  - **HT ATAs** — version data scraped from the HT8xxV2 firmware page.

- **Official + Beta firmware options in upgrade modal** — the firmware upgrade modal now shows both the official and beta versions when available for the device's model family. The user selects which version to install via a radio button before clicking Apply or Schedule. The `Official firmware` badge (green) marks the stable release; the `Beta firmware` badge (yellow) marks the pre-release.

- **Upgrade applies to all device families (ASAP or scheduled)** — the Apply now (ASAP) and Schedule update buttons work identically for all device types:
  - GWN devices call the existing GWN Cloud `/upgrade/add` endpoint.
  - All other devices (UCM/GCC/GRP/GXP/WP/HT) create a GDMS UC `task/add` task with `taskName=UPGRADE` and the selected firmware version. The GDMS task supports both immediate and scheduled execution via the `scheduleTime` field (milliseconds epoch).

- **`firmware.ajax.php?action=check_all`** — new AJAX action that fetches firmware versions for all tracked devices in one call. GWN devices use the GWN Cloud API; UC/phone devices use scraping with a per-slug cache to avoid duplicate HTTP requests for the same model family.

- **`firmware.ajax.php?action=upgrade_gdms`** — new AJAX action that creates a GDMS upgrade task for UC and phone devices. Accepts `mac` (colon or plain format), `version`, and optional `scheduleMs`.

- **`PluginGdmsintegrationAPI::gdmsCreateUpgradeTask()`** — new method in `api.class.php`. Uses a different HMAC signature scheme from `device/list`: `SHA256(&access_token=…&client_id=…&client_secret=…&timestamp=…&SHA256(body)&)`.

- **`PluginGdmsintegrationAPI::scrapeFirmwareVersions()`** — new method that fetches the official and beta firmware pages from `grandstream.com/support/firmware/{slug}-official-firmware` and `{slug}-beta-firmware` and extracts the version string via regex. Uses a private `curlGet()` helper with TLS verification.

### Changed

- `firmware.ajax.php?action=check` — original GWN-only stable check kept unchanged for backwards compatibility.
- Dashboard firmware fetch on page load switched from `action=check` to `action=check_all` to populate firmware data for all device types.
- Firmware `⬆` badge now appears on **all device types** with firmware data (previously excluded `type=Phone`). Badge element gains a `data-model` attribute used by JS to determine which upgrade path to call.

---

## [1.2.0] - 2026-03-28

### Added

- **Network traffic per device** — `upload_bytes`, `download_bytes`, and `usage_bytes` columns added to the device state table and synced from the GWN `ap/list` API (`upload`, `download`, `usage` fields). Values are displayed in multiple places (see below).
- **WiFi channel per device** — `channel_2g` and `channel_5g` columns added and synced. Displayed in the uptime tooltip.
- **First seen / Last seen timestamps** — `first_seen` and `last_seen` columns added and synced from GWN `firstSeen`/`lastSeen` epoch ms fields. Displayed in the uptime tooltip and in the port modal.
- **Management IP** — `mgmt_ip` column added (device LAN management address, separate from public IP). Available in the DB for future use.

- **Uptime cell tooltip** — hovering over the uptime value in the device table now shows a multi-line tooltip with:
  - **Network usage** — `↑ Upload / ↓ Download` in auto-scaled KB/MB/GB (only shown when traffic data is available).
  - **WiFi channels** — `2.4 GHz chN` and/or `5 GHz chN` (only shown when the device reports active channels).
  - **First seen** — date and time the device first appeared in the cloud.
  - **Last seen** — most recent timestamp reported by the cloud API.

- **Network name click → network modal** — the network name in the device table is now a clickable link. Clicking it opens a Bootstrap modal showing:
  - A row for each device category (Router / Switch / AP / Phones & PBX) that has at least one device in that network, with an online/offline badge pair and a colour-coded availability progress bar (green ≥ 80 %, yellow ≥ 50 %, red < 50 %).
  - **Clients** badge — total connected wireless clients for the network.
  - **Network traffic** row — aggregate `↑ Upload` and `↓ Download` for all devices in that network combined, shown only when traffic data exists.
  - Empty categories (zero devices of that type) are filtered out automatically.

- **Port modal traffic and timestamp block** — clicking any port dot now opens a detail modal that shows, **above the port legend**:
  - `↑ Upload` and `↓ Download` in auto-scaled KB/MB/GB (shown only when traffic data exists).
  - **First seen** and **Last seen** timestamps for the device.
  - If none of those fields have data, the block is omitted entirely and the modal starts directly with the port legend.

- **Phones & PBX card now counts PBX appliances** — UCM/GCC devices registered as `NetworkEquipment` in GLPI were never counted in the Phones & PBX summary card. Fixed by accumulating `phone_on`/`phone_off` from `$net_stats` (which correctly classifies UCM/GCC by model prefix) rather than iterating `$rows` and filtering by `type === 'Phone'`.

- **Phones & PBX row in network modal** — the network detail modal now includes a Phones & PBX row, using the correct per-network `phone_on`/`phone_off` counts from the sync data.

- **Six summary stat cards** — the dashboard header row now shows six category cards in the Grandstream Cloud style:
  - **Networks** (total count)
  - **Router** (online / offline)
  - **Switch** (online / offline)
  - **AP** (online / offline)
  - **Phones & PBX** (online / offline) — includes both GRP/GXP/WP phones and UCM/GCC PBX appliances
  - **Clients** (connected wireless clients)

- **Chart.js and vis-network bundled locally** — both JavaScript libraries are now served from the plugin's own `js/` folder via PHP stateless routes (`front/chartjs.php`, `front/visnetwork.php`), registered in `setup.php`. No external CDN requests are made by the dashboard. This resolves loading failures in environments that block external connections.

- **Self-healing column check on dashboard load** — `dashboard.php` runs `ALTER TABLE … ADD COLUMN IF NOT EXISTS` for all v1.2.0 columns on every page load. This ensures the new columns exist even on FTP-only deployments that do not run the GLPI plugin update flow.

### Changed

- `saveStateWithNetwork()` signature extended with 8 new optional parameters (all defaulting to `null`/`0` — no existing callers are broken).
- DB: 8 new `ALTER TABLE … ADD COLUMN IF NOT EXISTS` for `usage_bytes`, `upload_bytes`, `download_bytes`, `channel_2g`, `channel_5g`, `first_seen`, `last_seen`, `mgmt_ip`. Runs in `plugin_gdmsintegration_install()` and also on every dashboard load as a self-heal.

### Fixed

- **All JavaScript translated strings now use `json_encode`** — all PHP `__()` calls embedded inside JS string literals were replaced with a single `var STR = { … }` object at the top of the IIFE, where every value is output via `json_encode()`. This prevents translations containing apostrophes (e.g. French `L'équipement`) from silently breaking the entire JS block.
- **`fmtBytes` function restored** — the byte formatter function was accidentally removed during a refactoring pass. Its absence caused a `ReferenceError` whenever the port modal or network modal tried to display traffic values, silently preventing those modals from opening for devices that had traffic data.
- **Modal HTML no longer uses template literals** — `portModal.innerHTML = \`...\`` and `netModal.innerHTML = \`...\`` were rewritten as string concatenation. Template literals containing `<?= __() ?>` PHP injections could break the modal if any translation contained a backtick character.
- **`'use strict'` is now the first statement** in the IIFE — previously a variable declaration appeared before the directive, causing a `SyntaxError` in strict mode in some environments.
- **Port modal opened only for devices without traffic** — consequence of the missing `fmtBytes` (see above). Modals now open correctly for all devices regardless of whether traffic data is present.
- **Network modal opened only for devices without traffic** — same root cause; fixed alongside port modal.

---

## [1.1.0] - 2026-03-27

### Added
- **Device name fallback** — name resolution now uses GLPI asset name first, cloud device name second (stored in new `cloud_name` DB column), and MAC address as last resort.
- **Tech assignment on tickets** — when a GLPI asset has a technician assigned (`users_id_tech`), automatically created incident tickets are now assigned to that user and set to status "Assigned".
- **Configurable ticket requester** — new config option to select which GLPI user is set as requester on auto-generated tickets (defaults to system/cron user).
- **Configurable chart days** — availability histogram range is now configurable (7–365 days, default 60). Includes a note that values above 90 may impact performance.
- **Topology toggle** — network topology card can be hidden from the dashboard via config. When disabled, all topology data processing and vis-network loading are skipped entirely.
- **Network name clickable** — network name in the device table was converted from a plain tooltip to a clickable link opening a Bootstrap modal. (Traffic, Phones & PBX row, and per-network stats extended further in v1.2.0.)
- **Firmware: Apply now (ASAP)** — firmware modal now offers two options: "Apply now (ASAP)" (sends upgrade with no scheduled time) and "Schedule update" with a datetime picker. The scheduled time is passed to the GWN `upgrade/add` API as milliseconds epoch.
- **Clients count** — new `clients` column in devices table; sync stores the connected-clients count from the cloud API, used for network modal stats.
- **GWN token cache** — GWN access token is now cached in-process for its full lifetime (~3600 s). A full sync cycle no longer makes 6–8 redundant token requests; instead it makes one and reuses it, saving 5–8 seconds of wall time.

### Changed
- **Excel export** — removed the "Raw Data" sheet (high volume, no operational value). Export now contains two sheets: availability pivot (% online per day per device) and device summary with SLA tiers. Export respects the `chart_days` config setting.
- **Icon library migration** — all FontAwesome icons replaced with Tabler Icons (`ti ti-*`), eliminating the external FontAwesome CDN dependency. Affected: `dashboard.php`, `config.form.php`, `menu.class.php` (53 replacements).
- **Port modal title** — renamed from "WAN Port Status" to "Port Status" since the modal now shows all port types (WAN and LAN).
- **Dashboard & Tickets config card** — added `ti-dashboard` icon and consistent `h5` heading to match all other configuration cards.

### Fixed
- History data range (dashboard + export) now reads from `chart_days` config instead of hardcoded 60 days.

---

## [1.0.3] - 2026-03-27

### Fixed
- **WAN port internet status false positive** — `connectStatus=1` from the GWN API means internet is confirmed. The colour mapping was inverted: all online WAN ports were shown as orange (WAN up, no internet) even when fully connected. Fixed mapping: `connectStatus=1` → green (Online), `connectStatus=0` with link up → orange (No internet).

---

## [1.0.2] - 2026-03-27

### Added
- **Port monitoring column** — new *Ports* column in the NOC dashboard table. Shows one colour-coded dot per physical port for every online GWN router (WAN ports first, then LAN). Dots are clickable and open a detail modal.
- **Port detail modal** — clicking any dot in the Ports column opens a modal showing all ports for that device (WAN and LAN) with: silk-screen label, port name, WAN connection name, connection status, IP address, WAN type (DHCP/Static/PPPoE), link speed, time connected, port type (GE/SFP). A colour legend appears above the port cards.
- **Port-down incident ticket** — when a sync detects any port transitioning from link-up to link-down, a `[GDMS-WAN:portSilk]` incident ticket (urgency High) is created and linked to the asset. Duplicate guard prevents repeat tickets for the same port across syncs.
- **`front/ports.ajax.php`** — new AJAX endpoint returning full port state for all tracked online GWN routers. Uses stored `wan_ports_json` when available; falls back to live API on first load.
- **`wan_ports_json` column** — stores full port state snapshot after each sync for WAN transition detection.
- **Verbose port debug logging** — when debug mode is active, raw `portInfo[]` and `ipv4Info[]` are logged to aid in diagnosing port data issues.

### Security
- **SSL verification in batch cURL** — `CURLOPT_SSL_VERIFYPEER` was `false` in `gwnGetDeviceInfoBatch()`; set to `true` with `CURLOPT_SSL_VERIFYHOST => 2`.
- **Access control on AJAX endpoints** — `sync.ajax.php`, `firmware.ajax.php`, and `history_export.php` now require proper rights (`config UPDATE` / `config READ`) instead of `checkLoginUser()` alone.
- **XSS in firmware modal** — replaced `innerHTML` template literal interpolation with safe DOM construction.

### Fixed
- **CSRF on firmware upgrade** — switched to `FormData` + `window.glpiGetNewCSRFToken()` to obtain a fresh single-use token just before the POST.
- **Firmware upgrade icon not appearing** — `firmware.ajax.php` was registered as a stateless route, preventing `Session::checkLoginUser()` from passing. Removed from stateless registration.
- **Ticket creation on offline transition** — `prevStatus` was read after `saveStateWithNetwork()`, so the previous state was already overwritten; moved before the save.
- **Firmware upgrade MAC format** — `upgrade/add` API requires MACs without colons; colons are now stripped before the POST body is assembled.
- **`htmlspecialchars()` before DB write** — device names were HTML-encoded before storage, causing `&amp;` / `&lt;` to appear in asset names and ticket subjects; encoding moved to output time only.
- **Inconsistent SLA tiers** — Excel export and dashboard now use the same thresholds: Gold ≥ 99.9 % / Silver ≥ 99 % / Bronze ≥ 95 % / Critical < 95 %.
- **Port legend items merged** — JS operator precedence bug caused all legend labels to concatenate without separators; fixed by building each item as a self-contained `<span>`.
- **ipv4Info WAN name matching** — broadened matching between `portInfo` and `ipv4Info` entries; now tries `silkScreenPort`, `portId`, and `wanPortId` with case-insensitive comparison.

### Changed
- **Port sync** — all ports (WAN + LAN) stored in `wan_ports_json` on every sync; only WAN-role ports trigger incident tickets on link-down.
- **Legend strings** — all port modal legend labels now go through PHP `__()` and are fully translatable.
- **Deduplicated GWN signature logic** — `gwnGetFirmwareVersions()` and `gwnScheduleUpgrade()` now call `gwnBuildSignature()` helper instead of duplicating HMAC construction.

---

## [1.0.1] - 2026-03-25

### Added

#### GWN Cloud (GDMS Networking) integration
- Independent API client for Grandstream networking devices — GWN APs, switches, and routers.
- Separate OAuth2 token flow: `GET /oauth/token?grant_type=client_credentials`.
- Separate HMAC-SHA256 signature scheme using `appID` / `secretKey`.
- GWN `network/list` endpoint queried first to build `networkId → networkName` map; each network is then paginated via `ap/list`.
- `network_id` stored in the plugin device state table and passed through sync for use in firmware checks.

#### Firmware update check & scheduling (GDMS Networking only)
- `front/firmware.ajax.php` with two actions:
  - `check` — calls `POST /oapi/v1.0.0/upgrade/version {networkId}` for every tracked network; flags only **stable releases** (no `beta`, `rc`, `dev`, `alpha`).
  - `upgrade` — calls `POST /oapi/v1.0.0/upgrade/add {macs:[...]}` to schedule an official firmware update via GWN Cloud.
- Dashboard firmware column: shows amber `⬆` icon next to the current version when a stable update is available.
- Bootstrap modal on icon click: current vs. latest firmware, `Official` badge, reboot warning, and **Schedule update** button.
- Firmware check fires 2 seconds after page load in background — does not block the dashboard.

#### SN enrichment — parallel curl_multi
- All `device/info` requests for a network batch fire simultaneously via `curl_multi`, replacing sequential calls and cutting GWN sync time significantly.

#### NOC Dashboard
- Summary cards with total, online, offline counts and availability % progress bar.
- Device table with name, type, network, IP (WHOIS link), MAC, serial, firmware + upgrade icon, uptime, status badge, availability %, SLA tier.
- Per-device history chart — Chart.js line chart, last 60 days.
- Network topology — vis-network interactive graph.
- Auto-refresh with countdown timer and manual sync button.

#### Excel export (PhpSpreadsheet)
- Three sheets: % Online 60 days, Raw Data, Summary (per-device availability %, SLA tier).

#### Incident ticketing
- Duplicate guard, urgency routing, rich ticket body, asset element, auto-resolve on recovery.

#### Two-tier logging
- `log()` — always written. `debug()` — verbose, active when GLPI debug mode or plugin debug toggle is on.

#### Localization
- All strings translated to es_MX, fr_FR, de_DE (en_US / en_GB use msgid as base).

### Fixed
- GWN `device/info` SN parsed correctly as `{type, value, key}` objects.
- Webhook `ClassNotFoundError` — corrected exception namespace.
- Cron `MODE_EXTERNAL` registered for CLI execution.

---

## [1.0.0] - 2026-03-24

### Added
- Initial release — GDMS Cloud sync, incident tickets, NOC dashboard, webhook, per-entity config, GLPIKey encryption, 60-day history, vis-network topology, es_MX / fr_FR / de_DE locales, `plugin.xml`.
