# Dockerfile - /geoapi/shomgt3/sgupdt - conteneur de mise à jour des cartes Shom pour le conteneur shomgt
# Benoit DAVID - 17/5/2022
# A ce stade l'image doit être créée dans ce répertoire par "docker build -t sgupdt ." et non par le docker-compose

# Use an official Php runtime as a parent image
# J'utilise une sous-sous-version précise pour éviter le reconstructions itempestives
FROM php:8.1.7-cli-bullseye

# install 7zip + gdal + wget
RUN apt-get update \
	&& apt-get install -y p7zip-full gdal-bin wget \
# gestion des images de la librairie gd
  && apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev libgd-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install gd exif \

# Clean
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /var/cache/* \

# Install composer and put binary into $PATH
  && curl -sS https://getcomposer.org/installer | php \
	&& mv composer.phar /usr/local/bin/ \
	&& ln -s /usr/local/bin/composer.phar /usr/local/bin/composer \

# Attribution de /var/www à www-data:www-data
  && chown www-data:www-data /var/www \

# Création de /var/www/data et attribution à www-data:www-data
  && mkdir /var/www/data \
  && chown www-data:www-data /var/www/data

USER www-data:www-data

# Installation dans /var/www de vendor avec le module Yaml de Symphony
RUN cd /var/www && composer require symfony/yaml:6

# copie des fichiers de vérification de schema dans /var/www/cli/schema/
ADD --chown=www-data ./schema /var/www/cli/schema/

# copie des fichiers Php includes dans /var/www/cli/lib/
ADD --chown=www-data ./lib /var/www/cli/lib/

# copie des scripts Sh et Php dans /var/www/cli/
ADD --chown=www-data ./main.php ./maketile.php ./shomgt.php ./shomgt.schema.yaml /var/www/cli/

CMD ["php", "/var/www/cli/main.php"]
