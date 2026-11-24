# GDMS Integration — Handoff

## Package

- Plugin: GDMS Integration
- Version: **1.6.9**
- Package purpose: versioned 1.6.9 release of the reviewed GWN cloud-alert ticket control and GWN stable/beta firmware maintenance package, retaining the Teclib DOM-XSS correction.
- GLPI support declared by the existing plugin: GLPI 11/12.
- PHP requirement declared by the existing plugin: PHP 8.2+.

## Changes in this package

### 1. Independent GWN cloud-alert ticket setting

- Added the per-entity setting `gwn_alert_tickets_enabled` to `glpi_plugin_gdmsintegration_configs`.
- Added a separate card in the plugin configuration form: **Create tickets for GWN cloud alerts**.
- Default is `1` (enabled) for existing installations, preserving the previous behavior until an administrator changes it.
- When disabled, GWN cloud alerts remain available from the dashboard/API but `syncGwnAlertTickets()` does not create tickets.
- Offline-device tickets and WAN incident tickets remain independent and continue to follow their existing settings.
- Configuration JSON export/import includes the new setting.
- Added Spanish (Mexico), French (France), German and Brazilian Portuguese translations; corresponding `.mo` catalogs were rebuilt.

### 2. GWN stable/beta firmware correction

- Grandstream's firmware catalog `General Firmware` value is the authoritative stable version; `Beta Firmware` remains a separate channel.
- GWN Cloud's numeric firmware candidate is not considered stable solely because the API omits a beta/channel flag.
- `API::resolveGwnFirmwareChannel()` classifies an exact match to General Firmware as stable, an exact Beta Firmware match as beta, and other reported versions as unverified.
- `check` and `check_all` now display the catalog's General Firmware as the stable baseline and only permit scheduling when GWN Cloud reports that exact stable version.
- The GWN upgrade endpoint revalidates each target against the catalog and blocks beta, unverified or stale candidates before submitting an upgrade task.
- Device synchronization persists the catalog's stable version as `firmware_latest`; it does not promote an unclassified GWN candidate into the stable baseline. If the source is unavailable, the last verified value is preserved.
- Current catalog regression case for GWN7001/7002/7003: General `1.0.11.61`; Beta `1.0.11.63`.

### 3. Previous Teclib DOM-XSS correction retained

- Firmware modal `data-url` is still assigned through the DOM API rather than unsafe HTML-string interpolation.
- Dynamic HTML escaping still encodes both quote types.
- Scraped firmware download URLs still undergo HTML entity decoding, HTTPS/host validation and strict `.bin` path validation.

## Main files touched

- `hook.php`: install/update migration for the per-entity ticket setting.
- `src/Config.php`: safe default and preservation behavior for the new setting.
- `src/Service/ConfigService.php`: save, load, export and import handling.
- `templates/config_form.html.twig`: new cloud-alert ticket switch.
- `src/Sync.php`: conditionally suppresses cloud-alert ticket creation and sources stable firmware from Grandstream's catalog.
- `src/API.php`: GWN channel annotation and catalog resolution.
- `src/Service/FirmwareService.php`: stable-only firmware display/scheduling/upgrade guards.
- `locales/`: updated source translations and compiled catalogs.
- `tests/firmware_channel_regression.php`: standalone regression coverage.
- `README.md`, `CHANGELOG.md`: operational documentation and 1.6.8 release notes.

## Database migration and deployment

Version 1.6.9 does not introduce a database schema migration. The per-entity GWN alert-ticket setting and its idempotent migration were introduced in 1.6.8 and are retained.

For an existing 1.6.8 installation:

1. Back up the GLPI database and the existing plugin directory.
2. Replace `plugins/gdmsintegration` with the contents of this 1.6.9 package; keep the directory name `gdmsintegration`.
3. In GLPI, open **Setup → Plugins** and use **Update** for GDMS Integration so GLPI runs the normal update lifecycle and repairs the native SyncDevices CronTask registration.
4. Keep the plugin installed and enabled; do not uninstall it. Clear the GLPI cache if the dashboard or configuration page remains stale.
5. Verify the dashboard topology, configuration page, firmware checks and GWN alert-ticket preference.
6. Run a GWN sync and confirm existing alerts do not create duplicate tickets, including alerts whose IDs contain underscores or percent signs.

## Automated installation package artifact (2026-11-18)

The CLI workflow builds the installable ZIP with `scripts/package.sh`, validates its root/layout and required runtime files, and creates a SHA-256 checksum. It uploads the exact ZIP and checksum as the `gdmsintegration-installable` Actions artifact on pull requests and branch pushes as well as main-branch builds. Upload runs only in the PHP 8.3 matrix leg to avoid duplicate artifacts. The artifact is produced by the unprivileged CI workflow and is not consumed by privileged workflows. Download it from the successful **GDMS Integration CI** run under **Actions → Artifacts**.

## Validation performed

