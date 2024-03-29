<?php
/** définition des classes Layer, PyrLayer, LabelLayer et TiffLayer
 *
 * Les 4 classes permettent de construire à partir de shomgt.yaml la structuration en couches et de l'exploiter au travers
 * des méthodes map() qui recopie dans une image GD l'extrait de la couche correspondant à un rectangle
 * et pour la classe TiffLayer la méthode items() qui génère en GeoJSON les silhouettes des GéoTiffs.
 *
 * La classe abstraite Layer définit les couches du serveur de cartes.
 * La classe TiffLayer correspond aux couches agrégeant des GéoTiff.
 * La classe PyrLayer correspond à la pyramide des TiffLayer qui permet d'afficher le bon GéoTiff en fonction du niveau de zoom.
 * Enfin, la classe LabelLayer correspond aux étiquettes associées aux GéoTiff.
 *
 * journal:
 * - 3/9/2023
 *   - reformattage de la doc en PHPDoc
 *   - modification de la fonction d'initialisation des couches
 *   - renommage du fichier de définition des couches de shomgt.yaml en layers.yaml
 * - 28-31/7/2022:
 *   - correction suite à analyse PhpStan level 6
 *   - la gestion dans Layer::$layers de couches de types différents limite les possibilités d'analyse statique du code !! 
 * - 7/6/2022:
 *   - ajout d'une érosion des rectangles englobants des cartes d'une mesure définie sur la carte, ex 1mm
 *     pour s'assurer que le trait du bord de la carte est bien effacé
 * 6/6/2022:
 *   - modif. définition du niveau de zoom dans PyrLayer::map()
 * 30/5/2022:
 *   - modif initialisation Layer
 * 24/5/2022:
 *   - envoi d'une erreur Http 500 lorsque le fichier shomgt.yaml n'existe pas
 * 22/5/2022:
 *   - dans TiffLayer gestion des cartes n'ayant pas de Mdiso
 *   - dans TiffLayer duplication des silhouettes des GéoTiffs à cehval sur l'AM
 * 1/5/2022:
 *   - ajout de la classe PyrLayer
 * 29/4/2022:
 *   - gestion des GéoTiff à cheval sur l'anti-méridien
 *   - gestion de la superposition de plusieures couches
 * 25/4/2022:
 *   - scission de maps.php
 * @package shomgt\lib
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/grefimg.inc.php';
require_once __DIR__.'/geotiff.inc.php';
require_once __DIR__.'/zoom.inc.php';
require_once __DIR__.'/isomd.inc.php';

use Symfony\Component\Yaml\Yaml;

/** classe abstraite correspondant à une couche du serveur de cartes
 *
 * Cette classe définit l'interface et stocke le dictionnaire des couches dans la variable statique self::$layers
 * [{lyrName} => Layer] initialisé à partir du fichier shomgt.yaml par initFromShomGt()
*/
abstract class Layer {
  const ErrorUndef = 'Layer::ErrorUndef';
  const LAYERS_YAML_PATH = __DIR__.'/../data/layers.yaml';
  const LAYERS_PSER_PATH = __DIR__.'/../data/layers.pser';
  /** dilatation en mètres sur la carte */
  const DILATE = - 1e-3;

  /** @var array<string, Layer> $layers  dictionaire [{lyrName} => Layer] */
  static array $layers=[];

  /** initialise le dictionnaire des couches à partir du fichier Yaml ou du fichier pser
   *
   * Ce dictionnaire est stocké dans un fichier pser afin de ne pas avoir à le reconstruire à chaque appel */
  static function initLayers(): void {
    if (!is_file(self::LAYERS_YAML_PATH)) { // cas notamment où shomgt.yaml n'a pas encore été généré
      header('HTTP/1.1 500 Internal Server Error');
      header('Content-type: text/plain; charset="utf-8"');
      die("Erreur: Erreur fichier Yaml des couches absent\n");
    }
    if (is_file(self::LAYERS_PSER_PATH) && (filemtime(self::LAYERS_PSER_PATH) > filemtime(self::LAYERS_YAML_PATH))) {
      self::$layers = unserialize(file_get_contents(self::LAYERS_PSER_PATH));
      return;
    }
    self::$layers['gtpyr'] = new PyrLayer;
    $layers = Yaml::parseFile(self::LAYERS_YAML_PATH);
    foreach ($layers as $lyrName => $dictOfGT) {
      if (substr($lyrName,0,2)=='gt') {
        self::$layers[$lyrName] = new TiffLayer($lyrName, $dictOfGT ?? []);
        self::$layers['num'.substr($lyrName,2)] = new LabelLayer($lyrName, $dictOfGT ?? []);
      }
    }
    file_put_contents(self::LAYERS_PSER_PATH, serialize(self::$layers));
  }
  
