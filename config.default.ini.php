; <?php exit; ?> DO NOT REMOVE THIS LINE

[cache]

type = "memcached"
custom_timeout = false

; Feed responses (HTTP 200) carry Cache-Control: public, max-age=N,
; stale-while-revalidate=M where N equals the effective server-side cache
; timeout (the bridge's CACHE_TIMEOUT, or ?_cache_timeout when
; custom_timeout = true) and M covers the single-flight regeneration lease.
; They also expose a strong ETag validator, so readers and intermediaries
; can revalidate with If-None-Match (or If-Modified-Since) and receive a
; body-less 304 Not Modified whenever the feed content has not changed.

[proxy_profile_direct]

type = "Direct"

[proxy_profile_tgws]

type = "TgWS"
socks_url = ""
; Keep within the timeout cascade (3.2): connect_timeout + request_timeout
; (+1 retry) must stay below php-fpm request_terminate_timeout (90s).
connect_timeout = 5
request_timeout = 20
retries = 1

[authentication]

enable = false
username = "admin"
; Plaintext password. Discouraged: prefer password_hash below.
password = ""
; A password_hash() compatible hash (bcrypt/argon2id). Takes precedence over
; the plaintext password and is safe to commit to config.ini.php.
password_hash = ""
token = ""

[error]

output = "http"
report_limit = 1

[FileCache]

path = ""
enable_purge = true

[SQLiteCache]

file = "/app/cache/cache.sqlite"
enable_purge = true
timeout = 5000

[MemcachedCache]
; All memcached settings (mode, socket/TCP address, server tuning flags and
; PHP client chunking parameters) live in config/memcached.conf. Any key set
; below overrides the corresponding default there. Examples:
;   type = external          ; "internal" (right inside container) or "external" (TCP)
;   host = "10.0.0.5"        ; external mode only
;   port = 11211             ; external mode only
;   memory = 1g              ; server tuning: -m flag
type = internal

; Internal only: path to Unix socket
socket_path = "/var/run/memcached/memcached.sock"

[youtube]

iframe = true
nocookie = true

[Telegram2Bridge]
embed_max_size = 32m
