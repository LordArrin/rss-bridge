ARG ALPINE_VERSION=3.24
FROM alpine:${ALPINE_VERSION} AS builder

ARG CURL_VERSION=2.2.2
ARG BUILD_VERSION=1.31.4
ARG OPENSSL_VERSION=4.0.2
ARG PCRE_VERSION=10.48
ARG MIMALLOC_VERSION=2.5.1
ARG ZLIB_NG_VERSION=2.3.3
ARG BROTLI_URL=https://github.com/wxx9248/ngx_brotli.git
ARG HEADERS_MORE_URL=https://github.com/openresty/headers-more-nginx-module.git

ARG X86_MARCH=x86-64
ARG X86_MTUNE=generic
ARG X86_CFI_FLAGS=""

ARG ARM_MARCH=armv8-a
ARG ARM_MTUNE=generic
ARG ARM_CFI_FLAGS="-mbranch-protection=standard"

RUN set -euxo pipefail && \
    apk update && \
    apk add --no-cache --virtual .curl-build-deps \
      build-base linux-headers cmake ninja make patch autoconf automake pkgconfig libtool gperf \
      xz-libs xz-dev xz-static python3 python3-dev go bzip2 unzip git curl bash ca-certificates patchelf && \
    cd /tmp && \
    git clone --depth 1 -b v${CURL_VERSION} https://github.com/lexiforest/curl-impersonate.git /tmp/curl-impersonate && \
    cd /tmp/curl-impersonate && \
    mkdir -p /tmp/curl-install && \
    NB_PROC=$(grep -c ^processor /proc/cpuinfo) && \
    ARCH=$(uname -m); \
    case "$ARCH" in \
      x86_64) MARCH="${X86_MARCH}"; MTUNE="${X86_MTUNE}"; CFI_FLAGS="${X86_CFI_FLAGS}" ;; \
      aarch64) MARCH="${ARM_MARCH}"; MTUNE="${ARM_MTUNE}"; CFI_FLAGS="${ARM_CFI_FLAGS}" ;; \
    esac; \
    export HARDENING_CFLAGS="-fstack-protector-strong -fstack-clash-protection --param=ssp-buffer-size=4 \
      -Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 ${CFI_FLAGS} \
      -fno-plt -fno-semantic-interposition -ftrivial-auto-var-init=zero -fzero-call-used-regs=used-gpr \
      -ftrapv -fno-delete-null-pointer-checks -fipa-pta -fno-math-errno -fmerge-all-constants -fomit-frame-pointer" && \
    export OPT_CFLAGS="-O3 -march=${MARCH} -mtune=${MTUNE} -pipe -flto=auto ${HARDENING_CFLAGS}" && \
    export OPT_LDFLAGS="-Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack -Wl,-z,defs ${CFI_FLAGS} -flto=auto" && \
    BUILD_ARGS="-DCMAKE_INSTALL_PREFIX=/tmp/curl-install \
      -DCURL_CA_PATH=/etc/ssl/certs \
      -DCURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt \
      -DCMAKE_C_FLAGS=\"$OPT_CFLAGS -fPIC\" \
      -DCMAKE_CXX_FLAGS=\"$OPT_CFLAGS -fPIC\" \
      -DCMAKE_EXE_LINKER_FLAGS=\"$OPT_LDFLAGS\" \
      -DCMAKE_SHARED_LINKER_FLAGS=\"$OPT_LDFLAGS\"" && \
    make prepare-libidn2 BUILD_DIR=build && \
    make build BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" && \
    make checkbuild BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" && \
    make install-strip BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" && \
    IMP_LIB=$(ls /tmp/curl-install/lib*/libcurl-impersonate*.so* 2>/dev/null | head -n 1) && \
    if [ -n "$IMP_LIB" ]; then \
      patchelf --set-soname libcurl.so.4 "$IMP_LIB"; \
    fi && \
    apk del .curl-build-deps

