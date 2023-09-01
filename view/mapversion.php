<?php
// mapversion.php - versions des cartes dans shomgt/data
// Fonction à la fois en V3 et en V4

require_once __DIR__.'/../lib/envvar.inc.php';
require_once __DIR__.'/../lib/readmapversion.inc.php';

$MAPS_DIR_PATH = EnvVar::val('SHOMGT3_MAPS_DIR_PATH');

function shomGTVersion(): int {
  switch ($_SERVER['SCRIPT_NAME']) {
    case '/geoapi/shomgt/view/mapversion.php': return 4; // en local
    case '/shomgt3/shomgt/mapversion.php': return 3; // sur geoapi.fr en V3
    case '/shomgt/view/mapversion.php': return 4; // sur geoapi.fr en V4
    case '/view/mapversion.php': return 4; // sur sgpp.geoapi.fr en V4
    default: die("SCRIPT_NAME = $_SERVER[SCRIPT_NAME] non prévu\n");
  }
}

// Code en V4
// Renvoit le libellé de la version courante de la carte $mapnum ou '' si la carte n'existe pas
// ou 'undefined' si aucun fichier de MD n'est trouvé
function findCurrentMapVersionV4(string $MAPS_DIR_PATH, string $mapnum): string {
  /* cherche un des fichiers de MD ISO dans le répertoire de carte et en extrait la version */
  $mappath = '';
  if (!is_dir("$MAPS_DIR_PATH/$mapnum")) {
    // la carte est absente des cartes courantes
    return '';
  }
  foreach (new DirectoryIterator("$MAPS_DIR_PATH/$mapnum") as $filename) {
    if ($filename == "CARTO_GEOTIFF_{$mapnum}_pal300.xml") {
      //echo "$filename\n";
      $currentMapVersion = readMapVersion("$MAPS_DIR_PATH/$mapnum/$filename");
      //echo "findCurrentMapVersion() returns $currentMapVersion[version]\n";
      return $currentMapVersion['version'];
    }
  }
  // Cas où il n'existe pas de fichier de MD ISO standard
  // j'utilise l'extension .tfw à la place de .tif car en local le .tif a été effacé
  //echo "il n'existe pas de fichier de MD ISO standard\n";
  $fileNamePerType = [];
  foreach (new DirectoryIterator("$MAPS_DIR_PATH/$mapnum") as $filename) {
    //echo "  filename=$filename\n";
    if (in_array(substr($filename, -4), ['.xml','.tfw','.pdf']) && (substr($filename, -12)<>'.png.aux.xml')) {
      //echo "$filename match condition\n";
      $fileNamePerType[substr($filename, -3)][] = (string)$filename;
    }
  }
  //print_r($fileNamePerType);
  // si il existe un seul fichier .xml je l'utilise
  if (count($fileNamePerType['xml'] ?? []) == 1) {
    $filename = $fileNamePerType['xml'][0];
    //echo "j'utilise le seul fichier .xml '$filename'\n";
    $currentMapVersion = readMapVersion("$MAPS_DIR_PATH/$mapnum/$filename");
    //echo "findCurrentMapVersion() returns $currentMapVersion[version]\n";
    return $currentMapVersion['version'];
  }
  // sinon, si il existe un seul fichier .tif
  if (count($fileNamePerType['tfw'] ?? []) == 1) {
    $filename = $fileNamePerType['tfw'][0];
    //echo "j'utilise le seul fichier .tfw '$filename'\n";
    $version = substr($filename, 0, -4); // je prends le nom du .tfw sans l'extension
    //echo "findCurrentMapVersion() returns $version\n";
    return $version;
  }
  // sinon, si il existe un seul fichier .pdf
  if (count($fileNamePerType['pdf'] ?? []) == 1) {
    $filename = $fileNamePerType['pdf'][0];
    //echo "j'utilise le seul fichier .pdf '$filename'\n";
    //echo "findCurrentMapVersion() returns $filename\n";
    return $filename; // je prends le nom du .pdf AVEC l'extension
  }
  //echo "findCurrentMapVersion() returns 'undefined'\n";
  return 'undefined'; // sinon undefined
}

// code en V3
// Renvoit le libellé de la version courante de la carte $mapnum ou '' si la carte n'existe pas
// ou 'undefined' si aucun fichier de MD n'est trouvé
function findCurrentMapVersionV3(string $MAPS_DIR_PATH, string $mapnum): string {
  /* cherche un des fichiers de MD ISO dans le répertoire de carte et en extrait la version */
  $mappath = '';
  if (!is_dir("$MAPS_DIR_PATH/$mapnum")) {
    // la carte est absente des cartes courantes
    return '';
  }
  foreach (new DirectoryIterator("$MAPS_DIR_PATH/$mapnum") as $filename) {
    if (preg_match('!^CARTO_GEOTIFF_\d+_[^.]+\.xml!', $filename)) {
      //echo "$filename\n";
      $currentMapVersion = readMapVersion("$MAPS_DIR_PATH/$mapnum/$filename");
      //echo "currentMapVersion=$currentMapVersion\n";
      return $currentMapVersion['version'];
    }
  }
  return 'undefined';
}

function findCurrentMapVersion(string $MAPS_DIR_PATH, string $mapnum): string {
  switch($shomGTVersion = shomGTVersion()) {
    case 3: return findCurrentMapVersionV3($MAPS_DIR_PATH, $mapnum);
    case 4: return findCurrentMapVersionV4($MAPS_DIR_PATH, $mapnum);
    default: die("shomGTVersion=$shomGTVersion non prévue\n");
  }
}

echo "<!DOCTYPE html><html><head><title>mapversion@$_SERVER[HTTP_HOST]</title></head><body><pre>\n";

$versions = [];
foreach (new DirectoryIterator($MAPS_DIR_PATH) as $mapNum) {
  if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
  $versions[(string)$mapNum] = findCurrentMapVersion($MAPS_DIR_PATH, $mapNum);
}
ksort($versions);
foreach ($versions as $mapNum => $version)
  echo "$mapNum: $version\n";
