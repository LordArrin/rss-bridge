ARG ALPINE_VERSION=3.24

# Versions
ARG CURL_VERSION=2.3.3
ARG NGINX_VERSION=1.31.6
ARG OPENSSL_VERSION=4.1.0
ARG PCRE_VERSION=10.49
ARG MIMALLOC_VERSION=3.7.1
ARG ZLIB_NG_VERSION=2.3.3
ARG LIBEVENT_VERSION=2.2.2-alpha
ARG MEMCACHED_VERSION=1.6.45
ARG IMAGE_VERSION=1.3.2
ARG VERSION_PLACEHOLDER=__RSSBRIDGE_IMAGE_VERSION__

# PHP
ARG PHP_VERSION=8.6.0
ARG APCU_VERSION=5.1.28
ARG MSGPACK_VERSION=3.0.1
ARG ZSTD_EXT_VERSION=0.18.0
ARG BROTLI_EXT_VERSION=0.21.1
ARG MEMCACHED_EXT_VERSION=3.4.0
ARG COMPOSER_VERSION=2.11.2

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
ARG PHP_VERSION APCU_VERSION MSGPACK_VERSION ZSTD_EXT_VERSION BROTLI_EXT_VERSION MEMCACHED_EXT_VERSION COMPOSER_VERSION
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

# Shared compile flags -> /tmp/env.sh, sourced by every build step.
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
-Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 -Wformat -Werror=format-security ${CFI_FLAGS} \
-fno-plt -fno-semantic-interposition -ftrivial-auto-var-init=zero -fzero-call-used-regs=used-gpr \
-ftrapv -fno-delete-null-pointer-checks -fipa-pta -fno-math-errno -fmerge-all-constants -fomit-frame-pointer"

cat > /tmp/env.sh <<EOF
export NB_PROC='$NB_PROC'
export LTO_FLAG='$LTO_FLAG'
export MARCH='$MARCH' MTUNE='$MTUNE' CFI_FLAGS='$CFI_FLAGS'
export HARDENING_CFLAGS='$HARDENING_CFLAGS'
export OPT_CFLAGS='-O3 -march=$MARCH -mtune=$MTUNE -pipe -g1 -grecord-gcc-switches $LTO_FLAG $HARDENING_CFLAGS'
export OPT_LDFLAGS='-Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack -Wl,-z,defs -Wl,--build-id=sha1 -Wl,--as-needed $CFI_FLAGS $LTO_FLAG'
export CC='ccache gcc' CXX='ccache g++'
EOF

echo "build-env: $(uname -m) march=$MARCH mtune=$MTUNE jobs=$NB_PROC lto='$LTO_FLAG'"
SCRIPT

# PCRE2 (static, PIC; shared by nginx and PHP via pkg-config)
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

wget -O - --tries=3 "https://github.com/PCRE2Project/pcre2/releases/download/pcre2-${PCRE_VERSION}/pcre2-${PCRE_VERSION}.tar.gz" | tar xzf - -C /tmp
cd "/tmp/pcre2-${PCRE_VERSION}"
mkdir -p build && cd build
cmake \
    -DCMAKE_INSTALL_PREFIX=/usr/local/pcre2 \
    -DCMAKE_INSTALL_LIBDIR=lib \
    -DCMAKE_BUILD_TYPE=Release \
    -DBUILD_SHARED_LIBS=OFF \
    -DBUILD_STATIC_LIBS=ON \
    -DPCRE2_STATIC_PIC=ON \
    -DPCRE2_SUPPORT_JIT=ON \
    -DPCRE2_SUPPORT_UNICODE=ON \
    -DPCRE2_NEVER_BACKSLASH_C=ON \
    -DPCRE2_SUPPORT_LIBZ=OFF \
    -DPCRE2_SUPPORT_LIBBZ2=OFF \
    -DPCRE2_SUPPORT_LIBREADLINE=OFF \
    -DPCRE2_BUILD_PCRE2GREP=OFF \
    -DPCRE2_BUILD_TESTS=OFF \
    -DCMAKE_C_FLAGS="$OPT_CFLAGS" \
    -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
    ..
make -j "$NB_PROC"
make install

# ========================= GATE: PCRE2 install artifacts =========================
fail() { echo "PCRE2 GATE FAILED: $*" >&2; exit 1; }
test -f /usr/local/pcre2/lib/libpcre2-8.a || fail "static archive missing"
test -f /usr/local/pcre2/lib/pkgconfig/libpcre2-8.pc || fail "pkg-config file missing (PHP discovers PCRE2 only via pkg-config)"
grep -q "Version: ${PCRE_VERSION}" /usr/local/pcre2/lib/pkgconfig/libpcre2-8.pc || fail "pkg-config version mismatch"
test -f /usr/local/pcre2/include/pcre2.h || fail "pcre2.h missing (needed for PHP installed headers)"
echo "pcre2 gates: OK"
SCRIPT

# zlib-ng
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

