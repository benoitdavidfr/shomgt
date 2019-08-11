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
  11/4/2019:
    Traitement du cas particulier 6835/6835_pal300 pour lequel le cadre intersecte l'antiméridien mais pas le contenu
    de la carte
  9/4/2019:
    traitement du cas particulier de FR0101 par modif de gdainfo.inc.php
  3/4/2019:
    gestion des contraintes d'ordre des GT défini dans updt.yaml
  1/4/2019:
    Les GéoTiff pris en compte sont ceux dans current
    Suppression de l'option merge'
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
  - ../vendor/autoload.php
  - ../lib/gebox.inc.php
  - ../lib/coordsys.inc.php
  - gdalinfo.inc.php
  - ../cat/mapcat.inc.php
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/coordsys.inc.php';
require __DIR__.'/gdalinfo.inc.php';
require __DIR__.'/../cat/mapcat.inc.php';
use Symfony\Component\Yaml\Yaml;

// classe définissant l'intervalle d'échelles de chaque couche de GéoTiff
class LayerScaleDen {
  // liste des couches regroupant les GéoTiff avec pour chacune la valeur max du dénominateur d'échelle des GéoTiff
  // contenus dans la couche
  static $layersScaleDenMax = [
    '12k'=> 1.7e4,
    '25k'=> 3.5e4,
    '50k'=> 7e4,
    '100k'=> 1.6e5,
    '250k'=> 3.5e5,
    '500k'=> 7e5,
    '1M'=> 1.4e6,
    '2M'=> 3e6,
    '4M'=> 6e6,
    '10M'=> 1.4e7,
    '20M'=> 9e999,
  ];
  
  // détermination de $lyrName à partir de $shomgtgan['scaleD']
  static function getLyrName(int $scaleD): string {
    // liste des catégories de cartes avec intervalle d'échelles
    //echo "getLyrName(scaleD=$scaleD)\n";
    $lyrName = '';
    foreach (self::$layersScaleDenMax as $lyrName => $scaleDenMax) {
      if ($scaleD < $scaleDenMax)
        return "gt$lyrName";
    }
    return '';
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
  while (($file = readdir($mapdir)) !== false) {
    if (!preg_match('!^(.*)\.info$!', $file, $matches))
      continue;
    $fbname = $matches[1];
    if (!($gdalinfo = gdalinfo("$currentpath/$mapname/$fbname.info")))
      continue;
    $gtbbox = $gdalinfo['gbox'];
    $width = $gdalinfo['width'];
    $height = $gdalinfo['height'];
    try {
      $shomgtgan = MapCat::getCatInfoFromGtName("$mapname/$fbname", $gtbbox);
    }
    catch (Exception $e) {
      echo "# Erreur ",$e->getMessage()," sur MapCat::getCatInfoFromGtName($mapname/$fbname, gtbbox)\n";
      continue;
    }
    //echo "<pre>shomgtgan="; print_r($shomgtgan); echo "</pre>\n";
    $lyrName = LayerScaleDen::getLyrName(str_replace('.','',$shomgtgan['scaleDenominator']));
    if (preg_match('!^. - !', $shomgtgan['title']))
      $title = "$shomgtgan[num]-$shomgtgan[title]";
    else
      $title = "$shomgtgan[num] - $shomgtgan[title]";
    // Calcul des 2 boites en WorldMercator pour effectuer l'interpolation
    // Cas particulier 6835/6835_pal300 pour lequel le caddre intersecte l'antiméridien mais pas le contenu de la carte
    if ($gtbbox->west() > $shomgtgan['gbox']->east()) {
      //echo "***** Cas particulier $fbname *****\n";
      $gtbbox2 = new GBox([[$gtbbox->west()-360, $gtbbox->south()], [$gtbbox->east()-360, $gtbbox->north()]]);
      $gdalbox = $gtbbox2->proj('WorldMercator');
    }
    else {
      $gdalbox = $gtbbox->proj('WorldMercator');
    }
    $ganbox = $shomgtgan['gbox']->proj('WorldMercator');
    $left = ceil(($ganbox->west() - $gdalbox->west()) / $gdalbox->dx() * $width);
    if (($left <= 0) || ($left > $width/2))
      $left = 400;
    $bottom = ceil(($ganbox->south() - $gdalbox->south()) / $gdalbox->dy() * $height);
    if (($bottom <= 0) || ($bottom > $height/2))
      $bottom = 400;
    $right = ceil(($gdalbox->east() - $ganbox->east()) / $gdalbox->dx() * $width);
    if (($right <= 0) || ($right > $width/2))
      $right = 400;
    $top = ceil(($gdalbox->north() - $ganbox->north())/ $gdalbox->dy() * $height);
    if (($top <= 0) || ($top > $height/2))
      $top = 400;
    $shomgt[$lyrName]["$mapname/$fbname"] = [
      'title'=> $title,
      'edition'=> $shomgtgan['issued'],
      'scaleden'=> $shomgtgan['scaleDenominator'],
      'width'=> $width,
      'height'=> $height,
      'south'=> $gtbbox->south(),
      'west'=> $gtbbox->west(),
      'north'=> $gtbbox->north(),
      'east'=> $gtbbox->east(),
      'left'=> $left,
      'bottom'=> $bottom,
      'right'=> $right,
      'top'=> $top,
    ];
  }
}


// chgt d'ordre des GT dans les couches pour respecter les contraintes définies dans updt.yaml
class OnTop {
  static $onTop; // couples (gt1, gt2) où gt1 est au dessus de gt2, cad gt1 doit être après gt2 dans la liste
  
