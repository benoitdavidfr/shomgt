# ShomGT3 - services de consultation des cartes raster GéoTIFF du Shom - en cours de développement
Ce projet correspond à une nouvelle version de ShomGT dont l'objectif est d'exposer sous la forme de web-services le contenu des
[cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant les zones sous juridiction française pour permettre aux services du [MTECT](http://www.ecologie.gouv.fr),
du [MTE](http://www.ecologie.gouv.fr/) et du secrétariat d'Etat à la mer d'assurer leurs missions de service public.

La principale plus-value de ShomGT est de permettre de consulter le contenu des cartes en supprimant leur cadre
afin de passer d'une carte à l'autre sans couture et d'intégrer ces données dans les outils SIG habituels,
comme [Leaflet](https://leafletjs.com/) ou [QGis](https://www.qgis.org/).

Par rapport à la version précédente, cette version améliore la possibilité de construire un serveur local,
de l'approvisionner avec les cartes du Shom, puis de mettre à jour ces cartes, de manière simple en utilisant docker-compose.

Pour utiliser ces web-services, des cartes Shom doivent être intégrées au serveur, ce qui nécessite que les utilisateurs disposent des droits d'utilisation de ces cartes. C'est le cas notamment des services et des EPA de l'Etat conformément à l'[article 1 de la loi Pour une République numérique](https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte).
Pour les autres acteurs, consulter le Shom (bureau.prestations@shom.fr).

Ce projet ce décompose en 4 sous-projets:

  - **shomgt** expose les services suivants de consultation des cartes:
    - un service de tuiles au [format XYZ](https://en.wikipedia.org/wiki/Tiled_web_map), 
    - un autre conforme au protocole [WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
    - un service GeoJSON exposant les silhouettes des GéoTiffs,
    - une carte Leaflet de visualisation des tuiles et des silhouettes.
    
    *shomgt* peut être déployé comme conteneur Docker.
    
  - **sgupdt** construit et met à jour les fichiers nécessaires à *shomgt* en interrogeant *sgserver*. 
    Il peut aussi être déployé comme conteneur Docker.
    
  - **sgserver** expose les données du Shom à *sgupdt*. Il est mis à jour régulièrement grâce à *mapcat*.
  
  - **mapcat** a pour objectif d'identifier les cartes nécessitant une mise à jour ;
    pour cela il gère un catalogue des cartes.

## Déploiement Docker
Avec cette version , les conteneurs *shomgt* et *sgupdt* peuvent être déployés facilement sur un serveur local
ou un poste local disposant des logiciels docker et docker-compose.
Des images sont proposées sur https://hub.docker.com/r/benoitdavid/shomgt3.
Pour effectuer un déploiement utiliser le fichier [docker-compose.yml](docker-compose.yml).

A partir d'un serveur sur le réseau de l'Etat ou d'un EPA,
le conteneur sgupdt se connecte au serveur sgserver pour télécharger les cartes du Shom.
En dehors de ce réseau, l'accès au serveur nécessite une authentification et la variable d'environnement
`SHOMGT3_SERVER_URL` doit être définie avec l'URL `http://{login}:{passwd}@php81/geoapi/shomgt3/sgserver/index.php`
en remplacant `{login}` et `{passwd}` respectivement par le login et le mot de passe sur le serveur.



