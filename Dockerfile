FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j2 gd zip exif \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/product-image-batcher.ini
WORKDIR /app
COPY . /app

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app"]
