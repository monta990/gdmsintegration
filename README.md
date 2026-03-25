<p align="center"><img src="logo.png" alt="GDMS Integration"></p>

# GDMS Integration

[![GLPI](https://img.shields.io/badge/GLPI-11.0%2B-blue)](https://glpi-project.org)
[![License](https://img.shields.io/badge/License-GPL%20v3%2B-green)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple)](https://php.net)

Integrates **Grandstream GDMS Cloud** with GLPI 11. Automatically synchronizes network equipment, raises incident tickets when devices go offline, and provides a real-time NOC dashboard with topology visualization.

---

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
| PHP       | 8.1+    |
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

## License

GPL v3+. See [LICENSE](LICENSE).

**Author:** Edwin Elias Alvarez
