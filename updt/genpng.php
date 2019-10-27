<?php
/*PhpDoc:
name: genpng.php
title: genpng.php - génération des PNG à partir des TIF dans le répertoite tmp
doc: |
  script appelé par dezip.php
    - itère sur les répertoires de tmp
      - itère sur les fichiers .tif
        - génère le fichier .info
        - génère un fichier .png
        - supprime le fichier .tif
        - crée un répertoire pour les dalles PNG
    - itère sur les PNG créés
      - découpe le PNG en dalles
      - supprime le PNG
    - suppression des cartes à supprimer
    - transfère les répertoires des nouvelles cartes dans current
    - génère le nouveau shomgt.yaml et le met dans ../ws/
journal: |
  2/4/2019:
    suppression des cartes à supprimer
  1/4/2019:
    transfert des nouvelles cartes dans current et fabication du nouveau shomgt.yaml
  10/3/2019:
    création
*/
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

header('Content-type: text/plain; charset="utf8"');

$shomgeotiff = realpath(__DIR__.'/../../../shomgeotiff');
$tmppath = "$shomgeotiff/tmp";

// traitements dans tmp
$pngFiles = [];
$tmpdir = opendir($tmppath)
  or die("Erreur d'ouverture du répertoire $tmppath\n");
while (($mapname = readdir($tmpdir)) !== false) {
  if (!is_dir("$tmppath/$mapname") || in_array($mapname, ['.','..']))
    continue;
  $mapdir = opendir("$tmppath/$mapname")
    or die("Erreur d'ouverture du répertoire $tmppath/$mapname");
  echo "echo cd $tmppath/$mapname\n"; echo "cd $tmppath/$mapname\n";
  while (($tifname = readdir($mapdir)) !== false) {
    if (!preg_match('!^(.+)\.tif$!', $tifname, $matches))
      continue;
    $gtname = $matches[1]; // nom ss path ni suffixe
    
    // génération des .info pour chaque GeoTiff
    $cmde = "gdalinfo $gtname.tif > $gtname.info";
    echo "echo $cmde\n"; echo "$cmde\n";
    
    // conversion en PNG de chaque GeoTiff
    $cmde = "gdal_translate -of PNG $gtname.tif $gtname.png";          
    echo "echo $cmde\n"; echo "$cmde\n";
    $pngFiles[] = "$tmppath/$mapname/$gtname.png";
    
    // suppression du .tif pour économiser de la place
    echo "echo rm $gtname.tif\n"; echo "rm $gtname.tif\n";
    
    // création d'un répertoire pour les dalles ou s'il existe suppression et recréation
    if (is_dir("$tmppath/$mapname/$gtname")) {
      echo "echo rm -r $tmppath/$mapname/$gtname\n"; echo "rm -r $tmppath/$mapname/$gtname\n";
    }
    echo "echo mkdir $tmppath/$mapname/$gtname\n"; echo "mkdir $tmppath/$mapname/$gtname\n";
  }
  closedir($mapdir);
}
closedir($tmpdir);

// découpage en dalles de chaque GeoTiff
echo "echo cd ",__DIR__,"\n"; echo "cd ",__DIR__,"\n"; 
foreach ($pngFiles as $pngFile) {
  $cmde = "php tile.php $pngFile\n";          
  echo "echo $cmde\n"; echo "$cmde\n";
  
  // suppression du .png non découpé pour économiser de la place
  echo "echo rm $pngFile\n"; echo "rm $pngFile\n";
}

// supprime les cartes à supprimer
if (($argc > 1) && is_file("$shomgeotiff/incoming/$argv[1]/index.yaml")) {
  echo "echo $shomgeotiff/incoming/$argv[1]/index.yaml existe\n";
  $index = Yaml::parseFile("$shomgeotiff/incoming/$argv[1]/index.yaml");
  if (isset($index['toDelete'])) {
    foreach (array_keys($index['toDelete']) as $toDelete) {
      if (substr($toDelete, 0, 2))
        $toDelete = substr($toDelete, 2);
      echo "echo \"Suppresion de la carte $toDelete\"\n";
      if (is_dir("$shomgeotiff/current/$toDelete")) {
        echo "echo rm -r $shomgeotiff/current/$toDelete\n"; echo "rm -r $shomgeotiff/current/$toDelete\n";
      }
      else
        echo "echo \"La carte $toDelete n'existe pas dans current\"\n";
    }
  }
}

// transfert des nouveaux GéoTiff dans current en supprimant l'ancien s'il existe
$tmpdir = opendir($tmppath)
  or die("Erreur d'ouverture du répertoire $tmppath\n");
while (($mapname = readdir($tmpdir)) !== false) {
  if (!is_dir("$tmppath/$mapname") || in_array($mapname, ['.','..']))
    continue;
  if (is_dir("$shomgeotiff/current/$mapname")) {
    echo "echo rm -r $shomgeotiff/current/$mapname\n"; echo "rm -r $shomgeotiff/current/$mapname\n";
  }
  echo "echo mv $tmppath/$mapname $shomgeotiff/current/\n"; echo "mv $tmppath/$mapname $shomgeotiff/current/\n";
}
closedir($tmpdir);
