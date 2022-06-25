<?php
/*PhpDoc:
name: wms.php
title: wms.php - service WMS de shomgt (avec authentification - pas dans cette version)
classes:
doc: |
  QGis essaie par défaut d'afficher les couches dans leur extension maximum.
  C'est généralement très pénalisant car il faut alors afficher tous les GéoTiffs de la couche alors que cela n'a pas
  beaucoup de sens.
  Pour éviter cela dans le cas général, le serveur détermine si l'échelle demandé est trop petite par rapport à l'échelle
  de référence de la couche et dans ce cas retourne les silhouettes des GéoTiffs sur fond du planisphère 1/40M.
  Il existe des cas particuliers où ce mécanisme n'est pas mis en oeuvre mais l'important est qu'il fonctionne dans le
  cas général généré par QGis.

  **Contrôle d'accès NON ACTIVé
  Un contrôle d'accès est géré d'une part avec la fonction Access::cntrl()
  qui teste l'adresse IP de provenance et l'existence d'un cookie adhoc.
  Pour un serveur WMS, le cookie n'est pas utilisé par les clients lourds.
  En cas d'échec des 2 premiers moyens, le mécanisme d'authentification HTTP est utilisé.
  Ce dernier mécanisme est notamment utilisé par QGis
journal: |
  11/6/2022:
    - augmentation à 10 du repport de tooSmallScale() que je trouve trop faible
  8-10/6/2022:
    - adaptation du dessin des silhouettes quand l'échelle est trop petite
    - test Ok avec QGis
    - affinage notamment du niveau de zoom
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
includes:
  - lib/accesscntrl.inc.php
  - lib/coordsys.inc.php
  - lib/gebox.inc.php
  - lib/wmsserver.inc.php
  - lib/layer.inc.php
*/
require_once __DIR__.'/lib/accesscntrl.inc.php';
require_once __DIR__.'/lib/coordsys.inc.php';
require_once __DIR__.'/lib/gebox.inc.php';
require_once __DIR__.'/lib/wmsserver.inc.php';
require_once __DIR__.'/lib/layer.inc.php';

// Mécanisme de contrôle d'accès sur l'IP et le login / mdp
// Si le contrôle est activé et s'il est refusé alors demande d'authentification
try {
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
}

