<?php
/*PhpDoc:
name: geojson.php
title: geojson.php - génération GeoJSON du catalogue des cartes Shom ou d'une carte particulière
doc: |
  génération d'un geojson à partir du fichier mapcat.pser en filtrant sur l'échelle de la carte
  Les cartouches ne sont fournis ni dans les propriétés ni dans le géométrie
  sauf lorsque la carte ne comporte pas d'espace principale auquel cas ils le sont
journal: |
  14/12/2020:
    passage en catv2
  28/10/2019:
    suppression de la gestion de l'historique
  16/12/2018
    prise en compte évols mapcat.inc.php
  12/12/2018
    ajout appel cli
  11/12/2018
    reprise de shomgtcat.php
includes: [mapcat.inc.php]
*/
require_once __DIR__.'/mapcat.inc.php';

if (php_sapi_name()=='cli') {
  $sdmin = ($argc > 1) ? $argv[1] : null;
  $sdmax = ($argc > 2) ? $argv[2] : null;
}
else {
  $sdmin = $_GET['sdmin'] ?? null;
  $sdmax = $_GET['sdmax'] ?? null;
  $mapid = $_GET['id'] ?? null;
}

//echo "<pre>doc="; print_r($doc); die();

header('Content-type: application/json; charset="utf8"');
//header('Content-type: text/plain; charset="utf8"');
$nbre = 0;

echo '{"type":"FeatureCollection","features":[',"\n";
if ($mapid) {
  echo json_encode(MapCat::maps()[$mapid]->geojson(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
}
else {
  foreach (MapCat::maps() as $id => $map) {
    $scaleD = $map->scaleDenAsInt();
    if ($sdmax && ($scaleD > $sdmax))
      continue;
    if ($sdmin && ($scaleD <= $sdmin))
      continue;
    
    if ($nbre++ <> 0)
      echo ",\n";
    echo json_encode($map->geojson(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
  }
}

echo "\n]}\n";
