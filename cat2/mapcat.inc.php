<?php
/*PhpDoc:
name: mapcat.inc.php
title: cat2 / mapcat.inc.php - Gestion du catalogue des cartes du Shom v2
classes:
doc: |
  
journal: |
  13/12/2020:
    - passage en V2
includes: [../lib/gegeom.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: BBoxDM
title: class BBoxDM - Gestion des BBox des cartes et des cartouches
doc: |
*/
class BBoxDM {
  const PATTERN = "!^(\\d+)°((\\d+)(,(\\d+))?)?'(N|S) - (\\d+)°((\\d+)(,(\\d+))?)?'(E|W)$!";
  protected $sw;
  protected $ne;
  protected $southlimit;
  protected $westlimit;
  protected $northlimit;
  protected $eastlimit;
  
  function __construct(array $bboxDM) {
    if (!preg_match(self::PATTERN, $bboxDM['SW'], $matches))
      throw new Exception("Erreur d'initialisation de BBoxDM sur SW = '$bboxDM[SW]'");
    //print_r($matches);
    $this->southlimit = ($matches[6]=='S' ? -1 : +1) * ($matches[1] + "$matches[3].$matches[5]"/60);
    //echo "southlimit=$this->southlimit\n";
    $this->westlimit = ($matches[12]=='W' ? -1 : +1) * ($matches[7] + "$matches[9].$matches[11]"/60);
    //echo "westlimit=$this->westlimit\n";
    $this->sw = $bboxDM['SW'];
    if (!preg_match(self::PATTERN, $bboxDM['NE']))
      throw new Exception("Erreur d'initialisation de BBoxDM sur NE = '$bboxDM[NE]'");
    //print_r($matches);
    $this->northlimit = ($matches[6]=='S' ? -1 : +1) * ($matches[1] + "$matches[3].$matches[5]"/60);
    //echo "northlimit=$this->northlimit\n";
    $this->eastlimit = ($matches[12]=='W' ? -1 : +1) * ($matches[7] + "$matches[9].$matches[11]"/60);
    //echo "eastlimit=$this->eastlimit\n";
    $this->ne = $bboxDM['NE'];
  }
  
  function __toString(): string { return "{SW: $this->sw, NE: $this->ne}"; }
  
  function asArray(): array {
    return [
      'SW'=> $this->sw,
      'NE'=> $this->ne,
    ];
  }
  
  function asDcmiBox(): array {
    return [
      'southlimit'=> $this->southlimit,
      'westlimit'=> $this->westlimit,
      'northlimit'=> $this->northlimit,
      'eastlimit'=> $this->eastlimit,
    ];
  }
  
  function asGBox(): GBox {
    return new GBox([[$this->eastlimit, $this->southlimit],[$this->westlimit, $this->northlimit]]);
  }
  
  function asPolygon(): Polygon { // retourne un Polygon
    return Geometry::fromGeoJSON(['type'=> 'Polygon', 'coordinates'=> $this->asGBox()->polygon()]);
  }
};

class MapPart {
  protected $title; // titre du cartouche
  protected $scaleDenominator; // dénominateur de l'échelle de l'espace principal avec un . comme séparateur des milliers,
  protected $bbox; // boite englobante du cartouche comme BBoxDM
  
  function __construct(array $mapPart) {
    $this->title = $mapPart['title'];
    $this->scaleDenominator = $mapPart['scaleDenominator'] ?? null;
    $this->bbox = new BBoxDM($mapPart['bboxDM']);
  }
  
  function asArray(): array {
    return
      ['title'=> $this->title]
      + ['scaleDenominator'=> $this->scaleDenominator]
      + ['bboxDM'=> $this->bbox->asArray()]
      + ['spatial'=> $this->bbox->asDcmiBox()]
      ;
  }
  
  function polygonCoords(): array { return $this->bbox->asGBox()->polygon(); }
};

/*PhpDoc: classes
name: MapCat
title: classe MapCat - Gestion de la description des cartes du Shom
doc: |
  Chaque objet de la classe MapCat décrit une carte du Shom.
  La variable statique $all est un dictionnaire sur le no de la carte précédé de FR.
  Le fichier mapcat.pser contient le catalogue des cartes ainsi que la date d'actualisation.

  Il existe des cartes sans espace principal (exemple 7436 - Approches et Port de Bastia - Ports d'Ajaccio et de Propriano)
  uniquement constituées de cartouches.
*/
class MapCat {
  const PATH = __DIR__.'/mapcat'; // chemin des fichiers stockant le catalogue en pser ou en yaml, ajouter l'extension au path
  static $maps = []; // dictionnaire des MapCat [FR{num} => MapCat]
  static $catTitle = null; // titre du catalogue
  static $catDescription = null; // titre du catalogue
  static $catCreated = null; // date et heure de création du catalogue au format ISO 8601
  static $catModified = null; // date et heure d'actualisation du catalogue au format ISO 8601
  
