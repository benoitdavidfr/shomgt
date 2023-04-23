<?php
/*PhpDoc:
name: index2.php
title: index2.php - texte de la réelle page d'accueil
includes: [ lib/accesscntrl.inc.php ]
doc: |
journal: |
  9/11/2019
    amélioration du contrôle d'accès
  1-2/11/2019
    adaptation à la nouvelle version
  2/7/2017
    améliorations
  19/6/2017
    améliorations
  9/6/2017
    création
*/
require_once __DIR__.'/shomgt/lib/accesscntrl.inc.php';

if (Access::cntrlFor('homePage') && !Access::cntrl()) {
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
<h3>Actualité</h3>
<ul>
  <li>18/4/2023 : actualisation d'environ 30 cartes les moins à jour,</li>
  <li>8/3/2023 : actualisation d'environ 70 cartes les moins à jour,</li>
  <li>13/1/2023 : actualisation d'environ 50 cartes les moins à jour,</li>
  <li>25/6/2022 : nouvelle version de shomgt ; 
      réécriture du code et publication sur <a href='https://github.com/benoitdavidfr/shomgt3' target='_blank'>Github</a> ;
      ce nouveau code restructuré simplifie grandement l'installation d'un serveur local et la mise à jour automatique
      des cartes sur ce serveur ;
      en cas de besoin intensif des API, merci d'installer votre propre serveur !!!<br>
      mise à jour des cartes périmées.</li>
  </li>
  <li>1/6/2022 : actualisation d'environ 50 cartes les moins à jour,</li>
  <li>6, 11 et 12/4/2022 : actualisation des 129 cartes les moins à jour,
    ajout d'une nouvelle carte
    et suppression de 11 cartes Fac similé retirées du catalogue du Shom</li>
  <li>11/1/2022 : actualisation des 40 cartes les moins à jour</li>
  <li>7/6/2021 : ajout de 2 cartes et actualisation des 20 cartes les moins à jour</li>
  <li>29/1/2021 : les 26 cartes les moins à jour ont été actualisées</li>
  <li>11/1/2021 :<ul>
    <li>ajout dans la carte Leaflet d'une couche frontières et ZEE issue du service WFS du Shom</li>
    <li>ajout de la carte spéciale des zones maritimes (8510) et des cartes d'Action de l'Etat en mer de Polynésie (8517),
      de Nouvelle Calédonie et de Wallis et Futuna (8509)</li>
    <li>nouvelle version du serveur téléchargeable sur 
    <a href='https://github.com/benoitdavidfr/shomgt' target='_blank'>Github</a>,
    avec notamment nouvelle version de la détection des cartes à actualiser
    et simplification du téléchargement des cartes Shom</li>
  </ul></li>
  <li>10 et 23/12/2020 et 5 et 11/1/2021 : les cartes les moins à jour ont été actualisées (environ 60),
    certaines cartes ont été supprimées, quelques cartes ont été ajoutées</li>
  <li>26/02/2020 : toutes les cartes de métropole ont enfin été mises à jour</li>
  <li>21/11/2019 : la mise à jour des cartes est en cours mais, en raison d'une difficulté au Shom,
    seule une partie des cartes a été traitée ;
    les cartes mises à jour sont celles de métropole dont le numéro est inférieur ou égal à 7393.<br>
  </li>
  <li>16/11/2019 : <b>Attention</b>, les mises à jour des cartes effectuées jusqu'à ce jour ont été fondées sur l'édition de la carte
    et non sur les corrections effectuées sur chaque carte ;
    ainsi ces corrections ne sont pas prises en compte sur shomgt.
    A la suite d'une alerte d'une DDTM,
    une évolution de cette démarche est en cours pour actualiser les cartes comportant des corrections.</li>
  <li>14/11/2019 : une
    <a href='https://github.com/benoitdavidfr/shomgt/blob/master/docs/install.md' target='_blank'>documentation
      d'installation d'un serveur shomgt</a> a été publiée et a été mise en oeuvre avec succès par la DIRM NAMO</li>
  <li>1/11/2019 : nouvelle version de shomgt ; 
      réécriture du code et publication sur <a href='https://github.com/benoitdavidfr/shomgt' target='_blank'>Github</a> ;
      en cas de besoin intensif des API, merci d'installer votre propre serveur !!!<br>
      ce nouveau code restructuré simplifie grandement la mise à jour des cartes périmées ;
      attention la liste des couches a été modifiée ;<br>
      ajout de nouvelles cartes et mise à jour des cartes périmées.</li>
  </li>
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
par la <a href='mapwcat.php' target='_blank'>carte ici en plein écran</a>.
Elle peut aussi être utilisée dans des outils SIG comme QGis.<br>

Un <a href='https://geoapi.fr/shomgt/wms.php?service=WMS&amp;request=GetCapabilities' target='_blank'>
service WMS est aussi proposé</a>
notamment pour intégrer les cartes dans les outils SIG mais la solution recommandée pour les SIG
est l'utilisation du service de tuiles.</p>

<b>Merci d'utiliser les API avec modération.</b>
Si vous en avez un besoin intensif, merci d'installer votre propre serveur à partir des images Docker disponibles 
sur <a href='https://hub.docker.com/repository/docker/benoitdavid/shomgt3' target='_blank'>DockerHub</a> ;
plus d'informations sur <a href='https://github.com/benoitdavidfr/shomgt3' target='_blank'>Github</a>.

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
n'a pas acquis une licence d'utilisation de RasterMARINE.</p>

<h3>Une proposition décomposée en 4 éléments</h3>
<ol>
<li>Une <a href='mapwcat.php' target='_blank'>carte accessible à tous pour visualiser et télécharger les cartes du SHOM</a>
  (<a href='doc.html#mapwcat' target='doc'>Plus d'infos ici</a>)</li>
  
<li>Une API d'accès aux tuiles simple à intégrer dans une carte Leaflet ou dans QGis :
  <code><a href='tile.php' target='_blank'>https://geoapi.fr/shomgt/tile.php</a></code>
  (<a href='doc.html#tile' target='doc'>Plus d'infos ici</a>)</li>

<li>Pour les utilisateurs de SIG (comme QGis), un service WMS d'accès au contenu des cartes Shom :
  <code><a href='wms.php?SERVICE=WMS&REQUEST=GetCapabilities' target='_blank'>https://geoapi.fr/shomgt/wms.php</a></code>
  (<a href='doc.html#wms' target='doc'>Plus d'infos ici</a>)</li>

<li>Pour les serveurs installés localement, un serveur d'accès aux cartes Shom au format 7z :
  <code><a href='https://sgserver.geoapi.fr/index.php'
     target='_blank'>https://sgserver.geoapi.fr/index.php</a></code>
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

Si ce type de contrôle ne convient pas, un contrôle d'accès au moyen d'un login et d'un mot de passe en mode Web
(pour les API web) ou en mode HTTP (pour le service WMS).

<h3>Mise à jour des cartes</h3>
Les cartes sont régulièrement mises à jour dans la mesure du possible.<br>
ShomGt comporte plus de 400 cartes et des corrections y sont apportées chaque semaine.
Il n'est pas envisageable d'effectuer un remplacement à chaque correction.</p>

Le principe retenu est de mettre à jour les cartes ayant eu le plus de corrections depuis leur acquisition auprès du Shom.
Cela est effectué en consultant régulièrement le <a href='https://gan.shom.fr/diffusion/home' target='_blank'>GAN</a>.
Toutefois, ayant constaté peu d'utilisation outre-mer, les cartes en métropole sont privilégiées,
par rapport à celles dans les DOM, et enfin celles dans les COM et autres territoires outre-mer.<br>
L'objectif est d'essayer de mettre à jour les cartes ayant plus de 5 corrections en métropole
et plus de 20 corrections dans les COM.</p>

Le tableau de bord de mise à jour, utilisé pour choisir les cartes à actualiser,
est <a href='dashboard' target='_blank'>disponible ici (encore en béta)</a>.
Il liste les cartes en indiquant pour chaque carte un degré de péremption défini par le nombre de corrections non prises en compte,
divisé par 2 pour les DOM et par 4 pour les COM.
Dans le tableau, les cartes sont triées des plus périmées au moins périmées.
La date de référence du tableau est la date de consultation des GAN indiquée au dessus du tableau.
La colonne de droite du tableau indique les corrections détectées dans le GAN et non encore prises en compte dans ShomGt.<br>
Certaines cartes n'apparaissent pas dans le GAN et il n'est donc pas possible de calculer un degré de péremption,
qui vaut alors -1.<br>
</p>

Vos retours sont indispensables pour améliorer ce site.<br>
Merci aussi de m'indiquer les cartes Shom absentes qui vous seraient utiles.

<h3>Auteur</h3>
Ce site est réalisé par Benoit DAVID, MTECT/CGDD/SRI/Ecolab.<br>
Contact: <a href='mailto:contact@geoapi.fr'>contact@geoapi.fr</a><br>
Mise à jour: 13/1/2023<br>
