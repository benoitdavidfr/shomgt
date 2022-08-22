<?php
{/*PhpDoc:
name:  gegeom.inc.php
title: gegeom.inc.php - package géométrique utilisant des coordonnées géographiques ou euclidiennes
functions:
classes:
doc: |
  Ce fichier définit la classe abstraite Geometry, des sous-classes par type de géométrie GeoJSON
  (https://tools.ietf.org/html/rfc7946) ainsi qu'une classe Segment utilisé pour certains calculs.
  Une géométrie GeoJSON peut être facilement créée en décodant le JSON en Php par json_decode()
  puis en apppelant la méthode Geometry::fromGeoJSON().
journal: |
  22/8/2022:
    - correction bug
  28/7/2022:
    - correction suite à analyse PhpStan
    - suppression du style associé à une géométrie
    - GeomtryCollection n'est plus une sous-classe de Geometry
    - transfert de qqs méthodes dans Po, LPos et LLPos
  8/7/2022:
    - ajout Segment::(projPosOnLine+distancePosToLine+distanceToPos) + LineString::distanceToPos
  7-10/2/2022:
    - ajout de code aux exceptions
    - décomposition du test unitaire de la classe Geometry dans les tests des sous-classes
    - transformation des Exception en SExcept et fourniture d'un code de type string
  9/3/2019:
    - ajout de nombreuses fonctionnalités
  7/3/2019:
  -   création
includes: [coordsys.inc.php, zoom.inc.php, gebox.inc.php, sexcept.inc.php]
forks: [ /geovect/gegeom ]
*/}
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/coordsys.inc.php';
require_once __DIR__.'/zoom.inc.php';
require_once __DIR__.'/gebox.inc.php';
require_once __DIR__.'/sexcept.inc.php';

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
  echo "<html><head><meta charset='UTF-8'><title>gegeom</title></head><body><pre>";
}

// Prend une valeur et la transforme récursivement en aray Php pur sans objet, utile pour l'afficher avec json_encode
// Les objets rencontrés doivent avoir une méthode asArray() qui décompose l'objet en array des propriétés exposées
function asArray(mixed $val): mixed {
  //echo "AsArray(",json_encode($val),")<br>\n";
  if (is_array($val)) {
    foreach ($val as $k => $v) {
      $val[$k] = asArray($v);
    }
    return $val;
  }
  elseif (is_object($val)) {
    return asArray($val->asArray());
  }
  else
    return $val;
}

