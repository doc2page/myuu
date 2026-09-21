# ===== 构建阶段：安装 composer 依赖 =====
FROM composer:2 AS build
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader \
    && composer clear-cache

# ===== 运行阶段 =====
FROM php:8.3-cli-alpine

# 时区数据（群晖部署 TZ=Asia/Shanghai）
RUN apk add --no-cache tzdata

WORKDIR /app
COPY --from=build /app/vendor ./vendor
COPY composer.json ./
COPY bin ./bin
COPY src ./src
# 示例配置放 /app 根（不放 config/ 子目录：该目录会被 volume 挂载遮蔽），首次启动自动复制为 config/config.json
COPY config/config.example.json ./config.example.json

RUN chmod +x bin/transfer \
    && mkdir -p /app/data

# 默认常驻模式；一次性执行可覆盖 CMD 为 --once / --dry-run
ENTRYPOINT ["php", "/app/bin/transfer"]
