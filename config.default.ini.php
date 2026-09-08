; <?php exit; ?> DO NOT REMOVE THIS LINE

[system]

env = "prod"
enabled_bridges[] = *
timezone = "UTC"
enable_maintenance_mode = false
max_file_size = 20000000

[http]
; 20s covers slow upstreams without hanging forever.
timeout = 20

; 3 retries is enough for transient DNS/TLS errors.
retries = 3

max_filesize = 20

[cache]

type = "memcached"
custom_timeout = false

[proxy]

url = ""
name = "Hidden proxy name"
by_bridge = false

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
password = ""
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

; Performance tuning (internal only, ignored for external)
memory = 512m
max_connections = 1024
threads = 4
item_size_limit = 24M
modern = true
prealloc = true
lock_memory = false
hash_power = 16
backlog = 1024
idle_timeout = 0
verbosity = 0

[TelegramBridge]
max_pages = 20

[Telegram2Bridge]
embed_max_size = 20m

[DiscogsBridge]
personal_access_token = ""