# Définition de l'image php-bash : utilisation de Php avec Apache
# mise à jour 10/2/2018:
#   - passage à mongodb-1.4.0
#   - ajout de wget

# Use an official Php runtime as a parent image
FROM php:7.2-apache

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
RUN apt-get update \
	&& apt-get install -y openssl autoconf pkg-config libssl-dev zlib1g-dev zip unzip p7zip-full git wget \
  && docker-php-ext-install zip

# PHP
# intl
RUN apt-get install -y libicu-dev \
	&& docker-php-ext-configure intl \
	&& docker-php-ext-install intl
  
# xml
RUN apt-get install -y libxml2-dev libxslt-dev \
	&& docker-php-ext-install dom xmlrpc xsl
    
# images
RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev libgd-dev \
	&& docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
	&& docker-php-ext-install gd exif
    
# mysqli
RUN docker-php-ext-install mysqli

# mongodb
RUN pecl install mongodb-1.4.0
RUN echo "extension=mongodb.so" >> /usr/local/etc/php/conf.d/mongodb.ini

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

# execute bash
CMD bash

