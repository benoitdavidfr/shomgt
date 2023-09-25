<?php
/**
 * Package géométrique utilisant des coordonnées géographiques ou euclidiennes
 *
 *  Ce fichier définit la classe abstraite Geometry, des sous-classes par type de géométrie GeoJSON
 *  (https://tools.ietf.org/html/rfc7946) ainsi qu'une classe Segment utilisé pour certains calculs.
 *  Une géométrie GeoJSON peut être facilement créée en décodant le JSON en Php par json_decode()
 *  puis en appellant la méthode Geometry::fromGeoArray().
 *
 * journal:
 * - 2/9/2023
 *   - reformattage de la doc en PHPDoc
 * - 10/6/2023:
 *   - corrections pour mise à niveau Php 8.2
 *     - Deprecated: Using ${var} in strings is deprecated, use {$var} instead on line 590
 *     - Deprecated: Using ${var} in strings is deprecated, use {$var} instead on line 833
 * - 22/8/2022:
 *   - correction bug
 * - 28/7/2022:
 *   - correction suite à analyse PhpStan
 *   - suppression du style associé à une géométrie
 *   - GeomtryCollection n'est plus une sous-classe de Geometry
 *   - transfert de qqs méthodes dans Po, LPos et LLPos
 * - 8/7/2022:
 *   - ajout Segment::(projPosOnLine+distancePosToLine+distanceToPos) + LineString::distanceToPos
 * - 7-10/2/2022:
 *   - ajout de code aux exceptions
 *   - décomposition du test unitaire de la classe Geometry dans les tests des sous-classes
 *   - transformation des Exception en \SExcept et fourniture d'un code de type string
 * - 9/3/2019:
 *   - ajout de nombreuses fonctionnalités
 * - 7/3/2019:
 *   - création
 * @package gegeom
 */
namespace gegeom;

$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/coordsys.inc.php';
require_once __DIR__.'/zoom.inc.php';
require_once __DIR__.'/gebox.inc.php';
require_once __DIR__.'/sexcept.inc.php';

use Symfony\Component\Yaml\Yaml;

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
  echo "<html><head><meta charset='UTF-8'><title>gegeom</title></head><body><pre>";
}

/** Prend une valeur et la transforme récursivement en aray Php pur sans objet, utile pour l'afficher avec json_encode
 * Les objets rencontrés doivent avoir une méthode asArray() qui décompose l'objet en array des propriétés exposées */
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

