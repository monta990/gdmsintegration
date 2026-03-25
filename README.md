<p align="center"><img src="logo.png" alt="GDMS Integration"></p>
<h1 align="center"> GDMS Integration</h1>
<p align="center">
  <strong>GLPI plugin — Integrates Grandstream GDMS Cloud with GLPI</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-11.0%2B-blue" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v2%2B-green" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple" alt="PHP"></a>
  <a href="https://github.com/monta990/gdmsintegration/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/gdmsintegration/total"></a>
</p>

---

## Overview

Automatically synchronizes network equipment, raises incident tickets when devices go offline, and provides a real-time NOC dashboard with topology visualization.

---

GDMS Integration — GLPI Plugin — Integrates Grandstream GDMS Cloud with GLPI

## Features

- **Device Sync** — pulls devices from GDMS Cloud API and upserts them as `NetworkEquipment` in GLPI
- **Incident Tickets** — automatically opens a ticket when a device transitions from online → offline
- **Uptime & SLA** — tracks per-device uptime % and assigns Gold/Silver/Bronze/Critical tiers
- **NOC Dashboard** — doughnut summary chart + interactive vis-network topology map
- **Webhook** — HMAC-SHA256 validated endpoint for real-time GDMS push events
- **Multi-entity** — independent credentials per GLPI entity

---

## Requirements

| Component | Version |
|-----------|---------|
| GLPI      | 11.0+   |
| PHP       | 8.2+    |
| PHP ext   | `curl`  |

---

## Installation

1. Download the ZIP and extract it so the folder is named `gdmsintegration` inside `glpi/plugins/`.
2. In GLPI → **Setup → Plugins**, find **GDMS Integration** and click **Install**, then **Enable**.
3. Go to **Plugins → GDMS → Configuration** and enter your GDMS Cloud credentials.

---

## Configuration

| Field | Description |
|-------|-------------|
| Entity | GLPI entity this config applies to |
| Client ID | GDMS Cloud Open API client ID |
| Client Secret | GDMS Cloud Open API client secret (stored encrypted) |
| Webhook Secret | Shared secret for HMAC-SHA256 webhook validation (optional but recommended) |

The **Webhook URL** for your entity is shown at the bottom of the config page.

---

## Cron

The sync task is registered automatically on install. Default interval: **1 hour**.

You can adjust it in **Setup → Automatic Actions → GDMS Sync**.

---

## Webhook

Configure the webhook URL shown in the config page in your GDMS Cloud portal. GDMS will sign each request with the shared secret.

The endpoint validates the `X-GDMS-Signature: sha256=<hex>` header.

---

## Locales

| Locale | Status |
|--------|--------|
| es_MX  | ✅ Full |
| fr_FR  | ✅ Full |
| de_DE  | ✅ Full |
| en_US  | Base (msgid = EN) |
| en_GB  | Base (msgid = EN) |

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Author

Edwin Elias Alvarez — [GitHub](https://github.com/monta990).

---

## Buy me a coffee :)

If you like my work, you can support me with a donation:

<a href="https://www.buymeacoffee.com/monta990" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/default-yellow.png" alt="Buy Me A Coffee" height="51px" width="210px"></a>

---

## License

GPL-v3+-see [LICENSE](LICENSE).

## Issues

Report bugs or request features on the [issue tracker](https://github.com/monta990/gdmsintegration/issues).
