<?php
namespace mapcat;
/*PhpDoc:
name: mapcat.inc.php
title: mapcat/mapcat.inc.php - accès au catalogue MapCat et vérification des contraintes
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/../bo/lib.inc.php';

use Symfony\Component\Yaml\Yaml;

/* décode le champ spatial de MapCat pour différentes utilisations
* et Vérifie les contraintes et les exceptions du champ spatial
* Les contraintes sont définies dans la constante CONSTRAINTS
* et la liste des exceptions est dans la constante EXCEPTIONS
*/
class Spatial extends \gegeom\GBox {
  const CONSTRAINTS = [
    "les latitudes sont comprises entre -90° et 90°",
    "la latitude North est supérieure à la latitude South",
    "les longitudes West et East sont comprises entre -180° et 180° sauf dans l'exception circumnavigateTheEarth",
    "la longitude East est supérieure à la longitude West sauf dans l'exception astrideTheAntimeridian",
  ];
  const EXCEPTIONS = [
    'astrideTheAntimeridian'=> [
      "l'exception astrideTheAntimeridian correspond à une boite à cheval sur l'anti-méridien",
      "sauf dans l'exception circumnavigateTheEarth",
      "elle est indiquée par la valeur 'astrideTheAntimeridian' dans le champ exception",
      "dans ce cas East < West mais Spatial::__construct() augmente East de 360° pour que East > West",
    ],
    'circumnavigateTheEarth'=> [
      "l'exception circumnavigateTheEarth correspond à une boite couvrant la totalité de la Terre en longitude",
      "elle est indiquée par la valeur 'circumnavigateTheEarth' dans le champ exception",
      "dans ce cas (East - West) >= 360 et -180° <= West < 180° < East < 540° (360+180)"
    ],
  ];
  protected ?string $exception=null; // nom de l'exception ou null
  
  /** @param string|TPos|TLPos|TLLPos|array{SW: string, NE: string, exception?: string} $param */
  function __construct(array|string $param=[]) {
    parent::__construct($param);
    if (is_array($param) && isset($param['exception'])) {
      $this->exception = $param['exception'];
    }
  }
  
  /** @return TPos */
  function sw(): array { return $this->min; }
  /** @return TPos */
  function ne(): array { return $this->max; }

  function badLats(): ?string { // si les latitudes ne sont pas correctes alors renvoie la raison, sinon renvoie null
    if (($this->sw()[1] < -90) || ($this->ne()[1] > 90))
      return "lat < -90 || > 90";
    if ($this->sw()[1] >= $this->ne()[1])
      return "south > north";
    return null;
  }
  
  function badLons(): ?string { // si les longitudes ne sont pas correctes alors renvoie la raison, sinon renvoie null
    if ($this->sw()[0] >= $this->ne()[0])
      return "west >= est";
    if ($this->sw()[0] < -180)
      return "west < -180";
    return null;
  }
  
  function exceptionLons(): ?string { // si $this correspond à une exception alors renvoie son libellé, sinon null 
    if (($this->ne()[0] - $this->sw()[0]) >= 360)
      return 'circumnavigateTheEarth';
    if ($this->ne()[0] > 180)
      return 'astrideTheAntimeridian';
    return null;
  }
  
  function isBad(): ?string { // si $this n'est pas correct alors renvoie la raison, sinon null
    $bad = false;
    if (($error = $this->badLats()) || ($error = $this->badLons())) {
      return $error;
    }
    if (($exception = $this->exceptionLons()) <> $this->exception) {
      return $exception;
    }
    return null;
  }
  
  // surface approximative en degrés carrés
  function area(): float { return ($this->max[0] - $this->min[0]) * ($this->max[1] - $this->min[1]); }
  
  /** @return TPos */
  private function nw(): array { return [$this->min[0], $this->max[1]]; }
  /** @return TPos */
  private function se(): array { return [$this->max[0], $this->min[1]]; }
  
  private function shift(float $dlon): self { // créée une nouvelle boite décalée de $dlon
    $shift = clone $this;
    $shift->min[0] += $dlon;
    $shift->max[0] += $dlon;
    return $shift;
  }
  
  /** @return TLPos */
  private function ring(): array { return [$this->nw(), $this->sw(), $this->se(), $this->ne(), $this->nw()]; }
  
