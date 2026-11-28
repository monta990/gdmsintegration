# GDMS Integration 1.7.0 — Engineering handoff

**Status:** pull-request candidate; not yet released or tagged.

## Scope consolidated into 1.7.0

This branch consolidates the unreleased 1.6.11 work into one versioned change set. It combines dashboard/configuration UI refinements, CSP-aware external JavaScript, SIP account discovery and normalization, PBX association, initial dashboard synchronization, rate-limit resilience, and regression coverage.

## SIP account flow

- Query GDMS `POST /oapi/v1.0.0/device/account/info` and `POST /oapi/v1.0.0/device/account/status` through the existing signed authentication client.
- Normalize phone account lists and ATA FXS port lists. Merge configuration/status records using account ID where available, otherwise a stable extension/server identity.
- Treat a device-level `accountStatus` as device metadata, never as an account row.
- Preserve one normalized record per SIP account and count each registered account once.
- Persist redacted snapshots and check timestamps. Successful snapshots are reused for six hours; failure retry timing remains shorter. A failed or rate-limited API response must not replace a good snapshot with an empty list.
- Never persist or log SIP passwords, authentication usernames, tokens, client secrets, or URI user-info.

## PBX association and modal display

- Resolve every SIP account independently. A concrete SIP server IP must match exactly one managed UCM private IP, or a RemoteConnect hostname must encode a managed UCM MAC.
- Explicit PBX identifiers remain supported. Ambiguous duplicate IPs, generic hostnames and unmatched values remain unassigned.
- WAN/public-IP equality and shared GDMS site labels are diagnostic only; they do not prove SIP registration.
- Each SIP account row displays its resolved PBX asset name (link to the GLPI asset) and a validated UCM management-IP link. The separate device-level PBX row is hidden when the SIP account table is present to avoid duplicate information.

## Dashboard synchronization

- A first dashboard visit or stale `last_sync_at` triggers the regular full sync only when provider credentials are complete and the logged-in user has the existing update permission.
- A fresh entity does not start another sync on every dashboard visit.
- The per-entity lock is authoritative; a second tab or worker that finds the lock busy must not enter a browser reload loop.

## GDMS rate-limit resilience and performance

- Detect HTTP 429 and application-level quota messages such as `Too many requests from this IP`.
- Retry at most once for a recognized application-level quota response without needlessly refreshing a valid token.
- Honor `Retry-After` where provided and persist a provider-specific cooldown in the plugin's database lock table so web and cron workers sharing the database observe the same pause.
- Use a bounded cURL-multi pool of four concurrent SIP requests. The exact throughput must still be measured against the target GDMS account; a public concurrency quota is not assumed.
- On a failed/incomplete device-list request, disable removal detection for that cycle and preserve the last known inventory.

## Schema and upgrade notes

The install/update migration adds the SIP account snapshot fields and the SIP/PBX identity fields idempotently through GLPI's `Migration` API. Upgrade through GLPI's normal plugin update/activation workflow. Do not uninstall during an upgrade: uninstall intentionally removes plugin-owned tables and data. The activation guard is idempotent and only verifies the SIP snapshot columns.

## Validation commands

```bash
find setup.php hook.php src tests .github -type f -name '*.php' -print0 | sort -z | xargs -0 -r -n1 php -l
php tests/run.php
php tests/pbx_association_regression.php
php tests/gdms_rate_limit_regression.php
php tests/csp_readiness_regression.php
php tests/validate-release.php
node --check public/js/config.js
node --check public/js/dashboard.js
bash scripts/package.sh ./dist
```

The GitHub Actions matrix covers the repository's supported PHP versions through PHP 8.6, and its pull-request workflow remains the authoritative full CI result. Live GDMS behavior, real cURL-multi timing, and GLPI 11/12 browser acceptance still require a configured test instance.

## Privacy cleanup

Regression fixtures use generic device labels, locally administered test MACs, reserved documentation IP ranges, and synthetic extension numbers. No customer PBX labels, phone labels, real inventory identifiers, or live network addresses are embedded in this handoff.