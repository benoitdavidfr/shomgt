<?php
/*PhpDoc:
name: feature.inc.php
title: lib/feature.inc.php - définition de la classe Feature GeoJSON utilisée par les serveurs Wfs
classes:
doc: |
journal: |
  22/12/2020:
    création
includes: [gjbox.inc.php, gegeom.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/gjbox.inc.php';
require_once __DIR__.'/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: class Feature
title: class Feature - implémente un Feature GeoJSON, utilisé dans le retour du WFS
methods:
doc: |
  Seule la géométrie est obligatoire.
  Le bbox est calculé pour calculer la boite en WebMercator
*/
class Feature {
  public string $id;
  public ?GjBox $bbox;
  public array $properties;
  public Geometry $geometry;
  
  /*PhpDoc: methods
  name: __construct
  title: "function __construct(Geometry $geometry, string $id='', array $properties=[], ?GjBox $bbox=null)"
  */
  function __construct(Geometry $geometry, string $id='', array $properties=[], ?GjBox $bbox=null) {
    $this->id = $id;
    $this->bbox = $bbox;
    $this->properties = $properties;
    $this->geometry = $geometry;
  }
  
  static function test_new() { // Test unitaire
    //$f = new Feature(geometry: Geometry::fromGeoJSON(['type'=>'Point', 'coordinates'=> [0,0]]));
    $f = new Feature(
      id: 'id',
      bbox: new GjBox([178, 0, -178, 0]),
      properties: ['prop'=> 'val'],
      geometry: Geometry::fromGeoJSON([
        'type'=>'MultiPoint',
        'coordinates'=> [[178,0],[-178,0]]
      ])
    );
    echo Yaml::dump($f->geojson());
    echo Yaml::dump(['wembox'=> $f->wembox()->asArray()]);
  }
  
  /*PhpDoc: methods
  name: geojson
  title: "function geojson(): array - retoune la structure GeoJSON comme array "
  doc: |
    si le feature est à cheval sur l'antiméridien alors retourne le bbox à l'West de l'anti-méridien
  */
  function geojson(): array { return ['type'=>'Feature'] + $this->asArray(); } // retoune la structure GeoJSON comme array 
  
  /*PhpDoc: methods
  name: asArray
  title: "function asArray(): array - retourne le Feature comme array"
  */
  function asArray(): array { // retourne le Feature comme array 
    return
      ($this->id ? ['id'=> $this->id] : [])
    + ($this->bbox ? ['bbox'=> $this->bbox->asArray()] : [])
    + ($this->properties ? ['properties'=> $this->properties] : [])
    + ['geometry'=> $this->geometry->asArray()]
    ;
  }
  
  /*PhpDoc: methods
  name: wembox
  title: "function wembox(): EBox - calcule une boite en coord. WebMercator"
  doc: |
    si le feature est à cheval sur l'antiméridien alors retourne le bbox à l'West de l'anti-méridien
  */
  function wembox(): EBox {
    if (!$this->bbox)
      $this->bbox = GjBox::ofGeometry($this->geometry);
    $gboxes = $this->bbox->asGBoxes();
    return $gboxes[0]->proj('WebMercator');
  }
  
  /*PhpDoc: methods
  name: drawLabel
  title: "function drawLabel($image, EBox $bbox, int $width, int $height): bool - dessine dans l'image GD le numéro de la carte"
  doc: |
    $bbox est un EBox en WebMercator délimitant la tuile définie par l'image
  */
  function drawLabel($image, EBox $bbox, int $width, int $height): bool {
    //echo "title= ",$this->title,"<br>\n";
    $wembox = $this->wembox();
    if (!$wembox->intersects($bbox)) {
      return false;
    }
    $x = round(($wembox->west() - $bbox->west()) / $bbox->dx() * $width);
    $y = round(- ($wembox->north() - $bbox->north()) / $bbox->dy() * $height);
    //echo "x=$x, y=$y<br>\n"; die();
    $font = 3;
    $bg_color = imagecolorallocate($image, 255, 255, 0);
    $num = $this->properties['num'];
    $dx = strlen($num) * imagefontwidth($font);
    $dy = imagefontheight($font);
    imagefilledrectangle($image, $x+2, $y, $x+$dx, $y+$dy, $bg_color);
    $text_color = imagecolorallocate($image, 255, 0, 0);
    // bool imagestring ( resource $image , int $font , int $x , int $y , string $string , int $color )
    imagestring($image, $font, $x+2, $y, $num, $text_color);
    //die();
    return true;
  }
};


if (__FILE__ <> $_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME']) return; // Test unitaire

echo "<pre>\n";
Feature::test_new();
die();
