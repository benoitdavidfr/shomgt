<?php
/*PhpDoc:
title: maps.php - point d'accès de l'API de maps
name: maps.php
classes:
doc: |
  Tous les calculs sont effectués dans le CRS des cartes Shom qui est WGS84 World Mercator, abrévié en WoM.
  test:
    http://localhost:8081/index.php/collections/gt50k/showmap?bbox=1000,5220,1060,5280&width=6000&height=6000
journal: |
  28-31/7/2022:
    - correction suite à analyse PhpStan level 6
  25/6/2022:
    - ajout deletedZones
  30/5/2022:
    - modif initialisation Layer
  29/4/2022:
    - gestion de la superposition de plusieures couches
  25/4/2022:
    - renommage en maps.php
    - scission de layer.inc.php et geotiff.inc.php
  23-24/4/2022:
    - modif. en maps
  22/4/2022:
    - création
includes:
  - ../lib/layer.inc.php
  - ../lib/accesscntrl.inc.php
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../lib/layer.inc.php';
require_once __DIR__.'/../lib/accesscntrl.inc.php';

use Symfony\Component\Yaml\Yaml;

$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

//print_r($_GET); die("map.php");
if (Access::cntrlFor('wms') && !Access::cntrl()) {
  header('HTTP/1.1 403 Forbidden');
  header('Content-type: text/plain; charset="utf-8"');
  die("Accès interdit");
}

function coordDM(float $coord): string { // affichage en degrés minutes décimales avec 2 chiffres significatifs
  $coord = sprintf("%0d°%.2f'", floor($coord), ($coord-floor($coord))*60);
  return str_replace('.', ',', $coord);
}

/** @param TPos $pos */
function latLonDM(array $pos): string { // affichage lat,lon dans le format de l'exemple
  // example: 43°18,9'N - 10°07,9'E
  $latDM = coordDM(abs($pos[1])).($pos[1]<0 ? 'S' : 'N');
  $lonDM = coordDM(abs($pos[0])).($pos[0]<0 ? 'W' : 'E');
  return "$latDM - $lonDM";
}

// extraction des coins des rectangles englobants définis dans un array de Features GeoJSON, renvoit un array de Features
/**
 * @param array<int, TGeoJsonFeature> $rects
 * @return array<int, TGeoJsonFeature>
*/
function cornersOfRects(string $lyrname, array $rects): array {
  static $cornerString = ['SW','SE','NE','NW'];
  $ptsGeojson = [];
  foreach ($rects as $feature) {
    foreach ($feature['geometry']['coordinates'][0] as $i => $pos) {
      if ($i <> 4)
        $ptsGeojson[] = [
          'type'=> 'Feature',
          'properties'=> [
            'layer'=> $lyrname,
            'gtname'=> $feature['properties']['name'],
            'corner'=> $cornerString[$i],
            'latLonDM'=> latLonDM($pos),
          ],
          'geometry'=> [
            'type'=> 'Point',
            'coordinates'=> $pos,
          ]
        ];
    }
  }
  return $ptsGeojson;
}

// classe regroupant qqs méthodes statiques
class GtMaps {
  const ErrorUnknownCRS = 'GtMaps::ErrorUnknownCRS';
  const ErrorImageSaveAlpha = 'GtMaps::ErrorImageSaveAlpha';
  const HttpErrorMessage = [
    400 => 'Bad Request',
    404 => 'Not Found',
    500 => 'Internal Server Error',
  ];

  static function error(int $httpCode, string $message, string $scode=''): never {
    header(sprintf('HTTP/1.1 %d %s', $httpCode, self::HttpErrorMessage[$httpCode] ?? "Undefined for $httpCode"));
    header('Content-type: application/json; charset="utf8"');
    if (!$scode)
      die(json_encode($message,  JSON_UNESCAPED_UNICODE));
    else
      die(json_encode(['code'=> $scode, 'message'=> $message], JSON_UNESCAPED_UNICODE));
  }
  
  static function landingPage(): never {
    die("Landing Page maps/index.php\n");
  }
  
