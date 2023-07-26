<?php
/* bo/neworg.php - mise en oeuvre de la nouvelle organisation de shomgeotiff
  Principes
    - 3 sous-répertoires de shomgeotiff
    - archives
      - avec un répertoire par carte, nommé par le num. de la carte
      - dans ce répertoire de carte
        - 2 fichiers .7z et .md.json par version de carte
          - en utilisant un nom de base de la forme {mapnum}-{GanWeek}
          - où {GanWeek} est la semaine Shom de publication de la version constituée de 4 chiffres
            - 2 premiers chiffres correspondant aux 2 derniers chiffres de l'année
            - 2 derniers chiffres correspondant à la semaine
          - quand la semaine de publication est inconnue on utilise la semaine de dépôt de la carte
        - éventuellement un fichier {mapnum}-{GanWeek}.md.json indiquant l'obsolescence de la carte
          - où {GanWeek} est la semaine Shom de parution du retrait de la carte
          - ou si cette semaine n'est pas connue la semaine de fourniture de l'info
    - current
      - pour chaque carte non obsolète 2 liens vers la dernière version de la carte dans archives nommés respectivement
        - {mapnum}.7z pour le contenu de la carte
        - {mapnum}.md.json pour les métadonnées associées
      - pour chaque carte obsolète un lien nommé {mapnum}.md.json
        - vers le fichier {mapnum}-{GanWeek}.md.json indiquant l'obsolescence de la carte
    - users
      - un répertoire par utilisateur nommé avec son adresse email
      - contenant les cartes en cours de dépôt
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/mapmetadata.inc.php';

use Symfony\Component\Yaml\Yaml;

define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
//define ('SHOMGEOTIFF', '/var/www/html/shomgeotiff');

// déduit la semaine GAN d'un nom de livraison
/*function ganWeek(string $archiveName): string {
  if (!preg_match('!^(\d{4})(\d{2})!', $archiveName, $matches))
    throw new Exception("archiveName=$archiveName inadapté");
  $year = $matches[1];
  $month = $matches[2];
  $day = preg_match('!^(\d{4})(\d{2})(\d{2})!', $archiveName, $matches) ? $matches[3] : 1;
  $date = new DateTimeImmutable();
  $newDate = $date->setDate($year, $month, $day);
  //print_r($newDate);
  return $newDate->format('oW');
}*/

/* copie de la partie archives
if (!is_dir(SHOMGEOTIFF."/newarchives"))
  mkdir(SHOMGEOTIFF."/newarchives");

if (0)
foreach (new DirectoryIterator(SHOMGEOTIFF."/archives") as $archiveName) {
  if (in_array($archiveName, ['.','..','.DS_Store'])) continue;
  $ganWeek = substr(ganWeek($archiveName), 2); // je conserve l'année sur 2 chiffres uniquement
  //echo "$archiveName -> $ganWeek\n";
  foreach (new DirectoryIterator(SHOMGEOTIFF."/archives/$archiveName") as $mapName) {
    if (substr($mapName, -8) <> '.md.json') continue;
    $mapNum = substr($mapName, 0, -8);
    if (!is_dir(SHOMGEOTIFF."/newarchives/$mapNum"))
      mkdir(SHOMGEOTIFF."/newarchives/$mapNum");
    $md = json_decode(file_get_contents(SHOMGEOTIFF."/archives/$archiveName/$mapNum.md.json"), true);
    if (isset($md['ganWeek']) && $md['ganWeek'])
      $ganWeek = $md['ganWeek'];
    foreach (['7z','md.json'] as $ext) {
      if (is_file(SHOMGEOTIFF."/archives/$archiveName/$mapNum.$ext")) {
        rename(SHOMGEOTIFF."/archives/$archiveName/$mapNum.$ext", SHOMGEOTIFF."/newarchives/$mapNum/$mapNum-$ganWeek.$ext");
        echo "SHOMGEOTIFF/archives/$archiveName/$mapNum.$ext -> SHOMGEOTIFF/newarchives/$mapNum/$mapNum-$ganWeek.$ext\n";
      }
    }
  }
}

// Correction du bug d'utilisation de la semaine GAN sur 6 chiffres au lieu de 4
/*foreach (new DirectoryIterator(SHOMGEOTIFF."/newarchives") as $mapNum) {
  if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
  foreach (new DirectoryIterator(SHOMGEOTIFF."/newarchives/$mapNum") as $mapName) {
    if (preg_match('!^(\d{4}-)(\d{6})(\..+)$!', $mapName, $matches)) {
      $newMapName = $matches[1].substr($matches[2], 2).$matches[3];
      rename(SHOMGEOTIFF."/newarchives/$mapNum/$mapName", SHOMGEOTIFF."/newarchives/$mapNum/$newMapName");
      echo "SHOMGEOTIFF/newarchives/$mapNum/$mapName -> SHOMGEOTIFF/newarchives/$mapNum/$newMapName\n";
    }
  }
}*/

