#!/bin/sh
set -e

# Copy custom config from /config to /app
if [ -f /config/config.ini.php ]; then
    cp /config/config.ini.php /app/config.ini.php
    chown nginx:nginx /app/config.ini.php
    printf 'Added: config.ini.php -> /app/\n'
fi

# Copy custom bridges from /config/bridges-v2
if [ -d /config/bridges-v2 ]; then
    for f in /config/bridges-v2/*.php; do
        [ -f "$f" ] || continue
        name=$(basename "$f")
        case "$name" in
            *" "*) printf 'Skipping %s (space in name)\n' "$name"; continue ;;
        esac
        mkdir -p /app/bridges-v2
        cp "$f" /app/bridges-v2/
        chown nginx:nginx "/app/bridges-v2/$name"
        printf 'Added: %s -> /app/bridges-v2/\n' "$name"
    done
fi

# Legacy bridges are no longer supported - warn the user
if [ -d /config/bridges ]; then
    echo "WARNING: /config/bridges is deprecated. Legacy bridges are no longer supported."
    echo "Please migrate your custom bridges to /config/bridges-v2 directory."
fi

# Ensure cache directories are writable by nginx
chown -R nginx:nginx /app/cache
chmod 750 /app/cache/opcache

# Clear OPcache file cache on startup
rm -rf /app/cache/opcache/* 2>/dev/null || true

# Clean old cache
php /app/bin/cache-clear

# Generate composer autoloader if missing
if [ ! -f /app/vendor/autoload.php ]; then
    echo "Generating composer autoloader..."
    cd /app && composer dump-autoload --optimize --no-interaction
fi

# Build bridges metadata cache
php /app/bin/cache-bridge-metadata || echo "Warning: cache build failed"

# Start nginx (daemon mode)
nginx

# Start PHP-FPM (foreground)
exec php-fpm85 --nodaemonize