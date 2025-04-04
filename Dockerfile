# Use an official PHP image with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy your source code into the container
COPY ./src /var/www/html

# Set working directory
WORKDIR /var/www/html

# Install extensions (if you need PDO for MariaDB/MySQL)
RUN docker-php-ext-install pdo pdo_mysql
