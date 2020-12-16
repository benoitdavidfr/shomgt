<?php
/*PhpDoc:
name: wfs.php
title: cat2/wfs.php - utilisation du WFS du Shom
doc: |
  Définit:
    1) la classe Wfs et la méthode statique Wfs::dl() qui moissonne le WFS du Shom et retourne un array de Feature
    2) comme script la restitution des élts du WFS comme FaetureCollection avec un filtre sur sd
journal: |
  16/12/2020:
    création
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/wfsserver.inc.php';
require_once __DIR__.'/wfsjson.inc.php';
require_once __DIR__.'/france.inc.php';

use Symfony\Component\Yaml\Yaml;

// formatte un entier positif en ajoutant les séparateurs de milliers
function ajouteSepMilliers(int $val): string {
  if ($val < 1e3)
    return $val;
  else
    return sprintf('%s.%03d', ajouteSepMilliers($val/1e3), $val % 1e3);
}
if (0) { // Test unitaire
  foreach ([12, 3456, 123456, 1234567, 12345678, 500000, 2e7, 1e10] as $val)
    echo "$val -> ",ajouteSepMilliers($val),"\n";
  die();
}

class Feature {
  public string $id;
  public array $bbox; // LonLat Dd
  public array $properties;
  public array $geometry;
  
  function __construct($id, $bbox, $properties, $geometry) {
    $this->id = $id;
    $this->bbox = $bbox;
    $this->properties = $properties;
    $this->geometry = $geometry;
  }
  
  function geojson() { return ['type'=>'Feature'] + $this->asArray(); }
  
  function asArray(): array {
    return [
      'id'=> $this->id,
      'bbox'=> $this->bbox,
      'properties'=> $this->properties,
      'geometry'=> $this->geometry,
    ];
  }
  
  function geometry(): Geometry { return Geometry::fromGeoJSON($this->geometry); }
  
  function wembox(): EBox {
    $bboxDd = new BBoxDd($this->bbox);
    $gboxes = $bboxDd->asGBoxes();
    return $gboxes[0]->proj('WebMercator');
  }
  
  /*PhpDoc: methods
  name: drawLabel
  title: "function drawLabel($image, EBox $bbox, int $width, int $height): bool - dessine dans l'image GD le numéro de la carte"
  doc: |
    $bbox est un EBox en WebMercator
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

class Wfs {
  static $verbose = false;
  
  // lecture du wfs Shom des fantomes des cartes GeoTiff
  // retourne un ensemble de features chacun identifié par un id de la forme "FR{num}"
  static function dl(): array {
    //printf("time-filemtime=%.2f heures<br>\n",(time()-filemtime(__DIR__.'/wfsdl.pser'))/60/60);
    // Le fichier wfsdl.pser est automatiquement mis à jour toutes les 12 heures
    if (is_file(__DIR__.'/wfsdl.pser') && (time() - filemtime(__DIR__.'/wfsdl.pser') < 12*60*60))
      return unserialize(file_get_contents(__DIR__.'/wfsdl.pser'));
  
    //try {
      $typenames = [
        'CARTES_MARINES_GRILLE:grille_geotiff_30', // cartes echelle > 1/30K
        'CARTES_MARINES_GRILLE:grille_geotiff_30_300', // cartes aux échelles entre 1/30K et 1/300K
        'CARTES_MARINES_GRILLE:grille_geotiff_300_800', // cartes aux échelles entre 1/300K et 1/800K
        'CARTES_MARINES_GRILLE:grille_geotiff_800', // carte échelle < 1/800K
      ];

      $yaml = Yaml::parseFile(__DIR__.'/shomwfs.yaml');
      $shomwfs = new WfsServerJson($yaml, 'shomwfs');

      $wfs = [];
      foreach ($typenames as $typename) {
        $numberMatched = $shomwfs->getNumberMatched($typename);
        $count = 100;
        for ($startindex = 0; $startindex < $numberMatched; $startindex += $count) {
          $fc = $shomwfs->getFeatureAsArray($typename, [], -1, '', $count, $startindex);
          foreach ($fc['features'] as $feature) {
            $bbox = Geometry::fromGeoJSON($feature['geometry'])->bbox()->asArray();
            $num = $feature['properties']['carte_id'];
            $id = 'FR'.$num;
            $wfs[$id] = new Feature(
              id: $id,
              bbox: array_merge($bbox['min'], $bbox['max']), 
              properties: [
                'num'=> intval($num),
                'title'=> substr($feature['properties']['name'], strpos($feature['properties']['name'], '-')+2),
                'scaleDenominator'=> ajouteSepMilliers($feature['properties']['scale']),
              ],
              geometry: $feature['geometry']
            );
            //echo "id=$id\n";
          }
        }
      }
      //echo '<pre>',json_encode($maps, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
      echo count($wfs)," cartes téléchargées du WFS du Shom<br>\n";
    
      foreach (Yaml::parseFile(__DIR__.'/mapcatspec.yaml')['cartesAjoutéesAuServiceWfs'] as $mapid => $map) {
        echo "Ajout carte $mapid $map[title]\n";
        $bbox = $map['outerBBoxLonLatDd'];
        $wfs[$mapid] = new Feature(
          id: $mapid,
          bbox: $bbox,
          properties: [
            'num'=> intval(substr($mapid, 2)),
            'title'=> $map['title'],
            'scaleDenominator'=> $map['scaleDenominator'],
          ],
          geometry: [
            'type'=> 'Polygon',
            'coordinates'=> [[
              [$bbox[0], $bbox[1]], // SW
              [$bbox[0], $bbox[3]], // NW
              [$bbox[2], $bbox[3]], // NE
              [$bbox[2], $bbox[1]], // SE
              [$bbox[0], $bbox[1]], // SW
            ]],
          ]
        );
      }
    
      //echo Yaml::dump($wfs, 5, 2);
      ksort($wfs);
      file_put_contents(__DIR__.'/wfsdl.pser', serialize($wfs));
      return $wfs;
      /*}
    catch (Exception $e) {
      if (is_file(__DIR__.'/wfsdl.pser'))
        return unserialize(file_get_contents(__DIR__.'/wfsdl.pser'));
      else
        throw new Exception("Ereur: impossible de créer wfsdl.pser");
    }*/
  }

  // ajoute à chaque feature la propriété mapsFrenchAreas
  static function items(): array {
    $items = self::dl();
    foreach ($items as $id => &$item) {
      $item->properties['mapsFrenchAreas'] = France::interet($id, $item->properties['scaleDenominator'], $item->geometry());
    }
    return $items;
  }
  
  static function maketile(int $sdmin, ?int $sdmax, EBox $wembox, array $options=[]) {
    if (self::$verbose)
      echo "Wfs::maketile(lyrname=$sdmin-$sdmax, wembox=$wembox, options=",json_encode($options),")<br>\n";
    $width = $options['width'] ?? 256;
    $height = $options['height'] ?? 256;
    // fabrication de l'image
    if (!($image = imagecreatetruecolor($width, $height)))
      throw new Exception("erreur de imagecreatetruecolor() ligne ".__LINE__);
    // remplissage en transparent
    if (!imagealphablending($image, false))
      throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
    $transparent = imagecolorallocatealpha($image, 0xFF, 0xFF, 0xFF, 0x7F);
    if (!imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent))
      throw new Exception("erreur de imagefilledrectangle() ligne ".__LINE__);
    if (!imagealphablending($image, true))
      throw new Exception("erreur de imagealphablending() ligne ".__LINE__);
    
    foreach (self::dl() as $item) {
      $sd = str_replace('.', '', $item->properties['scaleDenominator']);
      if (($sd > $sdmin) && (!$sdmax || ($sd <= $sdmax))) {
        $item->drawLabel($image, $wembox, $width, $height);
      }
    }
    
    if (!imagesavealpha($image, true))
      throw new Exception("erreur de imagesavealpha() ligne ".__LINE__);
    return $image;
  }
};

if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire de la classe MapCat

if (php_sapi_name()=='cli') {
  $sdmin = ($argc > 1) ? $argv[1] : null;
  $sdmax = ($argc > 2) ? $argv[2] : null;
}
else {
  $sdmin = $_GET['sdmin'] ?? null;
  $sdmax = $_GET['sdmax'] ?? null;
}

//echo "<pre>doc="; print_r($doc); die();

header('Content-type: application/json; charset="utf8"');
//header('Content-type: text/plain; charset="utf8"');
$nbre = 0;

echo '{"type":"FeatureCollection","features":[',"\n";
foreach (Wfs::items() as $id => $item) {
  $scaleD = (int)str_replace('.', '', $item->properties['scaleDenominator']);
  if ($sdmax && ($scaleD > $sdmax))
    continue;
  if ($sdmin && ($scaleD <= $sdmin))
    continue;
    
  if ($nbre++ <> 0)
    echo ",\n";
  echo json_encode($item->geojson(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
}

echo "\n]}\n";

