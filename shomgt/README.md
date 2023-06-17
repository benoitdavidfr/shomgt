# Module shomgt de ShomGT3

Ce module propose les services suivants :

- 2 services exposant le contenu des cartes:
  - l'un au [format XYZ](https://en.wikipedia.org/wiki/Tiled_web_map), adapté notamment à une utilisation 
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

L'utilisation de tuiles, par rapport à l'utilisation du service WMS, offre de meilleures performances
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
  cette variable est nécessaire par exemple pour utiliser shomgt derrière un proxy inverse Traefik

## Liste des fichiers Php et principales classes et fonctions du module
Certains fichiers sont dupliqués entre shomgt et sgupdt afin que les modules puissent être déployés indépendamment
comme conteneurs Docker.
Cette duplication est mentionnée ci-dessous sous le sous-titre "identique à".
Il est important de maintenir ces fichiers identiques.

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
        - lib/log.inc.php
        - lib/gegeom.inc.php
        - lib/layer.inc.php
        - lib/cache.inc.php
        - lib/errortile.inc.php
        - ../secrets/tileaccess.inc.php
        - ../vendor/autoload.php
#### fichier inclus particulier
Le fichier ../secrets/tileaccess.inc.php n'est inclus que s'il existe.
Il permet de blacklister certaines adresses IP abusives, par exemple à partir de laquelle
quelqu'un cherche à recopier l'ensemble des tuiles.
Ces adresses IP black listées dans le fichier ../secrets/secretconfig.inc.php

Le fichier ../vendor/autoload.php permet de charger les [composants externes](../docs/composantexterne.md).

### wms.php - service WMS de shomgt avec authentification
Ce script expose un certain les différentes couches image sous la forme d'un serveur WMS.  
Il utilise le fichier `wmscapabilities.xml` qui définit les capacités du serveur.
#### inclus
        - lib/accesscntrl.inc.php
        - lib/coordsys.inc.php
        - lib/gebox.inc.php
        - lib/wmsserver.inc.php
        - lib/layer.inc.php
        - ../secrets/protect.inc.php
#### fichier inclus particulier
Le fichier ../secrets/protect.inc.php n'est inclus que s'il existe.
Il permet d'interdire l'accès au service en cas d'abus,
par exemple à partir de laquelle quelqu'un cherche à recopier l'ensemble des tuiles.

### wmsv.php - service WMS pour les couches vecteur de ShomGT
Ce script expose un certain nombre de couches vecteur sous la forme d'un serveur WMS.  
Il utilise:

  - le fichier `wmsvlayers.yaml` qui définit les couches vecteur et les styles à appliquer dans leur dessin,
  - le fichier `wmsvcapabilities.xml` qui définit les capacités du serveur.
#### inclus
        - lib/wmsserver.inc.php
        - lib/coordsys.inc.php
        - lib/gebox.inc.php
        - lib/vectorlayer.inc.php
        - ../vendor/autoload.php

