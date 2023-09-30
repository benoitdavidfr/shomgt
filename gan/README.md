# Module gan de ShomGT
L'objectif de *gan* est d'exposer sous une forme structurée adaptée au module *dashboard* les informations d'actualité des cartes
extraites du site GAN du Shom.
Ce site prenant la forme d'un site Web, il est scrappé pour en extraire les informations recherchées.

Le scrapping du GAN est effectué par la commande `php gan.php newHarvest` qui interroge le GAN pour chaque carte
du portefeuille et enregistre la page Html correspondante dans le répertoire `gan`.  
Une fois cette action réalisée, l'analyse des pages moissonnées est effectuée par la commande `php gan.php storeHarvest`
qui enregistre le résultat de l'analyse d'une part dans le fichier `gans.pser` et, d'autre part, dans le fichier `gans.yaml`
qui est conforme au schéma [`gans.schema.yaml`](gans.schema.yaml).

Ce module correspond au [package shomgt\gan](https://benoitdavidfr.github.io/shomgt/phpdoc/packages/shomgt-gan.html)
de la doc PhphDoc:

## Points d'attention
L'algorithme de scrapping peut être invalidé par une modification des pages Html du site du GAN.
Ainsi:

1. le code d'analyse du Html est concentré dans la méthode statique `analyzeHtml(string $html): array`
  de la classe `GanStatic` définie dans le fichier `gan.inc.php`.  
2. Cette analyse peut être testée au moyen de la commande `php gan.php analyzeHtml {mapNum}`
  où `{mapNum}` est le numéro de la carte à analyser.
