<?php
/*PhpDoc:
name: neworg.php
title: bo/neworg.php - mise en oeuvre de la nouvelle organisation de shomgeotiff (PERIME)
doc: |
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
require_once __DIR__.'/../mapcat/mapcat.inc.php';
require_once __DIR__.'/mapmetadata.inc.php';

use Symfony\Component\Yaml\Yaml;

define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
define ('SHOMGEOTIFF', '/var/www/html/shomgeotiff');

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


if (0) { // différence entre maps.json
  $geoapi = json_decode(file_get_contents('maps-geoapi.json'), true);
  $local = json_decode(file_get_contents('maps-local.json'), true);
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
}

if (0) { // script de chgt de md.json et de noms qui s'est planté le 28/7/2023 17:15 
  if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
    throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
  $PF_PATH = SHOMGEOTIFF;

  // reconstruction des md.json et changement des noms
  foreach (new DirectoryIterator("$PF_PATH/archives") as $mapNum) {
    if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
    $mapCatOfMap = new MapCat($mapNum); // non défini pour les cartes obsolètes
    foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $mdName) {
      if (substr($mdName, -8) <> '.md.json') continue;
      $nameOf7z = substr($mdName, 0, -8).'.7z';
      if (is_file("$PF_PATH/archives/$mapNum/$nameOf7z")) { // MD de carte non obsolète
        echo "mdName=$mdName -> NON obsolete\n";
        $md = MapMetadata::getFrom7z("$PF_PATH/archives/$mapNum/$nameOf7z", '', $mapCatOfMap->geotiffNames ?? []);
        // suppression de l'ancien fichier .md.json
        unlink("$PF_PATH/archives/$mdName");
        // création du nouveau fichier md.json
        $version = $md['version'];
        if (!is_file("$PF_PATH/archives/$mapNum/$mapNum-$version.md.json"))
          file_put_contents("$PF_PATH/archives/$mapNum/$mapNum-$version.md.json", json_encode($md, JSON_OPTIONS));
        else
          echo "Le fichier $mapNum-$version.md.json existe, il n'est pas écrasé\n";
        // chgt du nom du 7z
        if (!is_file("$PF_PATH/archives/$mapNum/$mapNum-$version.7z"))
          rename("$PF_PATH/archives/$mapNum/$nameOf7z", "$PF_PATH/archives/$mapNum/$mapNum-$version.7z");
        else
          echo "Le fichier $mapNum/$mapNum-$version.7z existe, le fichier $mapNum/$nameOf7z n'est pas renommé\n";
      }
      else {
        echo "fichier $PF_PATH/archives/$mapNum/$nameOf7z absent\n";
        echo "mdName=$mdName -> obsolete\n";
        // passage de ganWeek à date
        $ganWeek = substr($mdName, 5, 4);
        $isodate = ganWeek2iso($ganWeek);
        $md = [
          'status'=> 'obsolete',
          'date'=> $isodate,
        ];
        // suppression de l'ancien fichier .md.json
        //unlink("$PF_PATH/archives/$mdName");
        // création du nouveau fichier md.json
        file_put_contents("$PF_PATH/archives/$mapNum/$mapNum-$isodate.md.json", json_encode($md, JSON_OPTIONS));
      }
    }
  }
}

if (0) { // géréation des nouveaux noms des md.json des versions obsolètes - 29/7/2023
  if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
    throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
  $PF_PATH = SHOMGEOTIFF;

  foreach (new DirectoryIterator("$PF_PATH/archives") as $mapNum) {
    if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
    $mapCatOfMap = new MapCat($mapNum); // non défini pour les cartes obsolètes
    foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $mdName) {
      if (substr($mdName, -8) <> '.md.json') continue;
      $md = json_decode(file_get_contents("$PF_PATH/archives/$mapNum/$mdName"), true);
      if (($md['status'] ?? null) == 'obsolete') {
        echo "$mdName obsolete\n";
        if (preg_match('!^(\d{4})-(\d{4})\.md\.json$!', $mdName, $matches)) { // ancien nom avec ganWeek
          if (0) {
            $mapNum = $matches[1];
            $ganWeek = $matches[2];
            $isodate = ganWeek2iso($ganWeek);
            if (is_file("$PF_PATH/archives/$mapNum/$mapNum-$isodate.md.json"))
              echo "Le fichier $mapNum/$mapNum-$isodate.md.json existe déjà\n";
            else {
              file_put_contents(
                "$PF_PATH/archives/$mapNum/$mapNum-$isodate.md.json",
                json_encode(
                  [ 'status'=> 'obsolete', 'date'=> $isodate ],
                  JSON_OPTIONS
                )
              );
              echo "Le fichier $mapNum/$mapNum-$isodate.md.json est généré\n";
            }
          }
          echo "effacement du fichier $PF_PATH/archives/$mapNum/$mdName\n";
          unlink("$PF_PATH/archives/$mapNum/$mdName");
        }
      }
    }
  }
}

if (0) { // reconstruction des md.json et changement des noms des .7z - 29/7/2023
  if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
    throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
  $PF_PATH = SHOMGEOTIFF;

  foreach (new DirectoryIterator("$PF_PATH/archives") as $mapNum) {
    if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
    $mapCatOfMap = new MapCat($mapNum); // non défini pour les cartes obsolètes
    foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $nameOf7z) {
      if (preg_match('!^\d{4}-\d{4}\.md\.json$!', $nameOf7z)) {
        unlink("$PF_PATH/archives/$mapNum/$nameOf7z");
        continue;
      }
      if (substr($nameOf7z, -3) <> '.7z') continue;
      echo "Traitement de $nameOf7z\n";
      $md = MapMetadata::getFrom7z("$PF_PATH/archives/$mapNum/$nameOf7z", '', $mapCatOfMap->geotiffNames ?? []);
      // création du nouveau fichier md.json
      $version = $md['version'];
      file_put_contents("$PF_PATH/archives/$mapNum/$mapNum-$version.md.json", json_encode($md, JSON_OPTIONS));
      // chgt du nom du 7z
      if (!is_file("$PF_PATH/archives/$mapNum/$mapNum-$version.7z")) {
        echo "   $mapNum/$nameOf7z -> $mapNum/$mapNum-$version.7z\n";
        rename("$PF_PATH/archives/$mapNum/$nameOf7z", "$PF_PATH/archives/$mapNum/$mapNum-$version.7z");
      }
      else
        echo "  Le fichier $mapNum/$mapNum-$version.7z existe, le fichier $mapNum/$nameOf7z n'est pas renommé\n";
    }
  }
}

/*if (0) { // Mise à jour de current - 29/7/2023 
  /* Le principe de l'algorithme est:
     j'efface les fichiers dans current
     POUR chaque carte
       POUR chaque balayer les md.json
         construire un tableau Php avec comme clé la version recodée sur YYYYcCCCC et comme valeur le nom du fchier
         si j'ai un md.json d'obsolescence alors je met la clé 9999c9999
         si j'ai une version hors format je met la version 0000c0000
       FIN_POUR
       je trie le tableau Php sur la clé
       la dernière clé correspond à la version la plus récente
       j'effectue les liens dans current vers cette dernière version
     FIN_POUR
  *//*
  $PF_PATH = SHOMGEOTIFF;
  
  //if (0)
  foreach (new DirectoryIterator("$PF_PATH/current") as $filename) {
    if (in_array($filename, ['.','..'])) continue;
    unlink("$PF_PATH/current/$filename");
  }
  
  foreach (new DirectoryIterator("$PF_PATH/archives") as $mapNum) {
    if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
    $versions = []; // [version => nom]
    foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $mdName) {
      if (substr($mdName, -8) <> '.md.json') continue;
      elseif (preg_match('!^\d{4}-\d{4}-\d{2}-\d{2}\.md\.json$!', $mdName)) { // MD d'obsolescence
        $versions['Y9999c9999'] = (string)$mdName;
        break;
      }
      elseif (preg_match('!^(\d{4})-(\d{4})c(\d+)\.md\.json$!', $mdName, $matches)) { // carte active avec version std
        $versions['Y'.$matches[2].sprintf('c%04d', $matches[3])] = (string)$mdName;
      }
      else {
        if ($versions)
          die("Erreur, plusieurs versions non conformes pour $mapNum/$mdName\n");
        $versions['Y0000c0000'] = (string)$mdName;
      }
    }
    echo Yaml::dump([(string)$mapNum => $versions]);
    if (count($versions) > 1)
      ksort($versions); // tri sur YYYYcCCCC
    $lastKey = array_keys($versions)[count($versions)-1];
    $lastName = $versions[$lastKey];
    echo "$mapNum: last=$lastName\n";
    
    // symlink(string $target, string $link): bool
    echo "  symlink(../archives/$mapNum/$lastName, $PF_PATH/current/$mapNum.md.json)\n";
    symlink("../archives/$mapNum/$lastName", "$PF_PATH/current/$mapNum.md.json");
    if ($lastKey <> 'Y9999c9999') {
      $lastName = substr($lastName, 0, -8).'.7z';
      echo "  symlink(../archives/$mapNum/$lastName, $PF_PATH/current/$mapNum.7z)\n";
      symlink("../archives/$mapNum/$lastName", "$PF_PATH/current/$mapNum.7z");
    }
  }
}*/