  static function init(string $yamlpath) {
    $updt = Yaml::parseFile($yamlpath);
    self::$onTop = $updt['onTop'];
  }
  
  static function num(array $array, string $key): int {
    foreach ($array as $num => $value)
      if ($value == $key)
        return $num;
    return -1;
  }
  
  // change l'ordre du tableau $gtnames en mettant l'élément topNum juste après bellowNum
  static function chgOrder(array $gtnames, int $topNum, int $bellowNum): array {
    // recopie des élts avant topnum
    if ($topNum == 0)
      $result = [];
    else
      $result = array_slice($gtnames, 0, $topNum);
    if ($bellowNum > $topNum + 1)
      $result = array_merge($result, array_slice($gtnames, $topNum, $bellowNum - $topNum - 1));
    $result[] = $gtnames[$bellowNum];
    $result[] = $gtnames[$topNum];
    if ($bellowNum < count($gtnames))
      $result = array_merge($result, array_slice($gtnames, $bellowNum +1));
    //echo "result="; print_r($result);
    return $result;
  }
  
  // L'algorithme consiste pour chaque couple (top, bellow) à mettre top juste après bellow
  static function assess(string $lyrname, array $layer): array {
    //echo "layer="; print_r($layer);
    $gtnames = array_keys($layer);
    //echo "layer $lyrname, gtnames="; print_r($gtnames);
    foreach (self::$onTop as $top => $bellow) {
      if (!isset($layer[$top]) || !isset($layer[$bellow]))
        continue;
      $topNum = self::num($gtnames, $top);
      $bellowNum = self::num($gtnames, $bellow);
      //echo "top=$top doit être après bellow=$bellow\n";
      //echo "topNum=$topNum, bellowNum=$bellowNum\n";
      if ($bellowNum > $topNum)
        $gtnames = self::chgOrder($gtnames, $topNum, $bellowNum);
      //echo "layer $lyrname, gtnames="; print_r($gtnames);
    }
    // fabrication d'une nouvelle layer respectant le nouvel ordre des gtnames
    $newLayer = [];
    foreach ($gtnames as $gtname)
      $newLayer[$gtname] = $layer[$gtname];
    return $newLayer;
  }
};

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
            "    title: $gt[title]\n",
            (isset($gt['edition']) ? "    edition: $gt[edition]\n" : ''),
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
