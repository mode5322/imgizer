#!/usr/bin/env bash
#
# سكربت تثبيت "محلّل بيانات الصور" على سيرفر Debian/Ubuntu/Kali.
# الاستخدام:
#   sudo ./install.sh              # تثبيت الاعتماديات فقط
#   sudo ./install.sh --nginx      # + إعداد موقع Nginx
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
        *) echo "خيار غير معروف: $1"; exit 1 ;;
    esac
done

if [[ "$(id -u)" -ne 0 ]]; then
    echo "✖ يجب تشغيل السكربت بصلاحية root: sudo ./install.sh"
    exit 1
fi

log() { echo -e "\n\033[1;34m▶ $*\033[0m"; }
ok()  { echo -e "\033[1;32m✔ $*\033[0m"; }
warn(){ echo -e "\033[1;33m! $*\033[0m"; }

require_cmd() { command -v "$1" >/dev/null 2>&1; }

export DEBIAN_FRONTEND=noninteractive

log "تحديث فهارس الحزم"
apt-get update -y

log "تثبيت أدوات التحليل"
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
    curl || warn "تعذّر تثبيت بعض الحزم (قد تكون متوفرة بالفعل)."

log "تثبيت zsteg (تحليل تخفي PNG/BMP)"
if ! require_cmd zsteg; then
    if gem install zsteg >/dev/null 2>&1; then
        ok "تم تثبيت zsteg"
    else
        warn "تعذّر تثبيت zsteg، سيتعامل التطبيق مع غيابه."
    fi
else
    ok "zsteg مثبّت مسبقاً"
fi

log "ضبط حدود رفع الملفات في PHP"
for ini in /etc/php/*/fpm/php.ini /etc/php/*/cli/php.ini; do
    [[ -f "$ini" ]] || continue
    sed -i -E 's/^;?\s*upload_max_filesize\s*=.*/upload_max_filesize = 25M/' "$ini"
    sed -i -E 's/^;?\s*post_max_size\s*=.*/post_max_size = 30M/' "$ini"
    sed -i -E 's/^;?\s*max_execution_time\s*=.*/max_execution_time = 120/' "$ini"
    sed -i -E 's/^;?\s*max_input_time\s*=.*/max_input_time = 120/' "$ini"
done
ok "تم ضبط php.ini"

log "تهيئة مجلدات التخزين والصلاحيات"
WEB_USER="www-data"
[[ -d /var/www ]] || WEB_USER="www-data"
mkdir -p "$APP_DIR/storage/uploads" "$APP_DIR/storage/tmp"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage" 2>/dev/null || true
chmod -R 775 "$APP_DIR/storage"
ok "الجلسات: $WEB_USER"

if [[ "$INSTALL_NGINX" -eq 1 ]]; then
    log "إعداد Nginx"
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

    # منع الوصول لملفات النظام داخل المشروع
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
    ok "تم إعداد Nginx (server_name: ${DOMAIN})"
fi

log "إعادة تشغيل خدمات PHP/Nginx"
systemctl restart php*-fpm 2>/dev/null || true
[[ "$INSTALL_NGINX" -eq 1 ]] && systemctl restart nginx || true

cat <<EOF

\033[1;32mتم التثبيت بنجاح!\033[0m
المسار: ${APP_DIR}

للتشغيل السريع للتجربة:
    cd ${APP_DIR}
    php -S 0.0.0.0:8080 -t public

ثم افتح: http://<عنوان-السيرفر>:8080

للإنتاج استخدم Nginx مع: sudo ./install.sh --nginx --domain your-domain.com
EOF
