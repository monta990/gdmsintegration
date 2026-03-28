# Changelog — GDMS Integration

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.1.0] - 2026-03-27

### Added
- **Device name fallback** — name resolution now uses GLPI asset name first, cloud device name second (stored in new `cloud_name` DB column), and MAC address as last resort.
- **Tech assignment on tickets** — when a GLPI asset has a technician assigned (`users_id_tech`), automatically created incident tickets are now assigned to that user and set to status "Assigned".
- **Configurable ticket requester** — new config option to select which GLPI user is set as requester on auto-generated tickets (defaults to system/cron user).
- **Configurable chart days** — availability histogram range is now configurable (7–365 days, default 60). Includes a note that values above 90 may impact performance.
- **Topology toggle** — network topology card can be hidden from the dashboard via config. When disabled, all topology data processing and vis-network loading are skipped entirely.
- **Network tooltip** — hovering over a network name in the device table shows a breakdown of Router / Switch / AP online/offline counts and connected clients.
- **Firmware: Apply now (ASAP)** — firmware modal now offers two options: "Apply now (ASAP)" (sends upgrade with no scheduled time) and "Schedule update" with a datetime picker. The scheduled time is passed to the GWN `upgrade/add` API as milliseconds epoch.
- **Clients count** — new `clients` column in devices table; sync stores the connected-clients count from the cloud API, used for network tooltip stats.
- **GWN token cache** — GWN access token is now cached in-process for its full lifetime (~3600s). A full sync cycle no longer makes 6-8 redundant token requests (one per network/call); instead it makes one and reuses it. This reduces sync wall time by 5-8 seconds.

### Changed
- **Excel export** — removed the "Raw Data" sheet (rows × history records, high volume, no operational value). Export now contains two sheets: availability pivot (% online per day per device) and device summary with SLA tiers.
- Excel export respects the `chart_days` config setting.
- Availability chart heading now shows the actual configured number of days.

### Changed (continued)
- **Icon library migration** — all FontAwesome icons replaced with Tabler Icons (`ti ti-*`) which is the icon library bundled with GLPI 11. Eliminates the external FontAwesome CDN dependency. Affected files: `front/dashboard.php`, `front/config.form.php`, `inc/menu.class.php` (53 icon class replacements).
- **Port modal title** — renamed from "WAN Port Status" to "Port Status" since the modal now shows all port types (WAN and LAN), not just WAN.
- **Network name tooltip → modal** — replaced the plain text tooltip on network names in the device table with a clickable link that opens a Bootstrap modal showing Router / Switch / AP online/offline counts with progress bars and a clients badge.
- **Dashboard & Tickets config card** — added `ti-dashboard` icon and consistent `h5` heading to match all other configuration cards.

### Fixed
- History data range (dashboard + export) now reads from `chart_days` config instead of hardcoded 60 days.

## [1.0.3] - 2026-03-27

### Fixed
- **WAN port internet status false positive** — `connectStatus=1` from the GWN API means internet is confirmed (not "no internet"). The colour mapping was inverted: all online WAN ports were shown as orange (WAN up, no internet) even when fully connected. Fixed mapping: `connectStatus=1` → green (Online), `connectStatus=0` with link up → orange (No internet).

## [1.0.2] — 2026-03-27

### Added

- **Port monitoring column** — new *Ports* column in the NOC dashboard table. Shows one colour-coded dot per physical port for every online GWN router (WAN ports first, then LAN). Dots are clickable and open a detail modal.
- **Port detail modal** — shows all ports (WAN and LAN) with: silk-screen label, port name, WAN connection name, connection status, IP address, WAN type (DHCP/Static/PPPoE), link speed, time connected, port type (GE/SFP). Colour legend at the top of the modal:
  - 🟢 Green — WAN: link up, internet confirmed
  - 🟠 Orange — WAN: link up, no internet
  - 🟡 Amber — WAN: link up, status unknown
  - 🔵 Teal — LAN: link up
  - ⚫ Gray — link down (any port type)