git clone --depth 1 -b "${ZLIB_NG_VERSION}" https://github.com/zlib-ng/zlib-ng.git /tmp/zlib-ng
cd /tmp/zlib-ng
mkdir -p build && cd build
cmake \
    -DCMAKE_INSTALL_PREFIX=/usr/local/zlib-ng \
    -DZLIB_COMPAT=ON \
    -DBUILD_SHARED_LIBS=OFF \
    -DBUILD_TESTING=OFF \
    -DWITH_OPTIM=ON \
    -DWITH_NEW_STRATEGIES=ON \
    -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" \
    -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
    ..
make -j "$NB_PROC"
make install
ln -sf /usr/local/zlib-ng/include/zlib.h /usr/include/zlib.h
ln -sf /usr/local/zlib-ng/include/zconf.h /usr/include/zconf.h

# ========================= GATE: zlib-ng install artifacts =========================
fail() { echo "ZLIB-NG GATE FAILED: $*" >&2; exit 1; }
test -f /usr/local/zlib-ng/lib/libz.a || fail "static archive missing"
test -f /usr/local/zlib-ng/include/zlib.h || fail "zlib.h missing"
echo "zlib-ng gates: OK"
SCRIPT

# Brotli static deps for ngx_brotli
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

git clone --depth 1 "${BROTLI_URL}" /tmp/ngx_brotli
cd /tmp/ngx_brotli
git submodule update --init
cd /tmp/ngx_brotli/deps/brotli
mkdir -p out && cd out
cmake \
    -DCMAKE_BUILD_TYPE=Release \
    -DBUILD_SHARED_LIBS=OFF \
    -DCMAKE_C_FLAGS="$OPT_CFLAGS -fPIC" \
    -DCMAKE_CXX_FLAGS="$OPT_CFLAGS -fPIC" \
    -DCMAKE_EXE_LINKER_FLAGS="$OPT_LDFLAGS" \
    -DCMAKE_INSTALL_PREFIX=./installed \
    ..
cmake --build . --config Release --target brotlienc brotlidec brotlicommon --parallel "$NB_PROC"
make install
SCRIPT

# mimalloc
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

git clone --depth 1 -b "v${MIMALLOC_VERSION}" https://github.com/microsoft/mimalloc.git /tmp/mimalloc
cd /tmp/mimalloc
mkdir -p out/release && cd out/release
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
    ../..
make -j "$NB_PROC"
make install
SCRIPT

# libevent (memcached dependency)
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

wget -O - --tries=3 "https://github.com/libevent/libevent/releases/download/release-${LIBEVENT_VERSION}/libevent-${LIBEVENT_VERSION}.tar.gz" | tar zxf - -C /tmp
cd "/tmp/libevent-${LIBEVENT_VERSION}"
./configure \
    --prefix=/usr/local/libevent \
    --disable-shared \
    --enable-static \
    --disable-openssl \
    --disable-samples \
    CFLAGS="$OPT_CFLAGS -fPIC" \
    LDFLAGS="$OPT_LDFLAGS"
make -j "$NB_PROC"
make install
SCRIPT

# memcached (static)
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

wget -O - --tries=3 "https://memcached.org/files/memcached-${MEMCACHED_VERSION}.tar.gz" | tar zxf - -C /tmp
cd "/tmp/memcached-${MEMCACHED_VERSION}"
./configure \
    --prefix=/usr/local/memcached \
    --disable-coverage \
    --enable-static \
    --disable-dependency-tracking \
    --with-libevent=/usr/local/libevent \
    CFLAGS="$OPT_CFLAGS" \
    LDFLAGS="$OPT_LDFLAGS -static"
make -j "$NB_PROC"

# ========================= GATE: memcached static linkage =========================
fail() { echo "MEMCACHED GATE FAILED: $*" >&2; exit 1; }
if ! file memcached | grep -E 'statically linked|static-pie linked' >/dev/null; then
    file memcached >&2
    fail "memcached is not statically linked"
fi
echo "memcached gates: OK"

strip --strip-unneeded memcached
make install
SCRIPT

# OpenSSL (shared; also replaces system libs in runtime)
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

wget -O - --tries=3 "https://github.com/openssl/openssl/releases/download/openssl-${OPENSSL_VERSION}/openssl-${OPENSSL_VERSION}.tar.gz" | tar xzf - -C /tmp
cd "/tmp/openssl-${OPENSSL_VERSION}"
LDFLAGS="$OPT_LDFLAGS" ./config \
    --prefix=/usr/local/ssl \
    --openssldir=/usr/local/ssl \
    shared \
    enable-quic enable-tfo enable-ktls no-tests \
    -O3 -march="${MARCH}" -mtune="${MTUNE}" -pipe -fomit-frame-pointer \
    ${HARDENING_CFLAGS} \
    -DOPENSSL_TLS_SECURITY_LEVEL=3 -fuse-ld=mold ${LTO_FLAG}
make -j "$NB_PROC"
make install_sw install_ssldirs
SCRIPT

