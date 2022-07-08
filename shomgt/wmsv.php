<?php
/*PhpDoc:
name: wmsv.php
title: wmsv.php - service WMS-V de shomgt
classes:
doc: |
journal: |
  8/7/2022: fork de wms.php
includes:
  - lib/coordsys.inc.php
  - lib/gebox.inc.php
  - lib/wmsserver.inc.php
  - lib/vectorlayer.inc.php
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib/coordsys.inc.php';
require_once __DIR__.'/lib/gebox.inc.php';
require_once __DIR__.'/lib/wmsserver.inc.php';
require_once __DIR__.'/lib/vectorlayer.inc.php';

use Symfony\Component\Yaml\Yaml;

//die("Fin ligne ".__LINE__."\n");

// écrit dans le fichier de log les params de l'appel, notamment por connaitre et reproduire les appels effectués par QGis
WmsServer::log("appel avec REQUEST_URI=$_SERVER[REQUEST_URI]\n");
WmsServer::log("appel avec GET=".json_encode($_GET, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

/*PhpDoc: classes
name: class WmsvShomGt
title: class WmsvShomGt - classe implémentant les fonctions du WMS-V de ShomGt
doc: |
  La classe WmsShomGt hérite de la classe WmsServer qui gère le protocole WMS.
  Le script appelle WmsServer::process() qui appelle les méthodes WmsvShomGt::getCapabilities() ou WmsvShomGt::getMap()
*/
class WmsvShomGt extends WmsServer {
  // méthode GetCapabilities du serveur Shomgt
  function getCapabilities(string $version='') {
    header('Content-Type: text/xml');
    $request_scheme = $_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
    die(str_replace(
        '{OnlineResource}',
        "$request_scheme://$_SERVER[SERVER_NAME]$_SERVER[PHP_SELF]?",
        file_get_contents(__DIR__.'/wmsvcapabilities.xml')
    ));
  }

  private function wombox(string $crs, array $bbox): EBox { // calcul EBox en WorldMercator en fonction de crs 
    switch ($crs) {
      case 'EPSG:3395': { // WorldMercator
        return new EBox([[$bbox[0], $bbox[1]], [$bbox[2], $bbox[3]]]);
      }
      case 'EPSG:3857': { // WebMercator
        return new EBox([
          WorldMercator::proj(WebMercator::geo([$bbox[0], $bbox[1]])),
          WorldMercator::proj(WebMercator::geo([$bbox[2], $bbox[3]])),
        ]);
      }
      case 'EPSG:4326': { // WGS84 LatLon
        if (($bbox[0] < WorldMercator::MinLat) || ($bbox[0] > WorldMercator::MaxLat))
          WmsServer::exception(400, "Erreur, latitude incorrecte dans le paramètre BBOX", 'InvalidRequest');
        return new EBox([
          WorldMercator::proj([$bbox[1], $bbox[0]]),
          WorldMercator::proj([$bbox[3], $bbox[2]]),
        ]);
      }
      case 'CRS:84': { // WGS84 LonLat
        if (($bbox[1] < WorldMercator::MinLat) || ($bbox[1] > WorldMercator::MaxLat))
          WmsServer::exception(400, "Latitude incorrecte dans le paramètre BBOX", 'InvalidRequest');
        return new EBox([
          WorldMercator::proj([$bbox[0], $bbox[1]]),
          WorldMercator::proj([$bbox[2], $bbox[3]]),
        ]);
      }
      default:
        $this->exception(400, "CRS $crs non proposé", 'InvalidRequest');
    }
  }
  
  // méthode GetMap du serveur WMS Shomgt
  function getMap(string $version, array $lyrnames, array $styles, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor): void {
    if (($width < 100) || ($width > 2048) || ($height < 100) || ($height > 2048))
      WmsServer::exception(400, "Erreur, paramètre WIDTH ou HEIGHT incorrect", 'InvalidRequest');
    //    echo "bbox="; print_r($bbox); //die();
    $wombox = $this->wombox($crs, $bbox); // calcul en World Mercator du rectangle de la requête
    // dx() est en mètres, $width est un nbre de pixels, 0.00028 est la taille std du pixel pour WMS
    
    Layer::init(); // Initialisation
    $debug = $_GET['debug'] ?? false;
    $grImage = new GeoRefImage($wombox); // création de l'image Géoréférencée
    $grImage->create($width, $height, true); // création d'une image GD transparente
    
    foreach ($lyrnames as $i => $lyrname) { // dessin des couches demandées
      if (!($layer = Layer::layers()[$lyrname] ?? null))
        WmsServer::exception(404, "Erreur, la couche $lyrname est absente");
      $layer->map($grImage, $styles[$i] ?? $lyrname, $debug);
    }
    
    // génération de l'image
    $grImage->savealpha(true);
    if (!$debug)
      header('Content-type: '.$format);
    if ($format == 'image/png')
      imagepng($grImage->image());
    else
      imagejpeg($grImage->image());
    die();
  }
  
  private function toGeo(string $crs, array $geo): array {
    switch ($crs) {
      case 'EPSG:3395': { // WorldMercator
        return WorldMercator::geo($geo);
      }
      case 'EPSG:3857': { // WebMercator
        return WebMercator::geo($geo);
      }
      case 'EPSG:4326': { // WGS84 LatLon
        return [$geo[1], $geo[0]];
      }
      case 'CRS:84': { // WGS84 LonLat
        return $geo;
      }
      default:
        $this->exception(400, "CRS $crs non proposé", 'InvalidRequest');
    }
  }
  
