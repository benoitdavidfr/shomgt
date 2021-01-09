## Module de gestion du catalogue des cartes Shom

### Introduction
Ce module gère un catalogue de cartes Raster Marine du Shom afin :

  - de connaitre celles à commander au Shom (ajout ou actualisation) et celles obsolètes à supprimer,
  - d'exposer les caractéristiques des cartes utilisées par le module de mise à jour des cartes.
    
Le catalogue doit être régulièrement actualisé, il est construit à partir de 2 sources du Shom:

  - le flux WFS de la liste des cartes Shom,
  - les Groupes d'Avis aux Navigateurs (GAN) qui fournissent (sous la forme d'une page HTML)
    des informations actualisées chaque semaine sur chaque carte en vigueur.

Le module utilise pour déterminer les cartes Shom pertinentes une définition très simplifiée de la ZEE française
stockée en GeoJSON dans `france.geojson`.

### Mise en oeuvre
Ce module remplace le module cat v2.1 rendu obsolète par l'impossibilité d'interroger les GAN sans fournir de date d'origine.
Dans la version précédente le catalogue était construit à chaque moisson.
Ce principe change dans cette version car je dispose du catalogue de toutes les cartes (mapcatV1.yaml)
Différents outils permettent de consulter ce catalogue, notamment la possibilité d'en faire une carte.
Les cartes d'intérêt peuvent être sélectionnées par intersection avec la ZEE française.
  
La doctrine de mise à jour des cartes et en conséquence de gestion du catalogue est la suivante :

  - chercher à mettre à jour finement les cartes d'intérêt, cad dès qu'une correction est disponible, avec un cycle de qqs mois,
    entre 1 an et 3 mois,
  - pour cela gestion d'un catalogue mapcat de ces cartes avec notamment pour chacune le no et la date de sa dernière correction,
  - utilisation du flux WFS du Shom pour détecter les nouvelles cartes et les cartes obsolètes ; pour cela identification dans ce flux
    WFS les cartes d'intérêt (par intersection avec la ZEE) et confrontation avec les cartes du catalogue.
    Cette opération est contrôlée visuellement au travers d'une carte de toutes les cartes d'intérêt ou non,
  - utilisation par ailleurs du GAN pour détecter les nouvelles corrections,
  - enfin, lors de l'ajout d'une nouvelle carte au catalogue, saisie dans mapcat des coordonnées
    précises du cadre intérieur de chaque zone de la carte, utilisées par le module updt.

L'objectif de ce module est:
  1) de gérer le catalogue MapCat
    - avec la description des cartes de ShomGt plus des autres cartes d'intérêt,
    - avec les dates de mise à jour, l'édition et la dernière correction issues des MD ISO ShomGt,
    - avec les titres et bbox internes issues du GAN, ou à défaut saisies sur les cartes,
    - permettant une confrontation au WFS du Shom pour détecter les nouvelles cartes et celles de ShomGt obsolètes,
  2) de consulter les GANs concernant chaque carte pour détecter les cartes à mettre à jour.

Le processus de mise à jour des cartes est défini dans index.php?a=processus

Du point de vue architecture informatique:
  - le catalogue est stocké dans une structure de données de type Yaml spécifiée par un schéma JSON. Afin de gérer les erreurs
    détectées dans les sources de données du Shom (WFS et GAN), des écarts avec ces sources sont définis,
  - le catalogue est stocké en pser avec une version en Yaml,
  - les scripts Php lisent le pser et le réécrivent en cas de modification,
  - à chaque modification le fichier Yaml est réécrit,
  - le fichier Yaml peut, à la demande, être utilisé pour écraser le pser,
  - le fichier Yaml est régulièrement historisé dans Git.
  - le fichier des gans est aussi géré en pser et en Yaml

Le catalogue des cartes contient des infos absentes du catalogue des GéoTiffs, notamment le nom de certaines cartes, ex 7436

De manière plus générale, 3 catalogues différents sont gérés dans ShomGt :

  - le catalogue MapCat des cartes recense les cartes Raster Marine du Shom d'intérêt, plus qqs cartes comme les AEM et MancheGrid
  - le portefeuille des GéoTiffs (shomgt.yaml stocké dans ../ws) recense les GéoTiffs les plus récents obtenus du Shom
    - une carte est souvent composée de plusieurs GéoTiffs
  - la liste historique de toutes le cartes obtenues du Shom -> ../master/histo.php
