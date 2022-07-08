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

abstract class Layer {
  static array $styles; // dictionnaire des styles issu du fichier wmsvstyles.yaml
  static array $layers = []; // dictionaire [{lyrName} => Layer]
  
  static function init(): void { // initialisation de la liste des couches 
    $yaml = Yaml::parseFile(__DIR__.'/../wmsvstyles.yaml');
    foreach ($yaml['styles'] as $sid => $style) {
      if (isset($style['color']) && is_string($style['color'])) {
        if (isset($yaml['colors'][$style['color']]))
          $style['color'] = $yaml['colors'][$style['color']];
      }
      self::$styles[$sid] = $style;
      if (isset($style['fillColor']) && is_string($style['fillColor'])) {
        if (isset($yaml['colors'][$style['fillColor']]))
          $style['fillColor'] = $yaml['colors'][$style['fillColor']];
      }
      self::$styles[$sid] = $style;
    }
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
  
  function __construct(string $pathname) {
    $this->pathname = $pathname;
  }

  // fournit une représentation de la couche comme array pour affichage
  function asArray(): array { return [$this->pathname]; }

  // calcul de l'extension spatiale de la couche en WoM
  function ebox(): EBox {
    $gbox = new GBox([[-180, WorldMercator::MinLat],[180, WorldMercator::MaxLat]]);
    return $gbox->proj('WorldMercator');
  }

  // copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
  function map(GeoRefImage $grImage, string $style, bool $debug): void {
    $style = new Style(Layer::$styles[$style] ?? Layer::$styles['default'], $grImage);
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

  function featureInfo(array $geo, int $featureCount): array {
    $info = [];
    $geojson = json_decode(file_get_contents($this->pathname), true);
    foreach ($geojson['features'] as $feature) {
      $geometry = $feature['geometry'];
      switch ($geometry['type']) {
        case 'LineString': {
          $geom = Geometry::fromGeoJSON($geometry);
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
