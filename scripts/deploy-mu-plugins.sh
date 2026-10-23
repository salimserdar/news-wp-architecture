#!/bin/bash
# Copy repo mu-plugins onto the VPS WordPress tree.
#   sudo bash scripts/deploy-mu-plugins.sh

set -e

SOURCE="/opt/news-wp-architecture/wp/mu-plugins/"
TARGET="/var/www/html/wp-content/mu-plugins/"

echo "Deploying MU plugins..."

sudo mkdir -p "$TARGET"
sudo rsync -av --delete "$SOURCE" "$TARGET"

echo "Fixing ownership..."
sudo chown -R www-data:www-data "$TARGET"
sudo find "$TARGET" -type d -exec chmod 755 {} +
sudo find "$TARGET" -type f -exec chmod 644 {} +

# Reload picks up the new files in OPcache without dropping in-flight requests.
# Test first: a bad pool config makes reload kill the master.
echo "Reloading PHP-FPM..."
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if ! sudo bash "${ROOT}/scripts/reload-php-fpm.sh"; then
  echo "MU plugin files are in place. PHP-FPM was not reloaded, so running workers still have the previous code." >&2
  exit 1
fi

echo "MU plugins deployed successfully."
