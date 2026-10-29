#!/usr/bin/env bash
# Kiribati AI Fisheries Assistant — start backend + Flutter + frontend, DETACHED.
# Usage: bash scripts/start.sh
# Servers keep running after you close this terminal. Stop with: bash scripts/stop.sh
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# NOTE: no EXIT trap here — detachment is intentional.

fail() { echo ""; echo "❌ $1"; echo "$2"; exit 1; }

port_busy() { (echo > "/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1; }

wait_for() { # wait_for <port> <path> <tries> ; echoes 1 if 200 seen
  local port="$1" path="$2" tries="$3" i=0
  while [ "$i" -lt "$tries" ]; do
    if curl -s -m 3 -o /dev/null -w "%{http_code}" "http://127.0.0.1:${port}${path}" 2>/dev/null | grep -q 200; then
      echo 1
      return
    fi
    sleep 2
    i=$((i + 1))
  done
  echo 0
}

echo "=== Preflight checks ==="

for cmd in php node npm curl; do
  command -v "$cmd" >/dev/null 2>&1 || fail "Required command '$cmd' not found." "Install it (or fix your PATH) and re-run."
done
echo "✔ php, node, npm, curl found"

[ -f "$ROOT/backend/.env" ] || fail "backend/.env is missing." "Run: cd backend && cp .env.example .env && php artisan key:generate"
[ -d "$ROOT/backend/vendor" ] || fail "backend/vendor/ is missing." "Run: cd backend && composer install"
[ -f "$ROOT/backend/database/database.sqlite" ] || fail "backend/database/database.sqlite is missing." "Run: cd backend && touch database/database.sqlite && php artisan migrate --seed"
echo "✔ backend .env, vendor, database present"

[ -d "$ROOT/frontend/node_modules" ] || fail "frontend/node_modules/ is missing." "Run: cd frontend && npm install"
echo "✔ frontend node_modules present"

for port in 8000 3000 8080; do
  if port_busy "$port"; then
    holder="$(lsof -ti :"${port}" 2>/dev/null | head -3 | tr '\n' ' ')"
    fail "Port $port is already in use ${holder:+(PID(s): $holder)}." "Run: bash scripts/stop.sh   (then re-run start)"
  fi
done
echo "✔ ports 8000, 3000, 8080 are free"
echo ""

echo "Starting Laravel API (detached, log: /tmp/kiri_backend.log)..."
cd "$ROOT/backend"
nohup bash -c 'while true; do php artisan serve --host=127.0.0.1 --port=8000; sleep 1; done' </dev/null >>/tmp/kiri_backend.log 2>&1 &
echo $! > /tmp/kiri_backend.pid
disown 2>/dev/null || true
[ "$(wait_for 8000 /api/health 15)" = "1" ] || fail "Laravel API did not respond on port 8000." "See: tail -30 /tmp/kiri_backend.log"
echo "✔ backend UP on 8000"
echo ""

if [ -d "$ROOT/mobile/build/web" ] && command -v python3 >/dev/null 2>&1; then
  echo "Starting Flutter app via serve_flutter.py (detached, log: /tmp/kiri_flutter.log)..."
  nohup python3 "$ROOT/scripts/serve_flutter.py" 8080 "$ROOT/mobile/build/web" </dev/null >>/tmp/kiri_flutter.log 2>&1 &
  echo $! > /tmp/kiri_flutter.pid
  disown 2>/dev/null || true
  echo ""
elif command -v flutter >/dev/null 2>&1; then
  echo "Starting Flutter app (detached, log: /tmp/kiri_flutter.log)..."
  echo "(first build takes several minutes)"
  cd "$ROOT/mobile"
  nohup flutter run -d web-server --web-port 8080 </dev/null >>/tmp/kiri_flutter.log 2>&1 &
  echo $! > /tmp/kiri_flutter.pid
  disown 2>/dev/null || true
  echo ""
else
  echo "⚠ 'flutter' not on PATH and no build found — skipping the Flutter app (backend + admin still start)."
  echo ""
fi

echo "Starting Next.js admin (detached, log: /tmp/kiri_frontend.log)..."
cd "$ROOT/frontend"
nohup npm run dev </dev/null >>/tmp/kiri_frontend.log 2>&1 &
echo $! > /tmp/kiri_frontend.pid
disown 2>/dev/null || true
if [ "$(wait_for 3000 / 45)" = "1" ]; then
  echo "✔ frontend UP on 3000"
else
  echo "❌ Frontend did not respond. Last log lines:"
  tail -20 /tmp/kiri_frontend.log
  fail "Next.js failed to start." "Fix the error above (or paste it for help), then: bash scripts/stop.sh && bash scripts/start.sh"
fi
echo ""

if [ -f /tmp/kiri_flutter.pid ]; then
  if [ "$(wait_for 8080 / 30)" = "1" ]; then
    echo "✔ flutter UP on 8080"
  else
    echo "… Flutter still building — check later: bash scripts/status.sh  /  tail -f /tmp/kiri_flutter.log"
  fi
  echo ""
fi

echo "All running (detached — safe to close this terminal)."
bash "$ROOT/scripts/status.sh"
