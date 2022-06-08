<?php
/*PhpDoc:
name: wms.php
title: wms.php - service WMS de shomgt avec authentification
includes:
  - ../lib/accesscntrl.inc.php
  - ../lib/coordsys.inc.php
  - ../lib/gebox.inc.php
  - wmsserver.inc.php
  - geotiff.inc.php
  - protect.inc.php
classes:
doc: |
  Un contrôle d'accès est géré d'une part avec la fonction Access::cntrl()
  qui teste l'adresse IP de provenance et l'existence d'un cookie adhoc.
  Pour un serveur WMS, le cookie n'est pas utilisé par les clients lourds.
  En cas d'échec des 2 premiers moyens, le mécanisme d'authentification HTTP est utilisé.
  Ce dernier mécanisme est notamment utilisé par QGis
journal: |
  7/6/2022:
    - clonage dans ShomGt3
  7-8/2/2022:
    - gestion des erreurs sur les latitudes et sur la taille de l'image
  6/2/2022:
    - ajout possibilité d'effectuer un logout http
  5/2/2022:
    - ajout de l'envoi d'une exception WMS lorsqu'une exception est levée dans WmsServer::process()
      notamment en cas d'erreur de projection WebMercator ou WorldMercator
  29/3/2019:
    - adaptation à la V2
  22/7/2018:
    - possibilité de désactiver le controle d'accès du service WMS par la variable $controlAccessForWms
    - configuration du protocole (http/https) dans le GetCapabilities
  29/6/2017:
    correction d'un bug
  26/6/2017:
    lorsque l'échelle demandée est trop petite, affichage de la silhouette des cartes de la couche
  25/6/2017
    amélioration du log, avant cette modification chaque requête nécessitant une authentification était loguée
    une fois refusée et une fois acceptée
  24/6/2017
    affichage du 1/20M lorsque l'échelle demandée est inappropriée
  22-23/6/2017
    ajout du traitement pour toutes les projections
    ajout de couches
    le serveur ne fonctionne pas avec QGis sur certaines couches !!!! Je ne comprends pas
  17/6/2017
    Reprise du serveur de cadastre et évolutions
*/
//die("OK ligne ".__LINE__." de ".__FILE__);
//require_once __DIR__.'/../lib/accesscntrl.inc.php';
require_once __DIR__.'/lib/coordsys.inc.php';
require_once __DIR__.'/lib/gebox.inc.php';
require_once __DIR__.'/lib/wmsserver.inc.php';
require_once __DIR__.'/lib/layer.inc.php';

// Mécanisme de contrôle d'accès sur l'IP et le login / mdp
// Si le contrôle est activé et s'il est refusé alors demande d'authentification
/*try {
  if (Access::cntrlFor('wms') && !Access::cntrl(null,true)) {
    // Si la requete ne comporte pas d'utilisateur, alors renvoie d'une demande d'authentification 401
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
      write_log(false);
      header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
      WmsServer::exception(401,
        "Erreur, depuis cette adresse IP ($_SERVER[REMOTE_ADDR]), ce service nécessite une authentification");
    }
    // Si la requête comporte un utilisateur alors vérification du login/mdp
    elseif (!Access::cntrl("$_SERVER[PHP_AUTH_USER]:$_SERVER[PHP_AUTH_PW]")) {
      write_log(false);
      header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
      WmsServer::exception(401, "Erreur d'authentification pour \"$_SERVER[PHP_AUTH_USER]\"");
    }
  }
  // Mécanisme de protection optionnel contre des requêtes abusives
  // Le code de cette protection est gardé secret
  if (is_file(__DIR__.'/protect.inc.php')) {
    require_once __DIR__.'/protect.inc.php';
    if (Protect::limitExceeded()) {
      write_log(false);
      WmsServer::exception(509, "Bandwidth Limit Exceeded");
    }
  }
  write_log(true);
}
// notamment si les paramètres MySQL sont corrects mais que la base MySql correspondante n'existe pas
catch (Exception $e) {
  WmsServer::exception(500, "Erreur dans le contrôle d'accès ", '', $e->getMessage());
}*/

/*PhpDoc: classes
name: class WmsShomGt
title: class WmsShomGt - classe implémentant les fonctions du WMS de ShomGt
doc: |
  La classe WmsShomGt hérite de la classe WmsServer qui gère un serveur WMS générique
  et qui appelle les méthodes getCapabilities() et getMap()
*/
class WmsShomGt extends WmsServer {
  const BASE = 20037508.3427892476320267; // xmax en Web Mercator
  
