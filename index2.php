<?php
/*PhpDoc:
name: index2.php
title: index2.php - texte de la réelle page d'accueil
includes: [ ws/accesscntrl.inc.php ]
doc: |
journal: |
  1-2/11/2019
    adaptation à la nouvelle version
  2/7/2017
    améliorations
  19/6/2017
    améliorations
  9/6/2017
    création
*/
require_once __DIR__.'/ws/accesscntrl.inc.php';

if (!Access::cntrl()) {
  $adip = $_SERVER['REMOTE_ADDR'];
  header('HTTP/1.1 403 Forbidden');
  die("<body>Bonjour,</p>
    <b>Ce site est réservé aux agents de l'Etat et de ses Etablissements publics administratifs (EPA).</b><br>
    L'accès peut s'effectuer au travers d'une adresse IP correspondant à un intranet de l'Etat ou d'un de ses EPA (RIE, ...).
    Vous accédez actuellement à ce site au travers de l'adresse IP <b>$adip</b> qui n'est pas enregistrée
    comme une adresse IP d'un tel intranet.<br>
    Si vous souhaitez accéder à ce site et que vous appartenez à un service de l'Etat ou à un de ses EPA,
    vous pouvez transmettre cette adresse IP à Benoit DAVID de la MIG (contact at geoapi.fr)
    qui regardera la possibilité d'autoriser votre accès.<br>
    Une autre possibilité est d'<a href='login.php' target='_parent'>accéder en vous authentifiant ici</a>,
    si vous disposez d'un identifiant et d'un mot de passe.  
  ");
}
?>
<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>cartes shom</title></head>
<h2>Accès aux cartes du Shom</h2>
<h3>Nouveautés</h3>
<ul>
  <li>1/11/2019 : nouvelle version de shomgt en test<ul>
    <li>réécriture du code publié sur <a href='https://github.com/benoitdavidfr/shomgt' target='_blank'>Github</a> ;
      en cas de besoin intensif des API, merci d'installer votre propre serveur !!!<br>
      ce nouveau code restructuré simplifie grandement la mise à jour des cartes périmées ;
      attention la liste des couches a été modifiée ;
    </li>
    <li>ajout de nouvelles cartes et mise à jour des cartes périmées.</li>
  </ul></li>
  <li>19/12/2018 : remplacement du téléchargement du tiff des cartes par celui de l'archive 7z fournie par le Shom,
    permettant ainsi d'obtenir le fichier de métadonnées de la carte
  <li>8/12/2018 : mise à jour des cartes</a>
  <!--
  ><li>23/7/2018 : ajout d'un contrôle d'accès par referer
  <li>6/6/2018 : ajout de nouvelles adresses IP utilisées par les services du MTES/MCT et les DDT
     (publiées sur http://pne.metier.i2/adresses-presentees-sur-internet-a1012.html)
  -->
</ul>

<h3>Résumé</h3>
Ce site expérimental, illustré par la fenêtre de droite,
propose différents mécanismes d'accès au contenu des cartes du Shom, notamment une carte Leaflet simple d'utilisation.</p>

<b>L'utilisation de ce site est réservé aux agents de l'Etat et de ses Etablissements publics à caractère Administratif (EPA) pour leurs missions de service public et un usage interne.</b>

L'utilisation est soumise aux <b>conditions générales d’utilisation des produits numériques, services et prestations du Shom</b>
que vous trouverez en annexe 1 du 
<a href='http://diffusion.shom.fr/media/wysiwyg/catalogues/repertoire_2017_web.pdf' target='repertoire'>Répertoire des principaux documents dans lesquels figurent les informations publiques produites par le Shom disponible ici page 52</a>.
<b>En utilisant ce site ou l'une de ses API, <u>vous acceptez ces conditions d'utilisation</u></b>.</p>

L'API d'accès aux tuiles a été conçue pour être facilement utilisable dans une carte Leaflet,
et permettre ainsi des superpositions entre différentes couches, comme illustré
par la <a href='mapwcat.php' target='_blank'>carte ici en plein écran</a>.<br>

Un <a href='https://geoapi.fr/shomgt/wms.php?service=WMS&amp;request=GetCapabilities' target='_blank'>
service WMS est aussi proposé</a>
pour intégrer les cartes dans les outils SIG.</p>

<b>Merci d'utiliser les API avec modération.</b>
Si vous en avez un besoin intensif, merci d'installer votre propre serveur à partir du code disponible
sur <a href='https://github.com/benoitdavidfr/shomgt' target='_blank'>Github</a>
puis de copier les cartes dont vous avez besoin.

<h3>Rappel du contexte légal</h3>
Depuis le 1er janvier 2017, en application de la
<a href='https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte' target='LRN'>loi pour une République Numérique</a>,
les données publiques du Shom sont communiquées gratuitement à l'Etat et à ses EPA.
Parmi ces données, les 
<a href='http://diffusion.shom.fr/media/wysiwyg/catalogues/repertoire_2017_web.pdf' target='repertoire'>Images numériques géoréférencées des cartes marines</a>,
aussi appelées cartes GéoTIFF, sont issues de la numérisation des cartes marines traditionnelles du Shom.<br>
Le Shom propose aussi une API d'accès à ses cartes
(<a href='http://diffusion.shom.fr/media/wysiwyg/catalogues/repertoire_2017_web.pdf' target='repertoire'>nommées RasterMARINE</a>)
mais la considère comme des données privées dont l'utilisation reste donc payante.<br>

L'objectif de ce site est de simplifier l'accès au contenu des cartes GéoTIFF du Shom
aux agents de l'Etat et de ses EPA afin de leur permettre de travailler lorsque leur service
n'a pas acquis une licence d'utilsation de RasterMARINE.</p>

<h3>Une proposition décomposée en 6 éléments</h3>
<ol>
<li>Une <a href='mapwcat.php' target='_blank'>carte accessible à tous pour visualiser et télécharger les cartes du SHOM</a> (<a href='doc.html#mapwcat' target='doc'>Plus d'infos ici</a>)
  
<li>Une API d'accès aux tuiles simple à intégrer dans une carte Leaflet :
  <code><a href='https://geoapi.fr/shomgt/tile.php' target='_blank'>https://geoapi.fr/shomgt/tile.php</a></code>
  (<a href='doc.html#tile' target='doc'>Plus d'infos ici</a>)
  
<li>Une API d'accès au catalogue des images simple à intégrer dans une carte Leaflet :
  <code><a href='https://geoapi.fr/shomgt/ws/geojson.php' target='_blank'>https://geoapi.fr/shomgt/ws/geojson.php</a></code>
  (<a href='doc.html#geojson' target='doc'>Plus d'infos ici</a>)

<li>Une API de téléchargement notamment des cartes GéoTIFF :
  <code><a href='https://geoapi.fr/shomgt/ws/dl.php' target='_blank'>https://geoapi.fr/shomgt/ws/dl.php</a></code>
  (<a href='doc.html#dl' target='doc'>Plus d'infos ici</a>)

<li>Pour les utilisateurs de SIG (comme QGis), un service WMS d'accès au contenu des cartes Shom :
  <code><a href='https://geoapi.fr/shomgt/wms.php?SERVICE=WMS&REQUEST=GetCapabilities' target='_blank'>https://geoapi.fr/shomgt/wms.php</a></code>
  (<a href='doc.html#wms' target='doc'>Plus d'infos ici</a>)

<li>Un <a href='cat/' target='_blank'>catalogue des cartes SHOM</a>, pas uniquement celles sélectionnées dans shomgt.
</ol>


<h3>Liste des couches définies</h3>
Les cartes du Shom sont regroupées en couches correspondant à des cartes approximativement à la même échelle.
(<a href='doc.html#layers' target='doc'>Plus d'infos ici</a>)</p>

Afin de naviguer dans les différentes cartes sans avoir à changer de couche,
une couche supplémentaire, appelée Pyramide (gtpyr), est définie dont le contenu dépend du niveau de zoom.
(<a href='doc.html#pyr' target='doc'>Plus d'infos ici</a>)

<h3>Contrôle des accès</h3>
Pour respecter les règles de diffusion du Shom, l'accès aux cartes et à leur contenu est contrôlé.
L'accès le plus simple aux cartes s'effectue à partir d'un intranet de l'Etat (RIE, ...) dont l'adresse IP est enregistrée.</p>

Si ce type de contrôle ne convient pas, 2 autres contrôles d'accès sont aussi possibles:<ul>
<li>au moyen d'un login et d'un mot de passe en mode Web (pour les API web) ou en mode HTTP (pour le service WMS),
<li>en enregistrant le referer des cartes qui donnent accès aux images.
</ul>

Vos retours sont indispensables pour améliorer ces développements.<br>
Merci aussi de m'indiquer les cartes Shom absentes qui vous seraient utiles.

<h3>Auteur</h3>
Ce site est réalisé par Benoit DAVID, Chef de la MIG au MTES.<br>
Contact: <a href='mailto:contact@geoapi.fr'>contact@geoapi.fr</a><br>
Mise à jour: 1/11/2019<br>
