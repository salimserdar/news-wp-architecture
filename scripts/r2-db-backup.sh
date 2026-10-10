#!/usr/bin/env bash
# Database backups on Cloudflare R2. One private bucket for every day.
#
#   news-db/20261010/wordpress_2026-10-10_0330.sql.zst
#
# An admin machine creates the bucket once and puts a bucket lock on it, so
# objects can be added but not deleted or overwritten. The VPS holds a separate
# Object Read & Write key for this bucket only. It cannot remove the lock or
# delete the bucket. The media bucket is a different key.
#
#   scripts/r2-db-backup.sh provision                 # laptop: create + lock
#   scripts/r2-db-backup.sh upload FILE.sql.zst       # VPS: write today's dump
#   scripts/r2-db-backup.sh check
#   scripts/r2-db-backup.sh list [YYYYMMDD]
#   scripts/r2-db-backup.sh restore YYYYMMDD [FILE]
#
# Lock removal is not implemented. That stays in the Cloudflare dashboard.
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

# One bucket. R2_DB_BUCKET_PREFIX is accepted so an older .env still names it.
BUCKET="${R2_DB_BUCKET:-${R2_DB_BUCKET_PREFIX:-news-db}}"
ACCOUNT="${R2_ACCOUNT_ID:-}"

die() { echo "$*" >&2; exit 1; }

need_account() {
  [[ -n "$ACCOUNT" ]] || die "set R2_ACCOUNT_ID in .env (same Cloudflare account as R2 media)"
}

need_bucket_name() {
  [[ "$BUCKET" =~ ^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$ ]] || die "R2_DB_BUCKET must be a lowercase R2 bucket name"
  [[ "$BUCKET" != "${R2_BUCKET:-news-media}" ]] || die "R2_DB_BUCKET must be a different bucket from the media bucket (${R2_BUCKET:-news-media})"
}

utc_day() {
  date -u +%Y%m%d
}

need_jq() { command -v jq >/dev/null || die "jq is required for provision (brew install jq, or apt-get install jq)"; }
need_curl() { command -v curl >/dev/null || die "curl is required"; }

need_rclone() {
  command -v rclone >/dev/null || die "rclone not found. On the VPS: curl -fsSL https://rclone.org/install.sh | sudo bash"
  rclone help backend s3 2>/dev/null | grep -q 'Cloudflare' || die "rclone has no Cloudflare provider (need 1.61+). curl -fsSL https://rclone.org/install.sh | sudo bash"
}

writer_env_ok() {
  [[ -n "${R2_DB_ACCESS_KEY_ID:-}" && -n "${R2_DB_SECRET_ACCESS_KEY:-}" ]] || die "set R2_DB_ACCESS_KEY_ID and R2_DB_SECRET_ACCESS_KEY (from provision, not the media key)"
  if [[ -n "${R2_BACKUP_ADMIN_TOKEN:-}" ]]; then
    die "R2_BACKUP_ADMIN_TOKEN is set. Take it out of the VPS .env. The origin only uploads; it must not be able to unlock or delete the bucket."
  fi
}

export_rclone() {
  need_account
  need_bucket_name
  writer_env_ok
  need_rclone
  export RCLONE_CONFIG_R2DB_TYPE=s3
  export RCLONE_CONFIG_R2DB_PROVIDER=Cloudflare
  export RCLONE_CONFIG_R2DB_ACCESS_KEY_ID="${R2_DB_ACCESS_KEY_ID}"
  export RCLONE_CONFIG_R2DB_SECRET_ACCESS_KEY="${R2_DB_SECRET_ACCESS_KEY}"
  export RCLONE_CONFIG_R2DB_REGION=auto
  export RCLONE_CONFIG_R2DB_ENDPOINT="https://${ACCOUNT}.r2.cloudflarestorage.com"
  export RCLONE_CONFIG_R2DB_ACL=private
  export RCLONE_CONFIG_R2DB_NO_CHECK_BUCKET=true
}

file_sha256() {
  local f="$1"
  if command -v sha256sum >/dev/null; then
    sha256sum "$f" | awk '{print $1}'
  else
    shasum -a 256 "$f" | awk '{print $1}'
  fi
}

need_day() {
  [[ "$1" =~ ^[0-9]{8}$ ]] || die "date must be YYYYMMDD, got: ${1}"
}

# --- admin (laptop) ----------------------------------------------------------

refuse_on_origin() {
  if [[ -f /etc/cron.d/news-wp-backup && "${R2_DB_ALLOW_PROVISION:-}" != 1 ]]; then
    die "run provision on the admin machine, not the VPS. Do not put R2_BACKUP_ADMIN_TOKEN in the origin .env."
  fi
}

