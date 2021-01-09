## ShomGT - services de consultation des cartes raster GéoTIFF du Shom

L'objectif de ce projet est d'exposer sous la forme de web-services le contenu des
[cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant les zones sous juridiction française pour permettre au [MTES](http://www.ecologique-solidaire.gouv.fr)
et au [MCTRCT](http://www.cohesion-territoires.gouv.fr/)
d'assurer leurs missions de service public.

La principale plus-value est de permettre de consulter le contenu des cartes en supprimant leur cadre
afin de passer d'une carte à l'autre sans couture et d'intégrer ces données dans les outils SIG habituels,
comme [QGis](https://www.qgis.org/).

Pour utiliser ces web-services, des cartes Shom doivent être intégrées au serveur.
Cela nécessite que les utilisateurs disposent des droits  d'utilisation de ces cartes.
C'est le cas notamment des services et des EPA de l'Etat conformément à
[l'article 1 de la loi Pour une République numérique](https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte).
Pour les autres acteurs, consuler le Shom.

Ce projet propose les services suivants :

  - un service de tuiles au [format XYZ](https://en.wikipedia.org/wiki/Tiled_web_map)
    et un service WMS exposant les GéoTiffs,
    regroupés par couche en fonction de leur échelle,
  - un service GeoJSON exposant les silhouettes des GéoTiffs,
  - un service de téléchargement des GéoTiffs dans différents formats avec des infos associées,
  - un mécanisme de téléchargement et d'installation des cartes Shom à partir d'un serveur dit maitre.

Il propose aussi de visualiser les données au moyen de cartes Leaflet utilisant les services décits ci-dessus.

Ce projet est décomposé en 5 modules:

  - les [web-services de consultation et de téléchargement des 
    GéoTiffs](https://github.com/benoitdavidfr/shomgt/tree/master/ws)
  - la [gestion d'un catalogue des cartes du Shom](https://github.com/benoitdavidfr/shomgt/tree/master/cat2) afin
    - d'identifier les cartes d'intérêt pour ShomGt, c'est à dire celles des espaces sur lesquels la France exerce ses droits,
      notamment sa [ZEE](https://github.com/benoitdavidfr/shomgt/tree/master/cat2/france.geojson),
    - d'identifier la liste des cartes à actualiser, à supprimer ou à ajouter,
    - de fournir au module précédent les caractéristiques des cartes,
  - les [scripts de mise à jour des GéoTiffs soit automatiquement à partir du serveur dit maitre soit à partir d'une livraison du
    Shom](https://github.com/benoitdavidfr/shomgt/tree/master/updt),
  - un fil Atom de publication des cartes disponibles pour utilisation par un serveur esclave (master),
  - un [ensemble d'éléments partagés entre modules (lib)](https://github.com/benoitdavidfr/shomgt/tree/master/lib),
    notamment un package de gestion de la géométrie.

De plus:

  - le répertoire [`docs`](https://github.com/benoitdavidfr/shomgt/tree/master/docs) contient quelques documents,
  - le répertoire `leaflet` contient des fichiers utilisés par la carte Leaflet permettant une visualisation de cette carte
    sans accès à internet,
  - le répertoire `docker` contient la configuration Docker d'un serveur d'hébergement des web-services.
  - le répertoire `tilecache` contient un cache des tuiles de la couche gtpyr.

Outre le répertoire correspondant au code contenu dans ce projet Git (noté {shomgt}),
les répertoires suivants doivent être présents sur le serveur pour que le code s'exécute correctement:

  - `{shomgt}/../../shomgeotiff` - stockage des cartes Shom dans 2 sous-répertoires,
    - `current` contient un répertoire par carte exposé construit par le module de mise à jour,
    - `ìncoming` contient un répertoire par livraison, chacun contenant les archives fournies par le Shom,
  - `{shomgt}/vendor` - code installé par [composer](https://getcomposer.org/).

Le code utilise :

  - Php 8.0
  - [le composant Yaml de Symfony](https://symfony.com/doc/current/components/yaml.html) qui peut être installé par
    `composer require symfony/yaml`
  - les logiciels [gdal_translate et gdalinfo](https://www.gdal.org/) utilisés pour convertir les GéoTiff en PNG
  - le logiciel 7z utilisé pour dézipper les archives
  
Des spécification plus précises des versions des logiciels sont disponibles
dans le [fichier de configuration Docker](https://github.com/benoitdavidfr/shomgt/blob/master/docker/Dockerfile).

Une [documentation fonctionnelle plus détaillée est disponible
ici](https://github.com/benoitdavidfr/shomgt/tree/master/docs/docfonctionnelle.md).

Une [documentation technique détaillée pour installer un serveur *shomgt* est disponible
ici](https://github.com/benoitdavidfr/shomgt/tree/master/docs/install.md).

Une documentation technique complémentaire est disponible dans chacun des modules.
