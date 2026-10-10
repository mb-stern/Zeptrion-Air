#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
php_bin=${PHP_BIN:-php}
"$php_bin" -l ZeptrionAir/module.php
"$php_bin" -l ZeptrionAirDiscovery/module.php
"$php_bin" tests/run.php
log=$(mktemp)
"$php_bin" -S 127.0.0.1:18984 tests/http-router.php > "$log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; rm -f "$log"' EXIT
for attempt in {1..30}; do
    if "$php_bin" -r '$s=@fsockopen("127.0.0.1",18984); if (!$s) exit(1); fclose($s);'; then break; fi
    sleep 0.1
done
if ! kill -0 "$server_pid"; then cat "$log"; exit 1; fi
"$php_bin" tests/http-client.php 127.0.0.1:18984
