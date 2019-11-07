<?php
/*PhpDoc:
name: cropall.php
title: cropall.php - script de génération de shell pour fabriquer des géotiffs rognés pour toutes les cartes
doc: |
  - le script cropall rogne un ensemble de cartes stockées chacune comme archive 7z dans un répertoire source vers un 2nd répertoire destination
    - le script génère les ordres de dézippage
    - puis appelle le script de rognage d'une carte et de transfert dans un répertoire des rognés
    - puis supprime le répertoire dézippé
  - le script cropmap rogne les géotiff d'une carte dézippée
    - le script liste les géotiff de la carte
    - pour chaque géotiff il génère l'ordre gdal de rognage
  Ces 2 scripts ont éét développés pour finir des géotiffs rognés au SNUM le 6/11/2019

  Cmdes d'appel sur localhost/alwaysdata pour les cartes std et les spéciales:
    php cropall.php ../../../shomgeotiff/incoming/20190918 ../../../shomgeotiff/cropped
    php cropall.php ../../../shomgeotiff/all7z/cartesstd/  ../../../shomgeotiff/cropped
    php cropall.php ../../../shomgeotiff/incoming/cartesAEM201707 ../../../shomgeotiff/aemcropped
    php cropall.php ../../../shomgeotiff/all7z/cartesAEM/  ../../../shomgeotiff/aemcropped
journal: |
  6/11/2019:
    - création
*/

if ($argc < 3) {
  die("usage: php cropall.php {mapsDir} {cropsDir}\n");
}

$mapsDir = opendir($argv[1])
  or die("Erreur d'ouverture du répertoire $argv[1]");

while (($map7z = readdir($mapsDir)) !== false) {
  if (in_array($map7z, ['.','..']))
    continue;
  if (!preg_match('!^(.*).7z$!', $map7z, $matches)) {
    echo "# No match on archive $map7z\n";
    continue;
  }
  echo "7z x $argv[1]/$map7z\n";
  $mapname = basename($map7z, '.7z');
  echo "php cropmap.php $mapname $argv[2] | sh\n";
  echo "rm -r $mapname\n";
}
