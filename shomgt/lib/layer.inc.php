<?php
/*PhpDoc:
title: layer.inc.php - définition des classes Layer, PyrLayer, LabelLayer et TiffLayer
name: layer.inc.php
classes:
doc: |
  Les 4 classes permettent de construire à partir de shomgt.yaml la structuration en couches et de l'exploiter au travers
  des méthodes map() qui recopie dans une image GD l'extrait de la couche correspondant à un rectangle
  et pour la classe TiffLayer la méthode items() qui génère en GeoJSON les silhouettes des GéoTiffs.

  La classe abstraite Layer définit les couches du serveur de cartes.
  La classe TiffLayer correspond aux couches agrégeant des GéoTiff.
  La classe PyrLayer correspond à la pyramide des TiffLayer qui permet d'afficher le bon GéoTiff en fonction du niveau de zoom.
  Enfin, la classe LabelLayer correspond aux étiquettes associées aux GéoTiff.
journal: |
  7/6/2022:
    - ajout d'une érosion des rectangles englobants des cartes d'une mesure définie sur la carte, ex 1mm
      pour s'assurer que le trait du bord de la carte est bien effacé
  6/6/2022:
    - modif. définition du niveau de zoom dans PyrLayer::map()
  30/5/2022:
    - modif initialisation Layer
  24/5/2022:
    - envoi d'une erreur Http 500 lorsque le fichier shomgt.yaml n'existe pas
  22/5/2022:
    - dans TiffLayer gestion des cartes n'ayant pas de Mdiso
    - dans TiffLayer duplication des silhouettes des GéoTiffs à cehval sur l'AM
  1/5/2022:
    - ajout de la classe PyrLayer
  29/4/2022:
    - gestion des GéoTiff à cheval sur l'anti-méridien
    - gestion de la superposition de plusieures couches
  25/4/2022:
    - scission de maps.php
includes:
  - lib/grefimg.inc.php
  - lib/geotiff.inc.php
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/grefimg.inc.php';
require_once __DIR__.'/geotiff.inc.php';
require_once __DIR__.'/zoom.inc.php';
require_once __DIR__.'/isomd.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
title: class Layer - classe abstraite correspondant à une couche du serveur de cartes
name: Layer
doc: |
  Cette classe définit l'interface et stocke le dictionnaire des couches dans la variable statique self::$layers
  [{lyrName} => Layer] initialisé à partir du fichier shomgt.yaml par initFromShomGt()
*/
abstract class Layer {
  const LAYERS_PSER_PATH = __DIR__.'/layers.pser';
  const DILATE = - 1e-3; // dilatation en mètres sur la carte

  static array $layers=[]; // dictionaire [{lyrName} => Layer]

  // initialise le dictionnaire des couches à partir du fichier shomgt.yaml
  static function initFromShomGt(string $filename): void {
    if (!is_file("$filename.yaml")) { // cas notamment où shomgt.yaml n'a pas encore été généré
      header('HTTP/1.1 500 Internal Server Error');
      header('Content-type: text/plain; charset="utf-8"');
      die("Erreur: Erreur fichier $filename.yaml absent\n");
    }
    if (is_file(self::LAYERS_PSER_PATH) && (filemtime(self::LAYERS_PSER_PATH) > filemtime("$filename.yaml"))) {
      self::$layers = unserialize(file_get_contents(self::LAYERS_PSER_PATH));
      return;
    }
    self::$layers['gtpyr'] = new PyrLayer;
    $shomgt = Yaml::parseFile("$filename.yaml");
    foreach ($shomgt as $lyrName => $dictOfGT) {
      if (substr($lyrName,0,2)=='gt') {
        self::$layers[$lyrName] = new TiffLayer($lyrName, $dictOfGT ?? []);
        self::$layers['num'.substr($lyrName,2)] = new LabelLayer($lyrName, $dictOfGT ?? []);
      }
    }
    file_put_contents(self::LAYERS_PSER_PATH, serialize(self::$layers));
  }
  
  static function dilate(string $lyrName): float { // calcule le coeff de dilatation en fonction du nom de la couche
    $sd = substr($lyrName, 2);
    if (ctype_digit(substr($sd, 0, 1)) && ($sd <> '40M')) {
      $sd = str_replace(['k','M'], ['000','000000'], $sd);
      return self::DILATE * $sd;
    }
    else {
      return 0;
    }
  }

  // retourne le dictionnaire des couches
  static function layers() { return self::$layers; }
  
  // fournit une représentation de la couche comme array pour affichage
  abstract function asArray(): array;

  // calcul de l'extension spatiale de la couche en WoM
  abstract function ebox(): EBox;

  // copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
  // lorsqu'un élément intersecte l'anti-méridien, il est dupliqué dans les 2 hémisphères Ouest et Est
  // le paramètre zoom est uniquement utilisé dans la classe PyrLayer
  abstract function map(GeoRefImage $grImage, bool $debug, int $zoom=-1): void;
};

/*PhpDoc: classes
title: class PyrLayer - couche Pyramide
name: PyrLayer
doc: |
  La classe PyrLayer implémente un objet Layer correspondant à la pyramide des échelles.
  Le choix des échelles en fonction du niveau de zoom est défini dans la méthode PyrLayer::map()
*/
class PyrLayer extends Layer {
  const ErrorBadZoomValue = 'PyrLayer::ErrorBadZoomValue';
  
  function asArray(): array {
    return ['title'=> "Pyramide des cartes GéoTiff du 1/40M au 1/5k"];
  }
  
