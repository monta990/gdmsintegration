<p align="center"><img src="logo.png" alt="GDMS Integration"></p>
<h1 align="center">GDMS Integration</h1>
<p align="center">
  <strong>GLPI plugin — Grandstream GWN Cloud integration --  GDMS Networking --  GDMS Unified Communications</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v3%2B-green" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple" alt="PHP"></a>
  <a href="https://github.com/monta990/gdmsintegration/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/gdmsintegration/total"></a>
</p>

---

## Overview

Automatically synchronizes Grandstream networking equipment and VoIP phones from GDMS Cloud into GLPI. Raises incident tickets when devices go offline and auto-resolves them on recovery. Assigned technicians are notified automatically. Provides a real-time NOC dashboard with per-network device stats, configurable availability history chart, optional network topology, Excel export, and firmware update scheduling for GWN devices.

---

## Features

### Dual API Sync

| API | Device families | GLPI itemtype |
|-----|----------------|---------------|
| GDMS Unified Communications (`gdms.cloud`) | GRP, GXP, GXV, WP, HT phones | `Phone` |
| GDMS Unified Communications (`gdms.cloud`) | UCM, GCC PBX appliances | `NetworkEquipment` |
| GDMS Networking (`gdms.cloud`) | GWN APs, switches, routers | `NetworkEquipment` |

### Asset Management

- **Smart matching** — matches existing assets by serial number → MAC (`uuid`) → normalized name, across both itemtypes and all entities.
- **Non-destructive upsert** — only fills empty fields; never overwrites name, serial, comment, or description already set.
- **Model resolution** — resolves `phonemodels_id` / `networkequipmentmodels_id` from existing catalog without creating entries.
- **Parallel SN enrichment** — GWN `device/info` requests fire simultaneously via `curl_multi`. Serial number extracted from the `result[]` array of `{type, value, key}` objects.
- **SN caching** — once a serial is stored, `device/info` is skipped on subsequent syncs.
- **Token efficiency** — GWN token cached in-process for its full validity period (~3600 s). A full sync cycle with 6+ networks issues only one token request instead of one per network, saving 5–8 seconds of wall time.

### NOC Dashboard

- **Summary cards** — total, online, offline counts and global availability % with progress bar.
- **Device table** — device name (link to GLPI asset), type badge, network/site, public IP (WHOIS link), MAC, serial, firmware + upgrade icon, uptime (d h m), status badge, availability %, SLA tier.
- **Per-device history chart** — Chart.js line chart, one line per device with colour legend. Days shown are configurable (7–365, default 60; values > 90 may slow queries).
- **Network name tooltip** — hovering over a network name shows a breakdown of Router / Switch / AP (online/offline) and connected clients for that network.
- **Network topology** — vis-network interactive graph (can be hidden via config to skip all data processing).
- **Auto-refresh** — configurable interval (default 5 min) with countdown timer.
- **Manual sync** — background CLI dispatch, non-blocking.
- **Excel export** — two-sheet `.xlsx` via `phpoffice/phpspreadsheet` (GLPI vendor, no extra dependency):
  - *% Online N days* — pivot matrix with conditional colour fill (green ≥ 90 %, yellow ≥ 50 %, red < 50 %)
  - *Summary* — per-device availability %, SLA tier

### SLA Tiers

Availability % is calculated over the configured history period (default 60 days, adjustable in Settings). Each device is assigned a tier based on its percentage of time online:

| Tier | Threshold | Description |
|------|-----------|-------------|
| **Gold** | ≥ 99.9 % | Excellent availability — meets enterprise SLA |
| **Silver** | ≥ 99.0 % | Good availability — minor disruptions |
| **Bronze** | ≥ 95.0 % | Acceptable availability — some incidents recorded |
| **Critical** | < 95.0 % | Poor availability — requires immediate attention |

The same tiers and thresholds apply to both the NOC dashboard and the Excel export Summary sheet.

### Firmware Update (GDMS Networking only)

- Firmware check runs 2 seconds after page load via `firmware.ajax.php?action=check`.
- Calls `POST /oapi/v1.0.0/upgrade/version` per network; flags only **stable releases** (no `beta`, `rc`, `dev`, `alpha`).
- **⬆️ amber icon** appears next to the current firmware version when a stable update is available.
- **Click the icon** to open a Bootstrap modal with current vs. latest version, `Official` badge, reboot warning, and two action buttons:
  - **Apply now (ASAP)** — sends the upgrade request immediately with no scheduled time; the device reboots as soon as the cloud delivers the command.
  - **Schedule update** — a datetime picker lets you set a specific date and time; the value is sent as milliseconds epoch in the `time` field of `POST /oapi/v1.0.0/upgrade/add`.
- Success/error is shown inline in the modal without closing it.

### Port Monitoring (GDMS Networking — Routers only)

- **Ports column** in the NOC dashboard shows one colour-coded dot per physical port for every online GWN router.
- **Colour legend:**

  | Colour | Meaning |
  |--------|---------|
  | 🟢 Green | WAN port — link up, internet confirmed |
  | 🟠 Orange | WAN port — link up, no internet |
  | 🟡 Amber | WAN port — link up, status unknown |
  | 🔵 Teal | LAN port — link up |
  | ⚫ Gray | Link down (any port type) |

