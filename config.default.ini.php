; <?php exit; ?> DO NOT REMOVE THIS LINE

[system]

env = "prod"
enabled_bridges[] = *
timezone = "UTC"
enable_maintenance_mode = false

; Admin-defined system message, shown on every page as a unified notification
; (lib/notifications.php). Leave empty to disable. Rendered as plain text:
; HTML is stripped; the URL (if set) becomes a safe hyperlink in the body.
message = ""
message_url = ""
message_title = ""
; One of: info, warning, error
message_level = "info"

; Multiple messages are supported too (in addition to the single message above):
; messages[] = { text = "Second notice", title = "Alert", level = "error" }
; messages[] = "Plain info message"

[http]
; 60s matches upstream rss-bridge. Slow-but-alive sites (news portals, CDN
; edge misses) regularly need >20s for the first byte; 
timeout = 60

; SSRF protection. Before every outbound request the target hostname is
; resolved and each returned IP is checked against the blocked ranges below
; (private 10.0.0.0/8, 192.168.0.0/16, 172.16.0.0/12 and reserved ranges such
; as loopback 127.0.0.0/8 or link-local 169.254.0.0/16 incl. cloud metadata
; endpoints). Requests to forbidden addresses are refused outright. The vetted
; IP is then pinned for libcurl via CURLOPT_RESOLVE so a DNS rebind between
; the check and the actual connect cannot swap the destination. Redirects are
; re-screened hop by hop. Set to false only if you deliberately host bridges
; against internal services.
ssrf_check = true

; CIDR list (IPv4 and IPv6) that requests must never reach. Leave commented
; out to use the built-in secure default (RFC1918 + loopback + link-local +
; CGNAT + multicast/reserved + IPv6 ULA/loopback/link-local). Override with
; your own set, e.g.:
; blocked_ip_ranges[] = "127.0.0.0/8"
; blocked_ip_ranges[] = "10.0.0.0/8"
; blocked_ip_ranges[] = "192.168.0.0/16"
; blocked_ip_ranges[] = "169.254.0.0/16"
; blocked_ip_ranges[] = "::1/128"
; blocked_ip_ranges[] = "fc00::/7"
; blocked_ip_ranges[] = "fe80::/10"

[error]

; Defines how error messages are returned by RSS-Bridge
;
; "feed" = As part of the feed (default)
; "http" = As HTTP error message
; "none" = No errors are reported
output = "http"

; Transient errors only. Timeouts (errno 28) are NOT retried by the client
; (a dead upstream does not recover between attempts), so this value no
; longer multiplies worst-case worker blocking time.
retries = 1

; Maximum size of a downloaded page, in MEGABYTES. getContents() converts
; this value to bytes before handing it to CurlHttpClient (CURLOPT_MAXFILESIZE
; plus the xferinfo progress callback). Set to 0 to disable the limit.
max_filesize = 40

[cache]

type = "memcached"
custom_timeout = false

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

[admin]

email = ""
telegram = ""

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

[youtube]

iframe = true
nocookie = true

[FileCache]

path = ""
enable_purge = true

[SQLiteCache]

file = "/app/cache/cache.sqlite"
enable_purge = true
timeout = 5000

[MemcachedCache]
; type: internal (embedded Unix socket, secure) or external (separate TCP server)
type = internal

; Internal only: path to Unix socket
socket_path = "/var/run/memcached/memcached.sock"

; External only: TCP server address
;host = "127.0.0.1"
;port = 11211

[Telegram2Bridge]
embed_max_size = 32m
