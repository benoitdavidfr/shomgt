# Module shomgt de ShomGT3

Ce module propose les services suivants :

- 2 services exposant le contenu des cartes:
  - l'un au [format XYZ](https://en.wikipedia.org/wiki/Tiled_web_map), adapté notamment à une utilisation 
    avec les logiciels [QGis](https://www.qgis.org/) et [Leaflet](https://leafletjs.com/) et
  - un autre conforme au protocole [WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
- un service GeoJSON exposant :
  - les silhouettes des GéoTiffs,
  - les périmètres simplifiés des ZEE,
  - les zones SAR-SRR,
- un service de téléchargement des GéoTiffs avec des infos associées.

## Liste des fichiers Php et principales classes et fonctions du module
Certains fichiers sont dupliqués entre shomgt et sgupdt afin que les modules puissent être déployés indépendamment
comme conteneurs Docker.
Cette duplication est mentionnée ci-dessous sous le sous-titre "identique à".
Il est important de maintenir ces fichiers identiques.

### tile.php - webservice au standard XYZ d'accès aux GéoTIFF du Shom
Affichage des cartes Shom conformément au [standard XYZ](https://en.wikipedia.org/wiki/Tiled_web_map)
facile à utiliser dans une carte Leaflet.

Les points d'accès sont:

  - /tile.php - affichage de la documentation du service
  - /tile.php/{layer} - affichage de la documentation de la couche
  - /tile.php/{layer}/{z}/{x}/{y}.png - retourne la tuile du niveau de zoom {z}, colonne {x} et ligne {y}
#### inclus
        - lib/log.inc.php
        - lib/gegeom.inc.php
        - lib/layer.inc.php
        - lib/cache.inc.php
        - lib/errortile.inc.php
        - ../vendor/autoload.php
        - ../secrets/tileaccess.inc.php

### wms.php - service WMS de shomgt avec authentification
#### inclus
        - lib/accesscntrl.inc.php
        - lib/coordsys.inc.php
        - lib/gebox.inc.php
        - lib/wmsserver.inc.php
        - lib/layer.inc.php
        - ../secrets/protect.inc.php

### wmsv.php - service WMS pour les couches vecteur de ShomGT
#### inclus
        - ../vendor/autoload.php
        - lib/coordsys.inc.php
        - lib/gebox.inc.php
        - lib/wmsserver.inc.php
        - lib/vectorlayer.inc.php

### mapwcat.php - carte Leaflet avec les couches de geotiff, les catalogues, la ZEE, ...
#### inclus
        - lib/accesscntrl.inc.php

### maps.php - point d'accès de l'API de maps
API d'affichage des cartes Shom s'inspirant de l'API OGC Maps.

Les points d'accès sont:

  - /maps.php - affichage de la documentation du service
  - /maps.php/collections - liste des couches 
  
#### inclus
        - lib/layer.inc.php
        - lib/accesscntrl.inc.php

### dl.php - téléchargements appelé depuis la carte avec un gtname en paramètre
#### inclus
        - lib/envvar.inc.php
        - lib/gdalinfo.inc.php
        - lib/accesscntrl.inc.php

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

### lib/envvar.inc.php:
#### identique à
        - ../sgupdt/lib/envvar.inc.php
### lib/errortile.inc.php:

### lib/gdalinfo.inc.php:
#### identique à
        - ../sgupdt/lib/gdalinfo.inc.php
#### inclus
        - lib/sexcept.inc.php
        - lib/envvar.inc.php
        - lib/gebox.inc.php
        - ../vendor/autoload.php
        - lib/geotiffs.inc.php
### lib/gebox.inc.php:
#### identique à
        - ../sgupdt/lib/gebox.inc.php
#### inclus
        - lib/coordsys.inc.php
        - lib/pos.inc.php
        - lib/sexcept.inc.php
        - lib/zoom.inc.php
### lib/gegeom.inc.php:
#### inclus
        - lib/coordsys.inc.php
        - lib/zoom.inc.php
        - lib/gebox.inc.php
        - lib/sexcept.inc.php
### lib/geotiff.inc.php - définition de la classe GeoTiff
#### inclus
        - lib/envvar.inc.php
        - lib/gdalinfo.inc.php
### lib/geotiffs.inc.php - liste les GeoTiffs
#### identique à
        - ../sgupdt/lib/geotiffs.inc.php
#### inclus
        - lib/envvar.inc.php
### lib/grefimg.inc.php  - Définition de la classe GeoRefImage gérant une image géoréférencée'
#### identique à
        - ../sgupdt/lib/grefimg.inc.php
#### inclus
        - lib/sexcept.inc.php
        - lib/gebox.inc.php

### lib/isomd.inc.php - Récupération de MD ISO d'un GéoTiff'
#### inclus
        - lib/envvar.inc.php

### lib/layer.inc.php  - Définition des classes Layer, PyrLayer, LabelLayer et TiffLayer
Les 4 classes Layer, PyrLayer, LabelLayer et TiffLayer permettent de construire à partir de shomgt.yaml la structuration
en couches et de l'exploiter au travers des méthodes map() qui recopie dans une image GD l'extrait de la couche
correspondant à un rectangle et pour la classe TiffLayer la méthode items() qui génère en GeoJSON les silhouettes des GéoTiffs.

La classe abstraite Layer définit les couches du serveur de cartes.
La classe TiffLayer correspond aux couches agrégeant des GéoTiff.
La classe PyrLayer correspond à la pyramide des TiffLayer qui permet d'afficher le bon GéoTiff en fonction du niveau de zoom.
Enfin, la classe LabelLayer correspond aux étiquettes associées aux GéoTiff.
#### inclus
        - ../vendor/autoload.php
        - lib/grefimg.inc.php
        - lib/geotiff.inc.php
        - lib/zoom.inc.php
        - lib/isomd.inc.php

### vectorlayer.inc.php - gestion de couches d'objets vecteur
#### inclus
        - lib/layer.inc.php
        - lib/gegeom.inc.php

### lib/coordsys.inc.php (v3) - changement simple de projection a priori sur l'ellipsoide IAG_GRS_1980
#### identique à
        - ../sgupdt/lib/coordsys.inc.php
#### inclus
        - lib/sexcept.inc.php

### log.inc.php - Enregistrement d'un log
#### inclus
        - lib/mysql.inc.php
        - lib/sexcept.inc.php

### mysql.inc.php  - Classes MySql et MySqlResult utilisées pour exécuter des requêtes MySql
#### inclus
        - lib/sexcept.inc.php
        - lib/config.inc.php

### pos.inc.php - Définition des classes statiques Pos, LPos, LLPos
Comme dans GeoJSON, on distingue la notion de Point, qui est une primitive géométrique, de la notion de position
qui permet de construire les primitives géométriques.
Ainsi:
  - une position est stockée comme une liste de 2 ou 3 nombres
    et la classe Pos regroupe des méthodes statiques qui s'appliquent à une position,
  - la classe LPos regroupe des méthodes statiques qui s'appliquent à une liste de positions, et
  - la classe LLPos regroupe des méthodes statiques qui s'appliquent à une liste de listes de positions.
#### identique à
        - ../sgupdt/lib/pos.inc.php

### lib/sexcept.inc.php - Exception avec code string
#### identique à
        - ../sgupdt/lib/sexcept.inc.php

### lib/wmsserver.inc.php - définition de la classe abstraite WmsServer
La classe abstraite **WmsServer** gère de manière minimum les protocole WMS 1.1.1 et 1.3.0 et fournit qqs méthodes génériques ;
elle est indépendante des fonctionnalités du serveur de shomgt.
Elle génère un fichier temporaire de log utile au déverminage.


### lib/zoom.inc.php  - définition de la classe Zoom regroupant l'intelligence autour du tuilage et des niveaux de zoom
#### identique à
        - ../sgupdt/lib/zoom.inc.php
#### inclus
        - lib/sexcept.inc.php
        - lib/gebox.inc.php
