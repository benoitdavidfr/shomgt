<?php
/*PhpDoc:
name: shomgt.php
title: shomgt.php - génération du catalogue shomgt.yaml des GéoTiff de la livraison courante
doc: |
  Lecture des infos et extraction des coordonnées géographiques des coins
  génération du fichier Yaml des paramètres
  Traitement particulier pour le planisphère 0101:
    - un enregistrement existe 010bis est défini dans shomgt.default.yaml
    - dans current il doit y avoir un lien de 0101bis vers 0101
    - le répertoire 0101bis n'est pas pris en compte pour générer shomgt.yaml
  a priori buggé sur 2 points:
   - ne prend pas en compte les cartes à cheval sur l'anti-méridien
   - certaines coordonnées internes sont à l'extérieur du rectangle du géotiff et génère des artefacts
     exemple le left de 4232/4232_2_gtw est généré négatif !
journal: |
  10/1/2021:
    gestion des pdf non géo-référencés
  26/12/2020:
    suppression de la sortie en Yaml des infos non connues
  21/12/2020:
    passage sur cat2
  11/12/2020:
    ajout du champ mdDate déduit des MD XML, utilisation de mdiso19139() à la place de shomgtedition() pour analyser les MD
  16/11/2019:
    ajout du champ lastUpdate déduit des MD XML
  2/11/2019:
    utilisation de l'édition de la carte définie dans le fichier de MD XML à la place de celle du GAN
  29/10/2019:
    ajout des cartes AEM et MancheGrid
  22/9/2019:
    Correction d'un bug sur le changement d'ordre des GT
    Création d'un fichier ontop.inc.php pour y inclure du code de test de la classe OnTop
  11/4/2019:
    Traitement du cas particulier 6835/6835_pal300 pour lequel le cadre intersecte l'antiméridien mais pas le contenu
    de la carte
  9/4/2019:
    traitement du cas particulier de FR0101 par modif de gdalinfo.inc.php
  3/4/2019:
    gestion des contraintes d'ordre des GT défini dans updt.yaml
  1/4/2019:
    Les GéoTiff pris en compte sont ceux dans current
    Suppression de l'option merge
  30/3/2019:
    REFLEXIONS
    Ce script pourrait être revu selon les orientations suivantes:
     1) plutot que générer le catalogue de la livraison, reconstruire le catalogue de tous les GéoTiff en cours
       - l'avantage est de pouvoir effectuer des corrections d'extension ou de modifier l'ordre des cartes
         sur les GéoTiff courants plutot que sur la livraison en cours
     2) utiliser les MD ISO et non le GAN pour déterminer l'extension de la carte sans ses bords
     3) définir un fichier de corrections des extensions de carte pour tenir compte d'erreurs possibles
     4) définir un fichier d'ordonnancement des cartes
     5) tenir compte des cartes à supprimer, il faut le lier à la livraison
  
    Le principe devient donc de fabriquer un catalogue optimisé des GéoTiff pour son utilisation dans ws
    Je pars des répertoires des cartes dans current et notamment:
       - du fichier .info (produit par gdalinfo sur le .tif) qui donne:
         - l'extension du GéoTiff avec ses bords
         - la largeur et la hauteur du GéoTiff en nbre de pixels
       - du fichier des MD ISO qui donne l'extension du GéoTiff sans ses bords
     
  17/3/2019:
    - le GAN de certaines cartes est faux, notamment la carte 6643
    - un fichier de corrections est intégré dans le traitement des GAN dans shomgt/cat
  15/3/2019:
   - interpolation pour calculer top et bottom faite en WorldMercator et pas en coord. géo.
     il existe encore des erreurs, ex: "6643-C - Ile Europa" (corrigé)
  9-10/3/2019:
    création
includes:
  - ../lib/gebox.inc.php
  - ../lib/coordsys.inc.php
  - ../lib/store.inc.php
  - ../cat2/catapi.inc.php
  - gdalinfo.inc.php
  - ontop.inc.php
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/coordsys.inc.php';
require_once __DIR__.'/../lib/store.inc.php';
require_once __DIR__.'/../cat2/catapi.inc.php';
require_once __DIR__.'/gdalinfo.inc.php';
require_once __DIR__.'/ontop.inc.php';

use Symfony\Component\Yaml\Yaml;

// classe permettant de définir la couche dans laquelle sera affacté un géotiff
class LayerName {
  // liste des couches regroupant les GéoTiff avec pour chacune la valeur max du dénominateur d'échelle des GéoTiff
  // contenus dans la couche
  static $layersScaleDenMax = [
    '5k'=> 1.1e4,
    '12k'=> 2.2e4,
    '25k'=> 4.5e4,
    '50k'=> 9e4,
    '100k'=> 1.8e5,
    '250k'=> 3.8e5,
    '500k'=> 7e5,
    '1M'=> 1.4e6,
    '2M'=> 3e6,
    '4M'=> 6e6,
    '10M'=> 1.4e7,
    '20M'=> 9e999,
  ];
  
  // détermination de $lyrName à partir du num de carte et de son échelle
  static function get(string $mapname, int $scaleD): string {
    // liste des catégories de cartes avec intervalle d'échelles et cas particuliers
    //echo "getLyrName(scaleD=$scaleD)\n";
    if (in_array($mapname, ['7330','7344','7360','8502','8509','8517']))
      return 'gtaem';
    elseif (in_array($mapname, ['8101']))
      return 'gtMancheGrid';
    elseif (in_array($mapname, ['8510']))
      return 'gtZonMar';
    else {
      $lyrName = '';
      foreach (self::$layersScaleDenMax as $lyrName => $scaleDenMax) {
        if ($scaleD < $scaleDenMax)
          return "gt$lyrName";
      }
      return '';
    }
  }
}

header('Content-type: text/plain; charset="utf8"');

$shomgt = Yaml::parseFile(__DIR__.'/shomgt.default.yaml'); // [ {lyrname} => [ {gtname} => {GéoTiff} ]]
$currentpath = realpath(__DIR__.'/../../../shomgeotiff/current');
$current = opendir($currentpath)
  or die("Erreur d'ouverture du répertoire $currentpath");
$dmax = 0;
while (($mapname = readdir($current)) !== false) {
  if (!is_dir("$currentpath/$mapname") || in_array($mapname, ['.','..'])) 
    continue;
  $mapdir = opendir("$currentpath/$mapname")
    or die("Erreur d'ouverture du répertoire $currentpath/$mapname");
  if (0) { // code de test pour trouver pourquoi 7471 n'est pas traité (22/9/2019)
    if ($mapname <> '7471') {
      echo "$currentpath/$mapname skipped\n";
      continue;
    }
    echo "ouverture de $currentpath/$mapname\n";
  }
  while (($file = readdir($mapdir)) !== false) {
    if (!preg_match('!^(.*)\.info$!', $file, $matches))
      continue;
    $fbname = $matches[1];
    //echo "Traitement de $mapname/$fbname\n";
    $gdalinfo = gdalinfo($currentpath, "$mapname/$fbname");
    if (!isset($gdalinfo['gbox'])) {
      // la carte n'est pas géo-référencée avec 2 sous-cas:
      $gtgan = CatApi::getCatInfoFromGtName("$mapname/$fbname");
      // soit elle ne comporte pas d'espace principal
      if (!isset($gtgan['gbox']))
        continue;
      // soit c'est une carte spéciale fournie non-référencée
      if (isset($gtgan['borders'])) {
        $extgbox = externalGboxForNonGeoRef($gdalinfo['width'], $gdalinfo['height'], $gtgan['borders'], $gtgan['gbox']);
      }
    }
    else { // carte géo-référencée
      $extgbox = $gdalinfo['gbox'];
      $gtgan = CatApi::getCatInfoFromGtName("$mapname/$fbname", $extgbox);
      //echo '$gtgan='; print_r($gtgan);
      if (!$gtgan) {
        echo "# Erreur sur CatApi::getCatInfoFromGtName($mapname/$fbname, extgbox)\n";
        continue;
      }
    }
    $width = $gdalinfo['width'];
    $height = $gdalinfo['height'];
    $ingbox = $gtgan['gbox']; // coordonnées du cadre intérieur (zone utile) en coord. géo.
    $lyrName = LayerName::get($mapname, str_replace('.','',$gtgan['scaleDenominator']));
    if (preg_match('!^. - !', $gtgan['title']))
      $title = "$gtgan[num]-$gtgan[title]";
    else
      $title = "$gtgan[num] - $gtgan[title]";
    // Calcul des 2 boites en WorldMercator pour effectuer l'interpolation
    // Cas particulier 6835/6835_pal300 pour lequel le cadre intersecte l'antiméridien mais pas le contenu de la carte
    if ($extgbox->west() > $ingbox->east()) {
      //echo "***** Cas particulier $fbname *****\n";
      $extgbox2 = new GBox([[$extgbox->west()-360, $extgbox->south()], [$extgbox->east()-360, $extgbox->north()]]);
      $extwombox = $extgbox2->proj('WorldMercator');
    }
    else {
      $extwombox = $extgbox->proj('WorldMercator'); // coord. extérieures de la carte en WOM 
    }
    $inwombox = $ingbox->proj('WorldMercator'); // coordonnées du cadre intérieur (zone utile) en WOM
    $left = ceil(($inwombox->west() - $extwombox->west()) / $extwombox->dx() * $width);
    if (($left <= 0) || ($left > $width/2))
      $left = 400;
    $bottom = ceil(($inwombox->south() - $extwombox->south()) / $extwombox->dy() * $height);
    if (($bottom <= 0) || ($bottom > $height/2))
      $bottom = 400;
    $right = ceil(($extwombox->east() - $inwombox->east()) / $extwombox->dx() * $width);
    if (($right <= 0) || ($right > $width/2))
      $right = 400;
    $top = ceil(($extwombox->north() - $inwombox->north())/ $extwombox->dy() * $height);
    if (($top <= 0) || ($top > $height/2))
      $top = 400;
    $mdiso19139 = (new CurrentGeoTiff("$mapname/$fbname"))->mdiso19139();
    $shomgt[$lyrName]["$mapname/$fbname"] =
      ['title'=> $title]
    + (isset($mdiso19139['edition']) ? ['edition'=> $mdiso19139['edition']] : [])
    + (isset($mdiso19139['lastUpdate']) ? ['lastUpdate'=> $mdiso19139['lastUpdate']] : [])
    + (isset($mdiso19139['mdDate']) ? ['mdDate'=> $mdiso19139['mdDate']] : [])
    + [
      'scaleden'=> $gtgan['scaleDenominator'],
      'width'=> $width,
      'height'=> $height,
      'south'=> $extgbox->south(),
      'west'=> $extgbox->west(),
      'north'=> $extgbox->north(),
      'east'=> $extgbox->east(),
      'left'=> $left,
      'bottom'=> $bottom,
      'right'=> $right,
      'top'=> $top,
    ];
  }
}

OnTop::init(__DIR__.'/updt.yaml');
foreach ($shomgt as $lyrname => $layer) {
  if ((substr($lyrname, 0, 2) == 'gt') && $layer) {
    $shomgt[$lyrname] = OnTop::assess($lyrname, $layer);
  }
}

// fabrique un texte décalé de $nbchar caractères
function tab(int $nbchar, string $text): string {
  $result = '';
  foreach (explode("\n", $text) as $line)
    $result .= str_repeat(' ', $nbchar).$line."\n";
  return $result;
}

// génération de la sortie
foreach ($shomgt as $key => $value) {
  if (substr($key, 0, 2) <> 'gt') {
    if (strpos($value, "\n") == false)
      echo "$key: $value\n";
    else
      echo "$key: |\n",tab(2, $value);
  }
  else {
    echo "\n$key:\n";
    if (!$value)
      continue;
    foreach ($value as $gtname => $gt) {
      echo  "  $gtname:\n",
            "    title: '",str_replace("'","''", $gt['title']),"'\n",
            (isset($gt['edition']) ? "    edition: $gt[edition]\n" : ''),
            (isset($gt['lastUpdate']) ? "    lastUpdate: $gt[lastUpdate]\n" : ''),
            (isset($gt['mdDate']) ? "    mdDate: '$gt[mdDate]'\n" : ''),
            (isset($gt['scaleden']) ? sprintf("    scaleden: %d\n",str_replace('.','',$gt['scaleden'])) : ''),
            sprintf("    width: %d\n",$gt['width']),
            sprintf("    height: %d\n",$gt['height']),
            sprintf("    south: %.6f\n",$gt['south']),
            sprintf("    west: %.6f\n",$gt['west']),
            sprintf("    north: %.6f\n",$gt['north']),
            sprintf("    east: %.6f\n",$gt['east']),
            sprintf("    left: %d # nbre de pixels de la bordure gauche\n",$gt['left']),
            sprintf("    bottom: %d # nbre de pixels de la bordure basse\n",$gt['bottom']),
            sprintf("    right: %d # nbre de pixels de la bordure droite\n",$gt['right']),
            sprintf("    top: %d # nbre de pixels de la bordure haute\n",$gt['top']);
    }
  }
}
die();
