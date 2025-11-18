#!/bin/bash

# Mediagraph WordPress Plugin - Release Build Script
# Creates a production-ready ZIP file for distribution

set -e

VERSION="1.1.0"
PLUGIN_SLUG="mediagraph-picker"
BUILD_DIR="build"
RELEASE_DIR="releases"

echo "🚀 Building Mediagraph WordPress Plugin v${VERSION}"

# Clean previous builds
echo "🧹 Cleaning previous builds..."
rm -rf ${BUILD_DIR}
mkdir -p ${BUILD_DIR}/${PLUGIN_SLUG}
mkdir -p ${RELEASE_DIR}

# Install dependencies
echo "📦 Installing dependencies..."
npm install --production=false

# Build React assets
echo "⚛️  Building React assets..."
npm run build

# Copy plugin files
echo "📋 Copying plugin files..."
rsync -av \
  --exclude='node_modules/' \
  --exclude='admin/js/src/' \
  --exclude='build/' \
  --exclude='releases/' \
  --exclude='.git/' \
  --exclude='.gitignore' \
  --exclude='docker-compose.yml' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='webpack.config.js' \
  --exclude='.eslintrc.json' \
  --exclude='DEVELOPMENT-STATUS.md' \
  --exclude='BACKEND-SETUP.md' \
  --exclude='COMPLETION-SUMMARY.md' \
  --exclude='PKCE-REFACTOR-COMPLETE.md' \
  --exclude='create_oauth_app.rb' \
  --exclude='build-release.sh' \
  --exclude='*.log' \
  ./ ${BUILD_DIR}/${PLUGIN_SLUG}/

# Create ZIP
echo "📦 Creating ZIP file..."
# Remove old ZIP if exists
rm -f ${RELEASE_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip
cd ${BUILD_DIR}
zip -r ../${RELEASE_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip ${PLUGIN_SLUG}
cd ..

# Cleanup
echo "🧹 Cleaning up..."
rm -rf ${BUILD_DIR}

echo "✅ Build complete!"
echo "📦 Release file: ${RELEASE_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip"
echo "📊 File size: $(du -h ${RELEASE_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip | cut -f1)"