  /** calcule le coeff de dilatation en fonction du nom de la couche */
  static function dilate(string $lyrName): float {
    $sd = substr($lyrName, 2);
    if (ctype_digit(substr($sd, 0, 1)) && ($sd <> '40M')) {
      $sd = intval(str_replace(['k','M'], ['000','000000'], $sd));
      return self::DILATE * $sd;
    }
    else {
      return 0;
    }
  }

  /** retourne le dictionnaire des couches
   * @return array<string, Layer> */
  static function layers(): array { return self::$layers; }
  
  /** fournit une représentation de la couche comme array pour affichage
   * @return array<string, mixed> */
  abstract function asArray(): array;

  // calcul de l'extension spatiale de la couche en WoM
  abstract function ebox(): \gegeom\EBox;

  /** copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
   *
   * lorsqu'un élément intersecte l'anti-méridien, il est dupliqué dans les 2 hémisphères Ouest et Est
   * le paramètre zoom est uniquement utilisé dans la classe PyrLayer */
  abstract function map(GeoRefImage $grImage, bool $debug, int $zoom=-1): void;

  /** utile pour éviter les erreurs d'analyse statique
   * @return list<TGeoJsonFeature> */
  function items(string $lyrname, ?\gegeom\GBox $qgbox): array {
    throw new SExcept("Erreur, méthode non définie", self::ErrorUndef);
  }

  /** utile pour éviter les erreurs d'analyse statique
   * @return list<TGeoJsonFeature> */
  function deletedZones(string $lyrname, ?\gegeom\GBox $qgbox): array {
    throw new SExcept("Erreur, méthode non défiinie", self::ErrorUndef);
  }

  /** utile pour éviter les erreurs d'analyse statique
   * @return array<int, \gegeom\EBox> */
  function itemEBoxes(\gegeom\EBox $wombox): array {
    throw new SExcept("Erreur, méthode non définie", self::ErrorUndef);
  }
};

/** couche Pyramide
 *
 * Le choix des échelles en fonction du niveau de zoom est défini dans la méthode PyrLayer::map()
*/
class PyrLayer extends Layer {
  const ErrorBadZoomValue = 'PyrLayer::ErrorBadZoomValue';
  
  /** @return array<string, mixed> */
  function asArray(): array {
    return ['title'=> "Pyramide des cartes GéoTiff du 1/40M au 1/5k"];
  }
  
  function ebox(): \gegeom\EBox { // Le rectangle englobant est l'extension de WorldMercator
    $gbox = new \gegeom\GBox(\coordsys\WorldMercator::spatial());
    return $gbox->proj('WorldMercator');
  }
  
  function map(GeoRefImage $grImage, bool $debug, int $zoom=-1): void {
    if ($zoom == -1)
      throw new SExcept("dans PyrLayer::map() le paramètre zoom doit être défini", self::ErrorBadZoomValue);
    $layernames = ['gt40M'];
    if ($zoom >= 6)
      $layernames[] = 'gt10M';
    if ($zoom >= 7)
      $layernames[] = 'gt4M';
    if ($zoom >= 8)
      $layernames[] = 'gt2M';
    if ($zoom >= 9)
      $layernames[] = 'gt1M';
    if ($zoom >= 10)
      $layernames[] = 'gt500k';
    if ($zoom >= 11)
      $layernames[] = 'gt250k';
    if ($zoom >= 12)
      $layernames[] = 'gt100k';
    if ($zoom >= 13)
      $layernames[] = 'gt50k';
    if ($zoom >= 14)
      $layernames[] = 'gt25k';
    if ($zoom >= 15)
      $layernames[] = 'gt12k';
    if ($zoom >= 16)
      $layernames[] = 'gt5k';
    
    $layers = Layer::$layers;
    foreach ($layernames as $lyrname) {
      $layers[$lyrname]->map($grImage, $debug);
    }
  }
};


