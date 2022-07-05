# Catalogue des cartes MapCat

Le catalogue des cartes est contenu dans le fichier [mapcat.yaml](mapcat.yaml) ;
Son schéma JSON est défini dans le fichier [mapcat.schema.yaml](mapcat.schema.yaml).

Ce catalogue constitue une référence indispensable pour le fonctionnement de sgupdt et shomgt :

- l'extension spatiale (champ `spatial`) et l'échelle (champ `scaleDenominator`) des cartes et de leurs cartouches sont utilisés
  dans sgupdt et shomgt pour visualiser les cartes,
- les bordures (champ `borders`) sont nécessaires aux fichiers GéoTiff non ou mal géoréférencés,
- le titre (champ `title`) est utilisé pour l'afficher dans la carte Leaflet,
- les champs `layer` et `geotiffname` sont nécessaires pour gérer les cartes spéciales (cartes AEM, MancheGrid, ...),
- les champs `z-order`, `toDelete` et `outgrowth` permettent d'améliorer l'affichage des GéoTiff dans le service WMS
  et la carte Leaflet,
- enfin, les champs `noteCatalog` et `badGan` permettent de mémoriser les choix effectués dans le gestion de ce catalogue.

Ce catalogue est téléchargé par les copies réparties de ShomGt lors de la mise à jour des cartes.
Ainsi son amélioration bénéficie à toutes les instance de ShomGt.

Si vous souhaitez améliorer ce catalogue, notamment les champs `z-order`, `toDelete` et `outgrowth` pour améliorer
l'affichage des GéoTiff, proposez des pull requests sur le fichier mapcat.yaml.
