# Module sgupdt de ShomGT3
Ce module permet de construire et mettre à jour les fichiers nécessaires à [shomgt](../shomgt),
stockés dans un répertoire [data](../data), en interrogeant [sgserver](../sgserver2) en http.  

### Variables d'environnement

- `SHOMGT3_SERVER_URL` doit contenir l'URL du serveur de cartes 7z sgserver ;
  elle doit contenir si nécessaire le login/passwd
- `SHOMGT3_UPDATE_DURATION`contient le délai en jours entre 2 mises à jour (défaut 28)
- `http_proxy` contient si nécessaire le proxy pour accéder à sgserver, défaut absent
- `https_proxy` contient si nécessaire le proxy à utiliser pour le serveur de cartes 7z, défaut absent

## Liste des fichiers Php et principales classes et fonctions du module
### main.php - script principal de mise à jour des cartes
Main.php est le script de mise à jour des cartes.  
Il détecte les cartes qui ont besoin d'être téléchargée et si c'est le cas
  - les télécharge depuis sgserver,
  - dézippe l'archive téléchargée
  - puis pour chaque fichier GéoTiff
    - extrait les informations de géoréférencement
    - transforme l'image en PNG
    - découpe avec maketile.php l'image en dalles 1024x1024 pour faciliter l'utilisation des images
Et enfin génère fichier shomgt.yaml qui conserve un certain nombre de paramètres avec shomgt.php.
La conformité du fichier shomgt.yaml par rapport à son schéma JSON est vérifiée.

En fonction de la variable `SHOMGT3_UPDATE_DURATION`, le script suspend son exécution et se relance plus tard de manière 
éternelle.

    includes:
        - /sgupdt/lib/envvar.inc.php
        - /sgupdt/lib/execdl.inc.php
        - /sgupdt/lib/readmapversion.inc.php
        - /sgupdt/lib/mapcat.inc.php
        - /vendor/autoload.php

### maketile.php - découpe un PNG en dalles de 1024 X 1024 + effacement de zones définies dans mapcat.yaml
Découpe les PNG correspondant au cartes et efface les zones spécifiées dans mapcat.yaml

    includes:
        - /vendor/autoload.php
        - /sgupdt/lib/mapcat.inc.php
        - /sgupdt/lib/gdalinfo.inc.php
        - /sgupdt/lib/gebox.inc.php
        - /sgupdt/lib/grefimg.inc.php
### shomgt.php - génération du fichier shomgt.yaml
    includes:
        - /vendor/autoload.php
        - /sgupdt/schema/jsonschema.inc.php
        - /sgupdt/lib/geotiffs.inc.php
        - /sgupdt/lib/mapcat.inc.php
### schema/jsonschema.inc.php - validation de la conformité d'une instance Php à un schéma JSON
Ce code est utilisé dans main.php pour valider schomgt.yaml par rapport à son schéma JSON défini dans schomgt.schema.yaml.

    includes:
        - /vendor/autoload.php
        - /sgupdt/schema/jsonschfrg.inc.php
### schema/jsonschfrg.inc.php
Utilisé par schema/jsonschema.inc.php.

### lib/config.inc.php  - fichier de config par défaut
Retourne le e contenu du fichier de config.

    sameAs:
        - /shomgt/lib/config.inc.php
    includes:
        - /secrets/secretconfig.inc.php
### lib/coordsys.inc.php (v3) - changement simple de projection a priori sur l'ellipsoide IAG_GRS_1980
Code utilisé pour changer des coordonnées de système de coordonnées entre World Mercator (la projection des cartes),
les coordonnées géographiques WGS84 (utilisé pour fournir des informations).

    sameAs:
        - /shomgt/lib/coordsys.inc.php
    includes:
        - /sgupdt/lib/sexcept.inc.php
### lib/envvar.inc.php - envvar.inc.php - gestion des variables d'environnement et de leur valeur par défaut
Simplifie l'utilisation des variables d'environnement.

    sameAs:
        - /shomgt/lib/envvar.inc.php
### lib/execdl.inc.php - fonctions execCmde() et download()
Simplifie l'utilisation des commandes d'exécution d'un autre script et de téléchargement d'un fichier en Http.

### lib/gdalinfo.inc.php - Analyse un JSON fabriqué par GDAL INFO et en extrait les infos essentielles
Extrait des infos utiles du fichier JSON fabriqué par [gdalinfo](https://gdal.org/programs/gdalinfo.html).

    sameAs:
        - /shomgt/lib/gdalinfo.inc.php
    includes:
        - /sgupdt/lib/sexcept.inc.php
        - /sgupdt/lib/envvar.inc.php
        - /sgupdt/lib/gebox.inc.php
        - /vendor/autoload.php
        - /sgupdt/lib/geotiffs.inc.php
### lib/gebox.inc.php - définition de classes définissant un BBox avec des coord. géographiques ou euclidiennes
La classe GBox définit un bbox en coordonnées géographiques.  
La classe EBox définit un bbox en coordonnées euclidiennes projetées.

    sameAs:
        - /shomgt/lib/gebox.inc.php
    includes:
        - /sgupdt/lib/coordsys.inc.php
        - /sgupdt/lib/pos.inc.php
        - /sgupdt/lib/sexcept.inc.php
        - /sgupdt/lib/zoom.inc.php

### lib/geotiffs.inc.php - liste les GeoTiffs
    sameAs:
        - /shomgt/lib/geotiffs.inc.php
    includes:
        - /sgupdt/lib/envvar.inc.php
### lib/grefimg.inc.php - Définition de la classe GeoRefImage gérant une image géoréférencée
    sameAs:
        - /shomgt/lib/grefimg.inc.php
    includes:
        - /sgupdt/lib/sexcept.inc.php
        - /sgupdt/lib/gebox.inc.php
### lib/mapcat.inc.php - charge le catalogue de cartes et sait retourner pour un gtname les infos correspondantes
    includes:
        - /sgupdt/lib/envvar.inc.php
        - /sgupdt/lib/execdl.inc.php
        - /sgupdt/lib/gdalinfo.inc.php
### lib/pos.inc.php - définition des classes statiques Pos, LPos, LLPos'
    sameAs:
        - /shomgt/lib/pos.inc.php
### lib/readmapversion.inc.php - extrait du fichier MD ISO dont le path est fourni la version de la carte et la date dateStamp

### lib/sexcept.inc.php - Exception avec code string
    sameAs:
        - /shomgt/lib/sexcept.inc.php
### lib/zoom.inc.php - définition de la classe Zoom regroupant l''intelligence autour du tuilage et des niveaux de zoom
    sameAs:
        - /shomgt/lib/zoom.inc.php
    includes:
        - /sgupdt/lib/sexcept.inc.php
        - /sgupdt/lib/gebox.inc.php
