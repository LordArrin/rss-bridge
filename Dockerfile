ARG ALPINE_VERSION=3.24

# Versions
ARG CURL_VERSION=2.3.2
ARG NGINX_VERSION=1.31.5
ARG OPENSSL_VERSION=4.1.0
ARG PCRE_VERSION=10.49
ARG MIMALLOC_VERSION=3.6.1
ARG ZLIB_NG_VERSION=2.3.3
ARG LIBEVENT_VERSION=2.2.2-alpha
ARG MEMCACHED_VERSION=1.6.45
ARG IMAGE_VERSION=1.3.0
ARG VERSION_PLACEHOLDER=__RSSBRIDGE_IMAGE_VERSION__

# URLs
ARG BROTLI_URL=https://github.com/wxx9248/ngx_brotli.git
ARG HEADERS_MORE_URL=https://github.com/openresty/headers-more-nginx-module.git

# Architecture defaults
ARG X86_MARCH=x86-64
ARG X86_MTUNE=generic
ARG X86_CFI_FLAGS=""
ARG ARM_MARCH=armv8-a
ARG ARM_MTUNE=generic
ARG ARM_CFI_FLAGS="-mbranch-protection=standard"

# Build options
ARG BUILD_JOBS=0
ARG ENABLE_LTO=ON

FROM alpine:${ALPINE_VERSION} AS builder

ARG CURL_VERSION NGINX_VERSION OPENSSL_VERSION PCRE_VERSION MIMALLOC_VERSION
ARG ZLIB_NG_VERSION LIBEVENT_VERSION MEMCACHED_VERSION
ARG BROTLI_URL HEADERS_MORE_URL
ARG X86_MARCH X86_MTUNE X86_CFI_FLAGS
ARG ARM_MARCH ARM_MTUNE ARM_CFI_FLAGS
ARG BUILD_JOBS ENABLE_LTO

SHELL ["/bin/ash", "-euo", "pipefail", "-c"]
WORKDIR /tmp

# Toolchain
RUN apk update && apk upgrade --no-cache && \
    apk add --no-cache \
        build-base linux-headers fortify-headers ccache \
        wget perl git mold cmake pkgconfig

ENV CCACHE_DIR=/ccache \
    CCACHE_MAXSIZE=2G \
    PATH=/usr/lib/ccache:$PATH

# Shared compile flags -> /tmp/env.sh, sourced by every build step
RUN <<'SCRIPT'
set -eu

if [ "$BUILD_JOBS" = "0" ]; then NB_PROC="$(nproc)"; else NB_PROC="$BUILD_JOBS"; fi
if [ "$ENABLE_LTO" = "ON" ]; then LTO_FLAG="-flto=auto"; else LTO_FLAG=""; fi

case "$(uname -m)" in
    x86_64)  MARCH="$X86_MARCH"; MTUNE="$X86_MTUNE"; CFI_FLAGS="$X86_CFI_FLAGS" ;;
    aarch64) MARCH="$ARM_MARCH"; MTUNE="$ARM_MTUNE"; CFI_FLAGS="$ARM_CFI_FLAGS" ;;
    *) echo "unsupported arch: $(uname -m)" >&2; exit 1 ;;
esac

HARDENING_CFLAGS="-fstack-protector-strong -fstack-clash-protection --param=ssp-buffer-size=4 \
-Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 ${CFI_FLAGS} \
-fno-plt -fno-semantic-interposition -ftrivial-auto-var-init=zero -fzero-call-used-regs=used-gpr \
-ftrapv -fno-delete-null-pointer-checks -fipa-pta -fno-math-errno -fmerge-all-constants -fomit-frame-pointer"

