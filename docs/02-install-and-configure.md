# 02 — Install and configure

Takes the Ubuntu 24.04 VPS from [01](01-provision-vps.md) to a running nginx,
PHP-FPM, and MariaDB stack with an empty WordPress tree. Importing the existing
site is [03](03-import-the-site.md).

```
Cloudflare ──> [VPS: native packages]
                 nginx ──(cache miss / logged-in)──> PHP-FPM ──> MariaDB
                 system cron (WP-CLI every 60 s)
```

## What you need

| Item | Where it comes from |
|------|--------------------|
| VPS root SSH, Ubuntu 24.04 LTS | [01](01-provision-vps.md) |
| Domain on Cloudflare (proxied later) | Cloudflare dashboard |
| Cloudflare Origin CA certificate + key | SSL/TLS → Origin Server → Create Certificate |
| Cloudflare API token, permission **Zone → Cache Purge → Purge**, scoped to the zone; and the Zone ID | My Profile → API Tokens; Zone overview (right column) |
| The old site's table prefix | old `wp-config.php` (`$table_prefix`) |

## Repository layout

```
.env.example               copy to .env
config/
  nginx/                   http.conf, site.conf, snippets/ (cache, tls, hardening, realip)
  php/                     pool-www.conf, conf.d/zz-wp.ini
  mariadb/zz-tuning.cnf    InnoDB tuning
wp/mu-plugins/             cache-control.php (TTL headers), cache-purge.php (nginx+Cloudflare purge,
                           warmer, admin-bar button, WP-CLI), perf-tweaks.php,
                           r2-offload.php (new uploads → R2 during the upload request),
                           media-urls.php (public uploads → https://media.turkishnote.com)
scripts/                   setup-vps.sh, import-db.sh, import-wp-content.sh, post-import.sh,
                           wp.sh, backup.sh
import/                    (git-ignored) drop DB dumps and wp-content here
backups/  logs/            (git-ignored)
```

WordPress itself lives at `/var/www/html` on the VPS (not in this repo).

## Install the stack (10–20 min)

On the VPS:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone <this-repo> /opt/news-wp && cd /opt/news-wp
cp .env.example .env && nano .env     # SITE_DOMAIN, DB_*, CF_* (see below)
SSH_PORT=22 bash scripts/setup-vps.sh # change SSH_PORT if you use a custom port
```

The script installs nginx, PHP 8.3-FPM, MariaDB, and WP-CLI; tunes the kernel;
adds 2 GB swap; enables `fail2ban`; writes nginx FastCGI cache and PHP pool
configs; unpacks WordPress into `/var/www/html`; and opens 80/443 to the world
(`WEB_OPEN=1`) so you can test before Cloudflare DNS is live.

Until DNS is switched, reach the origin by IP (expect a certificate warning) or
add a hosts-file entry. Lock 80/443 to Cloudflare only after cut-over — that
step is in [04](04-cloudflare-and-go-live.md).

## Configure `.env` (5 min)

Fill in `.env`:

- `SITE_DOMAIN` (for example `www.example.com`)
- a strong `DB_PASSWORD`
- `DB_TABLE_PREFIX` **exactly as in the old `wp-config.php`**
- `CF_ZONE_ID` and `CF_API_TOKEN`

Leave `PHP_MAX_CHILDREN` and `DB_BUFFER_POOL` at the defaults unless the box is
smaller than 8 vCPU / 32 GB ([01](01-provision-vps.md),
[Resource allocation](reference/resources.md)).

Offsite backups use `BACKUP_RCLONE_REMOTE` (an rclone remote such as
`r2:news-backups`). Leave it empty until [05 — Operations](05-operations.md).

Put the Cloudflare Origin CA certificate in `config/nginx/certs/origin.pem` and
the key in `config/nginx/certs/origin.key` (see `config/nginx/certs/README.md`),
then re-run `scripts/setup-vps.sh` (or copy them to `/etc/nginx/certs/` and
`nginx -s reload`). If you skip the certificate for now, nginx uses a
self-signed pair and Cloudflare must run in "Full" mode instead of
"Full (strict)".

**Do not copy the old `wp-config.php`.** The setup script writes one with the
right constants (URLs, cache purge, salts).

Quick check from the VPS itself:

```bash
curl -sk -o /dev/null -w "%{http_code} cache=%header{x-fastcgi-cache}\n" \
  -H "Host: $SITE_DOMAIN" https://127.0.0.1/
```

You'll get a 302/200 to the WordPress installer at this point — expected; the
database is empty. A second curl of a renderable page should show `HIT`.

Next: [03 — Import the site](03-import-the-site.md).
