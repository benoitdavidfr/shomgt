<?php
/*PhpDoc:
name: tile.php
title: tile.php - webservice au standard XYZ d'accès aux GéoTIFF du Shom
doc: |
  Affichage des cartes Shom conformément au standard XYZ (voir https://en.wikipedia.org/wiki/Tiled_web_map)
  facile à utiliser dans une carte Leaflet.
  L'utilisation de l'option GET verbose=1 permet d'afficher des commentaires
  Point d'accès:
    end_point API:
      http://localhost/geoapi/gt/ws/tile.php
      https://geoapi.fr/shomgt/ws/tile.php
    end_point layer:
      http://localhost/geoapi/gt/ws/tile.php/{layer}
      https://geoapi.fr/shomgt/ws/tile.php/{layer}
    end_point tile:
      http://localhost/geoapi/gt/ws/tile.php/{layer}/{z}/{x}/{y}.png
      https://geoapi.fr/shomgt/ws/tile.php/{layer}/{z}/{x}/{y}.png
  Test:
    http://localhost/geoapi/shomgt/ws/tile.php/gtpyr/17/63957/45506.png
journal: |
  2/7/2022:
    - ajout du log
  6/6/2022:
    - mise en constantes du débrayage des caches
  30/5/2022:
    - modif initialisation Layer
  26/5/2022:
    - activation des headers de mise en cache
    - ajout du cache de tuiles
  24/5/2022:
    - correction du code affichant la version
  1/5/2022:
    création par copie de la version de shomgt2
includes: [lib/gegeom.inc.php, ../lib/log.inc.php, ../lib/config.inc.php, geotiff.inc.php, cache.inc.php, errortile.inc.php]
*/
$start = ['time'=>  microtime(true), 'memory'=> memory_get_usage(true)];
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/lib/log.inc.php';
require_once __DIR__.'/lib/gegeom.inc.php';
require_once __DIR__.'/lib/layer.inc.php';
require_once __DIR__.'/lib/cache.inc.php';
require_once __DIR__.'/lib/errortile.inc.php';
require_once __DIR__.'/../vendor/autoload.php'; // utile pour logRecord()

use Symfony\Component\Yaml\Yaml; // utile pour pour logRecord()

define ('NB_SECONDS_IN_CACHE', 0.5*24*60*60); // nb secondes en cache pour le navigateur si <> 0
//define ('NB_SECONDS_IN_CACHE', 0); // pas de mise en cache par le navigateur
define ('SERVER_TILECACHE', true); // mise en cache des tuiles sur le serveur
//define ('SERVER_TILECACHE', false); // PAS de mise en cache des tuiles sur le serveur

write_log(true); // log en base selon la var. d'env. adhoc 

// enregistrement d'un log temporaire pour afficher des infos, par ex. estimer les performances
function logRecord(array $log): void {
  // Si le log n'a pas été modifié depuis plus de 5' alors il est remplacé
  $flag_append = (is_file(__DIR__.'/log.yaml') && (time() - filemtime(__DIR__.'/log.yaml') > 5*60)) ? 0 : FILE_APPEND;
  file_put_contents(__DIR__.'/log.yaml',
    Yaml::dump([date(DATE_ATOM)=> array_merge(['path_info'=> $_SERVER['PATH_INFO'] ?? null], $log)]),
    $flag_append|LOCK_EX);
}

/*if (is_file(__DIR__.'/tileaccess.inc.php')) { // possibilité de restreindre l'accès dans certains cas 
  require_once __DIR__.'/tileaccess.inc.php';
}*/

if ($options = explode(',', $_GET['options'] ?? 'none')) {
  foreach ($options as $option) {
    if ($option == 'version') {
      header('Content-type: application/json');
      echo json_encode($VERSION);
      die();
    }
  }
}
//write_log(true);

