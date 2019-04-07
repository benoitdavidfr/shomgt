<?php
/*PhpDoc:
name: crop.php
title: crop.php - génère un script shell pour fabriquer un GéoTiff rogné
doc: |
  S'utilise en fournissant en paramètre le chemin d'un fichier tif dans un répertoire d'une carte
  exemple$ php crop.php ~/html/shomgeotiff/incoming/all/7154/7154_1_gtw.tif  | sh
  Génère la commande gdal pour fabriquer le fichier crop qui sera localisé à côté du fichier tif initial
  Utilise la base des GeoTiff pour connaitre les marges à supprimer
journal: |
  28/3/2019
    adaption à ShomGt V2
  10/7/2017
    création
*/
require_once __DIR__.'/../ws/geotiff.inc.php';

header('Content-type: text/plain; charset="utf8"');
/*
//echo "cd ~/Sites/shomgeotiff2017\n";
echo "cd ~/www/shomgeotiff2017\n";

GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');
//print_r(GeoTiff::geojson());
foreach (GeoTiff::geojson()['features'] as $feature) {
  $gt = $feature['properties'];
  if ($gt['gtname'] == '0101bis/0101_pal300')
    continue;
  echo "gdal_translate ",
       "-srcwin $gt[left] $gt[top] ",
       $gt['width']-$gt['left']-$gt['right'],' ',$gt['height']-$gt['top']-$gt['bottom'],
       ' -co "COMPRESS=PACKBITS"',
       " $gt[gtname].tif$gt[gtname]_crop.tif\n";
}
*/
if ($argc < 2) {
  die("usage: php crop.php {tiff_path}\n");
}
$path = $argv[1];
if (!preg_match('!/(\d\d\d\d/\d\d\d\d_(pal300|[\dA-Z]+_gtw)).tif$!', $path, $matches))
  die("path $path n'est pas un chemin correct\n");
$gtname = $matches[1];
$pathdir = substr($path, 0, strlen($path)-strlen($gtname)-4);
//echo "gtname=$gtname, pathdir=$pathdir\n";

GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');
//print_r(GeoTiff::geojson());
foreach (GeoTiff::geojson()['features'] as $feature) {
  $gt = $feature['properties'];
  if ($gt['gtname'] == $gtname) {
    echo "gdal_translate ",
         "-srcwin $gt[left] $gt[top] ",
         $gt['width']-$gt['left']-$gt['right'],' ',$gt['height']-$gt['top']-$gt['bottom'],
         ' -co "COMPRESS=PACKBITS"',
         " $pathdir$gt[gtname].tif $pathdir$gt[gtname]_crop.tif\n";
  }
}
