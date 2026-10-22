FROM php:8.2-apache
COPY config/vhost.conf /etc/apache2/sites-available/000-default.conf
RUN mkdir /app && chown -R www-data:www-data /app && a2ensite 000-default && a2enmod rewrite