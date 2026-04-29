FROM php:8.3-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install PDO MySQL
RUN docker-php-ext-install pdo_mysql

# PHP config
RUN echo "display_errors = Off" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "log_errors = On"      >> /usr/local/etc/php/conf.d/app.ini && \
    echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/app.ini

# Apache: allow .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
