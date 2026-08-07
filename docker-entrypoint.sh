#!/bin/sh
set -e

copy_custom() {
    [ -f "$1" ] || return 0
    name=$(basename "$1")
    
    case "$name" in
        *" "*) printf 'Skipping %s (space in name)\n' "$name"; return 0 ;;
        *Bridge.php) dest=/app/bridges ;;
        *Format.php) dest=/app/formats ;;
        config.ini.php|whitelist.txt|DEBUG) dest=/app ;;
        *) return 0 ;;
    esac
    
    mkdir -p "$dest"
    cp "$1" "$dest/"
    chown nginx:nginx "$dest/$name"
    printf 'Added: %s -> %s/\n' "$name" "$dest"
}

for f in /config/* /config/bridges/*; do
    copy_custom "$f"
done

if [ -n "${HTTP_PORT:-}" ]; then
    sed -i "s/listen 80/listen ${HTTP_PORT}/g" /etc/nginx/http.d/default.conf
fi

nginx

exec php-fpm85 --nodaemonize

fi