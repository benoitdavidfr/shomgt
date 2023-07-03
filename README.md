# ShomGT3 - services de consultation des cartes raster GéoTIFF du Shom
L'objectif de ShomGT est d'exposer sous la forme de web-services
le contenu des [cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant les zones sous juridiction française, pour permettre aux services du pôle ministériel
du [MTECT/MTE](http://www.ecologie.gouv.fr) et du [secrétariat d'Etat à la mer](https://mer.gouv.fr/)
d'assurer leurs missions de service public.

La principale plus-value de ShomGT est de permettre de consulter le contenu des cartes en supprimant leur cadre
afin de passer d'une carte à l'autre sans couture et d'intégrer ces données dans les outils SIG habituels,
comme [Leaflet](https://leafletjs.com/) ou [QGis](https://www.qgis.org/).

Ce dépôt correspond la version 3 de ShomGT qui, par rapport à la version précédente, simplifie la mise en place
d'un serveur local, son approvisionnement avec les cartes du Shom, puis la mise à jour de ces cartes ;
cette simplification s'appuie sur l'utilisation de conteneurs Docker et de docker-compose.

Pour utiliser ces web-services, des cartes Shom doivent être intégrées au serveur, ce qui nécessite que les utilisateurs disposent des droits d'utilisation de ces cartes. C'est le cas notamment des services et des EPA de l'Etat conformément à l'[article 1 de la loi Pour une République numérique](https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte).
Pour les autres acteurs, consulter le Shom (bureau.prestations@shom.fr).

## 1. Décomposition en modules
ShomGT3 se décompose dans les 6 modules suivants:

  - **[shomgt](shomgt)** expose différents services de consultation des cartes:
    - un service de tuiles au [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map), 
    - un autre conforme au [protocole WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
    - un service GeoJSON exposant les silhouettes des GéoTiffs et d'autres couches vecteur,
    - une carte Leaflet de visualisation des tuiles et des silhouettes des GéoTiffs et permettant de télécharger les cartes.
    
  - **[sgupdt](sgupdt)** construit et met à jour les fichiers nécessaires à *shomgt*
    et les stocke dans un [répertoire data décrit ici](data) ;
    pour cela il interroge *sgserver*.
    
    *shomgt* et *sgupdt* peuvent être déployés comme conteneurs Docker, dans ce cas le répertoire data constitue
    un volume partagé entre ces 2 conteneurs.
    
  - **[sgserver](sgserver)** expose à *sgupdt* les cartes du Shom au travers d'une API http.
    Il est mis à jour régulièrement grâce à *dashboard*.
  
  - **[dashboard](dashboard)** expose un tableau de bord permettant d'identifier:
    - les cartes les plus périmées à remplacer
    - les cartes obsolètes à retirer
    - les nouvelles cartes à prendre en compte
    
    *dashboard* confronte les versions des cartes du portefeuille aux informations d'actualité des cartes
    issues du [GAN du Shom](#gan).
    Il exploite aussi, pour détecter de nouvelles cartes, la liste des cartes diffusée par le Shom dans son serveur WFS.
    
  - **[mapcat](mapcat)** est un catalogue des cartes Shom couvrant les zones sous juridiction française.
    Il décrit des informations intemporelles sur les cartes comme le titre de la carte, sa couverture spatiale,
    la liste de ses cartouches, ....
    Il est utilisé par *sgupdt* et consultable au travers de *sgserver*.
  
  - **[shomft](shomft)** gère différents jeux de données GeoJSON, notamment certains issus du serveur WFS du Shom.
    Il gère aussi une version simplifiée des zones sous juridiction française afin d'identifier les cartes
    d'intérêt pour ShomGT dans *dashboard*.

Chacun de ces modules correspond à un répertoire ;
en plus de ces 6 modules, une [bibiothèque commune contient un certain nombre de scripts documentés ici](lib).
## 2. Termes et concepts utilisés dans ce projet
Dans ce projet sont utilisés différents termes et concepts définis ci-dessous:

- **portefeuille de cartes**: l'ensemble des cartes gérées par ShomGT, chacune dans une certaine version,
- **carte d'intérêt (pour ShomGT)**: carte ayant vocation à être dans le portefeuille.  
  Il s'agit:
    - des cartes intersectant la ZEE française
      - sauf quelques cartes ayant un intérêt insuffisant et listées explicitement dans le catalogue MapCat
    - plus quelques cartes à petite échelle (<1/6M) facilitant la navigation autour de la Terre
- **ZEE**: [Zone Economique Exclusive](https://fr.wikipedia.org/wiki/Zone_%C3%A9conomique_exclusive)
- **carte Shom** : c'est l'unité de livraison du Shom, qui correspond à une carte papier numérisée ;
  chaque carte est identifiée par un numéro sur 4 chiffres
  qui est parfois précédé des lettres FR pour indiquer qu'il s'agit d'un numéro français.
  La livraison par le Shom d'une carte correspond à une version particulière et prend la forme d'une archive 7z.
- **carte spéciale** : dans ShomGT, on distingue :
  - les cartes normales, c'est à dire les [Cartes marines numériques raster
    (images)](https://diffusion.shom.fr/searchproduct/product/configure/id/208)
    dont le [format de livraison
    est bien défini](https://services.data.shom.fr/static/specifications/Descriptif_Contenu_geotiff.pdf) ;
  - les [cartes spéciales](https://diffusion.shom.fr/cartes/cartes-speciales-aem.html),
    principalement les cartes d'action de l'Etat en mer (AEM), dont le format de livraison n'est pas fixé 
    et varie d'une carte à l'autre.
- **version** : une carte est livrée dans une certaine version qui s'exprime en 2 parties:
  - l'année d'édition ou de publication de la carte,
  - le numéro de correction sur l'édition.
    Historiquement, lorsqu'une correction était publiée, les détenteurs de la carte concernée devait la reporter sur la carte.  
  
  Dans ShomGT3, la version est définie sous la forme {année}c{correction}, où {année} est l'année d'édition ou de publication
  de la carte et {correction} est le numéro de correction sur cette édition.
  Cette notation n'est pas utilisée par le Shom qui utilise plutôt le numéro de la semaine de publication de la correction.
- **carte obsolète** : carte que le Shom a retiré de son catalogue et qui doit donc être retirée du portefeuille ShomGT,
- **carte périmée** : carte dont la version dans le portefeuille est remplacée par une version plus récente ;
  une carte peut être plus ou moins périmée ; cette péremption peut être mesurée par la différence
  du nombre de corrections apportées.
- **<a name='gan'>GAN</a>**: le GAN (Groupe d'Avis aux Navigateurs) est le dispositif du Shom de diffusion
  des actualisations de ses documents, notamment de ses cartes.
  Les actualisations sont publiées chaque semaine et datée par un nombre correspondant sur les 2 premiers chiffres à l'année,
  et sur 2 autres chiffres à la semaine dans l'année.
  Le GAN prend la forme du site https://gan.shom.fr/ qui est un site HTML 
  et les informations d'actualisation ne sont pas disponibles de manière structurée au travers d'une API.
  Dans ShomGT3 ce site est scrappé pour retrouver la version courante d'une carte et la comparer 
  avec celle de la carte du portefeuille.  
  Seules les cartes normales sont mentionnées dans le GAN, à l'exception de la carte 0101 qui est le planisphère terrestre.
  Les cartes spéciales ne sont pas mentionnées dans le GAN.
- **GéoTiff** : la numérisation d'une carte produit des images géoréférencées
  correspondant aux différentes zones géographiques de la carte, souvent une zone principale et des cartouches,
  chaque zone corespond dans la livraison à une image géoréférencée
  au [format GeoTIFF](https://fr.wikipedia.org/wiki/GeoTIFF) ; l'image est ainsi appelée **GéoTiff**.
  Par extension cette image est toujours appelée GéoTiff lorsqu'elle est transformée dans un autre format.
  Le GéoTiff est identifié dans la carte par le nom du fichier tiff sans l'extension .tif.
  Dans certains cas un GéoTiff peut ne pas être géoréférencé ; principalement dans 2 cas :
  - lorsqu'une carte ne comporte pas de zone principale alors le GéoTiff de la carte globale n'est pas géoréférencé,
  - plusieurs cartes spéciales ne sont pas géoréférencées.
- **système de coordonnées**: Tous les fichiers GéoTIFF sont fournis par le Shom
  en [projection Mercator](https://fr.wikipedia.org/wiki/Projection_de_Mercator) dans le système géodésique WGS84,
  ce système de coordonnées est aussi appelé **World Mercator**.  
  Les coordonnées, par exemple dans le GAN, ne sont pas fournies en World Mercator mais en coordonnées géographiques,
  en dégrés et minutes décimales en WGS84 ; par exemple "41°28,00'N - 010°30,00'W".  
  De son côté, pour permettre de superposer de multiples couches,
  le [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map) utilise
  le [système de coordonnées Web Mercator](https://en.wikipedia.org/wiki/Web_Mercator_projection)
  popularisé par Google et son produit Google Maps.  
  Il est donc souvent nécessaire de changer une position d'un système de coordonnées à un autre.  
- **niveau de zoom**: le [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map) définit ce concept
  de niveau de zoom où:
  - le niveau de zoom 0 correspond à un affichage de la Terre en projection Web Mercator sur une tuile 256 x 256,
  - puis le niveau de zoom n correspond à une décomposition en 4 de chaque tuile du niveau de zoom n-1.
  
  ShomGT3 utilise 18 niveaux de zoom, correspondant potentiellement à plus de 91 milliards de tuiles.
- **couches de données**: dans ShomGT3 les GéoTiffs des cartes normales sont répartis dans des couches image d'échelle homogène.
  Les dénominateurs des échelles retenus pour ces couches sont les suivants,
  avec entre parenthèses le ou les niveaux de zoom XYZ correspondants:
  40M (0-5), 10M (6), 4M (7), 2M (8), 1M (9),  500k (10), 250k (11), 100k (12), 50k (13), 25k (14), 12k (15), 5k (16-18).  
  Chacune de ses couches est identifiée par la chaine **gt** suivie du dénominateur de l'échelle.  
  De plus:
    - la couche **gtpyr** sélectionne la couche la plus appropriée parmi les 12 couches ci-dessus
      en fonction du niveau de zoom défini par l'appel,
    - la couche **gtaem** contient les 7 cartes Action de l'Etat en Mer (AEM)
    - la couche **gtMancheGrid** contient la carte MancheGrid,
    - la couche **gtZonMar** contient la carte de délimitation des zones maritimes.
  
  Enfin, à chacune des 16 couches définies ci-dessus est associée une couche de leur numéro,
  permettant de repérer une carte par son numéro.
  
  Par ailleurs, ShomGT3 met à disposition les couches vecteur suivantes :
    - la [ZEE](https://fr.wikipedia.org/wiki/Zone_%C3%A9conomique_exclusive) française simplifiée,
    - les [délimitations maritimes définies
      par le Shom](https://www.shom.fr/fr/nos-activites-diffusion/cellule-delimitations-maritimes),
    - les [zones SAR-SRR](https://diffusion.shom.fr/donnees/limites-maritimes/zones-sar.html).

## 3. Configuration
### 3.1 Déploiement décentralisé
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

Ce déploiement décentralisé nécessite aussi un déploiement central pour agir comme serveur de cartes.
### 3.2 Déploiement centralisé
Le code de ShomGT3 peut aussi être utilisé de manière plus conventionnelle en hébergeant les différents modules
sur un même site.

Si le site est exposé sur internet, il est nécessaire de gérer le [contrôle d'accès](docs/accesscontrol.md).

### 3.3 Divers
ShomGT3 utilise différents [composants externes décrits ici](docs/composantexterne.md).

Le [système de log est documenté ici](docs/log.md).

ShomGT3 utilise Php 8 et a été validé avec Php 8.2.