  function ebox(): EBox { // Le rectangle englobant est l'extension de WorldMercator
    $gbox = new GBox(WorldMercator::spatial());
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


/*PhpDoc: classes
title: class LabelLayer - couche d'étiquettes
name: PyrLayer
doc: |
  Permet de dessiner les étiquettes associées aux GéoTiffs
*/
class LabelLayer extends Layer {
  protected array $nws=[]; // dictionnaire [gtname => coin NW du rectangle englobant du GéoTiff en coord. WorldMercator]
  
  function __construct(string $lyrName, array $dictOfGT) {
    foreach ($dictOfGT as $gtname => $gt) {
      $gbox = GBox::fromShomGt($gt['spatial']);
      $nw = WorldMercator::proj([$gbox->west(), $gbox->north()]);
      $dilate = self::dilate($lyrName);
      $nw[0] -= $dilate;
      $nw[1] += $dilate;
      $this->nws[$gtname] = $nw;
    }
  }
  
  function asArray(): array { return $this->nw; }
  
  function ebox(): EBox {
    $ebox = new EBox;
    foreach ($this->nws as $pos)
      $ebox->bound($pos);
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


/*PhpDoc: classes
title: class LayerTiff - couche correspondant à un ensemble de GéoTiff juxtaposés
name: LayerTiff
doc: |
  Chaque couche est définie comme un dictionnaire [gtname -> ['spatial'=> EBoxEnWoM, 'borders'=>({borders}|null)]]
  où {borders} est un array des taille des bords à supprimer pour 'right', 'bottom', 'left' et 'top'
  La méthode map() recopie dans l'image géoréférencée passée en paramètre l'extrait des GéoTiffs d'une couche en effacant
  leurs bords. L'image est géoréférencée dans le système de coordonnées WorldMercator.
  Lorsqu'un GéoTiff intersecte l'anti-méridien, il est stocké une seule fois avec un EBox ayant -180 < west < 180 < east < 540
  et lors de la recopie dans l'image il est dupliqué dans l'hémisphère Est et dans l'hémisphère Ouest
*/
class TiffLayer extends Layer {
  const ErrorBadGeoCoords = 'Layer::ErrorBadGeoCoords';
  
  protected array $geotiffs=[]; // dictionnaire [gtname => ['title'=>string, 'spatial'=>EBox, 'borders'=>{borders}?]]
  
  function __construct(string $lyrName, array $dictOfGT) {
    //echo "lyrName=$lyrName<br>\n";
    //echo "dilate=$dilate<br>\n";
    foreach ($dictOfGT as $gtname => $gt) {
      foreach ($gt['borders'] ?? [] as $k => $b)
        $gt['borders'][$k] = eval("return $b;"); // si c'est une expression, l'évalue et stocke le résultat
      $this->geotiffs[$gtname] = [
        'title'=> $gt['title'],
        'spatial'=> GBox::fromShomGt($gt['spatial'])->proj('WorldMercator')->dilate(self::dilate($lyrName)),
        'borders'=> $gt['borders'] ?? null,
      ];
    }
  }
  
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
  
  // calcul de l'union des ebox des GeoTiff de la couche ;
  // lorsqu'un GéoTiff intersecte l'anti-méridien il est dupliqué dans les 2 hémisphères Ouest et Est
  function ebox(): EBox {
    $lyrEbox = new EBox;
    foreach($this->geotiffs as $gtname => $gt) {
      $lyrEbox->union($gt['spatial']);
      // Si le GéoTiff intersecte l'anti-méridien alors il est dupliqué pour apparaitre aussi dans l'hémisphère Ouest
      $gbox = $gt['spatial']->geo('WorldMercator');
      if ($gbox->intersectsAntiMeridian())
        $lyrEbox->union($gbox->translate360West()->proj('WorldMercator'));
    }
    return $lyrEbox;
  }
  
  // copie dans $grImage l'extrait de la couche correspondant au rectangle de $grImage,
  // lorsqu'un GéoTiff intersecte l'anti-méridien il est dupliqué dans les 2 hémisphères Ouest et Est
  function map(GeoRefImage $grImage, bool $debug, int $zoom=-1): void {
    //echo "<pre>"; print_r($this); echo "</pre>\n";
    $qebox = $grImage->ebox();
    // L'image est construite par la superposition des extraits des GéoTiffs intersectant le rectangle demandé
    foreach ($this->geotiffs as $gtname => $gt) {
      $gtif = null;
      $intEbox = $qebox->intersects($gt['spatial']); // intersection entre le rect. requêté et la z. cartographiée du GeoTiff
      if ($intEbox) {
        $gtif = new GeoTiff($gtname, $gt['spatial'], $gt['borders'], $debug);
        $gtif->copyImage($grImage, $intEbox, $debug);
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

  // retourne le Feature correspondant au GeoTiff
  private function itemForGeoTiff(string $lyrname, string $gtname, array $gt, GBox $gbox): array {
    try {
      $isoMd = IsoMD::read($gtname);
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
  
  function items(string $lyrname, ?GBox $qgbox): array { // retourne un array de Features correspondant aux bbox des GéoTiffs
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
    return $features;
  }
  
  function itemEBoxes(): array { // retourne la liste des EBox correspondant aux GéoTiffs de la couche 
    $eboxes = [];
    foreach($this->geotiffs as $gtname => $gt)
      $eboxes[] = $gt['spatial'];
    return $eboxes;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


// Initialisation à parir du fichier shomgt.yaml
Layer::initFromShomGt(__DIR__.'/../../data/shomgt');
//print_r(Layer::layers());
