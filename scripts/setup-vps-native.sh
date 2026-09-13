#!/usr/bin/env bash
# One-time VPS preparation for Ubuntu 24.04 LTS. Native packages — no Docker,
# no Redis, no FastCGI cache. Run as root:
#   bash scripts/setup-vps-native.sh
#
# Optional env (or a repo-root .env):
#   SSH_PORT=22
#   SITE_DOMAIN=www.example.com
#   DB_NAME / DB_USER / DB_PASSWORD / DB_TABLE_PREFIX
#   DB_BUFFER_POOL=8G          # default scales with RAM
#   PHP_MAX_CHILDREN=20
#   WEB_OPEN=1                 # 1 = 80/443 from anywhere (default, first boot)
#                              # 0 = Cloudflare IP ranges only
#   WP_ROOT=/var/www/html
#
# Docker Compose alternative: scripts/setup-vps.sh
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "run as root"; exit 1; }
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -f "${REPO_DIR}/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "${REPO_DIR}/.env"
  set +a
fi

SSH_PORT="${SSH_PORT:-22}"
WP_ROOT="${WP_ROOT:-/var/www/html}"
WEB_OPEN="${WEB_OPEN:-1}"
DB_NAME="${DB_NAME:-wordpress}"
DB_USER="${DB_USER:-wordpress}"
DB_TABLE_PREFIX="${DB_TABLE_PREFIX:-wp_}"
PHP_MAX_CHILDREN="${PHP_MAX_CHILDREN:-20}"
SITE_DOMAIN="${SITE_DOMAIN:-}"

if [[ -z "${DB_PASSWORD:-}" ]]; then
  DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
  GENERATED_DB_PASSWORD=1
else
  GENERATED_DB_PASSWORD=0
fi

if [[ -z "${DB_BUFFER_POOL:-}" ]]; then
  mem_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo)"
  if (( mem_kb < 4000000 )); then
    DB_BUFFER_POOL=512M
  elif (( mem_kb < 16000000 )); then
    DB_BUFFER_POOL=2G
  else
    DB_BUFFER_POOL=8G
  fi
fi

echo "==> Base packages"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl gnupg ufw fail2ban \
  unattended-upgrades apt-listchanges htop iotop ncdu jq rsync zstd unzip openssl rclone

echo "==> nginx + PHP 8.3-FPM + MariaDB"
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  nginx mariadb-server \
  php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl \
  php8.3-zip php8.3-gd php8.3-imagick php8.3-intl php8.3-bcmath

echo "==> WP-CLI"
if ! command -v wp >/dev/null; then
  curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod +x /usr/local/bin/wp
fi

echo "==> Kernel tuning"
cat > /etc/sysctl.d/99-news-wp.conf <<'EOF'
net.core.somaxconn = 65535
net.core.netdev_max_backlog = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_fin_timeout = 15
net.ipv4.tcp_keepalive_time = 300
net.ipv4.tcp_slow_start_after_idle = 0
fs.file-max = 2097152
fs.inotify.max_user_watches = 524288
vm.swappiness = 10
vm.overcommit_memory = 1
vm.dirty_ratio = 20
vm.dirty_background_ratio = 5
EOF
sysctl --system >/dev/null

echo "==> Swap (2 GB, emergency only)"
if ! swapon --show | grep -q '^/swapfile'; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "==> Firewall"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw limit "${SSH_PORT}/tcp" comment 'SSH (rate limited)'
bash "${REPO_DIR}/scripts/cloudflare-ips.sh" --nginx
if [[ "${WEB_OPEN}" == "0" ]]; then
  echo "    80/443: Cloudflare IP ranges only"
  bash "${REPO_DIR}/scripts/cloudflare-ips.sh" --ufw
else
  echo "    80/443: open to the world (set WEB_OPEN=0 to lock to Cloudflare)"
  ufw allow 80/tcp comment 'http'
  ufw allow 443/tcp comment 'https'
fi
ufw --force enable

echo "==> fail2ban (sshd)"
cat > /etc/fail2ban/jail.local <<EOF
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
[sshd]
enabled = true
port    = ${SSH_PORT}
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

