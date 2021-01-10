<?php
/*PhpDoc:
name: histo.php
title: master / histo.php - listing de l'historique des cartes dans incoming
functions:
classes:
doc: |
  Fabrique l'historique des cartes livrées sous la forme
    [ mapid => [
      'title' => titre,
      'scaleDenominator' => scaleDenominator,
      'mapsFrance'=> liste de codes ISO,
      'georss:polygon'=> polygone englobant en format georss:polygon comme chaine 
      'histo' => [
        mdDate =>
          ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin]
          | "Suppression de la carte"
        ]
      ]
    ]
  Affichage Yaml et enregistrement dans histo.pser
  Si histo.pser existe alors il est affiché.
journal: |
  7/1/2021:
    - ajout caractéristiques de la carte en provenance de ../cat2
  5/1/2021:
    - utilisation de ../lib/store.inc.php
  31/12/2020:
    - refonte
includes: [../lib/store.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/store.inc.php';
require_once __DIR__.'/../cat2/catapi.inc.php';

use Symfony\Component\Yaml\Yaml;

// noms de répertoire de incoming à exclure de l'historique
define('EXCLUDED_DELIVNAMES', [/*'201911cartesAEM',*/'20201226TEST-arriere','20201226TEST-avant']);

date_default_timezone_set('Europe/Paris');

function simplifyMapCat(?MapCat $map): array {
  if (!$map) return [];
  $mapa = $map->asArray();
  // s'il n'y a pas d'espace principal, scaleDenominator est le plus grand des dénominateurs d'échelle des cartouches
  $mapa['scaleDenominator'] = $map->scaleDenominator();
  if ($map->bbox()) { // il y a un espace principal
    $bbox = $map->bbox();
  }
  else { // calcul du rectangle englobant des cartouches
    $bbox = new GjBox;
    foreach ($map->insetMaps() as $imap) {
      $bbox->bound($imap->bbox()->ws());
      $bbox->bound($imap->bbox()->en());
      //echo 'bbox='; print_r($bbox);
    }
  }
  $ws = $bbox->ws();
  $en = $bbox->en();
  $mapa['georss:polygon'] =
    sprintf('%.2f %.2f %.2f %.2f %.2f %.2f %.2f %.2f %.2f %.2f', 
      $ws[1], $ws[0], $en[1], $ws[0], $en[1], $en[0], $ws[1], $en[0], $ws[1], $ws[0]);
  return $mapa;
}

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>histo</title></head><body><pre>\n";
if (!($histo = @file_get_contents(__DIR__.'/histo.pser'))) {
  $histo = []; // [mapid => [mdDate => ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin] | "Suppression de la carte"]]
  foreach (SevenZipMap::listOfDeliveries() as $delivName) { // $delivName correspond à une livraison
    if (in_array($delivName, EXCLUDED_DELIVNAMES))
      continue;
    //if ($delivName <> '20170717') continue;
    echo "$delivName<br>\n";
    foreach (SevenZipMap::listOfmaps($delivName) as $mapz) { // $mapz -> une carte zippée
      $mapnum = $mapz->mapnum();
      $mapid = "FR$mapnum";
      //if ($mapnum <> '6980') continue;
      $mdiso19139 = $mapz->mdiso19139();
      if (!isset($histo[$mapid])) {
        $mapcat = simplifyMapCat(CatApi::mapById($mapid));
        $histo[$mapid] =
          (isset($mapcat['title']) ? ['title'=> $mapcat['title']] : [])
        + (isset($mapcat['scaleDenominator']) ? ['scaleDenominator'=> $mapcat['scaleDenominator']] : [])
        + (isset($mapcat['mapsFrance']) ? ['mapsFrance'=> $mapcat['mapsFrance']] : [])
        + (isset($mapcat['georss:polygon']) ? ['georss:polygon'=> $mapcat['georss:polygon']] : []);
      }
      $histo[$mapid]['histo'][$mdiso19139['mdDate']] = [
        'edition'=> $mdiso19139['edition'],
        'lastUpdate'=> $mdiso19139['lastUpdate'],
        'path' => "incoming/$mapz",
      ];
      ksort($histo["FR$mapnum"]['histo']);
    }
    foreach (array_keys(SevenZipMap::obsoleteMaps($delivName)) as $mapToDelete) {
      $update = substr($delivName, 0, 4).'-'.substr($delivName, 4, 2).'-'.substr($delivName, 6);
      $histo[$mapToDelete]['histo'][$update] = "Suppression de la carte";
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
