#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$(dirname "$(readlink -f "$0")")")" && pwd)"
PACKAGE_JSON="$ROOT/package.json"
NODE_MODULES="$ROOT/node_modules/vis-network"

if ! command -v node >/dev/null 2>&1; then
    echo "Node.js is required to update the pinned vis-network asset." >&2
    exit 1
fi

EXPECTED_VERSION="$(node -e 'const p=require(process.argv[1]); process.stdout.write(p.dependencies?.["vis-network"] || "")' "$PACKAGE_JSON")"
if [[ ! "$EXPECTED_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "package.json must pin an exact vis-network semantic version." >&2
    exit 1
fi

BUNDLE="$NODE_MODULES/standalone/umd/vis-network.min.js"
if [[ ! -f "$BUNDLE" ]]; then
    echo "vis-network UMD bundle not found. Run npm ci first." >&2
    exit 1
fi

mkdir -p "$ROOT/public/js"
cp "$BUNDLE" "$ROOT/public/js/vis-network.min.js"
# Remove legacy generated copies; full license texts live in THIRD-PARTY-NOTICES.md.
rm -f "$ROOT/public/js/vis-network.LICENSE-APACHE-2.0" "$ROOT/public/js/vis-network.LICENSE-MIT"
SHA256="$(sha256sum "$ROOT/public/js/vis-network.min.js" | awk '{print $1}')"
node - "$ROOT/public/js/vis-network.manifest.json" "$EXPECTED_VERSION" "$SHA256" <<'NODE'
const fs = require('node:fs');
const [path, version, sha256] = process.argv.slice(2);
const manifest = {
  name: 'vis-network',
  version,
  source: 'https://www.npmjs.com/package/vis-network/v/' + version,
  repository: 'https://github.com/visjs/vis-network',
  bundle: 'standalone/umd/vis-network.min.js',
  license: '(Apache-2.0 OR MIT)',
  sha256
};
fs.writeFileSync(path, JSON.stringify(manifest, null, 2) + '\n');
NODE

echo "Updated vis-network $EXPECTED_VERSION (SHA-256: $SHA256); licenses are in THIRD-PARTY-NOTICES.md."
