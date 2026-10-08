#!/usr/bin/env bash
#
# Installer for "Image Metadata Analyzer" on Debian/Ubuntu/Kali servers.
# Usage:
#   sudo ./install.sh              # install dependencies only
#   sudo ./install.sh --nginx      # also set up an Nginx site
#   sudo ./install.sh --nginx --domain example.com
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_NGINX=0
DOMAIN="_"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --nginx) INSTALL_NGINX=1; shift ;;
        --domain) DOMAIN="$2"; shift 2 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [[ "$(id -u)" -ne 0 ]]; then
    echo "✖ Run this script with root privileges: sudo ./install.sh"
    exit 1
fi

log() { echo -e "\n\033[1;34m▶ $*\033[0m"; }
ok()  { echo -e "\033[1;32m✔ $*\033[0m"; }
warn(){ echo -e "\033[1;33m! $*\033[0m"; }

require_cmd() { command -v "$1" >/dev/null 2>&1; }

export DEBIAN_FRONTEND=noninteractive

log "Updating package lists"
apt-get update -y

log "Installing analysis tools"
apt-get install -y \
    libimage-exiftool-perl \
    exiv2 \
    imagemagick \
    binwalk \
    binutils \
    steghide \
    foremost \
    file \
    ruby \
    php-cli \
    php-fpm \
    php-mbstring \
    curl || warn "Some packages failed to install (they may already exist)."

log "Installing zsteg (PNG/BMP stego analysis)"
if ! require_cmd zsteg; then
    if gem install zsteg >/dev/null 2>&1; then
        ok "zsteg installed"
    else
        warn "zsteg install failed; the app will handle its absence."
    fi
else
    ok "zsteg already present"
fi

log "Tuning PHP upload limits"
for ini in /etc/php/*/fpm/php.ini /etc/php/*/cli/php.ini; do
    [[ -f "$ini" ]] || continue
    sed -i -E 's/^;?\s*upload_max_filesize\s*=.*/upload_max_filesize = 25M/' "$ini"
    sed -i -E 's/^;?\s*post_max_size\s*=.*/post_max_size = 30M/' "$ini"
    sed -i -E 's/^;?\s*max_execution_time\s*=.*/max_execution_time = 120/' "$ini"
    sed -i -E 's/^;?\s*max_input_time\s*=.*/max_input_time = 120/' "$ini"
done
ok "php.ini tuned"

log "Preparing storage folders and permissions"
WEB_USER="www-data"
[[ -d /var/www ]] || WEB_USER="www-data"
mkdir -p "$APP_DIR/storage/uploads" "$APP_DIR/storage/tmp"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage" 2>/dev/null || true
chmod -R 775 "$APP_DIR/storage"
ok "Web user: $WEB_USER"

if [[ "$INSTALL_NGINX" -eq 1 ]]; then
    log "Setting up Nginx"
    apt-get install -y nginx

    PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
    if [[ -z "$PHP_SOCK" ]]; then
        PHP_SOCK="/run/php/php-fpm.sock"
    fi

    cat > /etc/nginx/sites-available/exif-analyzer <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php;

    client_max_body_size 30m;
    client_body_timeout 120s;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy no-referrer always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_read_timeout 150s;
    }

    # Deny access to internal project dirs
    location ~* /(config|src|storage|deploy)/ {
        deny all;
        return 404;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX

    ln -sf /etc/nginx/sites-available/exif-analyzer /etc/nginx/sites-enabled/exif-analyzer
    rm -f /etc/nginx/sites-enabled/default
    nginx -t && systemctl reload nginx
    ok "Nginx configured (server_name: ${DOMAIN})"
fi

log "Restarting PHP/Nginx services"
systemctl restart php*-fpm 2>/dev/null || true
[[ "$INSTALL_NGINX" -eq 1 ]] && systemctl restart nginx || true

cat <<EOF

\033[1;32mInstallation complete!\033[0m
Project path: ${APP_DIR}

To run it quickly for testing:
    cd ${APP_DIR}
    php -S 0.0.0.0:8080 -t public

Then open: http://<server-address>:8080

For production, use Nginx with: sudo ./install.sh --nginx --domain your-domain.com
EOF