cat > /tmp/env.sh <<EOF
export NB_PROC='$NB_PROC'
export LTO_FLAG='$LTO_FLAG'
export MARCH='$MARCH' MTUNE='$MTUNE' CFI_FLAGS='$CFI_FLAGS'
export HARDENING_CFLAGS='$HARDENING_CFLAGS'
export OPT_CFLAGS='-O3 -march=$MARCH -mtune=$MTUNE -pipe $LTO_FLAG $HARDENING_CFLAGS'
export OPT_LDFLAGS='-Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack -Wl,-z,defs $CFI_FLAGS $LTO_FLAG'
export CC='ccache gcc' CXX='ccache g++'
EOF

echo "build-env: $(uname -m) march=$MARCH mtune=$MTUNE jobs=$NB_PROC lto='$LTO_FLAG'"
SCRIPT

# PCRE2
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    wget -O - --tries=3 https://github.com/PCRE2Project/pcre2/releases/download/pcre2-${PCRE_VERSION}/pcre2-${PCRE_VERSION}.tar.gz | tar xzf - -C /tmp && \
    cd /tmp/pcre2-${PCRE_VERSION} && \
    mkdir -p build && cd build && \
    cmake \
        -DCMAKE_INSTALL_PREFIX=/usr/local/pcre2 \
        -DBUILD_SHARED_LIBS=OFF \
        -DBUILD_STATIC_LIBS=ON \
        -DPCRE2_SUPPORT_JIT=ON \
        -DPCRE2_SUPPORT_UNICODE=ON \
        -DPCRE2_BUILD_PCRE2GREP=OFF \
        -DPCRE2_BUILD_TESTS=OFF \
        -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" \
        -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
        .. && \
    make -j "$NB_PROC" && make install

# zlib-ng
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    git clone --depth 1 -b ${ZLIB_NG_VERSION} https://github.com/zlib-ng/zlib-ng.git /tmp/zlib-ng && \
    cd /tmp/zlib-ng && \
    mkdir -p build && cd build && \
    cmake \
        -DCMAKE_INSTALL_PREFIX=/usr/local/zlib-ng \
        -DZLIB_COMPAT=ON \
        -DBUILD_SHARED_LIBS=OFF \
        -DBUILD_TESTING=OFF \
        -DWITH_OPTIM=ON \
        -DWITH_NEW_STRATEGIES=ON \
        -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" \
        -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
        .. && \
    make -j "$NB_PROC" && make install && \
    ln -sf /usr/local/zlib-ng/include/zlib.h /usr/include/zlib.h && \
    ln -sf /usr/local/zlib-ng/include/zconf.h /usr/include/zconf.h

# Brotli static deps for ngx_brotli
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    git clone --depth 1 ${BROTLI_URL} /tmp/ngx_brotli && \
    cd /tmp/ngx_brotli && \
    git submodule update --init && \
    cd /tmp/ngx_brotli/deps/brotli && \
    mkdir -p out && cd out && \
    cmake \
        -DCMAKE_BUILD_TYPE=Release \
        -DBUILD_SHARED_LIBS=OFF \
        -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" \
        -DCMAKE_CXX_FLAGS="$OPT_CFLAGS -fPIC" \
        -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
        -DCMAKE_INSTALL_PREFIX=./installed \
        .. && \
    cmake --build . --config Release --target brotlienc brotlidec brotlicommon --parallel "$NB_PROC" && \
    make install

# mimalloc
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    git clone --depth 1 -b v${MIMALLOC_VERSION} https://github.com/microsoft/mimalloc.git /tmp/mimalloc && \
    cd /tmp/mimalloc && \
    mkdir -p out/release && cd out/release && \
    cmake \
        -DCMAKE_BUILD_TYPE=Release \
        -DMI_SECURE=ON \
        -DMI_BUILD_SHARED=ON \
        -DMI_BUILD_STATIC=OFF \
        -DMI_BUILD_TESTS=OFF \
        -DMI_BUILD_OBJECT=OFF \
        -DMI_LIBC_MUSL=ON \
        -DCMAKE_INSTALL_PREFIX=/tmp/mimalloc-install \
        -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" \
        -DCMAKE_SHARED_LINKER_FLAGS="$OPT_LDFLAGS" \
        ../.. && \
    make -j "$NB_PROC" && make install

