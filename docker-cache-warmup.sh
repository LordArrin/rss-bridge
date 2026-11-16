#!/bin/sh

if [ "$MEMCACHED_TYPE" = "internal" ]; then
    i=0
    while [ "$i" -lt 30 ]; do
        if [ -S "$MEMCACHED_SOCKET_PATH" ]; then
            break
        fi
        i=$((i + 1))
        sleep 1
    done
    if [ ! -S "$MEMCACHED_SOCKET_PATH" ]; then
        echo "WARNING: memcached socket not ready after 30s"
    fi
fi

php /app/bin/cache-prune >/dev/null 2>&1 || true
php /app/bin/cache-bridge-metadata || echo "WARNING: bridge metadata warmup failed"
chown -R nginx:nginx /app/cache
exit 0