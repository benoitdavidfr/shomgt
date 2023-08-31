# Module view de ShomGT

Ce module propose les services suivants :

- 2 services exposant le contenu des cartes:
  - l'un au [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map), adapté notamment à une utilisation 
    avec les logiciels [QGis](https://www.qgis.org/) et [Leaflet](https://leafletjs.com/) et
  - un autre conforme au protocole [WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
- un service GeoJSON exposant :
  - les silhouettes des GéoTiffs,
  - les périmètres simplifiés des ZEE,
  - les délimitations maritimes,
  - les zones SAR-SRR,
- un service WMS exposant les couches vecteur,
- un service de téléchargement des cartes,
- une carte Leaflet de visualisation des tuiles et des couches GeoJSON et permettant de télécharger les cartes.

L'utilisation de tuiles offre de meilleures performances que l'utilisation du service WMS,
car il permet une meilleure gestion du cache tant sur le serveur, que sur le réseau et sur le poste client.

### Variables d'environnement

Ce module utilise les variables d'environnement suivantes:

- `SHOMGT3_LOG_MYSQL_URI`: URL définissant le serveur et la base MySql utilisés pour enregistrer les logs.
  si non définie alors le log est désactivé.
  L'URL prend la forme suivante: `mysql://{login}:{passwd}@{server}/{base}` où:
  - `{login}` est le login dans la base MySql,
  - `{passwd}` est le mot de passe associé au login,
  - `{server}` est l'adresse du serveur de bases MySql,
  - `{base}` est le nom de la base.
- `SHOMGT3_MAPWCAT_FORCE_HTTPS`: si `true` alors https est forcé dans mapwcat ;
  cette variable est nécessaire par exemple pour utiliser view derrière un proxy inverse Traefik

## Liste des fichiers Php

### tile.php - webservice au standard XYZ d'accès aux GéoTIFF du Shom
Affichage des cartes Shom conformément au [standard XYZ](https://en.wikipedia.org/wiki/Tiled_web_map)
facile à utiliser dans une carte Leaflet.

Les points d'accès sont:

        - /tile.php - affiche la documentation du service
        - /tile.php/{layer} - affiche la documentation de la couche {layer}
        - /tile.php/{layer}/{z}/{x}/{y}.png - retourne la tuile du niveau de zoom {z}, colonne {x} et ligne {y}
  
Exemple:

        - /tile.php/gtpyr/10/538/381.png
#### inclus
        - ../lib/layer.inc.php
        - ../lib/gegeom.inc.php
        - ../lib/errortile.inc.php
        - ../lib/log.inc.php
        - ../lib/cache.inc.php
        - ../secrets/tileaccess.inc.php
        - ../vendor/autoload.php
#### fichiers inclus particuliers
- Le fichier `../secrets/tileaccess.inc.php` n'est inclus que s'il existe.
  Il permet de blacklister certaines adresses IP abusives,
  par exemple à partir de laquelle quelqu'un cherche à aspirer l'ensemble des tuiles.
  Ces adresses IP blacklistées sont dans le fichier ../secrets/secretconfig.inc.php

- Le fichier `../vendor/autoload.php` charge le [composant externe symfony/yaml](../docs/composantexterne.md)
  intégré avec l'utilitaire composer.

### wms.php - service WMS de shomgt avec authentification
Ce script expose les différentes couches image sous la forme d'un serveur WMS.  
Il utilise le fichier `wmscapabilities.xml` qui définit les capacités du serveur.
#### inclus
        - ../lib/wmsserver.inc.php
        - ../lib/layer.inc.php
        - ../lib/gebox.inc.php
        - ../lib/coordsys.inc.php
        - ../lib/accesscntrl.inc.php
        - ../secrets/protect.inc.php
#### fichier inclus particulier
Le fichier ../secrets/protect.inc.php n'est inclus que s'il existe.
Il permet d'interdire l'accès au service en cas d'abus,
par exemple si quelqu'un cherche à recopier l'ensemble des tuiles.

### wmsv.php - service WMS pour les couches vecteur de ShomGT
Ce script expose les couches vecteur sous la forme d'un serveur WMS.  
Il utilise:

  - le fichier `wmsvlayers.yaml` qui définit les couches vecteur et les styles à appliquer dans leur dessin,
  - le fichier `wmsvcapabilities.xml` qui définit les capacités du serveur.
#### inclus
        - ../lib/wmsserver.inc.php
        - ../lib/vectorlayer.inc.php
        - ../lib/gebox.inc.php
        - ../lib/coordsys.inc.php
        - ../vendor/autoload.php

### mapwcat.php - carte Leaflet avec les couches de geotiff, les catalogues, la ZEE, ...
Ce script génère une carte [Leaflet](https://leafletjs.com/) qui:

- utilise les fichiers du répertoire `leaflet` qui contient notamment:
  - le code JavaScript de Leaflet
  - le [plug-in uGeoJSON](https://github.com/BenjaminVadant/leaflet-ugeojson)
    qui permet de créer des couches GeoJSON personnalisée,
  - le [plug-in Coordinates Control](https://github.com/zimmicz/Leaflet-Coordinates-Control)
    qui permet d'afficher les coordonnées d'un lieu en cliquant dessus,
  - le [plug-in Leaflet.EdgeBuffer](https://github.com/TolonUK/Leaflet.EdgeBuffer)
    qui prend en charge le préchargement des tuiles en dehors de la fenêtre d'affichage.
  
- appelle l'API tile.php pour obtenir le contenu des tuiles

- appelle l'API maps.php pour afficher:
  - les silhouettes des GéoTiffs,
  - les coins des silhouettes pour afficher leurs coordonnées,
  - les zones effacées dans les GéoTiffs,
  - les étiquettes avec le numéro des cartes.

- propose d'afficher les fichiers GeoJSON suivants stockés dans le répertoire `geojson` :
  - frzee.geojson - la ZEE française simplifiée produite dans le cadre de ce projet,
  - delmar.geojson - les délimitations maritimes produites par le Shom,
    téléchargées depuis le serveur WFS du Shom en utilisant le [module shomft](../shomft).
  - sar_2019.geojson - les zones SAR-SRR produites par le Shom,
    téléchargées depuis https://diffusion.shom.fr/zones-sar.html.

- propose pour un GéoTiff les téléchargements proposés par dl.php. 
#### inclus
        - ../lib/accesscntrl.inc.php

### maps.php - point d'accès de l'API de maps
API d'affichage des cartes Shom s'inspirant de l'API OGC Maps appelée par mapwcat.php.

Les points d'accès sont:

  - /maps.php - landing page
  - /maps.php/collections - liste des couches exposées
  - /maps.php/collections/{collection} - liste des bbox des géotiffs de la couche {collection}
  - /maps.php/collections/{collection}/map - retourne un extrait de la/des couche(s) {collection} en fonction du bbox en paramètre GET et éventuellement du crs
  - /maps.php/collections/{collection}/items - liste des GeoTiffs de la couche {collection}
  - /maps.php/collections/{collection}/corners - liste des coins des GeoTiffs de la couche {collection}
  - /maps.php/collections/{collection}/deletedZones - liste des zones effacées des GeoTiffs en GeoJSON de la couche {collection}
#### inclus
        - ../lib/layer.inc.php
        - ../lib/accesscntrl.inc.php

### dl.php - téléchargements appelé depuis la carte avec un gtname en paramètre
Propose de télécharger pour une image :

- l'image au format PNG, ce qui permet de l'afficher facilement dans un navigateur,
- les métadonnées ISO 19139 de l'image au format XML,
- les infos de gdalinfo du GéoTiff initial, notamment son géoréférencement, au format JSON,
- la carte contenant l'image comme archive 7z.
#### inclus
        - ../lib/gdalinfo.inc.php
        - ../lib/envvar.inc.php
        - ../lib/accesscntrl.inc.php
