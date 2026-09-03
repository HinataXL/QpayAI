FROM php:8.2-apache

# Habilitar el módulo de reescritura de Apache (si fuera necesario en el futuro)
RUN a2enmod rewrite

# Copiar el código fuente de la app
COPY . /var/www/html/

# Configurar permisos para Apache
RUN chown -R www-data:www-data /var/www/html

# Exponer el puerto
EXPOSE 80
