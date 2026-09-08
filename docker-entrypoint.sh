#!/bin/sh

set -e

# Import custom config
if [ -f /config/config.ini.php ]; then
    cp /config/config.ini.php /app/config.ini.php
    chown nginx:nginx /app/config.ini.php
fi

# Count custom settings compared to defaults
CUSTOM_SETTINGS=$(php -r '
    $default = parse_ini_file("/app/config.default.ini.php", true, INI_SCANNER_RAW) ?: [];
    $user = parse_ini_file("/app/config.ini.php", true, INI_SCANNER_RAW) ?: [];
    $count = 0;
    foreach ($user as $section => $values) {
        if (!is_array($values) || $section === "") continue;
        foreach ($values as $key => $value) {
            $def = $default[$section][$key] ?? null;
            if ($def !== $value) $count++;
        }
    }
    echo $count;
')

if [ "$CUSTOM_SETTINGS" -eq 0 ]; then
    echo "Config: default (no custom settings)"
else
    echo "Config: imported ${CUSTOM_SETTINGS} custom settings"
fi

# Import custom bridges
BRIDGE_COUNT=0
if [ -d /config/bridges-v2 ]; then
    for f in /config/bridges-v2/*.php; do
        [ -f "$f" ] || continue
        name=$(basename "$f")
        case "$name" in
            *" "*) continue ;;
        esac
        mkdir -p /app/bridges-v2
        cp "$f" /app/bridges-v2/
        chown nginx:nginx "/app/bridges-v2/$name"
        BRIDGE_COUNT=$((BRIDGE_COUNT + 1))
    done
fi

if [ "$BRIDGE_COUNT" -eq 0 ]; then
    echo "Bridges: no custom bridges"
else
    echo "Bridges: imported ${BRIDGE_COUNT} custom bridges"
fi

# Ensure cache directories are writable
chown -R nginx:nginx /app/cache
chmod 750 /app/cache/opcache
rm -rf /app/cache/opcache/* 2>/dev/null || true

# Generate composer autoloader if missing
if [ ! -f /app/vendor/autoload.php ]; then
    cd /app && composer dump-autoload --optimize --no-interaction --classmap-authoritative >/dev/null 2>&1
fi

# Read memcached configuration
eval "$(php /app/memcached-config.php)"

if [ "$MEMCACHED_TYPE" = "external" ]; then
    # External TCP memcached: just verify connectivity
    echo "Cache: external (${MEMCACHED_HOST}:${MEMCACHED_PORT})"
    for i in $(seq 1 10); do
        if nc -z "$MEMCACHED_HOST" "$MEMCACHED_PORT" 2>/dev/null; then
            break
        fi
        if [ "$i" = "10" ]; then
            echo "ERROR: External memcached unavailable"
            exit 1
        fi
        sleep 1
    done
else
    # Internal Unix socket memcached: start local instance
    echo "Cache: internal (${MEMCACHED_SOCKET_PATH})"

    # Prepare socket directory
    mkdir -p "$(dirname "$MEMCACHED_SOCKET_PATH")"
    chown memcached:nginx "$(dirname "$MEMCACHED_SOCKET_PATH")"
    chmod 770 "$(dirname "$MEMCACHED_SOCKET_PATH")"

    # Remove stale socket if exists
    rm -f "$MEMCACHED_SOCKET_PATH" 2>/dev/null || true

    # Build memcached command arguments
    MEMCACHED_ARGS="-u memcached -s $MEMCACHED_SOCKET_PATH"
    MEMCACHED_ARGS="$MEMCACHED_ARGS -a 660"
    MEMCACHED_ARGS="$MEMCACHED_ARGS -m $MEMCACHED_MEMORY"
    MEMCACHED_ARGS="$MEMCACHED_ARGS -c $MEMCACHED_MAX_CONNECTIONS"
    MEMCACHED_ARGS="$MEMCACHED_ARGS -t $MEMCACHED_THREADS"
    MEMCACHED_ARGS="$MEMCACHED_ARGS -I $MEMCACHED_ITEM_SIZE_LIMIT"
    MEMCACHED_ARGS="$MEMCACHED_ARGS -o hashpower=$MEMCACHED_HASH_POWER"
    MEMCACHED_ARGS="$MEMCACHED_ARGS -b $MEMCACHED_BACKLOG"

    [ "$MEMCACHED_IDLE_TIMEOUT" != "0" ] && MEMCACHED_ARGS="$MEMCACHED_ARGS -R $MEMCACHED_IDLE_TIMEOUT"
    [ "$MEMCACHED_VERBOSITY" != "0" ] && MEMCACHED_ARGS="$MEMCACHED_ARGS -v"
    [ "$MEMCACHED_MODERN" = "true" ] && MEMCACHED_ARGS="$MEMCACHED_ARGS -o modern"
    [ "$MEMCACHED_PREALLOC" = "true" ] && MEMCACHED_ARGS="$MEMCACHED_ARGS -o slab_reassign -o slab_automove"
    [ "$MEMCACHED_LOCK_MEMORY" = "true" ] && MEMCACHED_ARGS="$MEMCACHED_ARGS -r"

    memcached $MEMCACHED_ARGS &
    MEMCACHED_PID=$!

    # Wait for socket to be created
    for i in $(seq 1 10); do
        if [ -S "$MEMCACHED_SOCKET_PATH" ]; then
            break
        fi
        if [ "$i" = "10" ]; then
            echo "ERROR: Memcached failed to create socket"
            kill $MEMCACHED_PID 2>/dev/null || true
            exit 1
        fi
        sleep 1
    done

    # Ensure correct socket ownership and permissions
    chown memcached:nginx "$MEMCACHED_SOCKET_PATH"
    chmod 660 "$MEMCACHED_SOCKET_PATH"
fi

# Clean old cache
php /app/bin/cache-clear >/dev/null 2>&1 || true

# Build bridges metadata cache
php /app/bin/cache-bridge-metadata

# Fix ownership after cache build
chown -R nginx:nginx /app/cache

# Ensure supervisor runtime directory exists
mkdir -p /var/run/supervisor
chmod 700 /var/run/supervisor

# Start supervisord
echo "Starting web services..."
exec supervisord -n -c /etc/supervisord.conf