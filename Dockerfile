FROM php:8.2-cli

WORKDIR /var/www/


RUN apt-get update && apt-get install -y \
    libzip-dev unzip git \
    && docker-php-ext-install pcntl

RUN docker-php-ext-install sockets
# Dummy database file
RUN mkdir -p database && touch database/database.sqlite

COPY .env.example .env
COPY . .


COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

RUN php artisan migrate

CMD ["php", "artisan", "rabbitmq:listen"]