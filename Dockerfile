# syntax=docker/dockerfile:1
FROM php:8.3-cli-bookworm

# PHP extensions Laravel + the MySQL driver need. The extension installer pulls
# in the right system libraries so we don't hand-manage apt dependencies.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions pdo_mysql mbstring bcmath zip

# Composer (pinned major) from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies. Dev deps are kept on purpose: the database seeder
# uses User::factory(), which needs fakerphp/faker (a require-dev package).
COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Bake runtime-directory writability into the image so the container never hits
# a log/cache permission problem regardless of the runtime user.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data
EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