RUN set -euxo pipefail && \
    apk update && \
    apk upgrade --no-cache && \
    build_pkgs="build-base linux-headers fortify-headers ccache wget perl git mold cmake pkgconfig" && \
    apk --no-cache add --virtual .build-deps ${build_pkgs} && \
    cd /tmp && \
    NB_PROC=$(grep -c ^processor /proc/cpuinfo) && \
    ARCH=$(uname -m); \
    case "$ARCH" in \
      x86_64) MARCH="${X86_MARCH}"; MTUNE="${X86_MTUNE}"; CFI_FLAGS="${X86_CFI_FLAGS}" ;; \
      aarch64) MARCH="${ARM_MARCH}"; MTUNE="${ARM_MTUNE}"; CFI_FLAGS="${ARM_CFI_FLAGS}" ;; \
    esac; \
    export HARDENING_CFLAGS="-fstack-protector-strong -fstack-clash-protection --param=ssp-buffer-size=4 \
      -Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 ${CFI_FLAGS} \
      -fno-plt -fno-semantic-interposition -ftrivial-auto-var-init=zero -fzero-call-used-regs=used-gpr \
      -ftrapv -fno-delete-null-pointer-checks -fipa-pta -fno-math-errno -fmerge-all-constants -fomit-frame-pointer" && \
    export OPT_CFLAGS="-O3 -march=${MARCH} -mtune=${MTUNE} -pipe -flto=auto ${HARDENING_CFLAGS}" && \
    export OPT_LDFLAGS="-Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack -Wl,-z,defs ${CFI_FLAGS} -flto=auto" && \
    export CC="ccache gcc" CXX="ccache g++" && \
    wget -O - https://freenginx.org/download/freenginx-${BUILD_VERSION}.tar.gz --tries=3 | tar zxf - -C /tmp && \
    wget -O - https://github.com/openssl/openssl/releases/download/openssl-${OPENSSL_VERSION}/openssl-${OPENSSL_VERSION}.tar.gz --tries=3 | tar xzf - -C /tmp && \
    wget -O - https://github.com/PCRE2Project/pcre2/releases/download/pcre2-${PCRE_VERSION}/pcre2-${PCRE_VERSION}.tar.gz --tries=3 | tar xzf - -C /tmp && \
    git clone --depth 1 ${BROTLI_URL} /tmp/ngx_brotli && \
    cd /tmp/ngx_brotli && git submodule update --init && \
    cd /tmp/ngx_brotli/deps/brotli && mkdir -p out && cd out && \
    cmake \
      -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF \
      -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" -DCMAKE_CXX_FLAGS="$OPT_CFLAGS -fPIC" \
      -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" -DCMAKE_INSTALL_PREFIX=./installed \
      .. && \
    cmake --build . --config Release --target brotlienc brotlidec brotlicommon && make install && \
    cd /tmp/pcre2-${PCRE_VERSION} && mkdir -p build && cd build && \
    cmake \
      -DCMAKE_INSTALL_PREFIX=/usr/local/pcre2 -DBUILD_SHARED_LIBS=OFF -DBUILD_STATIC_LIBS=ON \
      -DPCRE2_SUPPORT_JIT=ON -DPCRE2_SUPPORT_UNICODE=ON -DPCRE2_BUILD_PCRE2GREP=OFF -DPCRE2_BUILD_TESTS=OFF \
      -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
      .. && \
    PATH="/usr/lib/ccache:${PATH}" make -j $NB_PROC && make install && \
    git clone --depth 1 ${HEADERS_MORE_URL} /tmp/ngx_headers_more && \
    git clone --depth 1 -b v${MIMALLOC_VERSION} https://github.com/microsoft/mimalloc.git /tmp/mimalloc && \
    cd /tmp/mimalloc && mkdir -p out/release && cd out/release && \
    cmake -DCMAKE_BUILD_TYPE=Release -DMI_SECURE=ON -DMI_BUILD_SHARED=ON -DMI_BUILD_STATIC=OFF \
          -DMI_BUILD_TESTS=OFF -DMI_BUILD_OBJECT=OFF -DMI_LIBC_MUSL=ON \
          -DCMAKE_INSTALL_PREFIX=/tmp/mimalloc-install -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" \
          -DCMAKE_SHARED_LINKER_FLAGS="$OPT_LDFLAGS" \
          ../.. && \
    PATH="/usr/lib/ccache:${PATH}" make -j $NB_PROC && make install && \
    cd /tmp/openssl-${OPENSSL_VERSION} && \
    LDFLAGS="$OPT_LDFLAGS" ./config \
      --prefix=/usr/local/ssl --openssldir=/usr/local/ssl \
      shared \
      enable-quic enable-tfo enable-ktls no-tests \
      -O3 -march=${MARCH} -mtune=${MTUNE} -pipe -fomit-frame-pointer \
      ${HARDENING_CFLAGS} -Wformat-security -Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 \
      -DOPENSSL_TLS_SECURITY_LEVEL=3 ${CFI_FLAGS} -fuse-ld=mold -flto=auto && \
    PATH="/usr/lib/ccache:${PATH}" make -j $NB_PROC && make install_sw install_ssldirs && \
    git clone --depth 1 -b ${ZLIB_NG_VERSION} https://github.com/zlib-ng/zlib-ng.git /tmp/zlib-ng && \
    cd /tmp/zlib-ng && mkdir -p build && cd build && \
    cmake \
      -DCMAKE_INSTALL_PREFIX=/usr/local/zlib-ng -DZLIB_COMPAT=ON -DBUILD_SHARED_LIBS=OFF \
      -DBUILD_TESTING=OFF -DWITH_OPTIM=ON -DWITH_NEW_STRATEGIES=ON \
      -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
      .. && \
    PATH="/usr/lib/ccache:${PATH}" make -j $NB_PROC && make install && \
    ln -sf /usr/local/zlib-ng/include/zlib.h /usr/include/zlib.h && \
    ln -sf /usr/local/zlib-ng/include/zconf.h /usr/include/zconf.h && \
    cd /tmp/freenginx-${BUILD_VERSION} && \
    ./configure \
      --prefix=/usr/share/nginx --sbin-path=/usr/sbin/nginx \
      --conf-path=/etc/nginx/nginx.conf \
      --error-log-path=/var/log/nginx/error.log \
      --http-log-path=/var/log/nginx/access.log \
      --pid-path=/var/run/nginx.pid \
      --lock-path=/var/run/nginx.lock \
      --http-client-body-temp-path=/var/lib/nginx/tmp/client_body \
      --http-proxy-temp-path=/var/lib/nginx/tmp/proxy \
      --http-fastcgi-temp-path=/var/lib/nginx/tmp/fastcgi \
      --http-uwsgi-temp-path=/var/lib/nginx/tmp/uwsgi \
      --http-scgi-temp-path=/var/lib/nginx/tmp/scgi \
      --with-compat --with-http_auth_request_module --with-http_gunzip_module \
      --with-http_gzip_static_module --with-http_realip_module --with-http_secure_link_module \
      --with-http_slice_module --with-http_ssl_module --with-http_sub_module \
      --with-http_v2_module --with-http_v3_module --with-stream --with-stream_realip_module \
      --with-stream_ssl_module --with-stream_ssl_preread_module \
      --without-http_autoindex_module --without-http_browser_module --without-http_empty_gif_module \
      --without-http_memcached_module --without-http_split_clients_module --without-http_ssi_module \
      --without-http_userid_module --with-file-aio --with-threads \
      --add-module=/tmp/ngx_brotli --add-module=/tmp/ngx_headers_more \
      --with-cc-opt="-I/usr/local/ssl/include -I/usr/local/zlib-ng/include -I/usr/local/pcre2/include $OPT_CFLAGS -fPIE -grecord-gcc-switches -Wformat-security -Wno-error=strict-aliasing -Wno-error=vla-parameter" \
      --with-ld-opt="-L/usr/local/ssl/lib64 -L/usr/local/ssl/lib -L/usr/local/zlib-ng/lib -L/usr/local/pcre2/lib -Wl,-rpath,/usr/local/ssl/lib64 -Wl,-rpath,/usr/local/ssl/lib -fuse-ld=mold -Wl,-pie $OPT_LDFLAGS" \
      --with-pcre-jit && \
    PATH="/usr/lib/ccache:${PATH}" make -j $NB_PROC && \
    strip --strip-unneeded objs/nginx && \
    make install && \
    apk del .build-deps

