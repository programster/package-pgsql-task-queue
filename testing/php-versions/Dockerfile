# An image for testing the package on a specific version of PHP, with the pgsql extension it needs, and pcntl and posix
# for the tests of stopping on SIGTERM. Used by testing/run-all-php-versions.sh and run-all-postgresql-versions.sh.
# Build with e.g.: docker build --build-arg PHP_VERSION=8.2 -t pgsql-task-queue-php8.2-test testing/php-versions
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pgsql pcntl \
    && rm -rf /var/lib/apt/lists/*
