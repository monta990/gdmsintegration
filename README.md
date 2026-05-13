<p align="center"><img src="logo.png" alt="GDMS Integration"></p>
<h1 align="center">GDMS Integration</h1>
<p align="center">
  <strong>GLPI plugin — Grandstream GWN Cloud &amp; GDMS Unified Communications integration (Grandstream Device Management System (GDMS))</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue" alt="GLPI 11 compatibility"></a>
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-12.0%2B-blue" alt="GLPI 12 compatibility"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v3%2B-green" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple" alt="PHP"></a>
  <a href="https://github.com/monta990/gdmsintegration/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/gdmsintegration/total"></a>
</p>

---

## Overview

Automatically synchronizes Grandstream networking equipment and VoIP phones from GDMS (Grandstream Device Management System) Cloud into GLPI. Raises incident tickets when devices go offline and auto-resolves them on recovery. Assigned technicians are notified automatically. Provides a real-time NOC dashboard with per-network device stats, traffic metrics, interactive port status, configurable availability history, network topology, Excel export, and firmware update scheduling for all Grandstream device families (GWN, UCM, GRP, GXV, WP, HT).

---

## Features

### Sync Lifecycle — What Happens to Each Device

| Situation | Dashboard | GLPI Asset | Plugin Records | Ticket |
|-----------|-----------|------------|----------------|--------|
| Device online in cloud | ✅ Visible, **Online** | Updated (empty fields only) | State + history kept | — |
| Device offline in cloud | ✅ Visible, **Offline** | Unchanged | State + history kept | ✅ Opened (`online → offline`) |
| Device back online | ✅ Visible, **Online** | Unchanged | State updated | ✅ Auto-resolved |
| **Device removed from cloud** | ❌ **Gone immediately** | **Untouched** | **Purged entirely** | ❌ **None** |

**Device removed from cloud** — on every sync cycle, any MAC/serial no longer returned by the GDMS or GWN API is permanently deleted from the plugin's device-state table and its full history, regardless of which plugin version originally created the record. This means:
- Ghost "offline" devices left over from previous versions are also cleaned up on the first sync after upgrading.
- The device no longer counts toward any SLA, uptime %, availability bar, or chart series.
- The GLPI asset (NetworkEquipment / Phone) is **never deleted** — it stays in GLPI with all its data intact.
- If the device is re-added to the cloud later, the next sync inserts it fresh and re-links it to the existing GLPI asset via serial → MAC match.

---

### Dual API Sync

| API | Device families | GLPI itemtype |
|-----|----------------|---------------|
| GDMS Unified Communications (`gdms.cloud`) | GRP, GXP, GXV, WP, HT phones | `Phone` |
| GDMS Unified Communications (`gdms.cloud`) | UCM, GCC PBX appliances | `NetworkEquipment` |
| GDMS Networking / GWN Cloud (`gwn.cloud`) | GWN APs, switches, routers | `NetworkEquipment` |

### Asset Management

- **Smart matching** — matches existing assets by serial number → MAC (`uuid`) → normalized name, across both itemtypes and all entities.
- **Non-destructive upsert** — only fills empty fields; never overwrites name, serial, comment, or description already set in GLPI.
- **Model resolution** — resolves `phonemodels_id` / `networkequipmentmodels_id` from the existing GLPI catalog without creating entries.
- **Parallel SN enrichment** — GWN `device/info` requests fire simultaneously via `curl_multi`. Serial number extracted from the `result[]` array of `{type, value, key}` objects.
- **SN caching** — once a serial is stored, `device/info` is skipped on subsequent syncs.
- **Token efficiency** — GWN token cached in-process for its full validity period (~3600 s). A full sync cycle with 6+ networks issues only one token request instead of one per network, saving 5–8 seconds of wall time.

---

### NOC Dashboard

#### Summary cards

Six stat cards at the top of the dashboard in Grandstream Cloud style, each showing online / offline counts:

| Card | What it counts |
|------|---------------|
| **Networks** | Total GWN networks / sites |
| **Router** | GWN7001/7002/7003 devices |
| **Switch** | GWN78xx / GSS devices |
| **AP** | Other GWN access points |
| **Phones & PBX** | GRP/GXP/GXV/WP/HT phones **and** UCM/GCC PBX appliances |
| **Clients** | Connected wireless clients (aggregate across all networks) |

A horizontal availability bar below the cards shows the overall online percentage across all devices.

#### Device table