/** couche d'étiquettes, permet de dessiner les étiquettes associées aux GéoTiffs */
class LabelLayer extends Layer {
  const ErrorUndef = 'LabelLayer::ErrorUndef';
  /** @var array<string, TPos> $nws */
  protected array $nws=[]; // dictionnaire [gtname => coin NW du rectangle englobant du GéoTiff en coord. WorldMercator]
  
  /** @param array<string, array<string, mixed>> $dictOfGT */
  function __construct(string $lyrName, array $dictOfGT) {
    foreach ($dictOfGT as $gtname => $gt) {
      $gbox = new \gegeom\GBox($gt['spatial']);
      $nw = \coordsys\WorldMercator::proj([$gbox->west(), $gbox->north()]);
      $dilate = self::dilate($lyrName);
      $nw[0] -= $dilate;
      $nw[1] += $dilate;
      $this->nws[$gtname] = $nw;
    }
  }
  
  /** @return array<string, TPos> */
  function asArray(): array { return $this->nws; }
  
  function ebox(): \gegeom\EBox {
    $ebox = new \gegeom\EBox;
    foreach ($this->nws as $pos)
      $ebox = $ebox->bound($pos);
    return $ebox;
  }
  
  function map(GeoRefImage $grImage, bool $debug, int $zoom=-1): void {
    $bg_color = $grImage->colorallocate([255, 255, 0]);
    $text_color = $grImage->colorallocate([255, 0, 0]);
    foreach ($this->nws as $gtname => $nw) {
      $label = substr($gtname, 0, 4);
      if (substr($gtname, 6, 4)=='_gtw')
        $label .= '/'.substr($gtname, 5, 1);
      $grImage->string(4, $nw, $label, $text_color, $bg_color, $debug);
    }
  }
};


/** couche correspondant à un ensemble de GéoTiff juxtaposés */
class TiffLayer extends Layer {
  const ErrorBadGeoCoords = 'Layer::ErrorBadGeoCoords';
  /** xmax en Web Mercator en mètres */
  const WOM_BASE = 20037508.3427892476320267;
  
  /** dict. des GéoTiffs contenus dans la couche
   * @var array<string, TGeoTiffStoredInLayer> $geotiffs */
  protected array $geotiffs=[];
  
  /** @param array<string, TGeoTiff> $dictOfGT dictionnaire des GéoTiffs */
  function __construct(string $lyrName, array $dictOfGT) {
    //echo "lyrName=$lyrName<br>\n";
    //echo "dilate=$dilate<br>\n";
    foreach ($dictOfGT as $gtname => $gt) {
      foreach ($gt['outgrowth'] ?? [] as $i => $outgrowth) {
        // l'excroissance est dilatée pour compenser l'érosion sur la partie principale
        $gt['outgrowth'][$i] = \gegeom\GBox::fromGeoDMd($outgrowth)->proj('WorldMercator')->dilate(-self::dilate($lyrName));
      }
      foreach ($gt['borders'] ?? [] as $k => $b)
        $gt['borders'][$k] = eval("return $b;"); // si c'est une expression, l'évalue et stocke le résultat
      $this->geotiffs[$gtname] = [
        'title'=> $gt['title'],
        // l'extension spatiale est légèrement errodée pour éviter d'affichier le trait noir du bord
        'spatial'=> \gegeom\GBox::fromGeoDMd($gt['spatial'])->proj('WorldMercator')->dilate(self::dilate($lyrName)),
        'outgrowth'=> $gt['outgrowth'] ?? [],
        'borders'=> $gt['borders'] ?? null,
        'deleted' => $gt['deleted'] ?? null,
      ];
    }
    //echo "<pre>"; print_r($this); echo "</pre>\n";
  }
  
  /** @return array<string, mixed> */
  function asArray(): array {
    $array = [];
    foreach($this->geotiffs as $gtname => $gt) {
      $ebox = $gt['spatial'];
      $array[$gtname] = [
        'spatial'=> ['west'=> $ebox->west(), 'south'=> $ebox->south(), 'east'=> $ebox->east(), 'north'=> $ebox->north()],
        'borders'=> $gt['borders'],
      ];
    }
    return $array;
  }
  
