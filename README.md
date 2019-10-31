## ShomGT - services de consultation des cartes raster GéoTIFF du Shom

L'objectif de ce projet est d'exposer sous la forme de web-services les
[cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant la métropole et l'outre-mer français pour permettre au [MTES](http://www.ecologique-solidaire.gouv.fr)
et au [MCTRCT](http://www.cohesion-territoires.gouv.fr/)
d'assurer leurs missions de service public.

Pour utiliser ces web-services, des cartes Shom doivent être au préalable commandées au Shom
puis intégrées au moyen des scripts de mise à jour.
La fourniture de ces cartes est gratuite pour les services et les EPA de l'Etat conformément à
[l'article 1 de la loi Pour une République numérique](https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte).
Commandes à faire par mail à diffusion-support@shom.fr.

Ce projet propose les services suivants :

  - un service de tuiles au [format XYZ](https://en.wikipedia.org/wiki/Tiled_web_map) exposant les GéoTiffs
    regroupés par couche en fonction de leur échelle,
  - un service GeoJSON exposant les silhouettes des GéoTiffs,
  - un service de téléchargement des GéoTiffs dans différents formats avec des infos associées,
  - un service GeoJSON exposant le catalogue des cartes du Shom
    [publié ici par tranches d'échelles](https://benoitdavidfr.github.io/shomgt/).
  
Ce projet est découpé en 4 modules:

  - les [web-services de consultation et de téléchargement des 
    GéoTiffs](https://github.com/benoitdavidfr/shomgt/tree/master/ws)
  - les [scripts de mise à jour des GéoTiffs à partir d'une livraison du
    Shom](https://github.com/benoitdavidfr/shomgt/tree/master/updt)
  - la [gestion d'un catalogue des cartes du Shom](https://github.com/benoitdavidfr/shomgt/tree/master/cat) afin
    - d'identifier les cartes des espaces sur lesquels la France exerce ses droits,
      notamment sa [ZEE](https://github.com/benoitdavidfr/shomgt/tree/master/cat/france.geojson),
    - d'identifier la liste des cartes à actualiser, à supprimer ou à ajouter,
    - de fournir au module précédent les caractéristiques des cartes,
  - un [package de gestion de la géométrie partagé entre les
    modules](https://github.com/benoitdavidfr/shomgt/tree/master/lib).

De plus:

  - le répertoire [docs](https://github.com/benoitdavidfr/shomgt/tree/master/docs) contient
    l'export GeoJSON du catalogue des cartes du Shom,
    qui peut ainsi être consulté sous la forme
    d'[une carte directement sur Github](https://benoitdavidfr.github.io/shomgt/catmap.html),
  - le répertoire docker contient la configuration Docker d'un serveur d'hébergement des web-services.

Outre le répertoire correspondant au code contenu dans ce projet Git (noté {shomgt}),
les répertoires suivants doivent être présents sur le serveur pour que le code s'exécute correctement:

  - {shomgt}/../../shomgeotiff - stockage des fichiers TIFF et PNG des cartes géré par le modfule de mise à jour,
  - {shomgt}/vendor - code installé par composer

Le code utilise :

  - Php 7.2
  - [le composant Yaml de Symfony](https://symfony.com/doc/current/components/yaml.html) qui peut être installé par
    `composer require symfony/yaml`
  - les logiciels [gdal_translate et gdalinfo](https://www.gdal.org/) utilisés pour convertir les GéoTiff en PNG.
  
Des indications plus précises sur les versions des logiciels sont disponibles
dans le [fichier de configuration Docker](https://github.com/benoitdavidfr/shomgt/blob/master/docker/Dockerfile).

Une [documentation fonctionnelle est disponible ici](https://github.com/benoitdavidfr/shomgt/tree/master/docs/doc.md).

Une [documentation complémentaire est disponible
dans le fichier phpdoc.yaml](https://github.com/benoitdavidfr/shomgt/blob/master/phpdoc.yaml).
