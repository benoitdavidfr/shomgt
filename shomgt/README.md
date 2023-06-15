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

### tile.php - webservice au standard XYZ d'accès aux GéoTIFF du Shom
Affichage des cartes Shom conformément au standard XYZ (voir https://en.wikipedia.org/wiki/Tiled_web_map)
facile à utiliser dans une carte Leaflet.

Les points d'accès sont:

  - /tile.php - affichage de la documentation du service
  - /tile.php/{layer} - affichage de la documentation de la couche
  - /tile.php/{layer}/{z}/{x}/{y}.png - retourne la tuile du niveau de zoom {z}, colonne {x} et ligne {y}

#### inclus
        - /shomgt/lib/log.inc.php
        - /shomgt/lib/gegeom.inc.php
        - /shomgt/lib/layer.inc.php
        - /shomgt/lib/cache.inc.php
        - /shomgt/lib/errortile.inc.php
        - /vendor/autoload.php
        - /secrets/tileaccess.inc.php

### wms.php - service WMS de shomgt avec authentification
    includes:
        - /shomgt/lib/accesscntrl.inc.php
        - /shomgt/lib/coordsys.inc.php
        - /shomgt/lib/gebox.inc.php
        - /shomgt/lib/wmsserver.inc.php
        - /shomgt/lib/layer.inc.php
        - /secrets/protect.inc.php
### wmsv.php - service WMS pour les couches vecteur de ShomGT
    includes:
        - /vendor/autoload.php
        - /shomgt/lib/coordsys.inc.php
        - /shomgt/lib/gebox.inc.php
        - /shomgt/lib/wmsserver.inc.php
        - /shomgt/lib/vectorlayer.inc.php

### mapwcat.php - carte Leaflet avec les couches de geotiff, les catalogues, la ZEE, ...
    includes:
        - /shomgt/lib/accesscntrl.inc.php

### maps.php - point d'accès de l'API de maps
    includes:
        - /shomgt/lib/layer.inc.php
        - /shomgt/lib/accesscntrl.inc.php

### dl.php - téléchargements appelé depuis la carte avec un gtname en paramètre
    includes:
        - /shomgt/lib/envvar.inc.php
        - /shomgt/lib/gdalinfo.inc.php
        - /shomgt/lib/accesscntrl.inc.php

### lib/accesscntrl.inc.php - contrôle d'accès
    includes:
        - /shomgt/lib/log.inc.php
        - /shomgt/lib/config.inc.php

### lib/cache.inc.php -  gestion d'un cache simple des tuiles
    includes:
        - /shomgt/lib/envvar.inc.php

### lib/config.inc.php - fichier de config par défaut
    sameAs:
        - /sgupdt/lib/config.inc.php
    includes:
        - /secrets/secretconfig.inc.php


### lib/coordsys.inc.php (v3) - changement simple de projection a priori sur l'ellipsoide IAG_GRS_1980
    sameAs:
        - /sgupdt/lib/coordsys.inc.php
    includes:
        - /shomgt/lib/sexcept.inc.php

### lib/envvar.inc.php:
    sameAs:
        - /sgupdt/lib/envvar.inc.php
### lib/errortile.inc.php:

### lib/gdalinfo.inc.php:
    sameAs:
        - /sgupdt/lib/gdalinfo.inc.php
    includes:
        - /shomgt/lib/ sexcept.inc.php
        - /shomgt/lib/ envvar.inc.php
        - /shomgt/lib/gebox.inc.php
        - /vendor/autoload.php
        - /shomgt/lib/geotiffs.inc.php
### lib/gebox.inc.php:
    sameAs:
        - /sgupdt/lib/gebox.inc.php
    includes:
        - /shomgt/lib/coordsys.inc.php
        - /shomgt/lib/pos.inc.php
        - /shomgt/lib/sexcept.inc.php
        - /shomgt/lib/zoom.inc.php
### lib/gegeom.inc.php:
    includes:
        - /shomgt/lib/coordsys.inc.php
        - /shomgt/lib/zoom.inc.php
        - /shomgt/lib/gebox.inc.php
        - /shomgt/lib/sexcept.inc.php
### lib/geotiff.inc.php - définition de la classe GeoTiff
    includes:
        - /shomgt/lib/envvar.inc.php
        - /shomgt/lib/gdalinfo.inc.php
### lib/geotiffs.inc.php - liste les GeoTiffs
    sameAs:
        - /sgupdt/lib/geotiffs.inc.php
    includes:
        - /shomgt/lib/envvar.inc.php
### lib/grefimg.inc.php  - Définition de la classe GeoRefImage gérant une image géoréférencée'
    sameAs:
        - /sgupdt/lib/grefimg.inc.php
    includes:
        - /shomgt/lib/sexcept.inc.php
        - /shomgt/lib/gebox.inc.php
### isomd.inc.php - Récupération de MD ISO d'un GéoTiff'
    includes:
        - /shomgt/lib/envvar.inc.php
### layer.inc.php  - Définition des classes Layer, PyrLayer, LabelLayer et TiffLayer
    includes:
        - /vendor/autoload.php
        - /shomgt/lib/grefimg.inc.php
        - /shomgt/lib/geotiff.inc.php
        - /shomgt/lib/zoom.inc.php
        - /shomgt/lib/isomd.inc.php
### log.inc.php - Enregistrement d'un log
    includes:
        - /shomgt/lib/mysql.inc.php
        - /shomgt/lib/sexcept.inc.php
### mysql.inc.php  - Classes MySql et MySqlResult utilisées pour exécuter des requêtes MySql
    includes:
        - /shomgt/lib/sexcept.inc.php
        - /shomgt/lib/config.inc.php
### pos.inc.php - Définition des classes statiques Pos, LPos, LLPos
    sameAs:
        - /sgupdt/lib/pos.inc.php

### lib/sexcept.inc.php - Exception avec code string
    sameAs:
        - /sgupdt/lib/sexcept.inc.php

### vectorlayer.inc.php
    includes:
        - /shomgt/lib/layer.inc.php
        - /shomgt/lib/gegeom.inc.php
### wmsserver.inc.php - définition de la classe abstraite WmsServer'

### zoom.inc.php  - définition de la classe Zoom regroupant l'intelligence autour du tuilage et des niveaux de zoom
    sameAs:
        - /sgupdt/lib/zoom.inc.php
    includes:
        - /shomgt/lib/sexcept.inc.php
        - /shomgt/lib/gebox.inc.php