need_admin() {
  need_account
  need_bucket_name
  need_jq
  need_curl
  [[ -n "${R2_BACKUP_ADMIN_TOKEN:-}" ]] || die "set R2_BACKUP_ADMIN_TOKEN in the environment for this command only (Cloudflare API token: Account → Workers R2 Storage → Edit). Do not store it on the VPS."
}

cf_api() {
  local method="$1" path="$2" body="${3:-}"
  local out err
  err="$(mktemp)"
  if [[ -n "$body" ]]; then
    out="$(curl -g -sS -X "$method" "https://api.cloudflare.com/client/v4${path}" \
      -H "Authorization: Bearer ${R2_BACKUP_ADMIN_TOKEN}" \
      -H "Content-Type: application/json" \
      --data "$body" 2>"$err")" || { cat "$err" >&2; rm -f "$err"; return 1; }
  else
    out="$(curl -g -sS -X "$method" "https://api.cloudflare.com/client/v4${path}" \
      -H "Authorization: Bearer ${R2_BACKUP_ADMIN_TOKEN}" \
      -H "Content-Type: application/json" 2>"$err")" || { cat "$err" >&2; rm -f "$err"; return 1; }
  fi
  rm -f "$err"
  printf '%s' "$out"
}

lock_body() {
  local days="${R2_DB_RETENTION_DAYS:-}"
  if [[ -z "$days" || "$days" == "0" ]]; then
    jq -n '{rules:[{id:"db-retain",enabled:true,condition:{type:"Indefinite"}}]}'
    return
  fi
  [[ "$days" =~ ^[0-9]+$ ]] || die "R2_DB_RETENTION_DAYS must be a positive number of days, or empty for a permanent lock"
  (( days >= 1 && days <= 3650 )) || die "R2_DB_RETENTION_DAYS must be 1..3650 (empty = permanent)"
  jq -n --argjson sec "$((days * 86400))" \
    '{rules:[{id:"db-retain",enabled:true,condition:{type:"Age",maxAgeSeconds:$sec}}]}'
}

create_bucket() {
  local body resp
  if [[ -n "${R2_DB_LOCATION_HINT:-}" ]]; then
    case "$R2_DB_LOCATION_HINT" in
      wnam|enam|weur|eeur|apac|oc) ;;
      *) die "R2_DB_LOCATION_HINT must be wnam, enam, weur, eeur, apac, or oc" ;;
    esac
    body="$(jq -n --arg name "$BUCKET" --arg hint "$R2_DB_LOCATION_HINT" '{name:$name,locationHint:$hint}')"
  else
    body="$(jq -n --arg name "$BUCKET" '{name:$name}')"
  fi
  resp="$(cf_api POST "/accounts/${ACCOUNT}/r2/buckets" "$body")"
  if printf '%s' "$resp" | jq -e '.success == true' >/dev/null; then
    echo "created  ${BUCKET}"
    return
  fi
  if printf '%s' "$resp" | jq -e '[(.errors // [])[]?.message // empty] | join(" ") | test("already exists";"i")' >/dev/null; then
    echo "exists   ${BUCKET}"
    return
  fi
  echo "create failed: ${BUCKET}" >&2
  printf '%s' "$resp" | jq '.errors, .messages' >&2
  exit 1
}

lock_bucket() {
  local body resp
  body="$(lock_body)"
  resp="$(cf_api PUT "/accounts/${ACCOUNT}/r2/buckets/${BUCKET}/lock" "$body")"
  if printf '%s' "$resp" | jq -e '.success == true' >/dev/null; then
    echo "locked   ${BUCKET}"
    return
  fi
  echo "lock failed: ${BUCKET}" >&2
  printf '%s' "$resp" | jq '.errors, .messages' >&2
  exit 1
}

print_dashboard_token_steps() {
  cat >&2 <<EOF
The bucket and lock are in place. Create the writer key in the dashboard:

  R2 → Manage R2 API tokens → Create API token
  Permission: Object Read & Write
  Apply to specific buckets only: ${BUCKET}
  Do not select the media bucket.

Then put the Access Key ID and Secret Access Key in the VPS .env:

  R2_DB_ACCESS_KEY_ID=
  R2_DB_SECRET_ACCESS_KEY=
EOF
}

hash_stdin() {
  if command -v sha256sum >/dev/null; then
    sha256sum | awk '{print $1}'
  else
    shasum -a 256 | awk '{print $1}'
  fi
}

