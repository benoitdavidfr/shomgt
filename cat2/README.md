## Module gestion du catalogue des cartes Shom

### Introduction
Ce module gère un catalogue des cartes GéoTIFF du Shom afin :

  - de connaitre celles à commander au Shom (ajout ou actualisation) et celles obsolètes à supprimer,
  - d'exposer les caractéristiques des cartes utilisées par le module de mise à jour des cartes.
    
Le catalogue doit être régulièrement actualisé, il est construit à partir de 2 sources du Shom:

  - le flux WFS de la liste des cartes Shom,
  - les Groupes d'Avis aux Navigateurs (GAN) qui fournissent (sous la forme d'une page HTML)
    des informations actualisées chaque semaine sur chaque carte en vigueur.

Le module utilise une définition très simplifiée de la ZEE française stockée en SHP dans `franceshp`
et en GeoJSON dans `france.geojson`.
Cette définition est utilisée pour déterminer les cartes Shom pertinentes.

### Mise en oeuvre

La mise en oeuvre est détaillée dans 