; <?php exit; ?> DO NOT REMOVE THIS LINE

[system]

env = "prod"
enabled_bridges[] = *
timezone = "UTC"
enable_maintenance_mode = false
max_file_size = 20000000

[http]

; curl-impersonate v1.2.5: TLS 1.3 + HTTP/2 handshake.
; 15s covers slow upstreams without hanging forever.
timeout = 15

; 2 retries is enough for transient DNS/TLS errors.
retries = 2

; DO NOT set useragent — curl-impersonate v1.2.5 sets it automatically
; based on CURL_IMPERSONATE=chrome120 env var. Overriding breaks fingerprint.
;useragent = ""

max_filesize = 20

[cache]

type = "sqlite"
custom_timeout = false

[logging]
; Disabled — errors captured by PHP-FPM error_log > docker logs
;file_path = "/dev/stderr"
;file_level = "WARNING"

[admin]

email = ""
telegram = ""
donations = false

[proxy]

url = ""
name = "Hidden proxy name"
by_bridge = false

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

host = "localhost"
port = 11211

[TelegramBridge]
max_pages = 20

[DiscogsBridge]
personal_access_token = ""