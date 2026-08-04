ARG ALPINE_VERSION=3.24
FROM alpine:${ALPINE_VERSION}

ARG IMAGE_VERSION=1.0.5
ARG CURL_IMPERSONATE_VERSION=1.5.6

LABEL org.opencontainers.image.title="RSS Bridge" \
      org.opencontainers.image.description="RSS-Bridge - generate feeds for websites that don't have one" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.source="https://github.com/LordArrin/rss-bridge"

ENV CURL_IMPERSONATE=firefox147

RUN set -xe && \
    apk add --no-cache \
      bash \
      ca-certificates \
      nginx \
      php85 \
      php85-ctype \
      php85-curl \
      php85-dom \
      php85-fileinfo \
      php85-fpm \
      php85-iconv \
      php85-intl \
      php85-mbstring \
      php85-openssl \
      php85-pdo_sqlite \
      php85-pecl-memcached \
      php85-simplexml \
      php85-sqlite3 \
      php85-xml \
      php85-zip \
      curl \
      patchelf \
    && \
    # --- curl-impersonate v1.5.6 (v2.0.0 musl is broken) ---
    curlimpersonate_version=1.5.6 && \
    arch="$(uname -m)" && \
    archive="libcurl-impersonate-v${curlimpersonate_version}.${arch}-linux-musl.tar.gz" && \
    curl -fSLo "/tmp/${archive}" \
      "https://github.com/lexiforest/curl-impersonate/releases/download/v${curlimpersonate_version}/${archive}" && \
    mkdir -p /usr/local/lib/curl-impersonate && \
    tar xzf "/tmp/${archive}" -C /usr/local/lib/curl-impersonate && \
    rm -f "/tmp/${archive}" && \
    # Set soname so PHP curl extension links to it directly
    patchelf --set-soname libcurl.so.4 \
      /usr/local/lib/curl-impersonate/libcurl-impersonate.so && \
    # Replace system libcurl with curl-impersonate
    rm -f /usr/lib/libcurl.so.4 /usr/lib/libcurl.so.4.8.0 && \
    ln -sf /usr/local/lib/curl-impersonate/libcurl-impersonate.so /usr/lib/libcurl.so.4 && \
    # --- Cleanup ---
    apk del --no-cache patchelf && \
    rm -f /etc/php85/php-fpm.d/www.conf && \
    mkdir -p /run/php85 /app/cache && \
    chown nginx:nginx /run/php85 /app/cache

RUN ln -sfT /dev/stderr /var/log/nginx/error.log && \
    ln -sfT /dev/stdout /var/log/nginx/access.log

COPY ./config/php-fpm.conf /etc/php85/php-fpm.conf
COPY ./config/php-fpm-pool.conf /etc/php85/php-fpm.d/rss-bridge.conf
COPY ./config/php.ini /etc/php85/conf.d/90-rss-bridge.ini
COPY ./config/nginx.conf /etc/nginx/http.d/default.conf
COPY LICENSE ./

COPY --chown=nginx:nginx ./ /app/
RUN chmod +x /app/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/app/docker-entrypoint.sh"]