<?php
/*PhpDoc:
title: dl.php - téléchargement appelé depuis la carte avec un gtname en paramètre
name: dl.php
doc: |
  Si l'archive est absente alors reconstruit l'image du GéoTiff et l'envoie en PNG.
  Si on décide de conserver l'archive, pourrait proposer plusieurs téléchargements.
*/
require_once __DIR__.'/lib/gdalinfo.inc.php';

if (in_array($_SERVER['PATH_INFO'] ?? '', ['', '/'])) { // appel sans paramètre 
  die("Appel sans paramètre\n");
}

ini_set('memory_limit', '12800M');

function error(string $message) { echo "$message\n"; die(1); }

$debug = $_GET['debug'] ?? 0;

$gtname = substr($_SERVER['PATH_INFO'], 1);
//echo "gtname=$gtname\n";

$mapnum = substr($gtname, 0, 4);
if (is_file(getenv('SHOMGT3_MAPS_DIR_PATH')."/$mapnum.7z")) {
  error("A développer");
}
else {
  $gdalinfo = new GdalInfo(GdalInfo::filepath($gtname));
  $size = $gdalinfo->size();
  //print_r($size);
  
  $image = @imagecreate($size['width'], $size['height'])
    or error("erreur de imagecreate() ligne ".__LINE__);
  
  for ($i=0; $i<$size['width']/1024; $i++) {
    for ($j=0; $j<$size['height']/1024; $j++) {
      $tilepath = sprintf('%s/%X-%X.png', getenv('SHOMGT3_MAPS_DIR_PATH')."/$mapnum/$gtname", $i, $j);
      $dalle = @imagecreatefrompng($tilepath)
        or error("Erreur d'ouverture de la dalle $tilepath");
      imagecopy($image, $dalle, $i*1024, $j*1024, 0, 0, 1024, 1024)
        or error("erreur de imagecopy() ligne ".__LINE__);
    }
  }
  imagesavealpha($image, true);
  if (!$debug)
    header('Content-type: image/png');
  imagepng($image);
}
