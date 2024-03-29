<?php
/** liste les GeoTiffs
 *
 * journal: |
 * - 6/6/2022:
 *   - passage à gdalinfo -json
 * - 22/5/2022:
 *   - utilisation EnvVar
 * - 24/4/2022:
 *   - documentation
 * @package shomgt\lib
 */
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/envvar.inc.php';

/** liste des GeoTiffs
 * @return list<string> */
function geotiffs(): array { //  
  $MAPS_DIR_PATH = EnvVar::val('SHOMGT3_MAPS_DIR_PATH');
  $gtiffs = [];
  foreach (new DirectoryIterator($MAPS_DIR_PATH) as $map) {
    if ($map->isDot()) continue;
    if ($map->getType() == 'dir') {
      //echo $map->getFilename() . "<br>\n";
      foreach (new DirectoryIterator("$MAPS_DIR_PATH/$map") as $gtiff) {
        if (substr($gtiff->getFilename(), -10) <> '.info.json') continue;
        //echo '** ',$gtiff->getFilename() . "<br>\n";
        $gtiffs[] = substr($gtiff->getFilename(), 0, strlen($gtiff->getFilename())-10);
      }
    }
  }
  sort($gtiffs);
  return $gtiffs;
}

if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire

print_r(geotiffs());
