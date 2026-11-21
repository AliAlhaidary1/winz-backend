FROM php:8.3-cli-bookworm

# System dependencies required by the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions.
RUN docker-php-ext-install pdo_mysql mbstring intl xml bcmath gd zip

# Composer binary from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first so the layer can be cached.
COPY composer.json .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader || true

# Copy the rest of the application and refresh the autoloader.
COPY . .
RUN composer dump-autoload -o || true

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
