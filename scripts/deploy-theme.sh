#!/bin/bash
# Copy the repo theme onto the VPS WordPress tree.
# Only tr724-news is synced, so other themes on the server stay.
#   sudo bash scripts/deploy-theme.sh

set -e

SOURCE="/opt/news-wp-architecture/wp/themes/tr724-news/"
TARGET="/var/www/html/wp-content/themes/tr724-news/"

echo "Deploying theme..."

sudo mkdir -p "$TARGET"
sudo rsync -av --delete "$SOURCE" "$TARGET"

echo "Fixing ownership..."
sudo chown -R www-data:www-data "$TARGET"
sudo find "$TARGET" -type d -exec chmod 755 {} +
sudo find "$TARGET" -type f -exec chmod 644 {} +

# Reload picks up the new files in OPcache without dropping in-flight requests.
echo "Reloading PHP-FPM..."
sudo systemctl reload php8.3-fpm

echo "Theme deployed successfully."
