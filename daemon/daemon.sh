#!/bin/bash
# LoxBerry Daemon for Harman Kardon AVR Plugin
# Starts and monitors hkavr_daemon.py inside plugin venv

PLUGIN_NAME="harmankardon"

# Load LoxBerry environment
if [ -f /etc/environment ]; then
    . /etc/environment
fi

LBHOMEDIR=${LBHOMEDIR:-"REPLACELBHOMEDIR"}
LBPDATA=${LBPDATA:-"$LBHOMEDIR/data/plugins"}
LBPBIN=${LBPBIN:-"$LBHOMEDIR/bin/plugins"}
LBPLOG=${LBPLOG:-"$LBHOMEDIR/log/plugins"}

VENV_PYTHON="$LBPDATA/$PLUGIN_NAME/venv/bin/python3"
DAEMON_SCRIPT="$LBPBIN/$PLUGIN_NAME/hkavr_daemon.py"
LOG_FILE="$LBPLOG/$PLUGIN_NAME/daemon.log"
PID_FILE="/run/shm/${PLUGIN_NAME}.pid"

# Fallback PID file
if [ ! -d "/run/shm" ]; then
    PID_FILE="/tmp/${PLUGIN_NAME}.pid"
fi

start() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "$PLUGIN_NAME daemon is already running with PID $PID."
            return 0
        fi
        rm -f "$PID_FILE"
    fi

    if [ ! -f "$DAEMON_SCRIPT" ]; then
        REL_DAEMON="$(cd "$(dirname "$0")/../bin" 2>/dev/null && pwd)/hkavr_daemon.py"
        if [ -f "$REL_DAEMON" ]; then
            DAEMON_SCRIPT="$REL_DAEMON"
        else
            echo "<ERROR> Daemon script not found at $DAEMON_SCRIPT."
            return 1
        fi
    fi

    if [ ! -f "$VENV_PYTHON" ]; then
        REL_VENV="$(cd "$(dirname "$0")/../data/venv/bin" 2>/dev/null && pwd)/python3"
        if [ -f "$REL_VENV" ]; then
            VENV_PYTHON="$REL_VENV"
        elif command -v python3 >/dev/null 2>&1; then
            VENV_PYTHON=$(command -v python3)
        else
            echo "<ERROR> Python venv not found at $VENV_PYTHON."
            return 1
        fi
    fi

    mkdir -p "$(dirname "$LOG_FILE")"

    CURRENT_USER=$(id -u -n 2>/dev/null || whoami)
    echo "Starting $PLUGIN_NAME daemon as user $CURRENT_USER..."

    if [ "$CURRENT_USER" = "root" ]; then
        chown -R loxberry:loxberry "$(dirname "$LOG_FILE")" 2>/dev/null
        su - loxberry -c "nohup \"$VENV_PYTHON\" \"$DAEMON_SCRIPT\" >> \"$LOG_FILE\" 2>&1 &"
    else
        nohup "$VENV_PYTHON" "$DAEMON_SCRIPT" >> "$LOG_FILE" 2>&1 &
    fi
    sleep 1

    PID=$(pgrep -f "hkavr_daemon.py" | head -n 1)
    if [ -n "$PID" ]; then
        echo "$PID" > "$PID_FILE" 2>/dev/null
        echo "$PLUGIN_NAME started with PID $PID."
    else
        echo "<WARNING> Daemon started, but PID not found."
    fi
}

stop() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        echo "Stopping $PLUGIN_NAME daemon (PID $PID)..."
        kill -TERM "$PID" 2>/dev/null
        for i in {1..10}; do
            if ! kill -0 "$PID" 2>/dev/null; then
                rm -f "$PID_FILE"
                echo "$PLUGIN_NAME stopped."
                return 0
            fi
            sleep 1
        done
        echo "Process did not stop gracefully. Killing..."
        kill -KILL "$PID" 2>/dev/null
        rm -f "$PID_FILE"
    else
        echo "$PLUGIN_NAME daemon is not running (no PID file)."
        pkill -f "hkavr_daemon.py" 2>/dev/null
    fi
}

status() {
    PID=$(pgrep -f "hkavr_daemon.py" | head -n 1)
    if [ -n "$PID" ]; then
        echo "$PLUGIN_NAME is running with PID $PID."
        return 0
    else
        echo "$PLUGIN_NAME is stopped."
        return 1
    fi
}

case "$1" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    restart)
        stop
        sleep 1
        start
        ;;
    status)
        status
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac

exit 0
