<?php
/*PhpDoc:
name: shomgtwfs.php
title: cat2/shomgtwfs.php - utilisation du WFS du Shom pour obtenir les fantomes des GéoTiff
classes:
doc: |
  Définit:
    1) la classe Wfs avec notamment
      a) la méthode statique Wfs::dl() qui moissonne le WFS du Shom et retourne un array de Feature
      b) la méthode statique maketile() utilisée pour générer la couche d'étiquettes pour la carte Leaflet
    2) le script d'affichage comme FeatureCollection des features du WFS avec un filtre sur sd utilisant les params sdmin et sdmax
journal: |
  28/12/2020:
    utilisation des classes WfsServerJson et FeaturesApi
  17/12/2020:
    export de la définition de GjBox
  16/12/2020:
    création
includes:
  - ../lib/config.inc.php
  - ../lib/gjbox.inc.php
  - ../lib/gegeom.inc.php
  - ../lib/feature.inc.php
  - wfsserver.inc.php
  - wfsjson.inc.php
  - france.inc.php
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/config.inc.php';
require_once __DIR__.'/../lib/gjbox.inc.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/../lib/feature.inc.php';
require_once __DIR__.'/../lib/wfs/wfsserver.inc.php';
require_once __DIR__.'/france.inc.php';

use Symfony\Component\Yaml\Yaml;


function ajouteSepMilliers(int $val): string { // formatte un entier positif en lui ajoutant les séparateurs de milliers 
  if ($val < 1e3)
    return $val;
  else
    return sprintf('%s.%03d', ajouteSepMilliers($val/1e3), $val % 1e3);
}
if (0) { // Test unitaire de ajouteSepMilliers() 
  echo "<pre>";
  foreach ([12, 3456, 123456, 1234567, 12345678, 12999999.99, 500000, 2e7, 1e10] as $val)
    echo "$val -> ",ajouteSepMilliers($val),"\n";
  die();
}

/*PhpDoc: classes
name: ShomGtWfs
title: "class ShomGtWfs"
methods:
*/
class ShomGtWfs extends FeaturesApi {
  // des types définis par le WFS du Shom
  const TYPENAMES = [
    'CARTES_MARINES_GRILLE:grille_geotiff_30', // cartes echelle > 1/30K
    'CARTES_MARINES_GRILLE:grille_geotiff_30_300', // cartes aux échelles entre 1/30K et 1/300K
    'CARTES_MARINES_GRILLE:grille_geotiff_300_800', // cartes aux échelles entre 1/300K et 1/800K
    'CARTES_MARINES_GRILLE:grille_geotiff_800', // carte échelle < 1/800K
  ];
  const PSER = __DIR__.'/shomgtwfsdl.pser';
  const VERBOSE = false;
  
  function __construct() {
    $wfsOptions = ($proxy = config('proxy')) ? ['proxy'=> str_replace('http://', 'tcp://', $proxy)] : [];
    parent::__construct('https://services.data.shom.fr/INSPIRE/wfs', $wfsOptions);
  }
  
  /*PhpDoc: methods
  name: dl
  title: "function dl(): array - lecture des fantomes des cartes GeoTiff dans le WFS Shom"
  doc: |
    retourne un ensemble de features chacun identifié par un id de la forme "FR{num}"
    bufferise le résultat dans un fichier pser dont l'ancienneté est limitée à 12 heures
    ajoute les cartes manquantes
  */
  function dl(): array {
    //printf("time-filemtime=%.2f heures<br>\n",(time()-filemtime(__DIR__.'/wfsdl.pser'))/60/60);
    // Le fichier wfsdl.pser est automatiquement mis à jour toutes les 12 heures
    if (is_file(self::PSER) && (time() - filemtime(self::PSER) < 12*60*60))
      return unserialize(file_get_contents(self::PSER));
  
    //try {
      $wfs = [];
      foreach (self::TYPENAMES as $typename) {
        $numberMatched = $this->getNumberMatched($typename);
        $count = 100;
        for ($startindex = 0; $startindex < $numberMatched; $startindex += $count) {
          $fc = $this->getFeatureAsArray($typename, [], -1, '', $count, $startindex);
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
      file_put_contents(self::PSER, serialize($wfs));
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
  title: "function igtItems(): array - enrichit chaque feature avec la propriété mapsFrance et définit les bbox qui ne le sont pas"
  */
  function gtItems(): array {
    $items = self::dl();
    foreach ($items as $id => &$item) {
      $item->properties['mapsFrance'] = France::interet($id, $item->properties['scaleDenominator'], $item->geometry);
      if (!$item->bbox)
        $item->bbox = GjBox::ofGeometry($item->geometry);
    }
    return $items;
  }
  
  // fabrication d'une tuile des étiquettes pour l'affichage de la carte Leaflet
  static function maketile(array $criteria, EBox $wembox, array $options=[]) {
    if (self::VERBOSE)
      echo "ShomGtWfs::maketile(criteria=",json_encode($criteria),", wembox=$wembox, options=",json_encode($options),")<br>\n";
    $sdmin = $criteria['sdmin'] ?? null;
    $sdmax = $criteria['sdmax'] ?? null;
    $mapid = $criteria['mapid'] ?? null;
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
    
    $shomGtWfs = new self;
    foreach ($shomGtWfs->dl() as $item) {
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


if (__FILE__ <> $_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME']) return; // Utilisation de la classe MapCat
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

$shomGtWfs = new ShomGtWfs;

if ($id) {
  if ($item = ($shomGtWfs->gtItems()[$id] ?? null)) {
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
  //header('Content-type: application/json; charset="utf8"');
  header('Content-type: text/plain; charset="utf8"');
  $nbre = 0;
  echo '{"type":"FeatureCollection","features":[',"\n";
  foreach ($shomGtWfs->gtItems() as $id => $item) {
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


