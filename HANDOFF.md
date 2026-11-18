# GDMS Integration — Handoff

## Package

- Plugin: GDMS Integration
- Version: **1.6.8**
- Package purpose: versioned 1.6.8 release of the reviewed GWN cloud-alert ticket control and GWN stable/beta firmware maintenance package, retaining the Teclib DOM-XSS correction.
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

The new setting requires one additional column in the plugin-owned configuration table. Its install/update migration is idempotent and adds it with `DEFAULT 1`, preserving existing behavior.

For an existing 1.6.7 installation:

1. Back up the GLPI database and the existing plugin directory.
2. Replace `plugins/gdmsintegration` with the contents of this 1.6.8 package (retain the folder name `gdmsintegration`).
3. In GLPI, open **Setup → Plugins** and use the **Update** action for GDMS Integration. The version change from 1.6.7 to 1.6.8 allows GLPI to run the normal plugin update lifecycle; the idempotent migration adds the GWN alert-ticket setting with `DEFAULT 1` if it is not already present.
4. Keep the plugin installed and enabled; do not uninstall it. Clear the GLPI cache if the configuration form remains stale.
5. Open **Tools → GDMS Integration → Configuration**. In **Cloud alert ticket creation**, disable **Create tickets for GWN cloud alerts** to stop `[GWN-ALERT:...]` tickets while keeping alerts visible. Save the configuration.
6. Run a GWN sync and confirm cloud alerts remain listed without generating new alert tickets. Verify WAN/offline ticket settings separately if those are in use.

## Validation performed

- Standalone firmware regression test: `php tests/firmware_channel_regression.php` — **18 assertions passed**.
- PHP syntax checks: all 26 PHP files in the package passed `php -l`.
- JavaScript syntax checks: all 4 inline JavaScript blocks in `templates/dashboard.html.twig` passed `node --check` after substituting inert stand-ins for Twig expressions.
- Translation catalogs: all 4 `.po` files (es_MX, fr_FR, de_DE and pt_BR) parsed, compiled to `.mo`, and checked for the new settings/status messages.
- Feature wiring: verified the configuration form, per-entity save/load/export/import paths, install migration and GWN ticket-creation guard are all connected.
- Compact firmware status consistency: beta and ahead-of-stable labels share one message; duplicate selection-row diagnostics are removed, and no selectable version is rendered unless scheduling is verified.
- Package checks: `setup.php` and `plugin.xml` both declare `1.6.8`; final ZIP integrity is checked as part of packaging.

## Known limitations and follow-up

- Firmware catalogue values can change after this package is built. The plugin fetches the vendor catalog at runtime; if Grandstream's catalog and GWN Cloud disagree, scheduling remains blocked rather than guessing a channel.
- This validation uses deterministic fixtures for firmware classification and static checks. A live GLPI site, configured GWN credentials and a physical GWN7001 device were not available here, so the GLPI UI and an actual firmware task have not been exercised against the customer's tenant.
- The GWN API schedules the cloud's available firmware rather than accepting a trusted arbitrary version in the current integration. The server therefore blocks the request unless the cloud candidate exactly matches Grandstream's General Firmware channel.

## Firmware modal compact validation update (2026-11-18)

- Beta-installed and ahead-of-stable states now share one concise status message.
- GWN catalog-only values use a short unconfirmed-channel message; they are not presented as actionable stable updates.
- The “Select version” row appears only when a verified, newer stable version can actually be scheduled. The status diagnostic is no longer duplicated in that row.
- Plugin version remains 1.6.8.
