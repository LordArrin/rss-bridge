#!/bin/sh
set -e

for file in /config/*; do
    [ -f "$file" ] || continue

    file_name="$(basename "$file")"

    case "$file_name" in
        *" "*) printf 'Custom file %s has a space in the name and will be skipped.\n' "$file_name"
               continue ;;
    esac

    case "$file_name" in
        *Bridge.php)
            cp "$file" /app/bridges/
            chown nginx:nginx "/app/bridges/$file_name"
            printf 'Custom Bridge %s added.\n' "$file_name"
            ;;
        *Format.php)
            cp "$file" /app/formats/
            chown nginx:nginx "/app/formats/$file_name"
            printf 'Custom Format %s added.\n' "$file_name"
            ;;
        config.ini.php)
            cp "$file" /app/
            chown nginx:nginx "/app/$file_name"
            printf 'Custom config.ini.php added.\n'
            ;;
        whitelist.txt)
            cp "$file" /app/
            chown nginx:nginx "/app/$file_name"
            printf 'Custom whitelist.txt added.\n'
            ;;
        DEBUG)
            cp "$file" /app/
            chown nginx:nginx "/app/$file_name"
            printf 'DEBUG file added.\n'
            ;;
    esac
done

if [ -n "${HTTP_PORT:-}" ]; then
    sed -i "s/listen 80/listen ${HTTP_PORT}/g" /etc/nginx/http.d/default.conf
fi

nginx

exec php-fpm85 --nodaemonize