Each row shows: device name (opens GLPI asset in a new tab), type badge, model (click to copy), network/site, public IP (WHOIS link, new tab) + private IP (clickable, opens device admin page in a new tab) + secondary IP (IPv6 or IPv4 depending on config), MAC (click to copy), serial (click to copy), firmware + upgrade icon, ports/SIP dot, uptime, status badge, availability %, SLA tier.

Default order is network devices (routers → switches → APs) first, then phones and PBX, each group sorted by name. Click any of the column headers **Device Name, Type, Model, Network, Status, Clients, Avail. %,** or **SLA** to sort ascending; click again to reverse. A **Reset sort** button appears in the card header to restore the default order. The active sort is persisted in the URL query string so it survives page reload.

#### Uptime cell — quick-access tooltip

Hovering over the uptime value shows a multi-line tooltip with traffic and timing data without opening any modal:

- **Network usage** — `↑ Upload` and `↓ Download` in auto-scaled KB / MB / GB (only shown when data exists).
- **WiFi channels** — active 2.4 GHz and/or 5 GHz channel numbers (only shown for wireless devices that report them).
- **First seen** — date and time the device first connected to the cloud.
- **Last seen** — most recent heartbeat timestamp.
- **Location** — physical location/site from the cloud API (when available).

> This tooltip is purely informational and requires no click.

#### Network name — click for network details modal

The network name in each row is a **clickable link**. Clicking it opens a modal showing the composition and health of that specific network:

- A row for each device category present in the network (Router / Switch / AP / Phones & PBX), showing online/offline badge pair and a colour-coded availability progress bar.
  - 🟢 Green bar — ≥ 80 % availability
  - 🟡 Yellow bar — 50–79 % availability
  - 🔴 Red bar — < 50 % availability
- **Clients** badge — total connected wireless clients in the network.
- **Network traffic** row — aggregate `↑ Upload` and `↓ Download` for all devices in that network (auto-scaled; only shown when traffic data exists).
- Device categories with zero devices are filtered out automatically.

#### Ports column — interactive port dots

Every online GWN router shows one colour-coded dot per physical port in the Ports column (WAN ports first, then LAN ports). Each dot is interactive:

**Dot colours:**

| Colour | Meaning |
|--------|---------|
| 🟢 Green | WAN port — link up, internet confirmed (`connectStatus=1`) |
| 🟠 Orange | WAN port — link up, no internet (`connectStatus=0`) |
| 🟡 Amber | WAN port — link up, status unknown |
| 🔵 Teal | LAN port — link up |
| ⚫ Gray | Link down (any port type) |

**Hovering** over a dot shows a tooltip with the port silk-screen number, port name, WAN connection name, and internet/link-down status.

**Clicking any dot** opens the port detail modal for that device, which contains:

1. **Traffic and timestamps block** (shown above the legend when data is available):
   - `↑ Upload` and `↓ Download` for the device in auto-scaled KB / MB / GB.
   - **First seen** and **Last seen** timestamps.

2. **Port legend** — colour key for the dot colour scheme.

3. **Port cards** — one card per WAN port with: silk-screen label, port name, WAN connection name, IP address, WAN type (DHCP / Static / PPPoE / PPTP / L2TP), gateway IP + reachability, primary and secondary DNS, WAN MAC, IPv6 address (when available), link speed (e.g. `1G FDX`), TX/RX packet counters, time connected. GE, SFP, and combo ports are distinguished. LAN port cards show link speed, label, description, VLAN, and per-port TX/RX byte counters.

> WAN port detail is available for GWN routers (GWN7001, GWN7002, etc.). LAN port detail is available for GWN switches (GWN78xx / GSS). APs, phones, and PBX devices do not report port data.

#### Phone SIP status dot

Phones show a 9 px colour-coded dot in the Ports column instead of port dots:

| Colour | Meaning |
|--------|---------|
| 🟢 Green | SIP account registered |
| 🔴 Red | SIP account unregistered |
| Dimmed | Device offline |

**Hovering** the dot shows a tooltip with SIP state, extension, and DND flag. A **DND** label appears next to the dot when Do Not Disturb is active.

This dot also appears for ATA devices (HT series) regardless of whether they are stored as Phone or NetworkEquipment in GLPI — classification is based on the model prefix.

**Clicking the dot** opens a detail modal with:

- SIP registration status
- Extension number (when available)
- Site / network
- MAC address — click to copy
- Private IP — clickable, opens device admin page
- Public IP — clickable, opens WHOIS lookup
- Last seen timestamp
- PBX / UCM — private IP (clickable) + device name as a link to the GLPI asset (opens new tab)
- Do Not Disturb badge
- Provisioning sync status and last sync error (when present)
- Scheduled task badge

