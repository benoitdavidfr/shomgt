# Définition de l'image php8sgt : utilisation de Php avec Apache ou en cli
# Inspiré de 'https://hub.docker.com/r/phpdocker/phpdocker/~/dockerfile/'
# mise à jour 20/12/2020
#  - passage à Php8
#  - suppression XML
#  - suppression install du zip et config GD
# mise à jour 22/5/2020
#  - ajout d'une ligne pour installer la bibliothèques PgSql dans Php

# Use an official Php runtime as a parent image
FROM php:8.0-apache

# Inspiré de 'https://hub.docker.com/r/phpdocker/phpdocker/~/dockerfile/'
        
# Locales
RUN apt-get update \
	&& apt-get install -y locales
RUN dpkg-reconfigure locales \
	&& locale-gen C.UTF-8 \
	&& /usr/sbin/update-locale LANG=C.UTF-8
RUN echo 'en_US.UTF-8 UTF-8' >> /etc/locale.gen \
	&& locale-gen
ENV LC_ALL C.UTF-8
ENV LANG en_US.UTF-8
ENV LANGUAGE en_US.UTF-8

# Common
# suppression && docker-php-ext-install zip
RUN apt-get update \
	&& apt-get install -y openssl autoconf pkg-config libssl-dev zlib1g-dev zip unzip p7zip-full git wget

# PHP
# intl
RUN apt-get install -y libicu-dev \
	&& docker-php-ext-configure intl \
	&& docker-php-ext-install intl
  
# suppression xml
#RUN apt-get install -y libxml2-dev libxslt-dev && docker-php-ext-install dom xmlrpc xsl
    
# images
# modif docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev libgd-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install gd exif

# mysqli
RUN docker-php-ext-install mysqli

COPY config/php.ini /usr/local/etc/php/

# Install composer and put binary into $PATH
RUN curl -sS https://getcomposer.org/installer | php \
	&& mv composer.phar /usr/local/bin/ \
	&& ln -s /usr/local/bin/composer.phar /usr/local/bin/composer

# gdal
RUN apt-get install -y gdal-bin

# Clean
RUN apt-get clean
RUN rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /var/cache/*

# Make port 80 available to the world outside this container
EXPOSE 80

COPY config/gitconfig /var/www/.gitconfig

RUN chown www-data:www-data /var/www /var/www/.gitconfig
