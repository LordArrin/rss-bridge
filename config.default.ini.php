; <?php exit; ?> DO NOT REMOVE THIS LINE

[system]

env = "prod"
enabled_bridges[] = *
timezone = "UTC"
enable_maintenance_mode = false
max_file_size = 20000000

[http]
; 30s matches upstream rss-bridge. Slow-but-alive sites (news portals, CDN
; edge misses) regularly need >20s for the first byte; a too-low timeout
; turns them into "cURL error 28 ... 0 bytes received".
timeout = 30

; Transient errors only. Timeouts (errno 28) are NOT retried by the client
; (a dead upstream does not recover between attempts), so this value no
; longer multiplies worst-case worker blocking time.
retries = 1

max_filesize = 20

[cache]

type = "memcached"
custom_timeout = false

[proxy_profile_direct]

type = "Direct"

[proxy_profile_tgws]

type = "TgWS"
socks_url = ""
connect_timeout = 30
request_timeout = 120
retries = 3

[admin]

email = ""
telegram = ""

[webdriver]

selenium_server_url = "http://localhost:4444"
headless = false

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
embed_max_size = 28m
