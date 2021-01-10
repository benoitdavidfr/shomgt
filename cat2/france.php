<?php
/*PhpDoc:
name: france.php
title: cat2 / france.php - définit la fonction de calcul d'intersection avec la ZEE et publie la ZEE en GeoJSON avec l'en-tête CORS
classes:
doc: |
  
journal: |
  10/1/2021:
    - utilisation dans mapwcat.php car nécessité de l'en-tête CORS
    - changement de nom en enlevant en .inc
  19/12/2020:
    - modification de la valeur retournée par France::interet()
  16/12/2020:
    - création
includes: [../lib/gegeom.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

class France {
  const SEUIL_PETITE_ECHELLE = 1e7; // Les cartes dont le dén. d'éch. est supérieur sont d'intérêt
  static array $zee = []; // dictionnaire [isoalpha2 -> Geometry]
  static array $interetInsuffisant = []; // dictionnaire des cartes d'intérêt insuffisant
  
  static function init() { // initialise la Zee
    $fc = json_decode(file_get_contents(__DIR__.'/france.geojson'), true);
    //echo Yaml::dump(['france.geojson'=> $fc], 5, 2);
    $zee = [];
    foreach ($fc['features'] as $feature) {
      $isoa2 = $feature['properties']['isoalpha2'];
      if (!isset($zee[$isoa2])) {
        $zee[$isoa2] = $feature['geometry'];
      }
      else {
        $zee[$isoa2]['coordinates'] = array_merge($zee[$isoa2]['coordinates'], $feature['geometry']['coordinates']);
      }
    }
    foreach ($zee as $isoa2 => $geometry)
      self::$zee[$isoa2] = Geometry::fromGeoJSON($geometry);
    self::$interetInsuffisant = Yaml::parseFile(__DIR__.'/mapcatspec.yaml')['cartesAyantUnIntérêtInsuffisant'];
  }
  
  static function zeeAsGeoJSON(): array { // génération de la ZEE comme FeatureCollection pour test
    if (!self::$zee)
      self::init();
    $features = [];
    foreach (self::$zee as $isoa2 => $geometry) {
      $features[] = [
        'type'=> 'Feature',
        'properties'=> ['isoalpha2'=> $isoa2],
        'geometry'=> $geometry->asArray(),
      ];
    }
    return ['type'=>'FeatureCollection', 'features'=> $features];
  }
  
  // calcule si la carte est d'intérêt, la géométrie doit être Polygon ou MultiPolygon
  // retourne soit [] si ce n'est pas le cas, soit ['FR'] pour les cartes à très petite échelle,
  // soit la liste des codes ISO alpha2 des zones intersectées
  static function interet(string $mapid, string $scaleDenominator, Geometry $geometry): array {
    $ret = self::interet2($mapid, $scaleDenominator, $geometry);
    //if ($mapid == 'FR6977')
      //echo "mapsFrenchAreas($mapid) = ", $ret ? "true\n" : "false\n";
    return $ret;
  }

  static function interet2(string $mapid, string $scaleDenominator, Geometry $geometry): array {
    if (!self::$zee)
      self::init();
    if (isset(self::$interetInsuffisant[$mapid]))
      return [];
    if (str_replace('.','',$scaleDenominator) > self::SEUIL_PETITE_ECHELLE) // je conserve les très petites échelles
      return ['FR'];
    //echo "bbox=",$this->bbox,"\n";
    $isoa2s = [];
    foreach (self::$zee as $isoa2 => $zeeGeom)
      if ($zeeGeom->inters($geometry))
        $isoa2s[] = $isoa2;
    return $isoa2s ? $isoa2s : [];
  }
};


if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;
// Vérifie l'algo d'initialisation en affichant le ZEE en GeoJSON en Yaml ou en JSON

if (0) {
  header('Content-type: text/plain; charset="utf8"');
  echo Yaml::dump(France::zeeAsGeoJSON(), 5, 2);
}
elseif (1) {
  header('Access-Control-Allow-Origin: *');
  header('Content-type: application/json; charset="utf8"');
  echo json_encode(France::zeeAsGeoJSON(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
}