# libevent (memcached dependency)
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    wget -O - --tries=3 https://github.com/libevent/libevent/releases/download/release-${LIBEVENT_VERSION}/libevent-${LIBEVENT_VERSION}.tar.gz | tar zxf - -C /tmp && \
    cd /tmp/libevent-${LIBEVENT_VERSION} && \
    ./configure \
        --prefix=/usr/local/libevent \
        --disable-shared \
        --enable-static \
        --disable-openssl \
        CFLAGS="$OPT_CFLAGS -fPIC" \
        LDFLAGS="$OPT_LDFLAGS" && \
    make -j "$NB_PROC" && make install

# memcached 
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    wget -O - --tries=3 https://memcached.org/files/memcached-${MEMCACHED_VERSION}.tar.gz | tar zxf - -C /tmp && \
    cd /tmp/memcached-${MEMCACHED_VERSION} && \
    ./configure \
        --prefix=/usr/local/memcached \
        --disable-coverage \
        --enable-static \
        --disable-dependency-tracking \
        --with-libevent=/usr/local/libevent \
        CFLAGS="$OPT_CFLAGS" \
        LDFLAGS="$OPT_LDFLAGS -static" && \
    make -j "$NB_PROC" && \
    if ! file memcached | grep -E 'statically linked|static-pie linked' >/dev/null; then \
        echo "GATE FAILED: memcached is not statically linked:" >&2; \
        file memcached >&2; \
        exit 1; \
    fi && \
    strip --strip-unneeded memcached && \
    make install

# OpenSSL (shared: also replaces system libs in the runtime stage)
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    wget -O - --tries=3 https://github.com/openssl/openssl/releases/download/openssl-${OPENSSL_VERSION}/openssl-${OPENSSL_VERSION}.tar.gz | tar xzf - -C /tmp && \
    cd /tmp/openssl-${OPENSSL_VERSION} && \
    LDFLAGS="$OPT_LDFLAGS" ./config \
        --prefix=/usr/local/ssl \
        --openssldir=/usr/local/ssl \
        shared \
        enable-quic enable-tfo enable-ktls no-tests \
        -O3 -march=${MARCH} -mtune=${MTUNE} -pipe -fomit-frame-pointer \
        ${HARDENING_CFLAGS} -Wformat-security -Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 \
        -DOPENSSL_TLS_SECURITY_LEVEL=3 ${CFI_FLAGS} -fuse-ld=mold ${LTO_FLAG} && \
    make -j "$NB_PROC" && make install_sw install_ssldirs