  // Retourne la boite comme MultiPolygon GeoJSON avec décomposition en 2 polygones
  // A linear ring MUST follow the right-hand rule with respect to the area it bounds,
  // i.e., exterior rings are clockwise, and holes are counterclockwise.
  /** @return TGJMultiPolygon */
  private function multiPolygon(): array { // génère un MultiPolygone GeoJSON 
    if ($this->max[0] < 180) { // cas standard
      return [
        'type'=> 'MultiPolygon',
        'coordinates'=> [[ $this->ring() ]],
      ];
    }
    else { // la boite intersecte l'antiméridien => duplication de l'autre côté
      return [
        'type'=> 'MultiPolygon',
        'coordinates'=> [
          [ $this->ring() ],
          [ $this->shift(-360)->ring() ],
        ],
      ];
      
    }
  }
  
  /** @return TGeoJsonFeatureCollection */
  private function layer(string $popupContent): array { // génère une FeatureCollection GeoJson contenant le multiPolygone
    return [
      'type'=> 'FeatureCollection',
      'features'=> [[
        'type'=> 'Feature',
        'geometry'=> $this->multiPolygon(),
        'properties'=> [
          'popupContent'=> $popupContent,
        ],
      ]],
    ];
  }
  
  /*function lgeoJSON0(): string { // génère un objet L.geoJSON - modèle avec constante
    return <<<EOT
  L.geoJSON(
          { "type": "MultiPolygon",
            "coordinates": [
               [[[ 180.0,-90.0 ],[ 180.1,-90.0 ],[ 180.1,90.0],[ 180.0,90.0 ],[ 180.0,-90.0 ] ] ],
               [[[-180.0,-90.0 ],[-180.1,-90.0 ],[-180.1,90.0],[-180.0,90.0 ],[-180.0,-90.0 ] ] ]
            ]
          },
          { style: { "color": "red", "weight": 2, "opacity": 0.65 } });

EOT;
  }*/
  /** @param array<string, string|int|float> $style */
  function lgeoJSON(array $style, string $popupContent): string { // retourne le code JS génèrant l'objet L.geoJSON
    return
      sprintf('L.geoJSON(%s,{style: %s, onEachFeature: onEachFeature});',
        json_encode($this->layer($popupContent)),
        json_encode($style))
      ."\n";
  }

  static function test(string $cas): void {
    echo "Spatial::test($cas)<br>\n";
    switch ($cas) {
      case 'Spatial::multiPolygon': {
        echo "<pre>";
        $spatial = new Spatial(['SW'=>"42°N - 9°E", 'NE'=> "43°N - 10°E"]);
        echo Yaml::dump([$spatial->multiPolygon()], 4);
        $spatial = new Spatial(['SW'=> "51°S - 104°E", 'NE'=> "02°S - 168°W"]);
        echo Yaml::dump([$spatial->multiPolygon()], 4);
        break;
      }
    }
  }
};
//Spatial::test();

// Un objet MapCat correspond à l'enregistrement d'une carte dans le catalogue MapCat
abstract class MapCatItem {
  //const SUBCLASS = 'MapCatFromFile'; // la sous-classe concrète effectivement utilisée
  const SUBCLASS = '\mapcat\MapCatItemInBase';
  const ALL_KINDS = ['current','obsolete','uninteresting','deleted'];
  /** @var TMapCatEntry $cat */
  protected array $cat; // contenu de l'entrée du catalogue correspondant à une carte
  /** @var TMapCatKind $kind */
  public readonly string $kind; // type de carte ('current' | 'obsolete' | 'uninteresting' | 'deleted')
  
  /** Retourne la liste des numéros de carte (sans FR) en fonction de la liste des types
   * @param list<TMapCatKind> $kindOfMap
   * @return list<string>
   */
  static function mapNums(array $kindOfMap=['current','obsolete']): array {
    return (self::SUBCLASS)::mapNums($kindOfMap);
  }
  
  /** Retourne l'objet MapCat correspondant au numéro de carte (sans FR) ou null s'il n'existe pas
   * @param list<TMapCatKind> $kindOfMap
   */
  static function get(string $mapNum, array $kindOfMap=['current','obsolete']): ?self {
    return (self::SUBCLASS)::get($mapNum, $kindOfMap);
  }
  
  /** 
   * @param TMapCatEntry $cat
   * @param TMapCatKind $kind */
  protected function __construct(array $cat, string $kind) { $this->cat = $cat; $this->kind = $kind; }
  
  function __get(string $property): mixed { return $this->cat[$property] ?? null; }
  
  /** @return array<string,mixed> */
  function asArray(): array { return array_merge($this->cat, ['kind'=> $this->kind]); }
  
  function scale(): ?string { // formatte l'échelle comme dans le GAN
    return $this->scaleDenominator ? '1 : '.str_replace('.',' ',$this->scaleDenominator) : 'undef';
  }

