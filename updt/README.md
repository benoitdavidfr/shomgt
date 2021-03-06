# Module de mise à jour des cartes SHOM pour les services de consultation

Ce module peut être utilisé soit pour charger automatiquement des cartes depuis un serveur maitre, c'est le mode appelé esclave,
soit pour intégrer une livraison Shom de cartes, chacune sous la forme d'une archive .7z.

## Mise à jour automatique en mode esclave
La mise à jour automatique s'effectue au moyen du script `slaveupdt.php` du module `updt`. 

Commencer par définir si nécessaire le proxy à utiliser en le définissant dans la variable shell `http_proxy`,
par exemple:

    export http_proxy='http://monproxy.mondomaine:8080'

Puis définir si nécessaire le login et mot de passe d'accès au maitre dans la variable `shomgtuserpwd`, par exemple:

    export shomgtuserpwd='demo:demo'

Les cartes peuvent être sélectionnées par zone définie par son code ISO alpha 2, FX pour métropole, RE pour La Réunion, ...
Les codes FR pour toute la France ou WLD pour toutes les cartes peuvent aussi être utilisés.
Attention la commande php génère du code sh et son résultat doit donc être éxécuté par sh ;
cela se fait en faisant suivre la commande php par `| sh`

    docker$ cd updt
    docker$ php slaveupdt.php RE | sh


## Mise à jour manuelle en mode autonome
Pour cela il faut :

  - convertir les archives 7z fournies par le Shom dans le format utilisé par les web-services
    puis installer les fichiers produits dans le répertoire de stockage des GéoTiffs
  - mettre à jour du catalogue des GéoTiffs `shomgt.yaml` stocké dans `../ws/`

Les cartes Shom doivent être stockées initialement dans un répertoire de livraison
dans `{shomgt}/../../shomgeotiff/incoming/{date}/`
où `{date}` est la date de livraison sous la forme `YYMMDD`.  
En outre chaque livraison devrait comporter un fichier `index.yaml` contenant notamment un champ 'toDelete'
qui contient un dictionnaire des cartes à supprimer à l'occasion de cette livraison.  
Ce dictionnaire est indexé par l'identifiant de la carte à détruire.
Il n'est pas nécessaire de mentionner les cartes à remplacer.

    exemple:
      toDelete: # cartes à supprimer de shomgt
        FR0982: Ile Saint-Pierre, Port de Saint-Pierre, Port de Miquelon (1/20.000)
        FR6725: Estuaire de la Tamise - Partie Sud (1/50000)
        FR6774: Puerto de Bilbao (1/12500) - 2018
        FR6786: De Biscarrosse à San Sebastian
        FR6851: Ports d'Ajaccio et de Propriano (1/10.000)
        FR5438:
          title: Océan Pacifique (1/27.000.000)
          edition: Édition n° 3 - 1943
          comment: n'apporte rien par rapport au planisphère et s'intègre mal du fait de son style

Le répertoire de stockage des cartes est `{shomgt}/../../shomgeotiff/current/` dans lequel chaque carte correspond
à un répertoire ayant pour nom le numéro de la carte {mapno}, ex: 7121  
Chaque répertoire de carte provient du dézippage du 7z de livraison.  
Il contient pour chaque GéoTiff nommé {gtname} issu de la livraison :

  - un fichier {gtname}.info produit par gdalinfo à partir du fichier au format GéoTIFF,
  - un fichier `CARTO_GEOTIFF_{gtname}.xml` contenant les métadonnées ISO 19115/139 du fichier GéoTiff,
  - un répertoire {gtname} contenant les dalles PNG correspondant à un découpage 1024 X 1024 du GéoTiff
    ces dalles sont découpées de gauche à droite et du haut vers le bas
    elles sont nommées par `{x}-{y}.png` où `{x}` est le numéro de colonne et `{y}` le numéro de ligne

L'id {gtname} du GéoTiff est défini par le Shom dans la livraison.
Il est de la forme :

  - {mapno}_pal300 pour la zone gégraphique principale de la carte numéro {mapno}, ex: 7121_pal300
  - {mapno}_{gtid}_gtw pour chaque zone gégraphique secondaire de la carte numéro {mapno} identifiée par {gtid}
    {gtid} est soit un numéro à partir de 1, soit une lettre à partir de A, ex: 7354_13_gtw

Seules les dalles PNG sont utilisées par les web-services de tuiles et WMS.
Les fichiers de MD XML sont utilisés par le module catalogue pour comparer la version des GéoTiff exposés
avec celle définie par le GAN.
Cette version est définie par l'édition de la carte ainsi que le nombre de corrections apportées à la carte.

L'identifiant `{mapno}/{gtname}` est utilisé dans `shomgt.yaml` pour identifier un GéoTiff dans le module ws.

### Données complémentaires
- les géotiffs sont répartis en couches en fonction de leur échelle,
  ainsi chaque couche est définie par un intervalle d'échelles défini dans `shomgt.php` ;
- le contenu de chaque couche est impacté par l'ordre des géotiffs dans leur catalogue
  qui peut être imposé dans le fichier `updt.yaml`.

### Algorithme

  - pour chaque livraison
    - les fichiers 7z de cartes sont dézippés et le répertoire résultant est déplacé dans
      `{shomgt}/../../shomgeotiff/tmp/`
    - pour chaque carte et chaque GéoTiff:
      - le fichier info est généré à partir du format GéoTIFF par gdal_info
      - le GéoTiff est converti en PNG par gdal puis supprimé
      - le PNG est découpé en dalles 1024 X 1024 puis supprimé
    - transfert des cartes de `{shomgt}/../../shomgeotiff/tmp/` dans `{shomgt}/../../shomgeotiff/current/`
    - les cartes périmées sont supprimées,
  - génération du catalogue Yaml des GéoTiff et écriture dans `../ws/shomgt.yaml`
  - suppression du cache `{shomgt}/tilecache` 

### Mise en oeuvre

  - tous les scripts doivent être appelés en ligne de commande
  - `updt.php` est appelé avec en paramètre les noms des livraisons et génère les cmdes sh pour:
    - dézipper les 7z des livraisons,
    - déplacer les répertoires dézippés dans le répertoire tmp
    - générer pour chaque GéoTiff un fichier .info avec gdalinfo
    - convertir chaque GéoTiff en PNG avec gdal_translate
    - découper chaque GéoTiff en dalles 1024 X 1024 avec tile.php
    - génèrer un catalogue Yaml des GéoTiff et l'enregistre dans le fichier ../ws/shomgt.yaml
  - `tile.php` découpe un fichiers PNG en dalles 1024 X 1024 et effectue un effacement de la partie définie dans updt.yaml
  - `shomgt.php` génère un catalogue Yaml des géotiffs à enregistrer dans le fichier `../ws/shomgt.yaml`.
  
### En pratique

  - En préalable à la mise à jour: actualiser le catalogue.
  - La mise à jour nécessite les étapes suivantes:
    - copier les fichier 7z de carte dans un répertoire de livraison et nommer ce répertoire par la date
      sous la forme `YYYYMMDD`
    - lancer en ligne de commande le script updt.php avec les noms de livarison en paramètres et en le pipant avec un sh ;
      il enchaine différentes commandes de dezippage, de transformation en PNG et de découpage
      seuls les PNG découpés sont conservés à la fin pour économiser l'espace.

