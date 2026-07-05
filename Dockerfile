FROM php:8.2-apache

# Suprimir la advertencia del ServerName de Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Instalar dependencias y extensión PostgreSQL para PHP
RUN apt-get update && apt-get install -y libpq-dev cron \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copiar archivos de la aplicación
COPY index.php /var/www/html/index.php
COPY cron_check.php /var/www/html/cron_check.php

# Asegurar permisos adecuados
RUN chown -R www-data:www-data /var/www/html

# Configurar cron: ejecutar cron_check.php cada minuto
RUN echo "* * * * * www-data php /var/www/html/cron_check.php >> /var/log/cron_check.log 2>&1" > /etc/cron.d/luces-cron \
    && chmod 0644 /etc/cron.d/luces-cron \
    && crontab /etc/cron.d/luces-cron \
    && touch /var/log/cron_check.log

# Script de arranque: inicia cron y Apache juntos
COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
