#!/usr/bin/env bash
#
# Move the PENTANA server from Ubuntu's PHP 8.3 to PHP 8.4 (ondrej/php PPA).
#
# Needed because composer.lock resolves symfony/* 8.1 and
# laravel-notification-channels/apn v6.4, which require PHP >= 8.4.
#
# Usage (on the server):
#   sudo bash upgrade-php84.sh
#
set -euo pipefail

OLD_VER="8.3"
NEW_VER="8.4"
UPLOAD_MAX="20M"
POST_MAX="21M"
SITE=/etc/nginx/sites-available/pentana

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash $0" >&2
    exit 1
fi

echo "==> 1/6  ondrej/php PPA"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -qq

echo "==> 2/6  PHP ${NEW_VER} + extensions"
apt-get install -y -qq \
    "php${NEW_VER}-fpm" "php${NEW_VER}-cli" \
    "php${NEW_VER}-pgsql" "php${NEW_VER}-mbstring" "php${NEW_VER}-xml" \
    "php${NEW_VER}-curl" "php${NEW_VER}-zip" "php${NEW_VER}-bcmath" \
    "php${NEW_VER}-intl" "php${NEW_VER}-gd"

echo "==> 3/6  Upload limits"
cat > "/etc/php/${NEW_VER}/fpm/conf.d/99-pentana.ini" <<EOF
upload_max_filesize = ${UPLOAD_MAX}
post_max_size = ${POST_MAX}
memory_limit = 256M
EOF

echo "==> 4/6  Default CLI php -> ${NEW_VER}"
update-alternatives --set php "/usr/bin/php${NEW_VER}"

echo "==> 5/6  Point nginx at the ${NEW_VER} socket"
cp "$SITE" "${SITE}.bak"
sed -i "s|php${OLD_VER}-fpm.sock|php${NEW_VER}-fpm.sock|g" "$SITE"
if ! nginx -t; then
    echo "nginx config invalid — restoring." >&2
    mv "${SITE}.bak" "$SITE"
    exit 1
fi
rm -f "${SITE}.bak"

echo "==> 6/6  Swap the running FPM"
systemctl enable --now "php${NEW_VER}-fpm"
systemctl disable --now "php${OLD_VER}-fpm" 2>/dev/null || true
systemctl reload nginx

echo
echo "PHP is now: $(php -v | head -1)"
systemctl is-active "php${NEW_VER}-fpm" "nginx"
echo "php${OLD_VER}-fpm left installed but stopped and disabled."
