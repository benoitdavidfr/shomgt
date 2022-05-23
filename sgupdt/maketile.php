<?php
/*PhpDoc:
name: maketile.php
title: maketile.php - découpage d'un PNG en dalles de 1024 X 1024 + effacement de zones définies dans build.yaml
doc: |
  script à appeler en ligne de commande avec en paramètre le chemin du fichier PNG en paramètre
  Crée un répertoire ayant le même nom que le png sans l'extension .png
  et y crée les dalles avec comme nom sprintf('%s/%X-%X.png', $dirpath, $i, $j)
  Lit le fichier build.yaml pour y trouver les zones à effacer et en déduit un fichier temporaire todelete.pser.
  Pour effacer ces zones, utilise le fichier .info créé à partir du tif avec gdalinfo pour géoréférencer l'image
  
  limites:
    - Le script détruit les couleurs dans certains cas, par exemple sur 8509_2015.png qui provient d'un PDF
journal: |
  16/5/2022:
    - le nom du fichier de paramètres est update.yaml
  8/5/2022:
    - correction d'un bug
  7/5/2022:
    - adaptation pour transfert dans build
  3/5/2022:
    - ajout de l'affichage de la progression
    - ajout possibilité de définir les polygones en DM
  26-27/4/2022:
    - chgt du nom tile.php en maketile.php
    - chgt du positionnement dans l'image Docker dans /var/www/html
    - lors de la fabrication des dalles effacement des zones définies dans shomgt.yaml
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib/gdalinfo.inc.php';
require_once __DIR__.'/lib/gebox.inc.php';
require_once __DIR__.'/lib/grefimg.inc.php';

use Symfony\Component\Yaml\Yaml;

header('Content-type: text/plain; charset="utf8"');
//ini_set('memory_limit', '12800M'); // 12 G c'est un peu abuser !!
ini_set('memory_limit', '2G'); // pour 7330_2016.png 512M, 1G insuffisant ; 2G ok


function error(string $message) { echo "$message\n"; die(1); }
  
//echo "argc=$argc\n";
if ($argc <> 2) {
  error("Usage: $argv[0] {fichierPNG}\n");
}
elseif ($argv[1]=='-v') {
  echo "Dates de dernière modification des fichiers sources:\n";
  echo Yaml::dump($VERSION);
  die();
}

$pngpath = $argv[1];
if (!is_file($pngpath))
  error("Erreur: $pngpath n'est pas un fichier");
$dirpath = dirname($pngpath).'/'.basename($pngpath, '.png');

if (!is_dir($dirpath) && !mkdir($dirpath))
  error("Erreur de création du répertoire $dirpath");

$image = @imagecreatefrompng($pngpath)
  or error("Erreur d'ouverture du GéoTiff $pngpath");
$width = imagesx($image);
$height = imagesy($image);

// Lecture du fichier shomgt.yaml pour voir s'il existe des zones à effacer pour le GeoTiff en cours de traitement

// On commence par construire la structure toDelete par nom de GT à partir de shomgt.yaml et à l'enregistrer en .pser
if (is_file(__DIR__.'/todelete.pser') && (filemtime(__DIR__.'/todelete.pser') > filemtime(__DIR__.'/update.yaml'))) {
  $toDelete = unserialize(file_get_contents(__DIR__.'/todelete.pser'));
}
else {
  $toDelete = []; // [{gtname}=> [{zone}]]
  $buildParams = Yaml::parseFile(__DIR__.'/update.yaml');
  foreach ($buildParams as $gtname => $gt) {
    if (ctype_digit(substr($gtname, 0, 4))) {
      if (isset($gt['toDelete']))
        $toDelete[$gtname] = $gt['toDelete'];
    }
  }
  file_put_contents(__DIR__.'/todelete.pser', serialize($toDelete));
}

// Si la liste des zones à effacer est non vide pour l'image courante alors je construis une image géoréférencée
// et j'en efface chaque zone
if ($listOfZonesToDelete = $toDelete[basename($pngpath, '.png')] ?? []) {
  imagealphablending($image, false)
    or error("erreur de imagealphablending() ligne ".__LINE__);
  $transparent = imagecolorallocatealpha($image, 0xFF, 0xFF, 0xFF, 0x7F);
  $gdalinfo = new GdalInfo(dirname($pngpath).'/'.basename($pngpath, '.png').'.info');
  $gri = new GeoRefImage($gdalinfo->ebox(), $image);
  foreach ($listOfZonesToDelete as $zoneToDelete) {
    echo "Effacement de:\n", Yaml::dump($zoneToDelete);
    if (isset($zoneToDelete['rect'])) {
      $gbox = GBox::fromShomGt($zoneToDelete['rect']); // interprétation du rectangle come GBox
      $gri->filledrectangle($gbox->proj('WorldMercator'), $transparent);
    }
    elseif (isset($zoneToDelete['polygon'])) {
      foreach ($zoneToDelete['polygon'] as $i => $pos) {
        if (is_string($pos))
          $pos = GBox::posFromGeoCoords($pos);
        $polygon[$i] = WorldMercator::proj($pos);
      }
      $gri->filledpolygon($polygon, $transparent);
    }
    else
      error("Type de zone non reconnue\n");
  }
  $image = $gri->image();
  imagealphablending($image, true)
    or error("erreur de imagealphablending() ligne ".__LINE__);
}

// Je découpe l'image en dalles que je stocke dans le répertoire $dirpath
$dalle = @imagecreate(1024, 1024)
  or error("erreur de imagecreate() ligne ".__LINE__);

$imax = floor($width/1024);
$jmax = floor($height/1024);
for ($i=0; $i<$imax; $i++) {
  for ($j=0; $j<$jmax; $j++) {
    imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, 1024, 1024)
      or error("erreur de imagecopy() ligne ".__LINE__);
    imageSaveAlpha($dalle, true)
      or error("erreur de imageSaveAlpha() ligne ".__LINE__);
    imagepng($dalle, sprintf('%s/%X-%X.png', $dirpath, $i, $j))
      or error("erreur de imagepng() ligne ".__LINE__);
    //echo "dalle $tilepath créée\n";
    printf("  Dalle %d/%d,%d/%d créée  \r", $i, $imax, $j, $jmax);
  }
}
imagedestroy($dalle);

// la colonne supplémentaire i=floor($width/1024)
$i = $imax;
$w = $width - 1024 * $i;
if ($w) {
  //echo "création de la colonne $i\n";
  //echo "w=$w<br>\n";
  $dalle = @imagecreate($w, 1024)
    or error("erreur de imagecreate() ligne ".__LINE__);
  for ($j=0; $j<$jmax; $j++) {
    imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, $w, 1024)
      or error("erreur de imagecopy() ligne ".__LINE__);
    $tilepath = sprintf('%s/%X-%X.png', $dirpath, $i, $j);
    imagepng($dalle, $tilepath)
      or error("erreur de imagepng() ligne ".__LINE__);
    printf("  Dalle %d/%d,%d/%d créée  \r", $i, $imax, $j, $jmax);
  }
  imagedestroy($dalle);
}

// la ligne supplémentaire j=floor($height/1024)
$j = $jmax;
$h = $height - 1024 * $j;
if ($h) {
  //echo "création de la ligne $j\n";
  //echo "h=$h<br>\n";
  $dalle = @imagecreate(1024, $h)
    or error("erreur de imagecreate() ligne ".__LINE__);
  for ($i=0; $i<$imax; $i++) {
    imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, 1024, $h)
      or error("erreur de imagecopy() ligne ".__LINE__);
    $tilepath = sprintf('%s/%X-%X.png', $dirpath, $i, $j);
    imagepng($dalle, $tilepath)
      or error("erreur de imagepng() ligne ".__LINE__);
    printf("  Dalle %d/%d,%d/%d créée  \r", $i, $imax, $j, $jmax);
  }
  imagedestroy($dalle);
}

// La dernière cellule
$i = $imax;
$w = $width - 1024 * $i;
$j = $jmax;
$h = $height - 1024 * $j;
if ($w && $h) {
  //echo "création de la cellule $i $j\n";
  $dalle = @imagecreate($w, $h)
    or error("erreur de imagecreate() ligne ".__LINE__);
  imagecopy($dalle, $image, 0, 0, $i*1024, $j*1024, $w, $h)
    or error("erreur de imagecopy() ligne ".__LINE__);
  $tilepath = sprintf('%s/%X-%X.png', $dirpath, $i, $j);
  imagepng($dalle, $tilepath)
    or error("erreur de imagepng() ligne ".__LINE__);
  printf("  Dalle %d/%d,%d/%d créée  \r", $i, $imax, $j, $jmax);
  imagedestroy($dalle);
}

echo "Découpage OK du fichier $pngpath en ",($imax+1)," X ",$jmax+1," dalles\n";
