<?php
namespace shomft;
/*PhpDoc:
name: shomft/frzee.inc.php
title: shomft/frzee.inc.php - Exploite le fichier frzee.geojson
*/
require_once __DIR__.'/../lib/gegeom.inc.php';

/* Chaque objet de la classe Zee correspond à un polygone de la ZEE française
* $all contient la liste des polygones de la ZEE chacun associé à une zoneid
* permet d'indiquer pour un $mpol quel zoneid il intersecte
*/
class Zee {
  const GEOJSON_FILE_PATH = __DIR__.'/../shomft/frzee.geojson';
  protected string $id;
  protected \gegeom\Polygon $polygon;
  /** @var array<int, Zee> $all */
  static array $all=[]; // contenu de la collection sous la forme [ Zee ]
  /** retourne la liste des zoneid des polygones intersectant la géométrie
   * @return array<int, string> */
  static function inters(\gegeom\MultiPolygon|\gegeom\GBox $geom): array {
    //echo "La classe de geom est ",get_class($geom),"<br>\n";
    //echo "Les classes parentes sont: ", implode(', ', class_parents($geom)),"<br>";
    if ((get_class($geom) == 'gegeom\GBox') || in_array('gegeom\GBox', class_parents($geom))) {
      //echo "dans Zee::inters() geom est un GBox ou un de ses enfants<br>\n";
      $coords = $geom->polygon(); // Les 5 positions définissant le GBox
      $mpol = new \gegeom\MultiPolygon([$coords]);
    }
    else {
      //echo "dans Zee::inters() geom n'est PAS un GBox ou un de ses enfants<br>\n";
      $mpol = $geom;
    }
    if (!self::$all)
      self::init();  
    $result = [];
    foreach (self::$all as $zee) {
      if ($mpol->inters($zee->polygon))
        $result[$zee->id] = 1;
    }
    ksort($result);
    return array_keys($result);
  }
  
  private static function init(): void { // initialise Zee
    $FeatureCollection = json_decode(file_get_contents(Zee::GEOJSON_FILE_PATH), true);
    foreach ($FeatureCollection['features'] as $feature) {
      switch ($type = $feature['geometry']['type']) {
        case 'Polygon': {
          self::$all[] = new self($feature['properties']['zoneid'], new \gegeom\Polygon($feature['geometry']['coordinates']));
          break;
        }
        case 'MultiPolygon': {
          foreach ($feature['geometry']['coordinates'] as $pol) {
            self::$all[] = new self($feature['properties']['zoneid'], new \gegeom\Polygon($pol));
          }
          break;
        }
        default: {
          throw new \Exception("Dans frzee.geojson, geometry de type '$type' non prévue");
        }
      }
    }
  }
  
  private function __construct(string $id, \gegeom\Polygon $polygon) {
    $this->id = $id;
    $this->polygon = $polygon;
  }
};
