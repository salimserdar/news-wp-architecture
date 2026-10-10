#!/usr/bin/env bash
# List generated attachment sizes on local disk. Does not delete.
#
#   scripts/list-image-sizes.sh > /tmp/image-sizes-to-delete.tsv
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"
wp_cli eval-file "${REPO_DIR}/scripts/list-image-sizes.php"