# curl-impersonate (BoringSSL; flags from env.sh, ccache disabled via CC/CXX override)
RUN <<'SCRIPT'
set -eu
apk add --no-cache --virtual .curl-build-deps \
    build-base linux-headers cmake ninja make patch autoconf automake pkgconfig libtool gperf \
    xz-libs xz-dev xz-static python3 python3-dev go bzip2 unzip git curl bash ca-certificates patchelf

. /tmp/env.sh
export CC=gcc CXX=g++

cd /tmp
git clone --depth 1 -b "v${CURL_VERSION}" https://github.com/lexiforest/curl-impersonate.git /tmp/curl-impersonate
cd /tmp/curl-impersonate
mkdir -p /tmp/curl-install

BUILD_ARGS="-DCMAKE_INSTALL_PREFIX=/tmp/curl-install \
  -DCURL_CA_PATH=/etc/ssl/certs \
  -DCURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt \
  -DCMAKE_C_FLAGS=\"$OPT_CFLAGS -fPIC\" \
  -DCMAKE_CXX_FLAGS=\"$OPT_CFLAGS -fPIC\" \
  -DCMAKE_EXE_LINKER_FLAGS=\"$OPT_LDFLAGS\" \
  -DCMAKE_SHARED_LINKER_FLAGS=\"$OPT_LDFLAGS\""

make prepare-libidn2 BUILD_DIR=build JOBS="$NB_PROC"
make build BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" JOBS="$NB_PROC"
make checkbuild BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" JOBS="$NB_PROC"
make install-strip BUILD_DIR=build CMAKE_CONFIGURE_ARGS="$BUILD_ARGS" JOBS="$NB_PROC"

# ========================= GATE: curl-impersonate artifacts =========================
fail() { echo "CURL GATE FAILED: $*" >&2; exit 1; }
test -x /tmp/curl-install/bin/curl-impersonate || fail "curl-impersonate binary missing"
set -- /tmp/curl-install/lib*/libcurl-impersonate*.so*
if [ -e "$1" ]; then patchelf --set-soname libcurl.so.4 "$1"; fi
if ! strings /tmp/curl-install/lib*/libcurl-impersonate*.so* | grep 'BoringSSL' >/dev/null; then
    fail "BoringSSL signature missing (wrong TLS backend linked?)"
fi
echo "curl gates: OK"

apk del .curl-build-deps
SCRIPT

# nginx modules
RUN git clone --depth 1 ${HEADERS_MORE_URL} /tmp/ngx_headers_more

# freenginx source
RUN wget -O - --tries=3 https://freenginx.org/download/freenginx-${NGINX_VERSION}.tar.gz | tar zxf - -C /tmp

# Fingerprinting patches: strip Server header (h1/h2/h3) + error-page footer.
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

grep -Eq '"<hr><center>"[[:space:]]+NGINX_(VER_BUILD|VER|NAME)[[:space:]]+"</center>"' "$sp" || \
    { echo "ERROR: footer pattern not found in $sp" >&2; exit 1; }
sed -E -i 's@"<hr><center>"[[:space:]]+NGINX_(VER_BUILD|VER|NAME)[[:space:]]+"</center>"@@g' "$sp"

# ========================= GATE: fingerprint patches applied =========================
if grep -q 'r->headers_out.server == NULL' "$h1" "$h2" "$h3" || grep -q '<hr><center>' "$sp"; then
    echo "GATE FAILED: fingerprinting patches were not applied" >&2
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
        --with-cc-opt="-I/usr/local/ssl/include -I/usr/local/zlib-ng/include -I/usr/local/pcre2/include $OPT_CFLAGS -fPIE -Wno-error=strict-aliasing -Wno-error=vla-parameter" \
        --with-ld-opt="-L/usr/local/ssl/lib64 -L/usr/local/ssl/lib -L/usr/local/zlib-ng/lib -L/usr/local/pcre2/lib -Wl,-rpath,/usr/local/ssl/lib64 -Wl,-rpath,/usr/local/ssl/lib -fuse-ld=mold -Wl,-pie $OPT_LDFLAGS" \
        --with-pcre-jit && \
    make -j "$NB_PROC"

# nginx gates (symbol gates on unstripped binary; no grep -q on pipes: SIGPIPE).
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh
cd "/tmp/freenginx-${NGINX_VERSION}"
BIN=objs/nginx

fail() { echo "NGINX GATE FAILED: $*" >&2; exit 1; }

# ========================= GATE 1/5: linked component identity =========================
strings /usr/local/ssl/lib*/libcrypto.so | grep "OpenSSL ${OPENSSL_VERSION}" >/dev/null || \
    fail "OpenSSL ${OPENSSL_VERSION} not found in libcrypto"
