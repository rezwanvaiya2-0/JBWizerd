# ============================================================
# JBWizerd Panel — Docker image
#
# Build:
#   docker build -t jbwizerd .
# Run (with docker-compose):
#   docker compose up -d
#
# Requires MySQL/MariaDB — use docker-compose.yml for the full stack.
# ============================================================

FROM php:8.2-apache

LABEL org.opencontainers.image.title="JBWizerd"
LABEL org.opencontainers.image.description="JetBackup backup monitoring panel"
LABEL org.opencontainers.image.url="https://github.com/rezwanvaiya2-0/JBWizerd"

# --- System deps + PHP extensions (pdo_mysql, curl, mbstring, json) ---
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        unzip \
        cron \
    && docker-php-ext-install pdo_mysql curl mbstring zip \
    && docker-php-ext-enable pdo_mysql curl mbstring zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# --- Copy the panel into the web root ---
WORKDIR /var/www/html
COPY . /var/www/html

# --- Permissions: www-data owns writable paths ---
RUN mkdir -p /var/www/html/cron \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# --- Apache: require auth headers are passed to PHP (for bearer tokens) ---
RUN echo 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' >> /etc/apache2/conf-available/remoteip.conf \
    && a2enconf remoteip

# --- Entrypoint: generate config if missing, start cron + Apache ---
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
