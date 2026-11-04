#!/usr/bin/env bash
# Run all Kiribati servers (Backend, Flutter Web, Next.js Admin) together in the same process group.
# This ensures shared loopback (127.0.0.1) connectivity so Next.js proxying to Laravel works seamlessly.
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

cleanup() {
  echo ""
  echo "Shutting down Kiribati servers..."
  trap - INT TERM EXIT
  kill 0 2>/dev/null || true
  exit 0
}

trap cleanup INT TERM EXIT

echo "=== Starting Kiribati Stack in Shared Environment ==="

# 1. Start Laravel Backend API
echo "Starting Laravel API on port 8000..."
( cd "$ROOT/backend" && while true; do php artisan serve --host=127.0.0.1 --port=8000; sleep 1; done ) &
BACKEND_PID=$!

# Wait for backend to be responsive
echo "Waiting for Laravel API..."
for i in $(seq 1 15); do
  if curl -s -m 2 http://127.0.0.1:8000/api/health >/dev/null 2>&1; then
    echo "✔ Backend is UP on http://127.0.0.1:8000"
    break
  fi
  sleep 1
done

# 2. Start Flutter Web App
echo "Starting Flutter Web on port 8080..."
( while true; do python3 "$ROOT/scripts/serve_flutter.py" 8080 "$ROOT/mobile/build/web"; sleep 1; done ) &
FLUTTER_PID=$!

# 3. Start Next.js Admin Dashboard
echo "Starting Next.js Admin on port 3000..."
( cd "$ROOT/frontend" && while true; do npm run start -- -p 3000; sleep 1; done ) &
FRONTEND_PID=$!

# Wait for frontend to be responsive
echo "Waiting for Next.js Admin..."
for i in $(seq 1 20); do
  if curl -s -m 2 http://127.0.0.1:3000/ >/dev/null 2>&1; then
    echo "✔ Frontend is UP on http://127.0.0.1:3000"
    break
  fi
  sleep 1
done

echo ""
echo "=========================================================="
echo "✔ All services are UP and sharing 127.0.0.1 loopback:"
echo "  - Backend API:    http://127.0.0.1:8000/api/health"
echo "  - Admin Frontend: http://127.0.0.1:3000"
echo "  - Flutter Mobile: http://127.0.0.1:8080"
echo "=========================================================="
echo ""

# Keep running
wait
