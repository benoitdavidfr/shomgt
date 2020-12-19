<?php
/*PhpDoc:
name: tilenum.php
title: tilenum.php - webservice au standard XYZ d'affichage des num des cartes Shom
doc: |
  Affichage des num. des cartes Shom conformément au standard XYZ (voir https://en.wikipedia.org/wiki/Tiled_web_map)
  facile à utiliser dans une carte Leaflet.
  L'utilisation de l'option GET verbose=1 permet d'afficher des commentaires
  Point d'accès:
    end_point API:
      http://localhost/geoapi/shomgt/cat2/tilenum.php
      https://geoapi.fr/shomgt/cat2/tilenum.php
    end_point layer:
      http://localhost/geoapi/shomgt/cat2/tilenum.php/{layer}
      https://geoapi.fr/shomgt/cat2/tilenum.php/{layer}
    end_point tile:
      http://localhost/geoapi/shomgt/cat2/tilenum.php/{layer}/{z}/{x}/{y}.png
      https://geoapi.fr/shomgt/cat2/tilenum.php/{layer}/{z}/{x}/{y}.png
  Test:
    http://localhost/geoapi/shomgt/cat2/tilenum.php/cat1e6-1e7/17/63957/45506.png

  Nécessite la définition de méthodes maketile() sur les classes MapCat et Wfs
journal: |
  16/12/2020:
    création
includes: [mapcat.inc.php, wfs.php]
*/
require_once __DIR__.'/mapcat.inc.php';
require_once __DIR__.'/wfs.php';

$verbose = false;

// liste des couches exposées par le service
$layers = [
  // étiquettes des numéros
  'cat{sdmin}-{sdmax}'=> [
    'title'=>"Nos des cartes du catalogue dont le dénominateur d'échelle sd est {sdmin} <= {sd} < {sdmax}",
  ],
  'wfs{sdmin}-{sdmax}'=> [
    'title'=>"Nos des cartes du WFS dont le dénominateur d'échelle sd est {sdmin} <= {sd} < {sdmax}",
  ],
];
    
$request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
  : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
$url = "$request_scheme://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

// end_point: tilenum.php
if (!isset($_SERVER['PATH_INFO'])) {
  $doc = [
    "title"=> "Serveur de tuiles des numéros de cartes GéoTIFF du Shom",
    "abstract"=> "Ce service expose les numéros de cartes du Shom sous forme de tuiles.
Plus d'informations sur <a href='https://geoapi.fr/gt/'>https://geoapi.fr/gt/</a>.",
    "contact"=> "contact@geoapi.fr",
    "doc_url"=> "https://geoapi.fr/gt/",
    "api_version"=> "2020-12-15",
    "end_points"=> [
      "tile.php"=> [
        "GET"=> "documentation de l'API"
      ],
      "tile.php/{layer}"=> [
        "GET"=> "documentation de la couche {layer}"
      ],
      "tile.php/{layer}/{z}/{x}/{y}.[png]"=> [
        "GET"=> "tuile zoom {z} colonne {x} ligne {y} de la couche {layer} en format png"
      ],
    ],
    "layers"=> [],
  ];
  foreach ($layers as $layername => $layer) {
    $doclayer = [
      "name"=> $layername,
      "title"=> $layer['title'],
      "url"=> "$url/$layername",
      "tiles"=> "$url/$layername/{z}/{x}/{y}.png",
    ];
    if (isset($layer['abstract']))
      $doclayer['abstract'] = $layer['abstract'];
    $doclayer['format'] = 'image/png';
    $doclayer['minZoom'] = 0;
    $doclayer['maxZoom'] = 18;
    $doc['layers'][] = $doclayer;
  }
  header('Content-type: application/json; charset="utf-8"');
  die(json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// end_point: tile.php/{layer}
if (preg_match('!^/([^/]*)$!', $_SERVER['PATH_INFO'], $matches)) {
  $lyrname = $matches[1];
  if (!preg_match('!^(cat|wfs)([\de]+)(-([\de]+)?)?$!', $lyrname, $matches)) {
    header('HTTP/1.1 404 Not Found');
    header('Content-type: text/plain; charset="utf-8"');
    die("Erreur: couche $lyrname inexistante, voir la liste des couches sur $url\n");
  }
  $type = $matches[1];
  $sdmin = $matches[2];
  $sdmax = $matches[4] ?? null;
  header('Content-type: application/json; charset="utf-8"');
  die(json_encode([
    "name"=> $lyrname,
    "title"=> "Nos des cartes du ".($type=='cat' ? 'catalogue' : 'WFS')
      ." dont le dén. d'échelle {sd} est $sdmin <= {sd}".($sdmax ? " < $sdmax" : ''),
    "url"=> "$url/$lyrname",
    "tiles"=> "$url/$lyrname/{z}/{x}/{y}.png",
    'format'=> 'image/png',
    'minZoom'=> 0,
    'maxZoom'=> 18,
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// end_point: tilenum.php/{layer}/{z}/{x}/{y}.png
if (!preg_match('!^/([^/]*)/(\d*)/(\d*)/(\d*)\.(png|html)$!', $_SERVER['PATH_INFO'], $matches)) {
  header('HTTP/1.1 400 Bad Request');
  header('Content-type: text/plain; charset="utf-8"');
  die("Erreur: requête non reconnue, voir la documentation sur $url\n");
}

$lyrname = $matches[1];
$z = (int)$matches[2];
$x = (int)$matches[3];
$y = (int)$matches[4];
$fmt = $matches[5];

if (!preg_match('!^(cat|wfs)([\de]+)(-([\de]+)?)?$!', $lyrname, $matches)) {
  header('HTTP/1.1 404 Not Found');
  header('Content-type: text/plain; charset="utf-8"');
  die("Erreur: couche $lyrname inexistante, voir la liste des couches sur $url\n");
}

$type = $matches[1];
$sdmin = $matches[2];
$sdmax = $matches[4] ?? null;

try {
  if ($type == 'cat') {
    $image = MapCat::maketile($sdmin, $sdmax, Zoom::tileEBox($z, $x, $y), ['zoom'=>$z]);  
  }
  else {
    $image = Wfs::maketile($sdmin, $sdmax, Zoom::tileEBox($z, $x, $y), ['zoom'=>$z]);  
  }
} catch (Exception $e) {
  sendErrorTile("$lyrname/$z/$x/$y", $e->getMessage());
}
if ($fmt == 'html') {
  $href = "$url/$lyrname/$z/$x/$y.png";
  echo "<a href='$href'><img src='$href'></a>\n";
}
else {
  if (1) { // Mise en cache
    $nbDaysInCache = 0.5;
    $nbSecondsInCache = $nbDaysInCache*24*60*60;
    //$nbSecondsInCache = 1;
    header('Cache-Control: max-age='.$nbSecondsInCache); // mise en cache pour $nbDaysInCache jours
    header('Expires: '.date('r', time() + $nbSecondsInCache)); // mise en cache pour $nbDaysInCache jours
    header('Last-Modified: '.date('r'));
  }
  header('Content-type: image/png');
  // envoi de l'image
  imagepng($image);
  flush();
  /*try {
    Cache::write($lyrname, $z, $x, $y, $image);
  } catch (Exception $e) {
  }*/
  imagedestroy($image);
}
die();
