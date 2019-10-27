# Pourquoi shomgt ? Comment ?

## 1. Besoin, objectif de shomgt et périmètre
De nombreux utilisateurs ont besoin,
notamment pour localiser en mer différents objets (projets d'aménagement, études, procédures administratives,
incidents, ...),
d'intégrer dans leur SIG (comme QGis) ou dans leur application Web
(construite par exemple avec OpenLayers)
des [cartes marines numériques au format GéoTIFF publiées par le Shom](https://diffusion.shom.fr/pro/navigation/cartes-marines/cartes-marines-geotiff.html).
Toutefois, les images géoréférencées fournies par le Shom ne peuvent pas être directement affichées dans une application
car elles comportent autour de chaque carte son cadre empêchant ainsi de juxtaposer les cartes
et de superposer des objets à cheval sur différentes cartes.
Cette difficulté est illustrée par l'image ci-dessous montant la juxtaposition de 3 cartes:

<p><img src="/geoapi/shomgt/docs/figure-s1.png" alt='sans shomgt' width=1404 height=960 border='4' /></p>

L'objectif de *shomgt* est de faciliter l'utilisation de ces cartes, notamment en extrayant la partie utile
de chaque image pour visualiser sans couture le contenu des cartes, illustré par la l'image ci-dessous:

<p><img src="/geoapi/shomgt/docs/figure-s2.png" alt='avec shomgt' width=1404 height=960 border='4' /></p>

Pour satisfaire à cet objectif, *shomgt* récupère les cartes publiées par le Shom
pour les exposer par 2 web-services utilisables dans les SIG et les applications Web.  
En outre Shomgt :

  - gère les informations nécessaires à la mise à jour régulière de ces cartes à partir des données publiées par le Shom,
  - permet le téléchargement des cartes telles que fournies par le Shom, et sous d'autres formes
    (PNG ou GéoTIFF rogné, c'est à dire sans cadre),
  - met à disposition des infos collectées sur les cartes, notamment sous la forme d'un catalogue de cartes,
  - propose des cartes OpenLayers pour consulter les cartes Shom et le catalogue des cartes.

Seules les cartes décrivant le territoire français, y compris la Polynésie, la Nouvelle Calédonie,
les TAAF et l'île de Clipperton, sont prises en compte.

## 2. Données Shom utilisées
### 2.1. Les cartes
Une carte est décomposée par le Shom en une ou plusieurs images géoréférencées avec généralement une image principale
et éventuellement des images secondaires.
L'image principale est celle de la carte principale et les images secondaires correspondent chacune à un cartouche
présent dans la carte permettant souvent de décrire plus précisément des zones particulières telles que des ports.
Chacune de ces images, appelée par la suite **géotiff**, est fournie par le Shom sous la forme
d'un fichier au [format GeoTIFF](https://fr.wikipedia.org/wiki/GeoTIFF)
auquel est associé un fichier de métadonnées ISO 19115/19139 contenant notamment l'édition de la carte.
Certaines cartes ne contiennent pas d'image principale mais uniquement des images secondaires ;
par exemple la carte 7102 représente différents ports de Guadeloupe proches les uns des autres.
Tous les fichiers GéoTIFF sont founis en projection World Mercator dans le système géodésique WGS84.
Par contre les coordonnées sont toujours indiquées des coordonnées géographiques dans le système géodésique WGS84.

Chaque carte est identifiée par un numéro, généralement à 4 chiffres (par exemple 6991),
parfois précédé de FR pour indiquer qu'il s'agit du numéro du Shom,
et est versionnée au moyen de mentions portées dans le cadre, généralement :

  - un numéro d'édition et une année, ex "Edition No 4 - 2015"
  - une année de publication, ex "Publication 1984"
  - l'identification des corrections portées sur la carte

La livraison d'une carte par le Shom prend la forme d'une archive 7z ayant pour nom le numéro de la carte
suivi de l'extension `'.7z'`.
Dans cette archive le nom de chacun des fichiers GéoTIFF respecte le motif Regexp suivant :
  `'\d\d\d\d_(pal300|(\d+|[A-Z]+)_gtw)\.tif'`
où les 4 premiers chiffres  correspondent au numéro de la carte
et la chaine `'pal300'` identifie l'image principale.
A chaque fichier GéoTIFF est associé un fichier de métadonnées dont le nom est constitué en préfixant
le nom du fichier GéoTIFF par 
  `'CARTO_GEOTIFF_'`
et en remplacant l'extension par `'.xml'`.
Enfin un fichier PNG ayant pour nom le numéro de la carte avec l'extension `'.png'` contient une image réduite
de la carte.

Ainsi on peut considérer que chaque géotiff secondaire est identifié dans la carte par un indice numérique ou alphabétique.
Cet indice n'est pas forcément identique à celui qui est éventuellement écrit sur la carte.

Ainsi, un géotiff est systématiquement identifié par le numéro de la carte suivi d'un indice
qui est vide lorsqu'il s'agit de l'image principale de la carte.
On peut aussi indentifier un géotiff par le nom du fichier GéoTIFF sans son extension.
Cette identification n'identifie pas une version particulière de la carte.

6 cartes sont traitées de manière particulière en raison de leurs caractéristiques:

  - 4 cartes constituent une série particulière pour l'action de l'Etat en mer (AEM) :
    - 7344 pour la Manche et Mer du Nord,
    - 7330 pour l'Atlantique,
    - 7360 pour la Méditerranée
    - 8502 pour la zone maritime du Sud de l’océan Indien (ZMSOI).
  - la carte 8101 MancheGrid définit un carroyage particulier pour la Manche,
  - la carte 0101 est le planisphère terrestre correspondant à l'échelle 1/40M à l'équateur ;
    il s'étend sur plus de 360° en longitude et nécessite donc un traitement particulier.

### 2.2. Le catalogue des cartes
La commande des cartes au Shom s'effectue sur un site spécifique qui permet difficilement de sélectionner les cartes
nécessaires.
Il existe en effet plus de 800 cartes au format GéoTIFF dont plus de la moitié sont pertinentes pour *shomgt*,
ces dernières correspondent approximativement à un peu moins de 800 images géotiff.
Pour identifier d'abord les cartes pertinentes puis régulièrement celles à actualiser,
celles périmées à supprimer ainsi que les nouvelles à ajouter,
il a été nécessaire de construire un catalogue de toutes les cartes au format GéoTIFF du Shom.

Pour cela 2 sources de données sont utilisées:

  - le flux WFS des cartes permet de connaitre à un instant donné la liste des cartes en vigueur,
  - les pages HTML du "Groupe d’Avis aux Navigateurs en Ligne" (GAN) permettent d'obtenir pour chaque carte
    plusieurs informations très utiles, notamment:
    - le titre et l'édition de la carte en vigueur,
    - la boite en coordonnées géographiques définissant la partie utile de la carte
      et de chacun de ses éventuels cartouches, c'est à dire la zone à l'intérieur du cadre.

La confrontation de l'édition de la carte en vigueur à celle de la carte stockée dans shomgt permet de détecter
les cartes à actualiser.
Les écarts entre la liste des cartes en vigueur et la liste des cartes stockées dans shomgt permet
de détecter des cartes périmées à supprimer et les cartes nouvelles à ajouter.
Enfin, la boite définissant la partie utile de chaque image permet d'effacer le cadre de chaque image.

## 3. Web-services exposés
*Shomgt* expose principalement un service WMS destiné à l'utilisation des cartes dans un SIG
et un service de tuiles destiné à l'utilisation des cartes dans une application Web.
Pour cela les cartes sont réparties en couches qui agrègent des cartes approximativement à la même échelle.

## 3.1. Définition des couches
On distingue les 5 types de couches suivants :

  - 10 couches correspondant à la répartition en fonction de leur échelle des géotiff ne correspondant pas
    aux 6 cartes spécifiques mentionnées ci-dessus.
    Les dénominateurs des échelles retenus pour ces couches sont les suivants,
    avec entre parenthèses le ou les niveaux de zoom correspondants:
    12k (15-18), 25k (14), 50k (13), 100k (12), 250k (11), 500k (10), 1M (9), 2M (8), 4M (7), 10M (6).  
    Le nom de chacune de ses couches est composé de **gt** suivi du dénominateur d'échelle.
  - la couche **gt20M** correspond au planisphère terrestre (carte 0101) et aux niveaux de zoom 0 à 5,
  - la couche **gtpyr** sélectionne une des 11 couches ci-dessus en fonction du niveau de zoom défini par l'appel,
  - la couche **gtaem** contient les cartes Action de l'Etat en Mer (AEM)
  - la couche **gtMancheGrid** contient la carte MancheGrid,
  - de plus à chacune des 14 couches définies ci-dessus est associée une couche des
    silhouettes des cartes et leur numéro, permettant de repérer une carte par son numéro.

## 3.2. Mise en oeuvre des web-services
Les 2 web-services (WMS et tuiles) retournent une image en fonction des paramètres fournis,
notamment la couche souhaitée et une boite en coordonnées géographiques.
Cette image est construite pour les couches de cartes en superposant les intersections de la boite demandée
avec les parties utiles (sans les cadres) des géotiff de la couche demandée lorsque l'intersection est non vide.

## 3. Restructuration des données

### 3.2. Corrections du flux WFS

### 3.3. Corrections du GAN

### 3.4. Ordre des cartes

