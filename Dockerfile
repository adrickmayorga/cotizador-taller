FROM php:8.2-apache

# Dependencias del sistema y Chromium para la generación de PDFs headless
RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    fonts-liberation \
    fonts-dejavu-core \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    curl \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Configuración personalizada de PHP (tamaño de subida para fotos e imágenes de recepción)
RUN echo "upload_max_filesize = 64M\npost_max_size = 64M\nmemory_limit = 256M\nmax_execution_time = 120" > /usr/local/etc/php/conf.d/cotizador.ini

WORKDIR /var/www/html

# Configuración de permisos para uploads y pdfs
RUN mkdir -p /var/www/html/uploads /var/www/html/pdf \
    && chown -R www-data:www-data /var/www/html
