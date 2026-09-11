#!/usr/bin/env bash
# WP-CLI wrapper.   scripts/wp.sh plugin list
# Runs inside a throwaway container with the same volumes/env as PHP-FPM.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
exec docker compose run --rm -T wpcli wp "$@"
