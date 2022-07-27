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
function asArray($val) {
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
function my_json_encode($val): string {
  return json_encode(asArray($val), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}


{/*PhpDoc: classes
name: Geometry
title: abstract class Geometry - Gestion d'une Geometry GeoJSON et de quelques opérations
doc: |
  Les coordonnées sont conservées en array comme en GeoJSON et pas structurées avec des objets.
  Chaque type de géométrie correspond à une sous-classe non abstraite.
  Par défaut, la géométrie est en coordonnées géographiques mais les classes peuvent aussi être utilisées
  avec des coordonnées euclidiennes en utilisant des méthodes soécifiques préfixées par e.
  Un style peut être associé à une Geometry. Il s'inspire de https://github.com/mapbox/simplestyle-spec/tree/master/1.1.0
*/}
abstract class Geometry {
  const ErrorFromGeoJSON = 'Geometry::ErrorFromGeoJSON';
  const ErrorCenterOfEmptyLPos = 'Geometry::ErrorCenterOfEmptyLPos';
  const HOMOGENEOUSTYPES = ['Point','LineString','Polygon','MultiPoint','MultiLineString','MultiPolygon'];
  static int $precision = 6; // nbre de chiffres après la virgule à conserver pour les coord. géo.
  static int $ePrecision = 1; // nbre de chiffres après la virgule à conserver pour les coord. euclidiennes
  protected array $coords; // coordonnées ou Positions, stockées comme array, array(array), ... en fonction de la sous-classe
  protected array $style; // un style peut être associé à une géométrie, toujours un array, par défaut []
  
  // crée une géométrie à partir du json_decode() du GeoJSON
  static function fromGeoJSON(array $geom, array $style=[]): Geometry {
    $type = $geom['type'] ?? null;
    if (in_array($type, self::HOMOGENEOUSTYPES) && isset($geom['coordinates']))
      return new $type($geom['coordinates']);
    elseif (($type=='GeometryCollection') && isset($geom['geometries'])) {
      $geoms = [];
      foreach ($geom['geometries'] as $g)
        $geoms[] = self::fromGeoJSON($g);
      return new GeometryCollection($geoms);
    }
    else
      throw new SExcept("Erreur de Geometry::fromGeoJSON(".json_encode($geom).")", self::ErrorFromGeoJSON);
  }
  
  // fonction d'initialisation valable pour toutes les géométries homogènes
  function __construct(array $coords, array $style=[]) { $this->coords = $coords; $this->style = $style; }
  
  // récupère le type
  function type(): string { return get_class($this); }
  // retourne la liste des types élémentaires ('Point','LineString','Polygon') contenus dans la géométrie
  abstract function eltTypes(): array;
  // récupère les coordonnées
  function coords() { return $this->coords; }
  
  // définit le style associé et le récupère
  function setStyle(array $style=[]): void { $this->style = $style; }
  function getStyle(): array { return $this->style; }
  
  // génère la réprésentation string GeoJSON
  function __toString(): string { return json_encode($this->asArray()); }
  
  // génère la représentation Php du GeoJSON
  function asArray(): array { return ['type'=>get_class($this), 'coordinates'=> $this->coords]; }
  
  // Retourne la liste des primitives contenues dans l'objet sous la forme d'objets
  // Point -> [], MutiPoint->[Point], LineString->[Point], MultiLineString->[LineString], Polygon->[LineString],
  // MutiPolygon->[Polygon], GeometryCollection->GeometryCollection
  abstract function geoms(): array;
  
  // renvoie le centre d'une géométrie
  abstract function center(): array;
    
  // calcule le centre d'une liste de positions, génère une exception si la liste est vide
  static function centerOfLPos(array $lpos): array {
    if (!$lpos)
      throw new SExcept("Erreur: Geometry::centerOfLPos() d'une liste de positions vide", self::ErrorCenterOfEmptyLPos);
    $c = [0, 0];
    $nbre = 0;
    foreach ($lpos as $pos) {
      $c[0] += $pos[0];
      $c[1] += $pos[1];
      $nbre++;
    }
    return [round($c[0]/$nbre, self::$precision), round($c[1]/$nbre, self::$precision)];
  }
  
  abstract function nbreOfPos(): int;
  
  // retourne un point de la géométrie
  abstract function aPos(): array;
  
  // renvoie la GBox de la géométrie considérée comme géographique
  abstract function bbox(): GBox;
  
  // reprojète ue géométrie, prend en paramètre une fonction de reprojection d'une position, retourne un objet géométrie
  abstract function reproject(callable $reprojPos): Geometry;

  // reprojète une liste de positions et en retourne la liste
  static function reprojLPos(callable $reprojPos, array $lpos): array {
    $coords = [];
    foreach ($lpos as $pos)
      $coords[] = $reprojPos($pos);
    return $coords;
  }

  // reprojète une liste de liste de positions et en retourne la liste
  static function reprojLLPos(callable $reprojPos, array $llpos): array {
    $coords = [];
    foreach ($llpos as $i => $lpos)
      $coords[] = Geometry::reprojLPos($reprojPos, $lpos);
    return $coords;
  }
  
  function dissolveCollection(): array { return [$this]; }
  
  // Décompose une géométrie en un array de géométries élémentaires (Point/LineString/Polygon)
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
  
  // $coords contient une liste de 2 ou 3 nombres
  
  function eltTypes(): array { return ['Point']; }
  function geoms(): array { return []; }
  function center(): array { return $this->coords; }
  function nbreOfPos(): int { return 1; }
  function aPos(): array { return $this->coords; }
  function bbox(): GBox { return new GBox($this->coords); }
  function reproject(callable $reprojPos): Geometry { return new Point($reprojPos($this->coords)); }

  function add($v): Point {
    /*PhpDoc: methods
    name:  add
    title: "function add($v): Point - $this + $v en 2D, $v peut être un Point ou une position"
    */
    if (Pos::is($v))
      return new Point([$this->coords[0] + $v[0], $this->coords[1] + $v[1]]);
    elseif (is_object($v) && (get_class($v) == 'Point'))
      return new Point([$this->coords[0] + $v->coords[0], $this->coords[1] + $v->coords[1]]);
    else
      throw new SExcept("Erreur dans Point:add(), paramètre ni position ni Point", self::ErrorBadParamInAdd);
  }
  
  function diff($v): Point {
    /*PhpDoc: methods
    name:  diff
    title: "function diff(Point $v): Point - $this - $v en 2D"
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
  static function A_ADAPTER_test_pscal() {
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
  }

  // multiplication de $this considéré comme un vecteur par un scalaire
  function scalMult(float $scal): Point { return new Point([$this->coords[0] * $scal, $this->coords[1] * $scal]); }

  function norm(): float { return sqrt($this->scalarProduct($this)); }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Point
  if (!isset($_GET['test']))
    echo "<a href='?test=Point'>Test unitaire de la classe Point</a>\n";
  elseif ($_GET['test']=='Point') {
    $pt = Geometry::fromGeoJSON(['type'=>'Point', 'coordinates'=> [0,0]]);
    echo "pt=$pt<br>\n";
    echo "reproject(pt)=",$pt->reproject(function(array $pos) { return $pos; }),"\n";
    echo $pt->add([1,1]),"\n";
    echo $pt->add(new Point([1,1])),"\n";
    try {
      echo $pt->add([[1,1]]),"\n";
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
  private $tab; // 2 positions: [[number]]
  
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
      ['b'=> new Segment([0,-5],[10,5]), 'result'=> true],
      //['POINT(0 0)','POINT(10 0)','POINT(0 0)','POINT(10 5)'],
      //['POINT(0 0)','POINT(10 0)','POINT(0 -5)','POINT(10 -5)'],
      //['POINT(0 0)','POINT(10 0)','POINT(0 -5)','POINT(20 0)'],
    ] as $test) {
      $b = $test['b'];
      echo "$a ->intersects($b) -> ",my_json_encode($a->intersects($b)), " / ", $test['result']?'true':'false',"<br>\n";
    }
  }

  // projection d'une position sur la ligne définie par le segment
  // retourne u / P' = A + u * (B-A).
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
  static function test_distancePosToLine() {
    $seg = new self([0,0], [1,0]);
    foreach ([[0,0], [1,0], [2, 0], [0.5, 5], [0.5, -5]] as $pos)
      echo "distancePosToLine($seg, [$pos[0],$pos[1]])-> ",$seg->distancePosToLine($pos),"\n";
  }
  
  // distance d'une position au segment
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
  
  // $coords contient une liste de listes de 2 ou 3 nombres
  
  function eltTypes(): array { return $this->coords ? ['Point'] : []; }
  
  function geoms(): array {
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new Point($coord);
    return $geoms;
  }
  
  function center(): array { return Geometry::centerOfLPos($this->coords); }
  function nbreOfPos(): int { return count($this->coords); }
  function aPos(): array {
    if (!$this->coords)
      throw new SExcept("Erreur: MultiPoint::aPos() sur une liste de positions vide", self::ErrorEmpty);
    return $this->coords[0];
  }
  function bbox(): GBox { return new GBox($this->coords); }
  function reproject(callable $reprojPos): Geometry { return new self(Geometry::reprojLPos($reprojPos, $this->coords)); }
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
    $mpt = Geometry::fromGeoJSON(['type'=>'MultiPoint', 'coordinates'=>[]]);
    $mpt = Geometry::fromGeoJSON(['type'=>'MultiPoint', 'coordinates'=>[[0,0],[1,1]]]);
    echo "$mpt ->center() = ",json_encode($mpt->center()),"<br>\n";
    echo "$mpt ->aPos() = ",json_encode($mpt->aPos()),"<br>\n";
    echo "$mpt ->bbox() = ",$mpt->bbox(),"<br>\n";
    echo "$mpt ->reproject() = ",$mpt->reproject(function(array $pos) { return $pos; }),"<br>\n";
  }
}


{/*PhpDoc: classes
name: LineString
title: class LineString extends Geometry - contient au moins 2 positions
*/}
class LineString extends Geometry {
  // $coords contient une liste de listes de 2 ou 3 nombres
  function eltTypes(): array { return ['LineString']; }
  
  function geoms(): array {
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new Point($coord);
    return $geoms;
  }
  
  function center(): array { return Geometry::centerOfLPos($this->coords); }
  function nbreOfPos(): int { return count($this->coords); }
  function aPos(): array { return $this->coords[0]; }
  function bbox(): GBox { return new GBox($this->coords); }
  
  function reproject(callable $reprojPos): Geometry {
    return new self(Geometry::reprojLPos($reprojPos, $this->coords));
  }
  
  /*PhpDoc: methods
  name:  pointInPolygon
  title: "pointInPolygon(array $p): bool - teste si une position pos est dans le polygone formé par la ligne fermée"
  */
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
  static function test_pointInPolygon() {
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
  function distanceToPos(array $pos): float {
    foreach ($this->segs() as $seg) {
      $d = $seg->distanceToPos($pos);
      if (!isset($dmin) || ($d < $dmin))
        $dmin = $d;
    }
    return $dmin;
  }
  static function test_distanceToPos(): void {
    $ls = ['type'=>'LineString', 'coordinates'=> [[0,0],[1,0],[2,2]]];
    foreach ([[0,0],[2,2],[1,1],[1,-1],[-1,-1]] as $pos)
      echo json_encode($ls),"->distanceToPos([$pos[0],$pos[1]])-> ",Geometry::fromGeoJSON($ls)->distanceToPos($pos),"\n";
  }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe LineString
  if (!isset($_GET['test']))
    echo "<a href='?test=LineString'>Test unitaire de la classe LineString</a><br>\n";
  elseif ($_GET['test']=='LineString') {
    $ls = Geometry::fromGeoJSON(['type'=>'LineString', 'coordinates'=> [[0,0],[1,1]]]);
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
  // $coords contient une liste de listes de listes de 2 ou 3 nombres
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
  
  function bbox(): GBox { return new GBox($this->coords); }
  
  function reproject(callable $reprojPos): Geometry {
    return new self(Geometry::reprojLLPos($reprojPos, $this->coords));
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
    $mls = Geometry::fromGeoJSON(['type'=>'MultiLineString', 'coordinates'=> [[[0,0],[1,1]]]]);
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

  // $coords contient une liste de listes de listes de 2 ou 3 nombres

  function eltTypes(): array { return ['Polygon']; }
  
  function geoms(): array {
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new LineString($coord);
    return $geoms;
  }
  
  function center(): array { return Geometry::centerOfLPos($this->coords[0]); }
  function nbreOfPos(): int { return LElts::LLcount($this->coords); }
  function aPos(): array { return $this->coords[0][0]; }
  function bbox(): GBox { return new GBox($this->coords); }
  
  function reproject(callable $reprojPos): Geometry {
    return new self(Geometry::reprojLLPos($reprojPos, $this->coords));
  }
  
  function area_A_ADAPTER () {
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
  function pointInPolygon(array $pos): bool {
    $c = false;
    foreach ($this->geoms() as $ring)
      if ($ring->pointInPolygon($pos))
        $c = !$c;
    return $c;
  }
  static function test_pointInPolygon() {
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
      if (!$this->bbox()->inters($geom->bbox()))
        return false;
      // si un point de $geom est dans $this alors il y a intersection
      foreach($geom->geoms() as $i=> $ls) {
        foreach ($ls->coords() as $j=> $pos) {
          if ($this->pointInPolygon($pos)) {
            if ($verbose)
              echo "Point $i/$j de geom dans this<br>\n";
            return true;
          }
        }
      }
      // Si un point de $this est dans $geom alors il y a intersection
      foreach ($this->geoms() as $i=> $ls) {
        foreach ($ls->coords() as $j=> $pos) {
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
  static function test_inters() {
    foreach([
      [
        'title'=> "cas d'un pol2 inclus dans pol1 ss que les rings ne s'intersectent",
        'pol1'=> new Polygon([[[0,0],[10,0],[10,10],[0,10],[0,0]]]), // carré 10x10 
        'pol2'=> new Polygon([[[1,1],[9,1],[9,9],[1,9],[1,1]]]),
        'result'=> true,
      ],
      [
        'title'=> "cas d'un pol2 intersectant pol1 ss qu'aucun point de l'un ne soit dans l'autre mais que les rings s'intersectent",
        'pol1'=> new Polygon([[[0,0],[10,0],[10,10],[0,10],[0,0]]]), // carré 10x10 
        'pol2'=> new Polygon([[[-1,1],[11,1],[11,9],[-1,9],[-1,1]]]),
        'result'=> true,
      ],
    ] as $test) {
      echo "<b>$test[title]</b><br>\n";
      echo "$test[pol1]->inters($test[pol2]):<br>\n",
          "-> ", ($test['pol1']->inters($test['pol2'], true)?'true':'false'),
           " / ", ($test['result']?'true':'false'),"<br>\n";
    }
  }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Polygon 
  if (!isset($_GET['test']))
    echo "<a href='?test=Polygon'>Test unitaire de la classe Polygon</a><br>\n";
  elseif ($_GET['test']=='Polygon') {
    $pol = Geometry::fromGeoJSON(['type'=>'Polygon', 'coordinates'=> [[[0,0],[1,0],[1,1],[0,0]]]]);
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
  const ErrorCenterOfEmpty = 'MultiPolygon::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'MultiPolygon::ErrorPosOfEmpty';
  const ErrorInters = 'MultiPolygon::ErrorInters';

  // $coords contient une liste de listes de listes de listes de 2 ou 3 nombres

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
  
  function bbox(): GBox {
    $bbox = new GBox;
    foreach ($this->coords as $llpos)
      $bbox->union(new GBox($llpos));
    return $bbox;
  }
  
  function reproject(callable $reprojPos): Geometry {
    $coords = [];
    foreach ($this->coords as $llpos)
      $coords[] = Geometry::reprojLLPos($reprojPos, $llpos);
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
    $mpol = Geometry::fromGeoJSON(['type'=>'MultiPolygon', 'coordinates'=> [[[[0,0],[1,0],[1,1],[0,0]]]]]);
    echo "mpol=$mpol<br>\n";
    echo "mpol->center()=",json_encode($mpol->center()),"<br>\n";
  }
}


{/*PhpDoc: classes
name: GeometryCollection
title: class GeometryCollection extends Geometry - Liste d'objets géométriques
methods:
*/}
class GeometryCollection extends Geometry {
  const ErrorCenterOfEmpty = 'GeometryCollection::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'GeometryCollection::ErrorPosOfEmpty';

  private $geometries; // list of Geometry objects
  
  // prend en paramètre une liste d'objets Geometry
  function __construct(array $geometries, array $style=[]) { $this->geometries = $geometries; $this->style = $style; }
  
  // traduit les géométries en array Php
  function geoms(): array {
    $geoms = [];
    foreach ($this->geometries as $geom)
      $geoms[] = $geom->asArray();
    return $geoms;
  }
  
  function asArray(): array { return ['type'=>'GeometryCollection', 'geometries'=> $this->geoms()]; }
  
  // retourne la liste des types élémentaires ('Point','LineString','Polygon') contenus dans la géométrie
  function eltTypes(): array {
    $allEltTypes = [];
    foreach ($this->geometries as $geom)
      if ($eltTypes = $geom->eltTypes())
        $allEltTypes[$eltTypes[0]] = 1;
    return array_keys($allEltTypes);
  }

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
    return [round($c[0]/$nbre, self::$precision), round($c[1]/$nbre, self::$precision)];    
  }
  
  function nbreOfPos(): int {
    $nbreOfPos = 0;
    foreach ($this->geometries as $g)
      $nbreOfPos += $g->nbreOfPos();
    return $nbreOfPos;
  }
  
  function aPos(): array {
    if (!$this->geometries)
      throw new SExcept("Erreur: GeometryCollection::aPos() sur une liste vide", self::ErrorPosOfEmpty);
    return $this->geometries[0]->aPos();
  }
  
  function bbox(): GBox {
    $bbox = new GBox;
    foreach ($this->geometries as $geom)
      $bbox->union($geom->bbox());
    return $bbox;
  }
  
  function reproject(callable $reprojPos): Geometry {
    $geoms = [];
    foreach ($this->geometries as $geom)
      $geoms[] = $geom->reproject($reprojPos);
    return new GeometryCollection($geoms);
  }
  
  function dissolveCollection(): array { return $this->geometries; }
  
  // Décompose une géométrie en un array de géométries élémentaires (Point/LineString/Polygon)
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
    $ls = Geometry::fromGeoJSON(['type'=>'LineString', 'coordinates'=> [[0,0],[1,1]]]);
    $mls = Geometry::fromGeoJSON(['type'=>'MultiLineString', 'coordinates'=> [[[0,0],[1,1]]]]);
    $mpol = Geometry::fromGeoJSON(['type'=>'MultiPolygon', 'coordinates'=> [[[[0,0],[1,0],[1,1],[0,0]]]]]);
    $gc = Geometry::fromGeoJSON([
      'type'=>'GeometryCollection',
      'geometries'=> [$ls->asArray(), $mls->asArray(), $mpol->asArray()]
    ]);
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
      echo json_encode($geom),' -> [',implode(',',Geometry::fromGeoJSON($geom)->decompose()),"]<br>\n";
    }

    $gc = Geometry::fromGeoJSON(['type'=>'GeometryCollection', 'geometries'=> []]);
    echo "gc=$gc<br>\n";
    //echo "gc->center()=",json_encode($gc->center()),"<br>\n";
    echo "gc->reproject()=",$gc->reproject(function(array $pos) { return $pos; }),"<br>\n";
  }
}
