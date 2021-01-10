<?php
/*PhpDoc:
name: geojson.php
title: geojson.php - génération des GéoTiff en GeoJSON
doc: |
  lecture de shomgt.yaml et génération d'un geojson pour les GéoTiff de la couche indiquée
  Les GéoTiff à cheval sur l'anti-méridien sont dédoublés pour apparaitre des 2 côtés de la carte
journal: |
  10/3/2019
    création
includes: [../lib/gegeom.inc.php, geotiff.inc.php]
*/
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/geotiff.inc.php';

$lyrname = $_GET['lyr'] ?? null;
//die("lyrname=$lyrname");
if ($bbox = $_GET['bbox'] ?? ($_POST['bbox'] ?? null)) {
  $bbox = new GBox($bbox); // en coord. géo.
  $wombox = $bbox->proj('WorldMercator');
}

try {
  GeoTiff::init(__DIR__.'/shomgt.yaml');
  $geojson = GeoTiff::geojson($lyrname, $bbox ? $wombox : null);  
} catch (Exception $e) {
  die($e->getMessage());
}
if ($bbox)
  $geojson['bbox'] = $bbox->asArray();
header('Access-Control-Allow-Origin: *');
header('Content-type: application/json; charset="utf8"');
echo json_encode($geojson, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