- Standalone firmware regression test: `php tests/firmware_channel_regression.php` — **23 assertions passed**.
- PHP syntax checks: all 26 PHP files in the package passed `php -l`.
- JavaScript syntax checks: all 4 inline JavaScript blocks in `templates/dashboard.html.twig` passed `node --check` after substituting inert stand-ins for Twig expressions.
- Translation catalogs: all 4 `.po` files (es_MX, fr_FR, de_DE and pt_BR) parsed, compiled to `.mo`, and checked for the new settings/status messages.
- Feature wiring: verified the configuration form, per-entity save/load/export/import paths, install migration and GWN ticket-creation guard are all connected.
- Installed GWN firmware regression: General `1.0.11.61` remains `up_to_date`, Beta `1.0.11.63` remains `beta_installed`, and catalog-only update candidates remain blocked unless GWN Cloud confirms them.
- Compact firmware status consistency: beta and ahead-of-stable labels share one message; duplicate selection-row diagnostics are removed, and no selectable version is rendered unless scheduling is verified.
- Package checks: `setup.php`, `plugin.xml`, `CHANGELOG.md` and the npm lockfile are synchronized for 1.6.9; the final ZIP is checked for required runtime files, a single plugin root and integrity.

## CLI and cross-version test strategy (2026-11-18)

The release test workflow is intentionally independent of a pinned plugin release number:

- `tests/run.php` runs the plugin's deterministic business-logic and security regression checks.
- `tests/firmware_channel_regression.php` runs as a separate CLI step in every PHP matrix job. It verifies firmware-channel behavior and compact status behavior without locking the test to one English sentence.
- `tests/validate-release.php` loads the plugin's own `setup.php` metadata and compares the runtime version with `plugin.xml` and `CHANGELOG.md`. It validates PHP/GLPI requirement syntax and ordering instead of requiring a specific minimum PHP version or fixed GLPI compatibility versions.
- `scripts/package.sh` reads the version from the plugin bootstrap at runtime. The CI workflow locates the generated archive by its package filename pattern rather than parsing a version literal from source.
- A local release-validator check with a simulated version `9.4.2` passed, confirming that the validator is not tied to a specific release number.

### GitHub Actions validation

Validation is performed against the exact head commit of PR #66; see the PR's latest CI and Security Analysis runs.

- CLI and package matrix: PHP 8.2, 8.3, 8.4, 8.5 and 8.6 experimental — all passed.
- Business logic: `tests/run.php` — 8/8 tests passed.
- Firmware channels: `tests/firmware_channel_regression.php` — 23 assertions passed in the CLI matrix.
- Release metadata/layout validation and installation ZIP build/integrity — passed in the CLI matrix.
- GLPI 11 and GLPI 12: dashboard Twig compile/render regression, PHP-CS-Fixer baseline, changed-runtime PHP style, PHP lint, Twig lint and dashboard TwigCS — passed. PHPStan and Psalm run in non-blocking legacy-baseline mode; their existing diagnostics are not a clean pass and do not gate this PR.
- Gitleaks, CodeQL Actions and zizmor — passed.

The installed GLPI UI and a live firmware task still require customer-environment validation; CI uses deterministic tests and GLPI source checkouts.

## Known limitations and follow-up

- Firmware catalogue values can change after this package is built. The plugin fetches the vendor catalog at runtime; if Grandstream's catalog and GWN Cloud disagree, scheduling remains blocked rather than guessing a channel.
- This validation uses deterministic fixtures for firmware classification and static checks. A live GLPI site, configured GWN credentials and a physical GWN7001 device were not available here, so the GLPI UI and an actual firmware task have not been exercised against the customer's tenant.
- The GWN API schedules the cloud's available firmware rather than accepting a trusted arbitrary version in the current integration. The server therefore blocks the request unless the cloud candidate exactly matches Grandstream's General Firmware channel.

## Firmware modal compact validation update (2026-11-18)

- Beta-installed and ahead-of-stable states now share one concise status message.
- GWN catalog-only values use a short unconfirmed-channel message; they are not presented as actionable stable updates.
- The “Select version” row appears only when a verified, newer stable version can actually be scheduled. The status diagnostic is no longer duplicated in that row.
- A device already running the exact General Firmware version is now correctly shown as stable/up to date even when GWN Cloud does not confirm a separate upgrade candidate. The cloud-confirmation guard remains in place for upgrades and continues to block an unverified newer version.
- Regression coverage explicitly verifies General `1.0.11.61` as up to date, Beta `1.0.11.63` as beta-installed, and keeps unconfirmed upgrades blocked.
- This firmware-modal behavior was introduced in 1.6.8 and remains unchanged in 1.6.9.


## 1.6.9 Security-review remediation (2026-11-24)

- Dashboard and configuration Twig templates now render through GLPI's native `TemplateRenderer` and the `@gdmsintegration` namespace. The standalone plugin Twig environment was removed, so GLPI owns Twig extensions and CSP nonce handling.
- Parsed Grandstream firmware catalogue is cached in GLPI's shared cache with a 600-second TTL; upstream errors are not cached.
- CronTask self-repair no longer runs from `plugin_init_gdmsintegration()`; install/update and activation hooks perform the repair.
- GWN alert ticket deduplication uses a constant-prefix candidate query plus exact PHP marker comparison, preventing SQL LIKE wildcard collisions for IDs containing underscores or percent signs.
- `vis-network` is pinned to 10.1.5; CI performs npm audit, refreshes the standalone UMD bundle from the npm package, records its SHA-256 and ships both upstream license texts.
- Existing GLPI 11/12 BaseTM compatibility, GWN General/Beta classification, exact firmware scheduling checks, alert ticket preference and DOM-XSS remediation are retained.
