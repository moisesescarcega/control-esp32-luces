FROM php:8.2-apache

# Suprimir la advertencia del ServerName de Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copiar el archivo index.php directamente desde la raíz a la ruta de Apache
COPY index.php /var/www/html/index.php

# Asegurar los permisos adecuados para el servidor web
RUN chown -R www-data:www-data /var/www/html