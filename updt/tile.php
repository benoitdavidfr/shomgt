<?php
/*PhpDoc:
name: tile.php
title: tile.php - découpage d'un PNG en dalles de 1024 X 1024
doc: |
  script à appeler en ligne de commande
  doit être appelé avec le nom du fichier PNG en paramètre
*/
header('Content-type: text/plain; charset="utf8"');
ini_set('memory_limit', '12800M');

function error(string $message) { echo $message; die(1); }
  
//echo "argc=$argc\n";
if ($argc <> 2) {
  error("Usage: argv[0] {fichierPNG}\n");
}

$pngpath = $argv[1];
if (!is_file($pngpath))
  error("Erreur: $pngpath n'est pas un fichier\n");
$dirpath = dirname($pngpath).'/'.basename($pngpath, '.png');

if (!is_dir($dirpath))
  error("Erreur: le répertoire $dirpath doit avoir été créé\n");

$image = @imagecreatefrompng($pngpath)
  or error("Erreur d'ouverture du GéoTiff $pngpath\n");
$width = imagesx($image);
$height = imagesy($image);

$dalle = @imagecreate(1024, 1024)
  or error("erreur de imagecreate() ligne ".__LINE__);

for ($i=0; $i<floor($width/1024); $i++) {
  for ($j=0; $j<floor($height/1024); $j++) {
    imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, 1024, 1024)
      or error("erreur de imagecopy() ligne ".__LINE__);
    $tilepath = sprintf('%s/%X-%X.png', $dirpath, $i, $j);
    imagepng($dalle, $tilepath)
      or error("erreur de imagepng() ligne ".__LINE__);
    //echo "dalle $tilepath créée\n";
  }
}
imagedestroy($dalle);

// la colonne supplémentaire i=floor($width/1024)
$i = floor($width/1024);
$w = $width - 1024 * $i;
if ($w) {
  echo "création de la colonne $i\n";
  //echo "w=$w<br>\n";
  $dalle = @imagecreate($w, 1024)
    or error("erreur de imagecreate() ligne ".__LINE__);
  for ($j=0; $j<floor($height/1024); $j++) {
    imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, $w, 1024)
      or error("erreur de imagecopy() ligne ".__LINE__);
    $tilepath = sprintf('%s/%X-%X.png', $dirpath, $i, $j);
    imagepng($dalle, $tilepath)
      or error("erreur de imagepng() ligne ".__LINE__);
    //echo "dalle $tilepath créée\n";
  }
  imagedestroy($dalle);
}

// la ligne supplémentaire j=floor($height/1024)
$j = floor($height/1024);
$h = $height - 1024 * $j;
if ($h) {
  echo "création de la ligne $j\n";
  //echo "h=$h<br>\n";
  $dalle = @imagecreate(1024, $h)
    or error("erreur de imagecreate() ligne ".__LINE__);
  for ($i=0; $i<floor($width/1024); $i++) {
    imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, 1024, $h)
      or error("erreur de imagecopy() ligne ".__LINE__);
    $tilepath = sprintf('%s/%X-%X.png', $dirpath, $i, $j);
    imagepng($dalle, $tilepath)
      or error("erreur de imagepng() ligne ".__LINE__);
    //echo "dalle $tilepath créée\n";
  }
  imagedestroy($dalle);
}

// La dernière cellule
$i = floor($width/1024);
$w = $width - 1024 * $i;
$j = floor($height/1024);
$h = $height - 1024 * $j;
if ($w && $h) {
  echo "création de la cellule $i $j\n";
  $dalle = @imagecreate($w, $h)
    or error("erreur de imagecreate() ligne ".__LINE__);
  imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, $w, $h)
    or error("erreur de imagecopy() ligne ".__LINE__);
  $tilepath = sprintf('%s/%X-%X.png', $dirpath, $i, $j);
  imagepng($dalle, $tilepath)
    or error("erreur de imagepng() ligne ".__LINE__);
  //echo "dalle $tilepath créée\n";
  imagedestroy($dalle);
}

if (0) { // Affichage
  echo "<table border=1><th></th>";
  for ($i=0; $i<=floor($width/1024); $i++)
    echo "<th>$i</th>";
  echo "\n";
  for ($j=0; $j<=floor($height/1024); $j++) {
    echo "<tr><td>$j</td>\n";
    for ($i=0; $i<=floor($width/1024); $i++) {
      $path = sprintf('%s/%s/%X-%X.png', $dirpath, $file, $i, $j);
      echo "<td><img src='$path'></td>\n";
    }
    echo "</tr>\n";
  }
  echo "</table>\n";
}
die("Découpage OK du fichier $pngpath\n");

