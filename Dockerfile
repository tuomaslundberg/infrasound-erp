# Use an official PHP image with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite and replace default site config (enables AllowOverride All)
RUN a2enmod rewrite
COPY ./docker/apache-site.conf /etc/apache2/sites-available/000-default.conf

# Copy source, config, and CLI lib into the container
COPY ./src    /var/www/html
COPY ./config /var/www/config
COPY ./cli    /var/www/cli

# Set working directory
WORKDIR /var/www/html

# Install extensions (if you need PDO for MariaDB/MySQL)
RUN docker-php-ext-install pdo pdo_mysql
