<?php
/*PhpDoc:
name: histo.php
title: master / histo.php - listing de l'historique des cartes dans incoming
functions:
classes:
doc: |
  Fabrique l'historique des cartes livrées sous la forme
    [mapid => [mdDate => ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin] | "Suppression de la carte"]]
  Affichage Yaml et enregistrement dans histo.pser
  Si histo.pser existe alors il est affiché.
journal: |
  5/1/2021:
    - utilisation de ../lib/store.inc.php
  31/12/2020:
    - refonte
includes: [../lib/store.inc.php]
*/

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/store.inc.php';

use Symfony\Component\Yaml\Yaml;

// noms de répertoire de incoming à exclure de l'historique
define('EXCLUDED_DELIVNAMES', ['201911cartesAEM','20201226TEST-arriere','20201226TEST-avant']);

date_default_timezone_set('Europe/Paris');


echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>histo</title></head><body><pre>\n";
if (!($histo = @file_get_contents(__DIR__.'/histo.pser'))) {
  $histo = []; // [mapid => [mdDate => ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin] | "Suppression de la carte"]]
  foreach (SevenZipMap::listOfDeliveries() as $delivName) { // $delivName correspond à une livraison
    if (in_array($delivName, EXCLUDED_DELIVNAMES))
      continue;
    echo "$delivName<br>\n";
    foreach (SevenZipMap::listOfmaps($delivName) as $mapz) { // $mapz -> une carte zippée
      $mdiso19139 = $mapz->mdiso19139();
      $mapnum = $mapz->mapnum();
      $histo["FR$mapnum"][$mdiso19139['mdDate']] = [
        'edition'=> $mdiso19139['edition'],
        'lastUpdate'=> $mdiso19139['lastUpdate'],
        'path' => "incoming/$mapz",
      ];
      ksort($histo["FR$mapnum"]);
    }
    foreach (array_keys(SevenZipMap::obsoleteMaps($delivName)) as $mapToDelete) {
      $update = substr($delivName, 0, 4).'-'.substr($delivName, 4, 2).'-'.substr($delivName, 6);
      $histo[$mapToDelete][$update] = "Suppression de la carte";
    }
  }
  ksort($histo);
  file_put_contents(__DIR__.'/histo.pser', serialize($histo));
}
else {
  $histo = unserialize($histo);
}
echo "title: historique des cartes détenues dans incoming classées par id de carte et par date des MD\n";
echo Yaml::dump(['histo'=> $histo], 5, 2);
