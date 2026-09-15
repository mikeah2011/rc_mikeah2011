FROM php:8.4-fpm

# Install system dependencies and PHP extensions needed for Laravel + Postgres
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libzip-dev libonig-dev zip \
    && docker-php-ext-install pdo pdo_pgsql zip mbstring pcntl \
    && rm -rf /var/lib/apt/lists/*

# Install Composer (copy from official composer image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files then install dependencies inside the image (won't persist to repo)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

# Copy application files
COPY . .

# Optimize autoloader
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload -o

EXPOSE 9000
CMD ["php-fpm"]