mint_writer_token() {
  local perm resp body resources name raw tid secret out prev
  echo "==> writer token for ${BUCKET} only"

  perm="$(cf_api GET "/accounts/${ACCOUNT}/tokens/permission_groups" \
    | jq -r '[.result[]? | select(.name == "Workers R2 Storage Bucket Item Write") | .id] | .[0] // empty' || true)"
  if [[ -z "$perm" ]]; then
    echo "could not create the writer token from the API (the admin token needs API Tokens → Edit, or the permission group name differed)." >&2
    print_dashboard_token_steps
    return 0
  fi

  resources="$(jq -nc --arg account "$ACCOUNT" --arg bucket "$BUCKET" \
    '{("com.cloudflare.edge.r2.bucket." + $account + "_default_" + $bucket): "*"}')"
  name="news-db-writer-$(date -u +%Y%m%dT%H%M%SZ)"
  body="$(jq -n --arg name "$name" --argjson resources "$resources" --arg perm "$perm" '{
    name: $name,
    policies: [{
      effect: "allow",
      resources: $resources,
      permission_groups: [{id: $perm}]
    }]
  }')"
  resp="$(cf_api POST "/accounts/${ACCOUNT}/tokens" "$body")"
  if ! printf '%s' "$resp" | jq -e '.success == true' >/dev/null; then
    echo "token create failed" >&2
    printf '%s' "$resp" | jq '.errors, .messages' >&2
    print_dashboard_token_steps
    return 0
  fi

  raw="$(printf '%s' "$resp" | jq -r '.result.value // empty')"
  tid="$(printf '%s' "$resp" | jq -r '.result.id // empty')"
  [[ -n "$raw" && -n "$tid" ]] || die "token response had no id/value"
  # R2 S3 secret is the SHA-256 hex of the API token value. The raw token is not stored.
  secret="$(printf '%s' "$raw" | hash_stdin)"
  raw=""

  mkdir -p backups
  prev=""
  if [[ -f backups/r2-db-writer.env ]]; then
    prev="$(sed -n 's/^R2_DB_ACCESS_KEY_ID=//p' backups/r2-db-writer.env | head -1)"
  fi
  out="$(mktemp backups/r2-db-writer.env.XXXX)"
  {
    echo "# R2 writer for the ${BUCKET} bucket. Install on the VPS .env, then delete this file."
    echo "# Object Read & Write on that bucket only. Cannot edit the bucket lock."
    echo "# token id (Access Key ID); revoke the previous one after the VPS is updated"
    echo "R2_DB_BUCKET=${BUCKET}"
    echo "R2_DB_ACCESS_KEY_ID=${tid}"
    echo "R2_DB_SECRET_ACCESS_KEY=${secret}"
  } >"$out"
  chmod 600 "$out"
  mv "$out" backups/r2-db-writer.env
  echo "wrote backups/r2-db-writer.env (mode 600, gitignored)"
  echo "copy those lines into the VPS .env. Do not copy R2_BACKUP_ADMIN_TOKEN."
  if [[ -n "$prev" && "$prev" != "$tid" ]]; then
    echo "after the VPS works, revoke the previous writer token: ${prev}"
  fi
}

provision_once() {
  refuse_on_origin
  need_admin
  echo "lock: $(lock_body | jq -c '.rules[0].condition')"
  echo "days land in ${BUCKET}/YYYYMMDD/ — the bucket is not recreated each night"
  create_bucket
  lock_bucket
  mint_writer_token
}

# --- VPS ---------------------------------------------------------------------

bucket_visible() {
  rclone lsf "r2db:${BUCKET}" --max-depth 1 --contimeout 10s --timeout 20s >/dev/null 2>&1
}

# Put an object only if it is not already stored at the same size.
# A locked bucket rejects overwrite; a retry of the same dump must still succeed.
copy_new() {
  local src="$1" dest="$2" local_size remote_size
  local_size="$(wc -c <"$src" | tr -d ' ')"
  if rclone copyto "$src" "$dest" --retries 5 --low-level-retries 10 --s3-no-check-bucket -P; then
    remote_size="$(rclone lsl "$dest" --contimeout 10s --timeout 60s | awk '{print $1; exit}')"
    [[ "$remote_size" == "$local_size" ]] || die "size mismatch local=${local_size} remote=${remote_size:-missing} (${dest})"
    return 0
  fi
  remote_size="$(rclone lsl "$dest" --contimeout 10s --timeout 60s 2>/dev/null | awk '{print $1; exit}' || true)"
  if [[ -n "$remote_size" && "$remote_size" == "$local_size" ]]; then
    echo "already stored ${dest} (${local_size} bytes)"
    return 0
  fi
  die "upload failed for ${dest} (local=${local_size} remote=${remote_size:-missing}). The bucket lock rejects overwrite; this file differs from the stored object."
}

