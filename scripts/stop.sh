#!/usr/bin/env bash
# Stop all Kiribati servers started by scripts/start.sh.
# Usage: bash scripts/stop.sh
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

port_busy() { (echo > "/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1; }

echo "Stopping Kiribati servers..."

# 1. Kill via pidfiles
for name in backend frontend flutter; do
  pidfile="/tmp/kiri_${name}.pid"
  if [ -f "$pidfile" ]; then
    kill "$(cat "$pidfile")" 2>/dev/null || true
    rm -f "$pidfile"
  fi
done
sleep 2

# 2. Fallback: kill whatever still holds our ports (covers orphaned children)
for port in 8000 3000 8080; do
  if port_busy "$port"; then
    if command -v fuser >/dev/null 2>&1; then
      fuser -k "${port}/tcp" >/dev/null 2>&1 || true
    else
      pids="$(lsof -ti :"${port}" 2>/dev/null)"
      if [ -n "$pids" ]; then kill $pids 2>/dev/null || true; fi
    fi
  fi
done
sleep 1

# 3. Verify
all_free=1
for port in 8000 3000 8080; do
  if port_busy "$port"; then
    echo "✘ port $port STILL BUSY — kill it manually: lsof -ti :$port | xargs kill"
    all_free=0
  fi
done
[ "$all_free" = "1" ] && echo "✔ all servers stopped, ports 8000/3000/8080 free."
