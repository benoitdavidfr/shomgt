<?php
// liste des geotiff de shomgt

require_once __DIR__.'/../ws/geotiff.inc.php';

GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');
echo "<pre>\n";

foreach (GeoTiff::geojson()['features'] as $feature) {
  $gt = $feature['properties'];
  //print_r($gt);
  // les géotiffs dont le nom se termine par -East sont des artefacts pour gérer les images à cheval sur l'anté-méridien
  if (preg_match('!-East$!', $gt['gtname']))
    continue;
  echo "$gt[title] ($gt[gtname])\n";
}