/** génère un json en traversant les objets qui savent se décomposer en array par asArray() */
function my_json_encode(mixed $val): string {
  return json_encode(asArray($val), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}


/**
 * abstract class Geometry - Gestion d'une Geometry GeoJSON (hors collection) et de quelques opérations
 *
 * Les coordonnées sont conservées en array comme en GeoJSON et pas structurées avec des objets.
 * Chaque type de géométrie correspond à une sous-classe concrète.
 * Par défaut, la géométrie est en coordonnées géographiques mais les classes peuvent aussi être utilisées
 * avec des coordonnées euclidiennes en utilisant des méthodes soécifiques préfixées par e.
*/
abstract class Geometry {
  const ErrorFromGeoArray = 'Geometry::ErrorFromGeoArray'; /** Code d'erreur dans SExcept() */
  /** Liste des types de géométries homogènes */
  const HOMOGENEOUSTYPES = ['Point','LineString','Polygon','MultiPoint','MultiLineString','MultiPolygon'];
  
  static int $precision = 6; /** nbre de chiffres après la virgule à conserver pour les coord. géo. */
  static int $ePrecision = 1; /** nbre de chiffres après la virgule à conserver pour les coord. euclidiennes */
  
  /** @var TPos|TLPos|TLLPos|TLLLPos $coords Positions, stockées comme array, array(array), ... en fn de la sous-classe */
  public readonly array $coords;
  
  /** crée une géométrie à partir du json_decode() d'une géométrie GeoJSON
   * @param TGeoJsonGeometry $geom */
  static function fromGeoArray(array $geom): Geometry|GeometryCollection {
    $type = $geom['type'] ?? null;
    if (in_array($type, self::HOMOGENEOUSTYPES) && isset($geom['coordinates'])) {
      return new (__NAMESPACE__.'\\'.$type)($geom['coordinates']); // @phpstan-ignore-line
    }
    elseif (($type=='GeometryCollection') && isset($geom['geometries'])) {
      $geoms = [];
      foreach ($geom['geometries'] as $g)
        $geoms[] = self::fromGeoArray($g);
      return new GeometryCollection($geoms);
    }
    else
      throw new \SExcept("Erreur de Geometry::fromGeoArray(".json_encode($geom).")", self::ErrorFromGeoArray);
  }
  
  /** fonction d'initialisation valable pour toutes les géométries homogènes
   * @param TPos|TLPos|TLLPos|TLLLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  
  /** retourne le nom du type GeoJSON qui est le nom de la classe sans l'espace de nom */
  function type(): string { return substr(get_class($this), strlen(__NAMESPACE__)+1); }
  
  /** retourne la liste des types élémentaires ('Point','LineString','Polygon') contenus dans la géométrie
   * @return list<string> */
  abstract function eltTypes(): array;
  
  /** génère la réprésentation string GeoJSON */
  function __toString(): string { return json_encode($this->asArray()); }
  
  /** génère la représentation Php du GeoJSON
   * @return array<string, string|TPos|TLPos|TLLPos|TLLLPos> */
  function asArray(): array { return ['type'=> $this->type(), 'coordinates'=> $this->coords]; }
  
  /** Retourne la liste des primitives contenues dans l'objet sous la forme d'objets
   * Point -> [], MutiPoint->[Point], LineString->[Point], MultiLineString->[LineString], Polygon->[LineString],
   * MutiPolygon->[Polygon]
   * @return array<int, object> */
  abstract function geoms(): array;
  
  /** renvoie le barycentre d'une géométrie
   * @return TPos */
  abstract function center(): array;
  
  abstract function nbreOfPos(): int;
  
  /** retourne un point de la géométrie
   * @return TPos */
  abstract function aPos(): array;
  
  /** retourne la GBox de la géométrie considérée comme géographique */
  abstract function gbox(): GBox;
  
  /** retourne la EBox de la géométrie considérée comme euclidienne */
  abstract function ebox(): EBox;
  
  /** distance min. d'une géométrie à une position
   * @param TPos $pos */
  abstract function distanceToPos(array $pos): float;
  
  /** reprojète ue géométrie, prend en paramètre une fonction de reprojection d'une position, retourne un objet géométrie */
  abstract function reproject(callable $reprojPos): Geometry;
  
  /** Décompose une géométrie en une liste de géométries élémentaires (Point|LineString|Polygon)
   * @return list<Point|LineString|Polygon> */
  function decompose(): array {
    $transfos = ['MultiPoint'=>'Point', 'MultiLineString'=>'LineString', 'MultiPolygon'=>'Polygon'];
    if (isset($transfos[$this->type()])) {
      $elts = [];
      foreach ($this->coords as $eltcoords) {
        $elts[] = new (__NAMESPACE__.'\\'.$transfos[$this->type()])($eltcoords);
      }
      return $elts;
    }
    else // $this est un élément
      return [$this]; // @phpstan-ignore-line
  }
}

// Le test unitaire est à la fin du fichier

/**
 * Un Point correspond à une position, il peut aussi être considéré comme un vecteur
 */
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

  /** $this + $v en 2D
   * @param Point|TPos $v peut être un Point ou une position */
  function add(Point|array $v): Point {
    if (Pos::is($v))
      return new Point([$this->coords[0] + $v[0], $this->coords[1] + $v[1]]);
    elseif (is_object($v) && (get_class($v) == __NAMESPACE__.'\Point'))
      return new Point([$this->coords[0] + $v->coords[0], $this->coords[1] + $v->coords[1]]);
    else
      throw new \SExcept("Erreur dans Point:add(), paramètre ni position ni Point", self::ErrorBadParamInAdd);
  }
  
  /** $this - $v en 2D
   * @param Point|TPos $v peut être un Point ou une position */
  function diff(Point|array $v): Point {
    if (Pos::is($v))
      return new Point([$this->coords[0] - $v[0], $this->coords[1] - $v[1]]);
    elseif (is_object($v) && get_class($v) == __NAMESPACE__.'\Point')
      return new Point([$this->coords[0] - $v->coords[0], $this->coords[1] - $v->coords[1]]);
    else
      throw new \SExcept(
        "Erreur dans Point:diff(), paramètre ni position ni Point mais ".get_class($v),
        self::ErrorBadParamInDiff);
  }
    
  /** produit vectoriel $this par $v en 2D */
  function vectorProduct(Point $v): float { return $this->coords[0] * $v->coords[1] - $this->coords[1] * $v->coords[0]; }
  
  /** produit scalaire $this par $v en 2D */
  function scalarProduct(Point $v): float { return $this->coords[0] * $v->coords[0] + $this->coords[1] * $v->coords[1]; }
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

  /** multiplication de $this considéré comme un vecteur par un scalaire */
  function scalMult(float $scal): Point { return new Point([$this->coords[0] * $scal, $this->coords[1] * $scal]); }

  /** norme de $this considéré comme un vecteur */
  function norm(): float { return sqrt($this->scalarProduct($this)); }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Point
  if (!isset($_GET['test']))
    echo "<a href='?test=Point'>Test unitaire de la classe Point</a>\n";
  elseif ($_GET['test']=='Point') {
    $pt = new Point ([0,0]);
    echo "pt=$pt\n";
    echo "pt->reproject() = ",$pt->reproject(function(array $pos) { return $pos; }),"\n";
    echo "$pt ->add([1,1]) = ",$pt->add([1,1]),"\n";
    echo "$pt ->add(new Point([1,1]))) = ",$pt->add(new Point([1,1])),"\n";
    echo "$pt ->diff([1,1]) = ",$pt->diff([1,1]),"\n";
    echo "$pt ->diff(new Point([1,1]))) = ",$pt->diff(new Point([1,1])),"\n";
    try {
      echo "$pt ->add([[1,1]]) (Vérification que cela génère une exception):\n";
      echo $pt->add([[1,1]]),"\n"; // @phpstan-ignore-line
    }
    catch(\SExcept $e) {
      echo "  Exception ",$e->getMessage(),", scode=",$e->getSCode(),"\n";
    }
  }
}