readelf -dW "$BIN" | grep 'libssl' >/dev/null || fail "nginx is not linked against libssl"
strings "$BIN" | grep 'zlib-ng' >/dev/null || fail "zlib-ng signature missing"
strings "$BIN" | grep "${PCRE_VERSION} 20" >/dev/null || fail "PCRE2 ${PCRE_VERSION} not linked into nginx"
readelf -sW "$BIN" | grep 'pcre2_jit_compile' >/dev/null || fail "PCRE2 JIT missing"
if readelf -dW "$BIN" | grep 'libpcre2' >/dev/null; then
    fail "PCRE2 is linked dynamically, expected static"
fi

# ========================= GATE 2/5: ELF hardening =========================
file "$BIN" | grep 'pie executable' >/dev/null || fail "binary is not PIE"
readelf -lW "$BIN" | grep 'GNU_RELRO' >/dev/null || fail "no GNU_RELRO segment"
readelf -dW "$BIN" | grep 'NOW' >/dev/null || fail "no BIND_NOW (partial RELRO only)"
readelf -lW "$BIN" | grep 'GNU_STACK' >/dev/null || fail "no GNU_STACK segment"
if readelf -lW "$BIN" | grep 'GNU_STACK' | grep 'RWE' >/dev/null; then
    fail "executable stack"
fi
readelf -sW "$BIN" | grep '__stack_chk_fail' >/dev/null || fail "stack protector dropped"

# ========================= GATE 3/5: toolchain provenance (LTO/CFI) =========================
if [ -n "$LTO_FLAG" ]; then
    readelf -SW objs/src/core/nginx.o | grep '\.gnu\.lto_' >/dev/null || fail "no LTO bytecode in nginx.o"
fi

# CFI: gate our objects; final-binary CET note is lost at link (distro crt unmarked).
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

# ========================= GATE 4/5: FORTIFY behaviour probe =========================
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
echo "nginx gates: OK"

# ========================= GATE 5/5: strip last, then install =========================
strip --strip-unneeded "$BIN"
make install
/usr/sbin/nginx -V
ccache -s
SCRIPT

# =============================================================================
# PHP core
# =============================================================================

# FFI not built (no code uses it).
RUN apk add --no-cache \
        autoconf xz \
        libxml2-dev sqlite-dev oniguruma-dev tidyhtml-dev \
        libsodium-dev icu-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libavif-dev \
        libmemcached-dev cyrus-sasl-dev zstd-dev brotli-dev

RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh

cd /tmp/curl-install/lib
ln -sf libcurl-impersonate.so libcurl.so
ln -sf libcurl-impersonate.so libcurl.so.4
CURL_BASE_VERSION=$(sed -n 's/^#define LIBCURL_VERSION "\(.*\)"/\1/p' /tmp/curl-install/include/curl/curlver.h)
test -n "$CURL_BASE_VERSION"
mkdir -p pkgconfig
cat > pkgconfig/libcurl.pc <<EOF
prefix=/tmp/curl-install
exec_prefix=\${prefix}
libdir=\${prefix}/lib
includedir=\${prefix}/include

Name: libcurl
Description: Library to transfer files with URLs (curl-impersonate)
Version: ${CURL_BASE_VERSION}
Libs: -L\${libdir} -lcurl
Cflags: -I\${includedir}
supported_features=SSL IPv6 libz brotli zstd AsynchDNS HTTP2 HTTP3 IDN alt-svc
EOF

cd /tmp
export PKG_CONFIG_PATH="/usr/local/pcre2/lib/pkgconfig:/usr/local/ssl/lib64/pkgconfig:/usr/local/ssl/lib/pkgconfig:/usr/local/zlib-ng/lib/pkgconfig:/tmp/curl-install/lib/pkgconfig"
export LD_LIBRARY_PATH="/usr/local/ssl/lib64:/usr/local/ssl/lib:/tmp/curl-install/lib"
export CFLAGS="$OPT_CFLAGS -fPIE"
export CXXFLAGS="$OPT_CFLAGS -fPIE"
export LDFLAGS="-Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack -Wl,--build-id=sha1 $CFI_FLAGS $LTO_FLAG -fuse-ld=mold"
export EXTRA_LDFLAGS_PROGRAM="-Wl,-pie -Wl,-z,defs"
export EXTENSION_DIR=/usr/local/php/lib/php/extensions

wget -O /tmp/php.tar.xz --tries=3 "https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz"
tar xJf /tmp/php.tar.xz -C /tmp
cd "/tmp/php-${PHP_VERSION}"

