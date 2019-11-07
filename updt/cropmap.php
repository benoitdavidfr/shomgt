<?php
/*PhpDoc:
name: cropmap.php
title: cropmap.php - script de génération de shell pour fabriquer des géotiffs rognés pour une carte
doc: |
  - le script cropall rogne un ensemble de cartes stockées chacune comme archive 7z dans un répertoire source vers un 2nd répertoire destination
    - le script génère les ordres de dézippage
    - puis appelle le script de rognage d'une carte et de transfert dans un répertoire des rognés
    - puis supprime le répertoire dézippé
  - le script cropmap rogne les géotiff d'une carte dézippée
    - le script liste les géotiff de la carte
    - pour chaque géotiff il génère l'ordre gdal de rognage
journal: |
  6/11/2019:
    - création
includes: [../ws/geotiff.inc.php]
*/

require_once __DIR__.'/../ws/geotiff.inc.php';

if ($argc < 3) {
  die("usage: php cropmap.php {mapDir} {cropDir}\n");
}

GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');

function gtprop(string $gtname): array {
  foreach (GeoTiff::geojson()['features'] as $feature) {
    //print_r($feature);
    $gt = $feature['properties'];
    if ($gt['gtname'] == $gtname) {
      return $gt;
    }
  }
  return [];
}

echo "# cropmap on $argv[1]\n";
$cropDir = $argv[2];
$mapDir = opendir($argv[1])
  or die("Erreur d'ouverture du répertoire $argv[1]");
echo "mkdir $cropDir/$argv[1]\n";
while (($gtfile = readdir($mapDir)) !== false) {
  //echo "gtfile=$gtfile\n";
  if (in_array($gtfile, ['.','..']))
    continue;
  if (preg_match('!\.(png|gt|xml)$!', $gtfile, $matches))
    continue;
  if (!preg_match('!^((\d\d\d\d)(_pal300|_[\dA-Z]+_gtw|_\d\d\d\d|))\.tif$!', $gtfile, $matches)) {
    echo "# No match on file $gtfile\n";
    continue;
  }
  $gt = gtprop("$matches[2]/$matches[1]");
  //print_r($gt);
  if (!$gt) {
    echo "# $matches[2]/$matches[1] non géoréférencé\n";
    continue;
  }
  $gdal_translate = "gdal_translate "
       ."-srcwin $gt[left] $gt[top] "
       .($gt['width']-$gt['left']-$gt['right']).' '.($gt['height']-$gt['top']-$gt['bottom'])
       .' -co "COMPRESS=PACKBITS"'
       ." $gt[gtname].tif $cropDir/$gt[gtname]_crop.tif";
  echo "echo $gdal_translate\n";
  echo "$gdal_translate\n";
}