/**
 * Segment composé de 2 positions ; considéré comme orienté de la première vers la seconde
 *
 * On considère le segment comme fermé sur sa première position et ouvert sur la seconde
 * Cela signifie que la première position appartient au segment mais pas la seconde. */
readonly class Segment {
  /** @var TLPos $tab tableau de 2 positions */
  public array $tab;
  
  /**
  * @param TPos $pos0
  * @param TPos $pos1
  */
  function __construct(array $pos0, array $pos1) { $this->tab = [$pos0, $pos1]; }
  
  function __toString(): string { return json_encode($this->tab); }
  
  /** génère le vecteur correspondant à $tab[1] - $tab[0] représenté par un Point */
  function vector(): Point {
    return new Point([$this->tab[1][0] - $this->tab[0][0], $this->tab[1][1] - $this->tab[0][1]]);
  }
  
  /**
   * intersection entre 2 segments
   *
   * Je considère les segments fermés sur le premier point et ouvert sur le second.
   * Cela signifie qu'une intersection ne peut avoir lieu sur la seconde position
   * Si les segments ne s'intersectent pas alors retourne []
   * S'ils s'intersectent alors retourne le dictionnaire
   *   ['pos'=> l'intersection comme Point, 'u'=> l'abscisse sur le premier segment, 'v'=> l'abscisse sur le second]
   * Si les 2 segments sont parallèles, alors retourne [] même s'ils sont partiellement confondus
   * @return array<string, mixed> */
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

  /** projection d'une position sur la ligne définie par le segment
   * retourne u / P' = A + u * (B-A).
   * @param TPos $pos */
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
  
  /** distance signée de $pos à la droite définie par le segment
   *
   * La distance est positive si le point est à gauche de la droite AB et négative s'il est à droite
   * @param TPos $pos */
  function distancePosToLine(array $pos): float {
    $ab = $this->vector();
    $ap = new Point([$pos[0]-$this->tab[0][0], $pos[1]-$this->tab[0][1]]);
    if ($ab->norm() == 0)
      throw new \Exception("Erreur dans distancePosToLine : Points A et B confondus et donc droite non définie");
    return $ab->vectorProduct($ap) / $ab->norm();
  }
  static function test_distancePosToLine(): void {
    $seg = new self([0,0], [1,0]);
    foreach ([[0,0], [1,0], [2, 0], [0.5, 5], [0.5, -5]] as $pos)
      echo "distancePosToLine($seg, [$pos[0],$pos[1]])-> ",$seg->distancePosToLine($pos),"\n";
  }
  
  /** distance de la position au segment
   * @param TPos $pos */
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

/** Une liste de points, éventuellement vide */
class MultiPoint extends Geometry {
  const ErrorEmpty = 'MultiPoint::ErrorEmpty';
  
  /** @var TLPos $coords contient une liste de Pos */
  public readonly array $coords;
  
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
      throw new \SExcept("Erreur: MultiPoint::aPos() sur une liste de positions vide", self::ErrorEmpty);
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


/** Ligne brisée, contient au moins 2 positions */
class LineString extends Geometry {
  /** @var TLPos $coords contient une liste de listes de 2 ou 3 nombres */
  public readonly array $coords;
  
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
  
  /** teste si la position p est dans le polygone formé par la ligne fermée
   *
   * @param TPos $p */
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
      echo $ls,"->pointInPolygon([$p0[0],$p0[1]])=",($ls->pointInPolygon($p0)?'true':'false'), // correction Php 8.2
           " / ",($test['result']?'true':'false'),"<br>\n";
    }
  }

  /** liste des segments constituant la polyligne
   * @return list<Segment> */
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
  
  /** calcule la distance d'une LineString à une position
   * @param TPos $pos */
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


/** Liste de lignes brisées */
class MultiLineString extends Geometry {
  const ErrorCenterOfEmpty = 'MultiLineString::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'MultiLineString::ErrorPosOfEmpty';
  
  /** @var TLLPos $coords */
  public readonly array $coords;
  
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
      throw new \SExcept("Erreur: MultiLineString::center() sur une liste vide", self::ErrorCenterOfEmpty);
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
      throw new \SExcept("Erreur: MultiLineString::aPos() sur une liste vide", self::ErrorPosOfEmpty);
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


/** Polygone au sens GeoJSON, cad avec une limite extérieure et éventuellement des limites intérieures ou trous
 *
 * L'extérieur contient au moins 4 positions.
 * Chaque intérieur contient au moins 4 positions et est contenu dans l'extérieur sans l'intersecter.
 * Les intérieurs ne s'intersectent pas 2 à 2 */
class Polygon extends Geometry {
  const ErrorInters = 'Polygon::ErrorInters';

  /** @var TLLPos $coords L 1ère liste de positions correspond à l'anneau extérieur, les autres aux anneaux intérieurs */
  public readonly array $coords;

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
  function gbox(): GBox { return new GBox($this->coords[0]); }
  function ebox(): EBox { return new EBox($this->coords[0]); }
  
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
  
  /** Calcule la surface du polygone.
   *
   * L'anneau extérieur est dans le sens inverse des aiguilles d'une montre, et les trous sont dans le sens des aiguilles
   * d’une montre.
   */
  function area(): float {
    $area = 0;
    foreach ($this->coords as $lpos) {
      $area += LPos::area($lpos);
    }
    return $area;
  }
  
  /** teste si la position est dans le polygone
   * @param TPos $pos */
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
      echo $pol,"->pointInPolygon([$p0[0],$p0[1]])=",($pol->pointInPolygon($p0)?'true':'false'), // correctin Php 8.2
           " / ",($test['result']?'true':'false'),"<br>\n";
    }
  }

  /** liste des segments constituant le polygone
   * @return list<Segment> */
  function segs(): array {
    $segs = [];
    foreach ($this->geoms() as $ls)
      $segs = array_merge($segs, $ls->segs());
    return $segs;
  }
  
  /** teste l'intersection entre les 2 polygones ou multi-polygones */
  function inters(Geometry $geom, bool $verbose=false): bool {
    if (get_class($geom) == __NAMESPACE__.'\Polygon') {
      // Si les boites ne s'intersectent pas alors les polygones non plus
      if (!$this->ebox()->inters($geom->ebox())) {
        if ($verbose)
          echo "Les 2 boites ne s'intersectent pas<br>\n";
        return false;
      }
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
    elseif (get_class($geom) == __NAMESPACE__.'\MultiPolygon') {
      return $geom->inters($this);
    }
    else
      throw new \SExcept(
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


/** Liste de polygones */
class MultiPolygon extends Geometry {
  const ErrorFromGeoArray = 'MultiPolygon::ErrorFromGeoArray';
  const ErrorCenterOfEmpty = 'MultiPolygon::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'MultiPolygon::ErrorPosOfEmpty';
  const ErrorInters = 'MultiPolygon::ErrorInters';

  /** @var TLLLPos $coords */
  public readonly array $coords; // contient une LLLPos

  // utile pour restreindre le type retourné, notamment pour phpstan
  /** @param TGJMultiPolygon $geom */
  static function fromGeoArray(array $geom): MultiPolygon {
    if (($geom['type'] ?? null) == 'MultiPolygon')
      return new MultiPolygon($geom['coordinates']);
    else
      throw new \SExcept("Erreur de MultiPolygon::fromGeoArray(".json_encode($geom).")", self::ErrorFromGeoArray);
  }
  
  /** @param TLLLPos $coords */
  function __construct(array $coords) { $this->coords = $coords; }
  
  function eltTypes(): array { return $this->coords ? ['Polygon'] : []; }
  
  function geoms(): array { // liste des primitives contenues dans l'objet sous la forme d'une liste d'objets
    $geoms = [];
    foreach ($this->coords as $coord)
      $geoms[] = new Polygon($coord);
    return $geoms;
  }
    
  function center(): array {
    if (!$this->coords)
      throw new \SExcept("Erreur: MultiPolygon::center() sur une liste vide", self::ErrorCenterOfEmpty);
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
      throw new \SExcept("Erreur: MultiPolygon::aPos() sur une liste vide", self::ErrorPosOfEmpty);
    return $this->coords[0][0][0];
  }
  
  function gbox(): GBox {
    $gbox = new GBox;
    foreach ($this->coords as $llpos)
      $gbox = $gbox->union(new GBox($llpos));
    return $gbox;
  }
  
  function ebox(): EBox {
    $ebox = new EBox;
    foreach ($this->coords as $llpos)
      $ebox = $ebox->union(new EBox($llpos));
    return $ebox;
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
  
  function area(): float {
    $area = 0.0;
    foreach($this->coords as $llpos) {
      $polygon = new Polygon($llpos);
      $area += $polygon->area();
    }
    return $area;
  }
  
  /** teste si une position est dans un des polygones.
   * @param TPos $pos */
  function pointInPolygon(array $pos): bool {
    foreach ($this->geoms() as $polygon)
      if ($polygon->pointInPolygon($pos))
        return true;
    return false;
  }
  
  /** teste l'intersection entre les 2 polygones ou multi-polygones */
  function inters(Geometry $geom): bool {
    if (get_class($geom) == 'gegeom\Polygon') {
      foreach($this->geoms() as $polygon) {
        if ($polygon->inters($geom)) // intersection entre 2 polygones
          return true;
      }
      return false;
    }
    elseif (get_class($geom) == 'gegeom\MultiPolygon') {
      foreach($this->geoms() as $pol0) {
        foreach ($geom->geoms() as $pol1) {
          if ($pol0->inters($pol1)) // intersection entre 2 polygones
            return true;
        }
      }
      return false;
    }
    else
      throw new \SExcept("Erreur d'appel de MultiPolygon::inters() avec un objet de ".get_class($geom), self::ErrorInters);
  }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe MultiPolygon
  if (!isset($_GET['test']))
    echo "<a href='?test=MultiPolygon'>Test unitaire de la classe MultiPolygon</a><br>\n";
  elseif ($_GET['test']=='MultiPolygon') {
    $mpol = new MultiPolygon([[[[0,0],[1,0],[1,1],[0,0]]]]);
    echo "$mpol ->center()=",json_encode($mpol->center()),"\n";
    $mpol2 = new MultiPolygon([[[[10,10],[11,10],[11,11],[10,10]]]]);
    echo "$mpol\n  ->inters($mpol2) -> ",$mpol->inters($mpol2)?'T':'F'," / F\n";
    $pol2 = new Polygon([[[10,10],[11,10],[11,11],[10,10]]]);
    echo "$mpol\n  ->inters($pol2) -> ",$mpol->inters($pol2)?'T':'F'," / F\n";
    echo "$pol2\n  ->inters($mpol) -> ",$pol2->inters($mpol)?'T':'F'," / F\n";
    
    // cas d'intersection
    $carre10 = new MultiPolygon([[[[0,0],[10,0],[10,10],[0,10],[0,0]]]]);
    $carre10decaleDe5 = new MultiPolygon([[[[5,5],[15,5],[15,15],[5,15],[5,5]]]]);
    echo "$carre10\n  ->inters($carre10decaleDe5) -> ",$carre10->inters($carre10decaleDe5)?'T':'F'," / T\n";
    
    die();
  }
}


/** Liste d'objets géométriques de ka classe Geometry */
class GeometryCollection {
  const ErrorCenterOfEmpty = 'GeometryCollection::ErrorCenterOfEmpty';
  const ErrorPosOfEmpty = 'GeometryCollection::ErrorPosOfEmpty';

  /** @var list<Geometry> $geometries */
  public readonly array $geometries;
  
  /** prend en paramètre une liste d'objets Geometry
   * @param array<int, Geometry> $geometries */
  function __construct(array $geometries) { $this->geometries = $geometries; }
  
  /** @return array{type: 'GeometryCollection', geometries: list<TGJSimpleGeometry>} */
  function asArray(): array {
    return [
      'type'=> 'GeometryCollection',
      'geometries'=> array_map(function(Geometry $g) { return $g->asArray(); }, $this->geometries),
    ];
  }
  
  // génère la réprésentation string GeoJSON
  function __toString(): string { return json_encode($this->asArray()); }
  
  /** retourne la liste des types élémentaires ('Point','LineString','Polygon') contenus dans la géométrie
   * @return list<string> */
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
      throw new \SExcept("Erreur: GeometryCollection::center() sur une liste vide", self::ErrorCenterOfEmpty);
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
      throw new \SExcept("Erreur: GeometryCollection::aPos() sur une liste vide", self::ErrorPosOfEmpty);
    return $this->geometries[0]->aPos();
  }
  
  function gbox(): GBox {
    $gbox = new GBox;
    foreach ($this->geometries as $geom)
      $gbox = $gbox->union($geom->gbox());
    return $gbox;
  }
  
  function ebox(): EBox {
    $ebox = new EBox;
    foreach ($this->geometries as $geom)
      $ebox = $ebox->union($geom->ebox());
    return $ebox;
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
  
  /** Décompose une géométrie en un array de géométries élémentaires (Point/LineString/Polygon)
   * @return list<Point|LineString|Polygon> */
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
    echo "Proj en WebMercator=",$gc->reproject(function(array $pos) { return \coordsys\WebMercator::proj($pos); }),"<br>\n";
    
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

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe Geometry
  if (!isset($_GET['test']))
    echo "<a href='?test=Geometry'>Test unitaire de la classe Geometry</a>\n";
  elseif ($_GET['test']=='Geometry') {
    $RFC_EXAMPLES = [
      'Point'=> '{"type": "Point", "coordinates": [100.0, 0.0]}',
      'LineString'=> '{
         "type": "LineString",
         "coordinates": [
             [100.0, 0.0],
             [101.0, 1.0]
         ]
      }',
      'Polygon No Hole'=> '{
         "type": "Polygon",
         "coordinates": [[[100.0, 0.0],[101.0, 0.0],[101.0, 1.0],[100.0, 1.0],[100.0, 0.0]]]
     }',
     'Polygon with Holes'=> '{
         "type": "Polygon",
         "coordinates": [
             [[100.0, 0.0],[101.0, 0.0],[101.0, 1.0],[100.0, 1.0],[100.0, 0.0]],
             [[100.8, 0.8],[100.8, 0.2],[100.2, 0.2],[100.2, 0.8],[100.8, 0.8]]
         ]
     }',
     'MultiPoint'=> '{
         "type": "MultiPoint",
         "coordinates": [
             [100.0, 0.0],
             [101.0, 1.0]
         ]
     }',
     'MultiLineString'=> '{
         "type": "MultiLineString",
         "coordinates": [
             [
                 [100.0, 0.0],
                 [101.0, 1.0]
             ],
             [
                 [102.0, 2.0],
                 [103.0, 3.0]
             ]
         ]
     }',
     'MultiPolygon'=> '{
         "type": "MultiPolygon",
         "coordinates": [
             [
                 [
                     [102.0, 2.0],
                     [103.0, 2.0],
                     [103.0, 3.0],
                     [102.0, 3.0],
                     [102.0, 2.0]
                 ]
             ],
             [
                 [
                     [100.0, 0.0],
                     [101.0, 0.0],
                     [101.0, 1.0],
                     [100.0, 1.0],
                     [100.0, 0.0]
                 ],
                 [
                     [100.2, 0.2],
                     [100.2, 0.8],
                     [100.8, 0.8],
                     [100.8, 0.2],
                     [100.2, 0.2]
                 ]
             ]
         ]
     }',
     'GeometryCollection'=> '{
         "type": "GeometryCollection",
         "geometries": [{
             "type": "Point",
             "coordinates": [100.0, 0.0]
         }, {
             "type": "LineString",
             "coordinates": [
                 [101.0, 0.0],
                 [102.0, 1.0]
             ]
         }]
     }',
    ]; // les exemples de la RFC (Annex A)
    foreach ($RFC_EXAMPLES as $label => $example) {
      $geom = Geometry::fromGeoArray(json_decode($example, true));
      //echo Yaml::dump([$label => $geom->asArray()], 4);
      $decompose = [];
      foreach ($geom->decompose() as $g)
        $decompose[] = $g->asArray();
      echo Yaml::dump(["decompose($label)" => $decompose], 5);
    }
  }
}
