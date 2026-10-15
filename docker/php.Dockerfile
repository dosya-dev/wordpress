ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli
# bullseye (php 7.4 base) is EOL: its security pool moved to archive.debian.org
RUN if grep -q bullseye /etc/os-release; then \
      sed -i 's#deb.debian.org/debian-security#archive.debian.org/debian-security#' /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null || true; \
    fi \
 && apt-get update \
 && apt-get install -y --no-install-recommends unzip git libzip-dev \
 && docker-php-ext-install mysqli zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /plugin
