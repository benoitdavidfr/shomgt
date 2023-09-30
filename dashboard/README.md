# Module dashboard de ShomGT
L'objectif de *dashboard* est d'identifier :

- les nouvelles cartes à ajouter au portefeuille,
- les cartes existantes rétirées par le Shom et donc à retirer du portefeuille,
- les cartes existantes à actualiser du fait de mises à jour publiées par le Shom.

Cela se fait par l'affichage d'un tableau de bord.

A noter, qu'il est trop contraignant d'essayer de prendre en compte chaque correction de carte.
La doctrine mise en place consiste à attendre d'avoir plus de 3 corrections sur une carte avant de la mettre à jour
et d'essayer de ne pas dépasser 5 corrections sans mise à jour.
Cela permet d'effectuer des commandes au Shom tous les 3 à 4 mois.

Pour constituer ce tableau de bord, 2 sources du Shom sont consultées:

1. le service WFS permet de détecter de nouvelles cartes ; sa consultation est effectuée dans le [module shomft](../shomft).
2. le site du GAN permet de détecter des mises à jour et des retraits de cartes existantes ;
   sa consultation est effectuée dans le [module gan](../gan).

Une fois ce moissonnage du GAN effectué, il est possible d'afficher le tableau de bord disponible dans `index.php`
ce qui permet:

1. de passer les commandes de cartes nécessaires au Shom dans sa boutique https://diffusion.shom.fr/
2. de déterminer dans le fichier `index.yaml` de la livraison la liste des cartes à retirer.

Ce module correspond au [package shomgt\dashboard](https://benoitdavidfr.github.io/shomgt/phpdoc/packages/shomgt-dashboard.html)
de la doc PhphDoc.


## Point d'attention
Du fait du [problème rencontré avec les dates de validité
des cartes](../sgserver#23-probl%C3%A8me-rencontr%C3%A9-avec-les-dates-de-validit%C3%A9-des-cartes)
la date de révision des cartes dans le portefeuille mentionnée dans le tableau de bord est souvent fausse.