  // méthode GetCapabilities du serveur Shomgt
  function getCapabilities(string $version='') {
    header('Content-Type: text/xml');
    $request_scheme = $_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
    die(str_replace(
        '{OnlineResource}',
        "$request_scheme://$_SERVER[SERVER_NAME]$_SERVER[PHP_SELF]?",
        file_get_contents(__DIR__.'/wmscapabilities.xml')
    ));
  }

  private function wombox(string $crs, array $bbox): EBox { // calcul EBox en WorldMercator 
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
        return EBox([
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
  
  // méthode GetMap du serveur Shomgt
  function getMap(string $version, array $lyrnames, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor) {
    if (($width <= 0) || ($width > 2048) || ($height <= 0) || ($height > 2048))
      WmsServer::exception(400, "Erreur, paramètre WIDTH ou HEIGHT incorrect", 'InvalidRequest');
    //    echo "bbox="; print_r($bbox); //die();
    $wombox = $this->wombox($crs, $bbox);
    $scaleden = $wombox->dx() / $width / 0.00028;
    // Si l'échelle est trop petite par rapport à la couche demandée, c'est le planisphère qui est retourné
    // avec un dessin des GéoTiffs présents dans la couche demandée
    $originalLayers = [];
    if ($lyrnames[0] == 'gtpyr') {
      $zoom = round(log(self::BASE/$scaleden/0.00028, 2))-7;
    }
    elseif (ctype_digit(substr($lyrnames[0], 2, 1))) {
      $numscaleden = str_replace(['k','M'], ['000','000000'], substr($lyrnames[0], 2)); // dén. échelle couche
      if ($scaleden > $numscaleden * 4) { // échelle demandée est trop petite
        $originalLayers = $lyrnames;
        $lyrnames = ['gt40M'];
      }
      $zoom = -1;
    }
    else {
      $zoom = -1;
    }
      
    Layer::initFromShomGt(__DIR__.'/../data/shomgt'); // Initialisation à partir du fichier shomgt.yaml
    $debug = $_GET['debug'] ?? false;
    $grImage = new GeoRefImage($wombox); // création de l'image Géoréférencée
    $grImage->create($width, $height, true); // création d'une image GD transparente
  
    foreach ($lyrnames as $lyrname) { // dessin des couches demandées 
      Layer::layers()[$lyrname]->map($grImage, $debug);
    }
    
    // Si l'échelle est trop petite par rapport à la couche demandée, dessin des GéoTiffs présents dans les couche demandées
    $color = null;
    foreach ($originalLayers as $lyrname) {
      if (!$color)
        $color = $grImage->colorallocate([0,0, 255]);
      foreach (Layer::layers()[$lyrname]->itemEBoxes() as $ebox)
        $grImage->rectangle($ebox, $color);
    }

    $grImage->savealpha(true);
    if (!$debug)
      header('Content-type: '.$format);
    if ($format == 'image/png')
      imagepng($grImage->image());
    else
      imagejpeg($grImage->image());
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
  echo "<h3>URL de Test du serveur WMS de ShomGt</h3>\n";
  foreach ([
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
      'layers'=> 'gt50k',
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
      'layers'=> 'gt250k',
      'styles'=> '',
      'format'=> 'image/png',
      'transparent'=> 'true',
      'height'=> '800',
      'width'=> '400',
      'crs'=> 'EPSG:4326',
      'bbox'=> '43,-3,46,3',
    ],
    'Carte des silhouettes de la couche gt50k' => [
      'service'=> 'WMS',
      'version'=> '1.3.0',
      'request'=> 'GetMap',
      'layers'=> 'gt50k',
      'styles'=> '',
      'format'=> 'image/png',
      'transparent'=> 'true',
      'width'=> '1532',
      'height'=> '771',
      'crs'=> 'EPSG:3857',
      'bbox'=> '-7977949,1814613,8231889,9972436',
    ],
    'La Terre on 100x100' => [
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
  ] as $label => $params) {
    $href = '';
    foreach ($params as $k => $v)
      $href .= ($href ? '&amp;' : '?').$k.'='.urlencode($v);
    echo "<a href='$href'>$label</a><br>\n";
  }
  //echo "<pre>"; print_r($_SERVER); echo "</pre>\n";
} else {
  $server = new WmsShomGt;
  try {
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
  die("OK ligne ".__LINE__);
}
