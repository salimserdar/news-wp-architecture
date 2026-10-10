#!/usr/bin/env bash
# Delete generated sizes from local disk using a list-image-sizes.sh TSV.
# Does not change the database or R2. Without "apply" it only counts.
#
#   scripts/delete-image-sizes.sh /tmp/image-sizes-to-delete.tsv
#   scripts/delete-image-sizes.sh /tmp/image-sizes-to-delete.tsv apply 200
#   scripts/delete-image-sizes.sh /tmp/image-sizes-to-delete.tsv apply
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"
wp_cli eval-file "${REPO_DIR}/scripts/delete-image-sizes.php" "$@"
