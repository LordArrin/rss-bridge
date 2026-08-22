#!/bin/sh
set -e

copy_custom() {
    [ -f "$1" ] || return 0
    name=$(basename "$1")
    
    case "$name" in
        *" "*) printf 'Skipping %s (space in name)\n' "$name"; return 0 ;;
        *Bridge.php)
            # Detect if source is bridges-v2 (PSR-4) or legacy
            if echo "$1" | grep -q "bridges-v2"; then
                dest=/app/bridges-v2
            else
                dest=/app/bridges
            fi
            ;;
        *Format.php) dest=/app/formats ;;
        config.ini.php|whitelist.txt|DEBUG) dest=/app ;;
        *) return 0 ;;
    esac
    
    mkdir -p "$dest"
    cp "$1" "$dest/"
    chown nginx:nginx "$dest/$name"
    printf 'Added: %s -> %s/\n' "$name" "$dest"
}

for f in /config/* /config/bridges/* /config/bridges-v2/*; do
    copy_custom "$f"
done

if [ -n "${HTTP_PORT:-}" ]; then
    sed -i "s/listen 80/listen ${HTTP_PORT}/g" /etc/nginx/http.d/default.conf
fi

# Clear OPcache file cache on startup
if [ -d /app/cache/opcache ]; then
    rm -rf /app/cache/opcache/* 2>/dev/null || true
    echo "OPcache file cache cleared"
fi

# Generate composer autoloader if missing
if [ ! -f /app/vendor/autoload.php ]; then
    echo "Generating composer autoloader..."
    cd /app && composer dump-autoload --optimize --no-interaction
fi

# Build bridge metadata cache
php /app/bin/cache-bridge-metadata || echo "Warning: cache build failed (non-fatal)"

# Start nginx
nginx

# Start PHP-FPM
exec php-fpm85 --nodaemonize
