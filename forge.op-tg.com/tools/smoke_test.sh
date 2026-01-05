#!/usr/bin/env bash
set -euo pipefail
TIMEOUT_SEC=${TIMEOUT_SEC:-120}
POLL_EVERY=${POLL_EVERY:-5}
REPO_ROOT="$(cd "$(dirname "$0")"/.. && pwd)"
PHP_BIN=${PHP_BIN:-php}

echo "[Smoke] Starting… (timeout=${TIMEOUT_SEC}s, every=${POLL_EVERY}s)"

ENQ="$REPO_ROOT/tools/ops/enqueue_sample.php"
if [[ -f "$ENQ" ]]; then
  payload='{"query":"diag-echo","ll":"24.7136,46.6753","radius_km":1,"lang":"ar","region":"sa","target":1}'
  set +e
  out=$($PHP_BIN "$ENQ" -t diag.echo -p "$payload" 2>&1)
  echo "[Smoke] enqueue output: $out"
  set -e
else
  echo "[Smoke] enqueue_sample.php not found. Will only poll jobs and fail if none found." >&2
fi

deadline=$(( $(date +%s) + TIMEOUT_SEC ))
job_found=0
job_succeeded=0
job_id=""

while (( $(date +%s) < deadline )); do
  php_code='<?php
require __DIR__ . "/../bootstrap.php";
$pdo = db();
try{
  $sql = "SELECT id,status,job_type,role FROM internal_jobs WHERE (updated_at >= NOW() - INTERVAL 2 MINUTE OR created_at >= NOW() - INTERVAL 2 MINUTE) ORDER BY updated_at DESC LIMIT 20";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){
  $rows = $pdo->query("SELECT id,status,job_type,role FROM internal_jobs WHERE (updated_at >= datetime('now','-2 minutes') OR created_at >= datetime('now','-2 minutes')) ORDER BY updated_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>'
  json=$(mktemp)
  echo "$php_code" > "$json.php"
  set +e
  res=$($PHP_BIN "$json.php" 2>/dev/null)
  rc=$?
  rm -f "$json.php"
  set -e
  if (( rc == 0 )); then
    ids=$(echo "$res" | jq -r '.[].id' 2>/dev/null || true)
    if [[ -n "$ids" ]]; then
      job_found=1
    fi
    st=$(echo "$res" | jq -r '.[] | select(.status=="done" or .status=="succeeded") | .id' 2>/dev/null || true)
    if [[ -n "$st" ]]; then
      job_succeeded=1
      job_id="$st"
      break
    fi
  fi
  sleep "$POLL_EVERY"
done

if (( job_succeeded == 1 )); then
  echo "PASS: pipeline is able to enqueue and complete a job (job #$job_id)."
  exit 0
elif (( job_found == 1 )); then
  echo "FAIL: job observed but did not reach succeeded/done within ${TIMEOUT_SEC}s. Check logs: storage/logs/worker/service.log" >&2
  exit 2
else
  echo "FAIL: no recent jobs observed within ${TIMEOUT_SEC}s. Check worker status and queue. Logs: storage/logs/worker/service.log" >&2
  exit 3
fi