  /** calcul de l'union des ebox des GeoTiff de la couche
   *
   * lorsqu'un GéoTiff intersecte l'anti-méridien il est dupliqué dans les 2 hémisphères Ouest et Est */
  function ebox(): \gegeom\EBox {
    $lyrEbox = new \gegeom\EBox;
    foreach($this->geotiffs as $gtname => $gt) {
      $lyrEbox = $lyrEbox->union($gt['spatial']);
      // Si le GéoTiff intersecte l'anti-méridien alors il est dupliqué pour apparaitre aussi dans l'hémisphère Ouest
      $gbox = $gt['spatial']->geo('WorldMercator');
      if ($gbox->intersectsAntiMeridian())
        $lyrEbox = $lyrEbox->union($gbox->translate360West()->proj('WorldMercator'));
    }
    return $lyrEbox;
  }
  
  /** copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
   *
   * lorsqu'un GéoTiff intersecte l'anti-méridien il est dupliqué dans les 2 hémisphères Ouest et Est */
  function map(GeoRefImage $grImage, bool $debug, int $zoom=-1): void {
    //echo "<pre>"; print_r($this); echo "</pre>\n";
    $qebox = $grImage->ebox();
    // L'image est construite par la superposition des extraits des GéoTiffs intersectant le rectangle demandé
    foreach ($this->geotiffs as $gtname => $gt) {
      $gtif = null;
      $intEbox = $qebox->intersects($gt['spatial']); // intersection entre le rect. requêté et la z. cartographiée du GeoTiff
      if ($intEbox) {
        //echo "$gtname -> "; print_r($gt);
        $gtif = new GeoTiff($gtname, $gt['spatial'], $gt['borders'], $debug);
        $gtif->copyImage($grImage, $intEbox, $debug);
      }
      foreach ($gt['outgrowth'] ?? [] as $i=> $outgrowth) {
        $intEbox = $qebox->intersects($outgrowth); // intersection entre le rect. requêté et l'excroissance
        if ($intEbox) {
          $gtogth = new GeoTiff($gtname, $outgrowth, null, $debug);
          $gtogth->copyImage($grImage, $intEbox, $debug);
        }
      }
      // Si le GéoTiff intersecte l'anti-méridien alors il est dupliqué pour apparaitre aussi dans l'hémisphère Ouest
      $gbox = $gt['spatial']->geo('WorldMercator');
      //echo "gbox=$gbox<br>\n";
      if ($gbox->intersectsAntiMeridian()) {
        // calcul de l'intersection avec le rectangle de la zone cartographiée translaté de 360° vers l'Ouest
        $intEbox = $qebox->intersects($gbox->translate360West()->proj('WorldMercator'));
        if ($intEbox) {
          if (!$gtif)
            $gtif = new GeoTiff($gtname, $gt['spatial'], $gt['borders'], $debug);
          $gtif->translate360West()->copyImage($grImage, $intEbox, $debug);
        }
      }
    }
  }

  /** retourne le Feature correspondant au GeoTiff
  * @param array<string, mixed> $gt
  * @return TGeoJsonFeature */
  private function itemForGeoTiff(string $lyrname, string $gtname, array $gt, \gegeom\GBox $gbox): array {
    //echo "TiffLayer::itemForGeoTiff($lyrname, $gtname, , $gbox)<br>\n";
    try {
      $isoMd = IsoMd::read($gtname);
      $errorMessage = '';
    }
    catch (SExcept $e) {
      $isoMd = [];
      $errorMessage = $e->getMessage();
    }
    $ganWeek = '1715'; // par défaut, c'est a peu près la date des premières livraisons
    if (isset($isoMd['mdDate'])) { // Si la date de mise à jour des MD est remplie alors je prends pour ganweek cette semaine
      $time = strtotime($isoMd['mdDate']);
      $ganWeek = substr(date('o', $time), 2) . date('W', $time);
    }
    return [
      'type'=> 'Feature',
      'properties'=> array_merge(
        [
          'layer'=> $lyrname,
          'name'=> $gtname,
          'title'=> $gt['title'] ?? "titre inconnu",
        ],
        $isoMd ? [
          'scaleDenominator'=> $isoMd['scaleDenominator'] ?? 'undef',
          'mdDate'=> $isoMd['mdDate'] ?? 'undef',
          'edition'=> $isoMd['edition'] ?? 'undef',
          'lastUpdate'=> $isoMd['lastUpdate'] ?? 'undef',
        ] : [],
        ['ganWeek'=> $ganWeek],
        $errorMessage ? ['errorMessage'=> $errorMessage] : [],
      ),
      'geometry'=> [
        'type'=> 'Polygon',
        'coordinates'=> $gbox->polygon(),
      ],
    ];
  }
  
