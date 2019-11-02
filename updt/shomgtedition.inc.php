<?php
/*PhpDoc:
name: shomgtedition.inc.php
title: shomgtedition.inc.php - traitements divers
journal: |
  2/11/2019:
    création
*/

// récupère l'info d'édition de la carte de shomgt dans les métadonnées XML du GéoTIFF
// en supprimant la dernière correction
// retourne '' si le fichier est absent
// $gtname est la clé du géotiff dans shomgt.yaml
function shomgtedition(string $gtname): string {
  $mdname = str_replace('/', '/CARTO_GEOTIFF_', $gtname);
  if (!is_file(__DIR__."/../../../shomgeotiff/current/$mdname.xml"))
    return '';
  $xmlmd = file_get_contents(__DIR__."/../../../shomgeotiff/current/$mdname.xml");
  $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
  if (!preg_match($pattern, $xmlmd, $matches)) {
    echo "<b>$mdname.xml</b><br>",
         str_replace(['<'],['{'],$xmlmd);
    die();
  }
  $edition = $matches[1];
  if (preg_match('!^(.* - \d+) -!', $edition, $matches)) // ex: Edition n° 4 - 2015 - Dernière correction : 12
    return $matches[1];
  elseif (preg_match('!^(.* \d+) -!', $edition, $matches)) // ex: Publication 1984 - Dernière correction : 101
    return $matches[1];
  else
    return $edition;
}
