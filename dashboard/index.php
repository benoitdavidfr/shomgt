<?php
// dashboard/index.php

require_once __DIR__.'/lib/gegeom.inc.php';

echo "<!DOCTYPE HTML><html><head><title>dashboard</title></head><body>\n";
if (!isset($_GET['a'])) {
  echo "<h2>Menu:</h2><ul>\n";
  echo "<li><a href='?a=listOfInterest'>listOfInterest</li>\n";
  echo "<li><a href='?a=newObsoleteMaps'>Nouvelles cartes et cartes obsolètes dans le patrimoine par rapport au WFS</li>\n";
  echo "</ul>\n";
  die();
}

// pour un entier fournit une représentation avec un '_' comme séparateur des milliers 
function addUndescoreForThousand(?int $scaleden): string {
  if ($scaleden === null) return 'undef';
  if ($scaleden < 0)
    return '-'.addUndescoreForThousand(-$scaleden);
  elseif ($scaleden < 1000)
    return sprintf('%d', $scaleden);
  else
    return addUndescoreForThousand(floor($scaleden/1000)).'_'.sprintf('%03d', $scaleden - 1000 * floor($scaleden/1000));
}

class Zee { // liste de polygones de la ZEE chacun associé à une zoneid
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
    if (!self::$all)
      die("Erreur, Zee doit être initialisé\n");  
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
      if ((isset($gmap['properties']['scale']) && ($gmap['properties']['scale'] > 6e6))
          || Zee::inters(Geometry::fromGeoJSON($gmap['geometry'])))
        $list[] = $gmap['properties']['carte_id'];
    }
    return $list;
  }
};

class Portfolio { // Portefeuille des cartes exposées sur ShomGt
  static array $all; // contenu du fichier maps.json
  
  static function init(): void {
    $INCOMING_PATH = getenv('SHOMGT3_INCOMING_PATH');
    self::$all = json_decode(file_get_contents("$INCOMING_PATH/../maps.json"), true);
  }
  
  static function isActive(string $mapnum): bool {
    return isset(self::$all[$mapnum]) && (self::$all[$mapnum]['status']=='ok');
  }
  
  static function actives(): array { // sélection des cartes actives 
    $actives = [];
    foreach (self::$all as $mapnum => $map) {
      if ($map['status']=='ok')
        $actives[$mapnum] = $map;
    }
    return $actives;
  }
};

if ($_GET['a'] == 'listOfInterest') { // vérification de la liste des cartes d'intérêt 
  MapFromWfs::init();
  Zee::init();
  $listOfInterest = MapFromWfs::interest();
  echo "<pre>listOfInterest="; print_r($listOfInterest); echo "</pre>\n";
  MapFromWfs::show();
  die();
}

if ($_GET['a'] == 'newObsoleteMaps') { // détecte de nouvelles cartes à ajouter au portefeuille et les cartes obsolètes 
  //echo "<pre>";
  Zee::init();
  MapFromWfs::init();
  Portfolio::init();
  //MapFromWfs::show();
  $listOfInterest = MapFromWfs::interest();
  //echo count($list)," / ",count(MapFromWfs::$fc['features']),"\n";
  $newMaps = [];
  foreach ($listOfInterest as $mapid) {
    if (!Portfolio::isActive($mapid)) {
      $newMaps[] = $mapid;
      //echo "$mapid dans WFS et pas dans sgserver<br>\n";
      //echo "<pre>"; print_r(MapFromWfs::$fts[$mapid]['properties']); echo "</pre>\n"; 
    }
  }
  if (!$newMaps)
    echo "<h2>Toutes les cartes d'intérêt du flux WFS sont dans le portefeuille</h2>>\n";
  else {
    echo "<h2>Cartes d'intérêt présentes dans le flux WFS et absentes du portefeuille</h2>\n";
    foreach ($newMaps as $mapid) {
      $map = MapFromWfs::$fts[$mapid]['properties'];
      echo "- $map[name] (1/",addUndescoreForThousand($map['scale'] ?? null),")<br>\n";
    }
  }
  
  $obsoletes = [];
  foreach (Portfolio::actives() as $mapid => $map) {
    if (!in_array($mapid, $listOfInterest))
      $obsoletes[] = $mapid;
  }
  if (!$obsoletes)
    echo "<h2>Toutes les cartes du portefeuille sont présentes dans le flux WFS</h2>\n";
  else {
    echo "<h2>Cartes du portefeuille absentes du flux WFS</h2>\n";
    foreach (Portfolio::actives() as $mapid => $map) {
      if (!in_array($mapid, $listOfInterest))
        echo "- $mapid<br>\n";
    }
  }
}
