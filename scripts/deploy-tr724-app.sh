#!/bin/bash
# Copy the tr724-app plugin onto the VPS WordPress tree and activate it.
# Only this plugin is synced, so other plugins on the server stay.
#   sudo bash scripts/deploy-tr724-app.sh

set -e

SOURCE="/opt/news-wp-architecture/wp/plugins/tr724-app/"
TARGET="/var/www/html/wp-content/plugins/tr724-app/"

echo "Deploying tr724-app..."

sudo mkdir -p "$TARGET"
sudo rsync -av --delete "$SOURCE" "$TARGET"
# A previous deploy copied the API contract into the public plugin directory.
sudo rm -f "${TARGET}openapi.yaml"

echo "Fixing ownership..."
sudo chown -R www-data:www-data "$TARGET"
sudo find "$TARGET" -type d -exec chmod 755 {} +
sudo find "$TARGET" -type f -exec chmod 644 {} +

# Reload picks up the new files in OPcache without dropping in-flight requests.
# Test first: a bad pool config makes reload kill the master.
echo "Reloading PHP-FPM..."
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if ! sudo bash "${ROOT}/scripts/reload-php-fpm.sh"; then
  echo "Plugin files are in place. PHP-FPM was not reloaded, so running workers still have the previous code." >&2
  exit 1
fi

# shellcheck source=lib.sh
source "${ROOT}/scripts/lib.sh"
echo "Activating tr724-app..."
wp_cli plugin activate tr724-app

echo "tr724-app deployed successfully."
