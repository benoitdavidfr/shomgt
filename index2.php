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
require_once __DIR__.'/lib/accesscntrl.inc.php';

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
else {
  die(file_get_contents(__DIR__.'/welcome.html'));
}
?>

