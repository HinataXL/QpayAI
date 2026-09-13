FROM php:8.2-apache

# La imagen oficial no trae php.ini activado, y sin él PHP muestra los errores en
# la respuesta. Se activa la configuración de producción que ya incluye la imagen:
# display_errors y display_startup_errors apagados, log_errors encendido.
# Esto cubre también los errores de arranque o de sintaxis, que ocurren antes de
# que ningún ini_set() del código llegue a ejecutarse. Los errores quedan en el
# log de Apache, visible con `docker logs`.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Módulos necesarios: rewrite/redirect para .htaccess, headers para las
# cabeceras de seguridad del panel de administración.
RUN a2enmod rewrite headers

# Permitir que .htaccess aplique (por defecto la imagen usa AllowOverride None),
# ocultar la versión del servidor y bloquear archivos ocultos como .env.
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Options -Indexes' \
    '</Directory>' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    > /etc/apache2/conf-available/qpayai.conf \
    && a2enconf qpayai

# Copiar el código fuente de la app
COPY . /var/www/html/

# Configurar permisos para Apache. El directorio debe ser escribible: el panel
# admin guarda qpaypro_docs.txt y las versiones en docs_versions/.
RUN chown -R www-data:www-data /var/www/html

# Exponer el puerto
EXPOSE 80
