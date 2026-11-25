#!/bin/sh
# Container entrypoint for Live Helper Chat.
#
#  1. keeps settings/settings.ini.php on the persistent data volume
#  2. waits for the database
#  3. installs on first boot when LHC_ADMIN_PASSWORD is provided
#  4. applies the bundled database schema (LHC_AUTO_MIGRATE=1, the default)
#  5. runs the given command (apache2-foreground, or docker/scheduler.sh)
set -eu

APP_DIR="${LHC_APP_DIR:-/var/www/html}"
TOOLS_DIR="${LHC_TOOLS_DIR:-/opt/lhc}"
DATA_DIR="${LHC_DATA_DIR:-/data}"
cd "$APP_DIR"

log() { echo "[lhc-entrypoint] $*"; }

# 1. persistent settings file -------------------------------------------------
mkdir -p "$DATA_DIR/settings"
if [ ! -L settings/settings.ini.php ]; then
    if [ -f settings/settings.ini.php ] && [ ! -f "$DATA_DIR/settings/settings.ini.php" ]; then
        mv settings/settings.ini.php "$DATA_DIR/settings/settings.ini.php"
    fi
    rm -f settings/settings.ini.php
    ln -s "$DATA_DIR/settings/settings.ini.php" settings/settings.ini.php
fi

# 2. database reachable? -------------------------------------------------------
if [ -n "${LHC_DB_HOST:-}" ]; then
    log "waiting for database ${LHC_DB_HOST}:${LHC_DB_PORT:-3306}"
    php -r '
        $env = function ($n, $d = "") { $v = getenv($n); if ($v === false && ($f = getenv($n . "_FILE")) && is_readable($f)) { $v = rtrim(file_get_contents($f), "\r\n"); } return $v === false ? $d : $v; };
        $dsn = "mysql:host=" . $env("LHC_DB_HOST") . ";port=" . $env("LHC_DB_PORT", "3306");
        for ($i = 0; $i < 60; $i++) {
            try { new PDO($dsn, $env("LHC_DB_USER"), $env("LHC_DB_PASSWORD")); exit(0); } catch (Exception $e) { sleep(2); }
        }
        fwrite(STDERR, "database not reachable: " . $e->getMessage() . "\n"); exit(1);
    '
fi

# Writable for www-data (owner and group): the installer also checks the group bits.
fix_permissions() {
    chown -R www-data:www-data cache var "$DATA_DIR" 2>/dev/null || true
    chmod -R ug+rwX cache var "$DATA_DIR" 2>/dev/null || true
    chown www-data:www-data settings 2>/dev/null || true
    chmod ug+rwX settings 2>/dev/null || true
}
fix_permissions

is_installed() {
    php -r '$c = @include "settings/settings.ini.php"; exit(is_array($c) && !empty($c["settings"]["site"]["installed"]) ? 0 : 1);'
}

ROLE="${LHC_ROLE:-web}"

# Only the web container installs and migrates; other roles wait for it.
if [ "$ROLE" != "web" ]; then
    until is_installed; do
        log "role=$ROLE waiting for the web container to finish installation"
        sleep 5
    done
fi

# 3. first boot install --------------------------------------------------------
if [ "$ROLE" = "web" ] && ! is_installed; then
    if [ -n "${LHC_ADMIN_PASSWORD:-}${LHC_ADMIN_PASSWORD_FILE:-}" ] && [ "${LHC_SKIP_INSTALL:-0}" != "1" ]; then
        log "not installed yet, installing from environment"
        php "$TOOLS_DIR/install-from-env.php"
    else
        log "not installed yet; open /index.php/install/install or set LHC_ADMIN_PASSWORD for an automatic install"
    fi
fi

# 4. schema migrations ---------------------------------------------------------
if [ "$ROLE" = "web" ] && is_installed && [ "${LHC_AUTO_MIGRATE:-1}" = "1" ]; then
    log "applying bundled database schema"
    php cli/update-db.php || log "schema update reported errors (see above); continuing"
fi

# Files created above as root must stay writable for Apache.
fix_permissions

exec "$@"
