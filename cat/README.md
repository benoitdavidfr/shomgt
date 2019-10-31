## Catalogue des cartes Shom

Ce modules gère un catalogue des cartes GéoTIFF du Shom afin :

  - de connaitre celles à commander au Shom (ajout ou actualisation) et celles à supprimer car périmées,
  - d'exposer les caractéristiques des cartes utilisées par le module de mise à jour des cartes.
    
Le catalogue doit être régulièrement actualisé, il est construit à partir de 2 sources du Shom:

  - le flux WFS de la liste des cartes Shom,
  - les Groupes d'Avis aux Navigateurs (GAN) qui fournissent des informations actualisées chaque semaine
    sur chaque carte en vigueur.

Le module utilise une définition de la ZEE française stockée en SHP dans `franceshp` et en GeoJSON dans `france.geojson`.
Cette définition est utilisée pour déterminer les cartes Shom pertinentes.

Les GAN définissent le rectangle délimitant la partie utile de chaque géotiff utilisé par le module de mise à jour.
Certains GAN sont erronés et des corrections sont définies dans le fichier `gancorrections.yaml`
ainsi que des ajouts pour les GAN manquants.

Mise en oeuvre:

  - le script `build.php`, à utiliser en ligne de commande, construit le catalogue
    - la sous-commande `harvestGan` moissonne le flux WFS et les pages du GAN dans le répertoire `gan`,
    - la sous-commande `store` fabrique le fichier `mapcat.pser` qui stocke le catalogue des cartes en format interne.  
  - le script index.php :
    - expose en JSON les cartes stockées dans mapcat.pser (action=showGan),
    - liste les cartes à actualiser (action=shomgtObsolete), celles à supprimer (action=shomgtObsolete2)
      et les cartes manquantes (action=shomgtMissing)
  - le script map.php affiche le catalogue sous la forme d'une carte Leaflet
  - le script geojson.php fournit le flux GéoJSON du catalogue, il est utilisé par la carte
  - le script mapcat.php permet de consulter le catalogue
    - par défaut il liste les cartes sous la forme d'une table HTML
    - expose la description JSON de chaque carte
    - affiche une carte Leaflet centrée et zoomée sur la carte Shom
