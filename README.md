# ShomGT3 - services de consultation des cartes raster GéoTIFF du Shom
Ce projet correspond à une nouvelle version de ShomGT, dont l'objectif est d'exposer sous la forme de web-services
le contenu des [cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant les zones sous juridiction française, pour permettre aux services du pôle ministériel
du [MTECT/MTE](http://www.ecologie.gouv.fr) et du [secrétariat d'Etat à la mer](https://mer.gouv.fr/)
d'assurer leurs missions de service public.

La principale plus-value de ShomGT est de permettre de consulter le contenu des cartes en supprimant leur cadre
afin de passer d'une carte à l'autre sans couture et d'intégrer ces données dans les outils SIG habituels,
comme [Leaflet](https://leafletjs.com/) ou [QGis](https://www.qgis.org/).

Par rapport à la version précédente, cette version simplifie la mise en place d'un serveur local, son approvisionnement avec
les cartes du Shom, puis la mise à jour de ces cartes ;
cette simplification s'appuie sur l'utilisation de conteneurs Docker et de docker-compose.

Pour utiliser ces web-services, des cartes Shom doivent être intégrées au serveur, ce qui nécessite que les utilisateurs disposent des droits d'utilisation de ces cartes. C'est le cas notamment des services et des EPA de l'Etat conformément à l'[article 1 de la loi Pour une République numérique](https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte).
Pour les autres acteurs, consulter le Shom (bureau.prestations@shom.fr).

## Décomposition en modules
ShomGT ce décompose dans 6 modules suivants:

  - **[shomgt](shomgt)** expose différents services de consultation des cartes:
    - un service de tuiles au [format XYZ](https://en.wikipedia.org/wiki/Tiled_web_map), 
    - un autre conforme au protocole [WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
    - un service GeoJSON exposant les silhouettes des GéoTiffs ainsi que certaines de leurs caractéristiques,
    - une carte Leaflet de visualisation des tuiles et des silhouettes des GéoTiffs et permettant de télécharger les cartes.
    
  - **[sgupdt](sgupdt)** construit et met à jour les fichiers nécessaires à *shomgt*, stockés dans un répertoire
    data [décrit ici](data), en interrogeant *sgserver*. 
    
    *shomgt* et *sgupdt* peuvent être déployés comme conteneurs Docker, dans ce cas le répertoire data constitue
    un volume partagé entre les 2 conteneurs.
    
  - **[sgserver](sgserver2)** expose  à *sgupdt* les cartes du Shom gérées dans un répertoire
    appelé [shomgeotiff décrit ici](docs/shomgeotiff.md). Il est mis à jour régulièrement grâce à *dashboard*.
  
  - **[dashboard](dashboard)** expose un tableau de bord permettant d'identifier:
    - les cartes les plus périmées à remplacer
    - les cartes obsolètes à marquer comme telle
    - les nouvelles cartes à prendre en compte
    
    *dashboard* confronte les versions des cartes de *shomgeotiff* aux informations d'actualité des cartes
    issues du [GAN du Shom](docs/gan.md). Il exploite aussi la liste des cartes du Shom diffusé par le Shom
    dans son serveur WFS.
    
  - **[mapcat](mapcat)** est un catalogue des cartes Shom couvrant les zones sous juridiction française.
    Il décrit des informations intemporelles sur les cartes.
    Il est utilisé par *sgupdt* et consultable au travers de *sgserver*.
  
  - **[shomft](shomft)** gère différents jeux de données GeoJSON, notamment certains issus du serveur WFS du Shom.
    Il gère aussi une version simplifiée des zones sous juridiction française afin d'identifier les cartes
    d'intérêt pour ShomGT dans *dashboard*.

## Déploiement Docker
Avec cette version, les conteneurs *shomgt* et *sgupdt* peuvent être déployés facilement sur un serveur local
ou un poste local disposant des logiciels docker et docker-compose.
Des images sont proposées pour cela sur https://hub.docker.com/r/benoitdavid/shomgt3.
Pour effectuer un déploiement utiliser le fichier [docker-compose.yml](docker-compose.yml)
en adaptant les variables d'environnement selon le besoin.

**Attention, à ne déployer ces conteneurs que sur un serveur auquel il n'est pas possible d'accéder depuis Internet !**

A partir d'un serveur sur le réseau de l'Etat ou d'un EPA,
le conteneur sgupdt se connecte au serveur sgserver pour télécharger les cartes du Shom.  
Si un proxy doit être utilisé, il doit être défini en s'inspirant des exemples
du fichier [docker-compose.yml](docker-compose.yml).  
En dehors du réseau de l'Etat ou des EPA, l'accès au serveur nécessite une authentification qui peut être défini grâce
à la variable d'environnement `SHOMGT3_SERVER_URL` doit contenir l'URL `http://{login}:{passwd}@sgserver.geoapi.fr/index.php`
en remplacant `{login}` et `{passwd}` respectivement par le login et le mot de passe sur le serveur.  
Par ailleurs, la mise à jour régulière des cartes peut être activée en définissant la variable d'environnement
`SHOMGT3_UPDATE_DURATION` qui doit contenir le nombre de jours entre 2 actualisations.
Les cartes n'étant pas actualisées très fréquemment, cette durée peut être fixée par exemple à 28 jours.

## Utilisation sans Docker
Le code de ShomGT3 peut aussi être utilisé de manière plus conventionnelle en hébergeant les différents modules
sur le même site.
