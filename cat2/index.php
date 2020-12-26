<?php
/*PhpDoc:
name: index.php
title: cat2/index.php - Gestion du catalogue des cartes du Shom v2
classes:
doc: |
  Du point de vue opérationnel le process est:
  
journal: |
  13/12/2020:
    - passage en V2
*/
?>
<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>cat2</title></head><body>
<?php if(!isset($_GET['a'])) { ?>
<h2>Cat2</h2><ul>
<li><a href='?a=processus'>Processus de mise à jour</a></li>
<li><a href='mapcat.php?f=yaml'>Affiche le catalogue en Yaml</a></li>
<li><a href='http://localhost/schema/?action=check&file=../geoapi/shomgt/cat2/mapcat.yaml'>
  Vérifie la conformité du catalogue à son schéma</a></li>
<li><a href='mapcat.php'>Affiche le catalogue en Html</a></li>
<li><a href='llmap.php'>Affiche le catalogue sous la forme d'une carte</a></li>
<li><a href='gestion.php?action=compCat'>
  Détecte de nouvelles cartes ou des cartes périmées par confontation du catalogue au flux WFS</a></li>
<li><a href='gan.php'>Liste les cartes à mettre à jour ordonnées par age décroissant</a></li>
</ul>
<h3>Autres actions</h3><ul>
  <li><a href='mapcat.php'>Gestion du catalogue</a></li>
  <li><a href='gestion.php'>Suivi du flux WFS</a></li>
  <li><a href='gan.php?a=menu'>Suivi des GAN</a></li>
</ul>
<?php } else if ($_GET['a']=='processus') { ?>
<h2>Processus de mise à jour de ShomGt en utilisant le catalogue</h2>
<h3>Introduction</h3>
Le répertoire <code><?php echo realpath(__DIR__.'/../../../shomgeotiff'); ?></code> contient les cartes du Shom,
dans cette documentation il est noté par <i>{shomgeotiff}</i>.<br>
Le <b>portefeuille de cartes</b>, c'est à dire celles qui s'affichent dans les web-services,
est constitué des cartes présentes dans le répertoire <code><i>{shomgeotiff}</i>/current</code>,
avec un sous-répertoire par carte.<br>
L'<b>historique des livraisons</b> est conservé dans le répertoire <code><i>{shomgeotiff}</i>/incoming</code>,
avec un sous-répertoire par livraison portant comme nom la date de livraison sous la forme YYYYMMDD.
</p>

Le processus de mise à jour consiste à :<ol>
<li>ajouter au portefeuille les <b>nouvelles cartes pertinentes</b>,
  qui sont des cartes apparues dans le catalogue du Shom
  et pertinentes pour ShomGt, c'est à dire cartographiant un espace français ;</li>
<li>supprimer du portefeuille les cartes <b>obsolètes</b>, c'est à dire celles que le Shom indique comme <i>plus en vigueur</i>,
  souvent remplacées par une nouvelle carte ;</li>
<li>remplacer les cartes <b>plus à jour</b> dans ShomGt, c'est à dire faisant l'objet soit d'une nouvelle édition, soit de corrections ; 
  ce remplacement n'est effectué que lorsque les évolutions sont suffisamment importantes.
</ol>


<h3>Etapes du processus</h3>
On part d'un catalogue MapCat existant.
Les étapes sont les suivantes :
<ol>
<li><a href='mapcat.php?a=synchroShomGt'>
  prendre en compte dans MapCat les évolutions de ShomGt pour mettre à jour la date de mise à jour, l'édition
  et la dernière correction des cartes du portefeuille</a>,</li>

<li><a href='gestion.php?action=compCat'>identifier les cartes obsolètes et les cartes à rajouter par confrontation au WFS,</a></li>

<li>moissonner le GAN (a priori de manière incrémentale) et charger la moisson ; pour cela effectuer en CLI :<pre>
      php gan.php harvestAndStore</pre>
  <ul>
    <li>alternativement, pour remoissonner complètement :<pre>
      php gan.php fullHarvestAndStore</pre>
    </li>
  </ul>
</li>
<li><a href='gan.php?f=html'>identifier les cartes les plus "agées", cad celles nécessitant le plus une mise à jour</a></li>
<li>choisir la liste des cartes à commander au Shom (cartes à ajouter et à remplacer)
  et celles à supprimer du portefeuille, notamment celles remplacées par de nouvelles cartes commandées,</li>
<li><ul>
  <li>commander au Shom les cartes à commander,</li>
  <li>noter cette commande dans <code>cmdesaushom.yaml</code>,</li>
  <li>attendre la réception des cartes,</li>
</ul>
<li>marquer comme obsolètes les cartes que l'on a choisi de supprimer du portefeuille,</li>
<li>lire sur les nouvelles cartes les bbox internes et saisir les informations dans <code>mapcat.yaml</code>,</li>
<li><a href='mapcat.php?a=loadYaml'>prendre en compte ce fichier Yaml dans MapCat</a></li>
<li>intégrer la livraison dans ShomGt, c'est à dire :
  <ul>
    <li>créer dans <code><i>{shomgeotiff}</i>/incoming</code> le répertoire de livraison,
      avec comme nom la date de livraison sous la forme YYYYMMDD,
      noté par la suite <i>{nomDuRépertoireDeLivraison}</i></li>
    <li>copier dans ce répertoire les fichiers 7zippés des cartes livrées,</li>
    <li>y ajouter un fichier index.yaml avec la liste des cartes à supprimer,</li>
    <li>prendre en compte cette livraison en effectuant en CLI dans le répertoire <code>updt</code> la commande :<pre>
      php updt.php {nomDuRépertoireDeLivraison} | sh</pre>
  </ul>
</li>
<li>itérer sur 1)
</ol>
<?php } ?>
  