# Unversioned config paths; ARG PHP_VERSION is the single source of truth.
# JIT compiled out entirely (--disable-opcache-jit); PCRE JIT stays enabled.
./configure \
    --prefix=/usr/local/php \
    --with-libdir=lib \
    --with-config-file-path=/etc/php \
    --with-config-file-scan-dir=/etc/php/conf.d \
    --enable-option-checking=fatal \
    --enable-cli \
    --enable-fpm \
    --with-fpm-user=nginx \
    --with-fpm-group=nginx \
    --disable-cgi \
    --disable-phpdbg \
    --without-pear \
    --enable-ctype \
    --enable-tokenizer \
    --enable-phar \
    --enable-fileinfo \
    --enable-dom \
    --enable-xml \
    --enable-xmlreader \
    --enable-xmlwriter \
    --enable-simplexml \
    --enable-mbstring \
    --enable-intl \
    --with-iconv \
    --with-curl \
    --with-openssl \
    --with-sodium \
    --with-zlib \
    --with-external-pcre \
    --with-pcre-jit \
    --disable-opcache-jit \
    --with-sqlite3 \
    --with-pdo-sqlite \
    --with-tidy \
    --enable-gd \
    --with-jpeg \
    --with-freetype \
    --with-webp \
    --with-avif \
    --enable-calendar \
    --enable-pcntl \
    --enable-shmop

make -j "$NB_PROC"
make install

# With --with-external-pcre, PHP_INSTALL_HEADERS installs php_pcre.h only.
# php_pcre.h includes "pcre2.h", so PECL builds need it next to php_pcre.h.
# Must be a real file: /usr/local/pcre2 is not copied into the runtime stage.
cp /usr/local/pcre2/include/pcre2.h /usr/local/php/include/php/ext/pcre/pcre2.h

PHP_BIN=/usr/local/php/bin/php
FPM_BIN=/usr/local/php/sbin/php-fpm
fail() { echo "PHP GATE FAILED: $*" >&2; exit 1; }

# ========================= GATE 1/7: artifact, version, extension set =========================
"$PHP_BIN" -v | grep "PHP ${PHP_VERSION} " >/dev/null || fail "version mismatch"
"$PHP_BIN" -v | grep 'Zend OPcache' >/dev/null || fail "opcache missing"
"$FPM_BIN" -v >/dev/null || fail "php-fpm not runnable"

for ext in ctype tokenizer Phar fileinfo dom xml xmlreader xmlwriter SimpleXML mbstring intl iconv curl openssl sodium zlib pcre sqlite3 pdo_sqlite tidy gd calendar pcntl shmop session posix filter; do
    "$PHP_BIN" -m | grep -ix "$ext" >/dev/null || fail "extension missing: $ext"
done

if "$PHP_BIN" -m | grep -ix FFI >/dev/null; then
    fail "FFI extension must not be built"
fi

# ========================= GATE 2/7: linked component identity =========================
"$PHP_BIN" -r 'exit(str_contains(curl_version()["ssl_version"], "BoringSSL") ? 0 : 1);' || fail "curl is not impersonate/BoringSSL"
"$PHP_BIN" -r 'exit(str_contains(OPENSSL_VERSION_TEXT, getenv("OPENSSL_VERSION")) ? 0 : 1);' || fail "OpenSSL version mismatch"
"$PHP_BIN" -r 'exit(str_contains(ZLIB_VERSION, "zlib-ng") ? 0 : 1);' || fail "zlib-ng not linked"

# External PCRE2: runtime version from pcre2_config(); JIT must be compiled in.
"$PHP_BIN" -r 'exit(str_starts_with(PCRE_VERSION, getenv("PCRE_VERSION")) ? 0 : 1);' || fail "PCRE2 version mismatch (expected external ${PCRE_VERSION})"
"$PHP_BIN" -r 'exit((int) ini_get("pcre.jit") === 1 ? 0 : 1);' || fail "PCRE JIT disabled"
readelf -sW "$PHP_BIN" | grep 'pcre2_jit_compile' >/dev/null || fail "PCRE2 JIT symbol missing"

# opcache JIT compiled out: its INI entries must not exist at all.
"$PHP_BIN" -r 'exit(ini_get("opcache.jit") === false ? 0 : 1);' || fail "opcache JIT must be compiled out"

PCRE_HDR=/usr/local/php/include/php/ext/pcre/pcre2.h
test -f "$PCRE_HDR" || fail "pcre2.h missing from installed PHP headers"
test ! -L "$PCRE_HDR" || fail "pcre2.h must be a regular file (symlink would dangle in runtime)"
grep -Eq "#define PCRE2_MAJOR[[:space:]]+${PCRE_VERSION%%.*}$" "$PCRE_HDR" || fail "installed pcre2.h major mismatch"
grep -Eq "#define PCRE2_MINOR[[:space:]]+${PCRE_VERSION##*.}$" "$PCRE_HDR" || fail "installed pcre2.h minor mismatch"

# ========================= GATE 3/7: ELF hardening + static PCRE2 (before strip) =========================
for BIN in "$PHP_BIN" "$FPM_BIN"; do
    file "$BIN" | grep 'pie executable' >/dev/null || fail "binary is not PIE: $BIN"
    readelf -lW "$BIN" | grep 'GNU_RELRO' >/dev/null || fail "no GNU_RELRO: $BIN"
    readelf -dW "$BIN" | grep 'NOW' >/dev/null || fail "no BIND_NOW: $BIN"
    readelf -lW "$BIN" | grep 'GNU_STACK' >/dev/null || fail "no GNU_STACK: $BIN"
    if readelf -lW "$BIN" | grep 'GNU_STACK' | grep 'RWE' >/dev/null; then
        fail "executable stack: $BIN"
    fi
    readelf -sW "$BIN" | grep '__stack_chk_fail' >/dev/null || fail "stack protector dropped: $BIN"
    strings "$BIN" | grep "${PCRE_VERSION} 20" >/dev/null || fail "PCRE2 ${PCRE_VERSION} not linked into $BIN"
    if readelf -dW "$BIN" | grep 'libpcre2' >/dev/null; then
        fail "PCRE2 is linked dynamically, expected static: $BIN"
    fi