// reconstruction de current
/*foreach (new DirectoryIterator(SHOMGEOTIFF."/archives") as $mapNum) {
  if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
  foreach (new DirectoryIterator(SHOMGEOTIFF."/archives/$mapNum") as $mapName) {
    if (substr($mapName, -8) <> '.md.json') continue;
    $lastMapName = (string)$mapName;
  }
  echo "SHOMGEOTIFF/archives/$mapNum/$lastMapName\n";
  if (!symlink("../archives/$mapNum/$lastMapName", SHOMGEOTIFF."/current/$mapNum.md.json"))
    die("Erreur sur symlink(../archives/$mapNum/$lastMapName, SHOMGEOTIFF./current/$mapNum.md.json)\n");
  echo "symlink(../archives/$mapNum/$lastMapName, SHOMGEOTIFF./current/$mapNum.md.json)\n";
  $lastMapName = substr($lastMapName, 0, -8);
  if (is_file(SHOMGEOTIFF."/archives/$mapNum/$lastMapName.7z")) {
    if (!symlink("../archives/$mapNum/$lastMapName.7z", SHOMGEOTIFF."/current/$mapNum.7z"))
      die("Erreur sur symlink(../archives/$mapNum/$lastMapName.7z, SHOMGEOTIFF./current/$mapNum.7z)\n");
    echo "symlink(../archives/$mapNum/$lastMapName.7z, SHOMGEOTIFF./current/$mapNum.7z)\n";
  }
  else
    echo "No .7z\n";
}*/

// différence entre maps.json
/*if (0) {
  $geoapi = json_decode(file_get_contents('geoapi-maps.json'), true);
  $local = json_decode(file_get_contents('local-maps.json'), true);
  foreach ($local as $mapNum => $localMap) {
    $geoapiMap = $geoapi[$mapNum] ?? 'undef';
    if ($geoapiMap == 'undef')
      echo "$mapNum -> geoapi non défini\n";
    elseif (($localMap['status'] == $geoapiMap['status']) && ($localMap['status'] == 'obsolete'))
      echo ''; //"$mapNum -> obsolete ok\n";
    elseif (($localMap['status'] == $geoapiMap['status']) && ($localMap['lastVersion'] == $geoapiMap['lastVersion']))
      echo ''; // "$mapNum -> status + lastVersion ok\n";
    else {
      echo json_encode([
        $mapNum => [
          'local'=> $localMap,
          'geoapi'=> $geoapiMap,
        ]],
        JSON_OPTIONS),"\n";
    }
    unset($geoapi[$mapNum]);
  }
  foreach ($geoapi as $mapNum => $geoapiMap) {
    echo json_encode([
        $mapNum => [
          'local'=> 'undef',
          'geoapi'=> $oldMap,
        ]],
        JSON_OPTIONS),"\n";
  }
}*/

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

$mapCat = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml');

// reconstruction des md.json
foreach (new DirectoryIterator("$PF_PATH/archives") as $mapNum) {
  if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
  $mapCatOfMap = $mapCat['maps']["FR$mapNum"] ?? []; // non défini pour les cartes obsolètes
  foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $mdName) {
    if (substr($mdName, -8) <> '.md.json') continue;
    $nameOf7z = substr($mdName, 0, -8).'.7z';
    if (is_file("$PF_PATH/archives/$mapNum/$nameOf7z")) { // MD de carte non obsolète
      echo "mdName=$mdName -> NON obsolete\n";
      $md = MapMetadata::getFrom7z("$PF_PATH/archives/$mapNum/$nameOf7z", '', $mapCatOfMap['geotiffNames'] ?? []);
      file_put_contents("$PF_PATH/archives/$mapNum/$mdName", json_encode($md, JSON_OPTIONS));
    }
    else {
      echo "fichier $PF_PATH/archives/$mapNum/$nameOf7z absent\n";
      echo "mdName=$mdName -> obsolete\n";
      $ganWeek = substr($mdName, 5, 4);
      $md = [
        'status'=> 'obsolete',
        'date'=> ['value'=> ganWeek2iso($ganWeek)],
      ];
      file_put_contents("$PF_PATH/archives/$mapNum/$mdName", json_encode($md, JSON_OPTIONS));
    }
  }
}
