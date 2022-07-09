<?php
/*PhpDoc:
title: vectorlayer.inc.php
name: vectorlayer.inc.php
doc: |
  Affichage couche vecteur
*/
//die("Fin ligne ".__LINE__."\n");
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/grefimg.inc.php';
require_once __DIR__.'/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

class StyleLib { // Gestion de la bibliothèque des styles stockée dans le fichier yaml
  const LIB_PATH = __DIR__.'/../wmsvstyles.yaml'; // chemin du fichier Yaml des styles

  // dictionnaire des styles indexé par leur identifiant
  // [{id} => ['title'=>{title}, 'color'=>{color}, 'weight'=<{weight}, 'fillColor'=>{fillColor}, 'fillOpacity'=>{fillOpacity}]]
  static array $all;
  
  // initialise les styles à partir du fichiet Yaml défini par LIB_PATH
  static function init() {
    $yaml = Yaml::parseFile(self::LIB_PATH);
    foreach ($yaml['styles'] as $sid => $style) {
      // remplacement du nom de la couleur par sa définition RVB
      if (isset($style['color']) && is_string($style['color'])) {
        if (isset($yaml['colors'][$style['color']]))
          $style['color'] = $yaml['colors'][$style['color']];
      }
      if (isset($style['fillColor']) && is_string($style['fillColor'])) {
        if (isset($yaml['colors'][$style['fillColor']]))
          $style['fillColor'] = $yaml['colors'][$style['fillColor']];
      }
      self::$all[$sid] = $style;
    }
  }
  
  // retourne le style correspondant au nom demandé ou si'il n'existe pas le style par défaut
  static function get(string $name): array { return self::$all[$name] ?? self::$all['default']; }
  
  // Publication de la liste des styles disponibles dans les capacités du serveur
  static function asXml(): string {
    $xml = '';
    foreach (self::$all as $id => $style) {
      $xml .= "<Style><Name>$id</Name>"
        .(isset($style['title']) ? "<Title>$style[title]</Title>" : '')
        ."</Style>";
    }
    return $xml;
  }
};

abstract class Layer {
  static array $layers = []; // dictionaire [{lyrName} => Layer]
  
  static function init(): void { // initialisation de la liste des couches 
    StyleLib::init();
    foreach (new DirectoryIterator(__DIR__.'/../geojson') as $geojsonfile) {
      if (($geojsonfile->getType() == 'file') && ($geojsonfile->getExtension()=='geojson')) {
        $lyrname = $geojsonfile->getBasename('.geojson');
        self::$layers[$lyrname] = new VectorLayer($geojsonfile->getPathname());
      }
    }
  }
  
  // retourne le dictionnaire des couches
  static function layers() { return self::$layers; }
  
  // fournit une représentation de la couche comme array pour affichage
  abstract function asArray(): array;

  // calcul de l'extension spatiale de la couche en WoM
  abstract function ebox(): EBox;

  // copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
  abstract function map(GeoRefImage $grImage, string $style, bool $debug): void;
};

class VectorLayer extends Layer {
  protected string $pathname;
  
  function __construct(string $pathname) { $this->pathname = $pathname; }

  // fournit une représentation de la couche comme array pour affichage
  function asArray(): array { return [$this->pathname]; }

  // calcul de l'extension spatiale de la couche en WoM
  function ebox(): EBox {
    $gbox = new GBox([[-180, WorldMercator::MinLat],[180, WorldMercator::MaxLat]]);
    return $gbox->proj('WorldMercator');
  }

  // copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
  function map(GeoRefImage $grImage, string $style, bool $debug): void {
    $style = new Style(StyleLib::get($style), $grImage);
    $geojson = json_decode(file_get_contents($this->pathname), true);
    foreach ($geojson['features'] as $feature) {
      $geometry = $feature['geometry'];
      switch ($geometry['type']) {
        case 'LineString': {
          $lpos = [];
          foreach ($geometry['coordinates'] as $i => $pos) {
            $lpos[$i] = WorldMercator::proj($pos);
          }
          $grImage->polyline($lpos, $style);
          break;
        }
        case 'Polygon': {
          $lpos = [];
          foreach ($geometry['coordinates'][0] as $i => $pos) {
            $lpos[$i] = WorldMercator::proj($pos);
          }
          $grImage->polygon($lpos, $style);
          break;
        }
        case 'MultiPolygon': {
          foreach ($geometry['coordinates'] as $polygon) {
            $lpos = [];
            foreach ($polygon[0] as $i => $pos) {
              $lpos[$i] = WorldMercator::proj($pos);
            }
            $grImage->polygon($lpos, $style);
          }
          break;
        }
        default: throw new Exception("Type de géométrie '$geometry[type] non prévu");
      }
    }
  }

  // retourne une liste de propriétés des features concernés
  function featureInfo(array $geo, int $featureCount, float $resolution): array {
    $info = [];
    $dmin = 10 * $resolution;
    $geojson = json_decode(file_get_contents($this->pathname), true);
    foreach ($geojson['features'] as $feature) {
      $geometry = $feature['geometry'];
      switch ($geometry['type']) {
        case 'LineString': {
          $geom = Geometry::fromGeoJSON($geometry);
          $d = $geom->distanceToPos($geo);
          if ($d < $dmin) {
            $dmin = $d;
            $info = [ $feature['properties'] ];
          }
          break;
        }
        case 'Polygon':
        case 'MultiPolygon': {
          $geom = Geometry::fromGeoJSON($geometry);
          if ($geom->pointInPolygon($geo))
            $info[] = $feature['properties'];
          break;
        }
        default: throw new Exception("Type de géométrie '$geometry[type]' non prévu");
      }
    }
    return $info;
  }
};
