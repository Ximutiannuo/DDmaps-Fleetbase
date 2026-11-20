# DDmaps 定制 FleetBase API 镜像
# 基于上游官方镜像, 固化以下内容 (避免每次容器重建后手动打补丁):
#   1. ZTS/FrankenPHP worker 相关修复 (opcache / ClassLoader / 类预加载)
#   2. construction-site 扩展 (PHP API 包) 的完整注册
# 注意: 本镜像的基础镜像是本机已拉取的 fleetbase/fleetbase-api:latest,
#       构建过程不需要网络.
FROM fleetbase/fleetbase-api:latest@sha256:23845396576416f0929864340c49c743f2b01f100a84c39d1cbe3e8297a355a0

# ---- 1. PHP opcache 配置 (ZTS worker 线程类表修复) ----
COPY docker/api/patches/opcache.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

# ---- 2. Composer ClassLoader 补丁 (ZTS 类表重置 bug) ----
COPY docker/api/patches/ClassLoader.php /fleetbase/api/vendor/composer/ClassLoader.php

# ---- 3. Worker 启动时预加载关键类 ----
COPY docker/api/patches/PreloadClassesOnWorkerStart.php /fleetbase/api/app/Listeners/PreloadClassesOnWorkerStart.php
COPY docker/api/patches/octane.php /fleetbase/api/config/octane.php
COPY api/app/Exceptions/Handler.php /fleetbase/api/app/Exceptions/Handler.php

# ---- 4. construction-site 扩展 (PHP API 包) ----
# 包源码烤进镜像 (运行时会被 docker-compose.override.yml 的同路径 bind mount 覆盖, 内容一致)
COPY packages/construction-site /fleetbase/packages/construction-site

# 注册脚本: symlink + composer.json + autoload_psr4/static + config/app.php + packages.php
COPY docker/api/register-construction-site.php /tmp/register-construction-site.php
RUN php /tmp/register-construction-site.php \
    && rm -f /tmp/register-construction-site.php \
    && sed -i '/ConstructionSiteServiceProvider/d' /fleetbase/api/config/app.php \
    && rm -f /fleetbase/api/bootstrap/cache/services.php \
    && echo ">>> construction-site registered in image (packages.php only, no double registration); services.php cleared"

WORKDIR /fleetbase/api
COPY docker/crontab /fleetbase/api/crontab
COPY docker/api/check-scheduler-health.php /usr/local/bin/check-scheduler-health.php
