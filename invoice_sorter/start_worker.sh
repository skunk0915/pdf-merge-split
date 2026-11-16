#!/usr/bin/env bash
# PDF Invoice Sorter - Local Worker Launcher
# Starts the background AI worker monitoring VPS jobs and running agy

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -f "worker/venv/bin/python3" ]; then
    echo "Creating virtual environment..."
    python3 -m venv worker/venv
    worker/venv/bin/pip install pypdf requests
fi

echo "=================================================="
echo " Starting PDF Invoice Sorter Local AI Worker"
echo " Monitoring: https://invoice-sort.162.43.39.238.sslip.io"
echo " Model: $(grep AGY_MODEL .env 2>/dev/null | cut -d= -f2 || echo 'flash-high')"
echo " Logs: storage/logs/worker.log"
echo " Press Ctrl+C to stop"
echo "=================================================="

exec worker/venv/bin/python3 worker/worker.py