- **Port-down incident ticket** — when a sync detects any port transitioning from link-up to link-down, a `[GDMS-WAN:portSilk]` incident ticket (urgency High) is created and linked to the asset. WAN ports are prioritised but LAN port link-down transitions also generate tickets. Duplicate guard prevents repeat tickets for the same port across syncs.
- **`front/ports.ajax.php`** — new AJAX endpoint returning full port state (role, link status, speed, type, WAN name, IP, connect status, connect duration) for all tracked online GWN routers. Uses stored `wan_ports_json` when available; falls back to live API on first load.
- **`wan_ports_json` column** in `glpi_plugin_gdmsintegration_devices` — stores full port state snapshot after each sync for WAN transition detection. Upgrade path: `ALTER TABLE ... ADD COLUMN IF NOT EXISTS wan_ports_json text NOT NULL DEFAULT ''`.
- **Verbose port debug logging** — when debug mode is active, raw `portInfo[]` and `ipv4Info[]` from `device/info` are logged to `gdmsintegration.log` to aid in diagnosing speed / WAN name / IP matching issues.

### Security

- **SSL verification in batch cURL** — `CURLOPT_SSL_VERIFYPEER` was `false` in `gwnGetDeviceInfoBatch()`; set to `true` with `CURLOPT_SSL_VERIFYHOST => 2`. All GWN API calls now fully verify TLS certificates.
- **Access control on AJAX endpoints** — `sync.ajax.php`, `firmware.ajax.php`, and `history_export.php` now require proper rights (`config UPDATE` / `config READ`) instead of `checkLoginUser()` alone.
- **XSS in firmware modal** — replaced `innerHTML` template literal interpolation with safe DOM construction (`createElement` / `textContent`).

### Fixed

- **CSRF on firmware upgrade** — switched to `FormData` + `window.glpiGetNewCSRFToken()` to obtain a fresh single-use token just before the POST. Avoids "already consumed" rejections caused by the page-load token being used by a prior request. `firmware.ajax.php` is no longer registered as a stateless path; CSRF is satisfied correctly via `$_POST`.
- **Firmware upgrade icon not appearing** — `firmware.ajax.php` was registered as a stateless route, preventing `Session::checkLoginUser()` from passing and causing the `check` action to return nothing. Removed from stateless registration; `check` now works normally with session.
- **Ticket creation on offline transition** — `prevStatus` was read *after* `saveStateWithNetwork()`, so the previous state was already overwritten; moved to *before* the save so `online → offline` transitions are correctly detected.
- **Firmware upgrade MAC format** — `upgrade/add` API requires MACs without colons; colons are stripped before the POST body is assembled.
- **`htmlspecialchars()` before DB write** — device names were HTML-encoded before storage, causing `&amp;` / `&lt;` to appear in asset names and ticket subjects; encoding moved to output time only.
- **Inconsistent SLA tiers** — Excel export used `Platinum/Gold/Silver/Critical` with different thresholds from the dashboard. Both now use `Gold ≥ 99.9 % / Silver ≥ 99 % / Bronze ≥ 95 % / Critical < 95 %`.
- **Port legend items merged** — JS operator precedence bug (`string + var === 'value'`) caused all legend labels to concatenate without separators; fixed by building each item as a self-contained `<span>` element.
- **ipv4Info WAN name matching** — broadened matching between `portInfo` and `ipv4Info` entries; now tries `silkScreenPort`, `portId`, and `wanPortId` with case-insensitive comparison.

### Changed

- **Dashboard title** — `'GDMS — ' . __('Dashboard', 'gdmsintegration')`; translatable.
- **Port sync** — all ports (WAN + LAN) stored in `wan_ports_json` on every sync; only WAN-role ports trigger incident tickets on link-down.
- **Legend strings** — all port modal legend labels now go through PHP `__()` before being passed to JS; fully translatable.
- **Deduplicated GWN signature logic** — `gwnGetFirmwareVersions()` and `gwnScheduleUpgrade()` now call `gwnBuildSignature()` helper instead of duplicating HMAC construction.

