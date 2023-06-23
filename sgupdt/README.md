# Module sgupdt de ShomGT3
L'objectif de ce module est, en interrogeant [sgserver](../sgserver) en http, de construire et mettre à jour 
les fichiers nécessaires à [shomgt](../shomgt), stockés dans un répertoire [data](../data).  

### Variables d'environnement

- `SHOMGT3_SERVER_URL` doit contenir l'URL du serveur de cartes 7z sgserver
  qui doit contenir si nécessaire le login/passwd
- `SHOMGT3_UPDATE_DURATION`contient le délai en jours entre 2 mises à jour (défaut 28)
- `http_proxy` contient si nécessaire le proxy pour accéder à sgserver, défaut pas de proxy
- `https_proxy` contient si nécessaire le proxy à utiliser pour le serveur de cartes 7z, défaut pas de proxy

## Liste des fichiers Php et principales classes et fonctions du module
### main.php - script principal de mise à jour des cartes
L'algorithme de mise à jour des cartes est le suivant:

- télécharge depuis sgserver le catalogue MapCat
- télécharge depuis sgserver la liste des cartes exposées et la version correspondante (maps.json)
- pour chaque carte exposée par sgserver
  - si ni la version ni la zone à effacer n'ont changée
    - alors passage à la carte suivante
  - télécharge la carte depuis sgserver et la dézippe,
  - puis pour chaque fichier GéoTiff
    - extrait les informations de géoréférencement du GéoTiff dans le fichier {gtname}.info.json
      où {gtname} est le nom du fichier TIFF sans son extension `.tif`,
    - transforme l'image TIFF en PNG,
    - découpe avec `maketile.php` l'image PNG en dalles 1024x1024 pour faciliter leur utilisation
      et applique les effacements définis dans le catalogue MapCat
- enfin génère avec `shomgt.php` le [fichier shomgt.yaml](../data#le-fichier-shomgtyaml)
  après avoir vérifié sa conformité à son schéma JSON défini dans [shomgt.schema.yaml](shomgt.schema.yaml).

En fonction de la variable `SHOMGT3_UPDATE_DURATION`, le script suspend son exécution et se relance plus tard de manière 
éternelle.

`main.php` est utilisé en mode CLI.
#### inclus
        - ../lib/envvar.inc.php
        - ../lib/execdl.inc.php
        - ../lib/readmapversion.inc.php
        - ../lib/mapcat.inc.php
        - ../vendor/autoload.php

### maketile.php - découpe un PNG en dalles de 1024 X 1024 + effacement de zones définies dans mapcat.yaml
Découpe un PNG correspondant à un GéoTiff et efface les zones spécifiées dans mapcat.yaml
#### inclus
        - ../vendor/autoload.php
        - ../lib/mapcat.inc.php
        - ../lib/gdalinfo.inc.php
        - ../lib/gebox.inc.php
        - ../lib/grefimg.inc.php

### shomgt.php - génère le fichier shomgt.yaml
Génère le fichier shomgt.yaml dans [data](../data/) après avoir vérifié sa conformité à son schéma JSON
défini dans [shomgt.schema.yaml](shomgt.schema.yaml).
#### inclus
        - schema/jsonschema.inc.php
        - ../lib/geotiffs.inc.php
        - ../lib/mapcat.inc.php
        - ../vendor/autoload.php

### schema/jsonschema.inc.php - validation de la conformité d'une instance Php à un schéma JSON
Code utilisé dans main.php pour valider shomgt.yaml par rapport à son schéma JSON défini dans shomgt.schema.yaml.
#### inclus
        - ../vendor/autoload.php
        - schema/jsonschfrg.inc.php

### schema/jsonschfrg.inc.php
Utilisé par schema/jsonschema.inc.php.
