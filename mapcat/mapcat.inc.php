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


class StdOrderOfProp {
  /** standardise l'ordre des propriétés de $src conformément au standard transmis $std
   * Le standard est défini récursivement comme un array Php dont chaque élément est:
   *  - soit int => chaine pour les propriétés élémentaires
   *  - soit chaine => sous-standard pour les propriétés contenant un sous-dict ou une liste de sous-dict
   *    le sous-standard s'applique alors au sous-dict ou a chacun des sous-dict
   * @param array<mixed> $std;
   * @param array<mixed> $src;
   * @return array<mixed>;
  */
  static function ofDict(array $std, array $src, string $path=''): array {
    $stdDict = [];
    //echo "<pre>Appel de StdOrderOfProp::ofDict(path='$path', std=",json_encode($std),", src=",json_encode($src),")<pre>\n";
    foreach ($std as $k => $prop) {
      //echo json_encode(["path=$path" => [$k => $prop]]),"\n";
      if (is_int($k)) { // propriété simple correspondant à une valeur
        //echo "$k -> $prop\n";
        // je réordonne les propriétés dans l'ordre de std
        if (isset($src[$prop])) {
          $stdDict[$prop] = $src[$prop];
        }
      }
      else { // propriété complexe
        list($prop, $sstd) = [$k, $prop]; // la clé est le nom de la propriété et la valeur ::= std | liste d'un std
        //echo Yaml::dump(['sstd'=> [$prop => $sstd]]),"\n";
        //echo "appel récursif sur $prop\n";
        if (!isset($src[$prop])) continue;
        if (!is_array($src[$prop]))
          throw new \Exception("erreur sur $path/$prop, incompatibilité entre le std et le src");
        if (!array_is_list($src[$prop])) { // propriété correspondant à un sous-objet
          $stdDict[$prop] = self::ofDict($sstd, $src[$prop], "$path/$prop"); // @phpstan-ignore-line
        }
        else { // propriété correspond à une liste de sous-objets
          $stdDict[$prop] = [];
          foreach ($src[$prop] as $i => $elt) {
            $stdDict[$prop][] = self::ofDict($sstd, $elt); // @phpstan-ignore-line
          }
        }
      }
      unset($src[$prop]);
    }
    // je rajoute à la fin les propriétés absentes du std
    foreach ($src as $prop => $val) {
      $stdDict[$prop] = $val;
    }
    //echo "<pre>StdOrderOfProp::ofDict(path='$path') retourne ",json_encode($stdDict),"<pre>\n";
    return $stdDict;
  }
  
  static function testOfDict(): void { // test de self::ofDict()
    $dict = [
      'title'=> 'title',
      'groupTitle'=> 'groupTitle',
      'spatial'=> [
        'NE'=> 'NE',
        'SW'=> 'SW',
      ],
      'insetMaps'=> [
        [
          'scaleDenominator'=> 'scaleDenominator',
          'title'=> 'title',
        ],
        [
          'scaleDenominator'=> 'scaleDenominator2',
          'title'=> 'title2',
        ],
      ]
    ];
    echo '<pre>',Yaml::dump(['dict'=> $dict, 'stdOrderOfPropForDict'=> self::ofDict(MapCatItem::STD_PROP, $dict)], 5, 2),"\n";
  }
  
  /** teste si $std est bien formé, si OK alors retourne null, sinon retourne l'erreur rencontrée
   * @param array<mixed> $std;
   */
  static function checkTypeOfStd(array $std, string $path=''): ?string {
    foreach ($std as $k => $prop) {
      //echo json_encode(["path=$path" => [$k => $prop]]),"\n";
      if (is_int($k)) { // propriété simple correspondant à une valeur atomique
        // prop doit être le nom de la propriété
        if (!is_string($prop))
          return "Erreur sur path='$path', ".json_encode([$k => $prop]).", prop n'est pas un string";
      }
      else { // propriété complexe
        list($prop, $sstd) = [$k, $prop]; // la clé est le nom de la propriété et la valeur ::= std | liste d'un std
        if (!is_array($sstd)) // @phpstan-ignore-line
          return "Erreur sur sur path='$path', ".json_encode([$prop => $sstd]).", sstd n'est pas un array";
        if ($error = self::checkTypeOfStd($sstd, "$path.$prop"))
          return $error;
      }
    }
    return null;
  }
  
