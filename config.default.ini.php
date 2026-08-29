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

; 2 retries is enough for transient DNS/TLS errors.
retries = 2

; DO NOT set useragent - curl-impersonate sets it automatically
;useragent = ""

max_filesize = 20

[cache]

type = "sqlite"
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

[logging]
; Disabled - errors captured by PHP-FPM error_log > docker logs
;file_path = "/dev/stderr"
;file_level = "WARNING"

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
; Using typical container name by default
host = "memcached"
port = 11211

[TelegramBridge]
max_pages = 20

[Telegram2Bridge]
embed_max_size = 20m

[DiscogsBridge]
personal_access_token = ""