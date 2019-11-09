## Module gestion du catalogue des cartes Shom

Ce module gère un catalogue des cartes GéoTIFF du Shom afin :

  - de connaitre celles à commander au Shom (ajout ou actualisation) et celles périmées à supprimer,
  - d'exposer les caractéristiques des cartes utilisées par le module de mise à jour des cartes.
    
Le catalogue doit être régulièrement actualisé, il est construit à partir de 2 sources du Shom:

  - le flux WFS de la liste des cartes Shom,
  - les Groupes d'Avis aux Navigateurs (GAN) qui fournissent (sous la forme d'une page HTML)
    des informations actualisées chaque semaine sur chaque carte en vigueur.

Les GAN définissent notamment, pour chaque géotiff, le rectangle délimitant sa partie utile
qui est utilisé par le module de mise à jour.
Certains GAN sont erronés et des corrections sont définies dans le fichier `gancorrections.yaml`
qui contient aussi des ajouts pour les GAN manquants.

De même, certaines cartes absentes du flux WFS sont complétées dans `lib.inc.php`.

Le module utilise une définition simplifiée de la ZEE française stockée en SHP dans `franceshp`
et en GeoJSON dans `france.geojson`.
Cette définition est utilisée pour déterminer les cartes Shom pertinentes.

### Mise en oeuvre

- le script `build.php`, à utiliser en ligne de commande, permet de construire le catalogue
  - la sous-commande `harvestGan` moissonne le flux WFS et les pages du GAN dans le répertoire `gan`,
  - la sous-commande `store` fabrique les fichiers `mapcat.pser` qui stocke le catalogue des cartes
    respectivement en format interne et en format JSON.  
- le script `index.php` exploite le catalogue :
  - il expose en JSON les cartes stockées dans `mapcat.pser` (action=showGan),
  - il liste les cartes à actualiser (action=shomgtObsolete), celles à supprimer (action=shomgtObsolete2)
    et celles manquantes (action=shomgtMissing)
- le script map.php affiche le catalogue sous la forme d'une carte Leaflet,
- le script geojson.php fournit le flux GéoJSON du catalogue utilisé par la carte,
- le script mapcat.php permet de consulter le catalogue
  - par défaut il liste les cartes sous la forme d'une table HTML,
  - avec en paramètre l'identifiant d'une carte il expose sa description JSON, ex: `mapcat.php/FR4219`,
  - si le paramètre fmt=map est ajouté (ex: `mapcat.php/FR4233?fmt=map`)
    il affiche une carte Leaflet centrée et zoomée sur la carte Shom.