  function insetScale(int $i): ?string { // formatte l'échelle comme dans le GAN
    return '1 : '.str_replace('.',' ',$this->insetMaps[$i]['scaleDenominator']);
  }

  function spatial(): ?Spatial { return $this->spatial ? new Spatial($this->spatial) : null; }
  
  /** @return array<string, Spatial>*/
  function spatials(): array { // retourne la liste des extensions spatiales sous la forme [title => Spatial]
    $spatials = $this->spatial ? ['image principale de la carte'=> new Spatial($this->spatial)] : [];
    //echo "<pre>insetMaps = "; print_r($this->insetMaps); echo "</pre>\n";
    foreach ($this->insetMaps ?? [] as $i => $insetMap) {
      $spatials[$insetMap['title']] = new Spatial($insetMap['spatial']);
    }
    return $spatials;
  }

  /** retourne la liste triée des titres des cartouches
   * @return list<string>
   */
  function insetTitlesSorted(): array {
    if (!$this->insetMaps) return [];
    $insetTitles = [];
    foreach ($this->insetMaps as $insetMap) {
      $insetTitles[] = $insetMap['title'];
    }
    sort($insetTitles);
    return $insetTitles;
  }

  // retourne si l'image principale est géoréférencée alors son scaleDenominator
  // sinon le plus grand scaleDenominator des cartouches
  function scaleDenominator(): string {
    if ($this->scaleDenominator)
      return $this->scaleDenominator;
    else {
      $scaleDenominators = [];
      foreach ($this->insetMaps as $inset) {
        $sd = $inset['scaleDenominator'];
        $scaleDenominators[(int)str_replace('.','',$sd)] = $sd;
      }
      ksort($scaleDenominators, SORT_NUMERIC);
      return array_values($scaleDenominators)[count($scaleDenominators)-1];
    }
  }
};

class MapCatItemFromFile extends MapCatItem {
  /** @var array<string, TMapCatEntry> $maps */
  static array $maps=[]; // contenu du champ maps de MapCat
  /** @var array<string, TMapCatEntry> $obsoleteMaps */
  static array $obsoleteMaps=[]; // contenu du champ obsoleteMaps de MapCat
  /** @var array<string, TMapCatEntry> $uninterestingMaps */
  static array $uninterestingMaps=[]; // contenu du champ uninterestingMaps de MapCat
  /** @var array<string, TMapCatEntry> $deletedMaps */
  static array $deletedMaps=[]; // contenu du champ deletedMaps de MapCat
  
  private static function init(): void {
    $mapCat = self::$maps = Yaml::parseFile(__DIR__.'/mapcat.yaml');
    self::$maps = $mapCat['maps'];
    self::$obsoleteMaps = $mapCat['obsoleteMaps'];
    self::$uninterestingMaps = $mapCat['uninterestingMaps'];
    self::$deletedMaps = $mapCat['deletedMaps'];
    //print_r(self::$uninterestingMaps);
  }
  
  /** Retourn la liste des numéros de cartes correspondant aux types définis dans $kindOfMaps
   * @param list<TMapCatKind> $kindOfMap
   * @return list<string>
   */
  static function mapNums(array $kindOfMap=['current','obsolete']): array {
    if (!self::$maps) self::init();
    return array_merge(
      in_array('current', $kindOfMap) ? array_keys(self::$maps) : [],
      in_array('obsolete', $kindOfMap) ? array_keys(self::$obsoleteMaps) : [],
      in_array('uninteresting', $kindOfMap) ? array_keys(self::$uninterestingMaps) : [],
      in_array('deleted', $kindOfMap) ? array_keys(self::$deletedMaps) : [],
    );
  }
    
  /** retourne l'entrée du catalogue correspondant à $mapNum sous la forme d'un objet MapCat
   * si cette entrée n'existe pas retourne null
   * @param list<TMapCatKind> $kindOfMap
   */
  static function get(string $mapNum, array $kindOfMap=['current','obsolete']): ?self {
    //echo "mapNum=$mapNum<br>\n";
    if (!self::$maps) self::init();
    if (substr($mapNum, 0, 2) <> 'FR')
      $mapNum = 'FR'.$mapNum;
    if (in_array('current', $kindOfMap) && ($cat = (self::$maps[$mapNum] ?? null))) {
      return new self($cat, 'current');
    }
    // Je cherche la carte dans les cartes obsolètes
    if (in_array('obsolete', $kindOfMap) && ($cat = (self::$obsoleteMaps[$mapNum] ?? null))) {
      //print_r($cat);
      $date = array_keys($cat)[count($cat)-1];
      return new self(array_merge(['obsoleteDate'=> $date], $cat[$date]), 'obsolete');
    }
    // Je cherche la carte dans les cartes inintéressantes
    if (in_array('uninteresting', $kindOfMap) && ($cat = self::$uninterestingMaps[$mapNum] ?? null)) {
      return new self($cat, 'uninteresting');
    }
    if (in_array('deleted', $kindOfMap) && ($cat = (self::$deletedMaps[$mapNum] ?? null))) {
      //print_r($cat);
      $date = array_keys($cat)[count($cat)-1];
      return new self(array_merge(['deletedDate'=> $date], $cat[$date]), 'deleted');
    }
    return null;
  }
}