# curl-impersonate (BoringSSL-based; expensive and bumped often — kept late).
# Build environment intentionally self-contained: no ccache/CC override here.
RUN apk add --no-cache --virtual .curl-build-deps \
        build-base linux-headers cmake ninja make patch autoconf automake pkgconfig libtool gperf \
        xz-libs xz-dev xz-static python3 python3-dev go bzip2 unzip git curl bash ca-certificates patchelf && \
    cd /tmp && \
    git clone --depth 1 -b v${CURL_VERSION} https://github.com/lexiforest/curl-impersonate.git /tmp/curl-impersonate && \
    cd /tmp/curl-impersonate && \
    mkdir -p /tmp/curl-install && \
    if [ "$BUILD_JOBS" = "0" ]; then NB_PROC=$(grep -c ^processor /proc/cpuinfo); else NB_PROC="$BUILD_JOBS"; fi && \
    if [ "$ENABLE_LTO" = "ON" ]; then LTO_FLAG="-flto=auto"; else LTO_FLAG=""; fi && \
    ARCH=$(uname -m); \
    case "$ARCH" in \
      x86_64) MARCH="${X86_MARCH}"; MTUNE="${X86_MTUNE}"; CFI_FLAGS="${X86_CFI_FLAGS}" ;; \
      aarch64) MARCH="${ARM_MARCH}"; MTUNE="${ARM_MTUNE}"; CFI_FLAGS="${ARM_CFI_FLAGS}" ;; \
    esac; \
    export HARDENING_CFLAGS="-fstack-protector-strong -fstack-clash-protection --param=ssp-buffer-size=4 \
      -Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 ${CFI_FLAGS} \
      -fno-plt -fno-semantic-interposition -ftrivial-auto-var-init=zero -fzero-call-used-regs=used-gpr \
      -ftrapv -fno-delete-null-pointer-checks -fipa-pta -fno-math-errno -fmerge-all-constants -fomit-frame-pointer" && \
    export OPT_CFLAGS="-O3 -march=${MARCH} -mtune=${MTUNE} -pipe ${LTO_FLAG} ${HARDENING_CFLAGS}" && \
    export OPT_LDFLAGS="-Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack -Wl,-z,defs ${CFI_FLAGS} ${LTO_FLAG}" && \
    BUILD_ARGS="-DCMAKE_INSTALL_PREFIX=/tmp/curl-install \
      -DCURL_CA_PATH=/etc/ssl/certs \
      -DCURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt \
      -DCMAKE_C_FLAGS=\"$OPT_CFLAGS -fPIC\" \
      -DCMAKE_CXX_FLAGS=\"$OPT_CFLAGS -fPIC\" \
      -DCMAKE_EXE_LINKER_FLAGS=\"$OPT_LDFLAGS\" \
      -DCMAKE_SHARED_LINKER_FLAGS=\"$OPT_LDFLAGS\"" && \
    make prepare-libidn2 BUILD_DIR=build JOBS=$NB_PROC && \
    make build BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" JOBS=$NB_PROC && \
    make checkbuild BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" JOBS=$NB_PROC && \
    make install-strip BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" JOBS=$NB_PROC && \
    test -x /tmp/curl-install/bin/curl-impersonate || \
        { echo "GATE FAILED: curl-impersonate binary missing" >&2; exit 1; } && \
    set -- /tmp/curl-install/lib*/libcurl-impersonate*.so* && \
    if [ -e "$1" ]; then patchelf --set-soname libcurl.so.4 "$1"; fi && \
    if ! strings /tmp/curl-install/lib*/libcurl-impersonate*.so* | grep 'BoringSSL' >/dev/null; then \
        echo "GATE FAILED: BoringSSL signature missing (wrong TLS backend linked?)" >&2; exit 1; \
    fi && \
    apk del .curl-build-deps

# nginx modules
RUN git clone --depth 1 ${HEADERS_MORE_URL} /tmp/ngx_headers_more

# freenginx source
RUN wget -O - --tries=3 https://freenginx.org/download/freenginx-${NGINX_VERSION}.tar.gz | tar zxf - -C /tmp

# Fingerprinting patches: remove Server header (h1/h2/h3) and error-page footer.
# grep assertions fail the build if upstream changes the patched code.
RUN <<'SCRIPT'
set -eu
cd "/tmp/freenginx-${NGINX_VERSION}"

h1=src/http/ngx_http_header_filter_module.c
h2=src/http/v2/ngx_http_v2_filter_module.c
h3=src/http/v3/ngx_http_v3_filter_module.c
sp=src/http/ngx_http_special_response.c

for f in "$h1" "$h2" "$h3"; do
    grep -q 'r->headers_out.server == NULL' "$f" || \
        { echo "ERROR: pattern not found in $f — update the patch" >&2; exit 1; }
    sed -i 's@r->headers_out.server == NULL@0@g' "$f"
done

# Footer is macro-concatenated: "<hr><center>" NGINX_NAME "</center>".
# Strip all three variants (NGINX_VER / NGINX_VER_BUILD / NGINX_NAME).
grep -Eq '"<hr><center>"[[:space:]]+NGINX_(VER_BUILD|VER|NAME)[[:space:]]+"</center>"' "$sp" || \
    { echo "ERROR: footer pattern not found in $sp" >&2; exit 1; }
