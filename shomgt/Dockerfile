# Dockerfile pour ShomGt3/geotiff - 18/4-1/5/2022
# 1/5/2022 - chgt chemin des cartes
# 6/5/2022 - chgt instructions de copie des fichiers
FROM php:8.1.5-apache

# install 7zip + gdal
RUN apt-get update \
	&& apt-get install -y p7zip-full gdal-bin

# gestion des images
RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev libgd-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install gd exif

# Clean
RUN apt-get clean
RUN rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /var/cache/*

# Install composer and put binary into $PATH
RUN curl -sS https://getcomposer.org/installer | php \
	&& mv composer.phar /usr/local/bin/ \
	&& ln -s /usr/local/bin/composer.phar /usr/local/bin/composer

# Attribution de /var/www à www-data:www-data
RUN chown www-data:www-data /var/www

USER www-data:www-data

# Installation dans /var/www de vendor avec le module Yaml de Symphony
RUN cd /var/www && composer require symfony/yaml:6

# copie des fichiers Leaflet dans /var/www/html/leaflet/
ADD --chown=www-data ./leaflet /var/www/html/leaflet/
# copie des fichiers Php includes dans /var/www/html/lib/
ADD --chown=www-data ./lib /var/www/html/lib/
# copie des scripts Php dans /var/www/html/
ADD --chown=www-data ./maps.php ./tile.php ./dl.php ./mapwcat.php /var/www/html/

EXPOSE 80
ENTRYPOINT ["apache2-foreground"]