FROM alpine:${ALPINE_VERSION} AS runtime

ARG IMAGE_VERSION=1.2.1
ENV RSSBRIDGE_SYSTEM_VERSION=${IMAGE_VERSION}
ENV CURL_IMPERSONATE=chrome150
ENV LD_PRELOAD=/usr/lib/libmimalloc-secure.so \
    MIMALLOC_PURGE_DELAY=120 \
    MIMALLOC_ARENA_EAGER_COMMIT=2

LABEL org.opencontainers.image.title="RSS Bridge" \
      org.opencontainers.image.description="RSS-Bridge with Hardened Freenginx" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.source="https://github.com/LordArrin/rss-bridge"

RUN set -xe && \
    apk add --no-cache \
      ca-certificates \
      php85 php85-ctype php85-curl php85-dom php85-fileinfo php85-fpm \
      php85-gd php85-iconv php85-intl php85-mbstring php85-openssl \
      php85-pdo_sqlite php85-pecl-igbinary php85-pecl-memcached php85-phar \
      php85-simplexml php85-sqlite3 php85-tokenizer php85-xml php85-xmlwriter php85-zip \
      composer \
      libgcc libstdc++ libatomic \
      tzdata \
    && \
    update-ca-certificates && \
    rm -f /etc/php85/php-fpm.d/www.conf && \
    mkdir -p /var/run /var/log/nginx /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
             /var/lib/nginx/tmp/fastcgi /var/lib/nginx/tmp/uwsgi /var/lib/nginx/tmp/scgi \
             /run/php85 /app/cache /app/cache/opcache /etc/nginx/http.d /usr/share/nginx/conf \
    && \
    addgroup -S nginx && \
    adduser -D -S -h /var/cache/nginx -s /sbin/nologin -G nginx nginx && \
    chown -R nginx:nginx /var/run /var/log/nginx /var/lib/nginx /run/php85 /app/cache /etc/nginx && \
    chmod 750 /app/cache/opcache && \
    rm -rf /var/cache/apk/*

RUN rm -f /usr/lib/libssl.so* /usr/lib/libcrypto.so*
COPY --from=builder /usr/local/ssl/lib*/libssl.so* /usr/lib/
COPY --from=builder /usr/local/ssl/lib*/libcrypto.so* /usr/lib/
COPY --from=builder /usr/local/ssl/bin/openssl /usr/bin/openssl

