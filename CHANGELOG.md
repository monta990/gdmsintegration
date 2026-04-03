# Changelog — GDMS Integration

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