  static function testCheckTypeOfStd(): void {
    echo "<pre>";
    $stds = [
      "1 ok" => [
        'a',
        'b',
        'c'=> ['a','b','c'],
        'd',
        'e'=> ['d','e','f'],
        'f'=> ['f'],
        'g'=> ['g'],
      ],
      "2 KO" => [
        'a',
        'c'=> 'a',
      ],
      "3 KO" => [
        'a',
        'c'=> [['a']],
      ],
    ];
    foreach ($stds as $label => $std) {
      echo "$label -> ",($error = self::checkTypeOfStd($std)) ? $error : 'ok',"\n";
    }
  }
};
if (0) { // @phpstan-ignore-line // Test de stdOrderOfPropForDict
  StdOrderOfProp::testOfDict();
  StdOrderOfProp::testCheckTypeOfStd();
  die("Fin ligne ".__LINE__);
}

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

// Un objet MapCatItem correspond à l'enregistrement d'une carte dans le catalogue MapCat
class MapCatItem {
  const ALL_KINDS = ['alive','uninteresting','deleted'];
  const STD_PROP = [
    'groupTitle',
    'title',
    'scaleDenominator',
    'spatial' => ['SW', 'NE', 'exception'], // propriété contenant un sous-objet
    'mapsFrance',
    'replaces',
    'references',
    'noteShom',
    'noteCatalog',
    'badGan',
    'z-order',
    'outgrowth',
    'toDelete',
    'borders',
    'layer',
    'insetMaps'=> [ // propriété contenant une liste de sous-objets
      'title',
      'scaleDenominator',
      'spatial',
      'noteCatalog',
      'badGan',
      'z-order',
      'outgrowth',
      'toDelete',
      'borders',
    ],
  ]; // ordre standard des propriétés 
  
  /** @var TMapCatEntry $item */
  protected array $item; // contenu de l'entrée du catalogue correspondant à une carte
  /** @var TMapCatKind $kind */
  public readonly string $kind; // type de carte ('alive' | 'uninteresting' | 'deleted')
  
  /** 
   * @param TMapCatEntry $item
   * @param TMapCatKind $kind */
  function __construct(array $item, string $kind) { $this->item = $item; $this->kind = $kind; }
  
  static function checkValidity(): ?string { // vérifie le type de self::STD_PROP
    return StdOrderOfProp::checkTypeOfStd(self::STD_PROP);
  }
  
  function __get(string $property): mixed { return $this->item[$property] ?? null; }
  
  /** @return array<string,mixed> */
  function asArray(): array {
    $array = StdOrderOfProp::ofDict(self::STD_PROP, $this->item);
    if ($this->kind == 'alive')
      return $array;
    else
      return array_merge($array, ['kind'=> $this->kind]);
  }
  
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

  function diff(string $labelA, string $labelB, self $b): void {
    echo "<table border=1><th>prop</th><th>$labelA</th><th>$labelB</th>\n";
    foreach ($this->item as $p => $val) {
      if ($val <> $b->$p)
        echo "<tr><td>$p</td><td><pre>",Yaml::dump($val),"</pre></td><td><pre>",Yaml::dump($b->$p),"</pre></td></tr>\n";
    }
    foreach ($b->item as $p => $val) {
      if (!$this->$p)
        echo "<tr><td>$p</td><td>null</td><td><pre>",Yaml::dump($val),"</pre></td></tr>\n";
    }
    echo "</table>\n";
  }
};
if ($error = MapCatItem::checkValidity()) throw new \Exception($error);

// La classe MapCat correspond au catalogue MapCat a priori en base 
class MapCat {
  /** Retourne la liste des numéros de carte (sans FR) en fonction de la liste des types
   * @param list<TMapCatKind> $kindOfMap
   * @return list<string>
   */
  static function mapNums(array $kindOfMap=['alive']): array {
    if ($kindOfMap <> ['alive'])
      throw new \Exception("En base seules les cartes vivantes sont disponibles");
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    $mapNums = [];
    $query = "select distinct(mapnum) from mapcat";
    foreach (\MySql::query($query) as $tuple) {
      $mapNums[] = substr($tuple['mapnum'], 2);
    }
    return $mapNums;
  }
  
  /** Retourne l'objet MapCat correspondant au numéro de carte (sans FR) ou null s'il n'existe pas
   * @param list<TMapCatKind> $kindOfMap
   */
  static function get(string $mapNum, array $kindOfMap=['alive']): ?MapCatItem {
    //echo "appel de MapCat::get($mapNum)<br>\n";
    if ($kindOfMap <> ['alive'])
      throw new \Exception("En base seules les cartes vivantes sont disponibles");
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    $mapcats = \MySql::getTuples("select mapnum, jdoc from mapcat where mapnum='FR$mapNum' order by id desc");
    //echo "<pre>mapcats="; print_r($mapcats); echo "</pre>\n";
    if (!($mapcat = $mapcats[0] ?? null)) // le plus récent est en 0 étant donné le tri sur id desc
      return null;
    $jdoc = json_decode($mapcat['jdoc'], true);
    return new MapCatItem($jdoc, 'alive');
  }
};

