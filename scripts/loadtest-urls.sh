#!/usr/bin/env bash
# Export real permalinks for k6. Run on the VPS (needs WP-CLI / compose).
#
#   scripts/loadtest-urls.sh
#   scripts/loadtest-urls.sh --create-draft
#   HOT=20 LONGTAIL=80 OUT=loadtest/urls.json scripts/loadtest-urls.sh
#
# Copy loadtest/urls.json to the generator (or share the repo working copy).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

HOT="${HOT:-20}"
LONGTAIL="${LONGTAIL:-80}"
OUT="${OUT:-loadtest/urls.json}"
CREATE_DRAFT=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --create-draft) CREATE_DRAFT=1; shift ;;
    --hot) HOT="$2"; shift 2 ;;
    --longtail) LONGTAIL="$2"; shift 2 ;;
    -o|--out) OUT="$2"; shift 2 ;;
    -h|--help)
      echo "Usage: $0 [--create-draft] [--hot N] [--longtail N] [-o FILE]"
      exit 0
      ;;
    *) echo "unknown arg: $1" >&2; exit 1 ;;
  esac
done

WP="docker compose run --rm -T wpcli wp"
TOTAL=$((HOT + LONGTAIL))

echo "==> Reading published permalinks (hot=$HOT longtail=$LONGTAIL)" >&2
HOME_URL="$($WP option get home | tr -d '\r')"
HOME_URL="${HOME_URL%/}/"
FEED_URL="$($WP eval 'echo get_feed_link();' | tr -d '\r')"

mapfile -t ALL < <($WP post list \
  --post_type=post \
  --post_status=publish \
  --orderby=date \
  --order=desc \
  --posts_per_page="$TOTAL" \
  --field=url | tr -d '\r')

HOT_URLS=()
LONGTAIL_URLS=()
if [[ ${#ALL[@]} -eq 0 ]]; then
  echo "warning: no published posts — hot set will be homepage + feed only" >&2
else
  HOT_URLS=("${ALL[@]:0:HOT}")
  LONGTAIL_URLS=("${ALL[@]:HOT}")
fi

EDITOR_POST_ID=""
if [[ "$CREATE_DRAFT" -eq 1 ]]; then
  echo "==> Creating draft fixture post (do not publish)" >&2
  EDITOR_POST_ID="$($WP post create \
    --post_type=post \
    --post_status=draft \
    --post_title='k6 loadtest fixture (do not publish)' \
    --post_content='Load-test fixture. Keep this draft.' \
    --porcelain | tr -d '\r')"
  echo "    EDITOR_POST_ID=$EDITOR_POST_ID" >&2
fi

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT
printf '%s\n' "${HOT_URLS[@]+"${HOT_URLS[@]}"}" > "$tmpdir/hot"
printf '%s\n' "${LONGTAIL_URLS[@]+"${LONGTAIL_URLS[@]}"}" > "$tmpdir/longtail"

HOME_URL="$HOME_URL" FEED_URL="$FEED_URL" EDITOR_POST_ID="$EDITOR_POST_ID" \
HOT_FILE="$tmpdir/hot" LONGTAIL_FILE="$tmpdir/longtail" \
python3 - "$OUT" <<'PY'
import json, os, sys

def lines(path):
    with open(path) as f:
        return [ln.strip() for ln in f if ln.strip()]

out = {
    "home": os.environ["HOME_URL"],
    "feed": os.environ["FEED_URL"],
    "hot": lines(os.environ["HOT_FILE"]),
    "longtail": lines(os.environ["LONGTAIL_FILE"]),
}
eid = os.environ.get("EDITOR_POST_ID", "").strip()
if eid.isdigit():
    out["editorPostId"] = int(eid)

path = sys.argv[1]
os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
with open(path, "w") as f:
    json.dump(out, f, indent=2)
    f.write("\n")
print(f"hot={len(out['hot'])} longtail={len(out['longtail'])} home={out['home']}", file=sys.stderr)
PY

echo "wrote $OUT" >&2
echo "k6 reads this as ../urls.json from loadtest/k6/ (or -e URLS=/absolute/path)" >&2