---

#### Private IP display preference

In the plugin configuration, the **Private IP display** setting controls which address family appears first in the IP column:

- **IPv4 preferred** (default) — shows IPv4 as the primary link; IPv6 shown below as a secondary clickable link when available.
- **IPv6 preferred** — shows IPv6 first; IPv4 shown below as fallback.

Both addresses are always rendered as clickable links (IPv6 using RFC 2732 bracket notation: `http://[addr]/`). If only one address family is available for a device, it is shown regardless of preference.

---

#### Network Topology card

A vis-network graph rendered below the device table (toggle in settings). Nodes are colour-coded green/red by online status.

**Edge types:**

| Edge | How determined |
|------|---------------|
| GWN uplink (AP/switch → parent) | `uplink_mac` field from GWN Cloud sync |
| Phone → PBX | Matched by shared `/24 subnet` of private IP; falls back to network name when private IP is unavailable |

Because phones and their UCM share the same LAN segment, each PBX forms its own independent cluster with only its registered phones connected to it — multiple UCMs produce multiple distinct sub-graphs.

---

### SLA Tiers

Availability % is calculated over the configured history period (default 60 days). Each device is assigned a tier:

| Tier | Threshold | Description |
|------|-----------|-------------|
| **Gold** | ≥ 99.9 % | Excellent availability — meets enterprise SLA |
| **Silver** | ≥ 99.0 % | Good availability — minor disruptions |
| **Bronze** | ≥ 95.0 % | Acceptable availability — some incidents recorded |
| **Critical** | < 95.0 % | Poor availability — requires immediate attention |

The same tiers and thresholds apply to both the NOC dashboard and the Excel export Summary sheet.

#### History Import

Availability history lost due to database migration, server move, or accidental deletion can be restored from a previously exported `gdms_history_*.xlsx` file directly from the Configuration page. The importer reads device names and MACs from the Summary sheet and reconstructs the daily percentage from the pivot sheet (100 synthetic records per device-day, spaced ~14 min apart — same order of magnitude as the sync cron). Device-days that already have records in the database are skipped; no existing data is overwritten.

---

### Firmware Updates (All device families)

The dashboard checks firmware availability for all Grandstream devices 2 seconds after page load, in background, without blocking the sync or the device table. GLPI's native Flatpickr date/time picker is used for scheduling updates, with locale matching the active GLPI session. For GDMS-managed devices (UCM, GRP, WP, HT), GDMS applies the latest firmware available in its repository — the version shown in the modal is informational.

**Version sources:**

| Device family | Source |
|---------------|--------|
| GWN routers / switches / APs | GWN Cloud API `/upgrade/version` — direct, per-network |
| UCM / GCC PBX appliances | `grandstream.com/support/firmware` scraper |
| GRP IP phones | `grandstream.com/support/firmware` scraper |
| GXV video phones | `grandstream.com/support/firmware` scraper |
| WP Wi-Fi phones | `grandstream.com/support/firmware` scraper |
| HT ATAs | `grandstream.com/support/firmware` scraper |

An amber **⬆** icon appears next to the firmware version when an update is available for any device type.

**Clicking the ⬆ icon** opens a modal showing:
- Current version installed on the device.
- Available versions with selection radio buttons:
  - **Official firmware** (green badge) — stable release from Grandstream.
  - **Beta firmware** (yellow badge) — pre-release, when available for that model family.
- Reboot warning (the device will restart during the upgrade).
- Two action buttons that apply to the selected version:
  - **Apply now (ASAP)** — sends the upgrade command immediately.
    - GWN devices: calls GWN Cloud `/upgrade/add`.
    - All other devices: creates a GDMS UC `task/add` task with `taskName=UPGRADE`.
  - **Schedule update** — a datetime picker lets you set a specific date and time; the value is sent as milliseconds epoch.
- Success or error is shown inline in the modal without closing it. The modal refreshes its CSRF token between requests, so scheduling multiple upgrades in one session works without page reload.

> GWN devices do not expose beta firmware versions through the GWN Cloud API — only the official stable version is available for them.


---

### Incident Tickets

- **Auto-open** — `[GDMS]` incident ticket created on:
  - Device `online → offline` transition (device unreachable).
  - WAN port physical link-down (`linkStatus` 1 → 0) — opens immediately.
  - WAN port internet loss while link stays up (`linkStatus=1`, `connectStatus` 1 → 0) — title suffix "No Internet". Subject to the configurable debounce delay (default 300 s) to filter transient high-latency false positives.