done

# ========================= GATE 4/7: toolchain provenance (LTO/CFI) =========================
if [ -n "$LTO_FLAG" ]; then
    readelf -SW main/main.o | grep '\.gnu\.lto_' >/dev/null || fail "no LTO bytecode in main.o"
fi

if [ -n "$CFI_FLAGS" ]; then
    case "$(uname -m)" in
        x86_64)
            readelf -n main/main.o | grep 'IBT' >/dev/null || fail "IBT missing in main.o"
            readelf -n main/main.o | grep 'SHSTK' >/dev/null || fail "SHSTK missing in main.o"
            ;;
        aarch64)
            readelf -n main/main.o | grep 'BTI' >/dev/null || fail "BTI missing in main.o"
            readelf -n main/main.o | grep 'PAC' >/dev/null || fail "PAC missing in main.o"
            ;;
    esac
fi

# ========================= GATE 5/7: compiler behaviour probes =========================
cat > /tmp/ftrapv-probe.c <<'EOF'
#include <limits.h>
int main(void) { volatile int x = INT_MAX; volatile int y = x + 1; (void)y; return 0; }
EOF
cc $OPT_CFLAGS -o /tmp/ftrapv-probe /tmp/ftrapv-probe.c
if /tmp/ftrapv-probe; then
    fail "-ftrapv probe survived a signed overflow"
fi

cat > /tmp/php-fortify-probe.c <<'EOF'
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
cc $OPT_CFLAGS -o /tmp/php-fortify-probe /tmp/php-fortify-probe.c
if /tmp/php-fortify-probe; then
    fail "FORTIFY probe survived an out-of-bounds copy"
fi

# ========================= GATE 6/7: functional smoke =========================
"$PHP_BIN" -r '$x = \Dom\XMLDocument::createFromString("<?xml version=\"1.0\"?><rss version=\"2.0\"><channel><item><title>ok</title></item></channel></rss>", LIBXML_NOERROR | LIBXML_NOWARNING); exit($x->querySelector("item > title")->textContent === "ok" ? 0 : 1);' || fail "XML DOM smoke test failed"
"$PHP_BIN" -r 'fwrite(STDERR, "libxml2 " . LIBXML_DOTTED_VERSION . "\n");'
"$PHP_BIN" -r '$dom = \Dom\HTMLDocument::createFromString("<html><body><p>ok</p></body></html>", LIBXML_NOERROR); exit($dom->querySelector("p")->textContent === "ok" ? 0 : 1);' || fail "DOM smoke test failed"
"$PHP_BIN" -r 'exit(preg_match("/(\d{4})-(\d{2})-(\d{2})/", "2026-11-23", $m) === 1 && $m[1] === "2026" ? 0 : 1);' || fail "PCRE2 match smoke test failed"
"$PHP_BIN" -r 'exit(class_exists("SQLite3") && class_exists("NumberFormatter") && function_exists("imagecreatetruecolor") && function_exists("sodium_crypto_sign_keypair") && function_exists("tidy_parse_string") && function_exists("mb_convert_encoding") ? 0 : 1);' || fail "extension smoke test failed"
echo "php gates: OK"

# ========================= GATE 7/7: strip last =========================
strip --strip-unneeded "$PHP_BIN" "$FPM_BIN"
SCRIPT

# =============================================================================
# PHP extensions (tarball + phpize, explicit CFLAGS export)
# =============================================================================
RUN --mount=type=cache,target=/ccache <<'SCRIPT'
set -eu
. /tmp/env.sh
export PATH=/usr/local/php/bin:$PATH
export PKG_CONFIG_PATH="/usr/local/pcre2/lib/pkgconfig:/usr/local/ssl/lib64/pkgconfig:/usr/local/ssl/lib/pkgconfig:/tmp/curl-install/lib/pkgconfig"
export LD_LIBRARY_PATH="/usr/local/ssl/lib64:/usr/local/ssl/lib:/tmp/curl-install/lib"
export CFLAGS="$OPT_CFLAGS"
# No -Wl,-z,defs here: extensions leave pcre2_*/PHP symbols undefined and
# resolve them from the PHP binary at dlopen time (e.g. APCu -> pcre2_match).
export LDFLAGS="-Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack -Wl,--build-id=sha1 $CFI_FLAGS $LTO_FLAG -fuse-ld=mold"
fail() { echo "EXT GATE FAILED: $*" >&2; exit 1; }

