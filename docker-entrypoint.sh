#!/bin/sh

set -e

# 1. Load environment variables and generate service configs
# This merges .env.default with /config/.env and creates override files
eval "$(php /app/bin/load-env)"

# 1.5. Import custom bridges config (Fixes host permission issues for PHP-FPM)
if [ -f /config/.bridges ]; then
    cp /config/.bridges /app/.bridges
    chown nginx:nginx /app/.bridges
    chmod 644 /app/.bridges
    echo "Bridges config: imported from /config/.bridges"
else
    echo "Bridges config: using default (.bridges.default)"
fi

# 2. Count custom bridge settings compared to defaults
# Note: We read from /app/.bridges here to ensure consistency with what PHP-FPM will see
CUSTOM_SETTINGS=$(php -r '
    $default = parse_ini_file("/app/.bridges.default", true, INI_SCANNER_RAW) ?: [];
    $user = parse_ini_file("/app/.bridges", true, INI_SCANNER_RAW) ?: [];
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
    echo "Bridges settings: default (no custom overrides)"
else
    echo "Bridges settings: imported ${CUSTOM_SETTINGS} custom overrides"
fi

# 3. Import custom bridges (PHP files)
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
    echo "Bridges code: no custom bridges"
else
    echo "Bridges code: imported ${BRIDGE_COUNT} custom bridges"
fi

# 4. Ensure cache directories are writable
chown -R nginx:nginx /app/cache
chmod 750 /app/cache/opcache
rm -rf /app/cache/opcache/* 2>/dev/null || true

if [ "${CACHE_TYPE:-memcached}" = "file" ]; then
    find "${CACHE_FILE_PATH:-/app/cache}" -maxdepth 1 -name '*.lock' -type f -delete 2>/dev/null || true
fi

# 5. Generate composer autoloader if missing
if [ ! -f /app/vendor/autoload.php ]; then
    cd /app && composer dump-autoload --optimize --no-interaction --classmap-authoritative >/dev/null 2>&1
fi

# 6. Read memcached configuration
eval "$(php /app/config/memcached-config.php)"

if [ "$MEMCACHED_TYPE" = "none" ]; then
    echo "Cache: backend ${CACHE_TYPE} (memcached skipped)"
    rm -f /etc/supervisor/conf.d/memcached.conf
elif [ "$MEMCACHED_TYPE" = "external" ]; then
    echo "Cache: external (${MEMCACHED_HOST}:${MEMCACHED_PORT})"
    rm -f /etc/supervisor/conf.d/memcached.conf
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
    echo "Cache: internal (${MEMCACHED_SOCKET_PATH})"

    mkdir -p "$(dirname "$MEMCACHED_SOCKET_PATH")"
    chown memcached:nginx "$(dirname "$MEMCACHED_SOCKET_PATH")"
    chmod 770 "$(dirname "$MEMCACHED_SOCKET_PATH")"
    rm -f "$MEMCACHED_SOCKET_PATH" 2>/dev/null || true

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
    [ "$MEMCACHED_LOCK_MEMORY" = "true" ] && MEMCACHED_ARGS="$MEMCACHED_ARGS -k"

    export MEMCACHED_ARGS
fi

# 7. Ensure supervisor runtime directory exists
mkdir -p /var/run/supervisor
chmod 700 /var/run/supervisor

# 8. Start supervisord
echo "Starting web services..."
ulimit -c 0
exec supervisord -n -c /etc/supervisord.conf