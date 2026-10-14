#!/usr/bin/env bash
#
# Issue a self-signed TLS certificate for PENTANA and switch nginx to HTTPS.
# Interim measure: replace with an internal-CA or Let's Encrypt certificate
# before members rely on passkey sign-in.
#
# Usage (on the server):
#   sudo bash setup-tls-selfsigned.sh [hostname]
#
# Default hostname is pentana.internal. Pass another as the first argument if
# your internal DNS uses something else — it must match APP_URL in .env.
#
set -euo pipefail

HOSTNAME_FQDN="${1:-pentana.internal}"
SERVER_IP="${SERVER_IP:-192.168.50.20}"
APP_DIR="${APP_DIR:-/var/www/pentana}"
# Detect the running FPM rather than hardcoding, so re-running this after a PHP
# upgrade doesn't point nginx at a stopped socket.
PHP_VER="${PHP_VER:-$(ls /run/php/php*-fpm.sock 2>/dev/null \
    | sed -E 's|.*/php([0-9.]+)-fpm\.sock|\1|' | sort -V | tail -1)}"
PHP_VER="${PHP_VER:-8.4}"
CERT_DIR="/etc/ssl/pentana"
UPLOAD_MAX="20M"
# Apple rejects manually trusted TLS certs valid longer than 398 days.
DAYS=398

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash $0 [hostname]" >&2
    exit 1
fi

echo "==> 1/3  Self-signed certificate for ${HOSTNAME_FQDN}"
install -d -m 755 "$CERT_DIR"

cat > "${CERT_DIR}/openssl.cnf" <<EOF
[req]
distinguished_name = dn
x509_extensions    = v3_req
prompt             = no

[dn]
CN = ${HOSTNAME_FQDN}
O  = PENTANA Internal

[v3_req]
basicConstraints = CA:FALSE
keyUsage         = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName   = @alt

[alt]
DNS.1 = ${HOSTNAME_FQDN}
DNS.2 = pentana
DNS.3 = localhost
IP.1  = ${SERVER_IP}
IP.2  = 127.0.0.1
EOF

openssl req -x509 -nodes -newkey rsa:2048 \
    -keyout "${CERT_DIR}/pentana.key" \
    -out    "${CERT_DIR}/pentana.crt" \
    -days   "$DAYS" -sha256 \
    -config "${CERT_DIR}/openssl.cnf" 2>/dev/null

chmod 600 "${CERT_DIR}/pentana.key"
chmod 644 "${CERT_DIR}/pentana.crt"

echo "==> 2/3  nginx HTTPS site"
# `http2 on;` is nginx >= 1.25. Older builds take http2 as a listen parameter.
NGINX_VER="$(nginx -v 2>&1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
NGINX_MAJOR="${NGINX_VER%%.*}"
NGINX_MINOR="$(echo "$NGINX_VER" | cut -d. -f2)"
if (( NGINX_MAJOR > 1 || (NGINX_MAJOR == 1 && NGINX_MINOR >= 25) )); then
    LISTEN_443="listen 443 ssl;
    http2 on;"
else
    LISTEN_443="listen 443 ssl http2;"
fi

SITE=/etc/nginx/sites-available/pentana
if [[ -f "$SITE" ]]; then cp "$SITE" "${SITE}.bak"; fi

cat > "$SITE" <<EOF
# Plain HTTP exists only to redirect.
server {
    listen 80;
    server_name ${HOSTNAME_FQDN} ${SERVER_IP} _;
    return 301 https://\$host\$request_uri;
}

server {
    ${LISTEN_443}
    server_name ${HOSTNAME_FQDN} ${SERVER_IP} _;
    root ${APP_DIR}/public;

    ssl_certificate     ${CERT_DIR}/pentana.crt;
    ssl_certificate_key ${CERT_DIR}/pentana.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    # No HSTS while the certificate is self-signed — it would pin an
    # untrusted cert and lock clients out of the fallback.

    index index.php;
    charset utf-8;
    client_max_body_size ${UPLOAD_MAX};

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
EOF

if ! nginx -t; then
    echo "nginx config invalid — restoring previous site file." >&2
    if [[ -f "${SITE}.bak" ]]; then
        mv "${SITE}.bak" "$SITE"
    else
        rm -f "$SITE" /etc/nginx/sites-enabled/pentana
    fi
    exit 1
fi
rm -f "${SITE}.bak"

echo "==> 3/3  Reload"
systemctl reload nginx

echo
echo "HTTPS is live on https://${HOSTNAME_FQDN}/ (and https://${SERVER_IP}/)."
openssl x509 -in "${CERT_DIR}/pentana.crt" -noout -subject -dates -ext subjectAltName
echo
echo "Next:"
echo "  * Point internal DNS for ${HOSTNAME_FQDN} at ${SERVER_IP}, or add a hosts entry."
echo "  * Set APP_URL=https://${HOSTNAME_FQDN} in .env, then: php artisan optimize"
echo "  * Distribute ${CERT_DIR}/pentana.crt to client devices to silence warnings."
echo "  * Passkeys need the DNS NAME (not the IP) and a trusted cert to work reliably."