  function getFeatureInfo(array $lyrnames, string $crs, array $pos, int $featureCount): void {
    echo "getFeatureInfo([",implode(', ', $lyrnames),"], [",implode(', ', $pos),"])<br>\n";
    $geo = $this->toGeo($crs, $pos);
    echo "geo="; print_r($geo); echo "<br>\n";
    Layer::init(); // Initialisation
    $info = [];
    foreach ($lyrnames as $i => $lyrname) {
      if (!($layer = Layer::layers()[$lyrname] ?? null))
        WmsServer::exception(404, "Erreur, la couche $lyrname est absente");
      $info[$lyrname] = $layer->featureInfo($geo, $featureCount);
    }
    echo "<pre>",Yaml::dump($info, 4, 2),"</pre>\n";
    die();
  }
};

if (!isset($_GET['SERVICE']) && !isset($_GET['service'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>WMS shomgt</title></head><body>\n";
  /*if (isset($_SERVER['PHP_AUTH_USER'])) {
    if (($_GET['action'] ?? null) == 'logout') {
      header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
      header('HTTP/1.1 401 Unauthorized');
      die("Logout effectué\n");
    }
    echo "Logué http comme '$_SERVER[PHP_AUTH_USER]'<br>\n";
    echo "<a href='?action=logout'>Se déloguer en http (cliquer sur annuler)</a><br>\n";
  }*/
  echo "<h3>URL de Test du serveur WMS-V de ShomGt</h3>\n";
  $menu = [
    'GetCapabilities' => [
      'SERVICE'=> 'WMS',
      'REQUEST'=> 'GetCapabilities',
    ],
    'GetCapabilities version 1.3.0' => [
      'SERVICE'=> 'WMS',
      'VERSION'=> '1.3.0',
      'REQUEST'=> 'GetCapabilities',
    ],
    'GetMap en EPSG:3857' => [
      'service'=> 'WMS',
      'version'=> '1.3.0',
      'request'=> 'GetMap',
      'layers'=> 'sar_2019',
      'styles'=> '',
      'format'=> 'image/png',
      'transparent'=> 'true',
      'height'=> '256',
      'width'=> '256',
      'crs'=> 'EPSG:3857',
      'bbox'=> '640848,5322463,645740,5327355',
    ],
    'GetMap en EPSG:4326' => [
      'service'=> 'WMS',
      'version'=> '1.3.0',
      'request'=> 'GetMap',
      'layers'=> 'sar_2019',
      'styles'=> '',
      'format'=> 'image/png',
      'transparent'=> 'true',
      'width'=>  '800',
      'height'=> '400',
      'crs'=> 'EPSG:4326',
      'bbox'=> '43,-3,46,3',
    ],
   'La Terre on 500x500' => [
      'service'=> 'WMS',
      'version'=> '1.3.0',
      'request'=> 'GetMap',
      'layers'=> 'sar_2019',
      'styles'=> '',
      'format'=> 'image/png',
      'transparent'=> 'true',
      'height'=> '500',
      'width'=> '500',
      'crs'=> 'CRS:84',
      'bbox'=> '-180,-80,180,80',
    ],
    "Génère une exception de projection WorldMercator en raison des coordonnées de la requête" => [
      'service'=> 'WMS',
      'version'=> '1.3.0',
      'request'=> 'GetMap',
      'layers'=> 'gtpyr',
      'styles'=> '',
      'format'=> 'image/png',
      'transparent'=> 'true',
      'height'=> '100',
      'width'=> '100',
      'crs'=> 'CRS:84',
      'bbox'=> '-2,84,2,86',
    ],
    "Génère une exception sur width et height" => [
      'service'=> 'WMS',
      'version'=> '1.3.0',
      'request'=> 'GetMap',
      'layers'=> 'gtpyr',
      'styles'=> '',
      'format'=> 'image/png',
      'transparent'=> 'true',
      'height'=> '18986',
      'width'=> '42718',
      'crs'=> 'CRS:84',
      'bbox'=> '-180,-80,180,80',
    ],
    "GetFeatureInfo" => [
      'SERVICE'=> 'WMS',
      'VERSION'=> '1.3.0',
      'REQUEST'=> 'GetFeatureInfo',
      'BBOX'=> '-129644.35587871669849847,5756825.90879483241587877,-129372.43423895248270128,5757193.60157816205173731',
      'CRS'=> 'EPSG:3857',
      'WIDTH'=> '477',
      'HEIGHT'=> '645',
      'LAYERS'=> 'sar_2019',
      'STYLES'=> '',
      'FORMAT'=> 'image/png',
      'QUERY_LAYERS'=> 'sar_2019',
      'INFO_FORMAT'=> 'text/html',
      'I'=> '214',
      'J'=> '272',
      'FEATURE_COUNT'=> '10',
    ],
  ];
  
  foreach ($menu as $label => $params) {
    $href = '';
    foreach ($params as $k => $v)
      $href .= ($href ? '&amp;' : '?').$k.'='.urlencode($v);
    echo "<a href='$href'>$label</a><br>\n";
  }
  //echo "<pre>"; print_r($_SERVER); echo "</pre>\n";
  echo "</body></html>\n";
} else {
  try {
    $server = new WmsvShomGt;
    $server->process($_GET);
  }
  catch (SExcept $e) {
    switch($e->getSCode()) {
      case WebMercator::ErrorBadLat:
      case WorldMercator::ErrorBadLat: {
        WmsServer::exception(400, "Latitude incorrecte dans le paramètre BBOX", 'InvalidRequest', $e->getMessage());
      }
      default: {
        WmsServer::exception(500, "Erreur de traitement de la requête", '', $e->getMessage());
      }
    }
  }
}