// liste des couches exposées par le service
$layers = [
  'gt40M'=> [
    'title'=>"Planisphère SHOM GeoTIFF 1/40.000.000",
  ],
  'gt10M'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/10.000.000",
  ],
  'gt4M'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/4.000.000",
  ],
  'gt2M'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/2.000.000",
  ],
  'gt1M'=> [
   'title'=>"Cartes SHOM GeoTIFF 1/1.000.000",
  ],
  'gt500k'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/500.000",
  ],
  'gt250k'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/250.000",
  ],
  'gt100k'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/100.000",
  ],
  'gt50k'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/50.000",
  ],
  'gt25k'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/25.000",
  ],
  'gt12k'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/12.000",
  ], 
  'gt5k'=> [
    'title'=>"Cartes SHOM GeoTIFF 1/5.000",
  ], 
  'gtpyr'=> [
    'title'=>"Pyramide des cartes SHOM GeoTIFF",
  ],
  'gtaem'=> [
    'title'=>"Cartes SHOM AEM",
    'abstract'=> "Cartes Shom Action de l'Etat en Mer (AEM)",
  ],
  'gtMancheGrid'=> [
    'title'=>"Cartes SHOM MancheGrid",
    'abstract'=> "Cartes Shom MancheGrid",
  ],
  'gtZonMar'=> [
    'title'=>"Carte des zones maritimes",
    'abstract'=> "Cartes des zones maritimes",
  ],
  // étiquettes des numéros
  'num20M'=> [
    'title'=>"Nos des cartes 1/20.000.000",
  ],
  'num10M'=> [
    'title'=>"Nos des cartes 1/10.000.000",
  ],
  'num4M'=> [
    'title'=>"Nos des cartes 1/4.000.000",
  ],
  'num2M'=> [
    'title'=>"Nos des cartes 1/2.000.000",
  ],
  'num1M'=> [
    'title'=>"Nos des cartes 1/1.000.000",
  ],
  'num500k'=> [
    'title'=>"Nos des cartes 1/550.000",
  ],
  'num250k'=> [
    'title'=>"Nos des cartes 1/250.000",
  ],
  'num100k'=> [
    'title'=>"Nos des cartes 1/100.000",
  ],
  'num50k'=> [
    'title'=>"Nos des cartes 1/50.000",
  ],
  'num25k'=> [
    'title'=>"Nos des cartes 1/25.000",
  ],
  'num12k'=> [
    'title'=>"Nos des cartes 1/12.000",
  ],  
  'num5k'=> [
    'title'=>"Nos des cartes 1/5.000",
  ],  
  'numaem'=> [
    'title'=>"Nos des cartes SHOM AEM",
    'abstract'=> "Numéros des cartes Shom Action de l'Etat en Mer (AEM)",
  ],
  'numMancheGrid'=> [
    'title'=>"Nos des cartes MancheGrid",
    'abstract'=> "Numéros des cartes Shom MancheGrid",
  ],
  'numZonMar'=> [
    'title'=>"No de la carte des zones maritimes",
    'abstract'=> "Numéro de la carte Shom des zones maritimes",
  ],
];
    
$request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
  : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
$url = "$request_scheme://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

$debug = $_GET['debug'] ?? 0;
  