sed -E -i 's@"<hr><center>"[[:space:]]+NGINX_(VER_BUILD|VER|NAME)[[:space:]]+"</center>"@@g' "$sp"

if grep -q 'r->headers_out.server == NULL' "$h1" "$h2" "$h3" || grep -q '<hr><center>' "$sp"; then
    echo "ERROR: fingerprinting patches were not applied" >&2
    exit 1
fi
echo "fingerprinting patches: OK"
SCRIPT

# Configure + build freenginx
RUN --mount=type=cache,target=/ccache \
    . /tmp/env.sh && \
    cd /tmp/freenginx-${NGINX_VERSION} && \
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
    make -j "$NB_PROC"

# Hardening gates: fail the build if a promised protection is missing.
# Symbol gates run on the unstripped binary.
# NOTE: never use `producer | grep -q` here: under pipefail, grep -q exits
# on the first match, the producer dies with SIGPIPE (141) and the gate
# fails even on success. `grep ... >/dev/null` consumes the full input.
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh
cd "/tmp/freenginx-${NGINX_VERSION}"
BIN=objs/nginx

fail() { echo "HARDENING GATE FAILED: $*" >&2; exit 1; }

# PIE (full ASLR)
file "$BIN" | grep 'pie executable' >/dev/null || fail "binary is not PIE"

# Full RELRO: GNU_RELRO segment + immediate binding
readelf -lW "$BIN" | grep 'GNU_RELRO' >/dev/null || fail "no GNU_RELRO segment"
readelf -dW "$BIN" | grep 'NOW' >/dev/null || fail "no BIND_NOW (partial RELRO only)"

# Non-executable stack
readelf -lW "$BIN" | grep 'GNU_STACK' >/dev/null || fail "no GNU_STACK segment"
if readelf -lW "$BIN" | grep 'GNU_STACK' | grep 'RWE' >/dev/null; then
    fail "executable stack"
fi

# Stack protector (matches __stack_chk_fail and __stack_chk_fail_local)
readelf -sW "$BIN" | grep '__stack_chk_fail' >/dev/null || fail "stack protector dropped"

# OpenSSL is SHARED here: the version string lives in libcrypto, not nginx
strings /usr/local/ssl/lib*/libcrypto.so | grep "OpenSSL ${OPENSSL_VERSION}" >/dev/null || \
    fail "OpenSSL ${OPENSSL_VERSION} not found in libcrypto"
readelf -dW "$BIN" | grep 'libssl' >/dev/null || fail "nginx is not linked against libssl"

# zlib-ng instead of stock zlib (static, embedded into nginx)
strings "$BIN" | grep 'zlib-ng' >/dev/null || fail "zlib-ng signature missing"

# PCRE2 JIT compiled in
readelf -sW "$BIN" | grep 'pcre2_jit_compile' >/dev/null || fail "PCRE2 JIT missing"

# GCC LTO bytecode in our objects (when LTO is enabled)
if [ -n "$LTO_FLAG" ]; then
    readelf -SW objs/src/core/nginx.o | grep '\.gnu\.lto_' >/dev/null || fail "no LTO bytecode in nginx.o"
fi

# CFI instrumentation in our own objects (when CFI flags are set).
# The final-binary note is AND-merged with distro crt objects; Alpine gcc
# is built with --disable-cet, so the note is lost at link time. musl does
# not activate CET at runtime either — gate our objects, report the rest.
if [ -n "$CFI_FLAGS" ]; then
    case "$(uname -m)" in
        x86_64)
            readelf -n objs/src/core/nginx.o | grep 'IBT' >/dev/null || fail "IBT missing in nginx.o"
            readelf -n objs/src/core/nginx.o | grep 'SHSTK' >/dev/null || fail "SHSTK missing in nginx.o"
            readelf -n "$BIN" | grep 'IBT' >/dev/null || \
                echo "NOTE: CET note lost at link time (unmarked distro crt objects)"
            ;;
        aarch64)
            readelf -n objs/src/core/nginx.o | grep 'BTI' >/dev/null || fail "BTI missing in nginx.o"
            readelf -n objs/src/core/nginx.o | grep 'PAC' >/dev/null || fail "PAC missing in nginx.o"
            readelf -n "$BIN" | grep 'BTI' >/dev/null || \
                echo "NOTE: BTI note lost at link time (unmarked OpenSSL asm / distro crt)"
            ;;
    esac