echo "==> Unattended security upgrades"
dpkg-reconfigure -f noninteractive unattended-upgrades

echo "==> Weekly Cloudflare IP refresh"
if [[ "${WEB_OPEN}" == "0" ]]; then
  cat > /etc/cron.weekly/cloudflare-ips <<EOF
#!/bin/sh
cd "${REPO_DIR}" && bash scripts/cloudflare-ips.sh --ufw --nginx \\
  && nginx -s reload >/dev/null 2>&1 || true
EOF
else
  cat > /etc/cron.weekly/cloudflare-ips <<EOF
#!/bin/sh
cd "${REPO_DIR}" && bash scripts/cloudflare-ips.sh --nginx \\
  && nginx -s reload >/dev/null 2>&1 || true
EOF
fi
chmod +x /etc/cron.weekly/cloudflare-ips

echo "==> PHP-FPM pool + ini"
if [[ -f /etc/php/8.3/fpm/pool.d/www.conf && ! -L /etc/php/8.3/fpm/pool.d/www.conf ]]; then
  mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.dist
fi
sed -E "s/^pm.max_children = .*/pm.max_children = ${PHP_MAX_CHILDREN}/" \
  "${REPO_DIR}/config/php/pool-www-native.conf" \
  > /etc/php/8.3/fpm/pool.d/www.conf
# Redis extension is not installed; drop its ini keys to avoid warnings.
grep -v -E '^[[:space:]]*redis\.' "${REPO_DIR}/config/php/conf.d/zz-wp.ini" \
  > /etc/php/8.3/fpm/conf.d/99-wp.ini
touch /var/log/php8.3-fpm-slow.log
chown www-data:www-data /var/log/php8.3-fpm-slow.log

echo "==> MariaDB database + tuning"
systemctl enable --now mariadb
install -m 0644 "${REPO_DIR}/config/mariadb/zz-tuning.cnf" \
  /etc/mysql/mariadb.conf.d/zz-tuning.cnf
cat > /etc/mysql/mariadb.conf.d/zz-buffer-pool.cnf <<EOF
[mysqld]
innodb_buffer_pool_size = ${DB_BUFFER_POOL}
EOF
if ! systemctl restart mariadb; then
  echo "    MariaDB rejected the tuning file — starting with distro defaults"
  rm -f /etc/mysql/mariadb.conf.d/zz-tuning.cnf /etc/mysql/mariadb.conf.d/zz-buffer-pool.cnf
  systemctl restart mariadb
fi
mysql -e "DELETE FROM mysql.user WHERE User=''; DROP DATABASE IF EXISTS test; DELETE FROM mysql.db WHERE Db='test' OR Db='test\\\\_%'; FLUSH PRIVILEGES;" || true
PASS_ESC="${DB_PASSWORD//\'/\'\'}"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${PASS_ESC}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${PASS_ESC}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> nginx configs (no FastCGI cache)"
if [[ "${WP_ROOT}" != "/var/www/html" ]]; then
  echo "    warning: nginx root is /var/www/html; WP_ROOT=${WP_ROOT} is not used by site.conf"
fi
mkdir -p /etc/nginx/snippets /etc/nginx/certs /etc/nginx/conf.d \
  /etc/nginx/sites-available /etc/nginx/sites-enabled

# First version of this script replaced Ubuntu's nginx.conf with one that used
# `http2 on;` (invalid on nginx 1.24), so `nginx -t` failed and reload never ran.
# Put the distro file back and use sites-enabled like a normal Ubuntu install.
if [[ -f /etc/nginx/nginx.conf.dist ]]; then
  echo "    restoring distro /etc/nginx/nginx.conf"
  mv -f /etc/nginx/nginx.conf.dist /etc/nginx/nginx.conf
fi
if ! grep -q 'include /etc/nginx/sites-enabled' /etc/nginx/nginx.conf; then
  echo "    writing a stock Ubuntu-style nginx.conf"
  cat > /etc/nginx/nginx.conf <<'EOF'
