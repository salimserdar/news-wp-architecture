#!/bin/bash
# Copy repo mu-plugins onto the VPS WordPress tree.
#   sudo bash scripts/deploy-mu-plugins.sh

set -e

SOURCE="/opt/news-wp-architecture/wp/mu-plugins/"
TARGET="/var/www/html/wp-content/mu-plugins/"

echo "Deploying MU plugins..."

sudo rsync -av --delete "$SOURCE" "$TARGET"

echo "MU plugins deployed successfully."