  /** retourne une liste de Features correspondant aux bbox des GéoTiffs
   * @return list<TGeoJsonFeature> */
  function items(string $lyrname, ?\gegeom\GBox $qgbox): array {
    //echo "TiffLayer::items($lyrname, $qgbox)<br>\n";
    $features = [];
    foreach($this->geotiffs as $gtname => $gt) {
      $gbox = $gt['spatial']->geo('WorldMercator'); // transf spatial en c. Géo.
      $f = null;
      if (!$qgbox || $qgbox->inters($gbox)) {
        $f = $this->itemForGeoTiff($lyrname, $gtname, $gt, $gbox);
        $features[] = $f;
      }
      if (!$qgbox || $qgbox->inters($gbox->translate360West())) { // duplication de la silhouette à l'Ouest du planisphère
        if (!$f)
          $f = $this->itemForGeoTiff($lyrname, $gtname, $gt, $gbox);
        // translation de la geometry de 360° Ouest
        $f['geometry']['coordinates'] = $gbox->translate360West()->polygon();
        $features[] = $f;
      }
    }
    //echo "TiffLayer::items() returns ",Yaml::dump($features),"<br>\n";
    return $features;
  }
  
  /** retourne la liste des EBox des GéoTiffs de la couche intersectant le rectangle
   *
   * Les GéoTiffs à cheval sur l'anti-méridien sont dupliqués à l'Ouest
   * @return array<int, \gegeom\EBox> */
  function itemEBoxes(\gegeom\EBox $wombox): array {
    $eboxes = [];
    foreach($this->geotiffs as $gtname => $gt) {
      if ($wombox->inters($gt['spatial']))
        $eboxes[] = $gt['spatial'];
      if ($gt['spatial']->east() > self::WOM_BASE) {
        $translated = $gt['spatial']->translateInX(- 2 * self::WOM_BASE);
        if ($wombox->inters($translated))
          $eboxes[] = $translated;
      }
    }
    return $eboxes;
  }

  /** retourne le Feature correspondant au zones effacées du GeoTiff
  * @param array<string, mixed> $gt
  * @return TGeoJsonFeature */
  private function deletedZonesForGeoTiff(string $lyrname, string $gtname, array $gt, \gegeom\GBox $gbox): array {
    //echo "<pre>gt="; print_r($gt);
    $mpolygon = [];
    foreach ($gt['deleted']['bboxes'] ?? [] as $bbox) {
      if (isset($bbox['SW'])) {
        $gbox = \gegeom\GBox:: fromGeoDMd($bbox);
        $mpolygon[] = $gbox->polygon();
      }
      else
        throw new Exception("TODO");
    }
    foreach($gt['deleted']['polygons'] ?? [] as $polygon) {
      foreach ($polygon as &$pos) {
        if (is_string($pos))
          $pos = \gegeom\Pos::fromGeoDMd($pos);
      }
      $mpolygon[] = [$polygon];
    }
    return [
      'type'=> 'Feature',
      'properties'=> [
        'layer'=> $lyrname,
        'name'=> $gtname,
        'title'=> $gt['title'] ?? "titre inconnu",
      ],
      'geometry'=> [
        'type'=> 'MultiPolygon',
        'coordinates'=> $mpolygon,
      ],
    ];
  }

  /** retourne un array de Features correspondant aux zones effacées des GéoTiffs
   * @return list<TGeoJsonFeature> */
  function deletedZones(string $lyrname, ?\gegeom\GBox $qgbox): array {
    $features = [];
    foreach($this->geotiffs as $gtname => $gt) {
      if (!$gt['deleted']) continue;
      $gbox = $gt['spatial']->geo('WorldMercator'); // transf spatial en c. Géo.
      if (!$qgbox || $qgbox->inters($gbox)) {
        $features[] = $this->deletedZonesForGeoTiff($lyrname, $gtname, $gt, $gbox);
      }
    }
    return $features;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


// Initialisation à parir du fichier shomgt.yaml
Layer::initLayers();
echo "<h2>Couches créées par Layer::initFromShomGt()</h2>\n";
echo "<pre>layers="; print_r(Layer::layers());