- **Failover detection** — when a WAN port goes down and another WAN port on the same router has verified internet, the ticket body includes a "Failover → \<ISP name\>" row.
- **Urgency routing** — High (4) for routers; Medium (3) for switches and phones.
- **Tech assignment** — if the GLPI asset has a technician set (`users_id_tech`), the ticket is automatically assigned to that user and opens with status "Assigned".
- **Configurable requester** — a GLPI user can be set as ticket requester in the plugin config (defaults to system/cron user).
- **Rich body** — table with MAC, serial, IP, network/site, firmware, last uptime, detection timestamp.
- **Location** — if the GLPI asset has a location set, the ticket inherits that `locations_id`.
- **Asset element** — asset linked as `Item_Ticket` affected item.
- **Duplicate guard** — skips creation if an open `[GDMS]` ticket already exists for that asset or port.
- **Auto-resolve** — on recovery: adds followup note and sets ticket to Solved.

---

### Webhook

- **Real-time events** — GDMS/GWN Cloud pushes status changes directly to the plugin endpoint.
- **HMAC-SHA256 validation** — verified against `X-GDMS-Signature` header. Secret optional but recommended.
- **GET health check** — returns `{"status":"ok","plugin":"gdmsintegration","endpoint":"webhook"}`.
- **Full ticket integration** — webhook transitions trigger the same open/resolve logic as the cron.

---

### Logging

Logs written to `files/_log/gdmsintegration.log`.

| Tier | When active | What is recorded |
|------|------------|-----------------|
| **Minimal** | Always | Token OK/ERROR, device counts, MATCH/CREATE/UPDATE, ticket events, API errors |
| **Verbose** | GLPI debug mode **or** plugin debug toggle | Full API URLs, request bodies, raw JSON responses, HMAC inputs, SN diagnostics |

> ⚠️ Verbose mode logs full API URLs including access tokens. Disable after troubleshooting and rotate API secrets if logs were exposed.

---

## Requirements

| Component | Version |
|-----------|---------|
| GLPI | 11.0+ |
| PHP | 8.2+ |
| PHP extensions | `curl`, `json` |
| GLPI vendor | `phpoffice/phpspreadsheet` (bundled with GLPI) |

---

## Installation

1. Download the ZIP and extract it so the folder is named **`gdmsintegration`** inside `glpi/plugins/`.
2. In GLPI → **Setup → Plugins** → **Install** → **Enable**.
3. Go to **Tools → GDMS Integration → Configuration** and enter your credentials.
4. Run the first sync manually from the dashboard or wait for the cron.

---

## Configuration

### Card 1 — GDMS Account
| Field | Description |
|-------|-------------|
| Username | GDMS Cloud user |
| Password | Login password — stored encrypted |

### Card 2 — GDMS Networking
| Field | Description |
|-------|-------------|
| GDMS Networking APP ID | APP ID from `gdms.cloud` → GDMS Networking → Organization → Global → Development API |
| GDMS Networking Secret Key | Client secret — stored encrypted |

### Card 3 — GDMS Unified Communications
| Field | Description |
|-------|-------------|
| GDMS UC API ID | Open API client ID from `gdms.cloud` → GDMS Unified Communications → System → Development API |
| GDMS UC Secret Key | Open API client secret — stored encrypted |

### Card 4 — Webhook & Settings
| Field | Description |
|-------|-------------|
| Webhook Secret | Shared secret for HMAC-SHA256 validation (optional but recommended) |
| Webhook URL | Full URL — paste into GDMS/GWN Cloud portal |
| Refresh interval | Dashboard auto-refresh in seconds (default 300) |
| Debug logging | Toggle verbose logging |
| Availability chart days | Days of history shown in chart and Excel export (7–365, default 60). Values > 90 may slow the dashboard. |
| Ticket requester | GLPI user set as requester on auto-generated incident tickets. Asset's assigned user (`users_id`) takes priority when set. |
| Ticket device name | Auto-generated tickets use the GLPI asset name when the device is already registered in GLPI; falls back to the GDMS cloud name for unregistered devices. |
| Show topology card | Toggle the vis-network topology graph. Disabling skips all topology data processing. |
| WAN no-internet debounce (seconds) | Seconds to wait before opening a "no internet" WAN ticket. Filters false alerts from transient high-latency events. 0 = open immediately. Default: 300. |
| Create WAN port tickets | Toggle (on by default). When disabled, the plugin suppresses all WAN link-down and no-internet incident tickets. Port state tracking and debounce timers continue running so re-enabling takes effect immediately. Resolved tickets are still closed automatically. |