class MapCatFromFile extends MapCat {
  /** @var array<string, TMapCatEntry> $maps */
  static array $maps=[]; // contenu du champ maps de MapCat
  /** @var array<string, TMapCatEntry> $uninterestingMaps */
  static array $uninterestingMaps=[]; // contenu du champ uninterestingMaps de MapCat
  /** @var array<string, TMapCatEntry> $deletedMaps */
  static array $deletedMaps=[]; // contenu du champ deletedMaps de MapCat
  
  private static function init(): void {
    $mapCat = self::$maps = Yaml::parseFile(__DIR__.'/mapcat.yaml');
    self::$maps = $mapCat['maps'];
    self::$uninterestingMaps = $mapCat['uninterestingMaps'];
    self::$deletedMaps = $mapCat['deletedMaps'];
    //print_r(self::$uninterestingMaps);
  }
  
  /** Retourn la liste des numéros de cartes correspondant aux types définis dans $kindOfMaps
   * @param list<TMapCatKind> $kindOfMap
   * @return list<string>
   */
  static function mapNums(array $kindOfMap=['alive']): array {
    if (!self::$maps) self::init();
    $mapNums = array_merge(
      in_array('alive', $kindOfMap) ? array_keys(self::$maps) : [],
      in_array('uninteresting', $kindOfMap) ? array_keys(self::$uninterestingMaps) : [],
      in_array('deleted', $kindOfMap) ? array_keys(self::$deletedMaps) : [],
    );
    foreach ($mapNums as &$mapNum) { $mapNum = substr($mapNum, 2); }
    return $mapNums;
  }
    
  /** retourne l'entrée du catalogue correspondant à $mapNum sous la forme d'un objet MapCat
   * si cette entrée n'existe pas retourne null
   * @param list<TMapCatKind> $kindOfMap
   */
  static function get(string $mapNum, array $kindOfMap=['alive']): ?MapCatItem {
    //echo "mapNum=$mapNum<br>\n";
    if (!self::$maps) self::init();
    if (substr($mapNum, 0, 2) <> 'FR')
      $mapNum = 'FR'.$mapNum;
    if (in_array('alive', $kindOfMap) && ($cat = (self::$maps[$mapNum] ?? null))) {
      return new MapCatItem($cat, 'alive');
    }
    // Je cherche la carte dans les cartes inintéressantes
    if (in_array('uninteresting', $kindOfMap) && ($cat = self::$uninterestingMaps[$mapNum] ?? null)) {
      return new MapCatItem($cat, 'uninteresting');
    }
    if (in_array('deleted', $kindOfMap) && ($cat = (self::$deletedMaps[$mapNum] ?? null))) {
      //print_r($cat);
      $date = array_keys($cat)[count($cat)-1];
      return new MapCatItem(array_merge(['deletedDate'=> $date], $cat[$date]), 'deleted');
    }
    return null;
  }
}


if (!\bo\callingThisFile(__FILE__)) return; // retourne si le fichier est inclus

  
// Test des définitions des classes

echo "<!DOCTYPE html>\n<html><head><title>mapcat/mapcat.inc.php@$_SERVER[HTTP_HOST]</title></head><body>\n";

switch ($_GET['action'] ?? null) {
  case null: { // menu
    echo "<a href='?action=testSpatial&cas=Spatial::multiPolygon'>Test Spatial, cas Spatial::multiPolygon</a><br>\n";
    $kind = isset($_GET['kind']) ? explode(',',$_GET['kind']) : [];
    echo "  <form>
      <div>
        <fieldset>
          <legend>Ou sélectionner un ou plusieurs types de carte</legend>\n";
    foreach (MapCatItem::ALL_KINDS as $k)
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
      foreach(MapCat::mapNums($kind) as $mapNum) {
        $mapcat = MapCat::get($mapNum, $kind);
        echo "<li><a href='?action=mapcat&mapNum=$mapNum&kind=",implode(',',$kind),"'>",
             "$mapNum - $mapcat->title ($mapcat->kind)</a></li>\n";
      }
      echo "</ul>\n";
    }
    else {
      $mapcat = MapCat::get($_GET['mapNum'], MapCatItem::ALL_KINDS);
      echo '<pre>',Yaml::dump($mapcat->asArray()),"</pre>\n";
      //print_r($mapcat);
      echo "<a href='?action=mapcat&kind=$_GET[kind]'>Retour à la liste des $_GET[kind]</a><br>\n";
      $kind = explode(',',$_GET['kind']);
    }
    echo "<a href='?kind=",implode(',',$kind),"'>Retour au choix</a><br>\n";
    die();
  }
}
