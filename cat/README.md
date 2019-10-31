## catalogue des cartes raster GéoTIFF du SHOM

Ce modules gère un catalogue des cartes GéoTIFF du Shom afin :

  - de connaitre celles à commander au Shom (ajout ou actualisation) et celles à supprimer (péremption),
  - d'exposer les caractéristiques des cartes utilisées par le module de mise à jour des cartes.
    
Le catalogue doit être régulièrement actualisé, il est construit à partir de 2 sources du Shom:

  - le flux WFS de la liste des cartes Shom
  - les Groupes d'Avis aux Navigateurs (GAN) qui fournissent des informations actualisées chaque semaine
    sur chaque carte non périmée

Mise en oeuvre:

  - le script `build.php` permet de lire le flux WFS et les GANs correspondants
    - la sous-commande `action=harvestGan` moissonne les pages du GAN dans le répertoire `gan`,
    - la sous-commande `action=store` fabrique le fichier `mapcat.pser`
      qui stocke le catalogue des cartes en format interne contenant un cliché des GANs construit à une date donnée.  
      Le champ maps est un dictionnaire des cartes indexé sur l'id de la carte.  
    - les cartes sont structurées par la classe MapCat (définie dans `mapcat.inc.php`)
      qui permet d'accéder au catalogue par différentes méthodes.
  - le script index.php :
    - expose en JSON les cartes stockées dans mapcat.pser (action=showGan),
    - liste les cartes à actualiser (action=shomgtObsolete), celles à supprimer (action=shomgtObsolete2)
      et les cartes manquantes (action=shomgtMissing)
  - le script map.php affiche le catalogue sous la forme d'une carte Leaflet
  - le script geojson.php fournit le flux GéoJSON du catalogue, il est utilisé par la carte
  - le script mapcat.php permet de consulter le catalogue
    - par défaut il liste les cartes en HTML
    - accès à la description d'une carte
    - affichage de la carte Leaflet centrée et zoomée sur la carte Shom
