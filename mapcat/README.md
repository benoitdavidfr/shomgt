# Catalogue des cartes MapCat

Le catalogue des cartes est contenu dans le fichier [mapcat.yaml](mapcat.yaml).
Son schéma JSON est défini dans le fichier [mapcat.schema.yaml](mapcat.schema.yaml).

Ce catalogue constitue une référence indispensable pour que sgupdt et shomgt puisse fonctionner:

- l'extension spatiale (champ spatial) et l'échelle (champ scaleDenominator) sont utilisés dans sgupdt et shomgt
  pour visualiser les cartes,
  
  

construire shomgt.yaml dans ,
où sont utilisés notamment l'extension spatiale et l'échelle des cartes et des cartouches.
La liste des bordures est utilisée pour les GéoTiffs non ou mal géoréférencés.
Les champs optionnels z-order et toDelete sont utilisés pour améliorer la superposition entre GéoTiffs.
Les champs layer et geotiffname sont utilisés pour les cartes spéciales ne respactant pas les specs des GéoTiffs.

Ce catalogue ne contient pas les infos temporelles (édition et correction), il ne recense que les caractéristiques stables
des cartes. Il est largement constitué par copie d'infos issues du GAN puis détection et gestion des écarts.
Ces écarts au GAN existent et sont alors expliqués dans les champs noteCatalog et badGan.

La structuration des cartouches (sauf leur ordre dans la carte) est gérée conformément à celle des GéoTiffs
qui n'est pas toujours identique à celle du GAN.

Le traitement dans le GAN des excroissances de cartes est hétérogène.
Parfois l'extension spatiale du GAN les intègre et parfois elle ne les intègre pas.
J'adopte le principe suivant:
  - en première approche j'aligne l'extension spatiale dans mapcat sur le GAN
    - sauf s'il y a une erreur manifeste dans le GAN que j'indique dans badGan
  - en cas de nécessité pour améliorer la visualisation des GéoTiffs j'affine les extensions et je l'indique dans badGan