fi

# FORTIFY functional probe: an out-of-bounds copy into a known-size heap
# buffer must trap. Alpine uses fortify-headers (inline checks + ud2),
# so there are no __*_chk symbols to grep for.
cat > /tmp/fortify-probe.c <<'EOF'
#include <stdlib.h>
#include <string.h>
int main(void) {
    char *b = malloc(4);
    volatile char sink;
    if (!b) return 2;
    memcpy(b, "aaaaaaaa", 8);
    sink = b[0];
    (void)sink;
    return 0;
}
EOF
cc $OPT_CFLAGS -o /tmp/fortify-probe /tmp/fortify-probe.c
if /tmp/fortify-probe; then
    fail "FORTIFY probe survived an out-of-bounds copy"
fi
echo "hardening gates: OK"

strip --strip-unneeded "$BIN"
make install
/usr/sbin/nginx -V
ccache -s
SCRIPT

# =============================================================================
# Runtime (unchanged logic; default shell kept so ls|head pipelines stay safe)
# =============================================================================
FROM alpine:${ALPINE_VERSION} AS runtime

ARG IMAGE_VERSION
ARG VERSION_PLACEHOLDER

ENV CURL_IMPERSONATE=firefox155
ENV LD_PRELOAD=/usr/lib/libmimalloc-secure.so \
    MIMALLOC_PURGE_DELAY=210 \
    MIMALLOC_ARENA_EAGER_COMMIT=2

LABEL org.opencontainers.image.title="RSS Bridge" \
      org.opencontainers.image.description="RSS-Bridge - generates web feeds for websites that don't have one" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.source="https://github.com/LordArrin/rss-bridge"

# Runtime dependencies + users
RUN set -xe && \
    apk update && \
    apk upgrade --no-cache && \
    apk add --no-cache \
      ca-certificates \
      # Core
      php85 \
      php85-fpm \
      php85-ctype \
      php85-tokenizer \
      php85-phar \
      # XML/HTML parsing
      php85-dom \
      php85-xml \
      php85-xmlreader \
      php85-xmlwriter \
      php85-simplexml \
      php85-tidy \
      # Strings & encoding
      php85-mbstring \
      php85-intl \
      php85-iconv \
      # Network & security
      php85-curl \
      php85-openssl \
      php85-sodium \
      # Cache & performance
      php85-pecl-memcached \
      php85-pecl-apcu \
      # Serialization & compression
      php85-pecl-igbinary \
      php85-pecl-msgpack \
      php85-pecl-zstd \
      php85-pecl-brotli \
      # Files & databases
      php85-fileinfo \
      php85-sqlite3 \
      php85-pdo_sqlite \
      # Images
      php85-gd \
      # System & processes
      php85-calendar \
      php85-pcntl \
      php85-shmop \
      php85-ffi \
      #
      composer \
      supervisor \
      libgcc libstdc++ libatomic libmemcached \
      tzdata \
    && \
    update-ca-certificates && \
    rm -f /etc/php85/php-fpm.d/www.conf && \
    mkdir -p \
      /var/run \
      /var/log/nginx \
      /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
      /var/lib/nginx/tmp/fastcgi /var/lib/nginx/tmp/uwsgi /var/lib/nginx/tmp/scgi \
      /run/php85 \
      /app/cache /app/cache/opcache \
      /etc/nginx/http.d /usr/share/nginx/conf \
      /var/log/supervisor /etc/supervisor/conf.d \
      /var/run/memcached \
    && \
    addgroup -S nginx && \
    adduser -D -S -h /var/cache/nginx -s /sbin/nologin -G nginx nginx && \
    addgroup -S memcached && \
    adduser -D -S -h /var/cache/memcached -s /sbin/nologin -G memcached memcached && \
    chown -R nginx:nginx /var/run /var/log/nginx /var/lib/nginx /run/php85 /app/cache /etc/nginx && \
    chown memcached:nginx /var/run/memcached && \
    chmod 750 /app/cache/opcache && \
    chmod 770 /var/run/memcached && \
    rm -rf /var/cache/apk/*

# Replace system OpenSSL with the custom build
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

# Replace system curl with curl-impersonate
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

# Freenginx
COPY --from=builder /usr/sbin/nginx /usr/sbin/nginx
COPY --from=builder /etc/nginx/mime.types /etc/nginx/
COPY --from=builder /etc/nginx/fastcgi_params /etc/nginx/
COPY --from=builder /etc/nginx/scgi_params /etc/nginx/
COPY --from=builder /etc/nginx/uwsgi_params /etc/nginx/
COPY --from=builder /etc/nginx/win-utf /etc/nginx/
COPY --from=builder /etc/nginx/koi-utf /etc/nginx/
COPY --from=builder /etc/nginx/koi-win /etc/nginx/

# Memcached
COPY --from=builder /usr/local/memcached/bin/memcached /usr/local/bin/memcached

# Mimalloc: fail on a broken preload instead of silently running without it
COPY --from=builder /tmp/mimalloc-install/lib*/libmimalloc* /usr/lib/