RUN SSL_LIB=$(ls /usr/lib/libssl.so* | grep -v '\.so$' | head -n1) && \
    CRYPTO_LIB=$(ls /usr/lib/libcrypto.so* | grep -v '\.so$' | head -n1) && \
    ln -sf "$SSL_LIB" /usr/lib/libssl.so.3 && \
    ln -sf "$SSL_LIB" /usr/lib/libssl.so && \
    ln -sf "$CRYPTO_LIB" /usr/lib/libcrypto.so.3 && \
    ln -sf "$CRYPTO_LIB" /usr/lib/libcrypto.so && \
    ldconfig && \
    echo "libssl3" >> /etc/apk/protected_paths.d/lst && \
    echo "libcrypto3" >> /etc/apk/protected_paths.d/lst

COPY --from=builder /tmp/curl-install/ /tmp/curl-install/

RUN rm -f /usr/lib/libcurl.so.4 /usr/lib/libcurl.so.* && \
    rm -f /usr/bin/curl && \
    cp /tmp/curl-install/lib*/libcurl*.so* /usr/lib/ && \
    cp /tmp/curl-install/bin/curl-impersonate /usr/bin/curl && \
    IMP_LIB=$(ls /usr/lib/libcurl-impersonate*.so* 2>/dev/null | head -n 1) && \
    if [ -n "$IMP_LIB" ]; then \
      ln -sf "$IMP_LIB" /usr/lib/libcurl.so.4; \
      ln -sf "$IMP_LIB" /usr/lib/libcurl.so; \
    fi && \
    ldconfig && \
    echo "libcurl" >> /etc/apk/protected_paths.d/lst && \
    rm -rf /tmp/curl-install

COPY --from=builder /usr/sbin/nginx /usr/sbin/nginx
COPY --from=builder /etc/nginx/mime.types /etc/nginx/
COPY --from=builder /etc/nginx/fastcgi_params /etc/nginx/
COPY --from=builder /etc/nginx/scgi_params /etc/nginx/
COPY --from=builder /etc/nginx/uwsgi_params /etc/nginx/
COPY --from=builder /etc/nginx/win-utf /etc/nginx/
COPY --from=builder /etc/nginx/koi-utf /etc/nginx/
COPY --from=builder /etc/nginx/koi-win /etc/nginx/
COPY --from=builder /tmp/mimalloc-install/lib*/libmimalloc* /usr/lib/

RUN MIMALLOC_LIB=$(find /usr/lib -maxdepth 1 \( -name 'libmimalloc*.so*' -type f -o -type l \) | head -n1) && \
    ln -sf "$MIMALLOC_LIB" /usr/lib/libmimalloc-secure.so

RUN echo "/usr/lib/libmimalloc-secure.so" > /etc/ld.so.preload

RUN ln -sfT /dev/stderr /var/log/nginx/error.log && \
    ln -sfT /dev/stdout /var/log/nginx/access.log

COPY ./config/php-fpm.conf /etc/php85/php-fpm.conf
COPY ./config/php-fpm-pool.conf /etc/php85/php-fpm.d/rss-bridge.conf
COPY ./config/php.ini /etc/php85/conf.d/90-rss-bridge.ini
COPY ./config/nginx-main.conf /etc/nginx/nginx.conf
COPY ./config/nginx.conf /etc/nginx/http.d/default.conf
COPY LICENSE ./

COPY --chown=nginx:nginx ./ /app/

WORKDIR /app
RUN composer install --optimize-autoloader --no-interaction --ignore-platform-reqs --classmap-authoritative

RUN chmod +x /app/bin/* && \
    chmod +x /app/docker-entrypoint.sh

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD curl -fsS --compressed "http://localhost/?action=health" || exit 1

EXPOSE 80

ENTRYPOINT ["/app/docker-entrypoint.sh"]