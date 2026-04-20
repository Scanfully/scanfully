#!/usr/bin/env bash
set -euo pipefail

if [ -z "${1:-}" ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 1.4.0"
    exit 1
fi

VERSION="$1"

# Validate semver format
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: version must be in semver format (e.g. 1.4.0)"
    exit 1
fi

# Resolve script directory to plugin root
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

echo "Preparing release: $VERSION"

echo "==> Updating readme.txt (Stable tag)..."
sed -i '' "s/Stable tag:.*/Stable tag: $VERSION/" readme.txt
echo "==> Complete"

echo "==> Updating scanfully.php (Plugin header)..."
sed -i '' "s/^ \* Version:.*/ * Version:     $VERSION/" scanfully.php
echo "==> Complete"

echo "==> Updating scanfully.php (SCANFULLY_VERSION constant)..."
sed -i '' "s/define( 'SCANFULLY_VERSION', '.*' );/define( 'SCANFULLY_VERSION', '$VERSION' );/" scanfully.php
echo "==> Complete"

echo
echo "Release preparation complete! Don't forget to:"
echo "- Add a CHANGELOG entry to readme.txt"
echo "- Commit all the changes and push!"
