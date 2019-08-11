<?php
/*PhpDoc:
name: frame.php
title: frame.php - vérifie la taille du cadre de la carte
doc: |
  Ce script est utilisé pour vérifier la taille du cadre définie dans ../ws/shomgt.yaml
  Il est appelé depuis la carte ../map.php
  Il a permis d'identifier les erreurs du GAN listées dans ../cat/gancorrections.yaml
  L'appel sans paramètre fmt génère une page HTML appellant le même script avec le paramètre fmt=img
  dans une balise <img> en taille réduite et dans une balise <a_href> en taille réelle.
  Ainsi en cliquant sur l'image réduite, on obtient l'image en taille réelle
includes:
  - ../lib/gebox.inc.php
  - ../lib/coordsys.inc.php
  - ../ws/geotiff.inc.php
  - ../cat/mapcat.inc.php
*/
ini_set('memory_limit', '12800M');
  
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/coordsys.inc.php';
require_once __DIR__.'/../ws/geotiff.inc.php';

$gtname = isset($_GET['gtname']) ? $_GET['gtname'] : '7211/7211_pal300';

// cas particulier, ex 6817/6817_pal300-East
if (!preg_match('!^\d\d\d\d/\d\d\d\d_(pal300|(\d+|[A-Z])_gtw)$!', $gtname)) {
  if (preg_match('!^(\d\d\d\d/\d\d\d\d_(pal300|(\d+|[A-Z])_gtw))-(East|West)$!', $gtname, $matches)) {
    echo "cas particulier $gtname -> $matches[1]<br>\n";
    $gtname = $matches[1];
  }
  else
    die("cas particulier non traité $gtname");
}

GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');
$gt = GeoTiff::get($gtname);
if (!$gt)
  die("GéoTiff $gtname absent de la cartothèque");
$gtwf = $gt->withFrame();

if (isset($_GET['fmt']) && ($_GET['fmt']=='img')) {
  if (!isset($_GET['size'])) { // taille réelle
    $width = $gt->width();
    $height = $gt->height();
  }
  else { // taille réduite a size en hauteur
    $height = (int)$_GET['size'];
    $reduction = $height / $gt->height();
    //echo "reduction=$reduction<br>\n";
    $width = round($gt->width() * $reduction);
  }

  if (!($image = imagecreatetruecolor($width, $height)))
    throw new Exception("erreur de imagecreatetruecolor() ligne ".__LINE__);
  // remplissage en transparent
  if (!imagealphablending($image, false))
    throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
  $transparent = imagecolorallocatealpha($image, 0xFF, 0xFF, 0xFF, 0x7F);
  if (!imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent))
    throw new Exception("erreur de imagefilledrectangle() ligne ".__LINE__);
  if (!imagealphablending($image, true))
    throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
  // recopie de la carte avec son cadre dans l'image
  $gtwf->imagecopytiles($image, $gt->wombox());
  /* dessin d'un rectangle noir autour de la carte
  $black = imagecolorallocatealpha($image, 0, 0, 0, 0);
  if ($black === false)
    throw new Exception("erreur de imagecolorallocatealpha() ligne ".__LINE__);
  if (!imagerectangle($image, 0, 0, $width-1, $height-1, $black))
    throw new Exception("Erreur imagerectangle() ligne ".__LINE__);*/
  // dessin du cadre de la carte en rouge transparent
  $gt->drawFrame($image, 0xFF0000, 0x40);

  header('Content-type: image/png');
  imagepng($image);
  die();
}

// calcul des marges
require_once __DIR__.'/../cat/mapcat.inc.php';

// infos issus du GAN
$catinfo = MapCat::getCatInfoFromGtName($gtname, $gt->gbox());
$ganbox = $catinfo['gbox'];
unset($catinfo['gbox']);
echo "<pre>catinfo=",json_encode($catinfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
//echo "<pre>ganbox="; print_r($ganbox); echo "</pre>\n";
echo "ganbox=", json_encode($ganbox->asArray()), "\n";
//echo "<pre>gt->gbox="; print_r($gt->gbox()); echo "</pre>\n";
echo "gt->gbox=", json_encode($gt->gbox()->asArray()), "\n";

$ganbox = $ganbox->proj('WorldMercator');
$gdalbox = $gt->gbox()->proj('WorldMercator');
$left = ceil(($ganbox->west() - $gdalbox->west()) / $gdalbox->dx() * $gt->width());
$bottom = ceil(($ganbox->south() - $gdalbox->south()) / $gdalbox->dy() * $gt->height());
$right = ceil(($gdalbox->east() - $ganbox->east()) / $gdalbox->dx() * $gt->width());
$top = ceil(($gdalbox->north() - $ganbox->north())/ $gdalbox->dy() * $gt->height());
echo "left=$left, bottom=$bottom, right=$right, top=$top</pre>\n";

$href = "frame.php?gtname=$gtname&amp;fmt=img";
echo "<a href='$href' target='_blank'><img src='$href&amp;size=500'></a>\n";