- **Click any dot** to open a detail modal per port showing: port label (silk-screen), port name, WAN name, connection status, IP address, WAN type (DHCP/Static/PPPoE), link speed, and time connected.
- **Port-down ticket** — when a sync detects any port transitioning from link-up to link-down, a `[GDMS-WAN:portName]` incident ticket is created (urgency High) and linked to the asset. WAN ports are prioritised; LAN port transitions also generate tickets. A duplicate guard prevents repeat tickets for the same port.
- Port data is updated each sync cycle and stored per device for transition detection. Only applies to online GWN routers (GWN7001, GWN7002, etc.) — switches, APs and phones do not report port info.

### Incident Tickets

- **Auto-open** — `[GDMS]` incident ticket created on online → offline transition (device down) or link-down transition (port down).
- **Urgency routing** — High (4) for routers; Medium (3) for switches and phones.
- **Tech assignment** — if the GLPI asset has a technician set (`users_id_tech`), the ticket is automatically assigned to that user and opens with status "Assigned".
- **Configurable requester** — a GLPI user can be set as ticket requester in the plugin config (defaults to system/cron user).
- **Rich body** — table with MAC, serial, IP, network/site, firmware, last uptime, detection timestamp.
- **Asset element** — asset linked as `Item_Ticket` affected item.
- **Duplicate guard** — skips creation if an open `[GDMS]` ticket already exists for that asset or port.
- **Auto-resolve** — on recovery: adds followup note and sets ticket to Solved.

### Webhook

- **Real-time events** — GDMS/GWN Cloud pushes status changes directly to the plugin endpoint.
- **HMAC-SHA256 validation** — verified against `X-GDMS-Signature` header. Secret optional but recommended.
- **GET health check** — returns `{"status":"ok","plugin":"gdmsintegration","endpoint":"webhook"}`.
- **Full ticket integration** — webhook transitions trigger the same open/resolve logic as the cron.

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
| GDMS Networking APP ID | APP ID from `gdms.cloud` -> GDMS Networking -> Organization -> Global -> Development API |
| GDMS Networking Secret Key | Client secret — stored encrypted |

### Card 3 — GDMS Unified Communications
| Field | Description |
|-------|-------------|
| GDMS Unified Communications API ID | Open API client ID from `gdms.cloud` ->GDMS Unified Communications -> System -> Development API |
| GDMS Unified Communications Secret Key | Open API client secret — stored encrypted |

### Card 4 — Webhook & Settings
| Field | Description |
|-------|-------------|
| Webhook Secret | Shared secret for HMAC-SHA256 validation (optional) |
| Webhook URL | Full URL shown — paste into GDMS/GWN Cloud portal |
| Refresh interval | Dashboard auto-refresh in seconds (default 300) |
| Debug logging | Toggle verbose logging |
| Availability chart days | Days of history shown in dashboard chart and exported to Excel (7–365, default 60). Values > 90 may slow the dashboard. |
| Ticket requester | GLPI user set as requester on auto-generated incident tickets (default: system/cron user) |
| Show topology card | Toggle the network topology card and vis-network graph on the dashboard. Disabling skips all topology data processing. |

After saving, the plugin tests both API connections and shows green/red status badges.

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `glpi_plugin_gdmsintegration_configs` | Credentials and settings per entity |
| `glpi_plugin_gdmsintegration_devices` | Live device state: MAC, status, network_id, network_name, IP, firmware, uptime_sec, sn_cloud, wan_ports_json, model, cloud_name, clients |
| `glpi_plugin_gdmsintegration_history` | Per-device status snapshots (retention based on `chart_days` config, default 60 days) |
| `glpi_plugin_gdmsintegration_links` | Network topology edges |

---

## Architecture Notes

### Entity scope
The plugin operates from the root entity (entity 0) and loads all network equipment across all GLPI entities. This is intentional — network infrastructure (routers, switches, APs) is shared organization-wide, not scoped to individual subsidiaries. Administrators with access to the plugin dashboard will see all devices regardless of entity boundaries.

### Device model resolution
The *Model* column in the NOC dashboard resolves first from the GLPI asset catalog (`networkequipmentmodels_id` / `phonemodels_id`). If no model is assigned in GLPI, the raw device type reported by the Grandstream API (e.g. `GWN7001`, `GRP2601`) is used as a fallback and stored in the plugin state table.

### GWN Cloud OAuth token
The GWN Cloud API requires the OAuth2 `client_credentials` grant as a `GET` request with `client_id` and `client_secret` in the query string — this is Grandstream's mandated format and cannot be changed to POST. Credentials are encrypted at rest with `GLPIKey` and transmitted only over TLS to `gwn.cloud`.

### Webhook secret
The HMAC-SHA256 webhook secret is optional because GWN Cloud does not include a verifiable signature on all event types. When no secret is configured, the endpoint is still reachable but all writes are limited to the state fields the API reports. Configuring a secret is strongly recommended for production deployments — a warning is shown in the configuration form.

### Third-party scripts
The dashboard uses two JavaScript libraries: **Chart.js** (loaded from jsDelivr) and **vis-network** (loaded from unpkg). `vis-network` is not included in GLPI's vendor bundle and has no equivalent in the GLPI ecosystem. Migration to GLPI's bundled Chart.js and self-hosted vis-network is planned for a future release.

---

## API Authentication

**GDMS Networking**
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

| Locale | Status |
|--------|--------|
| es_MX  | ✅ Full |
| fr_FR  | ✅ Full |
| de_DE  | ✅ Full |
| en_US  | Base |
| en_GB  | Base |

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
