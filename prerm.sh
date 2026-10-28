#!/bin/bash
# Pre-removal script for Harman Kardon AVR Plugin

echo "<INFO> Stopping Harman Kardon AVR Daemon..."
pkill -f "hkavr_daemon.py" 2>/dev/null || true

exit 0
