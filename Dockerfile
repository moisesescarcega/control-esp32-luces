FROM php:8.2-apache

# Suprimir la advertencia del ServerName de Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Instalar dependencias y extensión PostgreSQL para PHP
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copiar archivos de la aplicación
COPY db.php /var/www/html/db.php
COPY index.php /var/www/html/index.php
COPY cron_check.php /var/www/html/cron_check.php

# Asegurar permisos adecuados
RUN chown -R www-data:www-data /var/www/html
