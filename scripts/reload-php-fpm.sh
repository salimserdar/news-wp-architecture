#!/bin/bash
# Reload PHP-FPM only when the on-disk config is valid.
# A failed test leaves the running master untouched. Reloading an invalid
# pool exits the master (status 78) and every cache miss becomes a 502.
# If the service is already stopped, start it after a successful test.
set -euo pipefail

if ! php-fpm8.3 -t; then
  echo "PHP-FPM config test failed; leaving the running service untouched." >&2
  exit 1
fi

if systemctl is-active --quiet php8.3-fpm; then
  systemctl reload php8.3-fpm
else
  echo "PHP-FPM is not running; starting it."
  systemctl start php8.3-fpm
fi
