FROM php:7.4

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    git \
    libzip-dev \
    libxml2-dev

RUN docker-php-ext-install pdo_mysql soap zip

# Clear apt cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --2

CMD [ "php", "-S", "0.0.0.0:8000" ]