user www-data;
worker_processes auto;
pid /run/nginx.pid;
error_log /var/log/nginx/error.log;
include /etc/nginx/modules-enabled/*.conf;
events {
    worker_connections 4096;
    multi_accept on;
}
http {
    sendfile on;
    tcp_nopush on;
    types_hash_max_size 2048;
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log;
    gzip on;
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
EOF
fi

rm -f /etc/nginx/conf.d/site.conf /etc/nginx/conf.d/default.conf
cp -a "${REPO_DIR}/config/nginx/native/http.conf" /etc/nginx/conf.d/00-news-wp.conf
cp -a "${REPO_DIR}/config/nginx/native/site.conf" /etc/nginx/sites-available/wordpress
# 000- so this vhost is loaded before Ubuntu's "default" if it comes back.
rm -f /etc/nginx/sites-enabled/default \
      /etc/nginx/sites-enabled/default.conf \
      /etc/nginx/sites-enabled/wordpress
if [[ -f /etc/nginx/sites-available/default ]]; then
  mv -f /etc/nginx/sites-available/default /etc/nginx/sites-available/default.disabled
fi
ln -sfn /etc/nginx/sites-available/wordpress /etc/nginx/sites-enabled/000-wordpress

# Snippets stay symlinked so scripts/cloudflare-ips.sh --nginx updates take effect.
for snippet in fastcgi-php.conf security-headers.conf wordpress-hardening.conf tls.conf cloudflare-realip.conf; do
  ln -sfn "${REPO_DIR}/config/nginx/snippets/${snippet}" "/etc/nginx/snippets/${snippet}"
done

if [[ -s "${REPO_DIR}/config/nginx/certs/origin.pem" && -s "${REPO_DIR}/config/nginx/certs/origin.key" ]]; then
  cp -a "${REPO_DIR}/config/nginx/certs/origin.pem" /etc/nginx/certs/origin.pem
  cp -a "${REPO_DIR}/config/nginx/certs/origin.key" /etc/nginx/certs/origin.key
  chmod 600 /etc/nginx/certs/origin.key
else
  echo "    no Origin CA cert yet — generating a self-signed pair"
  openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout /etc/nginx/certs/origin.key -out /etc/nginx/certs/origin.pem \
    -subj "/CN=${SITE_DOMAIN:-localhost}" \
    -addext "subjectAltName=DNS:${SITE_DOMAIN:-localhost},DNS:localhost" >/dev/null 2>&1
  chmod 600 /etc/nginx/certs/origin.key
fi

echo "==> WordPress core"
# Root shells often use umask 077; that would extract 600 files and 403 nginx.
umask 022
mkdir -p "${WP_ROOT}"
if [[ ! -f "${WP_ROOT}/wp-load.php" ]]; then
  curl -fsSL https://wordpress.org/latest.tar.gz | tar xz -C /tmp
  rsync -a /tmp/wordpress/ "${WP_ROOT}/"
  rm -rf /tmp/wordpress
fi
# Ubuntu welcome page would otherwise be served instead of index.php.
rm -f "${WP_ROOT}/index.nginx-debian.html" "${WP_ROOT}/index.html"
chown -R www-data:www-data "${WP_ROOT}"
find "${WP_ROOT}" -type d -exec chmod 755 {} +
find "${WP_ROOT}" -type f -exec chmod 644 {} +
chmod 755 "${WP_ROOT}"
if [[ -f "${WP_ROOT}/wp-config.php" ]]; then
  chmod 640 "${WP_ROOT}/wp-config.php"
  chown www-data:www-data "${WP_ROOT}/wp-config.php"
fi

if [[ ! -f "${WP_ROOT}/wp-config.php" ]]; then
  echo "==> wp-config.php"
  extra_php="$(cat <<PHP
define('DISALLOW_FILE_EDIT', true);
define('FORCE_SSL_ADMIN', true);
define('WP_POST_REVISIONS', 10);
define('EMPTY_TRASH_DAYS', 7);
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
if (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    \$_SERVER['HTTPS'] = 'on';
}
PHP
)"
  if [[ -n "${SITE_DOMAIN}" ]]; then
    extra_php="$(cat <<PHP
define('WP_HOME',    'https://${SITE_DOMAIN}');
define('WP_SITEURL', 'https://${SITE_DOMAIN}');
${extra_php}
PHP
)"
  fi
  wp config create --allow-root --path="${WP_ROOT}" \
    --dbname="${DB_NAME}" \
    --dbuser="${DB_USER}" \
    --dbpass="${DB_PASSWORD}" \
    --dbhost="localhost" \
    --dbprefix="${DB_TABLE_PREFIX}" \
    --skip-check \
    --extra-php <<<"${extra_php}"
  chown www-data:www-data "${WP_ROOT}/wp-config.php"
  chmod 640 "${WP_ROOT}/wp-config.php"
fi

echo "==> Enable services"
systemctl enable --now php8.3-fpm nginx
systemctl restart php8.3-fpm
nginx -t
systemctl restart nginx

echo "==> Verify"
if [[ ! -f "${WP_ROOT}/index.php" ]]; then
  echo "    ERROR: ${WP_ROOT}/index.php is missing — WordPress did not unpack"
  exit 1
fi
echo "    sites-enabled: $(ls -1 /etc/nginx/sites-enabled 2>/dev/null | tr '\n' ' ')"
echo "    $(curl -sS -o /dev/null -w 'http  %{http_code}  redirect=%{redirect_url}\n' http://127.0.0.1/ || true)"
echo "    $(curl -skS -o /dev/null -w 'https %{http_code}  redirect=%{redirect_url}\n' https://127.0.0.1/ || true)"
http_body="$(curl -sS http://127.0.0.1/ || true)"
if echo "${http_body}" | grep -q 'Welcome to nginx'; then
  echo "    ERROR: still serving the Ubuntu welcome page. Check: nginx -t && ls -l /etc/nginx/sites-enabled"
  exit 1
fi
http_code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/ || true)"
if [[ "${http_code}" == "403" ]]; then
  echo "    ERROR: HTTP 403. Last nginx errors:"
  tail -n 20 /var/log/nginx/error.log || true
  ls -ld "${WP_ROOT}" "${WP_ROOT}/index.php" || true
  exit 1
fi

cred_file="/root/news-wp-native-credentials"
umask 077
cat > "${cred_file}" <<EOF
# Written by scripts/setup-vps-native.sh on $(date -u +%F)
SITE_DOMAIN=${SITE_DOMAIN}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
DB_TABLE_PREFIX=${DB_TABLE_PREFIX}
DB_BUFFER_POOL=${DB_BUFFER_POOL}
WP_ROOT=${WP_ROOT}
EOF
chmod 600 "${cred_file}"

mkdir -p "${REPO_DIR}"/{import,backups,logs}

if [[ -n "${GCS_BUCKET:-}" ]]; then
  echo "==> Cloud Storage tools (rclone env_auth + gcsfuse)"
  bash "${REPO_DIR}/scripts/gcs.sh" install
  bash "${REPO_DIR}/scripts/gcs.sh" rclone-config || true
fi

echo
echo "Done (native WordPress: nginx + PHP-FPM + MariaDB, no cache, no Docker)."
echo "  credentials: ${cred_file}"
[[ "${GENERATED_DB_PASSWORD}" == "1" ]] && echo "  generated DB_PASSWORD (saved in that file)"
echo
echo "Next:"
echo "  1. Put a Cloudflare Origin CA cert in /etc/nginx/certs/origin.{pem,key} (optional but recommended)"
echo "  2. Point DNS at this VPS and open https://${SITE_DOMAIN:-<your-domain>}/wp-admin/install.php"
echo "  3. Settings → Permalinks → Post name"
echo
echo "Lock 80/443 to Cloudflare later:"
echo "  WEB_OPEN=0 bash scripts/setup-vps-native.sh"
echo "  (or:  ufw delete allow 80/tcp; ufw delete allow 443/tcp;"
echo "        bash scripts/cloudflare-ips.sh --ufw && nginx -s reload)"
