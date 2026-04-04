#!/bin/sh

# LCDproc service management script for OPNsense
# Ported from pfSense LCDproc package

LCDD_BIN="/usr/local/sbin/LCDd"
LCDD_CONF="/usr/local/etc/LCDd.conf"
LCDPROC_CLIENT="/usr/local/opnsense/scripts/lcdproc/lcdproc_client.php"
PHP_BIN="/usr/local/bin/php"
PIDFILE_LCDD="/var/run/LCDd.pid"

lcdd_running() {
    if [ -f "${PIDFILE_LCDD}" ]; then
        pid=$(cat "${PIDFILE_LCDD}" 2>/dev/null)
        if [ -n "${pid}" ] && kill -0 "${pid}" 2>/dev/null; then
            return 0
        fi
    fi
    # Fallback: check by process name
    pgrep -x LCDd > /dev/null 2>&1
    return $?
}

client_running() {
    pgrep -f "lcdproc_client.php" > /dev/null 2>&1
    return $?
}

stop_service() {
    # Stop lcdproc client first
    client_pids=$(pgrep -f "lcdproc_client.php" 2>/dev/null)
    if [ -n "${client_pids}" ]; then
        for pid in ${client_pids}; do
            kill "${pid}" 2>/dev/null
        done
        sleep 1
    fi

    # Stop LCDd daemon
    if [ -f "${PIDFILE_LCDD}" ]; then
        pid=$(cat "${PIDFILE_LCDD}" 2>/dev/null)
        if [ -n "${pid}" ]; then
            kill "${pid}" 2>/dev/null
            sleep 1
        fi
        rm -f "${PIDFILE_LCDD}"
    fi

    # Ensure everything is stopped
    lcdd_pids=$(pgrep -x LCDd 2>/dev/null)
    if [ -n "${lcdd_pids}" ]; then
        for pid in ${lcdd_pids}; do
            kill "${pid}" 2>/dev/null
        done
    fi
}

start_service() {
    # Check if configuration exists
    if [ ! -f "${LCDD_CONF}" ]; then
        echo "LCDd.conf not found. Run 'configctl template reload OPNsense/LCDproc' first."
        exit 1
    fi

    # Check if LCDd binary exists
    if [ ! -x "${LCDD_BIN}" ]; then
        echo "LCDd binary not found at ${LCDD_BIN}. Install the lcdproc package."
        exit 1
    fi

    # Generate configuration from template
    /usr/local/bin/configctl template reload OPNsense/LCDproc

    # Initialize Matrix Orbital backlight color if configured
    init_mtxorb_backlight

    # Start LCDd daemon with high priority
    nice -n -20 "${LCDD_BIN}" -c "${LCDD_CONF}" -p "${PIDFILE_LCDD}"
    sleep 2

    # Verify LCDd started
    if ! lcdd_running; then
        echo "Failed to start LCDd daemon"
        exit 1
    fi

    # Start lcdproc client if the script exists
    if [ -f "${LCDPROC_CLIENT}" ]; then
        ${PHP_BIN} "${LCDPROC_CLIENT}" &
    fi

    echo "LCDproc started successfully"
}

init_mtxorb_backlight() {
    # Read Matrix Orbital backlight color from config and send initialization
    # command to serial port if needed
    driver=$(/usr/local/bin/configctl -q lcdproc general driver 2>/dev/null || true)
    color=$(/usr/local/bin/configctl -q lcdproc general mtxorb_backlight_color 2>/dev/null || true)
    comport=$(/usr/local/bin/configctl -q lcdproc general comport 2>/dev/null || true)

    if [ "${driver}" != "MtxOrb" ] || [ -z "${color}" ] || [ "${comport}" = "none" ]; then
        return
    fi

    # Map comport option to device path
    case "${comport}" in
        dev_cua0)  dev="/dev/cua0" ;;
        dev_cua1)  dev="/dev/cua1" ;;
        dev_cuau0) dev="/dev/cuau0" ;;
        dev_cuau1) dev="/dev/cuau1" ;;
        dev_cuaU0) dev="/dev/cuaU0" ;;
        dev_cuaU1) dev="/dev/cuaU1" ;;
        dev_ttyU0) dev="/dev/ttyU0" ;;
        dev_ttyU1) dev="/dev/ttyU1" ;;
        dev_ttyU2) dev="/dev/ttyU2" ;;
        dev_ttyU3) dev="/dev/ttyU3" ;;
        *)          return ;;
    esac

    if [ ! -c "${dev}" ]; then
        return
    fi

    # Matrix Orbital RGB backlight color values (octal)
    case "${color}" in
        white)        r="\377" g="\377" b="\377" ;;
        gray)         r="\200" g="\200" b="\200" ;;
        gray_alt)     r="\100" g="\100" b="\100" ;;
        red)          r="\377" g="\000" b="\000" ;;
        green)        r="\000" g="\377" b="\000" ;;
        blue)         r="\000" g="\000" b="\377" ;;
        cyan)         r="\000" g="\377" b="\377" ;;
        yellow)       r="\377" g="\377" b="\000" ;;
        yellow_alt)   r="\377" g="\200" b="\000" ;;
        magenta)      r="\377" g="\000" b="\377" ;;
        magenta_alt)  r="\200" g="\000" b="\377" ;;
        purple)       r="\200" g="\000" b="\200" ;;
        *)            return ;;
    esac

    # Send backlight color command to Matrix Orbital display
    /bin/printf "\376\377${r}${g}${b}" > "${dev}" 2>/dev/null
}

service_status() {
    if lcdd_running; then
        echo "LCDd is running"
        if client_running; then
            echo "LCDproc client is running"
        else
            echo "LCDproc client is not running"
        fi
        exit 0
    else
        echo "LCDd is not running"
        exit 1
    fi
}

case "$1" in
    start)
        stop_service
        start_service
        ;;
    stop)
        stop_service
        echo "LCDproc stopped"
        ;;
    restart)
        stop_service
        start_service
        ;;
    status)
        service_status
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac
