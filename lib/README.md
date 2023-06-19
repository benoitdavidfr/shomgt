# Bibliothèque commune de scripts Php pour ShomGT3
### wmsserver.inc.php - définition de la classe abstraite WmsServer
La classe abstraite **WmsServer** gère de manière minimum les protocole WMS 1.1.1 et 1.3.0 et fournit qqs méthodes génériques ;
elle est indépendante des fonctionnalités du serveur de shomgt.
Elle génère un fichier temporaire de log utile au déverminage.

### layer.inc.php  - Définition des classes Layer, PyrLayer, LabelLayer et TiffLayer
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
        - grefimg.inc.php
        - geotiff.inc.php
        - zoom.inc.php
        - isomd.inc.php

### vectorlayer.inc.php - gestion de couches d'objets vecteur
#### inclus
        - layer.inc.php
        - gegeom.inc.php

### geotiffs.inc.php - liste les GeoTiffs
#### inclus
        - envvar.inc.php

### geotiff.inc.php - définition de la classe GeoTiff implémentant des méthodes sur un GéoTiff
#### inclus
        - gdalinfo.inc.php
        - envvar.inc.php

### grefimg.inc.php  - Définition de la classe GeoRefImage gérant une image géoréférencée
La classe GeoRefImage propose différentes méthodes sur une image géoréférencée
en étendant la bibliothèque [GD](https://www.php.net/manual/fr/book.image.php) avec:

   - la définition d'un espace en coordonnées utilisateurs formalisé par un rectangle englobant dans
     un système de coordonnées projeté comme WorldMercator,
   - une notion de style inspiré de Leaflet pour dessiner des polylignes et des polygones.
#### inclus
        - gebox.inc.php
        - sexcept.inc.php

### gegeom.inc.php - package géométrique utilisant des coordonnées géographiques ou euclidiennes
Ce fichier définit la classe abstraite Geometry, des sous-classes
par type de [géométrie GeoJSON](https://tools.ietf.org/html/rfc7946)
ainsi qu'une classe Segment utilisé pour certains calculs.
Une géométrie GeoJSON peut être facilement créée en décodant le JSON en Php par json_decode()
puis en apppelant la méthode Geometry::fromGeoJSON().
#### inclus
        - coordsys.inc.php
        - zoom.inc.php
        - gebox.inc.php
        - sexcept.inc.php

### gebox.inc.php - définition de classes définissant un BBox avec des coord. géographiques ou euclidiennes
La classe GBox définit un bbox en coordonnées géographiques.  
La classe EBox définit un bbox en coordonnées euclidiennes projetées (World Mercator ou WebMercator).
#### inclus
        - pos.inc.php
        - zoom.inc.php
        - coordsys.inc.php
        - sexcept.inc.php

### pos.inc.php - Définition des classes statiques Pos, LPos, LLPos
Comme dans GeoJSON, on distingue la notion de Point, qui est une primitive géométrique, de la notion de position
qui permet de construire les primitives géométriques.
Ainsi:
  - une position est stockée en Php comme une liste de 2 ou 3 nombres
    et la classe Pos regroupe des méthodes statiques qui s'appliquent à une position,
  - la classe LPos regroupe des méthodes statiques qui s'appliquent à une liste de positions, et
  - la classe LLPos regroupe des méthodes statiques qui s'appliquent à une liste de listes de positions.

### zoom.inc.php  - définition de la classe Zoom regroupant l'intelligence autour du tuilage et des niveaux de zoom
#### inclus
        - sexcept.inc.php
        - gebox.inc.php

### coordsys.inc.php (v3) - changement simple de projection a priori sur l'ellipsoide IAG_GRS_1980
Code utilisé pour changer des coordonnées de système de coordonnées entre World Mercator (la projection des cartes),
les coordonnées géographiques WGS84 (utilisé pour fournir des informations) et Web Mercator (la projection des tuiles).
#### inclus
        - sexcept.inc.php

### accesscntrl.inc.php - contrôle d'accès
#### inclus
        - log.inc.php
        - config.inc.php

### cache.inc.php -  gestion d'un cache simple des tuiles
#### inclus
        - envvar.inc.php

### config.inc.php - fichier de config par défaut
Retourne le  contenu du fichier de config.
Utilise le fichier `../secrets/secretconfig.inc.php` uniquement s'il existe.
#### inclus
        - ../secrets/secretconfig.inc.php

### gdalinfo.inc.php - Analyse un JSON fabriqué par GDAL INFO et en extrait les infos essentielles
Extrait du fichier JSON fabriqué par [gdalinfo](https://gdal.org/programs/gdalinfo.html)
les infos essentielles qui sont la taille de l'image en nombre de pixels et si le fichier est géoréférencé 
son extension en coordonnées World Mercator et en coordonnées géographiques.
#### inclus
        - geotiffs.inc.php
        - gebox.inc.php
        - envvar.inc.php
        - sexcept.inc.php
        - ../vendor/autoload.php

### envvar.inc.php - envvar.inc.php - gestion des variables d'environnement et de leur valeur par défaut
Simplifie l'utilisation des variables d'environnement.

### errortile.inc.php - Génération d'une image d'erreur contenant le message d'erreur et l'identifiant de la tuile

### isomd.inc.php - Récupération de MD ISO d'un GéoTiff'
#### inclus
        - envvar.inc.php

### log.inc.php - Enregistrement d'un log
#### inclus
        - mysql.inc.php
        - sexcept.inc.php

### mysql.inc.php  - Classes MySql et MySqlResult utilisées pour exécuter des requêtes MySql
#### inclus
        - sexcept.inc.php
        - config.inc.php

### sexcept.inc.php - Exception avec code string

### execdl.inc.php - fonctions execCmde() et download()
Simplifie l'utilisation des commandes d'exécution d'un autre script et de téléchargement d'un fichier en Http.

### mapcat.inc.php - charge le catalogue de cartes et sait retourner pour un gtname les infos correspondantes
#### inclus
        - envvar.inc.php
        - execdl.inc.php
        - gdalinfo.inc.php

### readmapversion.inc.php - extrait du fichier MD ISO dont le path est fourni la version de la carte et la date dateStamp
