#!/bin/bash
#
# Build a distributable ZIP of the Mediagraph WordPress plugin.
#
# The version is read from the plugin header, which is the single source of
# truth. 1.x hard-coded it in four separate places and readme.txt drifted three
# releases behind.

set -euo pipefail

cd "$(dirname "$0")"

PLUGIN_SLUG="mediagraph-assets"
MAIN_FILE="mediagraph-picker.php"
BUILD_DIR="build"
RELEASE_DIR="releases"

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' "${MAIN_FILE}" | head -1)"

if [ -z "${VERSION}" ]; then
  echo "Could not read Version from ${MAIN_FILE}" >&2
  exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION}"

# Every other place the version appears must agree with the header.
check_version() {
  local file="$1"
  local pattern="$2"
  local found

  found="$(grep -Eo "${pattern}" "${file}" | head -1 || true)"

  if [ -n "${found}" ] && ! echo "${found}" | grep -q "${VERSION}"; then
    echo "Version mismatch in ${file}: expected ${VERSION}, found '${found}'" >&2
    exit 1
  fi
}

check_version "${MAIN_FILE}" "MEDIAGRAPH_VERSION', '[0-9][^']*"
check_version "package.json" '"version": "[0-9][^"]*'
check_version "readme.txt" 'Stable tag: [0-9][^[:space:]]*'

echo "Installing dependencies"
npm ci --no-audit --no-fund

echo "Linting"
npm run lint

echo "Testing"
npm test

echo "Building assets"
npm run build

if [ ! -f admin/js/dist/mediagraph-picker.bundle.js ]; then
  echo "Build produced no bundle" >&2
  exit 1
fi

echo "Assembling package"
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}" "${RELEASE_DIR}"

# The package deliberately carries the unminified JavaScript sources and the
# tooling needed to rebuild the bundles from them: admin/js/src/, the webpack
# and babel configs, package.json and the lockfile. WordPress.org guideline 4
# requires that the human-readable source and build tools be available, and
# shipping them in the package itself is simpler than maintaining a second
# public mirror of this directory. admin/js/__tests__/ comes along so that
# `npm ci && npm run lint && npm test && npm run build` all work from nothing
# but the distributed ZIP.
#
# Only genuinely local or non-distributable things are stripped: dependencies,
# build scratch, sourcemaps, our own dev environment, and agent instructions.
rsync -a \
  --exclude='.*' \
  --exclude='node_modules/' \
  --exclude="${BUILD_DIR}/" \
  --exclude="${RELEASE_DIR}/" \
  --exclude='create_oauth_app.rb' \
  --exclude='docker-compose.yml' \
  --exclude='CLAUDE.md' \
  --exclude='CONTRIBUTING.md' \
  --exclude='*.log' \
  --exclude='*.map' \
  ./ "${BUILD_DIR}/${PLUGIN_SLUG}/"

ZIP_PATH="${RELEASE_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip"
rm -f "${ZIP_PATH}"

( cd "${BUILD_DIR}" && zip -qr "../${ZIP_PATH}" "${PLUGIN_SLUG}" )

rm -rf "${BUILD_DIR}"

echo "Built ${ZIP_PATH} ($(du -h "${ZIP_PATH}" | cut -f1))"
