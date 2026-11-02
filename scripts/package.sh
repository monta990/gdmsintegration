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

VERSION="$(sed -nE "s/.*PLUGIN_GDMSINTEGRATION_VERSION', '([^']+)'.*/\\1/p" "$ROOT/setup.php" | head -n 1)"
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

for entry in \
    gdmsintegration/setup.php \
    gdmsintegration/hook.php \
    gdmsintegration/plugin.xml \
    gdmsintegration/src/Sync.php \
    gdmsintegration/src/Utils.php \
    gdmsintegration/templates/dashboard.html.twig \
    gdmsintegration/locales/es_MX.mo \
    gdmsintegration/public/js/vis-network.min.js
do
    if ! unzip -Z1 "$ARCHIVE" | grep -qx "$entry"; then
        echo "Required installation file missing from archive: $entry" >&2
        exit 1
    fi
done

echo "Created and validated $ARCHIVE"