# Legacy API shims for PECL sources against self-built PHP (no-ops if unused).
build_ext() {
    name="$1"
    version="$2"
    shift 2
    wget -O "/tmp/${name}.tgz" --tries=3 "https://pecl.php.net/get/${name}-${version}.tgz"
    mkdir -p "/tmp/ext-${name}"
    tar xzf "/tmp/${name}.tgz" -C "/tmp/ext-${name}" --strip-components=1
    cd "/tmp/ext-${name}"
    find . -name '*.[ch]' -exec sed -i -e 's/XtOffsetOf(/offsetof(/g' -e 's/EMPTY_SWITCH_DEFAULT_CASE()/default: ZEND_UNREACHABLE();/g' -e 's/zval_dtor(/zval_ptr_dtor_nogc(/g' -e 's/php_verror(NULL, "", /php_verror(NULL, /g' -e 's#PG(unserialize_callback_func)\[0\] == .\\0.#ZSTR_LEN(PG(unserialize_callback_func)) == 0#g' -e 's#ZVAL_STRING(&user_func, PG(unserialize_callback_func));#ZVAL_STR(\&user_func, zend_string_dup(PG(unserialize_callback_func), false));#g' {} +
    phpize
    ./configure "$@"
    make -j "$NB_PROC"
    make install
    cd /tmp
}

build_ext apcu "$APCU_VERSION" --enable-apcu
build_ext msgpack "$MSGPACK_VERSION" --with-msgpack
build_ext zstd "$ZSTD_EXT_VERSION" --enable-zstd --with-libzstd
build_ext brotli "$BROTLI_EXT_VERSION" --enable-brotli --with-libbrotli
build_ext memcached "$MEMCACHED_EXT_VERSION" \
    --enable-memcached \
    --disable-memcached-session \
    --disable-memcached-sasl \
    --with-libmemcached-dir=/usr

# ========================= GATE 1/4: hardening reached every .so (before strip) =========================
EXTDIR=/usr/local/php/lib/php/extensions
for so in apcu zstd brotli memcached msgpack; do
    readelf -sW "$EXTDIR/$so.so" | grep '__stack_chk_fail' >/dev/null \
        || fail "$so.so: hardening CFLAGS not applied"
done

mkdir -p /etc/php/conf.d /tmp/php-conf
cat > /tmp/php-conf/00-extensions.ini <<'EOF'
extension_dir = /usr/local/php/lib/php/extensions
extension = apcu.so
extension = msgpack.so
extension = zstd.so
extension = brotli.so
extension = memcached.so
apc.enable_cli = 1
EOF
cp /tmp/php-conf/00-extensions.ini /etc/php/conf.d/00-extensions.ini

# ========================= GATE 2/4: all extensions load =========================
for ext in apcu msgpack zstd brotli memcached; do
    php -m | grep -ix "$ext" >/dev/null || fail "$ext not loaded"
done

# ========================= GATE 3/4: functional smoke =========================
php -r 'exit(function_exists("apcu_store") && function_exists("msgpack_pack") && function_exists("zstd_compress") && function_exists("brotli_compress") && class_exists("Memcached") ? 0 : 1);' || fail "extension smoke test"
# APCu calls pcre2_match() directly; symbols resolve from the PHP binary at dlopen.
php -r 'apcu_store("pcre_probe", "v"); $n = 0; foreach (new APCUIterator("/^pcre_probe$/") as $entry) { $n++; } exit($n === 1 ? 0 : 1);' || fail "APCu regex iterator (external PCRE2) smoke test failed"
echo "ext gates: OK"

# ========================= GATE 4/4: strip last =========================
find "$EXTDIR" -name '*.so' -exec strip --strip-unneeded {} +
SCRIPT

RUN wget -O /tmp/composer.phar --tries=3 "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"

# =============================================================================
# Runtime
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

# Runtime deps + users
RUN set -xe && \
    apk update && \
    apk upgrade --no-cache && \
    apk add --no-cache \
      ca-certificates \
      libxml2 sqlite-libs oniguruma tidyhtml-libs libsodium icu-libs \
      libpng freetype libjpeg-turbo libwebp libwebpdemux libwebpmux libavif \
      zstd-libs brotli-libs \
      supervisor \
      libgcc libstdc++ libatomic libmemcached \
      tzdata \
    && \
    update-ca-certificates && \
    mkdir -p \
      /var/run \
      /var/log/nginx \
      /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
      /var/lib/nginx/tmp/fastcgi /var/lib/nginx/tmp/uwsgi /var/lib/nginx/tmp/scgi \
      /run/php \
      /app/cache /app/cache/opcache \
      /etc/php/conf.d /etc/php/php-fpm.d \
      /etc/nginx/http.d /usr/share/nginx/conf \
      /var/log/supervisor /etc/supervisor/conf.d \
      /var/run/memcached \
    && \
    addgroup -S nginx && \
    adduser -D -S -h /var/cache/nginx -s /sbin/nologin -G nginx nginx && \
    addgroup -S memcached && \
    adduser -D -S -h /var/cache/memcached -s /sbin/nologin -G memcached memcached && \
    chown -R nginx:nginx /var/run /var/log/nginx /var/lib/nginx /run/php /app/cache /etc/nginx && \
    addgroup nginx memcached && \
    chown memcached:nginx /var/run/memcached && \
    chmod 750 /app/cache/opcache && \
    chmod 770 /var/run/memcached && \
    rm -rf /var/cache/apk/*

# Replace system OpenSSL
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

# Mimalloc: fail on broken preload
COPY --from=builder /tmp/mimalloc-install/lib*/libmimalloc* /usr/lib/

