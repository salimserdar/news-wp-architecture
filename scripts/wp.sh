#!/usr/bin/env bash
# WP-CLI wrapper.   scripts/wp.sh plugin list
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
wp_cli "$@"
