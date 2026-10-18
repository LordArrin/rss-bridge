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
; resolved and each returned IP is screened against the blocked CIDR ranges.
; The vetted IP is pinned via CURLOPT_RESOLVE to prevent DNS-rebinding attacks
; between the check and the actual connect. Redirects are refused to prevent
; bypasses via Location headers pointing at internal addresses.
; Set to false only if you deliberately need bridges to reach internal services.
ssrf_check = true

; Blocked CIDR ranges (IPv4 and IPv6). The built-in secure defaults are ALWAYS
; active and cannot be disabled. Entries below are ADDITIONAL ranges appended
; to the defaults. Single IPs are accepted and converted to /32 or /128.
; Defaults include: RFC 1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16),
; loopback (127.0.0.0/8), link-local (169.254.0.0/16), CGNAT (100.64.0.0/10),
; multicast/reserved, and IPv6 ULA/loopback/link-local equivalents.
; blocked_ip_ranges[] = "203.0.113.0/24"
; blocked_ip_ranges[] = "198.51.100.50"

; Allowed CIDR ranges (whitelist). Entries here take precedence over the
; blocked ranges above. Use this to permit access to specific internal hosts
; or subnets that would otherwise be blocked by the defaults.
; Single IPs are accepted and converted to /32 or /128.
; allowed_ip_ranges[] = "192.168.1.222"
; allowed_ip_ranges[] = "10.0.0.50"
; allowed_ip_ranges[] = "192.168.100.0/24"

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
; All memcached settings (mode, socket/TCP address, server tuning flags and
; PHP client chunking parameters) live in config/memcached.conf. Any key set
; below overrides the corresponding default there. Examples:
;   type = external          ; "internal" (Unix socket) or "external" (TCP)
;   host = "10.0.0.5"        ; external mode only
;   port = 11211             ; external mode only
;   memory = 1g              ; server tuning: -m flag
type = internal

; Internal only: path to Unix socket
socket_path = "/var/run/memcached/memcached.sock"

[Telegram2Bridge]
embed_max_size = 32m
