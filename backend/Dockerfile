FROM php:8.4-cli-bookworm

# Install system dependencies & PHP extensions for MySQL
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl

# Disable IPv6 resolution for apt
RUN echo 'Acquire::ForceIPv4 "true";' > /etc/apt/apt.conf.d/99force-ipv4

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# Install Laravel dependencies
RUN composer install --optimize-autoloader --no-dev

# Ensure storage permissions
RUN chmod -R 777 storage bootstrap/cache

RUN echo "upload_max_filesize = 20M\npost_max_size = 20M" > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 8000
# Run Laravel's built-in server mapped to all network interfaces
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
