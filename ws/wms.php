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
  Ce mécanisme est notamment utilisé par QGis
journal: |
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
require_once __DIR__.'/../lib/accesscntrl.inc.php';
require_once __DIR__.'/../lib/coordsys.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/wmsserver.inc.php';
require_once __DIR__.'/geotiff.inc.php';

// Mécanisme de contrôle d'accès sur l'IP et le login / mdp
// Si le contrôle est activé et s'il est refusé alors demande d'authentification
try {
  if (Access::cntrlFor('wms') && !Access::cntrl(null,true)) {
    // Si la requete ne comporte pas d'utilisateur, alors renvoie d'une demande d'authentification 401
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
      write_log(false);
      header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
      WmsServer::exception(401, "Ce service nécessite une authentification");
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
  }
  write_log(true);
}
// notamment si les paramètres MySQL sont corrects mais que la base MySql correspondante n'existe pas
catch (Exception $e) {
  WmsServer::exception(500, "Erreur dans le contrôle d'accès ", '', $e->getMessage());
  //throw new Exception($e->getMessage());
}

/*PhpDoc: classes
name: class WmsShomGt
title: class WmsShomGt - classe implémentant les fonctions du WMS de ShomGt
doc: |
  La classe WmsShomGt hérite de la classe WmsServer qui gère un serveur WMS générique et qui appelle les méthodes getCapabilities() et getMap()
*/
class WmsShomGt extends WmsServer {
  // méthode GetCapabilities du serveur Shomgt
  function getCapabilities(string $version='') {
    header('Content-Type: text/xml');
    $request_scheme = isset($_SERVER['REQUEST_SCHEME']) ?
      $_SERVER['REQUEST_SCHEME'] :
      (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
    die(str_replace(
        '{OnlineResource}',
        "$request_scheme://$_SERVER[SERVER_NAME]$_SERVER[PHP_SELF]?",
        file_get_contents(__DIR__.'/wmscapabilities.xml')
    ));
  }

  // méthode GetMap du serveur Shomgt
  function getMap(string $version, array $layers, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor) {
    //    echo "bbox="; print_r($bbox); //die();
    // Si l'échelle est trop petite par rapport à la couche demandée, c'est le planisphère qui est retourné
    if ($crs == 'EPSG:3395') { // WorldMercator
      $wombox = new EBox([[$bbox[0], $bbox[1]], [$bbox[2], $bbox[3]]]);
      $scaleden = ($bbox[2] - $bbox[0]) / ($width * 0.00028);
    }
    elseif ($crs == 'EPSG:3857') { // WebMercator
      $wombox = new EBox([
          WorldMercator::proj(WebMercator::geo([$bbox[0], $bbox[1]])),
          WorldMercator::proj(WebMercator::geo([$bbox[2], $bbox[3]])),
        ]);
      $scaleden = ($bbox[2] - $bbox[0]) / ($width * 0.00028);
    }
    elseif ($crs == 'EPSG:4326') { // WGS84 LatLon
      $wombox = new EBox([
          WorldMercator::proj([$bbox[1], $bbox[0]]),
          WorldMercator::proj([$bbox[3], $bbox[2]]),
        ]);
      $scaleden = ($bbox[2] - $bbox[0]) * (10000000/90) / ($height * 0.00028);
    }
    elseif ($crs == 'CRS:84') { // WGS84 LonLat
      $wombox = new EBox([
          WorldMercator::proj([$bbox[0], $bbox[1]]),
          WorldMercator::proj([$bbox[2], $bbox[3]]),
        ]);
      $scaleden = ($bbox[3] - $bbox[1]) * (10000000/90) / ($height * 0.00028);
    }
    else
      $this->exception(400, "CRS $crs non proposé");
    
    $originalLayer = null;
    if ($layers[0]<>'gtpyr') {
      $numscaledens = [
        'gt5k' =>     5,
        'gt12k' =>   12,
        'gt25k' =>   25,
        'gt50k' =>   50,
        'gt150k' => 150,
        'gt250k' => 250,
        'gt350k' => 350,
        'gt550k' => 550,
        'gt1M' =>  1000,
        'gt2M' =>  2000,
        'gt3M' =>  3000,
        'gt4M' =>  4000,
        'gt6M' =>  6000,
        'gt8M' =>  8000,
        'gt20M'=> 20000,
      ];
      if (isset($numscaledens[$layers[0]]) && ($scaleden > $numscaledens[$layers[0]]*4000)) {
        $originalLayer = $layers[0];
        $layers[0] = 'gt20M';
      }
      $zoom = -1;
    }
    else { // $layers[0]=='gtpyr'
      //echo "scaleden=$scaleden<br>\n";
      $base = 20037508.3427892476320267; // xmax en Web Mercator
      $zoom = round(log($base/$scaleden/0.00028, 2))-7;
      //echo "zoom=$zoom<br>\n";
      //die("FIN ligne ".__LINE__);
    }
    
    GeoTiff::init(__DIR__.'/shomgt.yaml');
    $image = GeoTiff::maketile($layers[0], $wombox, ['width'=>$width, 'height'=>$height, 'zoom'=> $zoom]);
    if ($originalLayer)
      GeoTiff::drawOutline($image, $originalLayer, $wombox, $width, $height);
    imagesavealpha($image, true);
    header('Content-type: '.$format);
    if ($format == 'image/png')
      imagepng($image);
    else
      imagejpeg($image);
    imagedestroy($image);
    die();
  }
};

if (!isset($_GET['SERVICE']) && !isset($_GET['service'])) {
  echo <<<EOT
<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>WMS shomgt</title></head>
<h3>URL de Test du serveur WMS de ShomGt</h3>
<a href='?SERVICE=WMS&REQUEST=GetCapabilities'>GetCapabilities</a><br>
<a href='?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetCapabilities'>GetCapabilities version 1.3.0</a><br>
<a href='?SERVICE=WMS&version=1.3.0&request=GetMap&layers=gt50k&styles=&format=image%2Fpng&transparent=true&height=256&width=256&crs=EPSG%3A3857&amp;bbox=640848.0451429178,5322463.153553395,645740.014953169,5327355.123363646'>GetMap en EPSG:3857</a><br>
<a href='?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=43,-3,46,3&CRS=EPSG:4326&WIDTH=800&HEIGHT=400&LAYERS=gt350k&STYLES=&FORMAT=image/png&TRANSPARENT=TRUE'>GetMap en EPSG:4326</a><br>
<a href='?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=-7977949.091245136224,1814613.089056419674,8231889.115369650535,9972436.364056419581&CRS=EPSG:3857&WIDTH=1532&HEIGHT=771&LAYERS=gt50k&STYLES=&FORMAT=image/png&TRANSPARENT=TRUE'>Carte des silhouettes de la couche gt50k</a><br>
EOT;
  //echo "<pre>"; print_r($_SERVER); echo "</pre>\n";
} else {
  $server = new WmsShomGt;
  $server->process($_GET);
  die("OK ligne ".__LINE__);
}
?>