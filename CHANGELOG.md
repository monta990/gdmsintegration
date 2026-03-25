# Changelog — GDMS Integration

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.1] — 2026-03-24

### Changed
- **Duplicate detection**: `syncEntity()` now builds two caches — one by MAC (`uuid`) and one by serial number (`serial`). A device is matched first by MAC, then by serial as fallback. This prevents duplicate `NetworkEquipment` records when a device is replaced or when its MAC is not yet in the cache.
- **Field mapping**: MAC address → `uuid` field; Serial number → `serial` field (native GLPI "Número de serie").
- **Asset linked to ticket**: When an offline incident ticket is created, the corresponding `NetworkEquipment` asset is automatically linked as an affected item via `Item_Ticket` — no manual linking required.
- Ticket content now includes serial number: `Device Offline: <name> (MAC: XX:XX, S/N: XXXXXX)`.
- All identifiers normalized to lowercase before cache lookup to avoid case-mismatch duplicates.
- Locale strings updated across all 5 locales to reflect new ticket content format.

## [1.0.0] — 2026-03-24

### Added
- Initial release
- OAuth2 token-based authentication against GDMS Cloud Open API
- Automatic device sync via GLPI cron task (`syncDevices`, hourly)
- Pagination support in API client (capped at 50 pages)
- Upsert of `NetworkEquipment` records per GDMS device
- Per-entity configuration (Client ID / Client Secret / Webhook Secret)
- `client_secret` encrypted at rest using `Toolbox::encrypt()`
- Automatic incident ticket creation on device online→offline transition
- Per-device uptime % and SLA tier (Gold/Silver/Bronze/Critical)
- 60-day history auto-cleanup
- NOC dashboard with doughnut chart and vis-network topology map
- HMAC-SHA256 webhook endpoint validation
- Topology deduplication via `UNIQUE KEY (source_mac, target_mac)`
- Locales: es_MX (full), fr_FR (full), de_DE (full), en_US (empty msgstr), en_GB (empty msgstr)
- `plugin.xml` for GLPI marketplace compatibility
- Logo PNG (200×200, network graph, no text)

### Security
- All output on dashboard escaped with `htmlspecialchars()`
- CSRF token (`Session::checkCSRF`) on config form
- `Session::checkRight('config', UPDATE)` on config form
- `Session::haveRight` check on dashboard
- Webhook requires valid HMAC-SHA256 signature when secret is configured
- cURL enforces `SSL_VERIFYPEER` + `SSL_VERIFYHOST`
