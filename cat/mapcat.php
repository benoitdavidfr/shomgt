<?php
/*PhpDoc:
name: mapcat.php
title: mapcat.php - affichage des caractéristiques des cartes
doc: |
  sans paramètre liste les cartes en HTML
  avec l'id d'une carte en PATH_INFO:
    - ss paramètre fmt fournit en JSON l'enregistrement de la carte dans le catalogue
    - avec fmt=map affiche la carte de la carte
    - avec fmt=geojson générère le Feature GeoJSON correspondant à la carte
includes: [ mapcat.inc.php, map.inc.php ]
*/

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/mapcat.inc.php';

// Liste des cartes du catalogue en HTML
if (!isset($_SERVER['PATH_INFO']) || !$_SERVER['PATH_INFO']) {
  echo "<!DOCTYPE HTML><html><head><title>catalogue</title><meta charset='UTF-8'></head><body>\n";
  //echo "<pre>_SERVER="; print_r($_SERVER); echo "</pre>\n"; // PATH_INFO
  echo "<h2>Catalogue des cartes Shom</h2>\n";
  $mapcat = MapCat::allMostRecentAsArray();
  //echo "<pre>mapcat="; print_r($mapcat); echo "</pre>\n"; // PATH_INFO
  echo "Catalogue actualisé le $mapcat[modified]<br>\n";
  $maps = $mapcat['maps'];
  ksort($maps);
  echo "<table border=1><th>no/uri</th><th>title/map</th><th>scaleDen</th>\n";
  foreach ($maps as $id => $map) {
    //echo "<tr><td><pre>"; print_r($map); echo "</pre></td></tr>\n";
    $scaleD = isset($map['scaleDenominator']) ? $map['scaleDenominator'] : $map['boxes'][0]['scaleDenominator'];
    $href = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/FR$map[num]";
    echo "<tr><td><a href='$href'>$map[num]</a></td>",
         "<td><a href='$href?fmt=map'>$map[title]</a></td>",
         "<td align='right'>$scaleD</td></tr>\n";
  }
  echo "</table>\n";
  die();
}

// infos du catalogue sur une carte particulière en JSON
elseif (!isset($_GET['fmt'])) {
  //echo "<pre>_SERVER="; print_r($_SERVER); echo "</pre>\n"; // PATH_INFO
  $mapnum = substr($_SERVER['PATH_INFO'], 1);
  $maphisto = MapCat::getHistory($mapnum);
  //echo "<pre>maphisto="; print_r($maphisto); echo "</pre>\n";
  foreach ($maphisto as $modified => $map) {
    $maphisto[$modified] = $map->asArray();
  }
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode($maphisto, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
}

// affichage de la carte
elseif ($_GET['fmt'] == 'map')  {
  require_once __DIR__.'/map.inc.php';
  
  $scriptUrl = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";
  $dirname = dirname($scriptUrl);
  $shomgtUrl = dirname($dirname);
  $mapnum = substr($_SERVER['PATH_INFO'], 1);
  $map = new Map(
    [
      'title'=> "carte Shom $mapnum]",
      'bases'=> [
        'shomgt'=> [
          'title'=> "Cartes Shom GT",
          'type'=> 'TileLayer',
          'url'=> "$shomgtUrl/ws/tile.php/gtpyr/{z}/{x}/{y}.png",
          'options'=> ['format'=>'image/png','minZoom'=> 0,'maxZoom'=> 21,'detectRetina'=> true,'attribution'=>'shom'],
        ],
        'cartes'=> [
          'title'=> "Cartes IGN",
          'type'=> 'TileLayer',
          'url'=> 'http://igngp.geoapi.fr/tile.php/cartes/{z}/{x}/{y}.jpg',
          'options'=> ['format'=>'image/jpeg','minZoom'=> 0,'maxZoom'=> 21,'detectRetina'=> true,'attribution'=>'ign'],
        ],
        'whiteimg'=> [
          'title'=> "Fond blanc",
          'type'=> 'TileLayer',
          'url'=> 'http://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
          'options'=> [ 'format'=> 'image/jpeg', 'minZoom'=> 0, 'maxZoom'=> 21, 'detectRetina'=> true ],
        ],
      ],
      'overlays'=>[
        'france'=> [
          'title'=> "ZEE",
          'type'=> 'UGeoJSONLayer',
          'endpoint'=> "$dirname/france.geojson",
          'once'=> true,
        ],
        'map'=> [
          'title'=> "carte $mapnum",
          'type'=> 'UGeoJSONLayer',
          'endpoint'=> "$scriptUrl/$mapnum?fmt=geojson",
          'style'=> ['color'=> 'orange'],
        ],
      ],
      'defaultLayers'=>['shomgt','france','map'],
    ],
    'geodata/map');
  // la geométrie du feature correspondant à la carte dans le catalogue
  $geom = MapCat::getMostRecent($mapnum)->mapGeojson()['geometry'];
  // les coordonnées du premier (ou seul) polygone
  $polCoord = ($geom['type']=='Polygon') ? $geom['coordinates'] : $geom['coordinates'][0];
  // le centre du premier polygone, je pourrais chercher un centre plus adapté !
  $center = [ ($polCoord[0][0][1] + $polCoord[0][2][1])/2, ($polCoord[0][0][0] + $polCoord[0][2][0])/2 ];
  $extent = max(abs($polCoord[0][0][1] - $polCoord[0][2][1]), abs($polCoord[0][0][0] - $polCoord[0][2][0]));
  $zoom = round(8 - log($extent, 2));
  //echo "extent=$extent; zoom=$zoom"; die();
  $map->display('geodata/map', $center, $zoom); 
}

// Feature GeoJSON de la carte
elseif ($_GET['fmt'] == 'geojson') {
  $mapnum = substr($_SERVER['PATH_INFO'], 1);
  echo json_encode(MapCat::getMostRecent($mapnum)->mapGeojson());
}

else {
  echo "<pre>_SERVER="; print_r($_SERVER); echo "</pre>\n"; // PATH_INFO
  echo "<pre>_GET="; print_r($_GET); echo "</pre>\n"; // PATH_INFO
  
}