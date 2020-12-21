<?php
/*PhpDoc:
name: index.php
title: cat2/index.php - Gestion du catalogue des cartes du Shom v2
classes:
doc: |
  
journal: |
  13/12/2020:
    - passage en V2
*/
?>
<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>cat2</title></head><body>
<h2>Cat2</h2><ul>
<li><a href='mapcat.php?f=yaml'>Affiche le catalogue en Yaml</a></li>
<li><a href='http://localhost/schema/?action=check&file=../geoapi/shomgt/cat2/mapcat.yaml'>
  Vérifie la conformité du catalogue à son schéma</a></li>
<li><a href='mapcat.php'>Affiche le catalogue en Html</a></li>
<li><a href='llmap.php'>Affiche le catalogue sous la forme d'une carte</a></li>
<li><a href='gestion.php?action=compCat'>
  Détecte de nouvelles cartes ou des cartes périmées par confontation du catalogue au flux WFS</a></li>
<li><a href='hgan.php'>Liste les cartes à mettre à jour ordonnées par age décroissant</a></li>
</ul>
<h3>Autres actions</h3><ul>
  <li><a href='gestion.php'>Suivi du flux WFS</a></li>
  <li><a href='hgan.php?a=help'>Suivi des GAN</a></li>
</ul>
