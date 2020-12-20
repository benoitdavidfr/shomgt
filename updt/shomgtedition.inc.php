<?php
/*PhpDoc:
name: shomgtedition.inc.php
title: shomgtedition.inc.php - récupère l'édition de la carte à partir des MD XML des cartes
journal: |
  20/12/2020:
    script remplacé par mdiso19139.inc.php dans updt mais encore utilisé par catv1
  16/11/2019:
    retourne aussi la dernière correction
  2/11/2019:
    création
*/

//throw new Exception("Ce script shomgtedition.inc.php est déclaré comme périmé car remplacé par mdiso19139.inc.php");

// récupère les infos d'édition et de la dernière correction de la carte de shomgt dans les métadonnées XML du GéoTIFF
// retourne [] si le fichier est absent
// $gtname est la clé du géotiff dans shomgt.yaml
function shomgtedition(string $gtname): array {
  $mdname = str_replace('/', '/CARTO_GEOTIFF_', $gtname);
  if (!is_file(__DIR__."/../../../shomgeotiff/current/$mdname.xml"))
    return [];
  $xmlmd = file_get_contents(__DIR__."/../../../shomgeotiff/current/$mdname.xml");
  $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
  if (!preg_match($pattern, $xmlmd, $matches)) {
    echo "<b>$mdname.xml</b><br>",
         str_replace(['<'],['{'],$xmlmd);
    die();
  }
  $edition = $matches[1];
  if (preg_match('!^(.* - \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Edition n° 4 - 2015 - Dernière correction : 12
    return [$matches[1], $matches[2]];
  elseif (preg_match('!^(.* \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Publication 1984 - Dernière correction : 101
    return [$matches[1], $matches[2]];
  else
    return [$edition];
}
