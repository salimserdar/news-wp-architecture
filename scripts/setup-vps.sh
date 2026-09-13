#!/usr/bin/env bash
# One-time VPS preparation for Ubuntu 24.04 LTS. Run as root:
#   bash scripts/setup-vps.sh
#
# Native (no Docker, no cache) alternative: scripts/setup-vps-native.sh
#
# - installs Docker Engine + Compose plugin
# - kernel/network tuning for a busy web origin
# - 2 GB swap (emergency buffer only)
# - ufw: SSH + 80/443 from Cloudflare IP ranges ONLY
# - fail2ban for SSH
# - weekly refresh of Cloudflare IP ranges (firewall + nginx real_ip snippet)
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

echo "==> Base packages"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl gnupg ufw fail2ban \
  unattended-upgrades apt-listchanges htop iotop ncdu jq rsync zstd unzip rclone

echo "==> Docker Engine"
if ! command -v docker >/dev/null; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
# Keep container logs bounded even if a service forgets the logging block.
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "50m", "max-file": "5" },
  "live-restore": true
}
EOF
systemctl enable --now docker
systemctl restart docker

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

echo "==> Firewall: SSH + Cloudflare-only web"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw limit "${SSH_PORT}/tcp" comment 'SSH (rate limited)'
# NOTE: Docker-published ports bypass ufw. The effective protection for 80/443
# is the DOCKER-USER chain rule (--iptables); --ufw is kept for host services.
bash "${REPO_DIR}/scripts/cloudflare-ips.sh" --ufw --iptables --nginx
ufw --force enable
# Re-apply the DOCKER-USER rule after every boot (iptables rules are not persistent).
cat > /etc/systemd/system/cloudflare-only.service <<EOF
[Unit]
Description=Restrict Docker-published ports 80/443 to Cloudflare IP ranges
After=docker.service network-online.target
Requires=docker.service
[Service]
Type=oneshot
ExecStart=/bin/bash ${REPO_DIR}/scripts/cloudflare-ips.sh --iptables
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable cloudflare-only.service

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
cat > /etc/cron.weekly/cloudflare-ips <<EOF
#!/bin/sh
cd "${REPO_DIR}" && bash scripts/cloudflare-ips.sh --ufw --iptables --nginx && docker compose exec -T nginx nginx -s reload >/dev/null 2>&1 || true
EOF
chmod +x /etc/cron.weekly/cloudflare-ips

echo "==> Nightly backup at 03:30"
cat > /etc/cron.d/news-wp-backup <<EOF
30 3 * * * root cd ${REPO_DIR} && nice -n 10 ionice -c3 bash scripts/backup.sh >> ${REPO_DIR}/logs/backup.log 2>&1
EOF

mkdir -p "${REPO_DIR}"/{wordpress,import,backups,logs/nginx}
# uid/gid 82 = www-data inside the alpine-based containers
chown -R 82:82 "${REPO_DIR}/wordpress"

if [[ -n "${GCS_BUCKET:-}" ]]; then
  echo "==> Cloud Storage tools (rclone env_auth + gcsfuse)"
  bash "${REPO_DIR}/scripts/gcs.sh" install
  bash "${REPO_DIR}/scripts/gcs.sh" rclone-config || true
fi

echo
echo "Done. Next:  cp .env.example .env && edit it, then  docker compose up -d --build"
echo "GCS upload/download: set GCS_BUCKET in .env, then  scripts/gcs.sh smoke"
echo "See docs/08-docker-implementation-guide.md"