// génère un json en traversant les objets qui savent se décomposer en array par asArray()
function my_json_encode(mixed $val): string {
  return json_encode(asArray($val), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}


{/*PhpDoc: classes
name: Geometry
title: abstract class Geometry - Gestion d'une Geometry GeoJSON (hors collection) et de quelques opérations
doc: |
  Les coordonnées sont conservées en array comme en GeoJSON et pas structurées avec des objets.
  Chaque type de géométrie correspond à une sous-classe concrète.
  Par défaut, la géométrie est en coordonnées géographiques mais les classes peuvent aussi être utilisées
  avec des coordonnées euclidiennes en utilisant des méthodes soécifiques préfixées par e.
*/}
abstract class Geometry {
  const ErrorFromGeoArray = 'Geometry::ErrorFromGeoArray';
  const HOMOGENEOUSTYPES = ['Point','LineString','Polygon','MultiPoint','MultiLineString','MultiPolygon'];
  
  static int $precision = 6; // nbre de chiffres après la virgule à conserver pour les coord. géo.
  static int $ePrecision = 1; // nbre de chiffres après la virgule à conserver pour les coord. euclidiennes
  
  /** @var TPos|TLPos|TLLPos|TLLLPos $coords */
  public readonly array $coords; // coordonnées ou Positions, stockées comme array, array(array), ... en fn de la sous-classe
  
  // crée une géométrie à partir du json_decode() d'une géométrie GeoJSON
  /** @param array<string, string|TPos|TLPos|TLLPos|TLLLPos> $geom */
  static function fromGeoArray(array $geom): Geometry|GeometryCollection {
    $type = $geom['type'] ?? null;
    if (in_array($type, self::HOMOGENEOUSTYPES) && isset($geom['coordinates']))
      return new $type($geom['coordinates']);
    elseif (($type=='GeometryCollection') && isset($geom['geometries'])) {
      $geoms = [];
      foreach ($geom['geometries'] as $g)
        $geoms[] = self::fromGeoArray($g);
      return new GeometryCollection($geoms);
    }
    else
      throw new SExcept("Erreur de Geometry::fromGeoArray(".json_encode($geom).")", self::ErrorFromGeoArray);
  }
  
  // fonction d'initialisation valable pour toutes les géométries homogènes
  /** @param TPos|TLPos|TLLPos|TLLLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  
  // récupère le type
  function type(): string { return get_class($this); }
  
  // retourne la liste des types élémentaires ('Point','LineString','Polygon') contenus dans la géométrie
  /** @return array<int, string> */
  abstract function eltTypes(): array;
  
  // génère la réprésentation string GeoJSON
  function __toString(): string { return json_encode($this->asArray()); }
  
  // génère la représentation Php du GeoJSON
  /** @return array<string, string|TPos|TLPos|TLLPos|TLLLPos> */
  function asArray(): array { return ['type'=> get_class($this), 'coordinates'=> $this->coords]; }
  
  // Retourne la liste des primitives contenues dans l'objet sous la forme d'objets
  // Point -> [], MutiPoint->[Point], LineString->[Point], MultiLineString->[LineString], Polygon->[LineString],
  // MutiPolygon->[Polygon]
  /** @return array<int, object> */
  abstract function geoms(): array;
  
  // renvoie le centre d'une géométrie
  /** @return TPos */
  abstract function center(): array;
  
  abstract function nbreOfPos(): int;
  
  // retourne un point de la géométrie
  /** @return TPos */
  abstract function aPos(): array;
  
  // renvoie la GBox de la géométrie considérée comme géographique
  abstract function gbox(): GBox;
  
  // renvoie la EBox de la géométrie considérée comme euclidienne
  abstract function ebox(): EBox;
  
  // distance min. d'une géométrie à une position
  /** @param TPos $pos */
  abstract function distanceToPos(array $pos): float;
  
  // reprojète ue géométrie, prend en paramètre une fonction de reprojection d'une position, retourne un objet géométrie
  abstract function reproject(callable $reprojPos): Geometry;
  
  // Décompose une géométrie en un array de géométries élémentaires (Point/LineString/Polygon)
  /** @return array<int, object> */
  function decompose(): array {
    $transfos = ['MultiPoint'=>'Point', 'MultiLineString'=>'LineString', 'MultiPolygon'=>'Polygon'];
    if (isset($transfos[$this->type()])) {
      $elts = [];
      foreach ($this->coords as $eltcoords)
        $elts[] = new $transfos[$this->type()]($eltcoords);
      return $elts;
    }
    else // $this est un élément
      return [$this];
  }
  
  /* agrège un ensemble de géométries élémentaires en une unique Geometry
  static function aggregate(array $elts): Geometry {
    $bbox = new GBox;
    foreach ($elts as $elt)
      $bbox->union($elt->bbox());
    return new Polygon($bbox->polygon()); // temporaireemnt représente chaque agrégat par son GBox
    $elts = array_merge([new Polygon($bbox->polygon())], $elts);
    if (count($elts) == 1)
      return $elts[0];
    $agg = [];
    foreach ($elts as $elt)
      $agg[$elt->type()][] = $elt;
    if (isset($agg['Point']) && !isset($agg['LineString']) && !isset($agg['Polygon']))
      return MultiPoint::haggregate($agg['Point']);
    elseif (!isset($agg['Point']) && isset($agg['LineString']) && !isset($agg['Polygon']))
      return MultiLineString::haggregate($agg['LineString']);
    elseif (!isset($agg['Point']) && !isset($agg['LineString']) && isset($agg['Polygon']))
      return MultiPolygon::haggregate($agg['Polygon']);
    else 
      return new GeometryCollection(array_merge(
        MultiPoint::haggregate($agg['Point']),
        MultiLineString::haggregate($agg['LineString']),
        MultiPolygon::haggregate($agg['Polygon'])
      ));
  }
  */
}

{/*PhpDoc: classes
name: Point
methods:
title: class Point extends Geometry - Un Point correspond à une position, il peut aussi être considéré comme un vecteur
*/}
class Point extends Geometry {
  const ErrorBadParamInAdd = 'Point::ErrorBadParamInAdd';
  const ErrorBadParamInDiff = 'Point::ErrorBadParamInDiff';
  
  /** @var TPos $coords */
  public readonly array $coords; // contient une Pos
  
  /** @param TPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  function eltTypes(): array { return ['Point']; }
  function geoms(): array { return []; }
  function center(): array { return $this->coords; }
  function nbreOfPos(): int { return 1; }
  function aPos(): array { return $this->coords; }
  function gbox(): GBox { return new GBox($this->coords); }
  function ebox(): EBox { return new EBox($this->coords); }
  function distanceToPos(array $pos): float { return Pos::distance($this->coords, $pos); }
  function reproject(callable $reprojPos): Geometry { return new Point($reprojPos($this->coords)); }

  /** @param Point|TPos $v */
  function add(Point|array $v): Point {
    /*PhpDoc: methods
    name:  add
    title: "function add(Point|array $v): Point - $this + $v en 2D, $v peut être un Point ou une position"
    */
    if (Pos::is($v))
      return new Point([$this->coords[0] + $v[0], $this->coords[1] + $v[1]]);
    elseif (is_object($v) && (get_class($v) == 'Point'))
      return new Point([$this->coords[0] + $v->coords[0], $this->coords[1] + $v->coords[1]]);
    else
      throw new SExcept("Erreur dans Point:add(), paramètre ni position ni Point", self::ErrorBadParamInAdd);
  }
  
  /** @param Point|TPos $v */
  function diff(Point|array $v): Point {
    /*PhpDoc: methods
    name:  diff
    title: "function diff(Point|array $v): Point - $this - $v en 2D"
    */
    if (Pos::is($v))
      return new Point([$this->coords[0] - $v[0], $this->coords[1] - $v[1]]);
    elseif (get_class($v) == 'Point')
      return new Point([$this->coords[0] - $v->coords[0], $this->coords[1] - $v->coords[1]]);
    else
      throw new SExcept("Erreur dans Point:diff(), paramètre ni position ni Point", self::ErrorBadParamInDiff);
  }
    
  function vectorProduct(Point $v): float {
    /*PhpDoc: methods
    name:  vectorProduct
    title: "function vectorProduct(Point $v): float - produit vectoriel $this par $v en 2D"
    */
    return $this->coords[0] * $v->coords[1] - $this->coords[1] * $v->coords[0];
  }
  
  function scalarProduct(Point $v): float {
    /*PhpDoc: methods
    name:  scalarProduct
    title: "function scalarProduct(Point $v): float - produit scalaire $this par $v en 2D"
    */
    return $this->coords[0] * $v->coords[0] + $this->coords[1] * $v->coords[1];
  }
  /*static function A_ADAPTER_test_pscal() {
    foreach ([
      ['POINT(15 20)','POINT(20 15)'],
      ['POINT(1 0)','POINT(0 1)'],
      ['POINT(1 0)','POINT(0 3)'],
      ['POINT(4 0)','POINT(0 3)'],
      ['POINT(1 0)','POINT(1 0)'],
    ] as $lpts) {
      $v0 = new Point($lpts[0]);
      $v1 = new Point($lpts[1]);
      echo "($v0)->pvect($v1)=",$v0->pvect($v1),"\n";
      echo "($v0)->pscal($v1)=",$v0->pscal($v1),"\n";
    }
  }*/

  // multiplication de $this considéré comme un vecteur par un scalaire
  function scalMult(float $scal): Point { return new Point([$this->coords[0] * $scal, $this->coords[1] * $scal]); }

  function norm(): float { return sqrt($this->scalarProduct($this)); }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Point
  if (!isset($_GET['test']))
    echo "<a href='?test=Point'>Test unitaire de la classe Point</a>\n";
  elseif ($_GET['test']=='Point') {
    $pt = new Point ([0,0]);
    echo "pt=$pt<br>\n";
    echo "reproject(pt)=",$pt->reproject(function(array $pos) { return $pos; }),"\n";
    echo $pt->add([1,1]),"\n";
    echo $pt->add(new Point([1,1])),"\n";
    try {
      echo $pt->add([[1,1]]),"\n"; // @phpstan-ignore-line
    }
    catch(SExcept $e) {
      echo "Exception $e\nscode=",$e->getSCode(),"\n";
    }
  }
}


{/*PhpDoc: classes
name: Segment
title: class Segment - Segment composé de 2 positions ; considéré comme orienté de la première vers la seconde
methods:
doc: |
  On considère le segment comme fermé sur sa première position et ouvert sur la seconde
  Cela signifie que la première position appartient au segment mais pas la seconde.
*/}
class Segment {
  /** @var TLPos */
  protected array $tab; // 2 positions: [[number]]
  
  /**
  * @param TPos $pos0
  * @param TPos $pos1
  */
  function __construct(array $pos0, array $pos1) { $this->tab = [$pos0, $pos1]; }
  
  function __toString(): string { return json_encode($this->tab); }
  
  // génère le vecteur correspondant à $tab[1] - $tab[0] représenté par un Point
  function vector(): Point {
    return new Point([$this->tab[1][0] - $this->tab[0][0], $this->tab[1][1] - $this->tab[0][1]]);
  }
  
  /*PhpDoc: methods
  name:  intersects
  title: "function intersects(Segment $seg): array - intersection entre 2 segments"
  doc: |
    Je considère les segments fermé sur le premier point et ouvert sur le second.
    Cela signifie qu'une intersection ne peut avoir lieu sur la seconde position
    Si les segments ne s'intersectent pas alors retourne []
    S'ils s'intersectent alors retourne le dictionnaire
      ['pos'=> l'intersection comme Point, 'u'=> l'abscisse sur le premier segment, 'v'=> l'abscisse sur le second]
    Si les 2 segments sont parallèles, alors retourne [] même s'ils sont partiellement confondus
  */
  /** @return array<string, mixed> */
  function intersects(Segment $seg): array {
    $a = $this->tab;
    $b = $seg->tab;
    if (max($a[0][0],$a[1][0]) < min($b[0][0],$b[1][0])) return [];
    if (max($b[0][0],$b[1][0]) < min($a[0][0],$a[1][0])) return [];
    if (max($a[0][1],$a[1][1]) < min($b[0][1],$b[1][1])) return [];
    if (max($b[0][1],$b[1][1]) < min($a[0][1],$a[1][1])) return [];
    
    $va = $this->vector();
    $vb = $seg->vector();
    $ab = new Segment($a[0], $b[0]);
    $ab = $ab->vector(); // vecteur b0 - a0
    $pvab = $va->vectorProduct($vb);
    if ($pvab == 0)
      return []; // droites parallèles, éventuellement confondues
    $u = $ab->vectorProduct($vb) / $pvab;
    $v = $ab->vectorProduct($va) / $pvab;
    if (($u >= 0) && ($u < 1) && ($v >= 0) && ($v < 1))
      return [ 'pos'=> $va->scalMult($u)->add($a[0]),
               'posb'=> $vb->scalMult($v)->add($b[0]),
               'u'=>$u, 'v'=>$v,
             ];
    else
      return [];
  }
  static function test_intersects(): void {
    $a = new Segment([0,0], [10,0]);
    foreach ([
      ['b'=> new Segment([0,-5],[10,5]), 'result'=> 'true'],
      //['POINT(0 0)','POINT(10 0)','POINT(0 0)','POINT(10 5)'],
      //['POINT(0 0)','POINT(10 0)','POINT(0 -5)','POINT(10 -5)'],
      //['POINT(0 0)','POINT(10 0)','POINT(0 -5)','POINT(20 0)'],
    ] as $test) {
      $b = $test['b'];
      echo "$a ->intersects($b) -> ",my_json_encode($a->intersects($b)), " / $test[result]<br>\n";
    }
  }

  // projection d'une position sur la ligne définie par le segment
  // retourne u / P' = A + u * (B-A).
  /** @param TPos $pos */
  function projPosOnLine(array $pos): float {
    $ab = $this->vector();
    $ap = new Point([$pos[0]-$this->tab[0][0], $pos[1]-$this->tab[0][1]]);
    return $ab->scalarProduct($ap) / $ab->scalarProduct($ab);
  }
  static function test_projPosOnLine(): void {
    $seg = new self([0,0], [1,0]);
    foreach ([[0,0], [1,0], [2, 0], [0.5, 5]] as $pos)
      echo "projPosOnLine($seg, [$pos[0],$pos[1]])-> ",$seg->projPosOnLine($pos),"\n";
    $seg = new self([0,0], [2,1]);
    foreach ([[0,0], [1,0], [2, 0], [0.5, 5]] as $pos)
      echo "projPosOnLine($seg, [$pos[0],$pos[1]])-> ",$seg->projPosOnLine($pos),"\n";
  }
  
  /** @param TPos $pos */
  function distancePosToLine(array $pos): float {
    {/*PhpDoc: methods
    name:  distancePosToLine
    title: "distancePosToLine(array $pos): float - distance signée de $pos à la droite définie par le segment"
    doc: |
      distance signée de la position $pos à la droite définie par les 2 positions $a et $b"
      La distance est positive si le point est à gauche de la droite AB et négative s'il est à droite
      # Distance signee d'un point P a une droite orientee definie par 2 points A et B
      # la distance est positive si P est a gauche de la droite AB et negative si le point est a droite
      # Les parametres sont les 3 points P, A, B
      # La fonction retourne cette distance.
      # --------------------
      sub DistancePointDroite
      # --------------------
      { my @ab = (@_[4] - @_[2], @_[5] - @_[3]); # vecteur B-A
        my @ap = (@_[0] - @_[2], @_[1] - @_[3]); # vecteur P-A
        return pvect (@ab, @ap) / Norme(@ab);
      }
    */}
    $ab = $this->vector();
    $ap = new Point([$pos[0]-$this->tab[0][0], $pos[1]-$this->tab[0][1]]);
    if ($ab->norm() == 0)
      throw new Exception("Erreur dans distancePosToLine : Points A et B confondus et donc droite non définie");
    return $ab->vectorProduct($ap) / $ab->norm();
  }
  static function test_distancePosToLine(): void {
    $seg = new self([0,0], [1,0]);
    foreach ([[0,0], [1,0], [2, 0], [0.5, 5], [0.5, -5]] as $pos)
      echo "distancePosToLine($seg, [$pos[0],$pos[1]])-> ",$seg->distancePosToLine($pos),"\n";
  }
  
  // distance d'une position au segment
  /** @param TPos $pos */
  function distanceToPos(array $pos): float {
    $u = $this->projPosOnLine($pos);
    if ($u < 0)
      return Pos::distance($pos, $this->tab[0]);
    elseif ($u > 1)
      return Pos::distance($pos, $this->tab[1]);
    else
      return abs($this->distancePosToLine($pos));
  }
  static function test_distanceToPos(): void {
    $seg = new self([0,0], [1,0]);
    foreach ([[0,0], [1,0], [2, 0], [0.5, 5], [0.5, -5], [5,4]] as $pos)
      echo "distanceToPos($seg, [$pos[0],$pos[1]])-> ",$seg->distanceToPos($pos),"\n";
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Segment
  if (!isset($_GET['test']))
    echo "<a href='?test=Segment'>Test unitaire de la classe Segment</a><br>\n";
  elseif ($_GET['test']=='Segment') {
    Segment::test_intersects();
    Segment::test_projPosOnLine();
    echo "\n";
    Segment::test_distancePosToLine();
    echo "\n";
    Segment::test_distanceToPos();
  }
}

{/*PhpDoc: classes
name: Point
title: class MultiPoint extends Geometry - Une liste de points, peut-être vide
*/}
class MultiPoint extends Geometry {
  const ErrorEmpty = 'MultiPoint::ErrorEmpty';
  
  /** @var TLPos $coords */
  public readonly array $coords; // contient une liste de Pos
  
  /** @param TLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  function eltTypes(): array { return $this->coords ? ['Point'] : []; }
  
  function geoms(): array {
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new Point($coord);
    return $geoms;
  }
  
  function center(): array { return LPos::center($this->coords); }
  function nbreOfPos(): int { return count($this->coords); }
  function aPos(): array {
    if (!$this->coords)
      throw new SExcept("Erreur: MultiPoint::aPos() sur une liste de positions vide", self::ErrorEmpty);
    return $this->coords[0];
  }
  function gbox(): GBox { return new GBox($this->coords); }
  function ebox(): EBox { return new EBox($this->coords); }
  
  function distanceToPos(array $pos): float {
    $dmin = INF;
    foreach ($this->coords as $p) {
      $d = Pos::distance($p, $pos);
      if ($d < $dmin)
        $dmin = $d;
    }
    return $dmin;
  }
  
  function reproject(callable $reprojPos): self { return new self(LPos::reproj($reprojPos, $this->coords)); }
  /*static function haggregate(array $elts) - NON UTILISE {
    $coords = [];
    foreach ($elts as $elt)
      $coords[] = $elt->coords;
    return new MultiPoint($coords);
  }*/
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe MultiPoint
  if (!isset($_GET['test']))
    echo "<a href='?test=MultiPoint'>Test unitaire de la classe MultiPoint</a><br>\n";
  elseif ($_GET['test']=='MultiPoint') {
    $mpt = new MultiPoint([]);
    $mpt = new MultiPoint([[0,0],[1,1]]);
    echo "$mpt ->center() = ",json_encode($mpt->center()),"<br>\n";
    echo "$mpt ->aPos() = ",json_encode($mpt->aPos()),"<br>\n";
    echo "$mpt ->gbox() = ",$mpt->gbox(),"<br>\n";
    echo "$mpt ->reproject() = ",$mpt->reproject(function(array $pos) { return $pos; }),"<br>\n";
  }
}


{/*PhpDoc: classes
name: LineString
title: class LineString extends Geometry - contient au moins 2 positions
*/}
class LineString extends Geometry {
  /** @var TLPos $coords */
  public readonly array $coords; // contient une liste de listes de 2 ou 3 nombres
  
  /** @param TLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  function eltTypes(): array { return ['LineString']; }
  
  function geoms(): array {
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new Point($coord);
    return $geoms;
  }
  
  function center(): array { return LPos::center($this->coords); }
  function nbreOfPos(): int { return count($this->coords); }
  function aPos(): array { return $this->coords[0]; }
  function gbox(): GBox { return new GBox($this->coords); }
  function ebox(): EBox { return new EBox($this->coords); }
  
  function reproject(callable $reprojPos): LineString {
    return new self(LPos::reproj($reprojPos, $this->coords));
  }
  
  /*PhpDoc: methods
  name:  pointInPolygon
  title: "pointInPolygon(array $p): bool - teste si une position pos est dans le polygone formé par la ligne fermée"
  */
  /** @param TPos $p */
  function pointInPolygon(array $p): bool {
    {/*  Code de référence en C:
    int pnpoly(int npol, float *xp, float *yp, float x, float y)
    { int i, j, c = 0;
      for (i = 0, j = npol-1; i < npol; j = i++) {
        if ((((yp[i]<=y) && (y<yp[j])) ||
             ((yp[j]<=y) && (y<yp[i]))) &&
            ((x - xp[i]) < (xp[j] - xp[i]) * (y - yp[i]) / (yp[j] - yp[i])))
          c = !c;
      }
      return c;
    }*/}
    $c = false;
    $cs = $this->coords;
    $j = count($cs) - 1;
    for($i=0; $i<count($cs); $i++) {
      if (((($cs[$i][1] <= $p[1]) && ($p[1] < $cs[$j][1])) || (($cs[$j][1] <= $p[1]) && ($p[1] < $cs[$i][1])))
        && (($p[0] - $cs[$i][0]) < ($cs[$j][0] - $cs[$i][0]) * ($p[1] - $cs[$i][1]) / ($cs[$j][1] - $cs[$i][1]))) {
        $c = !$c;
      }
      $j = $i;
    }
    return $c;
  }
  static function test_pointInPolygon(): void {
    $p0 = [0, 0];
    foreach ([ // liste de polyligne non fermées
      ['coords'=> [[1, 0],[0, 1],[-1, 0],[0, -1]], 'result'=> true],
      ['coords'=> [[1, 1],[-1, 1],[-1, -1],[1, -1]], 'result'=> true],
      ['coords'=> [[1, 1],[-1, 1],[-1, -1],[1, -1],[1, 1]], 'result'=> true],
      ['coords'=> [[1, 1],[2, 1],[2, 2],[1, 2]], 'result'=> false],
    ] as $test) {
      $coords = $test['coords'];
      $coords[] = $coords[0]; // fermeture de la polyligne
      $ls = new LineString($coords);
      echo "${ls}->pointInPolygon([$p0[0],$p0[1]])=",($ls->pointInPolygon($p0)?'true':'false'),
           " / ",($test['result']?'true':'false'),"<br>\n";
    }
  }

  /*PhpDoc: methods
  name:  segs
  title: "segs(): array - liste des segments constituant la polyligne"
  */
  /** @return array<int, object> */
  function segs(): array {
    $segs = [];
    $posPrec = null;
    foreach ($this->coords as $pos) {
      if (!$posPrec)
        $posPrec = $pos;
      else {
        $segs[] = new Segment($posPrec, $pos);
        $posPrec = $pos;
      }
    }
    return $segs;
  }
  
  /*PhpDoc: methods
  name:  distanceToPos
  title: "distanceToPos(array $pos): float - calcule la distance d'une LineString à une position"
  */
  /** @param TPos $pos */
  function distanceToPos(array $pos): float {
    $dmin = INF;
    foreach ($this->segs() as $seg) {
      $d = $seg->distanceToPos($pos);
      if (($dmin == INF) || ($d < $dmin))
        $dmin = $d;
    }
    return $dmin;
  }
  static function test_distanceToPos(): void {
    $ls = new LineString([[0,0],[1,0],[2,2]]);
    foreach ([[0,0],[2,2],[1,1],[1,-1],[-1,-1]] as $pos)
      echo $ls,"->distanceToPos([$pos[0],$pos[1]])-> ",$ls->distanceToPos($pos),"\n";
  }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe LineString
  if (!isset($_GET['test']))
    echo "<a href='?test=LineString'>Test unitaire de la classe LineString</a><br>\n";
  elseif ($_GET['test']=='LineString') {
    $ls = new LineString([[0,0],[1,1]]);
    echo "ls=$ls<br>\n";
    echo "ls->center()=",json_encode($ls->center()),"<br>\n";
    LineString::test_pointInPolygon();
    LineString::test_distanceToPos();
  }
}


{/*PhpDoc: classes
name: MultiLineString
title: class MultiLineString extends Geometry - contient une liste de liste de positions, chaque liste de positions en contient au moins 2
*/}
class MultiLineString extends Geometry {
  const ErrorCenterOfEmpty = 'MultiLineString::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'MultiLineString::ErrorPosOfEmpty';
  
  /** @var TLLPos $coords */
  public readonly array $coords; // contient une LLPos
  
  /** @param TLLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  function eltTypes(): array { return $this->coords ? ['LineString'] : []; }
  
  function geoms(): array {
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new LineString($coord);
    return $geoms;
  }
  
  function center(): array {
    if (!$this->coords)
      throw new SExcept("Erreur: MultiLineString::center() sur une liste vide", self::ErrorCenterOfEmpty);
    $c = [0, 0];
    $nbre = 0;
    foreach ($this->coords as $lpos) {
      foreach ($lpos as $pos) {
        $c[0] += $pos[0];
        $c[1] += $pos[1];
        $nbre++;
      }
    }
    return [round($c[0]/$nbre, self::$precision), round($c[1]/$nbre, self::$precision)];
  }
  
  function nbreOfPos(): int { return LElts::LLcount($this->coords); }
  
  function aPos(): array {
    if (!$this->coords)
      throw new SExcept("Erreur: MultiLineString::aPos() sur une liste vide", self::ErrorPosOfEmpty);
    return $this->coords[0][0];
  }
  
  function gbox(): GBox { return new GBox($this->coords); }
  function ebox(): EBox { return new EBox($this->coords); }
  
  function distanceToPos(array $pos): float {
    $dmin = INF;
    foreach ($this->geoms() as $ls) {
      $d = $ls->distanceToPos($pos);
      if ($d < $dmin)
        $dmin = $d;
    }
    return $dmin;
  }
  
  function reproject(callable $reprojPos): Geometry {
    return new self(LLPos::reproj($reprojPos, $this->coords));
  }
  
  /*static function haggregate(array $elts) - NON UTILISE {
    $coords = [];
    foreach ($elts as $elt)
      $coords[] = $elt->coords;
    return new MultiLineString($coords);
  }*/
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Geometry
  if (!isset($_GET['test']))
    echo "<a href='?test=MultiLineString'>Test unitaire de la classe MultiLineString</a><br>\n";
  elseif ($_GET['test']=='MultiLineString') {
    $mls = new MultiLineString([[[0,0],[1,1]]]);
    echo "mls=$mls<br>\n";
    echo "mls->center()=",json_encode($mls->center()),"<br>\n";
  }
}


{/*PhpDoc: classes
name: Polygon
title: class Polygon extends Geometry - Polygon
doc: |
  Contient un extérieur qui contient au moins 4 points
  Chaque intérieur contient au moins 4 points et est contenu dans l'extérieur
  Les intérieurs ne s'intersectent pas 2 à 2
*/}
class Polygon extends Geometry {
  const ErrorInters = 'Polygon::ErrorInters';

  /** @var TLLPos $coords */
  public readonly array $coords; // contient une LLPos

  /** @param TLLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  function eltTypes(): array { return ['Polygon']; }
  
  function geoms(): array {
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new LineString($coord);
    return $geoms;
  }
  
  function center(): array { return LPos::center($this->coords[0]); }
  function nbreOfPos(): int { return LElts::LLcount($this->coords); }
  function aPos(): array { return $this->coords[0][0]; }
  function gbox(): GBox { return new GBox($this->coords); }
  function ebox(): EBox { return new EBox($this->coords); }
  
  function distanceToPos(array $pos): float {
    if ($this->pointInPolygon($pos))
      return 0;
    $dmin = INF;
    foreach ($this->geoms() as $g) {
      $d = $g->distanceToPos($pos);
      if ($d < $dmin)
        $dmin = $d;
    }
    return $dmin;
  }
  
  function reproject(callable $reprojPos): Geometry {
    return new self(LLPos::reproj($reprojPos, $this->coords));
  }
  
  function area_A_ADAPTER (): void {
    /*PhpDoc: methods
    name:  area
    title: "function area($options=[]): float - renvoie la surface dans le système de coordonnées courant"
    doc: |
      Par défaut, l'extérieur et les intérieurs tournent dans des sens différents.
      La surface est positive si l'extérieur tourne dans le sens trigonométrique, <0 sinon.
      Si l'option 'noDirection' vaut true alors les sens ne sont pas pris en compte
    */
    /*function area(array $options=[]): float {
      $noDirection = (isset($options['noDirection']) and ($options['noDirection']));
      foreach ($this->geom as $ring)
        if (!isset($area))
          $area = ($noDirection ? abs($ring->area()) : $ring->area());
        else
          $area += ($noDirection ? -abs($ring->area()) : $ring->area());
      return $area;
    }*/
    /*static function test_area() {
      foreach ([
        'POLYGON((0 0,1 0,0 1,0 0))' => "triangle unité",
        'POLYGON((0 0,1 0,1 1,0 1,0 0))'=>"carré unité",
        'POLYGON((0 0,10 0,10 10,0 10,0 0))'=>"carré 10",
        'POLYGON((0 0,10 0,10 10,0 10,0 0),(2 2,2 8,8 8,8 2,2 2))'=>"carré troué bien orienté",
        'POLYGON((0 0,0 10,10 10,10 0,0 0),(2 2,2 8,8 8,8 2,2 2))'=>"carré troué mal orienté",
      ] as $polstr=>$title) {
        echo "<h3>$title</h3>";
        $pol = new Polygon($polstr);
        echo "area($pol)=",$pol->area();
        echo ", noDirection->",$pol->area(['noDirection'=>true]),"\n";
      }
    }*/
  }
  
  /*PhpDoc: methods
  name:  pointInPolygon
  title: "pointInPolygon(array $pos): bool - teste si une position est dans le polygone"
  */
  /** @param TPos $pos */
  function pointInPolygon(array $pos): bool {
    $c = false;
    foreach ($this->geoms() as $ring)
      if ($ring->pointInPolygon($pos))
        $c = !$c;
    return $c;
  }
  static function test_pointInPolygon(): void {
    $p0 = [0, 0];
    foreach ([ // liste de polyligne non fermées
      ['coords'=> [[1, 0],[0, 1],[-1, 0],[0, -1]], 'result'=> true],
      ['coords'=> [[1, 1],[-1, 1],[-1, -1],[1, -1]], 'result'=> true],
      ['coords'=> [[1, 1],[-1, 1],[-1, -1],[1, -1],[1, 1]], 'result'=> true],
      ['coords'=> [[1, 1],[2, 1],[2, 2],[1, 2]], 'result'=> false],
    ] as $test) {
      $coords = $test['coords'];
      $coords[] = $coords[0]; // fermeture de la polyligne
      $pol = new Polygon([$coords]);
      echo "${pol}->pointInPolygon([$p0[0],$p0[1]])=",($pol->pointInPolygon($p0)?'true':'false'),
           " / ",($test['result']?'true':'false'),"<br>\n";
    }
  }

  /*PhpDoc: methods
  name:  segs
  title: "segs(): array - liste des segments constituant le polygone"
  */
  /** @return array<int, Object> */
  function segs(): array {
    $segs = [];
    foreach ($this->geoms() as $ls)
      $segs = array_merge($segs, $ls->segs());
    return $segs;
  }
  
  /*PhpDoc: methods
  name:  inters
  title: "function inters(Geometry $geom): bool - teste l'intersection entre les 2 polygones ou multi-polygones"
  */
  function inters(Geometry $geom, bool $verbose=false): bool {
    if (get_class($geom) == 'Polygon') {
      // Si les boites ne s'intersectent pas alors les polygones non plus
      if (!$this->ebox()->inters($geom->ebox()))
        return false;
      // si un point de $geom est dans $this alors il y a intersection
      foreach($geom->geoms() as $i=> $ls) {
        foreach ($ls->coords as $j=> $pos) {
          if ($this->pointInPolygon($pos)) {
            if ($verbose)
              echo "Point $i/$j de geom dans this<br>\n";
            return true;
          }
        }
      }
      // Si un point de $this est dans $geom alors il y a intersection
      foreach ($this->geoms() as $i=> $ls) {
        foreach ($ls->coords as $j=> $pos) {
          if ($geom->pointInPolygon($pos)) {
            if ($verbose)
              echo "Point $i/$j de this dans geom<br>\n";
            return true;
          }
        }
      }
      // Si 2 segments s'intersectent alors il y a intersection
      foreach ($this->segs() as $i => $seg0) {
        foreach($geom->segs() as $j => $seg1) {
          if ($seg0->intersects($seg1)) {
            if ($verbose)
              echo "Segment $i de this intersecte le segment $j de geom<br>\n";
            return true;
          }
        }
      }
      // Sinon il n'y a pas intersection
      return false;
    }
    elseif (get_class($geom) == 'MultiPolygon') {
      return $geom->inters($this);
    }
    else
      throw new SExcept(
        "Erreur d'appel de Polygon::inters() avec en paramètre un objet de ".get_class($geom),
        self::ErrorInters);
  }
  static function test_inters(): void {
    foreach([
      [
        'title'=> "cas d'un pol2 inclus dans pol1 ss que les rings ne s'intersectent",
        'pol1'=> new Polygon([[[0,0],[10,0],[10,10],[0,10],[0,0]]]), // carré 10x10 
        'pol2'=> new Polygon([[[1,1],[9,1],[9,9],[1,9],[1,1]]]),
        'result'=> 'true',
      ],
      [
        'title'=> "cas d'un pol2 intersectant pol1 ss qu'aucun point de l'un ne soit dans l'autre mais que les rings s'intersectent",
        'pol1'=> new Polygon([[[0,0],[10,0],[10,10],[0,10],[0,0]]]), // carré 10x10 
        'pol2'=> new Polygon([[[-1,1],[11,1],[11,9],[-1,9],[-1,1]]]),
        'result'=> 'true',
      ],
    ] as $test) {
      echo "<b>$test[title]</b><br>\n";
      echo "$test[pol1]->inters($test[pol2]):<br>\n",
          "-> ", ($test['pol1']->inters($test['pol2'], true)?'true':'false'),
           " / $test[result]<br>\n";
    }
  }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Polygon 
  if (!isset($_GET['test']))
    echo "<a href='?test=Polygon'>Test unitaire de la classe Polygon</a><br>\n";
  elseif ($_GET['test']=='Polygon') {
    $pol = new Polygon([[[0,0],[1,0],[1,1],[0,0]]]);
    echo "pol=$pol<br>\n";
    echo "pol->center()=",json_encode($pol->center()),"<br>\n";

    if (!isset($_GET['method'])) {
      echo "<a href='?test=Polygon&method=pointInPolygon'>Test de la méthode Polygon::pointInPolygon</a><br>\n";
      echo "<a href='?test=Polygon&method=inters'>Test de la méthode Polygon::inters</a><br>\n";
    }
    else {
      $testmethod = "test_$_GET[method]";
      Polygon::$testmethod();
    }
  }
}


{/*PhpDoc: classes
name: MultiPolygon
title: class MultiPolygon extends Geometry - Chaque polygone respecte les contraintes du Polygon
methods:
*/}
class MultiPolygon extends Geometry {
  const ErrorFromGeoArray = 'MultiPolygon::ErrorFromGeoArray';
  const ErrorCenterOfEmpty = 'MultiPolygon::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'MultiPolygon::ErrorPosOfEmpty';
  const ErrorInters = 'MultiPolygon::ErrorInters';

  /** @var TLLLPos $coords */
  public readonly array $coords; // contient une LLLPos

  /** @param TLLLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  
  // crée un MultiPolygon à partir du json_decode() d'une géométrie GeoJSON de type MultiPolygon ou Polygon
  /** @param array<string, string|TLLPos|TLLLPos> $geom */
  static function fromGeoArray(array $geom): self {
    $type = $geom['type'] ?? null;
    if (($type == 'MultiPolygon') && isset($geom['coordinates']))
      return new MultiPolygon($geom['coordinates']);
    else if (($type == 'Polygon') && isset($geom['coordinates']))
      return new MultiPolygon([$geom['coordinates']]);
    else
      throw new SExcept("Erreur de MultiPolygon::fromGeoArray(".json_encode($geom).")", self::ErrorFromGeoArray);
  }
  
  function eltTypes(): array { return $this->coords ? ['Polygon'] : []; }
  
  function geoms(): array { // liste des primitives contenues dans l'objet sous la forme d'une liste d'objets
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new Polygon($coord);
    return $geoms;
  }
    
  function center(): array {
    if (!$this->coords)
      throw new SExcept("Erreur: MultiPolygon::center() sur une liste vide", self::ErrorCenterOfEmpty);
    $c = [0, 0];
    $nbre = 0;
    foreach ($this->coords as $polygon) {
      foreach ($polygon[0] as $pt) {
        $c[0] += $pt[0];
        $c[1] += $pt[1];
        $nbre++;
      }
    }
    return [round($c[0]/$nbre, self::$precision), round($c[1]/$nbre, self::$precision)];    
  }
  
  function nbreOfPos(): int { return LElts::LLLcount($this->coords); }
  
  function aPos(): array {
    if (!$this->coords)
      throw new SExcept("Erreur: MultiPolygon::aPos() sur une liste vide", self::ErrorPosOfEmpty);
    return $this->coords[0][0][0];
  }
  
  function gbox(): GBox {
    $bbox = new GBox;
    foreach ($this->coords as $llpos)
      $bbox->union(new GBox($llpos));
    return $bbox;
  }
  
  function ebox(): EBox {
    $bbox = new EBox;
    foreach ($this->coords as $llpos)
      $bbox->union(new EBox($llpos));
    return $bbox;
  }
  
  function distanceToPos(array $pos): float {
    $dmin = INF;
    foreach ($this->geoms() as $ls) {
      $d = $ls->distanceToPos($pos);
      if ($d < $dmin)
        $dmin = $d;
    }
    return $dmin;
  }
  
  function reproject(callable $reprojPos): Geometry {
    $coords = [];
    foreach ($this->coords as $llpos)
      $coords[] = LLPos::reproj($reprojPos, $llpos);
    return new self($coords);
  }
  
  /*function area(): float {
    $area = 0.0;
    foreach($this->geom as $polygon)
      $area += $polygon->area();
    return $area;
  }*/
  
  /*PhpDoc: methods
  name:  pointInPolygon
  title: "pointInPolygon(array $pos): bool - teste si une position est dans un des polygones"
  */
  /** @param TPos $pos */
  function pointInPolygon(array $pos): bool {
    foreach ($this->geoms() as $polygon)
      if ($polygon->pointInPolygon($pos))
        return true;
    return false;
  }
  
  /*PhpDoc: methods
  name:  inters
  title: "function inters(Geometry $geom): bool - teste l'intersection entre les 2 polygones ou multi-polygones"
  */
  function inters(Geometry $geom): bool {
    if (get_class($geom) == 'Polygon') {
      foreach($this->geoms() as $polygon) {
        if ($polygon->inters($geom)) // intersection entre 2 polygones
          return true;
      }
      return false;
    }
    elseif (get_class($geom) == 'MultiPolygon') {
      foreach($this->geoms() as $pol0) {
        foreach ($geom->geoms() as $pol1) {
          if ($pol0->inters($pol1)) // intersection entre 2 polygones
            return true;
        }
      }
      return false;
    }
    else
      throw new SExcept("Erreur d'appel de MultiPolygon::inters() avec un objet de ".get_class($geom), self::ErrorInters);
  }
  
  /*static function haggregate(array $elts) - NON UTILISE {
    $coords = [];
    foreach ($elts as $elt)
      $coords[] = $elt->coords;
    return new MultiPolygon($coords);
  }*/
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe MultiPolygon
  if (!isset($_GET['test']))
    echo "<a href='?test=MultiPolygon'>Test unitaire de la classe MultiPolygon</a><br>\n";
  elseif ($_GET['test']=='MultiPolygon') {
    $mpol = new MultiPolygon([[[[0,0],[1,0],[1,1],[0,0]]]]);
    echo "mpol=$mpol<br>\n";
    echo "mpol->center()=",json_encode($mpol->center()),"<br>\n";
    die();
  }
}


{/*PhpDoc: classes
name: GeometryCollection
title: class GeometryCollection - Liste d'objets géométriques
methods:
*/}
class GeometryCollection {
  const ErrorCenterOfEmpty = 'GeometryCollection::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'GeometryCollection::ErrorPosOfEmpty';

  /** @var array<int, Object> $geometries */
  public readonly array $geometries; // list of Geometry objects
  
  // prend en paramètre une liste d'objets Geometry
  /** @param array<int, Object> $geometries */
  function __construct(array $geometries) { $this->geometries = $geometries; }
  
  /** @return array<string, string|array<int, array<string, string|TPos|TLPos|TLLPos|TLLLPos>>> */
  function asArray(): array {
    return [
      'type'=> 'GeometryCollection',
      'geometries'=> array_map(function(Geometry $g) { return $g->asArray(); }, $this->geometries),
    ];
  }
  
  // génère la réprésentation string GeoJSON
  function __toString(): string { return json_encode($this->asArray()); }
  
  // retourne la liste des types élémentaires ('Point','LineString','Polygon') contenus dans la géométrie
  /** @return array<int, string> */
  function eltTypes(): array {
    $allEltTypes = [];
    foreach ($this->geometries as $geom)
      if ($eltTypes = $geom->eltTypes())
        $allEltTypes[$eltTypes[0]] = 1;
    return array_keys($allEltTypes);
  }

  /** @return TPos */
  function center(): array {
    if (!$this->geometries)
      throw new SExcept("Erreur: GeometryCollection::center() sur une liste vide", self::ErrorCenterOfEmpty);
    $c = [0, 0];
    $nbre = 0;
    foreach ($this->geometries as $g) {
      $pt = $g->center();
      $c[0] += $pt[0];
      $c[1] += $pt[1];
      $nbre++;
    }
    return [$c[0]/$nbre, $c[1]/$nbre];
  }
  
  function nbreOfPos(): int {
    $nbreOfPos = 0;
    foreach ($this->geometries as $g)
      $nbreOfPos += $g->nbreOfPos();
    return $nbreOfPos;
  }
  
  /** @return TPos */
  function aPos(): array {
    if (!$this->geometries)
      throw new SExcept("Erreur: GeometryCollection::aPos() sur une liste vide", self::ErrorPosOfEmpty);
    return $this->geometries[0]->aPos();
  }
  
  function gbox(): GBox {
    $bbox = new GBox;
    foreach ($this->geometries as $geom)
      $bbox->union($geom->gbox());
    return $bbox;
  }
  
  function ebox(): EBox {
    $bbox = new EBox;
    foreach ($this->geometries as $geom)
      $bbox->union($geom->ebox());
    return $bbox;
  }
  
  /** @param TPos $pos */
  function distanceToPos(array $pos): float {
    $dmin = INF;
    foreach ($this->geometries as $ls) {
      $d = $ls->distanceToPos($pos);
      if ($d < $dmin)
        $dmin = $d;
    }
    return $dmin;
  }
  
  function reproject(callable $reprojPos): GeometryCollection {
    $geoms = [];
    foreach ($this->geometries as $geom)
      $geoms[] = $geom->reproject($reprojPos);
    return new GeometryCollection($geoms);
  }
  
  // Décompose une géométrie en un array de géométries élémentaires (Point/LineString/Polygon)
  /** @return array<int, object> */
  function decompose(): array {
    $elts = [];
    foreach ($this->geometries as $g)
      $elts = array_merge($elts, $g->decompose());
    return $elts;
  }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe GeometryCollection
  if (!isset($_GET['test']))
    echo "<a href='?test=GeometryCollection'>Test unitaire de la classe GeometryCollection</a><br>\n";
  elseif ($_GET['test']=='GeometryCollection') {
    $ls = new LineString([[0,0],[1,1]]);
    $mls = new MultiLineString([[[0,0],[1,1]]]);
    $mpol = new MultiPolygon([[[[0,0],[1,0],[1,1],[0,0]]]]);
    $gc = new GeometryCollection([$ls, $mls, $mpol]);
    echo "gc=$gc<br>\n";
    echo "gc->center()=",json_encode($gc->center()),"<br>\n";
    echo "gc->reproject()=",$gc->reproject(function(array $pos) { return $pos; }),"<br>\n";
    echo "Proj en WebMercator=",$gc->reproject(function(array $pos) { return WebMercator::proj($pos); }),"<br>\n";
    
    echo "<b>Test de decompose</b><br>\n";
    foreach ([
      [ 'type'=>'MultiPoint', 'coordinates'=>[[0,0], [1,1]]],
      [ 'type'=>'GeometryCollection',
        'geometries'=> [
          ['type'=>'MultiPoint', 'coordinates'=>[[0,0], [1,1]]],
          ['type'=>'LineString', 'coordinates'=>[[0,0], [1,1]]],
        ],
      ]
    ] as $geom) {
      echo json_encode($geom),' -> [',implode(',',Geometry::fromGeoArray($geom)->decompose()),"]<br>\n";
    }

    $gc = Geometry::fromGeoArray(['type'=>'GeometryCollection', 'geometries'=> []]);
    echo "gc=$gc<br>\n";
    //echo "gc->center()=",json_encode($gc->center()),"<br>\n";
    echo "gc->reproject()=",$gc->reproject(function(array $pos) { return $pos; }),"<br>\n";
  }
}