RUN MIMALLOC_LIB=$(find /usr/lib -maxdepth 1 \( -name 'libmimalloc*.so*' -type f -o -type l \) | head -n1) && \
    test -n "$MIMALLOC_LIB" && test -e "$MIMALLOC_LIB" && \
    ln -sf "$MIMALLOC_LIB" /usr/lib/libmimalloc-secure.so && \
    echo "/usr/lib/libmimalloc-secure.so" > /etc/ld.so.preload

RUN ln -sf /usr/bin/openssl /usr/sbin/openssl

# Application configs and source
COPY ./config/php-fpm.conf /etc/php85/php-fpm.conf
COPY ./config/php-fpm-pool.conf.template /app/config/php-fpm-pool.conf.template
COPY ./config/php.ini /etc/php85/conf.d/rss-bridge.ini
COPY ./config/nginx-main.conf /etc/nginx/nginx.conf
COPY ./config/nginx.conf /etc/nginx/http.d/default.conf

COPY ./supervisor/supervisord.conf /etc/supervisord.conf
COPY ./supervisor/nginx.conf /etc/supervisor/conf.d/nginx.conf
COPY ./supervisor/php-fpm.conf /etc/supervisor/conf.d/php-fpm.conf

COPY LICENSE ./
COPY --chown=nginx:nginx ./ /app/

WORKDIR /app

# Immutable version artifact
RUN echo -n "${IMAGE_VERSION}" > /app/VERSION && \
    chown nginx:nginx /app/VERSION && \
    chmod 444 /app/VERSION

RUN composer install --optimize-autoloader --no-interaction --ignore-platform-reqs --classmap-authoritative && \
    sed -i "s/${VERSION_PLACEHOLDER}/${IMAGE_VERSION}/g" /app/index.html && \
    chmod +x /app/bin/* && \
    chmod +x /app/docker-entrypoint.sh

# App-level healthcheck: covers nginx + PHP-FPM, not just nginx config
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
  CMD curl -fsS --compressed "http://localhost/?action=health" || exit 1

EXPOSE 80/tcp 80/udp

RUN find /app -type f \( -name '*.sh' -o -name '*.php' \) -exec sed -i 's/\r$//' {} +

ENTRYPOINT ["/app/docker-entrypoint.sh"]