cmd_upload() {
  local src="$1" base day local_size side sum
  export_rclone
  [[ -n "$src" ]] || die "usage: $0 upload FILE.sql.zst"
  [[ -f "$src" ]] || die "not found: $src"
  base="$(basename "$src")"
  [[ "$base" == *.sql.zst ]] || die "refusing to upload a non-dump: ${base}"
  day="$(utc_day)"
  if ! bucket_visible; then
    die "cannot see ${BUCKET}. Create it once from the admin machine: scripts/r2-db-backup.sh provision"
  fi
  echo "upload ${src} -> r2:${BUCKET}/${day}/${base}"
  copy_new "$src" "r2db:${BUCKET}/${day}/${base}"
  local_size="$(wc -c <"$src" | tr -d ' ')"
  sum="$(file_sha256 "$src")"
  side="$(mktemp)"
  printf '%s  %s\n' "$sum" "$base" >"$side"
  copy_new "$side" "r2db:${BUCKET}/${day}/${base}.sha256"
  rm -f "$side"
  echo "ok ${BUCKET}/${day}/${base} ${local_size} bytes sha256=${sum}"
}

cmd_check() {
  export_rclone
  if bucket_visible; then
    echo "ok ${BUCKET}/$(utc_day)/"
    rclone lsf "r2db:${BUCKET}/$(utc_day)/" --contimeout 10s --timeout 20s || true
  else
    die "cannot see ${BUCKET}"
  fi
}

cmd_list() {
  local day
  export_rclone
  day="${1:-$(utc_day)}"
  need_day "$day"
  rclone lsl "r2db:${BUCKET}/${day}/" --contimeout 10s --timeout 60s
}

cmd_restore() {
  local day="${1:-}" file="${2:-}" dest sum want
  export_rclone
  need_day "$day"
  if [[ -z "$file" ]]; then
    echo "objects in ${BUCKET}/${day}/:" >&2
    rclone lsl "r2db:${BUCKET}/${day}/" --contimeout 10s --timeout 60s
    die "pass the dump file name to download it. This does not import into MariaDB."
  fi
  file="$(basename "$file")"
  [[ "$file" == *.sql.zst ]] || die "refusing to download a non-dump: ${file}"
  mkdir -p backups/db
  dest="backups/db/${file}"
  echo "download r2:${BUCKET}/${day}/${file} -> ${dest}"
  rclone copyto "r2db:${BUCKET}/${day}/${file}" "$dest" --retries 5 --s3-no-check-bucket -P
  rm -f "${dest}.sha256"
  rclone copyto "r2db:${BUCKET}/${day}/${file}.sha256" "${dest}.sha256" --retries 3 --s3-no-check-bucket || true
  if [[ -f "${dest}.sha256" ]]; then
    sum="$(file_sha256 "$dest")"
    want="$(awk '{print $1}' "${dest}.sha256")"
    [[ "$sum" == "$want" ]] || die "sha256 mismatch for ${dest}"
    echo "sha256 ok"
  fi
  echo "restore with:"
  echo "  zstd -dc ${dest} | mysql \"${DB_NAME:-wordpress}\""
}

usage() {
  cat >&2 <<EOF
usage: $0 COMMAND

  provision                  admin machine: create ${BUCKET} once, lock it, write backups/r2-db-writer.env
  upload FILE.sql.zst        VPS: copy one dump to ${BUCKET}/YYYYMMDD/ (no delete)
  check                      VPS: confirm the bucket is visible
  list [YYYYMMDD]
  restore YYYYMMDD [FILE]    download only; does not import

Days are folders inside the one bucket. A new bucket is not created each night.

Env: R2_ACCOUNT_ID R2_DB_BUCKET
     R2_DB_RETENTION_DAYS   empty = lock forever; a number = lock for that many days
     R2_BACKUP_ADMIN_TOKEN  provision only, never on the VPS
     R2_DB_ACCESS_KEY_ID    VPS writer
     R2_DB_SECRET_ACCESS_KEY
EOF
  exit 1
}

cmd="${1:-}"
shift || true
case "$cmd" in
  provision|provision-ahead) provision_once ;;
  upload)                    cmd_upload "${1:-}" ;;
  check)                     cmd_check ;;
  list)                      cmd_list "${1:-}" ;;
  restore)                   cmd_restore "${1:-}" "${2:-}" ;;
  *)                         usage ;;
esac
