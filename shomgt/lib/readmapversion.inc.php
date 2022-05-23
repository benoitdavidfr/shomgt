<?php
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

// extrait du fichier MD ISO dont le path est fourni une chaine "${anneeEdition}c${lastUpdate}" de la carte
function readMapVersion(string $path): string {
  if (!($xmlmd = @file_get_contents($path)))
    throw new Exception("Fichier de MD non trouvé pour path=$path");
    
  $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
  if (!preg_match($pattern, $xmlmd, $matches)) {
    echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
    throw new Exception("edition non trouvé pour $path");
  }
  $edition = $matches[1];
  
  // ex: Edition n° 4 - 2015 - Dernière correction : 12
  if (preg_match('!^[^-]*- (\d+) - [^\d]*(\d+)$!', $edition, $matches)) { 
    $anneeEdition = $matches[1];
    $lastUpdate = $matches[2];
    return "${anneeEdition}c${lastUpdate}";
  }
  // ex: Publication 1984 - Dernière correction : 101
  elseif (preg_match('!^[^\d]*(\d+) - [^\d]*(\d+)$!', $edition, $matches)) {
    $anneeEdition = $matches[1];
    $lastUpdate = $matches[2];
    return "${anneeEdition}c${lastUpdate}";
  }
  throw new Exception("Format de l'édition inconnu pour $edition");
}
