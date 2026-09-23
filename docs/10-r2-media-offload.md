# 10 — Offload `uploads/` from the VM to Cloudflare R2

Later-phase runbook. Media stays on **local disk** until you actually run these
steps (doc 05 Q6 → A). Do this when `uploads/` grows past ~50 GB, disk hits 80 %
(doc 07), or nightly backups should stop copying media.

**Public URLs do not change.** Readers keep requesting
`https://SITE_DOMAIN/wp-content/uploads/...`. Years of hardcoded `<img src>` in
`post_content` are left alone — filters on `wp_get_attachment_url` do not rewrite
those. A Cloudflare Worker on that path serves objects from R2.

rclone is already on the box (`scripts/setup-vps.sh`). Use it for the bulk copy.
Do **not** `rclone mount` (or gcsfuse) as the live `uploads/` tree — same rule as
doc 07 for GCS.

Themes, plugins, and `mu-plugins` stay on the VM. Image **generation** stays on
the VM (`perf-tweaks.php` WebP + 2560px cap). Only the **bytes** leave.

```
Readers
  └── Cloudflare edge (already caches /wp-content/* for 1 year)
        ├── HTML / PHP  →  VPS (unchanged)
        └── /wp-content/uploads/*  →  R2  (Worker, same URL)
              ▲
              └── PHP-FPM writes new files via S3 API (mu-plugin r2-offload.php)
```



## Contents

