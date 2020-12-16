<?php
/*PhpDoc:
name: france.inc.php
title: cat2 / france.inc.php - calcul l'intersection avec la ZEE
classes:
doc: |
  
journal: |
  16/12/2020:
    - création
includes: [../lib/gegeom.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

class France {
  const SEUIL_PETITES_ECHELLES = 1e7; // Les cartes dont le dén. d'éch. est supérieur sont d'intérêt
  static $zee = null; // le MultiPolygone de la ZEE
  static $interetInsuffisant = null; // dictionnaire des cartes d'intérêt insuffisant
  
  // calcule si la carte est d'intérêt, la géométrie qui doit être Polygon ou MultiPolygon
  static function interet(string $mapid, string $scaleDenominator, Geometry $geometry): bool {
    $ret = self::interet2($mapid, $scaleDenominator, $geometry);
    //if ($mapid == 'FR6977')
      //echo "mapsFrenchAreas($mapid) = ", $ret ? "true\n" : "false\n";
    return $ret;
  }

  static function interet2(string $mapid, string $scaleDenominator, Geometry $geometry): bool { // calcule si la carte est d'intérêt
    if (!self::$zee) {
      $fc = json_decode(file_get_contents(__DIR__.'/france.geojson'), true);
      //echo Yaml::dump(['$france'=> $france], 5, 2);
      $mpCoords = [];
      foreach ($fc['features'] as $feature) {
        $mpCoords[] = $feature['geometry']['coordinates'];
      }
      self::$zee = Geometry::fromGeoJSON(['type'=> 'MultiPolygon', 'coordinates'=> $mpCoords]);
      //echo "zee_france = $zee_france\n";
    }
    if (!self::$interetInsuffisant) {
      self::$interetInsuffisant = Yaml::parseFile(__DIR__.'/mapcatspec.yaml')['cartesAyantUnIntérêtInsuffisant'];
      //print_r($interetInsuffisant);
    }
    if (isset(self::$interetInsuffisant[$mapid]))
      return false;
    if (str_replace('.','',$scaleDenominator) > self::SEUIL_PETITES_ECHELLES) // je conserve les très petites échelles
      return true;
    //echo "bbox=",$this->bbox,"\n";
    return self::$zee->inters($geometry);
  }
};
