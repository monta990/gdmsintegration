# GDMS Integration — CLI testing and release validation

This guide describes the automated and staging checks for the published GDMS Integration plugin. The current published baseline is **1.6.7**. The testing infrastructure is development-only and does not change the plugin version or runtime loading sequence.

## 1. Test environment

The plugin declares PHP 8.2 as its minimum and supports GLPI 11.0 and GLPI 12.0. The required CLI matrix is PHP 8.2, 8.3, 8.4 and 8.5. PHP 8.6 is an experimental, non-blocking job.

The automated business-logic tests run without a GLPI installation, GDMS credentials, GWN credentials, or a live Grandstream API. GLPI-specific static analysis is performed against both supported core branches.

## 2. Run the CLI checks

Run the following commands from the repository root:

```bash
# Check all plugin and test PHP files.
find setup.php hook.php src tests -type f -name '*.php' -print0 \
  | sort -z \
  | xargs -0 -r -n1 php -l

# Run deterministic business-logic tests.
php tests/run.php

# Validate plugin metadata and the source directory layout.
php tests/validate-release.php

# Build the same one-folder structure used by GLPI plugin installation.
bash scripts/package.sh dist

# Inspect and test the release archive.
unzip -t dist/gdmsintegration-1.6.7.zip
unzip -l dist/gdmsintegration-1.6.7.zip
```

The package command reads the current version from `setup.php`; the example filename above corresponds to the current 1.6.7 baseline.

## 3. Automated business-logic coverage

The standalone CLI suite exercises existing production methods without changing them:

- SLA threshold boundaries: Gold, Silver, Bronze and Critical.
- HTML ticket generation: escaping of untrusted values, multiline text, invalid rows and GLPI rich-text sanitization integration.
- Safe ticket follow-up notes.
- Firmware model-to-CDN mappings and unsupported-model behavior.
- MAC normalization and firmware-version format validation.
- Trusted firmware download host validation.
- Firmware scheduling rules: only the exact verified stable version may be scheduled, and only when newer than the installed version.

The suite deliberately does not emulate a GLPI database or claim to validate live API behavior. Network calls, cron execution, ticket creation, database migrations, permissions and asset persistence require a disposable GLPI staging installation.

## 4. Package layout and file-order protection

The installation archive must have exactly one top-level directory named `gdmsintegration/`. The original relative paths are preserved, including:

- `gdmsintegration/setup.php` and `gdmsintegration/hook.php`.
- `gdmsintegration/src/` with its existing class hierarchy.
- `gdmsintegration/templates/`.
- `gdmsintegration/locales/`.
- `gdmsintegration/public/js/vis-network.min.js`.
- The root plugin metadata and image files.

The packaging script copies an explicit runtime allow-list. It does not copy the repository wholesale. Therefore `.github/`, `tests/`, `scripts/`, static-analysis configuration and development-only files cannot accidentally enter the release ZIP.

The existing runtime bootstrap remains unchanged: `setup.php` continues to load `hook.php` with `require_once` before the plugin lifecycle functions are registered. The tests and workflow are never required by plugin runtime code.

## 5. GLPI staging acceptance

Use a disposable clone of the production GLPI instance. Back up both the database and the GLPI plugin document directory before testing an update.

### GLPI 11.0

- [ ] Install or update the exact candidate ZIP without uninstalling the existing plugin.
- [ ] Confirm GLPI recognizes GDMS Integration and reports no compatibility warning.
- [ ] Confirm the plugin configuration page opens and existing entity configurations remain intact.
- [ ] Confirm encrypted GDMS/GWN credentials remain present and are not exposed through UI or REST responses.
- [ ] Run the native `SyncDevices` automatic action and confirm the cron task remains registered once.
- [ ] Confirm device state, history, network locations and client history remain intact.
- [ ] Confirm dashboard, firmware, ticket and XLSX functions remain usable.

### GLPI 12.0

- [ ] Repeat the same update and functional checks on a separate GLPI 12.0 staging instance.
- [ ] Confirm plugin initialization and menu registration work with the GLPI 12 API.
- [ ] Confirm the native `SyncDevices` task is present with the canonical itemtype.
- [ ] Confirm dashboard rendering, Twig templates and asset diagnostic tabs work.
- [ ] Confirm no PHP deprecation, SQL error or Twig exception is introduced.

### Synchronization and operational behavior

- [ ] Test an online device, an offline transition and a recovery using a controlled test account.
- [ ] Confirm a device removed from the cloud is removed from plugin state/history but its GLPI asset is not deleted.
- [ ] Confirm a failed provider/API request is not reported as a fully successful synchronization.
- [ ] Confirm independent entities continue processing when one entity fails.
- [ ] Confirm the configured history period is used consistently for availability, health and SLA calculations.
- [ ] Confirm ticket creation and resolution obey configured categories, request source, requester and technician.
- [ ] Confirm unknown Grandstream alert severities are retained rather than guessed.
- [ ] Confirm firmware scheduling accepts only a verified official stable target and rejects stale, beta, malformed or untrusted targets.
- [ ] Confirm XLSX exports open correctly and ticket descriptions remain plain text.

Do not run destructive firmware actions, device resets or real production ticket generation as part of routine validation.

## 6. CI and security checks

The GitHub Actions checks validate the exact commit under review:

- PHP syntax and deterministic CLI tests across the declared stable PHP matrix.
- Source metadata and compatibility checks against `setup.php` and `plugin.xml`.
- A release ZIP build and archive-content audit.
- PHPStan level 8 with GLPI's PHPStan extension.
- Psalm security/taint analysis.
- GLPI-aware PHP lint with deprecated-syntax detection and TwigCS using GLPI's ruleset.
- The same static-analysis workflow against GLPI 11.0 and GLPI 12.0.
- CodeQL security-extended analysis for JavaScript and GitHub Actions.
- Gitleaks repository-history scanning and zizmor workflow auditing.
- Dependabot checks for GitHub Actions updates.

A passing CLI workflow does not replace the GLPI staging checks above. Review the CI result for the exact candidate commit; success on an earlier commit is not evidence for a later one.

## 7. Release acceptance

- [ ] All required CLI and package jobs pass on the exact candidate commit.
- [ ] Both GLPI 11.0 and GLPI 12.0 static-analysis jobs pass.
- [ ] Security analysis has no unresolved blocking findings.
- [ ] The final ZIP has one `gdmsintegration/` root and no development files.
- [ ] The exact ZIP artifact from CI is installed and tested on both staging core versions.
- [ ] Manual synchronization, SLA, firmware, ticket, export and upgrade checks are completed.
- [ ] The release notes and plugin version are reviewed separately from the test-infrastructure change.

This document is an acceptance checklist. A checked item must correspond to an actual executed test; the existence of a checklist is not evidence that a manual test has passed.
