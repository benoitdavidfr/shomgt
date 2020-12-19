<?php
/*PhpDoc:
name: wfs.php
title: cat2/wfs.php - utilisation du WFS du Shom
classes:
doc: |
  Définit:
    1) la classe Wfs avec notamment
      a) la méthode statique Wfs::dl() qui moissonne le WFS du Shom et retourne un array de Feature
      b) la méthode statique maketile() utilisée pour générer la couche d'étiquettes pour la carte Leaflet
    2) la classe Feature, objets retournés par Wfs
    3) comme script la restitution des élts du WFS comme FaetureCollection avec un filtre sur sd
journal: |
  17/12/2020:
    export de la définition de GjBox
  16/12/2020:
    création
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/wfsserver.inc.php';
require_once __DIR__.'/wfsjson.inc.php';
require_once __DIR__.'/france.inc.php';
require_once __DIR__.'/gjbox.inc.php';

use Symfony\Component\Yaml\Yaml;


function ajouteSepMilliers(int $val): string { // formatte un entier positif en lui ajoutant les séparateurs de milliers 
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


/*PhpDoc: classes
name: class Feature
title: class Feature - imlémente un Feature GeoJSON, utilisé dans le retour du WFS
methods:
doc: |
  Seule la géométrie est obligatoire
*/
class Feature {
  public ?string $id;
  public ?GjBox $bbox;
  public array $properties;
  public Geometry $geometry;
  
  function __construct(Geometry $geometry, ?string $id=null, array $properties=[], ?GjBox $bbox=null) {
    $this->id = $id;
    $this->bbox = $bbox;
    $this->properties = $properties;
    $this->geometry = $geometry;
  }
  
  function geojson() { return ['type'=>'Feature'] + $this->asArray(); }
  
  function asArray(): array {
    return
      (($this->id !== null) ? ['id'=> $this->id] : [])
    + ($this->bbox ? ['bbox'=> $this->bbox->asArray()] : [])
    + ($this->properties ? ['properties'=> $this->properties] : [])
    + ['geometry'=> $this->geometry->asArray()]
    ;
  }
  
  // calcule une boite en coord. WebMercator
  // si le feature est à cheval sur l'antiméridien alors retourne le bbox à l'West de l'anti-méridien
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

class Wfs {
  static $verbose = false;
  
  /*PhpDoc: classes
  name: methods
  title: "static function dl(): array - lecture du wfs Shom des fantomes des cartes GeoTiff"
  doc: |
    retourne un ensemble de features chacun identifié par un id de la forme "FR{num}"
    bufferise le résultat dans un fichier pser dont l'ancienneté est limitée à 12 heures
    ajoute les cartes manquantes
  */
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
            $num = $feature['properties']['carte_id'];
            $id = 'FR'.$num;
            $wfs[$id] = new Feature(
              id: $id,
              properties: [
                'num'=> intval($num),
                'title'=> substr($feature['properties']['name'], strpos($feature['properties']['name'], '-')+2),
                'scaleDenominator'=> ajouteSepMilliers($feature['properties']['scale']),
                //'properties'=> $feature['properties'],
              ],
              geometry: Geometry::fromGeoJSON($feature['geometry'])
            );
            //echo "id=$id\n";
          }
        }
      }
      //echo '<pre>',json_encode($maps, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
      //echo count($wfs)," cartes téléchargées du WFS du Shom<br>\n";
    
      foreach (Yaml::parseFile(__DIR__.'/mapcatspec.yaml')['cartesAjoutéesAuServiceWfs'] as $mapid => $map) {
        //echo "Ajout carte $mapid $map[title]\n";
        if (isset($wfs[$mapid]))
          echo "Attention, la carte $mapid est remplacée par une carte ajoutée\n";
        $bbox = $map['bboxLonLatDd'];
        $wfs[$mapid] = new Feature(
          id: $mapid,
          bbox: new GjBox($bbox),
          properties: [
            'num'=> intval(substr($mapid, 2)),
            'title'=> $map['title'],
            'scaleDenominator'=> $map['scaleDenominator'],
          ],
          geometry: Geometry::fromGeoJSON([
            'type'=> 'Polygon',
            'coordinates'=> [[
              [$bbox[0], $bbox[1]], // SW
              [$bbox[0], $bbox[3]], // NW
              [$bbox[2], $bbox[3]], // NE
              [$bbox[2], $bbox[1]], // SE
              [$bbox[0], $bbox[1]], // SW
            ]],
          ])
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
        throw new Exception("Erreur: impossible de créer wfsdl.pser");
    }*/
  }

  /*PhpDoc: classes
  name: methods
  title: "static function items(): array - enrichit chaque feature avec la propriété mapsFrance et définit les bbox qui ne le sont pas"
  */
  static function items(): array {
    $items = self::dl();
    foreach ($items as $id => &$item) {
      $item->properties['mapsFrance'] = France::interet($id, $item->properties['scaleDenominator'], $item->geometry);
      if (!$item->bbox)
        $item->bbox = GjBox::ofGeometry($item->geometry);
    }
    return $items;
  }
  
  // fabrication d'une tuile des étiquettes pour l'affichage de la carte Leaflet
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


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;
// si id est défini alors affiche le feature id, sinon affiche les features avec un filtre sur sdmin et sdmax
// L'affichage des faetures est utilise par la carte Leaflet

if (php_sapi_name()=='cli') {
  $sdmin = ($argc > 1) ? $argv[1] : null;
  $sdmax = ($argc > 2) ? $argv[2] : null;
}
else {
  $sdmin = $_GET['sdmin'] ?? null;
  $sdmax = $_GET['sdmax'] ?? null;
  $id = $_GET['id'] ?? null;
}


if ($id) {
  if ($item = (Wfs::items()[$id] ?? null)) {
    header('Content-type: application/json; charset="utf8"');
    //header('Content-type: text/plain; charset="utf8"');
    echo json_encode($item->asArray(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  }
  else {
    header('Content-type: text/plain; charset="utf8"');
    echo "objet $id absent\n";
  }
}
else {
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
}