---

## [1.0.1] — 2026-03-25

### Added

#### GWN Cloud (GDMS Networking) integration
- Independent API client for Grandstream networking devices — GWN APs, switches, and routers.
- Separate OAuth2 token flow: `GET /oauth/token?grant_type=client_credentials`.
- Separate HMAC-SHA256 signature scheme using `appID` / `secretKey`.
- GWN `network/list` endpoint queried first to build `networkId → networkName` map; each network is then paginated via `ap/list`.
- `network_id` (int) stored in the plugin device state table and passed through sync for use in firmware checks.

#### Firmware update check & scheduling (GDMS Networking only)
- New `front/firmware.ajax.php` endpoint with two actions:
  - `check` — calls `POST /oapi/v1.0.0/upgrade/version {networkId}` for every tracked network, returns `{mac, currentVersion, latestVersion, hasUpdate}` filtered to **stable releases only** (no `beta`, `rc`, `dev`, `alpha`).
  - `upgrade` — calls `POST /oapi/v1.0.0/upgrade/add {macs:[...]}` to schedule an official firmware update via GWN Cloud.
- Dashboard firmware column: shows `⬆️` amber icon next to the current version when a stable update is available.
- Bootstrap modal on icon click: displays current vs. latest firmware, `Official` badge, maintenance reboot warning, and **Schedule update** button.
- Firmware check fires 2 seconds after page load in background — does not block the dashboard or the sync cycle.

#### SN enrichment — parallel curl_multi
- All `device/info` requests for a network batch fire simultaneously via `curl_multi`, replacing sequential calls and cutting GWN sync time significantly.
- `gwnGetDeviceInfoBatch()` private method added to `api.class.php`.
- Token pre-fetched once at the top of `gwnGetDevices()` and reused for all page requests and batch info calls.

#### SN caching
- Once `sn_cloud` is stored in the plugin device state table, `device/info` is skipped on subsequent syncs.

#### NOC Dashboard
- **Summary cards** with total, online, offline counts and availability % progress bar.
- **Device table** — device name (link to GLPI asset), type badge, network/site, public IP (WHOIS link), MAC, serial, firmware + upgrade icon, uptime (d h m), status badge, availability %, SLA tier.
- **Per-device history chart** — Chart.js line chart, one line per device with colour legend, last 60 days.
- **Network topology** — vis-network interactive graph.
- **Auto-refresh** — configurable interval (default 300 s) with countdown timer.
- **Manual sync button** — dispatches background CLI cron task immediately.

#### Excel export (PhpSpreadsheet)
- Three sheets: **% Online 60 days** (pivot with conditional colour fill), **Raw Data**, **Summary** (per-device availability %, SLA tier).

#### Incident ticketing improvements
- **Duplicate guard**, **urgency routing**, **rich ticket body**, **asset element**, **auto-resolve** on recovery.
- Public wrappers `triggerOfflineTicket()` / `triggerResolveTicket()` for webhook use.

#### Two-tier logging
- `log()` — always written. `debug()` — verbose, active when GLPI debug mode or plugin debug toggle is on.

#### Localization
- All strings translated to es_MX, fr_FR, de_DE (en_US / en_GB use msgid as base).

### Fixed
- **GWN `device/info` SN** — `result[]` parsed correctly as `{type, value, key}` objects.
- **Webhook `ClassNotFoundError`** — corrected exception namespace to `Symfony\Component\HttpKernel\Exception\*`.
- **Duplicate GWN token requests** — token fetched once per sync cycle.
- **Cron `MODE_EXTERNAL`** — registered for CLI execution.

---

## [1.0.0] — 2026-03-24

### Added
- Initial release — GDMS Cloud sync, incident tickets, NOC dashboard, webhook, per-entity config, GLPIKey encryption, 60-day history, vis-network topology, es_MX / fr_FR / de_DE locales, `plugin.xml`.
