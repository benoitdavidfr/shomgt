<?php
/*PhpDoc:
title: vectorlayer.inc.php
name: vectorlayer.inc.php
doc: |
  Affichage des couches vecteur
journal: |
  10/7/2022:
    - rajout des couches de catalogue en réutilisant le code de layer.inc.php
  8-9/7/2022:
    - création sur le modèle de layer.inc.php
*/
//die("Fin ligne ".__LINE__."\n");
//require_once __DIR__.'/../../vendor/autoload.php';
//require_once __DIR__.'/grefimg.inc.php';
require_once __DIR__.'/layer.inc.php';
require_once __DIR__.'/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

class StyleLib { // Gestion de la bibliothèque des styles stockée dans le fichier yaml
  // dictionnaire des styles indexé par leur identifiant
  // [{id} => ['title'=>{title}, 'color'=>{color}, 'weight'=<{weight}, 'fillColor'=>{fillColor}, 'fillOpacity'=>{fillOpacity}]]
  static array $all;
    
  // retourne le style correspondant au nom demandé ou si'il n'existe pas le style par défaut
  static function get(string $name): array { return self::$all[$name] ?? self::$all['default']; }
  
  // Publication de la liste des styles disponibles dans les capacités du serveur
  static function asXml(): string {
    $xml = '';
    foreach (self::$all as $id => $style) {
      $xml .= "<Style><Name>$id</Name><Title>$style[title]</Title><Abstract>$style[description]</Abstract></Style>";
    }
    return $xml;
  }
}

class VectorLayer { // structure d'une couche vecteur + dictionnaire de ces couches
  protected string $name;
  protected string $title;
  protected string $description;
  protected ?string $path;
  protected array $style;
  
  static array $all = []; // dictionaire [{lyrName} => VectorLayer]

  static function initVectorLayers(string $filename): void {
    Layer::initFromShomGt(__DIR__.'/../../data/shomgt'); // Initialisation des couches raster à partir du fichier shomgt.yaml
    $yaml = Yaml::parseFile($filename);
    foreach ($yaml['vectorLayers'] as $name => $vectorLayer) {
      self::$all[$name] = new self($name, $vectorLayer);
    }
    // La modèle de couche cat{sd} est un prototype des couches cat{sd}
    // il est appliqué aux couches gt{sd} où {sd} est le dénominateur de l'échelle
    $catsd = $yaml['vectorLayerModels']['cat{sd}'];
    foreach (array_keys(Layer::layers()) as $rLyrName) {
      if (substr($rLyrName, 0, 2)=='gt') {
        $sd = substr($rLyrName, 2);
        if (!ctype_digit(substr($sd, 0, 1))) continue;
        self::$all["cat$sd"] = new self("cat$sd", [
          'title'=> str_replace('{sd}', $sd, $catsd['title']),
          'description'=> str_replace('{sd}', $sd, $catsd['description']),
          'style'=> $catsd['style'],
        ]);
      }
    }
    StyleLib::$all = $yaml['styles'];
  }
  
  // retourne le dictionnaire des couches
  static function layers() { return self::$all; }

  function __construct(string $name, array $vectorLayer) {
    $this->name = $name;
    $this->title = $vectorLayer['title'];
    $this->description = $vectorLayer['description'];
    $this->path = $vectorLayer['path'] ?? null;
    $this->style = $vectorLayer['style'];
  }

  // fournit une représentation de la couche comme array pour affichage
  function asArray(): array { return [$this->pathname]; }

  // calcul de l'extension spatiale de la couche en WoM
  function ebox(): EBox {
    $gbox = new GBox([[-180, WorldMercator::MinLat],[180, WorldMercator::MaxLat]]);
    return $gbox->proj('WorldMercator');
  }

  // retourne un array de Features structurés comme array Php
  private function items(): array {
    if ($this->path) {
      return json_decode(file_get_contents(__DIR__.'/../'.$this->path), true)['features'];
    }
    elseif (substr($this->name, 0, 3)=='cat') {
      $rasterLayerName = 'gt'.substr($this->name, 3);
      if (!($rasterLayer = Layer::layers()[$rasterLayerName] ?? null))
        throw new Exception("couche $rasterLayerName non trouvée");
      return $rasterLayer->items($rasterLayerName, null);
    }
    else {
      throw new Exception("Cas non prévu");
    }
  }
  
  // copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
  function map(GeoRefImage $grImage, string $style): void {
    $style = new Style($style ? StyleLib::get($style) : $this->style, $grImage);
    foreach ($this->items() as $feature) {
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
    foreach ($this->items() as $feature) {
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
  
  // Génère l'extrait XML de la couche pour les capacités
  private function asXml(): string {
    return
      '<Layer queryable="1" opaque="0">'
        ."<Name>$this->name</Name>"
        ."<Title>$this->title</Title>"
        ."<Abstract>$this->description</Abstract>"
      .'</Layer>';
  }
  
  // Génère l'extrait XML des couches pour les capacités
  static function allAsXml(): string {
    $xml = '';
    foreach(self::$all as $name => $layer)
      $xml .= $layer->asXml();
    return $xml;
  }
};
