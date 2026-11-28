#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${1:-$ROOT/dist}"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP CLI is required to read the plugin version." >&2
    exit 1
fi
if ! command -v zip >/dev/null 2>&1 || ! command -v unzip >/dev/null 2>&1; then
    echo "zip and unzip are required to build and validate the installation archive." >&2
    exit 1
fi

# A clean checkout does not contain node_modules. Reproduce the pinned browser
# bundle and its source-only integrity manifest before assembling the installable ZIP.
ASSET_VERSION="$(node -e 'const p=require(process.argv[1]); process.stdout.write(p.dependencies?.["vis-network"] || "")' "$ROOT/package.json" 2>/dev/null || true)"
ASSET_READY=false
if [[ -n "$ASSET_VERSION" && -f "$ROOT/public/js/vis-network.min.js" && -f "$ROOT/public/js/vis-network.manifest.json" ]]; then
    ASSET_READY="$(node - "$ROOT/public/js/vis-network.manifest.json" "$ROOT/public/js/vis-network.min.js" "$ASSET_VERSION" <<'NODE'
const fs = require('node:fs');
const crypto = require('node:crypto');
const [manifestPath, bundlePath, expectedVersion] = process.argv.slice(2);
try {
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const digest = crypto.createHash('sha256').update(fs.readFileSync(bundlePath)).digest('hex');
  process.stdout.write(String(manifest.version === expectedVersion && manifest.sha256 === digest));
} catch {
  process.stdout.write('false');
}
NODE
)"
fi
if [[ "$ASSET_READY" != "true" ]]; then
    if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
        echo "Node.js and npm are required to stage the pinned vis-network asset from a clean checkout." >&2
        exit 1
    fi
    (cd "$ROOT" && npm ci --ignore-scripts --no-audit && npm audit --audit-level=moderate)
    bash "$ROOT/scripts/update-vis-network.sh"
fi

VERSION="$(php -r 'require $argv[1]; echo PLUGIN_GDMSINTEGRATION_VERSION;' "$ROOT/setup.php")"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Could not read a valid semantic version from setup.php." >&2
    exit 1
fi

if ! grep -Fq "<num>$VERSION</num>" "$ROOT/plugin.xml"; then
    echo "plugin.xml does not contain version $VERSION." >&2
    exit 1
fi
if ! grep -Fq "gdmsintegration-$VERSION.zip" "$ROOT/plugin.xml"; then
    echo "plugin.xml download URL is not synchronized with version $VERSION." >&2
    exit 1
fi

mkdir -p "$DIST_DIR"
DIST_DIR="$(cd "$DIST_DIR" && pwd)"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/gdmsintegration-package.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

PACKAGE_ROOT="$WORK_DIR/gdmsintegration"
mkdir -p "$PACKAGE_ROOT"

# Copy the existing plugin layout explicitly. Do not zip the repository root:
# tests, scripts, workflows and development configuration must remain private
# to the repository and must never be installed into GLPI.
cp -a \
    "$ROOT/CHANGELOG.md" \
    "$ROOT/LICENSE" \
    "$ROOT/README.md" \
    "$ROOT/THIRD-PARTY-NOTICES.md" \
    "$ROOT/config.png" \
    "$ROOT/hook.php" \
    "$ROOT/logo.png" \
    "$ROOT/plugin.xml" \
    "$ROOT/setup.php" \
    "$ROOT/user.png" \
    "$PACKAGE_ROOT/"

cp -a \
    "$ROOT/locales" \
    "$ROOT/public" \
    "$ROOT/src" \
    "$ROOT/templates" \
    "$PACKAGE_ROOT/"

# The manifest is source-only build metadata, not a browser runtime asset.
# Complete third-party license texts are consolidated in the root notice file.
rm -f \
    "$PACKAGE_ROOT/public/js/vis-network.manifest.json" \
    "$PACKAGE_ROOT/public/js/vis-network.LICENSE-APACHE-2.0" \
    "$PACKAGE_ROOT/public/js/vis-network.LICENSE-MIT"

ARCHIVE="$DIST_DIR/gdmsintegration-$VERSION.zip"
rm -f "$ARCHIVE"
(
    cd "$WORK_DIR"
    zip -qr "$ARCHIVE" gdmsintegration
)

# Keep the plugin manager's expected single-folder layout and reject any
# development-only paths if this allow-list is changed in a future commit.
if unzip -Z1 "$ARCHIVE" | grep -Evq '^gdmsintegration(/|$)'; then
    echo "Unexpected archive entry outside the gdmsintegration/ root." >&2
    exit 1
fi

if unzip -Z1 "$ARCHIVE" | grep -Eq '^gdmsintegration/(tests|scripts|\.github|vendor)(/|$)'; then
    echo "Development tooling was included in the installation archive." >&2
    exit 1
fi

if unzip -Z1 "$ARCHIVE" | grep -Eq '\.(log|cache)$|(^|/)\.DS_Store$|(^|/)Thumbs\.db$'; then
    echo "Unexpected local or temporary file found in the installation archive." >&2
    exit 1
fi

# The installable public/js directory contains only the browser runtime bundle.
public_js_entries="$(unzip -Z1 "$ARCHIVE" | grep -E '^gdmsintegration/public/js/[^/]+

for entry in \
    gdmsintegration/THIRD-PARTY-NOTICES.md \
    gdmsintegration/setup.php \
    gdmsintegration/hook.php \
    gdmsintegration/plugin.xml \
    gdmsintegration/src/Sync.php \
    gdmsintegration/src/Utils.php \
    gdmsintegration/templates/dashboard.html.twig \
    gdmsintegration/locales/es_MX.mo \
    gdmsintegration/public/js/config.js \
    gdmsintegration/public/js/dashboard.js \
    gdmsintegration/public/js/vis-network.min.js
do
    if ! unzip -Z1 "$ARCHIVE" | grep -qx "$entry"; then
        echo "Required installation file missing from archive: $entry" >&2
        exit 1
    fi
done

echo "Created and validated $ARCHIVE"
 || true)"
expected_public_js_entries="$(printf '%s\n' \
    gdmsintegration/public/js/config.js \
    gdmsintegration/public/js/dashboard.js \
    gdmsintegration/public/js/vis-network.min.js | sort)"
actual_public_js_entries="$(printf '%s\n' "${public_js_entries}" | sed '/^$/d' | sort)"
if [[ "$actual_public_js_entries" != "$expected_public_js_entries" ]]; then
    printf 'Unexpected files in installable public/js directory:\n%s\n' "${public_js_entries:-<none>}" >&2
    exit 1
fi

for entry in \
    gdmsintegration/THIRD-PARTY-NOTICES.md \
    gdmsintegration/setup.php \
    gdmsintegration/hook.php \
    gdmsintegration/plugin.xml \
    gdmsintegration/src/Sync.php \
    gdmsintegration/src/Utils.php \
    gdmsintegration/templates/dashboard.html.twig \
    gdmsintegration/locales/es_MX.mo \
    gdmsintegration/public/js/config.js \
    gdmsintegration/public/js/dashboard.js \
    gdmsintegration/public/js/vis-network.min.js
do
    if ! unzip -Z1 "$ARCHIVE" | grep -qx "$entry"; then
        echo "Required installation file missing from archive: $entry" >&2
        exit 1
    fi
done

echo "Created and validated $ARCHIVE"