RUN MIMALLOC_LIB=$(find /usr/lib -maxdepth 1 \( -name 'libmimalloc*.so*' -type f -o -type l \) | head -n1) && \
    test -n "$MIMALLOC_LIB" && test -e "$MIMALLOC_LIB" && \
    ln -sf "$MIMALLOC_LIB" /usr/lib/libmimalloc-secure.so && \
    echo "/usr/lib/libmimalloc-secure.so" > /etc/ld.so.preload

RUN ln -sf /usr/bin/openssl /usr/sbin/openssl

# PHP (unversioned paths)
COPY --from=builder /usr/local/php/ /usr/local/php/
COPY --from=builder /tmp/php-conf/00-extensions.ini /etc/php/conf.d/00-extensions.ini
COPY --from=builder /tmp/composer.phar /usr/local/bin/composer.phar

RUN ln -sf /usr/local/php/bin/php /usr/bin/php && \
    ln -sf /usr/local/php/bin/phpize /usr/bin/phpize && \
    ln -sf /usr/local/php/bin/php-config /usr/bin/php-config && \
    ln -sf /usr/local/php/sbin/php-fpm /usr/sbin/php-fpm && \
    printf '#!/bin/sh\nexec /usr/bin/php /usr/local/bin/composer.phar "$@"\n' > /usr/local/bin/composer && \
    chmod +x /usr/local/bin/composer && \
    php -v && \
    php-fpm -v && \
    php -m | grep -ix memcached >/dev/null && \
    composer --version

# Application configs and source
COPY ./config/php-fpm.conf /etc/php/php-fpm.conf
COPY ./config/php-fpm-pool.conf.template /app/config/php-fpm-pool.conf.template
COPY ./config/php.ini /etc/php/conf.d/rss-bridge.ini
COPY ./config/nginx-main.conf /etc/nginx/nginx.conf
COPY ./config/nginx.conf /etc/nginx/http.d/default.conf

COPY ./supervisor/supervisord.conf /etc/supervisord.conf
COPY ./supervisor/nginx.conf /etc/supervisor/conf.d/nginx.conf
COPY ./supervisor/php-fpm.conf /etc/supervisor/conf.d/php-fpm.conf
COPY ./supervisor/memcached.conf /etc/supervisor/conf.d/memcached.conf
COPY ./supervisor/cache-warmup.conf /etc/supervisor/conf.d/cache-warmup.conf

COPY LICENSE ./
COPY --chown=nginx:nginx ./ /app/

WORKDIR /app

# ========================= GATE: no versioned PHP paths in configs =========================
RUN ! grep -RInE '/etc/php8[0-9]|/run/php8[0-9]|php-fpm8[0-9]' \
      /app/config /app/supervisor /app/bin /etc/nginx/http.d /etc/php \
      || { echo "GATE FAILED: versioned PHP path leaked into configs" >&2; exit 1; }

RUN echo -n "${IMAGE_VERSION}" > /app/VERSION && \
    chown nginx:nginx /app/VERSION && \
    chmod 444 /app/VERSION

RUN find /app -type f \( -name '*.sh' -o -name '*.php' -o -path '*/bin/*' \) -exec sed -i 's/\r$//' {} +

RUN sed -i "s/\"version\": \".*\"/\"version\": \"${IMAGE_VERSION}\"/" /app/composer.json && \
    composer install --no-dev --optimize-autoloader --no-interaction --classmap-authoritative && \
    grep -q "${VERSION_PLACEHOLDER}" /app/documentation/pages/static/index.html && \
    sed -i "s/${VERSION_PLACEHOLDER}/${IMAGE_VERSION}/g" /app/documentation/pages/static/index.html && \
    chmod +x /app/bin/* && \
    chmod +x /app/docker-entrypoint.sh

# Security
RUN rm -rf /sbin/apk /usr/bin/apk /usr/sbin/apk /lib/libapk.so* /usr/lib/libapk* /etc/apk /lib/apk /var/cache/apk

# App-level healthcheck (nginx + PHP-FPM)
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
  CMD curl -fsS --compressed "http://localhost/?action=health" || exit 1

EXPOSE 80/tcp 80/udp

ENTRYPOINT ["/app/docker-entrypoint.sh"]
