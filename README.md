# ShomGT3 - services de consultation des cartes raster GéoTIFF du Shom - en cours de développement
Ce projet correspond à une nouvelle version de ShomGT dont l'objectif est d'exposer sous la forme de web-services le contenu des
[cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant les zones sous juridiction française pour permettre au [MTE](http://www.ecologie.gouv.fr)
et au [MCTRCT](http://www.cohesion-territoires.gouv.fr/) d'assurer leurs missions de service public.

La principale plus-value de ShomGT est de permettre de consulter le contenu des cartes en supprimant leur cadre
afin de passer d'une carte à l'autre sans couture et d'intégrer ces données dans les outils SIG habituels,
comme [Leaflet](https://leafletjs.com/) ou [QGis](https://www.qgis.org/).

Par rapport à la version précédente, cette version améliore la possibilité de construire un serveur local,
de l'approvisionner avec les cartes du Shom, puis de mettre à jour ces cartes, de manière simple en utilisant docker-compose.

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