  protected $num; // no de la carte
  protected $groupTitle; // sur-titre optionnel identifiant un ensemble de cartes
  protected $title; // titre de la carte
  protected $edition; // edition de la carte
  protected $mapsFrenchAreas; // identifie les cartes dites d'intérêt, true, false, ou null
  protected $modified; // date de la dernière correction apportée à la carte ou null
  protected $lastUpdate; // no de la dernière correction apportée à la carte ou 0 ou null
  protected $scaleDenominator; // dénominateur de l'échelle de l'espace principal avec un . comme séparateur des milliers,
                                // null ssi la carte ne comporte pas d'espace principal
  protected $bbox; // boite englobante de l'espace principal de la carte comme BBoxDM, null ssi pas d'espace principal
  protected $replaces; // facsimilé éventuel
  protected $references; // ssi la carte est un fac-similé alors référence de la carte étrangère reproduite
  protected $note; // commentaire associé à la carte
  protected $hasPart=[]; // liste des éventuels cartouches, chacun comme MapPart

  private function mapsFrenchAreas(string $mapid) { // calcule si la carte intersecte la ZEE
    static $zee_france = null;
    static $interetInsuffisant = null;
    
    if (!$zee_france) {
      $france = json_decode(file_get_contents(__DIR__.'/france.geojson'), true);
      //echo Yaml::dump(['$france'=> $france], 5, 2);
      $zee_france = ['type'=> 'MultiPolygon', 'coordinates'=> []];
      foreach ($france['features'] as $feature) {
        $zee_france['coordinates'][] = $feature['geometry']['coordinates'];
      }
      $zee_france = Geometry::fromGeoJSON($zee_france);
      //echo "zee_france = $zee_france\n";
    }
    if (!$interetInsuffisant) {
      $interetInsuffisant = Yaml::parseFile(__DIR__.'/mapcatspec.yaml')['cartesAyantUnIntérêtInsuffisant'];
    }
    if (isset($interetInsuffisant[$mapid]))
      return false;
    //echo "bbox=",$this->bbox,"\n";
    if ($this->bbox) {
      $geom = $this->bbox->asPolygon();
    }
    else {
      $multiPolygonCoords = [];
      foreach ($this->hasPart as $part) {
        $multiPolygonCoords[] = $part->polygonCoords();
        $geom = Geometry::fromGeoJSON(['type'=> 'MultiPolygon', 'coordinates'=> $multiPolygonCoords]);
      }
    }
    return $zee_france->inters($geom);
  }
  
  function __construct(string $mapid, array $map) {
    $this->num = substr($mapid, 2);
    $this->groupTitle = $map['groupTitle'] ?? null;
    $this->title = $map['title'];
    $this->edition = $map['edition'] ?? $map['issued'] ?? null;
    $this->modified = $map['modified'] ?? null;
    $this->lastUpdate = isset($map['lastUpdate']) ? intval($map['lastUpdate']) : null;
    $this->scaleDenominator = $map['scaleDenominator'] ?? null;
    $this->bbox = isset($map['bboxDM']) ? new BBoxDM($map['bboxDM']) : null;
    $this->replaces = $map['replaces'] ?? null;
    $this->references = $map['references'] ?? null;
    $this->note = $map['note'] ?? null;
    foreach ($map['hasPart'] ?? $map['boxes'] ?? [] as $mapPart) {
      $this->hasPart[] = new MapPart($mapPart);
    }
    // si non défini alors il est calculé
    $this->mapsFrenchAreas = $map['mapsFrenchAreas'] ?? self::mapsFrenchAreas($mapid);
    if (!$this->mapsFrenchAreas)
      $this->lastUpdate = null;
    echo "mapsFrenchAreas: ",$this->mapsFrenchAreas ? "true\n" : "false\n";
  }
  
  static function importFromV1() {
    echo "importFromV1()\n";
    $catv1 = json_decode(file_get_contents(__DIR__.'/../cat/mapcat.json'), true);
    self::$catTitle = $catv1['title'];
    foreach ($catv1['maps'] as $mapid => $map) {
      //echo Yaml::dump([$mapid => $map]);
      self::$maps[$mapid] = new self($mapid, $map);
      //print_r(self::$all[$mapid]);
    }
    self::$catTitle = $catv1['title'];
    $created = date(DATE_ATOM);
    self::$catDescription = "Import du fichier V1 le $created";
    self::$catCreated = $created;
    self::$catModified = $created;
  }
  
