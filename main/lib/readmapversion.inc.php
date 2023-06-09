<?php
/*PhpDoc:
title: readmapversion.inc.php - extrait du fichier MD ISO dont le path est fourni la version de la carte et la date dateStamp
name: readmapversion.inc..php
doc: |
  La version est fournie sous la forme d'une chaine "${anneeEdition}c${lastUpdate}" 
  Le retour est un dict. ['version'=> {version}, 'dateStamp'=> {dateStamp}]
journal: |
  10/6/2023:
    - corrections passage Php8.2 - Deprecated: Using ${var} in strings is deprecated, use {$var} instead on line 46
  13/1/2023:
    - modif format de l'édition dans le XML -> 'Publication 1989 - Dernière correction : 149 - GAN : 2250'
  17/10/2022:
    - modif format de l'édition dans le XML
  1/8/2022:
    - ajout déclarations PhpStan pour level 6
*/

/** @return array<string, string> */
function readMapVersion(string $path): array {
  if (!($xmlmd = @file_get_contents($path)))
    throw new Exception("Fichier de MD non trouvé pour path=$path");
  
  $pattern = '!<gmd:dateStamp>\s*<gco:DateTime[^>]*>([^<]*)</gco:DateTime>\s*</gmd:dateStamp>!';
  if (!preg_match($pattern, $xmlmd, $matches)) {
    echo "<b>$path</b><br>",str_replace(['<'],['{'],$xmlmd);
    throw new Exception("dateStamp non trouvé pour $path");
  }
  $dateStamp = $matches[1];
  
  $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
  if (!preg_match($pattern, $xmlmd, $matches)) {
    echo "<b>$path</b><br>",str_replace(['<'],['{'],$xmlmd);
    throw new Exception("edition non trouvé pour $path");
  }
  $edition = $matches[1];
  
  // ex: Edition n° 4 - 2015 - Dernière correction : 12
  // ou: Edition n° 4 - 2022 - Dernière correction : 0 - GAN : 2241
  if (preg_match('!^[^-]*- (\d+) - [^\d]*(\d+)( - GAN : \d+)?$!', $edition, $matches)
  // ex: Publication 1984 - Dernière correction : 101
  // ou: Publication 1989 - Dernière correction : 149 - GAN : 2250
   || preg_match('!^[^\d]*(\d+) - [^\d]*(\d+)( - GAN : \d+)?$!', $edition, $matches)) { 
    $anneeEdition = $matches[1];
    $lastUpdate = $matches[2];
    return ['version'=> $anneeEdition.'c'.$lastUpdate, 'dateStamp'=> $dateStamp];
  }
  else
    throw new Exception("Format de l'édition inconnu pour \"$edition\"");
}
