# Changelog — GDMS Integration

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.1] — 2026-03-25

### Added

#### GWN Cloud (gwn.cloud) integration
- Independent API client for Grandstream networking devices — GWN APs, switches, and routers.
- Separate OAuth2 token flow: `GET /oauth/token?grant_type=client_credentials`.
- Separate HMAC-SHA256 signature scheme using `appID` / `secretKey`.
- GWN `network/list` endpoint queried first to build `networkId → networkName` map; each network is then paginated via `ap/list`.
- `network_id` (int) stored in the plugin device state table and passed through sync for use in firmware checks.

#### Firmware update check & scheduling (GWN only)
- New `front/firmware.ajax.php` endpoint with two actions:
  - `check` — calls `POST /oapi/v1.0.0/upgrade/version {networkId}` for every tracked network, returns `{mac, currentVersion, latestVersion, hasUpdate}` filtered to **stable releases only** (no `beta`, `rc`, `dev`, `alpha`).
  - `upgrade` — calls `POST /oapi/v1.0.0/upgrade/add {macs:[...]}` to schedule an official firmware update via GWN Cloud.
- Dashboard firmware column: shows `⬆️` (warning amber) icon next to the current version when a stable update is available.
- Bootstrap modal on icon click: displays current vs. latest firmware, `Official` badge, maintenance reboot warning, and **Schedule update** button.
- Firmware check fires 2 seconds after page load in background — does not block the dashboard or the sync cycle.
- Update icon hidden after scheduling to avoid re-triggering.
- All 14 firmware UI strings translated to es_MX, fr_FR, de_DE.

#### SN enrichment — parallel curl_multi
- All `device/info` requests for a network batch fire simultaneously via `curl_multi`, replacing sequential calls and cutting GWN sync time significantly.
- `gwnGetDeviceInfoBatch()` private method added to `api.class.php`.
- `result[]` parsed correctly as an array of `{type, value, key}` objects; `key === 'sn'` locates the serial number.
- Token pre-fetched once at the top of `gwnGetDevices()` and reused for all page requests and batch info calls.

#### SN caching
- Once `sn_cloud` is stored in the plugin device state table, `device/info` is skipped on subsequent syncs.

#### Device state fields
- `network_name` — populated from GWN `networkName` or GDMS `siteName`.
- `network_id` — GWN integer network ID stored per device.
- `ip` — public IP (GDMS: `publicIp`, GWN: `ip` / `ipv4`).
- `firmware` — current firmware version (`firmwareVersion` / `versionFirmware`).
- `uptime_sec` — seconds since last reboot (GWN `upTime`).

#### Asset management improvements
- `buildAssetCaches()` no longer filters by `entities_id` — loads from all entities to avoid entity 0 vs. active entity mismatch.
- `otherserial` field also indexed in serial cache as fallback match.
- `find([], [], 0)` used (no limit) to load all assets.

#### NOC Dashboard
- **Summary cards** with total, online, offline counts and availability % progress bar.
- **Device table** — columns: device name (link to GLPI asset), type badge, network/site, public IP (WHOIS link in new tab), MAC, serial, firmware + upgrade icon, uptime (d h m), status badge, availability %, SLA tier.
- **Per-device history chart** — Chart.js line chart, one line per device with colour palette and bottom legend, replaces the previous single-line aggregate chart.
- **Network topology** — vis-network interactive graph.
- **Auto-refresh** — configurable interval (default 300 s) with countdown timer in header.
- **Manual sync button** — dispatches a background CLI cron task immediately without blocking GLPI output.
- Table first-column padding corrected (`ps-3`); type badge contrast fixed for all themes.
- Dashboard asset lookup queries all entities (no entity filter).

#### Excel export (PhpSpreadsheet)
- New `front/history_export.php` using `phpoffice/phpspreadsheet` (GLPI vendor — no external dependency).
- Three sheets: **% Online 60 days** (pivot with conditional colour fill green ≥ 90 % / yellow ≥ 50 % / red < 50 %), **Raw Data**, **Summary** (per-device availability %, SLA tier).
- Export button in dashboard header translated: "Exportar" / "Exporter" / "Exportieren".

#### Incident ticketing improvements
- **Duplicate guard** — checks for existing open `[GDMS]` ticket linked to the same asset before creating another.
- **Urgency routing** — High (4) for routers; Medium (3) for switches and phones.
- **Rich ticket body** — Markdown table with MAC, serial, IP, network/site, firmware, last uptime, detection timestamp.
- **Asset element** — asset (`Phone` or `NetworkEquipment`) linked as `Item_Ticket` affected item.
- **Auto-resolve** — on offline → online transition the plugin adds a followup note and sets ticket status to Solved.
- Public wrappers `triggerOfflineTicket()` / `triggerResolveTicket()` allow webhook to invoke ticket logic directly.