// end_point: tile.php
if (!isset($_SERVER['PATH_INFO'])) {
  $doc = [
    "title"=> "Serveur de tuiles des cartes GéoTIFF du Shom",
    "abstract"=> "Ce service expose des cartes du Shom sous forme de tuiles. Il est géré par le MTES pour répondre à ses besoins et son utilisation est réservée aux agents de l'Etat et de ses Etablissements publics administratifs (EPA) pour réaliser leurs missions de service public.
Plus d'informations sur <a href='https://geoapi.fr/shomgt/'>https://geoapi.fr/shomgt/</a>.",
    "contact"=> "contact@geoapi.fr",
    "doc_url"=> "https://geoapi.fr/shomgt/",
    "api_version"=> "2019-03-08",
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
    $doclayer['attribution'] = "(c) <a href='https://www.shom.fr/'>SHOM</a>";
    $doc['layers'][] = $doclayer;
  }
  header('Access-Control-Allow-Origin: *');
  header('Content-type: application/json; charset="utf-8"');
  die(json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// end_point: tile.php/{layer}
if (preg_match('!^/([^/]*)$!', $_SERVER['PATH_INFO'], $matches)) {
  $lyrname = $matches[1];
  if (!isset($layers[$lyrname])) {
    header('HTTP/1.1 404 Not Found');
    header('Content-type: text/plain; charset="utf-8"');
    die("Erreur: couche $lyrname inexistante, voir la liste des couches sur $url\n");
  }
  $doclayer = [
    "name"=> $lyrname,
    "title"=> $layers[$lyrname]['title'],
    "url"=> "$url/$lyrname",
    "tiles"=> "$url/$lyrname/{z}/{x}/{y}.png",
  ];
  if (isset($layers[$lyrname]['abstract']))
    $doclayer['abstract'] = $layers[$lyrname]['abstract'];
  $doclayer['format'] = 'image/png';
  $doclayer['minZoom'] = 0;
  $doclayer['maxZoom'] = 18;
  $doclayer['attribution'] = "(c) <a href='https://www.shom.fr/'>SHOM</a>";
  header('Access-Control-Allow-Origin: *');
  header('Content-type: application/json; charset="utf-8"');
  die(json_encode($doclayer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// end_point: tile.php/{layer}/{z}/{x}/{y}.png
if (!preg_match('!^/([^/]*)/(\d*)/(\d*)/(\d*)\.png$!', $_SERVER['PATH_INFO'], $matches)) {
  header('HTTP/1.1 400 Bad Request');
  header('Content-type: text/plain; charset="utf-8"');
  die("Erreur: requête non reconnue, voir la documentation sur $url\n");
}

$lyrname = $matches[1];
$z = (int)$matches[2];
$x = (int)$matches[3];
$y = (int)$matches[4];

if (!isset($layers[$lyrname])) {
  header('HTTP/1.1 404 Not Found');
  header('Content-type: text/plain; charset="utf-8"');
  die("Erreur: couche $lyrname inexistante, voir la liste des couches sur $url\n");
}

if (SERVER_TILECACHE && !$debug)
  Cache::readAndSend($lyrname, $z, $x, $y);

try {
  $ebox = Zoom::tileEBox($z, $x, $y)->geo('WebMercator')->proj('WorldMercator');
  $grImage = new GeoRefImage($ebox); // création de l'image Géoréférencée
  $grImage->create(256, 256, true); // création d'une image GD transparente
  Layer::initFromShomGt(__DIR__.'/../data/shomgt'); // Initialisation à partir du fichier shomgt.yaml
  $layers = Layer::layers();
  if (!isset($layers[$lyrname])) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-type: text/plain; charset="utf-8"');
    die("Erreur: couche $lyrname inexistante en interne\n");
  }
  $layers[$lyrname]->map($grImage, $debug, $z);
  $grImage->savealpha(true);
} catch (Exception $e) {
  sendErrorTile("$lyrname/$z/$x/$y", $e->getMessage());
}

if (!$debug) {
  if (NB_SECONDS_IN_CACHE) { // Mise en cache par le navigateur
    header('Cache-Control: max-age='.NB_SECONDS_IN_CACHE); // mise en cache pour NB_SECONDS_IN_CACHE s
    header('Expires: '.date('r', time() + NB_SECONDS_IN_CACHE)); // mise en cache pour NB_SECONDS_IN_CACHE s
    header('Last-Modified: '.date('r'));
  }
  header('Content-type: image/png');
  // envoi de l'image
  imagepng($grImage->image());
  flush();
  //logRecord(['ellapsed_time'=> microtime(true)-$start['time'], 'memory_usage'=> memory_get_usage(true)-$start['memory']]);
  try {
    if (SERVER_TILECACHE)
      Cache::write($lyrname, $z, $x, $y, $grImage->image());
  } catch (Exception $e) {
    sendErrorTile("$lyrname/$z/$x/$y", $e->getMessage());
  }
}
else {
  $href = "$url/$lyrname/$z/$x/$y.png";
  echo "<a href='$href'><img src='$href'></a>\n";
}
die();