  static function listOfLayers(): never {
    header('Access-Control-Allow-Origin: *');
    header('Content-type: application/json; charset="utf8"');
    //header('Content-type: text/plain; charset="utf8"');
    die(json_encode(
      array_keys(Layer::layers()),
      JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
  
  static function describeLayer(string $lyrname): never {
    $layers = Layer::layers();
    if (!isset($layers[$lyrname]))
      self::error(404, "$lyrname not found");
    header('Content-type: application/json; charset="utf8"');
    die(json_encode($layers[$lyrname]->asArray(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
  
  private static function eboxToWoM(string $crs, \gegeom\EBox $ebox): \gegeom\EBox { // projette l'ebox en World Mercator
    switch ($crs) {
      case 'EPSG:3395': return $ebox; // WGS84 World Mercator
      case 'EPSG:3857': return $ebox->geo('WebMercator')->proj('WorldMercator'); // Web Mercator
      case 'EPSG:4326': return $ebox->geo('LatLonDd')->proj('WorldMercator'); // WGS84 lat,lon
      case 'CRS:84': return $ebox->geo('LonLatDd')->proj('WorldMercator'); // WGS84 lon,lat
      default: throw new SExcept("CRS '$crs' non pris en charge", self::ErrorUnknownCRS);
    }
  }
  
  // envoie au navigateur l'image correspondant aux paramètres en GET et à la liste des couches passée en paramètre
  /** @param array<int, string> $lyrnames */
  static function map(array $lyrnames): void {
    $layers = Layer::layers();
    foreach ($lyrnames as $lyrname) {
      if (!isset($layers[$lyrname]))
        self::error(404, "Layer '$lyrname' not found");
    }
    //echo "<pre>"; print_r($layer); echo "</pre>\n";
    $crs = $_GET['crs'] ?? 'EPSG:3395'; // par défaut je considère que je suis en World Mercator
    if (isset($_GET['bbox']) && $_GET['bbox']) {
      if (count(explode(',', $_GET['bbox'])) <> 4)
        self::error(400, "Le paramètre bbox ne définit pas 4 nombres");
      $ebox =  new \gegeom\EBox($_GET['bbox']);
    }
    else {
      $ebox = new \gegeom\EBox;
      foreach ($lyrnames as $lyrname)
        $ebox->union($layers[$lyrname]->ebox());
    }
    //echo $ebox;
    $ebox = GtMaps::eboxToWoM($crs, $ebox); // l'ebox est transformé en World Mercator
  
    $width = (isset($_GET['width']) && $_GET['width']) ? intval($_GET['width']) : 1200;
    if (($width < 10) || ($width > 4096))
      self::error(400, "Paramètre width='$_GET[width]' incorrect");
    $height = (isset($_GET['height']) && $_GET['height']) ? intval($_GET['height']) : 600;
    if (($height < 10) || ($height > 4096))
      self::error(400, "Paramètre height='$_GET[height]' incorrect");
    $debug = $_GET['debug'] ?? false;
    $grImage = new GeoRefImage($ebox); // création de l'image Géoréférencée
    $grImage->create($width, $height, true); // création d'une image GD transparente
  
    foreach ($lyrnames as $lyrname) {
      $layers[$lyrname]->map($grImage, $debug);
    }
    //die("ligne ".__LINE__."\n");
    $grImage->savealpha(true);
    if (!$debug)
      header('Content-type: image/png');
    imagepng($grImage->image());
  }

  /** @param array<int, string> $lyrnames */
  static function items(array $lyrnames): void { // silhouettes des GéoTiffs
    //echo "GtMaps::items(",implode($lyrnames),")<br>\n";
    $features = [];
    if ($bbox = $_GET['bbox'] ?? ($_POST['bbox'] ?? null)) {
      $bbox = new \gegeom\GBox($bbox); // en coord. géo.
    }
    foreach ($lyrnames as $lyrname) {
      if (!($layer = Layer::layers()[$lyrname] ?? null))
        self::error(404, "$lyrname not found");
      $features = array_merge($features, $layer->items($lyrname, $bbox));
    }
    $fc = array_merge(
      [
        'type'=> 'FeatureCollection',
        'features'=> $features,
      ],
      $bbox ? ['bbox'=> $bbox->asGeoJsonBbox()] : [],
      //['bboxParam'=> $_GET['bbox'] ?? $_POST['bbox'] ?? null]
    );
    header('Access-Control-Allow-Origin: *');
    header('Content-type: application/json; charset="utf8"');
    die(json_encode($fc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); 
  }
  
  /** @param array<int, string> $lyrnames */
  static function corners(array $lyrnames): void { // coins des silhouettes des GéoTiffs
    $features = [];
    if ($bbox = $_GET['bbox'] ?? ($_POST['bbox'] ?? null)) {
      $bbox = new \gegeom\GBox($bbox); // en coord. géo.
    }
    foreach ($lyrnames as $lyrname) {
      if (!($layer = Layer::layers()[$lyrname] ?? null))
        self::error(404, "$lyrname not found");
      $features = array_merge($features, cornersOfRects($lyrname, $layer->items($lyrname, $bbox)));
    }
    $fc = array_merge(
      [
        'type'=> 'FeatureCollection',
        'features'=> $features,
      ],
      $bbox ? ['bbox'=> $bbox->asGeoJsonBbox()] : []
    );
    header('Access-Control-Allow-Origin: *');
    header('Content-type: application/json; charset="utf8"');
    die(json_encode($fc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); 
  }
  
  /** @param array<int, string> $lyrnames */
  static function deletedZones(array $lyrnames): void { // coins des silhouettes des GéoTiffs
    $features = [];
    if ($bbox = $_GET['bbox'] ?? ($_POST['bbox'] ?? null)) {
      $bbox = new \gegeom\GBox($bbox); // en coord. géo.
    }
    foreach ($lyrnames as $lyrname) {
      if (!($layer = Layer::layers()[$lyrname] ?? null))
        self::error(404, "$lyrname not found");
      $features = array_merge($features, $layer->deletedZones($lyrname, $bbox));
    }
    $fc = array_merge(
      [
        'type'=> 'FeatureCollection',
        'features'=> $features,
      ],
      $bbox ? ['bbox'=> $bbox->asGeoJsonBbox()] : [],
      //['bboxParam'=> $_GET['bbox'] ?? $_POST['bbox'] ?? null]
    );
    header('Access-Control-Allow-Origin: *');
    header('Content-type: application/json; charset="utf8"');
    die(json_encode($fc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); 
  }
  
  // gère un formulaire HTML pour appeller map
  /** @param array<int, string> $lyrnames */
  static function showmap(array $lyrnames): void {
    $layers = Layer::layers();
    foreach ($lyrnames as $lyrname) {
      if (!isset($layers[$lyrname]))
        die("$lyrname not found<br>\n");
    }
    $layer = $layers[$lyrnames[0]];

    $crs = $_GET['crs'] ?? 'EPSG:3395';
    $bboxkm = $_GET['bbox'] ?? '';
    if (!$bboxkm) {
      switch($crs) {
        case 'EPSG:3395': { $bboxkm = $layer->ebox(); break; }
        case 'EPSG:3857': { $bboxkm = $layer->ebox()->geo('WorldMercator')->proj('WebMercator'); break; }
        case 'CRS:84': { $bboxkm = $layer->ebox()->geo('WorldMercator')->proj('LonLatDd'); break; }
        case 'EPSG:4326': { $bboxkm = $layer->ebox()->geo('WorldMercator')->proj('LatLonDd'); break; }
      }
      if (in_array($crs, ['EPSG:3395','EPSG:3857']))
        $bboxkm = implode(',', [
          round($bboxkm->west()/1000), round($bboxkm->south()/1000),
          round($bboxkm->east()/1000), round($bboxkm->north()/1000)]);
        else
          $bboxkm = implode(',', [$bboxkm->west(), $bboxkm->south(),$bboxkm->east(), $bboxkm->north()]);
    }
    $width = (isset($_GET['width']) && $_GET['width']) ? $_GET['width'] : 1200;
    $height = (isset($_GET['height']) && $_GET['height']) ? $_GET['height'] : 600;
    echo "<form><table border=1>\n";
    echo "<tr><td>crs</td><td><select name='crs'>\n";
    echo "<option value='EPSG:3395' ",$crs=='EPSG:3395' ? 'selected' : '',">WorldM</option>\n";
    echo "<option value='EPSG:3857' ",$crs=='EPSG:3857' ? 'selected' : '',">WebM</option>\n";
    echo "<option value='CRS:84' ",$crs=='CRS:84' ? 'selected' : '',">lon,lat</option>\n";
    echo "<option value='EPSG:4326' ",$crs=='EPSG:4326' ? 'selected' : '',">lat,lon</option>\n";
    echo "</select></td>\n";
    echo "<td>bbox(km|°)</td><td><input type='text' name='bbox' size=40 value='$bboxkm'></td>\n";
    echo "<td>width</td><td><input type='text' name='width' size=10 value='$width'></td>\n";
    echo "<td>height</td><td><input type='text' name='height' size=10 value='$height'></td>\n";
    echo "<td colspan=2><center><input type='submit' label='go'></center></td></tr>\n";
    echo "</table></form>\n";
  
    if (in_array($crs,['EPSG:3395','EPSG:3857'])) {
      $bbox = explode(',', $bboxkm);
      for ($i=0; $i<4; $i++) $bbox[$i] *= 1000;
      $bbox = implode(',', $bbox);
    }
    else {
      $bbox = $bboxkm;
    }
    //echo "<pre>"; print_r($_SERVER); 
    $url = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/collections/".implode(',',$lyrnames)."/map"
      ."?crs=$crs&amp;bbox=$bbox&amp;width=$width&amp;height=$height";
    //echo "url=$url\n";
    echo "<table border=1><tr><td><img src='$url'></td></tr></table>\n";
  }
}

try {
  if (in_array($_SERVER['PATH_INFO'] ?? '', ['', '/'])) { // appel sans paramètre 
    $options = explode(',', $_GET['options'] ?? '');
    if (in_array('version', $options)) {
      header('Content-type: application/json');
      echo json_encode($VERSION);
      die();
    }
    else {
      GtMaps::landingPage();
    }
  }

  Layer::initFromShomGt(__DIR__.'/../data/shomgt'); // Initialisation à partir du fichier shomgt.yaml

  if ($_SERVER['PATH_INFO'] == '/collections') { // liste des couches 
    GtMaps::listOfLayers();
  }

  if (preg_match('!^/collections/([^/]+)$!', $_SERVER['PATH_INFO'], $matches)) { // définition d'une couche 
    GtMaps::describeLayer($matches[1]);
  }

  if (preg_match('!^/collections/([^/]+)/map$!', $_SERVER['PATH_INFO'], $matches)) { // affichage d'un extrait de la/des couche(s)
    GtMaps::map(explode(',', $matches[1]));
  }

  if (preg_match('!^/collections/([^/]+)/items$!', $_SERVER['PATH_INFO'], $matches)) { // rectangles des GeoTiff en GeoJSON
    GtMaps::items(explode(',', $matches[1]));
  }

  if (preg_match('!^/collections/([^/]+)/corners$!', $_SERVER['PATH_INFO'], $matches)) { // coins des GeoTiff en GeoJSON
    GtMaps::corners(explode(',', $matches[1]));
  }

  if (preg_match('!^/collections/([^/]+)/deletedZones$!', $_SERVER['PATH_INFO'], $matches)) { // zones effacées des GeoTiff en GeoJSON
    GtMaps::deletedZones(explode(',', $matches[1]));
  }

  // Test de /collections/{collectionId}/map par affichage d'un formulaire de saisie des paramètres
  if (preg_match('!^/collections/([^/]+)/showmap$!', $_SERVER['PATH_INFO'], $matches)) {
    GtMaps::showmap(explode(',', $matches[1]));
  }
}
catch (SExcept $e) {
  GtMaps::error(500, $e->getMessage(), $e->getSCode());
}
catch (Exception $e) {
  GtMaps::error(500, $e->getMessage());
}
