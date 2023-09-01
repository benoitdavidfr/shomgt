# Bibliothèque commune de fonctions et classes
### layer.inc.php  - Classes Layer, PyrLayer, LabelLayer et TiffLayer
La classe Layer est une classe abstraite d'une couche de ShomGT qui regoupe les classes PyrLayer, LabelLayer et TiffLayer.  
La classe Layer contient en outre en statique le dictionnaire des couches de ShomGT.  
Les objets Layer sont construits à partir du contenu du [fichier shomgt.yaml](../data#le-fichier-shomgtyaml).   

Un objet Layer, au travers de la méthode map(), sait recopier dans une image GD l'extrait de la couche
correspondant à un rectangle.

La classe TiffLayer correspond aux couches agrégeant des GéoTiff ;
la méthode items() génère en GeoJSON les silhouettes des GéoTiffs.  
La classe PyrLayer correspond à la pyramide des TiffLayer qui permet d'afficher le bon GéoTiff en fonction du niveau de zoom.  
Enfin, la classe LabelLayer correspond aux étiquettes associées aux GéoTiff.  
#### inclus
        - ../vendor/autoload.php
        - grefimg.inc.php
        - geotiff.inc.php
        - zoom.inc.php
        - isomd.inc.php

### vectorlayer.inc.php - Classe VectorLayer gérant les couches d'objets vecteur
La classe VectorLayer gère une couche d'objets vecteur et est utilisé par ../view/wmsv.php qui implémente
un serveur WMS pour les couches vecteur.  
Comme pour la classe PyrLayer, la méthode map(), sait recopier dans une image GD l'extrait image de la couche
correspondant à un rectangle et la méthode items() .
#### inclus
        - layer.inc.php
        - gegeom.inc.php

### geotiffs.inc.php - liste les GeoTiffs
Ce script définit la fonction geotiffs() qui tretourne la liste des GéoTiffs dans SHOMGT3_MAPS_DIR_PATH.
#### inclus
        - envvar.inc.php

### geotiff.inc.php - Classe GeoTiff implémentant des méthodes sur un GéoTiff
La classe GeoTiff définit plusieurs méthodes sur un GéoTiff,
notamment la méthode copyImage() qui recopie dans un GeoRefImage la partie du GéoTiff
qui correspond à une boite en coordonnées WorldMercator.
#### inclus
        - gdalinfo.inc.php
        - envvar.inc.php

### grefimg.inc.php  - Classe GeoRefImage gérant une image géoréférencée
La classe GeoRefImage propose différentes méthodes sur une image géoréférencée
en étendant la bibliothèque [GD](https://www.php.net/manual/fr/book.image.php) avec:

   - la définition d'un espace en coordonnées utilisateurs formalisé par une boite englobante (EBox)
     dans un système de coordonnées projeté comme WorldMercator,
   - une notion de style inspiré de Leaflet pour dessiner des polylignes et des polygones.
#### inclus
        - gebox.inc.php
        - sexcept.inc.php

### gegeom.inc.php - package géométrique utilisant des coordonnées géographiques ou euclidiennes
Ce fichier définit la classe abstraite Geometry, des sous-classes
par type de [géométrie GeoJSON](https://tools.ietf.org/html/rfc7946)
ainsi qu'une classe Segment utilisé pour certains calculs.
Une géométrie GeoJSON peut être facilement créée en décodant le JSON en Php par json_decode()
puis en apppelant la méthode Geometry::fromGeoArray().
#### inclus
        - coordsys.inc.php
        - zoom.inc.php
        - gebox.inc.php
        - sexcept.inc.php

### gebox.inc.php - Classes définissant un BBox avec des coord. géographiques ou euclidiennes
La classe abstraite BBox définit une boite englobante en coordonnées géographiques ou euclidiennes.  
La classe GBox définit une boite en coordonnées géographiques.  
La classe EBox définit une boite en coordonnées euclidiennes projetées (World Mercator ou WebMercator).
#### inclus
        - pos.inc.php
        - zoom.inc.php
        - coordsys.inc.php
        - sexcept.inc.php

### pos.inc.php - Types Pos, LPos, LLPos
Comme dans GeoJSON, on distingue la notion de Point, qui est une primitive géométrique, de la notion de position
qui permet de construire les primitives géométriques.
Ainsi:

  - le type Pos correspond à une position stockée en Php comme une liste de 2 ou 3 nombres
    et la classe Pos regroupe des méthodes statiques qui s'appliquent à une position,
  - le type LPos correspond à une liste de Pos
    et la classe LPos regroupe des méthodes statiques qui s'appliquent à de telles listes, et
  - le type LLPos correspond à une liste de LPos
    et la classe LLPos regroupe des méthodes statiques qui s'appliquent à une valeur de ce type.

Ces 3 types sont aussi définis en PhpStan respectivemet sous les libellés TPos, TLPos et TLLPos.

### zoom.inc.php  - Classe Zoom regroupant l'intelligence autour du tuilage et des niveaux de zoom
#### inclus
        - sexcept.inc.php
        - gebox.inc.php

### coordsys.inc.php (v3) - changement simple de projection a priori sur l'ellipsoide IAG_GRS_1980
Code utilisé pour changer des coordonnées de système de coordonnées entre World Mercator (la projection des cartes),
les coordonnées géographiques WGS84 (utilisé pour fournir des informations) et Web Mercator (la projection des tuiles).
#### inclus
        - sexcept.inc.php

### wmsserver.inc.php - définition de la classe abstraite WmsServer
La classe abstraite **WmsServer** gère de manière minimum les protocole WMS 1.1.1 et 1.3.0 et fournit qqs méthodes génériques ;
elle est indépendante des fonctionnalités du serveur de shomgt.
Elle génère un fichier temporaire de log utile au déverminage.

### jsonschema.inc.php - validation de la conformité d'une valeur Php à un schéma JSON
Utilisé dans main.php pour valider shomgt.yaml par rapport à son schéma JSON défini dans shomgt.schema.yaml.
#### inclus
        - ../vendor/autoload.php
        - jsonschfrg.inc.php

### jsonschfrg.inc.php
Utilisé par schema/jsonschema.inc.php.

### mapcat.inc.php - charge le catalogue de cartes et sait retourner pour un gtname les infos correspondantes
#### inclus
        - envvar.inc.php
        - execdl.inc.php
        - gdalinfo.inc.php

### cache.inc.php -  gestion d'un cache simple des tuiles
#### inclus
        - envvar.inc.php

### accesscntrl.inc.php - contrôle d'accès
#### inclus
        - log.inc.php
        - config.inc.php

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

### readmapversion.inc.php - extrait du fichier MD ISO dont le path est fourni la version de la carte et la date dateStamp

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
