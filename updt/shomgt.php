<?php
/ *PhpDoc:
name: shomgt.php
title: shomgt.php - génération du catalogue shomgt.yaml des GéoTiff de la livraison courante
doc: |
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
  ===
  Lecture des infos et extraction des coordonnées géographiques des coins
  génération du fichier Yaml des paramètres
  a priori buggé sur 2 points:
   - ne prend pas en compte les cartes à cheval sur l'anti-méridien
   - certaines coordonnées internes sont à l'extérieur du rectangle du géotiff et génère des artefacts
     exemple le left de 4232/4232_2_gtw est généré négatif !
journal: |
  17/3/2019:
    - le GAN de certaines cartes est faux, notamment la carte 6643
    - un fichier de corrections est intégré dans le traitement des GAN dans shomgt/cat
  15/3/2019:
   - interpolation pour calculer top et bottom faite en WorldMercator et pas en coord. géo.
     il existe encore des erreurs, ex: "6643-C - Ile Europa" (corrigé)
  9-10/3/2019:
    création
*/
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/coordsys.inc.php';

header('Content-type: text/plain; charset="utf8"');

$merge = (php_sapi_name()=='cli') ? (($argc > 1) && ($argv[1]=='merge')) : isset($_GET['merge']);

// classe définissant l'intervalle d'échelles de chaque couche de GéoTiff
class LayerScaleDen {
  // liste des couches regroupant les GéoTiff avec pour chacune la valeur max du dénominateur d'échelle des GéoTiff
  // contenus dans la couche
  static $layersScaleDenMax = [
    '12k'=> 1.8e4,
    '25k'=> 3e4,
    '50k'=> 9e4,
    '100k'=> 2e5,
    '250k'=> 3e5,
    '500k'=> 7e5,
    '1M'=> 1.5e6,
    '2M'=> 3e6,
    '4M'=> 6e6,
    '10M'=> 1.6e7,
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

// traduit en degré décimal la coordonnée géographique fournie par gdalinfo
function todecdeg($val): float {
//  echo "val=$val<br>\n";
  if (is_numeric($val))
    return $val;
  if (preg_match('!^\s*([^d]+)d([^\']+)\'([^"]+)"(E|W|N|S)!', $val, $matches)) {
//    echo "<pre>matches="; print_r($matches);
    $decdeg = $matches[1] + ($matches[2] + $matches[3]/60)/60;
    return (in_array($matches[4], ['E','N']) ? $decdeg : -$decdeg); 
  } elseif (preg_match('!^([^d]+)d([^\']+)\'(E|W|N|S)!', $val, $matches)) {
//    echo "<pre>matches="; print_r($matches);
    $decdeg = $matches[1] + $matches[2]/60;
    return (in_array($matches[3], ['E','N']) ? $decdeg : -$decdeg); 
  } else
    throw new Exception("nomatch in todecdeg for !$val!");
}

// extrait du fichier gdalinfo ['gbox'=> {gbox}, 'width'=> {width}, 'height'=> {height} ]
// Si ces coordonnées sont absentes alors retourne [], le GéoTiff n'est pas géolocalisé
function gdalinfo(string $filepath): array {
  if (!($info = @file_get_contents($filepath)))
    throw new Exception("Erreur d'ouverture de $filepath");
  //die($info);

  $pattern = '!Size is (\d+), (\d+)!';
  if (!preg_match($pattern, $info, $matches))
    throw new Exception("No match for $fbname Size is\n$info");
  $width = $matches[1];
  $height = $matches[2];
  
  $pattern = '!Upper Right \(\s*(-?[\d.]+),\s*(-?[\d.]+)\) \(\s*([\dd\'." ]+[EW]),\s*([\dd\'." ]+[NS])\)!';
  if (!preg_match($pattern, $info, $matches)) {
    if (preg_match('!\(\s*(-?[\d.]+),\s*(-?[\d.]+)\)!', $info))
      return []; // le GéoTiff n'est pas géolocalisé
    else
      throw new Exception("No match for $fbname Upper Right\n$info");
  }
  $xmax = $matches[1];
  $ymax = $matches[2];
  $east = todecdeg($matches[3]);
  $north = todecdeg($matches[4]);
  $pattern = '!Lower Left  \(\s*(-?[\d.]+),\s*(-?[\d.]+)\) \(\s*([\dd\'." ]+[EW]), ([\dd\'." ]+[NS])\)!';
  if (!preg_match($pattern, $info, $matches))
    die("No match for $fbname Lower Left\n$info");
  $xmin = $matches[1];
  $ymin = $matches[2];
  $west = todecdeg($matches[3]);
  $south = todecdeg($matches[4]);
  if ($east < $west)
    $east += 360.0;
  if ($north < $south)
    throw new Exception("Erreur dans gdalinfo, north=$north < south=$south");
  return [
    'gbox'=> new GBox([$west, $south, $east, $north]),
    //'ebox'=> new EBox([$xmin, $ymin, $xmax, $ymax]),
    'width'=> $width,
    'height'=> $height,
  ];
}

require __DIR__.'/../cat/mapcat.inc.php';

$newshomgt = []; // [ {lyrname} => [ {gtname} => {GéoTiff} ]]
$shomgeotiff = realpath(__DIR__.'/../../../shomgeotiff');
$tmppath = "$shomgeotiff/tmp";
$tmp = opendir($tmppath)
  or die("Erreur d'ouverture du répertoire $tmppath");
$dmax = 0;
while (($mapname = readdir($tmp)) !== false) {
  if (!is_dir("$tmppath/$mapname") || in_array($mapname, ['.','..','0101bis'])) 
    continue;
  $mapdir = opendir("$tmppath/$mapname")
    or die("Erreur d'ouverture du répertoire $tmppath/$mapname");
  while (($file = readdir($mapdir)) !== false) {
    if (!preg_match('!^(.*)\.info$!', $file, $matches))
      continue;
    $fbname = $matches[1];
    if (!($gdalinfo = gdalinfo("$tmppath/$mapname/$fbname.info")))
      continue;
    $gtbbox = $gdalinfo['gbox'];
    $width = $gdalinfo['width'];
    $height = $gdalinfo['height'];
    $shomgtgan = MapCat::getCatInfoFromGtName("$mapname/$fbname", $gtbbox);
    //echo "<pre>shomgtgan="; print_r($shomgtgan); echo "</pre>\n";
    $lyrName = LayerScaleDen::getLyrName(str_replace('.','',$shomgtgan['scaleDenominator']));
    if (preg_match('!^. - !', $shomgtgan['title']))
      $title = "$shomgtgan[num]-$shomgtgan[title]";
    else
      $title = "$shomgtgan[num] - $shomgtgan[title]";
    // Calcul des 2 boites en WorldMercator pour effectuer l'interpolation
    $gdalbox = $gtbbox->proj('WorldMercator');
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
    $newshomgt[$lyrName]["$mapname/$fbname"] = [
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

function genyaml(array $shomgt): string {
  $yaml = '';
  foreach ($shomgt as $lyrName => $layer) {
    if (substr($lyrName, 0, 2) <> 'gt')
      continue;
    $yaml .= "\n$lyrName:\n";
    if (!$layer)
      continue;
    foreach ($layer as $gtname => $gt) {
      $yaml .= "  $gtname:\n"
            ."    title: $gt[title]\n"
            .(isset($gt['edition']) ? "    edition: $gt[edition]\n" : '')
            .(isset($gt['scaleden']) ? sprintf("    scaleden: %d\n",str_replace('.','',$gt['scaleden'])) : '')
            .sprintf("    width: %d\n",$gt['width'])
            .sprintf("    height: %d\n",$gt['height'])
            .sprintf("    south: %.6f\n",$gt['south'])
            .sprintf("    west: %.6f\n",$gt['west'])
            .sprintf("    north: %.6f\n",$gt['north'])
            .sprintf("    east: %.6f\n",$gt['east'])
            .sprintf("    left: %d # nbre de pixels de la bordure gauche\n",$gt['left'])
            .sprintf("    bottom: %d # nbre de pixels de la bordure basse\n",$gt['bottom'])
            .sprintf("    right: %d # nbre de pixels de la bordure droite\n",$gt['right'])
            .sprintf("    top: %d # nbre de pixels de la bordure haute\n",$gt['top']);
    }
  }
  return $yaml;
}

require_once __DIR__.'/../../../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

function tab(int $nbchar, string $text): string {
  $result = '';
  foreach (explode("\n", $text) as $line)
    $result .= str_repeat(' ', $nbchar).$line."\n";
  return $result;
}

if (!$merge) {
  // affichage du fragment Yaml correspondant aux nouvelles cartes
  echo genyaml($newshomgt);
}
else {
  // fusion de l'ancien et du nouveau fichier
  $oldshomgt = Yaml::parsefile(__DIR__.'/../ws/shomgt.yaml');
  foreach ($newshomgt as $lyrname => $layer) {
    if ($lyrname == 'gt20M')
      continue;
    if (isset($oldshomgt[$lyrname]))
      $oldshomgt[$lyrname] = array_merge($oldshomgt[$lyrname], $newshomgt[$lyrname]);
    else
      $oldshomgt[$lyrname] = $newshomgt[$lyrname];
  }
  foreach ($oldshomgt as $key => $value) {
    if (substr($key, 0, 2) == 'gt')
      continue;
    if (strpos($value, "\n") == false)
      echo "$key: $value\n";
    else
      echo "$key: |\n",tab(2, $value);
  }
  echo genyaml($oldshomgt);
}
die();
