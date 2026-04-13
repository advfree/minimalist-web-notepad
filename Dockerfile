# Minimalist Notepad - Caddy + PHP-FPM 镜像
# Alpine Linux + Caddy HTTP + PHP 8.2
# ============================================================
FROM php:8.2-fpm-alpine

# 安装必要的 PHP 扩展
RUN apk add --no-cache \
    curl \
    libzip \
    oniguruma \
    && docker-php-ext-install \
        pdo \
        pdo_sqlite \
        zip \
        mbstring

# 安装 Caddy（Alpine 包管理器，极简）
RUN apk add --no-cache caddy

WORKDIR /var/www/html

# 复制应用文件
COPY . .

# 创建数据目录
RUN mkdir -p /var/www/html/_data && chmod 777 /var/www/html/_data

# 使用 supervisord 管理 PHP-FPM 和 Caddy 双进程
RUN apk add --no-cache supervisor

RUN mkdir -p /etc/supervisor.d && cat > /etc/supervisord.conf << 'EOF'
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/var/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:caddy]
command=caddy run --config /var/www/html/Caddyfile --adapter caddyfile
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
EOF

EXPOSE 80 443

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