  static function storeAsPser() {
    file_put_contents(self::PATH.'.pser', serialize([
      'title'=> self::$catTitle,
      'description'=> self::$catDescription,
      'created'=> self::$catCreated,
      'modified'=> self::$catModified,
      'maps'=> self::$maps,
    ]));
  }
  
  static function allAsArray(): array {
    return [
      'title'=> self::$catTitle,
      'description'=> self::$catDescription,
      '$id'=> 'http://geoapi.fr/shomgt/cat2/mapcat',
      '$schema'=> __DIR__.'/mapcat',
      'created'=> self::$catCreated,
      'modified'=> self::$catModified,
      'maps'=> array_map(function(MapCat $map) { return $map->asArray(); }, self::$maps),
    ];
  }
  
  static function storeAsYaml() {
    file_put_contents(self::PATH.'.yaml', Yaml::dump(self::allAsArray(), 5, 2));
  }
  
  static function init() {
    if (!file_exists(self::PATH.'.pser') && !file_exists(self::PATH.'.yaml'))
      throw new Exception("Erreur dans MapCat::init() : les fichiers mapcat.yaml et mapcat.pser n'existent ni l'un ni l'autre");
    if (!file_exists(self::PATH.'.pser')
     || (file_exists(self::PATH.'.yaml') && (filemtime(self::PATH.'.pser') < filemtime(self::PATH.'.yaml')))) {
      $yaml = Yaml::parseFile(self::PATH.'.yaml');
      echo "<pre>"; print_r($yaml);
      self::$catTitle = $yaml['title'];
      self::$catDescription = $yaml['description'];
      self::$catCreated = $yaml['created'];
      self::$catModified = $yaml['modified'];
      self::$maps = [];
      foreach ($yaml['maps'] as $mapid => $map) {
        self::$maps[$mapid] = new self($mapid, $map);
      }
      self::storeAsPser();
    }
    else { // le phpser existe et est plus récent que le Yaml alors initialisation à partir du phpser
      $pser = unserialize(file_get_contents(self::PATH.'.pser'));
      self::$catTitle = $pser['title'];
      self::$catDescription = $pser['description'];
      self::$catCreated = $pser['created'];
      self::$catModified = $pser['modified'];
      self::$maps = $pser['maps'];
    }
    
  }
  
  function asArray(): array {
    return
        ($this->groupTitle ? ['groupTitle'=> $this->groupTitle] : [])
      + ($this->title ? ['title'=> $this->title] : [])
      + ($this->edition ? ['edition'=> $this->edition] : [])
      + (($this->mapsFrenchAreas !== null) ? ['mapsFrenchAreas'=> $this->mapsFrenchAreas] : [])
      + ($this->modified ? ['modified'=> $this->modified] : [])
      + ($this->lastUpdate !== null ? ['lastUpdate'=> $this->lastUpdate] : [])
      + ($this->scaleDenominator ? ['scaleDenominator'=> $this->scaleDenominator] : [])
      + ($this->bbox ? [
        'bboxDM'=> $this->bbox->asArray(),
        'spatial'=> $this->bbox->asDcmiBox(),
        ] : [])
      + ($this->replaces ? ['replaces'=> $this->replaces] : [])
      + ($this->references ? ['references'=> $this->references] : [])
      + ($this->note ? ['note'=> $this->note] : [])
      + ($this->hasPart ? ['hasPart'=> array_map(function(MapPart $mapPart) { return $mapPart->asArray(); }, $this->hasPart)] : [])
      ;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire de la classe MapCat


if (php_sapi_name() <> 'cli') {
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>mapcat</title></head><body><pre>\n";
  if (!isset($_GET['action'])) {
    echo "</pre>mapcat.inc.php - Actions proposées:<ul>\n";
    echo "<li><a href='?action=importFromV1'>importe le catalogue V1 et l'enregistre en pser</a>\n";
    echo "<li><a href='?action=yaml'>Lit le catalogue et génère un Yaml</a>\n";
    echo "</ul>\n";
    die();
  }
  else
    $action = $_GET['action'];
}

if ($action == 'importFromV1') {
  MapCat::importFromV1();
  MapCat::storeAsYaml();
  MapCat::storeAsPser();
  die();
}

if ($action == 'yaml') {
  MapCat::init();
  echo Yaml::dump(MapCat::allAsArray(), 5, 2);
  die();
}

die("Action $action non prévue\n");
