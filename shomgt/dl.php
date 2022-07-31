<?php
/*PhpDoc:
title: dl.php - téléchargements appelé depuis la carte avec un gtname en paramètre
name: dl.php
doc: |
  Propose différents téléchargements (le GéoTiff en PNG, les MD en XML, gdalinfo en JSON et la carte en 7z).
journal: |
  25/6/2022:
    - ajout différents téléchargements
  3/6/2022:
    - correction d'un bug sur SHOMGT3_MAPS_DIR_PATH
*/
require_once __DIR__.'/lib/envvar.inc.php';
require_once __DIR__.'/lib/gdalinfo.inc.php';
require_once __DIR__.'/lib/accesscntrl.inc.php';

if (!Access::cntrl()) {
  header('HTTP/1.1 403 Forbidden');
  die("Accès interdit");
}

if (in_array($_SERVER['PATH_INFO'] ?? '', ['', '/'])) { // appel sans paramètre 
  die("Appel sans paramètre\n");
}

function error(string $message): never { echo "$message\n"; die(1); }

$debug = $_GET['debug'] ?? 0;

$path_info = substr($_SERVER['PATH_INFO'], 1);


if (preg_match('!^([^.]+)$!', $path_info, $matches)) { // sans extension, propose liste des possibilités 
  $gtname = $matches[1];
  $mapnum = substr($gtname, 0, 4);
  echo "<!DOCTYPE HTML><html><head><title>$gtname</title></head><body><h2>Téléchargements</h2><ul>\n";
  echo "<li><a href='$gtname.png'>GéoTiff $gtname au format png</a></li>\n";
  echo "<li><a href='$gtname.xml'>MD du GéoTiff $gtname au format XML</a></li>\n";
  echo "<li><a href='$gtname.json'>GdalInfo du GéoTiff $gtname au format JSON</a></li>\n";
  echo "<li><a href='https://sgserver.geoapi.fr/index.php/maps/$mapnum.7z'>carte $mapnum au format 7z</a></li>\n";
  die("</ul>");
}

if (preg_match('!^([^.]+)\.png$!', $path_info, $matches)) { // Téléchargement du GéoTiff $gtname au format png 
  $gtname = $matches[1];
  ini_set('memory_limit', '12800M');
  $mapnum = substr($gtname, 0, 4);
  $gdalinfo = new GdalInfo(GdalInfo::filepath(gtname: $gtname, temp: false));
  $size = $gdalinfo->size();
  //print_r($size);
  
  $image = @imagecreate($size['width'], $size['height'])
    or error("erreur de imagecreate() ligne ".__LINE__);
  
  for ($i=0; $i<$size['width']/1024; $i++) {
    for ($j=0; $j<$size['height']/1024; $j++) {
      $tilepath = sprintf('%s/%X-%X.png', EnvVar::val('SHOMGT3_MAPS_DIR_PATH')."/$mapnum/$gtname", $i, $j);
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
  die();
}

if (preg_match('!^([^.]+)\.xml$!', $path_info, $matches)) { // Téléchargement des MD du GéoTiff au format XML 
  $gtname = $matches[1];
  $mapnum = substr($gtname, 0, 4);
  header('Content-type: application/xml; charset="utf-8"');
  die(file_get_contents(EnvVar::val('SHOMGT3_MAPS_DIR_PATH')."/$mapnum/CARTO_GEOTIFF_$gtname.xml"));
}

if (preg_match('!^([^.]+)\.json$!', $path_info, $matches)) { // Téléchargement du GéoTiff $gtname au format png 
  $gtname = $matches[1];
  $mapnum = substr($gtname, 0, 4);
  header('Content-type: application/json; charset="utf-8"');
  die(file_get_contents(EnvVar::val('SHOMGT3_MAPS_DIR_PATH')."/$mapnum/$gtname.info.json"));
}