class MapCatItemInBase extends MapCatItem {
  static function mapNums(array $kindOfMap=['current','obsolete']): array {
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    $mapNums = [];
    $query = "select distinct(mapnum) from mapcat where kind in ('".implode("','", $kindOfMap)."')";
    foreach (\MySql::query($query) as $tuple) {
      $mapNums[] = substr($tuple['mapnum'], 2);
    }
    return $mapNums;
  }
  
  static function get(string $mapNum, array $kindOfMap=['current','obsolete']): ?self {
    //echo "appel de MapCatInBase::get($mapNum)<br>\n";
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    $mapcats = \MySql::getTuples("select mapnum, kind, jdoc from mapcat where mapnum='FR$mapNum' order by id desc");
    //echo "<pre>mapcats="; print_r($mapcats); echo "</pre>\n";
    if (!($mapcat = $mapcats[0] ?? null)) // le plus récent est en 0 étant donné le tri sur id desc
      return null;
    $jdoc = json_decode($mapcat['jdoc'], true);
    unset($jdoc['kind']);
    return new MapCatItemInBase($jdoc, $mapcat['kind']);
  }
};


if (!\bo\callingThisFile(__FILE__)) return; // retourne si le fichier est inclus

  
// Test des définitions des classes

switch ($_GET['action'] ?? null) {
  case null: { // menu
    echo "<a href='?action=testSpatial&cas=Spatial::multiPolygon'>Test Spatial, cas Spatial::multiPolygon</a><br>\n";
    $kind = isset($_GET['kind']) ? explode(',',$_GET['kind']) : [];
    echo "  <form>
      <div>
        <fieldset>
          <legend>Ou sélectionner un ou plusieurs types de carte</legend>\n";
    foreach (MapCat::ALL_KINDS as $k)
      echo "        <div><input type='checkbox' name='$k' value='true' ",in_array($k, $kind) ? 'checked ' : '',"/>",
                   "<label for='$k'>$k</label></div>\n";
    echo "      </fieldset>
      </div>
      <div>
        <input type='hidden' name='action' value='mapcat' />
        <button type='submit'>Go</button>
      </div>
    </form>\n";
    die();
  }
  case 'testSpatial': {
    Spatial::test($_GET['cas']);
    echo "<a href='?'>Retour au choix</a><br>\n";
    die();
  }
  case 'mapcat': { // liste des MapCat et création d'un MapCat
    if (!isset($_GET['mapNum'])) {
      if (isset($_GET['kind'])) {
        $kind = explode(',',$_GET['kind']);
      }
      else {
        $kind = [];
        foreach (MapCatItem::ALL_KINDS as $k) {
          if ($_GET[$k] ?? null)
            $kind[] = $k;
        }
      }
      echo "<h3>Liste des ",implode(',',$kind),"</h3><ul>\n";
      foreach(MapCatItem::mapNums($kind) as $mapNum) {
        $mapcat = MapCatItem::get($mapNum, $kind);
        echo "<li><a href='?action=mapcat&mapNum=$mapNum&kind=",implode(',',$kind),"'>",
             "$mapNum - $mapcat->title ($mapcat->kind)</a></li>\n";
      }
      echo "</ul>\n";
    }
    else {
      $mapcat = MapCatItem::get($_GET['mapNum'], MapCatItem::ALL_KINDS);
      echo '<pre>',Yaml::dump($mapcat->asArray()),"</pre>\n";
      //print_r($mapcat);
      echo "<a href='?action=mapcat&kind=$_GET[kind]'>Retour à la liste des $_GET[kind]</a><br>\n";
      $kind = explode(',',$_GET['kind']);
    }
    echo "<a href='?kind=",implode(',',$kind),"'>Retour au choix</a><br>\n";
    die();
  }
}
