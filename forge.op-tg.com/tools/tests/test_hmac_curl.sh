#!/usr/bin/env bash
set -euo pipefail

# Linux/macOS E2E test: HMAC heartbeat -> pull_job -> report_results + replay=409
# Requirements: bash, curl, openssl

BASE_URL=${BASE_URL:-"http://127.0.0.1:8080"}
WORKER_ID=${WORKER_ID:-"dev-curl"}
INTERNAL_SECRET=${INTERNAL_SECRET:-"dev-secret"}
WORKER_SECRET=${WORKER_SECRET:-""}

json_pp(){ python - <<'PY' 2>/dev/null || cat
import sys, json
try:
    print(json.dumps(json.load(sys.stdin), ensure_ascii=False, indent=2))
except Exception:
    sys.stdout.write(sys.stdin.read())
PY
}

body_sha(){
  # prints hex sha256 of stdin
  openssl dgst -sha256 | awk '{print $2}'
}

hmac_sign(){
  local method="$1" path="$2" sha="$3" ts="$4" secret="$5"
  local msg
  msg="$(printf '%s|%s|%s|%s' "${method^^}" "$path" "$sha" "$ts")"
  printf '%s' "$msg" | openssl dgst -sha256 -hmac "$secret" | awk '{print $2}'
}

curl_j(){ curl -sS -H 'Content-Type: application/json' "$@"; }

hr(){ printf '\n%s\n' "============================================================"; }

echo "Base: $BASE_URL | Worker: $WORKER_ID"

# 1) Heartbeat (POST)
path='/api/heartbeat.php'
hb_body='{"hello":"world"}'
ts=$(date +%s)
sha=$(printf '%s' "$hb_body" | body_sha)
sign=$(hmac_sign 'POST' "$path" "$sha" "$ts" "$INTERNAL_SECRET")
hr; echo "Heartbeat..."
resp=$(curl_j -X POST \
  -H "X-Worker-Id: $WORKER_ID" \
  -H "X-Auth-TS: $ts" \
  -H "X-Auth-Sign: $sign" \
  -H "X-Worker-Secret: $WORKER_SECRET" \
  -w '\n%{http_code}' \
  --data "$hb_body" "$BASE_URL$path")
code="${resp##*$'\n'}"; body="${resp%$'\n'*}"
echo "$body" | json_pp
echo "HTTP=$code (expect 200)"

# 2) Pull job (GET)
path='/api/pull_job.php'
ts=$(date +%s)
sha=$(printf '' | body_sha)
sign=$(hmac_sign 'GET' "$path" "$sha" "$ts" "$INTERNAL_SECRET")
hr; echo "Pull job..."
resp=$(curl -sS -X GET \
  -H "X-Worker-Id: $WORKER_ID" \
  -H "X-Auth-TS: $ts" \
  -H "X-Auth-Sign: $sign" \
  -H "X-Worker-Secret: $WORKER_SECRET" \
  -w '\n%{http_code}' \
  "$BASE_URL$path?lease_sec=120")
code="${resp##*$'\n'}"; body="${resp%$'\n'*}"
echo "$body" | json_pp
if [ "$code" = "204" ]; then
  echo "HTTP=204 (no job available)"
elif [ "$code" = "200" ]; then
  echo "HTTP=200"
  jid=$(printf '%s' "$body" | python - <<'PY' 2>/dev/null || true
import sys, json
try:
  j=json.load(sys.stdin)
  print(j.get('job',{}).get('id') or '')
except Exception:
  pass
PY
)
else
  echo "Unexpected HTTP=$code"; exit 1
fi

# 3) Report results (POST) if job exists
if [ -n "${jid:-}" ]; then
  path='/api/report_results.php'
  rp_body=$(cat <<JSON
{"job_id":$jid,"cursor":1,"done":true,"items":[{"name":"Demo Curl Lead","city":"الرياض","country":"SA","phone":"0550000009"}]}
JSON
)
  ts=$(date +%s)
  sha=$(printf '%s' "$rp_body" | body_sha)
  sign=$(hmac_sign 'POST' "$path" "$sha" "$ts" "$INTERNAL_SECRET")
  hr; echo "Report results (first)..."
  resp=$(curl_j -X POST \
    -H "X-Worker-Id: $WORKER_ID" \
    -H "X-Auth-TS: $ts" \
    -H "X-Auth-Sign: $sign" \
    -H "X-Worker-Secret: $WORKER_SECRET" \
    -w '\n%{http_code}' \
    --data "$rp_body" "$BASE_URL$path")
  code="${resp##*$'\n'}"; body="${resp%$'\n'*}"
  echo "$body" | json_pp
  echo "HTTP=$code (expect 200)"

  # Replay same exact request (same ts + body) should yield 409
  hr; echo "Report results (replay, expect 409)..."
  resp=$(curl_j -X POST \
    -H "X-Worker-Id: $WORKER_ID" \
    -H "X-Auth-TS: $ts" \
    -H "X-Auth-Sign: $sign" \
    -H "X-Worker-Secret: $WORKER_SECRET" \
    -w '\n%{http_code}' \
    --data "$rp_body" "$BASE_URL$path" || true)
  code="${resp##*$'\n'}"; body="${resp%$'\n'*}"
  echo "$body" | json_pp
  echo "HTTP=$code"
else
  echo "No job available to test report_results; seed_dev.php can create one."
fi

hr; echo "Done"
