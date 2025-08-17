# Multi-stage build for Laravel backend
FROM composer:2.8 AS composer-build

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

FROM php:8.4-fpm-alpine AS production

# Install system dependencies
RUN apk add --no-cache \
    su-exec \
    && docker-php-ext-install pdo pdo_mysql

# Копируем скрипт в образ
COPY entrypoint.sh /entrypoint.sh

# Делаем скрипт исполняемым
RUN chmod +x /entrypoint.sh

# Указываем entrypoint
ENTRYPOINT ["/entrypoint.sh"]

# Create laravel user
RUN addgroup -g 1000 -S laravel \
    && adduser -u 1000 -S laravel -G laravel

# Set working directory
WORKDIR /var/www

# Copy application code
COPY --chown=laravel:laravel . .

# Copy composer dependencies
COPY --from=composer-build --chown=laravel:laravel /app/vendor ./vendor

# Set production permissions
RUN chmod -R 755 /var/www \
    && chown -R laravel:laravel /var/www \
    && chmod -R 775 storage bootstrap/cache

# PHP production optimizations
RUN echo 'opcache.enable=1' > /usr/local/etc/php/conf.d/production.ini \
    && echo 'opcache.memory_consumption=256' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'opcache.interned_strings_buffer=16' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'opcache.max_accelerated_files=20000' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'opcache.revalidate_freq=0' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'opcache.validate_timestamps=0' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'opcache.save_comments=1' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'opcache.fast_shutdown=1' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'realpath_cache_size=4096K' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'realpath_cache_ttl=600' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'expose_php=Off' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'max_execution_time=30' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'memory_limit=256M' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'post_max_size=50M' >> /usr/local/etc/php/conf.d/production.ini \
    && echo 'upload_max_filesize=50M' >> /usr/local/etc/php/conf.d/production.ini

# Configure PHP-FPM to run properly with laravel user but allow logging
RUN echo '[global]' > /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'error_log = /proc/self/fd/2' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo '[www]' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'user = laravel' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'group = laravel' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'listen = 9000' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'listen.mode = 0666' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'pm = dynamic' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'pm.max_children = 5' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'pm.start_servers = 2' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'pm.min_spare_servers = 1' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'pm.max_spare_servers = 3' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'catch_workers_output = yes' >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo 'access.log = /proc/self/fd/2' >> /usr/local/etc/php-fpm.d/docker.conf

# Expose port but run as root initially for entrypoint
EXPOSE 9000

# Default command if no entrypoint
CMD ["php-fpm"]



HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php-fpm -t || exit 1