1. [Why this shape](#why-this-shape)
2. [Prerequisites](#prerequisites)
3. [Step 1 — Create the R2 bucket](#step-1--create-the-r2-bucket)
4. [Step 2 — rclone remote](#step-2--rclone-remote-for-the-bulk-copy)
5. [Step 3 — Copy existing uploads](#step-3--copy-existing-uploads-readers-still-on-local-disk)
6. [Step 4 — Serve reads from R2](#step-4--serve-reads-from-r2-same-urls)
7. [Step 5 — Send new uploads to R2](#step-5--send-new-uploads-to-r2)
8. [Step 6 — Soak](#step-6--soak-do-not-delete-yet)
9. [Step 7 — Reclaim VM disk](#step-7--reclaim-vm-disk)
10. [Step 8 — Change backups and monitoring](#step-8--change-backups-and-monitoring)
11. [Security](#security)
12. [What does not change](#what-does-not-change)
13. [Rollback](#rollback)
14. [Decision log](#decision-log-when-you-actually-do-this)

---



## Why this shape


| Option                                                               | Verdict                                                                                                                             |
| -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| **A. Worker on** `/wp-content/uploads/`* **+ R2 binding** — *chosen* | URLs unchanged. Edge never needs the VM for media. Matches Q6 “switch on later without URL changes”.                                |
| B. Custom domain `media.SITE_DOMAIN` + rewrite attachment URLs       | Cleaner hostname, but every historical `<img src>` in `post_content` still points at the old path unless you 301 or rewrite the DB. |
| C. nginx `proxy_pass` to R2                                          | Disk goes to zero, but every Cloudflare miss still hits the origin. Extra hop, extra origin bandwidth. Fallback only.               |
| D. rclone/gcsfuse mount of `uploads/`                                | Latency, lock, and outage risk. Rejected (same as live GCS mounts).                                                                 |


Option B is fine **as well as** A: bind `media.SITE_DOMAIN` to the bucket for
ops and for a plugin “delivery domain”, but readers keep talking to the site host
via the Worker.

Do **not** reuse `BACKUP_RCLONE_REMOTE` for live media. That remote is for
**private** DB/uploads backups. Public media needs its own bucket.

---



## Prerequisites


| Item                                          | Notes                                                                                         |
| --------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Cloudflare account that already owns the zone | Same account as the site ([doc 08](08-implementation-guide.md) step 8)                        |
| R2 enabled on that account                    | Dashboard → R2                                                                                |
| Two buckets, not one                          | `news-media` (public via Worker / custom domain) and the existing private backup remote       |
| API token for S3                              | **Object Read & Write** on `news-media` only. Account ID + Access Key + Secret. Never commit. |
| Disk snapshot or GCS `uploads/` mirror        | Take this **before** deleting local files                                                     |
| Plugin budget                                 | [Doc 07](07-operations.md): keep under 15 active plugins. Offload is a must-use plugin in this repo, not another wp-admin plugin. |


Estimate size first:

```bash
du -sh /var/www/html/wp-content/uploads
find /var/www/html/wp-content/uploads -type f | wc -l
```

---



## Step 1 — Create the R2 bucket

In **R2 → Create bucket**:

- Name: `news-media` (or `tr724-media`)
- Location: automatic, or the region closest to the GCE zone ([doc 00](00-create-gce-vm.md))
- Object versioning: **on** (this replaces nightly `rsync` of uploads after cut-over)
- Public development URL (`*.r2.dev`): leave **disabled**. It is rate-limited and the wrong public hostname.

Optional but useful: bind custom domain `media.SITE_DOMAIN` to the bucket (SSL is
automatic on a proxied hostname). That hostname is for ops and for the plugin
“delivery domain”, not for rewriting article HTML.

Lifecycle (after soak):

- Noncurrent versions: expire after 30 days
- Delete markers: expire after 7 days
- Do **not** expire current objects

---

## Step 2 — rclone remote for the bulk copy

`rclone lsd r2-media:` and `rclone mkdir r2-media:…` fail with
`didn't find section in config file ("r2-media")` until this create step
succeeds. rclone stores remotes in `~/.config/rclone/rclone.conf` for **the
user you are now** (root → `/root/.config/…`; keep using that same user).

This is a **second** rclone remote, not `BACKUP_RCLONE_REMOTE`.

Ubuntu 24.04's apt rclone is often 1.60, which has no `provider Cloudflare`.
Need 1.61+. If `rclone version` is older:

```bash
sudo bash scripts/gcs.sh install   # official rclone; already used for GCS
rclone version
```

Fill the commented `R2_*` keys in `.env` first (never commit):

```bash
# .env
R2_ACCOUNT_ID=your_32_char_account_id
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=news-media
```

Write the remote into rclone's config (same user you will run `sync` as —
root → `/root/.config/rclone/rclone.conf`). `rclone config create --non-interactive`
can print JSON and still leave no `[r2-media]` section; writing the file is
repeatable.

```bash
cd /opt/news-wp-architecture
set -a && source .env && set +a

# stop here if .env was not filled — empty keys make later commands fail
if [[ -z "${R2_ACCOUNT_ID}" || -z "${R2_ACCESS_KEY_ID}" || -z "${R2_SECRET_ACCESS_KEY}" ]]; then
  echo "fill R2_ACCOUNT_ID / R2_ACCESS_KEY_ID / R2_SECRET_ACCESS_KEY in .env"
else
  echo "R2 env ok, account=${R2_ACCOUNT_ID}"
fi

mkdir -p ~/.config/rclone
conf=~/.config/rclone/rclone.conf
# drop a previous r2-media section if present
if [[ -f "$conf" ]]; then
  awk 'BEGIN{p=1} /^\[r2-media\]/{p=0} /^\[/ && !/^\[r2-media\]/{p=1} p' "$conf" > "$conf.tmp"
  mv "$conf.tmp" "$conf"
fi
cat >> "$conf" <<EOF
[r2-media]
type = s3
provider = Cloudflare
access_key_id = ${R2_ACCESS_KEY_ID}
secret_access_key = ${R2_SECRET_ACCESS_KEY}
region = auto
endpoint = https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com
acl = private
no_check_bucket = true
EOF
chmod 600 "$conf"

rclone listremotes
# must print:  r2-media:
grep -E '^\[r2-media\]|^endpoint ' "$conf"
# endpoint must be https://<account-id>.r2.cloudflarestorage.com
# not https://.r2.cloudflarestorage.com
```

`no_check_bucket` is required for R2 API tokens that are Object Read & Write
only (HeadBucket / ListBuckets are otherwise denied). If later commands say
`unknown provider Cloudflare`, the binary is still too old — re-run
`scripts/gcs.sh install`.

Do **not** `rclone lsd r2-media:` (no bucket). That calls ListBuckets, which a
bucket-scoped Object Read & Write token cannot do (403 Access Denied). List
**inside** the bucket from Step 1 instead. Skip `rclone mkdir` — the dashboard
already created it.

```bash
rclone lsf r2-media:news-media
# empty bucket → no output, exit 0
# "didn't find section" → the block above did not run as this user, or conf was deleted
# 403 → token cannot see this bucket (wrong key, or scoped to another name)
```

---



## Step 3 — Copy existing uploads (readers still on local disk)

Preserve the URL path. Object key = URI path with the leading slash stripped:

`https://SITE_DOMAIN/wp-content/uploads/2024/01/foo.jpg`
→ R2 key `wp-content/uploads/2024/01/foo.jpg`

From the live tree:

```bash
rclone sync /var/www/html/wp-content/uploads/ \
  r2-media:news-media/wp-content/uploads/ \
  --checksum --transfers 16 --checkers 16 \
  --fast-list -P
```

Or from the GCS backup without touching the live disk (good if the VM is already
tight):

```bash
# after scripts/gcs.sh rclone-config  (remote name: gcs)
rclone sync gcs:tr724-backup/wp-content/uploads/ \
  r2-media:news-media/wp-content/uploads/ \
  --checksum --fast-list -P
```

Re-run is safe. Spot-check:

```bash
rclone ls r2-media:news-media/wp-content/uploads/2024/01/ | head
rclone check /var/www/html/wp-content/uploads r2-media:news-media/wp-content/uploads --one-way
```

Do **not** delete local files yet.

---



## Step 4 — Serve reads from R2 (same URLs)



### 4a. Worker (recommended)

Create a Worker with an R2 binding named `MEDIA`, bound to `news-media`. Route:

`SITE_DOMAIN/wp-content/uploads/*`

Sketch (the binding name and cache headers are the important part). This lives in
the Cloudflare dashboard / Wrangler, not in this repo:

```javascript
export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const key = url.pathname.replace(/^\/+/, ""); // wp-content/uploads/...
    if (/\.(?:php|phtml|phar|pl|py|cgi|sh)$/i.test(key)) {
      return new Response("Not found", { status: 404 });
    }
    if (request.method === "HEAD" || request.method === "GET") {
      const obj = await env.MEDIA.get(key);
      if (!obj) return new Response("Not found", { status: 404 });
      const headers = new Headers();
      headers.set("Cache-Control", "public, max-age=31536000, immutable");
      headers.set("Content-Type", obj.httpMetadata?.contentType || "application/octet-stream");
      headers.set("ETag", obj.httpEtag);
      return new Response(obj.body, { headers });
    }
    return new Response("Method not allowed", { status: 405 });
  },
};
```

Cache: either honour that `Cache-Control` with the existing **Rule 1** in
[doc 08](08-implementation-guide.md) (`http.host eq SITE_DOMAIN` already covers
this path), or add an explicit Cache Rule:

- When: `starts_with(http.request.uri.path, "/wp-content/uploads/")`
- Then: Eligible for cache; Edge TTL 1 year; ignore query string except `ver`

The Worker must **not** run on `/wp-admin`, HTML, or PHP. The route prefix
already prevents that.

Verify (expect **no** `x-fastcgi-cache` — origin nginx is out of the path):

```bash
curl -sI "https://SITE_DOMAIN/wp-content/uploads/YYYY/MM/known-file.jpg" \
  | grep -iE 'HTTP|cf-cache-status|cf-ray|cache-control|content-type'
# 1st: cf-cache-status: MISS   (or DYNAMIC on first Worker miss, then HIT)
# 2nd: cf-cache-status: HIT
# must 404 for a path that does not exist, including *.php
```

Confirm PHP is still blocked. Origin hardening
(`config/nginx/snippets/wordpress-hardening.conf`) is origin-only; the Worker is
the new gate:

```bash
curl -sI "https://SITE_DOMAIN/wp-content/uploads/evil.php"
# 404 from Worker, never 200
```



### 4b. Fallback: nginx proxy (only if you skip the Worker)

Keep files off disk but still pull through origin on Cloudflare misses. Hairpin
risk if `proxy_pass` targets a hostname on the same zone — prefer the S3 API or
the R2 custom domain from a **separate** hostname. This is strictly worse than 4a.

---



## Step 5 — Send new uploads to R2

Image **generation** stays on the VM (GD/Imagick, `perf-tweaks.php`). Only the
**bytes** leave, and they leave **before the upload request returns**. Otherwise
the Worker 404s the photo (it never reads the VM disk).

`wp/mu-plugins/r2-offload.php` does that PUT. `scripts/setup-vps.sh` installs it
with the other mu-plugins. It does not appear in the wp-admin plugin list and
does not rewrite attachment URLs.

Credentials live in `.env`, not in the database and not in the plugin file.
Setup copies non-empty values into `wp-config.php` (mode 640, `www-data`):


| `.env`                  | `wp-config.php`               |
| ----------------------- | ----------------------------- |
| `R2_ACCOUNT_ID`         | `NEWS_R2_ACCOUNT_ID`          |
| `R2_ACCESS_KEY_ID`      | `NEWS_R2_ACCESS_KEY_ID`       |
| `R2_SECRET_ACCESS_KEY`  | `NEWS_R2_SECRET_ACCESS_KEY`   |
| `R2_BUCKET`             | `NEWS_R2_BUCKET` (`news-media`) |


If any constant is missing, the mu-plugin does nothing and logs once. Endpoint
is `https://ACCOUNT_ID.r2.cloudflarestorage.com/BUCKET/key`, region `auto`,
SigV4 from PHP (no AWS SDK). The bucket stays private; the Worker is the reader.

For each new or regenerated attachment it uploads:

- the main file (`file`, including a `-scaled` original)
- `original_image`, when WordPress kept the pre-scale file
- every intermediate size, including WebP from `perf-tweaks.php`
- any `sources` entries

Object key = public path with the leading slash removed:

`https://SITE_DOMAIN/wp-content/uploads/2026/09/23/photo.webp`
→ `wp-content/uploads/2026/09/23/photo.webp`

The folder is today's date in the WordPress timezone (`YYYY/MM/DD`). WordPress
would otherwise file the image under the article's publish date, so a photo
added to yesterday's story would show up in yesterday's folder.

Local files stay on disk until Step 7. This mu-plugin never deletes them.
Deleting an attachment deletes those keys in R2 (versioning can restore them).

A failed PUT is logged, shown on the next wp-admin screen, and retried by the
existing minutely system cron. After repeated failure the notice stays. The
file is still on the VM, but readers miss it until a PUT succeeds.

After a successful PUT or delete, the public URL is handed to `cache-purge.php`
so a cached Worker 404 (or a deleted image) does not stick under the 1-year
uploads cache rule.

Historical files are not copied here — Step 3's rclone sync already did that.

Acceptance, on the VM:

1. Fill `R2_*` in `.env`, pull, re-run `sudo bash scripts/setup-vps.sh`.
   That refreshes mu-plugins and constants. It does not wipe the database.
2. Upload a test image in wp-admin. The media request should not return before
   the objects exist.
3. Confirm the objects: `rclone ls r2-media:news-media/wp-content/uploads/$(date +%Y/%m)/ | grep test`
   — original and WebP sizes.
4. Confirm the article HTML still uses `/wp-content/uploads/...` on `SITE_DOMAIN`.
5. `curl -sI` that URL → `200`, and `cf-cache-status: HIT` on the second request.
6. Confirm no new cookie on anonymous HTML (`make stats` BYPASS ratio unchanged).

---



## Step 6 — Soak (do not delete yet)

Run both sources in parallel for **at least 7 days** (or one full publish week):

- `r2-offload.php` retries a failed PUT on the minutely system cron. A nightly
  `rclone sync` of local `uploads/` to `r2-media` still catches anything that
  retry gave up on
- `scripts/backup.sh` still rsyncs local uploads
- Compare: `rclone check` local vs R2
- Watch R2 class A/B ops and 404s in Worker logs
- Editors: media library, featured images, Newspaper galleries, PDF inserts if you use them

Only then delete local files (Step 7). `r2-offload.php` never removes them.

---



## Step 7 — Reclaim VM disk

When `rclone check` is clean and Worker 404s are only for truly missing files:

```bash
# last local copy → GCS, then R2, then snapshot
scripts/gcs.sh backup
rclone sync /var/www/html/wp-content/uploads/ r2-media:news-media/wp-content/uploads/ --checksum --fast-list -P

# keep an empty tree so WordPress and nginx still have a directory
sudo -u www-data find /var/www/html/wp-content/uploads -type f -delete
```

Safer than `rm -rf`: keep the year/month directories so a misbehaving plugin that
writes locally still has a path.

Optional: shrink the GCE disk later ([doc 00](00-create-gce-vm.md)). That is a VM
recreation/resize, not an R2 step.

---



## Step 8 — Change backups and monitoring

After local files are gone, **stop** treating uploads as origin data.


| Before                                                                                          | After                                                              |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Nightly `rsync` `WP_ROOT/wp-content/uploads` → `backups/uploads` → GCS / `BACKUP_RCLONE_REMOTE` | Drop the uploads half of `scripts/backup.sh`. DB dump unchanged.   |
| GCS `uploads/` mirror                                                                           | Keep the last pre-cutover mirror; do not keep growing it           |
| Disk alert on uploads                                                                           | Alert on R2 storage size + Worker 5xx + 404 rate                   |
| Restore uploads from GCS                                                                        | Restore from R2 versioning / `rclone copy r2-media:news-media/...` |


`BACKUP_RCLONE_REMOTE=r2:news-backups` can stay as a **private** backup
destination. Different bucket, no public Worker route.

This repo does not change `scripts/backup.sh` until you cut over. After Step 7,
edit that script (or wrap it) so it no longer rsyncs local `uploads/`.

---



## Security

- Bucket itself: **private**. The Worker (or the custom domain on the Cloudflare zone) is the only public reader.
- Token: scoped to `news-media`, not account-wide. Rotate if it ever lands in git.
- Worker: GET/HEAD only. Never PUT/DELETE from the public route.
- Deny `.php` / `.phtml` keys in the Worker (mirror `wordpress-hardening.conf`).
- Do not offload `wp-content/plugins`, `themes`, or anything executable.
- Authenticated Origin Pulls (doc 05 Q11) do not apply to R2; R2 is not the VPS.

---



## What does not change

- HTML caching, FastCGI cache, purge-on-publish (docs 03, 08)
- Image *processing* on upload (`perf-tweaks.php`)
- Cloudflare Cache Rule 1 for `/wp-content/*` (still valid if URLs stay on `SITE_DOMAIN`)
- Editor workflow in wp-admin
- GCS as the **database** backup source of truth

---



## Rollback

As long as local files exist: disable the Worker route; origin nginx serves disk
again.

After Step 7:

```bash
rclone sync r2-media:news-media/wp-content/uploads/ \
  /var/www/html/wp-content/uploads/ \
  --checksum --fast-list -P
sudo chown -R www-data:www-data /var/www/html/wp-content/uploads
```

Then remove the Worker route. Leave `r2-offload.php` in place (new uploads keep
going to R2 and to disk) or delete it from `wp-content/mu-plugins`.

---



## Decision log (when you actually do this)

Add to [doc 05](05-alternatives-and-decisions.md):

> **YYYY-MM-DD** — Media serving moved to **Cloudflare R2** (Q6 → B). Public URLs
> unchanged (`/wp-content/uploads/` on `SITE_DOMAIN` via Worker). Local files
> removed after soak. Uploads no longer part of nightly origin backup; R2
> versioning is the media backup. See doc 10.

