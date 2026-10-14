#!/usr/bin/env bash
#
# Provision a bare Ubuntu 24.04 host to run PENTANA.
# Installs runtimes + web stack only — it does NOT deploy the application.
# Safe to run before the change request is approved; it leaves an empty server ready.
#
# Usage (on the server, as a user with sudo):
#   sudo bash provision-server.sh
#
set -euo pipefail

APP_USER="${APP_USER:-fahmi}"
APP_DIR="${APP_DIR:-/var/www/pentana}"
PHP_VER="8.3"
NODE_MAJOR="22"
UPLOAD_MAX="20M"
POST_MAX="21M"

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash $0" >&2
    exit 1
fi

echo "==> 1/7  System packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq curl ca-certificates gnupg git unzip acl

echo "==> 2/7  PHP ${PHP_VER} + extensions"
# Ubuntu 24.04 ships PHP 8.3, which satisfies Laravel 12's ^8.2 — no PPA needed.
apt-get install -y -qq \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" \
    "php${PHP_VER}-pgsql" "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" \
    "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-bcmath" \
    "php${PHP_VER}-intl" "php${PHP_VER}-gd"

echo "==> 3/7  PHP upload limits (payment proofs / lunch images)"
cat > "/etc/php/${PHP_VER}/fpm/conf.d/99-pentana.ini" <<EOF
upload_max_filesize = ${UPLOAD_MAX}
post_max_size = ${POST_MAX}
memory_limit = 256M
EOF

echo "==> 4/7  Composer"
if ! command -v composer >/dev/null 2>&1; then
    EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    if [[ "$EXPECTED" != "$ACTUAL" ]]; then
        echo "Composer installer checksum mismatch — aborting." >&2
        rm -f /tmp/composer-setup.php
        exit 1
    fi
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

echo "==> 5/7  Node ${NODE_MAJOR} (NodeSource)"
# Ubuntu's own nodejs is 18.19 — too old for Vite 7 (needs ^20.19 || >=22.12).
if ! command -v node >/dev/null 2>&1 || [[ "$(node -v | cut -c2- | cut -d. -f1)" -lt 20 ]]; then
    mkdir -p /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -qq
    apt-get install -y -qq nodejs
fi

echo "==> 6/7  nginx + application directory"
apt-get install -y -qq nginx
install -d -o "$APP_USER" -g www-data -m 2775 "$APP_DIR"

cat > /etc/nginx/sites-available/pentana <<EOF
server {
    listen 80;
    server_name _;
    root ${APP_DIR}/public;

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
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
EOF

ln -sf /etc/nginx/sites-available/pentana /etc/nginx/sites-enabled/pentana
rm -f /etc/nginx/sites-enabled/default
nginx -t

echo "==> 7/7  Enable services"
systemctl enable --now "php${PHP_VER}-fpm"
systemctl enable --now nginx
systemctl reload nginx

echo
echo "Provisioning complete."
printf '  %-12s %s\n' "PHP"      "$(php -v | head -1)"
printf '  %-12s %s\n' "Composer" "$(composer --version 2>/dev/null | head -1)"
printf '  %-12s %s\n' "Node"     "$(node -v)"
printf '  %-12s %s\n' "nginx"    "$(nginx -v 2>&1)"
printf '  %-12s %s\n' "App dir"  "$APP_DIR (owner ${APP_USER}:www-data)"
echo
echo "Server is ready but EMPTY — no application deployed yet."
echo "Still outstanding before go-live:"
echo "  * HTTPS certificate (passkey sign-in will not work over plain http)"
echo "  * Deploy the app, set .env, run migrations, install the scheduler cron"
