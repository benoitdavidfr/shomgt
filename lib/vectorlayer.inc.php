<?php
/** gestion de couches d'objets vecteur
 *
 * journal: |
 * - 28-31/7/2022:
 *   - correction suite à analyse PhpStan level 6
 * - 10/7/2022:
 *   - rajout des couches de catalogue en réutilisant le code de layer.inc.php
 * - 8-9/7/2022:
 *   - création sur le modèle de layer.inc.php
 * @package shomgt\lib
 */
require_once __DIR__.'/layer.inc.php';
require_once __DIR__.'/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

/** Gestion de la bibliothèque des styles stockée dans le fichier yaml */
class StyleLib {
  /** dictionnaire des styles indexés par leur identifiant
   * @var array<string, TStyle> $all */
  static array $all;
    
  /** retourne le style correspondant au nom demandé ou s'il n'existe pas []
   * @return TStyle */
  static function get(string $name): array { return self::$all[$name] ?? []; }
  
  /** Publication de la liste des styles disponibles dans les capacités du serveur
   *
   * Le titre du style doit comporter le titre de la couche car le titre du style est utilisa dans QGis
   * pour définir le titre de la couche stylée.
   */
  static function asXml(string $lyrTitle): string {
    $xml = '';
    foreach (self::$all as $styleId => $style) {
      $xml .= "<Style><Name>$styleId</Name><Title>$lyrTitle - $style[title]</Title>"
        ."<Abstract>$style[description]</Abstract></Style>";
    }
    return $xml;
  }
}

/** Couche d'objets vecteur + dictionnaire de ces couches */
class VectorLayer {
  protected string $name;
  protected string $title;
  protected string $description;
  protected ?string $path;
  /** @var TStyle $style */
  protected array $style;
  
  /** // dictionaire [{lyrName} => VectorLayer]
   * @var array<string, VectorLayer> $all */
  static array $all = [];

  static function initVectorLayers(string $filename): void {
    Layer::initLayers(); // Initialisation des couches raster à partir du fichier Yaml
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
  
  /** retourne le dictionnaire des couches
   * @return array<string, VectorLayer> */
  static function layers(): array { return self::$all; }

  /** @param array<string, mixed> $vectorLayer */
  function __construct(string $name, array $vectorLayer) {
    $this->name = $name;
    $this->title = $vectorLayer['title'];
    $this->description = $vectorLayer['description'];
    $this->path = $vectorLayer['path'] ?? null;
    $this->style = $vectorLayer['style'];
  }

  /** fournit une représentation de la couche comme array pour affichage
   * @return array<int, string> */
  function asArray(): array { return [$this->path]; }

  // calcul de l'extension spatiale de la couche en WoM
  function ebox(): \gegeom\EBox {
    $gbox = new \gegeom\GBox([[-180, \coordsys\WorldMercator::MinLat],[180, \coordsys\WorldMercator::MaxLat]]);
    return $gbox->proj('WorldMercator');
  }

  /** retourne un array de Features structurés comme array Php
   * @return array<int, TGeoJsonFeature> */
  private function items(): array {
    if ($this->path) {
      return json_decode(file_get_contents(__DIR__.'/../view/'.$this->path), true)['features'];
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
  
  /** copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage, */
  function map(GeoRefImage $grImage, string $styleId): void {
    // si le paramètre $style est non vide alors J'essaie de récupérer le style dans la bibliothèque
    $styleDef = $styleId ? StyleLib::get($styleId) : [];
    // si le style est défini dans la bib alors je l'utilise sinon j'utilise le style par défaut défini pour la couche
    $style = new Style($styleDef ? $styleDef : $this->style, $grImage);
    foreach ($this->items() as $feature) {
      $geometry = $feature['geometry'];
      switch ($geometry['type']) {
        case 'LineString': {
          $lpos = [];
          foreach ($geometry['coordinates'] as $i => $pos) {
            $lpos[$i] = \coordsys\WorldMercator::proj($pos);
          }
          $grImage->polyline($lpos, $style);
          break;
        }
        case 'Polygon': {
          $lpos = [];
          foreach ($geometry['coordinates'][0] as $i => $pos) {
            $lpos[$i] = \coordsys\WorldMercator::proj($pos);
          }
          $grImage->polygon($lpos, $style);
          break;
        }
        case 'MultiPolygon': {
          foreach ($geometry['coordinates'] as $polygon) {
            $lpos = [];
            foreach ($polygon[0] as $i => $pos) {
              $lpos[$i] = \coordsys\WorldMercator::proj($pos);
            }
            $grImage->polygon($lpos, $style);
          }
          break;
        }
        default: throw new Exception("Type de géométrie '$geometry[type] non prévu");
      }
    }
    if (substr($this->name, 0, 3)=='cat') { // pour les catalogues, j'ajoute la couche des numéros de cartes
      $numLyrName = 'num'.substr($this->name,3);
      Layer::layers()[$numLyrName]->map($grImage, false);
    }
  }

  /** retourne une liste de propriétés des features concernés
  * @param TPos $geo
  * @return array<int, TGeoJsonProperties> */
  function featureInfo(array $geo, int $featureCount, float $resolution): array {
    $info = [];
    $dmin = 10 * $resolution;
    foreach ($this->items() as $feature) {
      $geometry = $feature['geometry'];
      switch ($geometry['type']) {
        case 'LineString': {
          $geomType = "\gegeom\\$geometry[type]";
          $geom = new $geomType($geometry['coordinates']);
          $d = $geom->distanceToPos($geo);
          if ($d < $dmin) {
            $dmin = $d;
            $info = [ $feature['properties'] ];
          }
          break;
        }
        case 'Polygon':
        case 'MultiPolygon': {
          $geomType = "\gegeom\\$geometry[type]";
          $geom = new $geomType($geometry['coordinates']);
          if ($geom->pointInPolygon($geo))
            $info[] = $feature['properties'];
          break;
        }
        default: throw new Exception("Type de géométrie '$geometry[type]' non prévu");
      }
    }
    return $info;
  }
  
  /** Génère l'extrait XML de la couche pour les capacités */
  private function asXml(): string {
    return
      '<Layer queryable="1" opaque="0">'
        ."<Name>$this->name</Name>"
        ."<Title>$this->title</Title>"
        ."<Abstract>$this->description</Abstract>"
        .StyleLib::asXml($this->title)
      .'</Layer>';
  }
  
  /** Génère l'extrait XML des couches pour les capacités */
  static function allAsXml(): string {
    $xml = '';
    foreach(self::$all as $name => $layer)
      $xml .= $layer->asXml();
    return $xml;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


VectorLayer::initVectorLayers('../view/wmsvlayers.yaml');
echo "<h2>Couches créées par VectorLayer::initVectorLayers()</h2>\n";
echo "<pre>vectorLayers="; print_r(VectorLayer::$all);
