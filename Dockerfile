FROM php:8.3-apache

# ── PHP extensions needed by FinPulse ─────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        cron \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring iconv zip opcache \
    && rm -rf /var/lib/apt/lists/*

# ── Apache: point the document root at public/ and enable mod_rewrite ─────────
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's|/var/www/html|${APACHE_DOCUMENT_ROOT}|g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' \
        /etc/apache2/apache2.conf \
    && a2enmod rewrite headers

# ── PHP tuning ────────────────────────────────────────────────────────────────
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=64'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'upload_max_filesize=10M'; \
    echo 'post_max_size=12M'; \
    echo 'memory_limit=128M'; \
    echo 'date.timezone=UTC'; \
} > /usr/local/etc/php/conf.d/finpulse.ini

# ── Copy the application ──────────────────────────────────────────────────────
COPY . /var/www/html/

# ── Writable storage (SQLite DB, mail logs, cron lock) ────────────────────────
RUN mkdir -p /var/www/html/storage/mail \
    && chown -R www-data:www-data /var/www/html/storage

# ── Cron: run scripts/cron.php every 15 minutes ──────────────────────────────
RUN echo '*/15 * * * * www-data php /var/www/html/scripts/cron.php >> /var/log/finpulse-cron.log 2>&1' \
    > /etc/cron.d/finpulse \
    && chmod 0644 /etc/cron.d/finpulse \
    && crontab -u www-data /etc/cron.d/finpulse

# ── Entrypoint: start cron alongside Apache ───────────────────────────────────
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