### mapwcat.php - carte Leaflet avec les couches de geotiff, les catalogues, la ZEE, ...
Ce script génère une carte [Leaflet](https://leafletjs.com/) qui:

- utilise les fichiers du répertoire `leaflet` qui contient notamment:
  - le code JavaScript de Leaflet
  - le [plug-in Leaflet uGeoJSON](https://github.com/BenjaminVadant/leaflet-ugeojson)
    qui permet de créer des couches GeoJSON personnalisée,
  - le [plug-in Leaflet Coordinates Control](https://github.com/zimmicz/Leaflet-Coordinates-Control)
    qui permet d'afficher les coordonnées d'un lieu en cliquant dessus,
  - le [plug-in Leaflet Leaflet.EdgeBuffer](https://github.com/TolonUK/Leaflet.EdgeBuffer)
    qui prend en charge le préchargement des tuiles en dehors de la fenêtre d'affichage.
  
- appelle l'API maps.php pour afficher:
  - les silhouettes des GéoTiffs,
  - les coins des silhouettes pour afficher leurs coordonnées,
  - les zones effacées dans les GéoTiffs,
  - les étiquettes avec le numéro des cartes.

- propose d'afficher les fichiers GeoJSON suivants stockés dans le répertoire `geojson` :
  - frzee.geojson - la ZEE française simplifiée produite par Benoit DAVID,
  - delmar.geojson - les délimitations maritimes produites par le Shom,
  - sar_2019.geojson - les zones SAR-SRR produites par le Shom.

  Les 2 derniers fichiers sont téléchargés depuis le serveur WFS du Shom en utilisant le [module shomft](../shomft).

#### inclus
        - lib/accesscntrl.inc.php

### maps.php - point d'accès de l'API de maps
API d'affichage des cartes Shom s'inspirant de l'API OGC Maps appelée par mapwcat.php.

Les points d'accès sont:

  - /maps.php - landing page
  - /maps.php/collections - liste des couches exposées
  - /maps.php/collections/{collection} - liste des bbox des géotiffs de la couche {collection}
  - /maps.php/collections/{collection}/map - retourne un extrait de la/des couche(s) {collection} en fonction du bbox en paramètre et éventuellement du crs ; ces paramètres sont passés en GET
  - /maps.php/collections/{collection}/items - liste des GeoTiffs de la couche {collection}
  - /maps.php/collections/{collection}/corners - liste des coins des GeoTiffs de la couche {collection}
  - /maps.php/collections/{collection}/deletedZones - liste des zones effacées des GeoTiffs en GeoJSON de la couche {collection}
#### inclus
        - lib/layer.inc.php
        - lib/accesscntrl.inc.php

### dl.php - téléchargements appelé depuis la carte avec un gtname en paramètre
#### inclus
        - lib/envvar.inc.php
        - lib/gdalinfo.inc.php
        - lib/accesscntrl.inc.php

### lib/wmsserver.inc.php - définition de la classe abstraite WmsServer
La classe abstraite **WmsServer** gère de manière minimum les protocole WMS 1.1.1 et 1.3.0 et fournit qqs méthodes génériques ;
elle est indépendante des fonctionnalités du serveur de shomgt.
Elle génère un fichier temporaire de log utile au déverminage.

### lib/geotiffs.inc.php - liste les GeoTiffs
#### identique à
        - ../sgupdt/lib/geotiffs.inc.php
#### inclus
        - lib/envvar.inc.php

### lib/grefimg.inc.php  - Définition de la classe GeoRefImage gérant une image géoréférencée'
La classe GeoRefImage propose différentes méthodes sur une image géoréférencée
en étendant la bibliothèque [GD](https://www.php.net/manual/fr/book.image.php) avec:

   - la définition d'un espace en coordonnées utilisateurs comme EBox,
     typiquement un système de coordonnées projeté comme WorldMercator
   - une notion de style inspiré de Leaflet pour dessiner des polylignes et des polygones
#### identique à
        - ../sgupdt/lib/grefimg.inc.php
#### inclus
        - lib/sexcept.inc.php
        - lib/gebox.inc.php

### lib/layer.inc.php  - Définition des classes Layer, PyrLayer, LabelLayer et TiffLayer
Les 4 classes Layer, PyrLayer, LabelLayer et TiffLayer permettent de construire à partir de shomgt.yaml la structuration
en couches et de l'exploiter au travers des méthodes map() qui recopie dans une image GD l'extrait de la couche
correspondant à un rectangle et pour la classe TiffLayer la méthode items() qui génère en GeoJSON les silhouettes des GéoTiffs.

La classe abstraite Layer définit les couches du serveur de cartes.  
La classe TiffLayer correspond aux couches agrégeant des GéoTiff.  
La classe PyrLayer correspond à la pyramide des TiffLayer qui permet d'afficher le bon GéoTiff en fonction du niveau de zoom.  
Enfin, la classe LabelLayer correspond aux étiquettes associées aux GéoTiff.  

Les listes de couches sont initialisées notamment à partir du [fichier shomgt.yaml](../data#le-fichier-shomgtyaml).
#### inclus
        - ../vendor/autoload.php
        - lib/grefimg.inc.php
        - lib/geotiff.inc.php
        - lib/zoom.inc.php
        - lib/isomd.inc.php

### lib/vectorlayer.inc.php - gestion de couches d'objets vecteur
#### inclus
        - lib/layer.inc.php
        - lib/gegeom.inc.php

### lib/geotiff.inc.php - définition de la classe GeoTiff implémentant des méthodes sur un GéoTiff
#### inclus
        - lib/envvar.inc.php
        - lib/gdalinfo.inc.php

### lib/gebox.inc.php - définition de classes définissant un BBox avec des coord. géographiques ou euclidiennes
La classe GBox définit un bbox en coordonnées géographiques.  
La classe EBox définit un bbox en coordonnées euclidiennes projetées.
#### identique à
        - ../sgupdt/lib/gebox.inc.php
#### inclus
        - lib/coordsys.inc.php
        - lib/pos.inc.php
        - lib/sexcept.inc.php
        - lib/zoom.inc.php

### lib/gegeom.inc.php - package géométrique utilisant des coordonnées géographiques ou euclidiennes
#### inclus
        - lib/coordsys.inc.php
        - lib/zoom.inc.php
        - lib/gebox.inc.php
        - lib/sexcept.inc.php

### lib/pos.inc.php - Définition des classes statiques Pos, LPos, LLPos
Comme dans GeoJSON, on distingue la notion de Point, qui est une primitive géométrique, de la notion de position
qui permet de construire les primitives géométriques.
Ainsi:
  - une position est stockée en Php comme une liste de 2 ou 3 nombres
    et la classe Pos regroupe des méthodes statiques qui s'appliquent à une position,
  - la classe LPos regroupe des méthodes statiques qui s'appliquent à une liste de positions, et
  - la classe LLPos regroupe des méthodes statiques qui s'appliquent à une liste de listes de positions.
#### identique à
        - ../sgupdt/lib/pos.inc.php

### lib/zoom.inc.php  - définition de la classe Zoom regroupant l'intelligence autour du tuilage et des niveaux de zoom
#### identique à
        - ../sgupdt/lib/zoom.inc.php
#### inclus
        - lib/sexcept.inc.php
        - lib/gebox.inc.php

### lib/coordsys.inc.php (v3) - changement simple de projection a priori sur l'ellipsoide IAG_GRS_1980
#### identique à
        - ../sgupdt/lib/coordsys.inc.php
#### inclus
        - lib/sexcept.inc.php

### lib/accesscntrl.inc.php - contrôle d'accès
#### inclus
        - lib/log.inc.php
        - lib/config.inc.php

### lib/cache.inc.php -  gestion d'un cache simple des tuiles
#### inclus
        - lib/envvar.inc.php

### lib/config.inc.php - fichier de config par défaut
#### identique à
        - ../sgupdt/lib/config.inc.php
#### inclus
        - ../secrets/secretconfig.inc.php

### lib/envvar.inc.php - envvar.inc.php - gestion des variables d'environnement et de leur valeur par défaut
Simplifie l'utilisation des variables d'environnement.
#### identique à
        - ../sgupdt/lib/envvar.inc.php

### lib/errortile.inc.php - Génération d'une image d'erreur contenant le message d'erreur et l'identifiant de la tuile

### lib/gdalinfo.inc.php - Analyse un JSON fabriqué par GDAL INFO et en extrait les infos essentielles
Extrait des infos utiles du fichier JSON fabriqué par [gdalinfo](https://gdal.org/programs/gdalinfo.html).
Extrait du fichier JSON fabriqué par [gdalinfo](https://gdal.org/programs/gdalinfo.html)
les infos essentielles qui sont la taille de l'image en nombre de pixels et si le fichier est géoréférencé 
son extension en coordonnées World Mercator et en coordonnées géographiques.
#### identique à
        - ../sgupdt/lib/gdalinfo.inc.php
#### inclus
        - lib/sexcept.inc.php
        - lib/envvar.inc.php
        - lib/gebox.inc.php
        - ../vendor/autoload.php
        - lib/geotiffs.inc.php

### lib/isomd.inc.php - Récupération de MD ISO d'un GéoTiff'
#### inclus
        - lib/envvar.inc.php

### lib/log.inc.php - Enregistrement d'un log
#### inclus
        - lib/mysql.inc.php
        - lib/sexcept.inc.php

### lib/mysql.inc.php  - Classes MySql et MySqlResult utilisées pour exécuter des requêtes MySql
#### inclus
        - lib/sexcept.inc.php
        - lib/config.inc.php

### lib/sexcept.inc.php - Exception avec code string
#### identique à
        - ../sgupdt/lib/sexcept.inc.php
