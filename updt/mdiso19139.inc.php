<?php
/*PhpDoc:
name: mdiso19139.inc.php
title: mdiso19139.inc.php - récupère des infos à partir des MD ISO 1939 de la carte
journal: |
  11/12/2020:
    - renommage en mdiso19139.inc.php
  16/11/2019:
    retourne aussi la dernière correction
  2/11/2019:
    création
*/

// récupère les infos d'édition et de la dernière correction de la carte de shomgt dans les métadonnées XML du GéoTIFF
// $gtname est la clé du géotiff dans shomgt.yaml
// retourne [] si le fichier est absent
function mdiso19139(string $gtname): array {
  $mdname = str_replace('/', '/CARTO_GEOTIFF_', $gtname);
  $filepath = __DIR__."/../../../shomgeotiff/current/$mdname.xml";
  if (!is_file($filepath))
    return [];
  $xmlmd = file_get_contents($filepath);
    
  $pattern = '!<gmd:dateStamp>\s*<gco:DateTime[^>]*>([^<]*)</gco:DateTime>\s*</gmd:dateStamp>!';
  if (!preg_match($pattern, $xmlmd, $matches)) {
    echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
    die();
  }
  $md['mdDate'] = $matches[1];

  $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
  if (!preg_match($pattern, $xmlmd, $matches)) {
    echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
    die();
  }
  $edition = $matches[1];
  if (preg_match('!^(.* - \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Edition n° 4 - 2015 - Dernière correction : 12
    $md += ['édition'=> $matches[1], 'dernièreCorrection'=> $matches[2]];
  elseif (preg_match('!^(.* \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Publication 1984 - Dernière correction : 101
    $md += ['édition'=> $matches[1], 'dernièreCorrection'=> $matches[2]];
  else
    $md += ['édition'=> $edition];
  
  return $md;
}


// Tests unitaires de la fonction
if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


echo json_encode(mdiso19139('7442/7442_pal300'), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

