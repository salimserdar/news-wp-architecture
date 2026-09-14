# Shared by other scripts. Source from a file in scripts/:
#   # shellcheck source=lib.sh
#   source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -f "${REPO_DIR}/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "${REPO_DIR}/.env"
  set +a
fi

WP_ROOT="${WP_ROOT:-/var/www/html}"
NGINX_CACHE="${NGINX_CACHE:-/var/cache/nginx/wp}"
NGINX_ACCESS_LOG="${NGINX_ACCESS_LOG:-/var/log/nginx/access.log}"

wp_cli() {
  if [[ ${EUID:-$(id -u)} -eq 0 ]]; then
    sudo -u www-data -- wp --path="${WP_ROOT}" "$@"
  else
    wp --path="${WP_ROOT}" "$@"
  fi
}
