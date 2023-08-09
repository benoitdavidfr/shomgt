<?php
/*PhpDoc:
name: index2.php
title: index2.php - texte de la réelle page d'accueil
includes: [ lib/accesscntrl.inc.php ]
doc: |
journal: |
  10/8/2023:
    report dans Access du message lors du refus d'accès
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
  header('HTTP/1.1 403 Forbidden');
  die(str_replace('{adip}', $_SERVER['REMOTE_ADDR'], Access::FORBIDDEN_ACCESS_MESSAGE));
}
else {
  die(file_get_contents(__DIR__.'/welcome.html'));
}

