#!/usr/bin/env bash
# Show UP/DOWN status of the three Kiribati servers.
# Usage: bash scripts/status.sh
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

check() { # check <label> <url>
  local code
  code="$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$2" 2>/dev/null)" || code="000"
  if [ "$code" = "200" ]; then
    echo "✔ UP   $1  ($2)"
    return 0
  else
    echo "✘ DOWN $1  ($2)  [http=$code]"
    return 1
  fi
}

echo "=== Kiribati stack status ==="
fails=0
check "backend  " "http://127.0.0.1:8000/api/health" || fails=$((fails + 1))
check "frontend " "http://127.0.0.1:3000/" || fails=$((fails + 1))
check "flutter  " "http://127.0.0.1:8080/" || fails=$((fails + 1))
if [ "$fails" = "0" ]; then
  echo "All services are UP."
else
  echo "Some services are DOWN — start with: bash scripts/start.sh (it diagnoses itself)"
fi
exit "$fails"
