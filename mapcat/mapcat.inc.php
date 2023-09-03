<?php
namespace mapcat;
/*PhpDoc:
name: mapcat.inc.php
title: mapcat/mapcat.inc.php - accès au catalogue MapCat et vérification des contraintes
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/../lib/jsonschema.inc.php';
require_once __DIR__.'/../bo/lib.inc.php';
require_once __DIR__.'/../bo/htmlform.inc.php';
require_once __DIR__.'/../shomft/frzee.inc.php';

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
  public readonly ?string $exception; // nom de l'exception ou null
  
  /** @param string|TPos|TLPos|TLLPos|array{SW: string, NE: string, exception?: string} $param */
  function __construct(array|string $param=[]) {
    parent::__construct($param);
    $this->exception = !is_array($param) ? null : ($param['exception'] ?? null);
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
          [ $this->translate360West()->ring() ],
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
// La classe porte en outre en constante le modèle de document Yaml
readonly class MapCatItem {
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
  
  const DOC_MODEL_IN_YAML = <<<EOT
title: # Titre de la carte, peut être recopié du GAN ou lu sur la carte, champ obligatoire
  #exemple: "De Port-Barcarès à l'embouchure de l'Aude"
scaleDenominator: # dénominateur de l'échelle de l'espace principal
  #commentaires:
  #  - avec un . comme séparateur des milliers, peut être recopié du GAN ou lu sur la carte
  #  - Champ absent ssi la carte ne comporte pas d'espace principal (uniquement des cartouches).
  #exemple:
  #  scaleDenominator: '50.200'
spatial: # boite englobante de l'espace principal décrit par ces 2 coins Sud-Ouest et Nord-Est 
  SW: # coin Sud-Ouest de la boite en degrés et minutes WGS84
  NE: # coin Nord-Est de la boite en degrés et minutes WGS84
  #commentaires:
  #  - Champ absent ssi la carte ne comporte pas d'espace principal (uniquement des cartouches).
  #  - chaque coin doit respecter le motif: '^\d+°(\d\d(,\d+)?'')?(N|S) - \d+°(\d\d(,\d+)?'')?(E|W)$'
  #  - peut être recopié du GAN ou lu sur la carte
  #exemple:
  #  spatial:
  #    SW: "42°43,64'N - 002°56,73'E"
  #    NE: "43°13,44'N - 003°24,43'E"
insetMaps: # liste éventuelle de cartouches, chacun respectant la structure ci-dessous
  - title: # Titre du cartouche, peut être recopié du GAN ou lu sur la carte
    scaleDenominator: # dénominateur de l'échelle deu cartouche, peut être recopié du GAN ou lu sur la carte
    spatial: # boite englobante du cartouche décrite comme celle de l'espace principal

EOT;
  
  /** construit le schéma d'une déf. de MapCat, déduit du schéma de MapCat
   * @return array<string, mixed> */
  static function getDefSchema(string $def): array {
    $catSchema = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.schema.yaml');
    if (!isset($catSchema['definitions'][$def]))
      throw new \Exception("Définition '$def' inconnue dans le schéma de MapCat");
    return [
      '$id'=> "https://sgserver.geoapi.fr/index.php/cat/schema/$def",
      '$schema'=> $catSchema['$schema'],
      'definitions' => $catSchema['definitions'],
      '$ref'=> "#/definitions/$def",
    ];
  }
  
  /** complète/valide le doc. / schéma
   * retourne un array contenant:
   *  - un champ errors avec les erreurs de validation si le doc n'est pas conforme au schéma map
   *  - un champ warnings avec les alertes
   *  - un champ validDoc avec le document corrigé et valide en Php si le doc est conforme
   * @return array{errors?: list<string|list<mixed>>, warnings?: list<string>, validDoc?: array<mixed>}
   */
  static function validatesAgainstSchema(string $yaml): array {
    // parse yaml
    try {
      $doc = Yaml::parse($yaml);
    }
    catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
      return ['errors'=> ["Erreur Yaml: ".$e->getMessage()]];
    }
    
    // si insetMaps n'est pas défini alors spatial et scaleDenominator doivent l'être
    // <=> (!insetMaps => spatial  && scaleDenominator)
    // <=> (!insetMaps && !(spatial  && scaleDenominator)) est faux
    // <=> si (!insetMaps && !(spatial  && scaleDenominator)) alors erreur
    if (!isset($doc['insetMaps']) && !(isset($doc['spatial']) && isset($doc['scaleDenominator']))) {
      return ['errors'=> ["Erreur: si .insetMaps n'est pas défini alors .spatial et .scaleDenominator doivent l'être"]];
    }
    
    // si spatial contient un tiret comme dans le GAN, le remplacer par un tiret simple
    if (isset($doc['spatial'])) {
      $doc['spatial'] = str_replace('—','-', $doc['spatial']);
    }
    if (isset($doc['insetMaps']) && is_array($doc['insetMaps'])) {
      foreach ($doc['insetMaps'] as $i => $insetMap) {
        if (isset($insetMap['spatial']))
          $doc['insetMaps'][$i]['spatial'] = str_replace('—','-', $insetMap['spatial']);
      }
    }
    
    // calcul de MapsFrance en fonction de spatial
    if (!isset($doc['mapsFrance'])) {
      if (isset($doc['spatial'])) { // Si spatial est défini
        $spatialSchema = new \jsonschema\Schema(self::getDefSchema('spatial'));
        if (!$spatialSchema->check($doc['spatial'])->errors()) { // s'il est conforme à son schéma
          $mapSpatial = new Spatial($doc['spatial']);
          $doc['mapsFrance'] = \shomft\Zee::inters($mapSpatial);
        }
      }
      elseif (isset($doc['insetMaps']) && is_array($doc['insetMaps'])) { // sinon, j'essaie de déduire des cartouches
        $mapSpatial = new \gegeom\GBox;
        foreach ($doc['insetMaps'] as $insetMap) {
          $insetMapSchema = new \jsonschema\Schema(self::getDefSchema('insetMap'));
          if (!$insetMapSchema->check($insetMap)->errors()) {
            $mapSpatial = $mapSpatial->union(new Spatial($insetMap['spatial']));
          }
        }
        $doc['mapsFrance'] = \shomft\Zee::inters($mapSpatial);
      }
    }
    
    // si le scaleDenominator est flottant, cela signifie que c'est un dénominateur entre 1.000.000 et 999
    if (isset($doc['scaleDenominator']) && is_float($doc['scaleDenominator'])) {
      $doc['scaleDenominator'] = sprintf('%.3f', $doc['scaleDenominator']);
    }
    foreach ($doc['insetMaps'] ?? [] as $i => $insetMap) { // idem dans les cartouches
      if (isset($insetMap['scaleDenominator']) && is_float($insetMap['scaleDenominator'])) {
        $doc['insetMaps'][$i]['scaleDenominator'] = sprintf('%.3f', $insetMap['scaleDenominator']);
      }
    }
    
    // vérification du schema de map
    $mapSchema = new \jsonschema\Schema(self::getDefSchema('map'));
    $status = $mapSchema->check($doc);
    if ($status->errors())
      return [
        'errors'=> $status->errors(),
        'warnings'=> $status->warnings(),
      ];
    else
      return [
        'warnings'=> $status->warnings(),
        'validDoc'=> $doc,
      ];
  }
  
  static function testValidatesAgainstSchema(): void {
    define('JEUX_TESTS', [
      "Cas ok sans cartouche, ni mapsFrance" => [
        'yaml' => <<<EOT
title: "De Port-Barcarès à l'embouchure de l'Aude"
scaleDenominator: '50.200'
spatial:
  SW: "42°43,64'N - 002°56,73'E"
  NE: "43°13,44'N - 003°24,43'E"
EOT
      ],
      "Cas ok avec cartouches, sans pp, ni mapsFrance" => [
        'yaml' => <<<EOT
title: 'Port Phaeton (Teauaa) - Tapuaeraha'
insetMaps:
  - title: 'A - Port Phaeton (Teauaa)'
    scaleDenominator: '10.000'
    spatial: { SW: '17°46,45''S - 149°20,54''W', NE: '17°43,66''S - 149°18,45''W' }
  - title: 'B - Tapuaeraha'
    scaleDenominator: '10.000'
    spatial: { SW: '17°49,06''S - 149°19,56''W', NE: '17°46,28''S - 149°17,47''W' }
EOT
      ],
      "Cas ok sans cartouche, ni mapsFrance, avec scaleDenominator flottant" => [
        'yaml' => <<<EOT
title: "De Port-Barcarès à l'embouchure de l'Aude"
scaleDenominator: 50.200
spatial:
  SW: "42°43,64'N - 002°56,73'E"
  NE: "43°13,44'N - 003°24,43'E"
EOT
      ],
      "Cas ok sans cartouche, ni mapsFrance, avec scaleDenominator >= 1M" => [
        'yaml' => <<<EOT
title: 'Des îles Baléares à la Corse et à la Sardaigne'
scaleDenominator: 1.000.000
spatial:
  SW: '35°30,00''N - 002°00,00''E'
  NE: '45°23,00''N - 010°12,00''E'
EOT
      ],
      "Cas KO sans cartouche, ni spatial, ni mapsFrance" => [
        'yaml' => <<<EOT
title: "De Port-Barcarès à l'embouchure de l'Aude"
scaleDenominator: '50.200'
EOT
      ],
      "Cas yaml KO" => [
        'yaml' => <<<EOT
title 'Port Phaeton (Teauaa) - Tapuaeraha'
insetMaps:
  - title: 'A - Port Phaeton (Teauaa)'
    scaleDenominator: '10.000'
    spatial: { SW: '17°46,45''S - 149°20,54''W', NE: '17°43,66''S - 149°18,45''W' }
  - title: 'B - Tapuaeraha'
    scaleDenominator: '10.000'
    spatial: { SW: '17°49,06''S - 149°19,56''W', NE: '17°46,28''S - 149°17,47''W' }
EOT
      ],
    ]);
    foreach (JEUX_TESTS as $title => $jeu) {
      $valid = self::validatesAgainstSchema($jeu['yaml']);
      if (isset($valid['errors']))
        echo "<pre>",\bo\YamlDump([$title => ['jeu' => $jeu, 'validatesAgainstSchema'=> $valid]], 6, 2),"</pre>\n";
      else
        echo "<pre>",\bo\YamlDump([$title => ['validatesAgainstSchema'=> $valid]], 6, 2),"</pre>\n";
    }
  }

  /** @var TMapCatItem $item */
  public array $item; // contenu de l'entrée du catalogue correspondant à une carte
  /** @var TMapCatKind $kind */
  public string $kind; // type de carte ('alive' | 'uninteresting' | 'deleted')
  
  /** 
   * @param TMapCatItem $item
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

  /** insert en base l'enregistrement Mapcat correspondant à la carte $mapNum
   * utilisée par updateMapCat()
   * @param TMapCatItem $doc
   */
  private static function insertInMySql(string $mapNum, array $doc, string $user): bool {
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    $jdocRes = \MySql::$mysqli->real_escape_string(json_encode($doc));
    $query = "insert into mapcat(mapnum, jdoc, updatedt, user) "
                        ."values('FR$mapNum', '$jdocRes', now(), '$user')";
    echo "<pre>query=$query</pre>\n";
    \MySql::query($query);
    echo "maj carte $mapNum ok<br>\n";
    return true;
  }
  
  // appelée pour mettre à jour la description de la carte $mapNum cad en créer un nouvel enregistrement
  // la première fois arrête le script en générant le formulaire qui rappelle le même script avec même URL et données en POST
  // les fois suivantes
  //   SI ajout ok alors retour de la méthode
  //   SINON message d'erreur, génération à nouveau du formulaire avant arrêt du script
  // Avec l'affichage du formulaire un lien d'abandon est affiché.
  static function updateMapCat(string $mapNum, string $user, string $abort): bool {
    if (!isset($_POST['yaml'])) { // Premier affichage du formulaire quand l'enregistrement existe
      $mapcat = \MySql::getTuples("select mapnum, jdoc from mapcat where mapnum='FR$mapNum' order by id desc")[0];
      if (!$mapcat) {
        echo "Erreur FR$mapNum n'existe pas dans la table mapcat<br>\n";
        return false;
      }
      $yaml = \bo\YamlDump(json_decode($mapcat['jdoc'], true), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
    else { // Retour d'une saisie d'une description soit depuis updateMapCat() soit depuis insertMapCat()
      $validationResult = self::validatesAgainstSchema($_POST['yaml']);
      if (isset($validationResult['validDoc'])) { // description conforme, l'enregistrement est créé en base
        return self::insertInMySql($mapNum, $validationResult['validDoc'], $user);
      }
      else { // description non conforme
        echo "<b>Erreur, la description fournie n'est pas conforme au schéma JSON:</b>\n";
        echo '<pre>',Yaml::dump($validationResult),"</pre>"; // affichage des erreurs
        $yaml = $_POST['yaml']; // $yaml contient la description non valide
      }
    }
    
    echo "<b>Mise à jour de la description dans le catalogue MapCat de la carte $mapNum]:</b></p>\n";
    echo new \html\Form(
      fields: ['yaml' => new \html\TextArea(text: $yaml, rows: 18, cols: 120)],
      submit: 'maj', hiddens: ['mapNum'=> $mapNum], method: 'post');
    echo "</p><a href='https://gan.shom.fr/diffusion/qr/gan/$mapNum' target='_blank'>",
          "Affichage du GAN de cette carte</a><br>";
    echo "<a href='?action=showMapCatScheme' target='_blank'>",
          "Affichage du schéma JSON à respecter pour cette description</a><br>";
    echo "$abort";
    die();
  }

  // appelée pour insérer la description de la carte $mapNum
  // Si le formulaire n'a pas été saisi alors le génère en rappellant le même script avec même URL et données en POST
  // puis arrête le script
  // Si le formulaire a été saisi appelle updateMapCat
  static function insertMapCat(?string $mapNum, string $user, string $abort): bool {
    if (isset($_POST['yaml'])) { // Retour d'une saisie d'une description
      if (isset($_POST['mapNum']))
        $mapNum = $_POST['mapNum'];
      return self::updateMapCat($mapNum, $user, $abort);
    }
    echo "<b>Ajout de la description dans le catalogue MapCat de la carte $mapNum selon le modèle ci-dessous:</b></p>\n";
    echo new \html\Form( // formulaire avec un champ mapNum s'il n'est pas défini en entrée 
      fields: array_merge(
        $mapNum ? [] : ['mapNum'=> new \html\Input(label: 'mapNum')],
        ['yaml' => new \html\TextArea (text: self::DOC_MODEL_IN_YAML, cols: 120, rows: 18)]
      ),
      submit: 'ajout',
      hiddens: array_merge(['action'=> 'insertMapCat'], $mapNum ? ['mapNum'=> $mapNum] : []),
      action: '',
      method: 'post'
    );
      
    echo "</p><a href='https://gan.shom.fr/diffusion/qr/gan/$mapNum' target='_blank'>",
          "Affichage du GAN de cette carte</a><br>";
    echo "<a href='?action=showMapCatScheme' target='_blank'>",
          "Affichage du schéma JSON à respecter pour cette description</a><br>";
    echo "$abort";
    die();
  }
};
if ($error = MapCatItem::checkValidity()) throw new \Exception($error);

// La classe MapCat correspond au catalogue MapCat en base 
// La classe porte en outre en constante la définition SQL de la table mapcat
// ainsi qu'une méthode statique traduisant la définition SQL en requête SQL
class MapCat {
  // la structuration de la constante est définie dans son champ description
  const MAPCAT_TABLE_SCHEMA = [
    'description' => "Ce dictionnaire définit le schéma d'une table SQL avec:\n"
            ." - le champ 'comment' précisant la table concernée,\n"
            ." - le champ obligatoire 'columns' définissant le dictionnaire des colonnes avec pour chaque entrée:\n"
            ."   - la clé définissant le nom SQL de la colonne,\n"
            ."   - le champ 'type' obligatoire définissant le type SQL de la colonne,\n"
            ."   - le champ 'keyOrNull' définissant si la colonne est ou non une clé et si elle peut ou non être nulle\n"
            ."   - le champ 'comment' précisant un commentaire sur la colonne.\n"
            ."   - pour les colonnes de type 'enum' correspondant à une énumération le champ 'enum'\n"
            ."     définit les valeurs possibles dans un dictionnaire où chaque entrée a:\n"
            ."     - pour clé la valeur de l'énumération et\n"
            ."     - pour valeur une définition et/ou un commentaire sur cette valeur.",
    'comment' => "table du catalogue des cartes avec 1 n-uplet par carte et par mise à jour",
    'columns'=> [
      'id'=> [
        'type'=> 'int',
        'keyOrNull'=> 'not null auto_increment primary key',
        'comment'=> "id du n-uplet incrémenté pour permettre des versions sucessives par carte",
      ],
      'mapnum'=> [
        'type'=> 'char(6)',
        'keyOrNull'=> 'not null',
        'comment'=> "numéro de carte sur 4 chiffres précédé de 'FR'",
      ],
      'jdoc'=> [
        'type'=> 'JSON',
        'keyOrNull'=> 'not null',
        'comment'=> "enregistrement conforme au schéma JSON",
      ],
      /*'bbox'=> [
        'type'=> 'POLYGON',
        'keyOrNull'=> 'not null',
        'comment'=> "boite engobante de la carte en WGS84",
      ], voir le besoin */
      'updatedt'=> [
        'type'=> 'datetime',
        'keyOrNull'=> 'not null',
        'comment'=> "date de création/mise à jour de l'enregistrement dans la table",
      ],
      'user'=> [
        'type'=> 'varchar(256)',
        'comment'=> "utilisateur ayant réalisé la mise à jour, null pour une versions système",
      ],
    ],
  ]; // Définition du schéma SQL de la table mapcat

  /** fabrique le code SQL de création de la table à partir d'une des constantes de définition du schéma
   * @param array<string, mixed> $schema */
  static function createTableSql(string $tableName, array $schema): string {
    $cols = [];
    foreach ($schema['columns'] ?? [] as $cname => $col) {
      $cols[] = "  $cname "
        .match($col['type'] ?? null) {
          'enum' => "enum('".implode("','", array_keys($col['enum']))."') ",
          default => "$col[type] ",
          null => die("<b>Erreur, la colonne '$cname' doit comporter un champ 'type'</b>."),
      }
      .($col['keyOrNull'] ?? '')
      .(isset($col['comment']) ? " comment \"$col[comment]\"" : '');
    }
    return ("create table $tableName (\n"
      .implode(",\n", $cols)."\n)"
      .(isset($schema['comment']) ? " comment \"$schema[comment]\"\n" : ''));
  }
  
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

  /** retourne tout le contenu de MapCat chaque entrée ssous la forme d'array et les num. avec FR
   * @return array<string, TMapCatItem>
   */
  static function allAsArray(): array {
    $all = [];
    // étant donné le tri $all ne contient que la dernière version pour chaque carte 
    foreach (\MySql::query('select mapnum, jdoc from mapcat order by id') as $tuple) {
      $all[$tuple['mapnum']] = json_decode($tuple['jdoc']);
    }
    ksort($all);
    return $all;
  }
};

class MapCatFromFile extends MapCat {
  /** @var array<string, TMapCatItem> $maps */
  static array $maps=[]; // contenu du champ maps de MapCat
  /** @var array<string, TMapCatItem> $uninterestingMaps */
  static array $uninterestingMaps=[]; // contenu du champ uninterestingMaps de MapCat
  /** @var array<string, TMapCatItem> $deletedMaps */
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
