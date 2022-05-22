<?php
/*PhpDoc:
title: geotiffs.inc.php - liste les GeoTiffs
name: geotiffs.inc.php
doc: |
journal: |
  24/4/2022:
    - documentation
*/

function geotiffs(): array { // liste des GeoTiffs 
  $gtiffs = [];
  foreach (new DirectoryIterator(getenv('SHOMGT3_MAPS_DIR_PATH')) as $map) {
    if ($map->isDot()) continue;
    if ($map->getType() == 'dir') {
      //echo $map->getFilename() . "<br>\n";
      foreach (new DirectoryIterator(getenv('SHOMGT3_MAPS_DIR_PATH')."/$map") as $gtiff) {
        if (substr($gtiff->getFilename(), -5) <> '.info') continue;
        //echo '** ',$gtiff->getFilename() . "<br>\n";
        $gtiffs[] = substr($gtiff->getFilename(), 0, strlen($gtiff->getFilename())-5);
      }
    }
  }
  sort($gtiffs);
  return $gtiffs;
}

if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire

print_r(geotiffs());