**Ticket creation by device type** — five independent toggles (all on by default):

| Option | Description |
|--------|-------------|
| IP Phones (GRP, GXP, GXV, WP) | Open offline tickets when IP phones go offline. |
| Routers (GWN7001/7002/7003) | Open offline tickets when routers go offline. |
| Switches (GWN7800, GSS) | Open offline tickets when managed switches go offline. |
| Access Points (GWN76xx) | Open offline tickets when wireless APs go offline. |
| IP PBX / UCM (UCM, GCC) | Open offline tickets when UCM/GCC PBX appliances go offline. |

Disabling a type suppresses ticket creation for that category only. Ticket auto-resolution is never suppressed — open tickets still close when a device comes back online.

After saving, the plugin tests both API connections and shows green/red status badges.

### History Import card

Upload a `gdms_history_*.xlsx` file (exported from the availability chart) to restore historical availability data. Days already present in the database are skipped.

### Configuration Backup card

| Action | Description |
|--------|-------------|
| **Download JSON** | Exports all settings to a JSON file. Check *Include API credentials* to also export username, keys, and secrets (for full server migrations). |
| **Import JSON** | Restores settings from a backup. If the file includes credentials, they are written; otherwise existing stored secrets are preserved. |

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `glpi_plugin_gdmsintegration_configs` | Credentials and settings per entity |
| `glpi_plugin_gdmsintegration_devices` | Live device state: MAC, status, network_id, network_name, IP, firmware, uptime_sec, sn_cloud, wan_ports_json, model, cloud_name, clients, upload_bytes, download_bytes, usage_bytes, channel_2g, channel_5g, first_seen, last_seen, mgmt_ip, firmware_latest, ipv6, private_ip, location |
| `glpi_plugin_gdmsintegration_history` | Per-device status snapshots (retention based on `chart_days` config) |
| `glpi_plugin_gdmsintegration_links` | Network topology edges |

---

## Architecture Notes

### Entity scope
The plugin operates from the root entity (entity 0) and loads all network equipment across all GLPI entities. Network infrastructure is shared organization-wide, not scoped to individual subsidiaries.

### Device model resolution
The *Model* column in the NOC dashboard resolves first from the GLPI asset catalog. If no model is assigned in GLPI, the raw device type reported by the Grandstream API (e.g. `GWN7001`, `GRP2601`) is used as a fallback.

### GWN Cloud OAuth token
The GWN Cloud API requires the OAuth2 `client_credentials` grant as a `GET` request — this is Grandstream's mandated format. Credentials are encrypted at rest with `GLPIKey` and transmitted only over TLS.

### JavaScript libraries
vis-network 10.0.3 is the only library bundled inside the plugin's `js/` directory and served via a PHP stateless route (`front/visnetwork.php`). Chart rendering uses **ECharts 5** and date picking uses **Flatpickr** — both are already bundled with GLPI and loaded on demand via `Html::requireJs('charts')` and `Html::requireJs('flatpickr')`. No external CDN requests are made.

### Webhook secret
Configuring a webhook secret is strongly recommended for production deployments — a warning is shown in the configuration form when no secret is set.

---

## API Authentication

**GDMS Networking (GWN Cloud)**
- Token: `POST /oapi/oauth/token` — `password = SHA256(MD5(plaintext))`
- Signature: `SHA256( & sorted_params & SHA256(body) & )`

**GDMS Unified Communications**
- Token: `GET /oauth/token?grant_type=client_credentials&client_id=…&client_secret=…`
- Signature: `SHA256( & access_token=…&appID=…&secretKey=…&timestamp=… & SHA256(body) & )`

---

## Webhook Testing

```bash
# Health check
curl https://your-glpi.example.com/plugins/gdmsintegration/front/webhook.php

# Simulate offline event
curl -X POST "https://your-glpi.example.com/plugins/gdmsintegration/front/webhook.php" \
  -H "Content-Type: application/json" \
  -d '{"mac":"c0:74:ad:ec:02:fc","status":"offline"}'
```

---

## Locales

es_MX, fr_FR, de_DE

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Author

Edwin Elias Alvarez — [GitHub](https://github.com/monta990)

---

## Buy me a coffee :)

If you like my work, you can support me with a donation:

<a href="https://www.buymeacoffee.com/monta990" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/default-yellow.png" alt="Buy Me A Coffee" height="51px" width="210px"></a>

---

## License

GPL v3+ — see [LICENSE](LICENSE).

## Issues

Report bugs or request features on the [issue tracker](https://github.com/monta990/gdmsintegration/issues).
