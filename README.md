<p align="center"><img src="logo.png" alt="GDMS Integration"></p>
<h1 align="center">GDMS Integration</h1>
<p align="center">
  <strong>GLPI 11 plugin — Grandstream GDMS Cloud &amp; GWN Cloud integration</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v3%2B-green" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple" alt="PHP"></a>
  <a href="https://github.com/monta990/gdmsintegration/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/gdmsintegration/total"></a>
</p>

---

## Overview

Automatically synchronizes Grandstream networking equipment and VoIP phones from GDMS Cloud and GWN Cloud into GLPI 11. Raises incident tickets when devices go offline and auto-resolves them on recovery. Provides a real-time NOC dashboard with uptime history, availability SLA, network topology, Excel export, and one-click firmware update scheduling for GWN devices.

---

## Features

### Dual API Sync

| API | Device families | GLPI itemtype |
|-----|----------------|---------------|
| GDMS Cloud (`gdms.cloud`) | GRP, GXP, GXV, WP, HT phones | `Phone` |
| GDMS Cloud (`gdms.cloud`) | UCM, GCC PBX appliances | `NetworkEquipment` |
| GWN Cloud (`gwn.cloud`) | GWN APs, switches, routers | `NetworkEquipment` |

### Asset Management

- **Smart matching** — matches existing assets by serial number → MAC (`uuid`) → normalized name, across both itemtypes and all entities.
- **Non-destructive upsert** — only fills empty fields; never overwrites name, serial, comment, or description already set.
- **Model resolution** — resolves `phonemodels_id` / `networkequipmentmodels_id` from existing catalog without creating entries.
- **Parallel SN enrichment** — GWN `device/info` requests fire simultaneously via `curl_multi`. Serial number extracted from the `result[]` array of `{type, value, key}` objects.
- **SN caching** — once a serial is stored, `device/info` is skipped on subsequent syncs.
- **Token efficiency** — GWN token fetched once per sync cycle and reused for all page requests and batch calls.

### NOC Dashboard

- **Summary cards** — total, online, offline counts and global availability % with progress bar.
- **Device table** — device name (link to GLPI asset), type badge, network/site, public IP (WHOIS link), MAC, serial, firmware + upgrade icon, uptime (d h m), status badge, availability %, SLA tier.
- **Per-device history chart** — Chart.js line chart, one line per device with colour legend, last 60 days.
- **Network topology** — vis-network interactive graph.
- **Auto-refresh** — configurable interval (default 5 min) with countdown timer.
- **Manual sync** — background CLI dispatch, non-blocking.
- **Excel export** — three-sheet `.xlsx` via `phpoffice/phpspreadsheet` (GLPI vendor, no extra dependency):
  - *% Online 60 days* — pivot matrix with conditional colour fill
  - *Raw Data* — individual history records
  - *Summary* — per-device availability % and SLA tier

### Firmware Update (GWN only) -- WORK IN PROGRESS

- Firmware check runs 2 seconds after page load via `firmware.ajax.php?action=check`.
- Calls `POST /oapi/v1.0.0/upgrade/version` per network; flags only **stable releases** (no `beta`, `rc`, `dev`, `alpha`).
- **⬆️ amber icon** appears next to the current firmware version when a stable update is available.
- **Click the icon** to open a Bootstrap modal with current vs. latest version, `Official` badge, reboot warning, and a **Schedule update** button.
- Schedule calls `POST /oapi/v1.0.0/upgrade/add` — success/error shown inline in the modal.

### Incident Tickets -- WORK IN PROGRESS

- **Auto-open** — `[GDMS]` incident ticket created on online → offline transition.
- **Urgency routing** — High (4) for routers; Medium (3) for switches and phones.
- **Rich body** — table with MAC, serial, IP, network/site, firmware, last uptime, detection timestamp.
- **Asset element** — asset linked as `Item_Ticket` affected item.
- **Duplicate guard** — skips creation if an open `[GDMS]` ticket already exists for that asset.
- **Auto-resolve** — on recovery: adds followup note and sets ticket to Solved.

### Webhook -- WORK IN PROGRESS

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
| Username | GDMS Cloud login email |
| Password | Login password — stored encrypted |

### Card 2 — GWN Networking
| Field | Description |
|-------|-------------|
| GWN API ID | Client ID from `gwn.cloud` developer portal |
| GWN Secret Key | Client secret — stored encrypted |

### Card 3 — GDMS Unified Communications
| Field | Description |
|-------|-------------|
| GDMS API ID | Open API client ID from `gdms.cloud` |
| GDMS Secret Key | Open API client secret — stored encrypted |

### Card 4 — Webhook & Settings
| Field | Description |
|-------|-------------|
| Webhook Secret | Shared secret for HMAC-SHA256 validation (optional) |
| Webhook URL | Full URL shown — paste into GDMS/GWN Cloud portal |
| Refresh interval | Dashboard auto-refresh in seconds (default 300) |
| Debug logging | Toggle verbose logging |

After saving, the plugin tests both API connections and shows green/red status badges.

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `glpi_plugin_gdmsintegration_configs` | Credentials and settings per entity |
| `glpi_plugin_gdmsintegration_devices` | Live device state: MAC, status, network_id, network_name, IP, firmware, uptime_sec, sn_cloud |
| `glpi_plugin_gdmsintegration_history` | Per-device status snapshots (60-day retention) |
| `glpi_plugin_gdmsintegration_links` | Network topology edges |

---

## API Authentication

**GDMS Cloud**
- Token: `POST /oapi/oauth/token` — `password = SHA256(MD5(plaintext))`
- Signature: `SHA256( & sorted_params & SHA256(body) & )`

**GWN Cloud**
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
