# ShomGT - services de consultation des cartes raster GéoTIFF du Shom
L'objectif de ShomGT est d'exposer sous la forme de web-services
le contenu des [cartes GéoTIFF du Shom](https://diffusion.shom.fr/loisirs/cartes-marines-geotiff.html)
couvrant les zones sous juridiction française, pour permettre aux services du pôle ministériel
du [MTECT/MTE](http://www.ecologie.gouv.fr) et du [secrétariat d'Etat à la mer](https://mer.gouv.fr/)
d'assurer leurs missions de service public.

La principale plus-value de ShomGT est de permettre de consulter le contenu des cartes en supprimant leur cadre
afin de passer d'une carte à l'autre sans couture et d'intégrer ces données dans les outils SIG habituels,
comme [Leaflet](https://leafletjs.com/) ou [QGis](https://www.qgis.org/).

Ce dépôt correspond la version 4 de ShomGT.
Cette version, en cours de développement, propose des outils en mode web pour gérer le portefeuille de cartes 
(ajout/suppression d'une carte ou d'une version d'une carte).
Son objectif est de permettre à différentes personnes d'effectuer les mises à jour du portefeuille de cartes.

La version 3 avait pour objectif de simplifier la mise en place d'un serveur local, son approvisionnement
avec les cartes du Shom, puis la mise à jour de ces cartes ;
cette simplification s'appuie sur l'utilisation de conteneurs Docker et de docker-compose.

Pour utiliser ces web-services, des cartes Shom doivent être intégrées au serveur, ce qui nécessite que les utilisateurs disposent des droits d'utilisation de ces cartes. C'est le cas notamment des services et des EPA de l'Etat conformément à l'[article 1 de la loi Pour une République numérique](https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte).
Pour les autres acteurs, consulter le Shom (bureau.prestations@shom.fr).

## 1. Décomposition en modules
ShomGT4 se décompose dans les 7 modules suivants:

  - **[view](view)** expose les services suivants de consultation des cartes:
    - une API tuiles au [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map) très utilisé, 
    - un service conforme au [protocole WMS](https://www.ogc.org/standards/wms), utilisé par de nombreux SIG,
    - un service GeoJSON exposant les silhouettes des GéoTiffs et d'autres couches vecteur,
    - une carte Leaflet de visualisation des tuiles et des silhouettes des GéoTiffs et permettant de télécharger les cartes.
    
  - **[sgupdt](sgupdt)** construit et met à jour les fichiers nécessaires à *shomgt*,
    en interrogeant *sgserver*,
    et les stocke dans un [répertoire data décrit ici](data).  
    
    *view* et *sgupdt* peuvent être déployés comme conteneurs Docker,
    dans ce cas le répertoire data constitue un volume partagé entre ces 2 conteneurs.
    
  - **[sgserver](sgserver)** expose à *sgupdt* les cartes du Shom au travers d'une API http.
    Il est mis à jour régulièrement au travers du BO en fonction des infos fournies par le *dashboard*.
  
  - **[dashboard](dashboard)** expose un tableau de bord permettant d'identifier:
    - les cartes les plus périmées à remplacer
    - les cartes obsolètes à retirer
    - les nouvelles cartes à prendre en compte
    
    *dashboard* confronte les versions des cartes du portefeuille aux informations d'actualité des cartes
    issues du [GAN du Shom](#gan).
    Il exploite aussi, pour détecter de nouvelles cartes, la liste des cartes diffusée par le Shom dans son serveur WFS.
    
  - **[BO](bo)** est le module de gestion du portefeuille de cartes exposé par sgserver.
    Il permet notamment d'ajouter de nouvelles versions des cartes.
  
  - **[mapcat](mapcat)** est un catalogue des cartes Shom couvrant les zones sous juridiction française.
    Il décrit des informations intemporelles sur les cartes comme le titre de la carte, sa couverture spatiale,
    la liste de ses cartouches, ....
    Il est utilisé par *sgupdt* et consultable au travers de *sgserver*.
  
  - **[shomft](shomft)** expose différents jeux de données GeoJSON, notamment certains issus du serveur WFS du Shom.
    Il comprend aussi une version simplifiée des zones sous juridiction française afin d'identifier les cartes
    d'intérêt pour ShomGT dans *dashboard*.

Chacun de ces modules correspond à un répertoire ;
en plus de ces 7 modules, une [bibiothèque commune contient un certain nombre de scripts documentés ici](lib).

## 2. Termes et concepts utilisés dans ShomGT
Dans ShomGT sont utilisés différents termes et concepts définis ci-dessous:

- **portefeuille de cartes**: l'ensemble des cartes exposées dans *sgserver*, chacune dans une certaine version,
- **carte d'intérêt (pour ShomGT)**: carte ayant vocation à être dans le portefeuille.  
  Il s'agit:
    - des cartes intersectant la ZEE française,
      - sauf quelques cartes ayant un intérêt insuffisant et listées explicitement dans le catalogue MapCat,
    - plus quelques cartes à petite échelle (<1/6M) facilitant la navigation autour de la Terre,
    - plus quelques cartes à proximité de la ZEE française et jugée utiles.
- **ZEE**: [Zone Economique Exclusive](https://fr.wikipedia.org/wiki/Zone_%C3%A9conomique_exclusive),
  intégrant parfois les extensions du [plateau continental](https://fr.wikipedia.org/wiki/Plateau_continental_(droit)).
- **carte Shom** : c'est l'unité de livraison du Shom, qui correspond à une carte papier numérisée ;
  chaque carte est identifiée par un numéro sur 4 chiffres
  qui est parfois précédé des lettres FR pour indiquer qu'il s'agit d'un numéro français.
  La livraison par le Shom d'une carte correspond à une version particulière et prend la forme numérique d'une archive 7z.
- **carte spéciale** : dans ShomGT, on distingue :
  - les cartes normales, c'est à dire les [Cartes marines numériques raster
    (images)](https://diffusion.shom.fr/searchproduct/product/configure/id/208)
    dont le [format de livraison
    est bien défini](https://services.data.shom.fr/static/specifications/Descriptif_Contenu_geotiff.pdf) ;
  - les [cartes spéciales](https://diffusion.shom.fr/cartes/cartes-speciales-aem.html) dont le format de livraison
    n'est pas fixé et varie d'une carte à l'autre. ShomGT expose les 9 cartes spéciales suivantes:
    - les 7 cartes d'action de l'Etat en mer (AEM),
    - la carte des délimitations des zones maritimes et la carte Manche GRID.
- **Fac similé** : carte normale reproduction d'une carte étrangère, son format de livraison peut être légèrement différent
  de celui des cartes normales.
- **version** : une carte est livrée dans une certaine version qui est exprimée en 2 parties:
  - l'année d'édition ou de publication de la carte,
  - le numéro de la correction sur l'édition.
    Historiquement, lorsqu'une correction était publiée, les détenteurs de la carte concernée devait la reporter sur la carte.  
  
  Dans ShomGT, la version est identifiée par un libellé de la forme {année}c{#correction}, où {année} est l'année d'édition
  ou de publication de la carte et {#correction} est le numéro de correction sur cette édition.
  Cette notation n'est pas utilisée par le Shom qui utilise plutôt le numéro de la semaine de publication de la correction.  
  Certaines cartes spéciales ont pour identifiant de version uniquement l'année de publication.
- **carte obsolète** : carte retirée par le Shom de son catalogue, et qui est donc retirée du portefeuille ShomGT,
- **carte périmée** : carte pour laquelle le Shom distribue une versions plus récente que celle du portefeuille ;
  une carte du portefeuille peut être plus ou moins périmée et cette péremption peut être mesurée
  par la différence du nombre de corrections apportées.
- **<a name='gan'>GAN</a>**: le GAN (Groupe d'Avis aux Navigateurs) est le dispositif du Shom de diffusion
  des actualisations de ses documents, notamment de ses cartes.
  Les actualisations sont publiées chaque semaine (le jeudi) et datée par un libellé de 4 chiffres
  dont les 2 premiers correspondent aux 2 derniers chiffres de l'année, et les 2 derniers chiffres à la semaine dans l'année
  conformément à [la définition ISO](https://fr.wikipedia.org/wiki/Num%C3%A9rotation_ISO_des_semaines).
  Le GAN prend la forme du site https://gan.shom.fr/ qui est un site HTML 
  et les informations d'actualisation ne sont pas disponibles de manière structurée au travers d'une API.
  Dans ShomGT ce site est scrappé pour retrouver la version courante d'une carte et la comparer 
  avec celle dans le portefeuille.  
  Seules les cartes normales sont mentionnées dans le GAN, à l'exception de la carte 0101 qui est le planisphère terrestre.
  Les cartes spéciales ne sont pas mentionnées dans le GAN.
- **Image** : la numérisation d'une carte produit des images géoréférencées
  correspondant aux différentes zones géographiques de la carte, souvent une zone principale et des cartouches,
  chaque zone corespond dans la livraison à une image géoréférencée
  au [format GeoTIFF](https://fr.wikipedia.org/wiki/GeoTIFF).
  Dans les 2 cas suivants une Image n'est pas géoréférencée:
  - certaines cartes ne comportent pas de zone principale mais sont uniquement composées de cartouches ;
    dans ce cas l'image de la carte globale livrée par le Shom n'est pas géoréférencée,
  - plusieurs cartes spéciales ne sont pas géoréférencées et sont même parfois livrées uniquement sous la forme d'un fichier PDF.
  De plus quelques géoréférencements d'images sont erronés et ne peuvent pas être interprétés par certains logiciels.
- **système de coordonnées**: Tous les fichiers GéoTIFF utilisés dans ShomGT sont fournis par le Shom
  en [projection Mercator](https://fr.wikipedia.org/wiki/Projection_de_Mercator)
  dans le [système géodésique WGS84](https://fr.wikipedia.org/wiki/WGS_84),
  ce système de coordonnées est appelé **World Mercator**.  
  Les coordonnées, par exemple dans le GAN, ne sont pas fournies en World Mercator mais en coordonnées géographiques,
  en dégrés et minutes décimales en WGS84 ;
  par exemple "41°28,00'N - 010°30,00'W" signifie latitude 41° et 28,00 minutes Nord, et longitude 10° et 30,00 minutes Ouest.  
  De son côté, pour permettre de superposer de multiples couches,
  le [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map) utilise
  le [système de coordonnées Web Mercator](https://en.wikipedia.org/wiki/Web_Mercator_projection)
  (différent de World Mercator) popularisé par Google et son produit Google Maps.  
  Il est donc souvent nécessaire de changer une position d'un système de coordonnées à un autre.  
- **niveau de zoom**: le [standard defacto XYZ](https://en.wikipedia.org/wiki/Tiled_web_map) définit ce concept
  de niveau de zoom par:
  - le niveau de zoom 0 correspond à un affichage de la Terre entière en projection Web Mercator sur une tuile 256 x 256,
  - puis le niveau de zoom n correspond à une décomposition en 4 de chaque tuile du niveau de zoom n-1.
  
  ShomGT utilise 19 niveaux de zoom (de 0 à 18), correspondant potentiellement à plus de 366 milliards de tuiles.
- **couches de données**: dans ShomGT les images des cartes normales sont réparties dans 12 couches image d'échelle homogène.
  dont les dénominateurs sont les suivants,
  avec entre parenthèses le ou les niveaux de zoom XYZ correspondants:
  40M (0-5), 10M (6), 4M (7), 2M (8), 1M (9), 500k (10), 250k (11), 100k (12), 50k (13), 25k (14), 12k (15), 5k (16-18).  
  Chacune de ses couches est identifiée par la chaine **gt** suivie du dénominateur de l'échelle.  
  De plus:
    - la couche **gtpyr** sélectionne la couche la plus appropriée parmi les 12 couches ci-dessus
      en fonction du niveau de zoom défini par l'appel de l'API,
    - la couche **gtaem** contient les 7 cartes Action de l'Etat en Mer (AEM)
    - la couche **gtMancheGrid** contient la carte MancheGrid,
    - la couche **gtZonMar** contient la carte de délimitation des zones maritimes.
  
  Enfin, à chacune des 16 couches définies ci-dessus est associée une couche des numéros de carte,
  permettant de repérer une carte par son numéro.
  
  Par ailleurs, ShomGT met à disposition les couches vecteur suivantes :
    - la [ZEE](https://fr.wikipedia.org/wiki/Zone_%C3%A9conomique_exclusive) française simplifiée,
    - les [délimitations maritimes définies
      par le Shom](https://www.shom.fr/fr/nos-activites-diffusion/cellule-delimitations-maritimes),
    - les [zones SAR-SRR](https://diffusion.shom.fr/donnees/limites-maritimes/zones-sar.html).

## 3. Configuration
### 3.1 Déploiement décentralisé
Depuis la version 3 de ShomGT, les conteneurs *shomgt* et *sgupdt* peuvent être déployés facilement sur un serveur local
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
à la variable d'environnement `SHOMGT3_SERVER_URL`
contenant l'URL `http://{login}:{passwd}@sgserver.geoapi.fr/index.php`
en remplacant `{login}` et `{passwd}` respectivement par le login et le mot de passe sur le serveur.  
Par ailleurs, la mise à jour régulière des cartes peut être activée en définissant la variable d'environnement
`SHOMGT3_UPDATE_DURATION` contenant le nombre de jours entre 2 actualisations.
Les cartes n'étant pas actualisées très fréquemment, cette durée peut être fixée par exemple à 28 jours.

Ce déploiement décentralisé nécessite aussi un déploiement central pour agir comme serveur de cartes.
### 3.2 Déploiement centralisé
Le code de ShomGT peut aussi être utilisé de manière plus conventionnelle en hébergeant les différents modules
sur un même site.

Si le site est exposé sur internet, il est nécessaire de gérer le [contrôle d'accès](docs/accesscontrol.md).

### 3.3 Divers
ShomGT utilise différents [composants externes décrits ici](docs/composantexterne.md).

Le [système de log est documenté ici](docs/log.md).

ShomGT utilise Php dans sa version 8.2.
