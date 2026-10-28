#!/bin/bash
# postinstall.sh - Harman Kardon AVR Plugin

# Arguments from LoxBerry plugininstall.pl
COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4
PTEMPPATH=$6

# Combine with /etc/environment
if [ -f /etc/environment ]; then
    . /etc/environment
fi

PDATA=${LBPDATA}/$PDIR
PBIN=${LBPBIN}/$PDIR
PLOG=${LBPLOG}/$PDIR
PDAEMON=${LBPDAEMON}/$PDIR

echo "<INFO> Harman Kardon AVR postinstall: Target dir is $PDIR (Version $PVERSION)"

# Create plugin data and log directories
mkdir -p "$PDATA" "$PLOG"

# Setup Python virtual environment in $PDATA/venv
VENV_DIR="$PDATA/venv"
echo "<INFO> Überprüfe Python Virtual Environment in $VENV_DIR..."

if [ ! -d "$VENV_DIR" ]; then
    echo "<INFO> Erstelle isoliertes Python venv..."
    python3 -m venv "$VENV_DIR"
    if [ $? -ne 0 ]; then
        echo "<WARNING> Konnte venv nicht erstellen. Bitte sicherstellen, dass python3-venv installiert ist."
    fi
fi

if [ -f "$VENV_DIR/bin/pip" ]; then
    echo "<INFO> Installiere/Aktualisiere paho-mqtt im venv..."
    "$VENV_DIR/bin/pip" install --upgrade pip
    "$VENV_DIR/bin/pip" install paho-mqtt
    if [ $? -eq 0 ]; then
        echo "<OK> paho-mqtt erfolgreich im venv installiert!"
    else
        echo "<WARNING> venv Installation schlug fehl. Versuche systemweite Installation..."
        pip3 install paho-mqtt --break-system-packages 2>/dev/null || pip3 install paho-mqtt 2>/dev/null
    fi
else
    echo "<INFO> Kein venv pip gefunden, versuche systemweite Installation..."
    pip3 install paho-mqtt --break-system-packages 2>/dev/null || pip3 install paho-mqtt 2>/dev/null
fi

# Ensure permissions on bin and daemon scripts
if [ -d "$PBIN" ]; then
    chmod +x "$PBIN"/* 2>/dev/null
fi
if [ -d "$PDAEMON" ]; then
    chmod +x "$PDAEMON"/* 2>/dev/null
fi
if [ -f "$PDAEMON" ]; then
    chmod +x "$PDAEMON" 2>/dev/null
fi

# Restart daemon
if [ -x "$PDAEMON" ] && [ ! -d "$PDAEMON" ]; then
    echo "<INFO> Starte Harman Kardon AVR Daemon via $PDAEMON..."
    "$PDAEMON" restart 2>&1
elif [ -x "$PDAEMON/daemon.sh" ]; then
    echo "<INFO> Starte Harman Kardon AVR Daemon via $PDAEMON/daemon.sh..."
    "$PDAEMON/daemon.sh" restart 2>&1
elif [ -x "$PDAEMON/harmankardon" ]; then
    echo "<INFO> Starte Harman Kardon AVR Daemon via $PDAEMON/harmankardon..."
    "$PDAEMON/harmankardon" restart 2>&1
elif [ -f "$PBIN/hkavr_daemon.py" ]; then
    echo "<INFO> Starte Harman Kardon AVR Daemon direkt via Python..."
    pkill -f "hkavr_daemon.py" 2>/dev/null
    VENV_PY="$PDATA/venv/bin/python3"
    PY="python3"
    if [ -x "$VENV_PY" ]; then
        PY="$VENV_PY"
    fi
    nohup "$PY" "$PBIN/hkavr_daemon.py" >> "$PLOG/daemon.log" 2>&1 &
fi

echo "<OK> Harman Kardon AVR Installation abgeschlossen."
exit 0