// écrit dans le fichier de log les params de l'appel, notamment por connaitre et reproduire les appels effectués par QGis
WmsServer::log("appel avec REQUEST_URI=$_SERVER[REQUEST_URI]\n");
WmsServer::log("appel avec GET=".json_encode($_GET, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

/*PhpDoc: classes
name: class WmsShomGt
title: class WmsShomGt - classe implémentant les fonctions du WMS de ShomGt
doc: |
  La classe WmsShomGt hérite de la classe WmsServer qui gère le protocole WMS.
  Le script appelle WmsServer::process() qui appelle les méthodes WmsShomGt::getCapabilities() ou WmsShomGt::getMap()
*/
class WmsShomGt extends WmsServer {
  const STD_PIXEL_SIZE = 0.00028; // taille du pixel définie par WMS en mètres, soit 90,7 dpi (1 inch = 25,4 mm)
  // Le Mac demande du 72 dpi, le PC du 96 dpi
  const BASE = 20037508.3427892476320267; // xmax en Web Mercator en mètres
  // = demi grand axe de l'ellipsoide WGS84 (6378137.0) * PI
  const OUTLINE_COLOR = [0, 0, 0xFF]; // couleur des silhouettes sous la forme [R,V,B]
  
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
  
  // indique si l'échelle demandée est considérée comme trop petite pour la couche
  private function tooSmallScale(float $scaleden, string $lyrname): bool {
    if (ctype_digit(substr($lyrname, 2, 1))) { // les couches correspondant à une échelle
      $layerscaleden = str_replace(['k','M'], ['000','000000'], substr($lyrname, 2)); // dén. échelle de la couche
    }
    elseif ($lyrname=='gtZonMar') {
      $layerscaleden = 9e999;
    }
    else { // les autres couches spéciales
      $layerscaleden = 10_000_000;
    }
    // l'échelle demandée est trop petite ssi son dén. est plus de 10 fois supérieur à celui de l'échelle de réf. de la couche
    return ($scaleden > $layerscaleden * 10);
  }
  
  private function zoom(EBox $wombox, int $width): float {
    return log(self::BASE*2/$wombox->dx() * $width/256, 2);
  }
  
  // méthode GetMap du serveur WMS Shomgt
  function getMap(string $version, array $lyrnames, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor) {
    if (($width < 100) || ($width > 2048) || ($height < 100) || ($height > 2048))
      WmsServer::exception(400, "Erreur, paramètre WIDTH ou HEIGHT incorrect", 'InvalidRequest');
    //    echo "bbox="; print_r($bbox); //die();
    $wombox = $this->wombox($crs, $bbox); // calcul en World Mercator du rectangle de la requête
    // dx() est en mètres, $width est un nbre de pixels, 0.00028 est la taille std du pixel pour WMS
    $scaleden = $wombox->dx() / $width / self::STD_PIXEL_SIZE; // dénominateur de l'échelle demandée
    $originalLayerName = null; // valeur par défaut
    $zoom = -1; // valeur par défaut
    if (in_array('gtpyr', $lyrnames)) { // si gtpyr est demandé, on ne teste pas si l'échelle est trop petite
      // Explication de la formule de déduction du zoom
      // Si width=256 alors zomm est le log base 2 de BASE*2/dx, plus dx diminue plus le zoom augmente
      // A dx constant, si width augmente alors le zoom augmente car on affiche plus de détails
      // La formule dans le log() est sans unité puisque c'est m / m * pixels / pixels
      // Enfin en expérimentant avec QGis en cherchant à obtenir la bonne carte pour une échelle donnée, j'ajuste avec le -1 
      $zoom = round($this->zoom($wombox, $width) - 1);
      if ($zoom < 0)
        $zoom = 0;
    }
    // On détecte sii l'échelle est trop petite par rapport à la couche demandée et dans ce cas on copie le nom
    // de la première couche dans $originalLayerName et on affecte à $lyrnames le planisphère 1/40M.
    // Si plusieurs couches ont été demandées, seule la première est prise en compte.
    elseif ($this->tooSmallScale($scaleden, $lyrnames[0])) {
      $originalLayerName = $lyrnames[0];
      $lyrnames = ['gt40M'];
    }
      
    Layer::initFromShomGt(__DIR__.'/../data/shomgt'); // Initialisation à partir du fichier shomgt.yaml
    $debug = $_GET['debug'] ?? false;
    $grImage = new GeoRefImage($wombox); // création de l'image Géoréférencée
    $grImage->create($width, $height, true); // création d'une image GD transparente
  
    foreach ($lyrnames as $lyrname) { // dessin des couches demandées ou de la couche gt40M si échelle inappropriée
      Layer::layers()[$lyrname]->map($grImage, $debug, $zoom);
    }
    
    // Si échelle inappropriée alors dessin des silhouettes des GéoTiffs de la 1ère couche demandée ainsi que leur numéro
    if ($originalLayerName) {
      // dessin des étiquettes des numéros des GéoTiffs
      $numLyrName = 'num'.substr($originalLayerName, 2);
      Layer::layers()[$numLyrName]->map($grImage, $debug, $zoom);
      $color = $grImage->colorallocate(self::OUTLINE_COLOR);
      // dessin des silhouettes des GéoTiffs - affichage de leur rectangle dans la couleur définie ci-dessus
      foreach (Layer::layers()[$originalLayerName]->itemEBoxes($wombox) as $ebox)
        $grImage->rectangle($ebox, $color);
    }
    
    if (0) { // commentaire de debug
      if ($debug) echo "Debug ligne ",__LINE__,"<br>\n";
      $wombox2 = new EBox([
        $wombox->west(),
        $wombox->north()-$wombox->dy()/10,
        //($wombox->north()+$wombox->south())/2,
        ($wombox->west()+$wombox->east())/2,
        $wombox->north(),
      ]);
      $grImage2 = new GeoRefImage($wombox2);
      $grImage2->create($width/2, $height/10, true);
      $bg_color = $grImage2->colorallocate([255, 255, 0]);
      $text_color = $grImage2->colorallocate([255, 0, 0]);
      $grImage2->string(
          12, [$grImage2->ebox()->west(), $grImage2->ebox()->north()],
          sprintf("scaleden=%.2f, zoom=%d, zoom2=%.2f", $scaleden, $zoom, $this->zoom($wombox, $width)),
          $text_color, $bg_color, $debug);
      $grImage->copyresampled($grImage2, $wombox2, $debug);
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
      'width'=>  '800',
      'height'=> '400',
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
    $server = new WmsShomGt;
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