if (0) { // gestion du cas particulier de la carte 0101 pour laquelle j'ai 2 fois la même version  - 29/7/2023 
  $PF_PATH = SHOMGEOTIFF;
  $mapNum = '0101';
  $nameOf7z = '0101-2123.7z';
  $md = MapMetadata::getFrom7z("$PF_PATH/archives/$mapNum/$nameOf7z");
  $version = $md['version'];
  file_put_contents("$PF_PATH/archives/$mapNum/$mapNum-{$version}a.md.json", json_encode($md, JSON_OPTIONS));
  rename("$PF_PATH/archives/$mapNum/$nameOf7z", "$PF_PATH/archives/$mapNum/$mapNum-{$version}a.7z");
  unlink("$PF_PATH/current/0101.md.json");
  unlink("$PF_PATH/current/0101.7z");
  // symlink(string $target, string $link): bool
  symlink("../archives/$mapNum/$mapNum-{$version}a.md.json", "$PF_PATH/current/$mapNum.md.json");
  symlink("../archives/$mapNum/$mapNum-{$version}a.7z", "$PF_PATH/current/$mapNum.7z");
}

if (0) { // gestion du cas particulier de la carte 0101 pour laquelle j'ai 2 fois la même version - PARTIE II - 29/7/2023 
  $PF_PATH = SHOMGEOTIFF;
  $mapNum = '0101';
  $nameOf7z = '0101-2016c0.7z';
  $md = MapMetadata::getFrom7z("$PF_PATH/archives/$mapNum/$nameOf7z");
  $version = $md['version'];
  file_put_contents("$PF_PATH/archives/$mapNum/$mapNum-{$version}.md.json", json_encode($md, JSON_OPTIONS));
}