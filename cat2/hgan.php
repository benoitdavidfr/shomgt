<?php
/*PhpDoc:
name: hgan.php
title: cat2/hgan.php - identifier les cartes à mettre à jour en interrogeant le GAN
doc: |
  L'objectif est d'identifier les cartes à mettre à jour en interrogeant le GAN.

  La démarche est:
    1) effectuer une moisson en CLI
    2) fabriquee les fichiers gans.yaml/pser à partir de la moisson
    3) afficher la liste les cartes à mettre à jour ordonnées par age décroissant

  Certains traitements se font en cli (moissonnage), d'autres en non-CLI (affichage).

  Erreur 500 semble signfier que la carte n'est pas gérée dans le GAN, il s'agit visiblement surtout de cartes outre-mer
  Ex: https://gan.shom.fr/diffusion/qr/gan/6280/1931 - Partie Nord de Raiatea - Port d'Uturoa (1/12000)
  Le qrcode donne:
    Error Page
    status code: 404
    Exception Message: N/A

journal: |
  19/12/2020:
    - définition de la classe Gan, stockage en Yaml et en pser
    - utilisation du pser pour la liste
    - définition du concept d'age pour hiérarchiser des priorités de mise à jour, plus agé <=> plus important à mettre à jour
    - affichage des cartes du catalogue par age décroissant
    - formalisation d'une doctrine d'importance des territoires poura la mise à jour
  18/12/2020:
    - création
    - 1ère étape - moissonner le GAN et afficher pour chaque carte les corrections mentionnées par le GAN
    - il faut encore
      - décider si une carte doit ou non être mise à jour
      - faire des priorités ? utiliser la distinction métropole / DOM / COM/TOM ? nbre de corrections ?
      - packager pour en faire un process simple de mise à jour
    - plusieurs erreurs détectées
      - FR6284, FR6420, FR6823, FR7040, FR7135, FR7154, FR7436
    - des particularités (mise à jour ultérieure)
      - FR6713, FR6821, FR6930, FR7271
includes: [mapcat.inc.php, gan.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/mapcat.inc.php';
require_once __DIR__.'/gan.inc.php';

use Symfony\Component\Yaml\Yaml;

if (php_sapi_name() <> 'cli') {
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>hgan</title></head><body>\n";
  if (!isset($_GET['action'])) {
    echo "hgan.php - Actions proposées:<ul>\n";
    //echo "<li><a href='?action=harvest'>Moissonner les Gan</a></li>\n";
    //echo "<li><a href='?action=rename'>Renommer les fichiers</a></li>\n";
    echo "<li><a href='?action=yamlpser'>Fabrique les fichiers gans.yaml/pser à partir de la moisson</a></li>\n";
    echo "<li><a href='?action=yaml'>Affiche le Yaml depuis la moisson</a></li>\n";
    echo "<li><a href='?action=list'>Liste les cartes avec synthèse moisson et lien vers Gan</a></li>\n";
    echo "<li><a href='?action=updt'>Liste les cartes à mettre à jour ordonnées par age décroissant</a></li>\n";
    echo "</ul>\n";
    die();
  }
  else
    $action = $_GET['action'];
}
else {
  //echo "argc=$argc\n"; print_r($argv);
  if ($argc == 1) {
    echo "usage: hgan.php {action}\n";
    echo "{action}\n";
    echo "  - harvest - Moissonne les Gan\n";
    die();
  }
  else
    $action = $argv[1];
}

$gandir = __DIR__.'/gan';

if ($action == 'list') {
  $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
  echo "<table border=1>\n",
       "<th>",implode('</th><th>', ['mapid','title','FR','lastUpdt','gan','harvest','analyze','age']),"</th>\n";
  foreach (Mapcat::maps() as $mapid => $map) {
    $mapa = $map->asArray();
    $sStart = $map->obsolete() ? '<s>' : '';
    $sEnd = $map->obsolete() ? '</s>' : '';
    $ganHref = null; // href vers le Shom
    if (isset($mapa['modified']) && !$map->obsolete()) {
      $ganWeek = ganWeek($mapa['modified']);
      $url = "https://www.shom.fr/qr/gan/$mapid/$ganWeek";
      $ganHref = "<a href='$url'>$ganWeek</a>";
    }
    $ganHHref = null; // href local
    $ganAnalyze = Gan::gans($mapid); // résultat de l'analyse
    $age = $ganAnalyze['age'] ?? null;
    $ganAnalyze = (isset($ganAnalyze['edition']) ? ['edition'=> $ganAnalyze['edition']] : [])
      + (isset($ganAnalyze['corrections']) ? ['corrections'=> $ganAnalyze['corrections']] : []);
    if (file_exists("$gandir/$mapid-$ganWeek.html")) {
      $ganHHref = "<a href='gan/$mapid-$ganWeek.html'>$ganWeek</a>";
    }
    elseif ($errors["$mapid-$ganWeek"] ?? null) {
      $ganHHref = 'error';
    }
    echo "<tr><td>$mapid</td><td>$sStart$mapa[title]$sEnd<br>$mapa[edition]</td>",
          "<td>",implode(', ', $mapa['mapsFrance']) ?? 'indéfini',"</td>",
          "<td>",$mapa['lastUpdate'] ?? 'indéfini',"</td>",
          "<td>",$ganHref ?? 'indéfini',"</td>",
          "<td>",$ganHHref ?? 'indéfini',"</td>\n",
          "<td><pre>",$ganAnalyze ? Yaml::dump($ganAnalyze) : '',"</pre></td>",
          "<td>",$age ?? 'indéfini',"</td>",
          "</tr>\n";
  }
  echo "</table>\n";
  die();
}

if ($action == 'updt') {
  echo "<table border=1>\n",
       "<th>",implode('</th><th>', ['mapid','title','age','FR','lastUpdt','gan','harvest','analyze']),"</th>\n";
  foreach (Gan::gans() as $mapid => $gan) {
    $ganArray = $gan->asArray();
    $mapa = Mapcat::maps($mapid);
    $ganHref = null; // href vers le Shom
    if (isset($mapa['modified'])) {
      $ganWeek = ganWeek($mapa['modified']);
      $url = "https://www.shom.fr/qr/gan/$mapid/$ganWeek";
      $ganHref = "<a href='$url'>$ganWeek</a>";
    }
    $ganHHref = null; // href local
    $age = $ganArray['age'] ?? null;
    $ganArray = (isset($ganArray['edition']) ? ['edition'=> $ganArray['edition']] : [])
      + (isset($ganArray['corrections']) ? ['corrections'=> $ganArray['corrections']] : []);
    if (file_exists("$gandir/$mapid-$ganWeek.html")) {
      $ganHHref = "<a href='gan/$mapid-$ganWeek.html'>$ganWeek</a>";
    }
    elseif ($ganArray['harvestError'] ?? null) {
      $ganHHref = 'error';
    }
    echo "<tr><td>$mapid</td><td>$mapa[title]<br>$mapa[edition]</td>",
            "<td>",$age ?? 'indéfini',"</td>",
          "<td>",implode(', ', $mapa['mapsFrance']) ?? 'indéfini',"</td>",
          "<td>",$mapa['lastUpdate'] ?? 'indéfini',"</td>",
          "<td>",$ganHref ?? 'indéfini',"</td>",
          "<td>",$ganHHref ?? 'indéfini',"</td>\n",
          "<td><pre>",$ganArray ? Yaml::dump($ganArray) : '',"</pre></td>",
          "</tr>\n";
  }
  echo "</table>\n";
  die();
}

function http_error_code($http_response_header): ?string { // extrait le code d'erreur Http 
  if (!isset($http_response_header))
    return 'indéfini';
  $http_error_code = null;
  foreach ($http_response_header as $line) {
    if (preg_match('!^HTTP/.\.. (\d+) !', $line, $matches))
      $http_error_code = $matches[1];
  }
  return $http_error_code;
}

if ($action == 'harvest') {
  Gan::harvest();
  die();
}

if ($action == 'yaml') {
  echo "<pre>\n";
  Gan::build();
  //print_r(Gan::$gans);
  echo Yaml::dump(Gan::allAsArray(), 4, 2);
  die();
}

if ($action == 'yamlpser') {
  Gan::build();
  file_put_contents(Gan::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  Gan::storeAsPser();
  die("Enregistrement des fichiers Yaml et pser ok\n");
}