#### Webhook improvements
- **GET health check** — plain `GET` returns `{"status":"ok","plugin":"gdmsintegration","endpoint":"webhook"}`.
- **Ticket integration** — state transitions received via webhook trigger the full ticket open/resolve flow.
- **Broader payload parsing** — accepts `mac` / `device_mac` / `deviceMac` and `status` / `deviceStatus` / `event`; maps `offline` / `disconnect` / `down` / `0` to offline.

#### Two-tier logging
- `PluginGdmsintegrationUtils::log()` — always written; records errors, token results, device counts, match/create/update actions, ticket events.
- `PluginGdmsintegrationUtils::debug()` — verbose, written only when GLPI debug mode is active **or** the plugin `debug_logging` toggle is enabled.
- All API URLs, request bodies, raw responses, and HMAC signature inputs are verbose-only.
- **Debug logging toggle** in config form (Webhook card). Clears session cache on save for immediate effect.

#### Localization
- All 140 strings translated to es_MX, fr_FR, de_DE (en_US / en_GB use msgid as base).
- Includes Excel sheet names / column headers, firmware modal strings, ticket body phrases, and debug toggle labels.

### Changed
- Cron interval default changed from 60 to **30 minutes**.
- `sync.ajax.php` rewritten — background `exec()` CLI dispatch replaces inline sync to avoid GLPI `LegacyFileLoadController` output-buffer conflict; removed `fastcgi_finish_request()`.
- `webhook.php` rewritten — uses correct Symfony exception namespaces (`Symfony\Component\HttpKernel\Exception\*`) required by GLPI 11.
- `saveStateWithNetwork()` signature extended with `network_id` parameter.
- Sync field mapping corrected — GDMS (`publicIp`, `firmwareVersion`, `siteName`) and GWN (`ip`/`ipv4`, `versionFirmware`, `networkName`, `upTime`) fields mapped explicitly.
- All `<div class="form-text">` in config form replaced with `<small class="text-muted">` to avoid yellow highlight in GLPI dark themes.
- Dashboard JS firmware check and modal injected via `document.body.insertAdjacentHTML` after page render.

### Fixed
- **GWN `device/info` SN** — `result[]` is an array of `{type, value, key}` objects, not an associative map; code now iterates to find `key === 'sn'`. Previous merge approach always yielded empty SN.
- **Webhook `ClassNotFoundError`** — `MethodNotAllowedHttpException` was incorrectly namespaced as `Glpi\Exception\Http\*`; corrected to `Symfony\Component\HttpKernel\Exception\*`.
- **Output buffer crash** — `fastcgi_finish_request()` inside `LegacyFileLoadController` `ob_start()` wrapper caused crash; removed.
- **Duplicate GWN token requests** — `gwnGetToken()` called once per `gwnGetDevices()` call and reused for all pages and batch requests (was called once per page per network).
- **Cron `MODE_EXTERNAL`** — task registered with CLI mode; resolves GLPI automatic-actions warning.
- **`logs_days` = 60** — force-updated on reinstall; was showing 30 days in GLPI automatic actions.

---

## [1.0.0] — 2026-03-24

### Added
- Initial release.
- OAuth2 token-based authentication against GDMS Cloud Open API.
- Automatic device sync via GLPI cron task (`syncDevices`, hourly).
- Pagination support in API client (capped at 50 pages).
- Upsert of `NetworkEquipment` and `Phone` records per GDMS device.
- Smart itemtype selection — phones as `Phone`, PBX and networking gear as `NetworkEquipment`.
- Per-entity configuration (GDMS Username / Password / API ID / Secret).
- All credentials encrypted at rest using `GLPIKey`.
- Automatic incident ticket creation on device online → offline transition with `Item_Ticket` link.
- Per-device uptime % and SLA tier (Gold / Silver / Bronze / Critical).
- 60-day history auto-cleanup.
- NOC dashboard with summary cards, device table, doughnut availability chart, and vis-network topology.
- HMAC-SHA256 webhook endpoint validation.
- Topology deduplication via `UNIQUE KEY (source_mac, target_mac)`.
- Locales: es_MX (full), fr_FR (full), de_DE (full), en_US (empty msgstr), en_GB (empty msgstr).
- `plugin.xml` for GLPI marketplace compatibility.
- Logo PNG (200 × 200, network graph, no text).

### Security
- All dashboard output escaped with `htmlspecialchars()`.
- CSRF token on config form via `Session::getNewCSRFToken()`.
- `Session::checkRight('config', UPDATE)` on config form.
- `Session::haveRight` check on dashboard.
- Webhook requires valid HMAC-SHA256 signature when secret is configured.
- cURL enforces `SSL_VERIFYPEER` + `SSL_VERIFYHOST`.
