# ============================================================
# Stage 1: Builder
# ============================================================
ARG ALPINE_VERSION=3.24
FROM alpine:${ALPINE_VERSION} AS builder

ARG CURL_VERSION=2.1.1
ENV CURL_IMPERSONATE_VERSION=${CURL_VERSION}

RUN set -xe && \
    apk add --no-cache \
      bash \
      curl \
      patchelf \
    && \
    arch="$(uname -m)" && \
    archive="libcurl-impersonate-v${CURL_IMPERSONATE_VERSION}.${arch}-linux-musl.tar.gz" && \
    curl -fSLo "/tmp/${archive}" \
      "https://github.com/lexiforest/curl-impersonate/releases/download/v${CURL_IMPERSONATE_VERSION}/${archive}" && \
    mkdir -p /usr/local/lib/curl-impersonate && \
    tar xzf "/tmp/${archive}" -C /usr/local/lib/curl-impersonate && \
    rm -f "/tmp/${archive}" && \
    # Set soname so PHP curl extension links to it directly
    patchelf --set-soname libcurl.so.4 \
      /usr/local/lib/curl-impersonate/libcurl-impersonate.so && \
    rm -f /usr/local/lib/curl-impersonate/libcurl-impersonate.la

# ============================================================
# Stage 2: Runtime
# ============================================================
FROM alpine:${ALPINE_VERSION} AS runtime

ARG IMAGE_VERSION=1.1.9
ENV RSSBRIDGE_SYSTEM_VERSION=${IMAGE_VERSION}
ENV CURL_IMPERSONATE=firefox147

LABEL org.opencontainers.image.title="RSS Bridge" \
      org.opencontainers.image.description="RSS-Bridge - generate feeds for websites that don't have one" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.source="https://github.com/LordArrin/rss-bridge"

# Runtime dependencies
RUN set -xe && \
    apk add --no-cache \
      ca-certificates \
      nginx \
      php85 \
      php85-ctype \
      php85-curl \
      php85-dom \
      php85-fileinfo \
      php85-fpm \
      php85-gd \
      php85-iconv \
      php85-intl \
      php85-mbstring \
      php85-openssl \
      php85-pdo_sqlite \
      php85-pecl-igbinary \
      php85-pecl-memcached \
      php85-phar \
      php85-simplexml \
      php85-sqlite3 \
      php85-tokenizer \
      php85-xml \
      php85-xmlwriter \
      php85-zip \
      composer \
      curl \
    && \
    # Remove default PHP-FPM pool config
    rm -f /etc/php85/php-fpm.d/www.conf && \
    # Prepare runtime directories
    mkdir -p /run/php85 /app/cache /app/cache/opcache && \
    chown -R nginx:nginx /run/php85 /app/cache && \
    chmod 750 /app/cache/opcache && \
    # Protect libcurl from being upgraded
    echo "libcurl" >> /etc/apk/protected_paths.d/lst && \
    # Clean up package cache
    rm -rf /var/cache/apk/*

# Copy patched curl-impersonate from builder
COPY --from=builder /usr/local/lib/curl-impersonate/ /usr/local/lib/curl-impersonate/

# Replace system libcurl with curl-impersonate
RUN rm -f /usr/lib/libcurl.so.4 /usr/lib/libcurl.so.* && \
    ln -sf /usr/local/lib/curl-impersonate/libcurl-impersonate.so /usr/lib/libcurl.so.4

# Redirect nginx logs to stdout/stderr for Docker
RUN ln -sfT /dev/stderr /var/log/nginx/error.log && \
    ln -sfT /dev/stdout /var/log/nginx/access.log

# Copy configuration files
COPY ./config/php-fpm.conf /etc/php85/php-fpm.conf
COPY ./config/php-fpm-pool.conf /etc/php85/php-fpm.d/rss-bridge.conf
COPY ./config/php.ini /etc/php85/conf.d/90-rss-bridge.ini
COPY ./config/nginx.conf /etc/nginx/http.d/default.conf
COPY LICENSE ./

# Copy application
COPY --chown=nginx:nginx ./ /app/

# Install Composer dependencies
WORKDIR /app
RUN composer install --optimize-autoloader --no-interaction --ignore-platform-reqs --classmap-authoritative

# Make scripts executable
RUN chmod +x /app/bin/* && \
    chmod +x /app/docker-entrypoint.sh

# Health check
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD curl -fsS --compressed "http://localhost/?action=health" || exit 1

EXPOSE 80

ENTRYPOINT ["/app/docker-entrypoint.sh"]