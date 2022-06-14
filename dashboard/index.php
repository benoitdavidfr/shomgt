<?php

require_once __DIR__.'/lib/gegeom.inc.php';

echo "<!DOCTYPE HTML><html><head><title>dashboard</title></head><body>\n";
if (!isset($_GET['a'])) {
  echo "<h2>Menu:</h2><ul>\n";
  echo "<li><a href='?a=newMaps'>Nouvelles cartes</li>\n";
  echo "</ul>\n";
  die();
}

// liste de polygones de la ZEE associés à la zoneid
class Zee {
  protected string $id;
  protected Polygon $polygon;
  static array $all=[]; // [ Zee ]
  
  static function add(array $ft): void { // ajoute un feature
    if ($ft['geometry']['type'] == 'Polygon')
      self::$all[] = new self($ft['properties']['zoneid'], Geometry::fromGeoJSON($ft['geometry']));
    else { // MultiPolygon
      foreach ($ft['geometry']['coordinates'] as $pol) {
        self::$all[] = new self($ft['properties']['zoneid'], Geometry::fromGeoJSON(['type'=>'Polygon', 'coordinates'=>$pol]));
       }
    }
  }
  
  static function init(): void { // initialise Zee
    $frzee = json_decode(file_get_contents(__DIR__.'/../shomft/frzee.geojson'), true);
    foreach ($frzee['features'] as $ftzee) {
      Zee::add($ftzee);
    }
  }
  
  function __construct(string $id, Polygon $polygon) {
    $this->id = $id;
    $this->polygon = $polygon;
  }
  
  static function inters(Geometry $geom): array { // retourne la liste des zoneid des polygones intersectant la géométrie
    $result = [];
    foreach (self::$all as $zee) {
      if ($geom->inters($zee->polygon))
        $result[$zee->id] = 1;
    }
    ksort($result);
    return array_keys($result);
  }
};

class MapFromWfs {
  static array $fts; // liste des features indexés sur carte_id
  
  static function init(): void {
    $fc = json_decode(file_get_contents(__DIR__.'/../shomft/gt.json'), true);
    foreach ($fc['features'] as $gmap)
      self::$fts[$gmap['properties']['carte_id']] = $gmap;
  }
  
  static function show(): void { // affiche le statut de chaque carte Wfs
    foreach (self::$fts as $gmap) {
      //print_r($gmap);
      if ($gmap['properties']['scale'] > 6e6)
        echo '"',$gmap['properties']['name'],"\" est à petite échelle<br>\n";
      elseif ($mapsFr = Zee::inters(Geometry::fromGeoJSON($gmap['geometry'])))
        echo '"',$gmap['properties']['name'],"\" intersecte ",implode(',',$mapsFr),"<br>\n";
      else
        echo '"',$gmap['properties']['name'],"\" N'intersecte PAS la ZEE<br>\n";
    }
  }
  
  static function interest(): array { // liste des cartes d'intérêt
    $list = [];
    foreach (self::$fts as $gmap) {
      if (($gmap['properties']['scale'] > 6e6) || Zee::inters(Geometry::fromGeoJSON($gmap['geometry'])))
        $list[] = $gmap['properties']['carte_id'];
    }
    return $list;
  }
};

if ($_GET['a'] == 'newMaps') { // détecte de nouvelles cartes à ajouter au portefeuille 
  //echo "<pre>";
  Zee::init();
  MapFromWfs::init();
  //MapFromWfs::show();
  $list = MapFromWfs::interest();
  //echo count($list)," / ",count(MapFromWfs::$fc['features']),"\n";
  $INCOMING_PATH = getenv('SHOMGT3_INCOMING_PATH');
  $maps = json_decode(file_get_contents("$INCOMING_PATH/../maps.json"), true);
  foreach ($list as $mapid) {
    if (!isset($maps[$mapid])) {
      echo "$mapid dans WFS et pas dans sgserver<br>\n";
      echo "<pre>"; print_r(MapFromWfs::$fts[$mapid]['properties']); echo "</pre>\n"; 
    }
  }
}
