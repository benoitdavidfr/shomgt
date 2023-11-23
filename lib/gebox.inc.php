<?php
/**
 * définition de classes définissant un BBox avec des coord. géographiques ou euclidiennes
 *
 * La classe abstraite BBox implémente des fonctionnalités génériques valables en coord. géo. comme euclidiennes
 * Des classes héritées concrètes GBox et EBox implémentent les fonctionnalités spécifiques aux coord. géo.
 * ou euclidiennes.
 * Comme dans GeoJSON, on distingue la notion de Point, qui est une primitive géométrique, de la notion de position
 * qui permet de construire les primitives géométriques.
 * Une position est stockée comme un array de 2 ou 3 nombres.
 * On gère aussi une liste de positions comme array de positions et une liste de listes de positions
 * comme array d'array de positions.
 * 
 * Les rectangles à cheval sur l'anti-méridien soulèvent des difficultés particulières.
 * Ils peuvent être pris en compte en gérant les positions à l'Est de l'anti-méridien avec une longitude > 180°.
 *
 * journal: |
 * - 23/11/2023:
 *    - correction d'un bug dans BBox::__construct(), cas llpos
 * - 31/8/2023:
 *   - modif de BBox pour mettre en readonly les prop. $min et $max, modif des méthodes modifiant $min ou $max
 * - 15/8/2023:
 *   - ajout de BBox::includes()
 *   - modification de BBox::__toString()
 *   - intégration dans GBox::__construct() de l'initialisation d'un GBox à partir du format Spatial
 * - 28/7/2022:
 *   - correction suite à analyse PhpStan level 4
 * - 22/5/2022:
 *   - correction d'un bug dans GBox::asGeoJsonBbox()
 * - 29/4/2022:
 *   - création d'un GBox à partir des coins SW et NE et prise en compte du cas où il intersecte l'anti-méridien
 * - 9/3/2019:
 *   - scission de gegeom.inc.php
 * - 7/3/2019:
 *   - création
 * @package gegeom
 */
namespace gegeom;

$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/coordsys.inc.php';
require_once __DIR__.'/pos.inc.php';
require_once __DIR__.'/sexcept.inc.php';

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de chaque classe
  echo "<!DOCTYPE html>\n<html><head><title>gebox@$_SERVER[HTTP_HOST]</title></head><body>\n";
}

/** Boite englobante en coord. géo. ou euclidiennes, chaque position codée comme [lon, lat] ou [x, y]
 *
 * Cette classe est abstraite.
 * 2 classes concrètes en héritent, l'une avec des coord. géographiques, l'autre des coord. euclidiennes
 * Il existe une BBox particulière correspondant à un espace vide. A sa création sans paramètre la BBox est vide.
 */
abstract class BBox {
  const ErrorIncorrectNbOfParams = 'BBox::ErrorIncorrectNbOfParams';
  const ErrorIncorrectParams = 'BBox::ErrorIncorrectParams';
  const ErrorIncorrectPosTypeInBound = 'BBox::ErrorIncorrectPosTypeInBound';
  const ErrorIntersectsWithUndefBBox = 'BBox::ErrorIntersectsWithUndefBBox';
  
  /** @var TPos $min [number, number] ou [] */
  public readonly array $min;
  /** @var TPos $max [number, number] ou [], [] ssi $min == [] */
  public readonly array $max;
  
  /** Soit ne prend pas de paramètre et créée alors une BBox vide,
   * soit prend en paramètre un array de 2 ou 3 nombres (Pos) interprété comme une position,
   * soit prend en paramètre un string dont l'explode donne 2 ou 3 nombres, interprété comme une position,
   * soit un array de 4 ou 6 nombres, soit un string dont l'explode donne 4 ou 6 nombres, interprétés comme 2 pos.
   * soit un array d'array, interprété comme une liste de positions (LPos),
   * soit un array d'array d'array, interprété comme une liste de listes de positions (LLPos),
   * @param string|TPos|TLPos|TLLPos $param
   */
  function __construct(array|string $param=[]) {
    //echo "BBox::__construct(",json_encode($param),")<br>\n";
    if (!$param) {
      $this->min = [];
      $this->max = [];
    }
    elseif (is_array($param) && in_array(count($param), [2,3]) && is_numeric($param[0])) { // 1 pos
      $this->min = [$param[0], $param[1]];
      $this->max = $this->min;
    }
    elseif (is_array($param) && (count($param)==4) && is_numeric($param[0])) { // 2 pos
      $this->min = LPos::min([[$param[0], $param[1]], [$param[2], $param[3]]]);
      $this->max = LPos::max([[$param[0], $param[1]], [$param[2], $param[3]]]);
    }
    elseif (is_array($param) && (count($param)==6) && is_numeric($param[0])) { // 2 pos
      $this->min = LPos::min([[$param[0], $param[1]], [$param[3], $param[4]]]);
      $this->max = LPos::max([[$param[0], $param[1]], [$param[3], $param[4]]]);
    }
    elseif (is_string($param)) {
      $params = explode(',', $param);
      if (in_array(count($params), [2,3])) {
        $this->min = [(float)$params[0], (float)$params[1]];
        $this->max = $this->min;
      }
      elseif (count($params)==4) {
        $lpos = [[(float)$params[0], (float)$params[1]], [(float)$params[2], (float)$params[3]]];
        $this->min = LPos::min($lpos);
        $this->max = LPos::max($lpos);
      }
      elseif (count($params)==6) {
        $lpos = [[(float)$params[0], (float)$params[1]], [(float)$params[3], (float)$params[4]]];
        $this->min = LPos::min($lpos);
        $this->max = LPos::max($lpos);
      }
      else
        throw new \SExcept("Erreur de BBox::__construct(param=$param)", self::ErrorIncorrectParams);
    }
    elseif (LPos::is($param)) { // $param est une liste de positions en cont. au moins une 
      //echo "param est une LPos<br>\n";
      $this->min = LPos::min($param);
      $this->max = LPos::max($param);;
    }
    elseif (LLPos::is($param)) { // $param est une liste de listes de positions en contenant au moins une
      $min = $param[0][0];
      $max = $param[0][0];
      foreach ($param as $lpos) {
        $min = LPos::min([$min, LPos::min($lpos)]);
        $max = LPos::max([$max, LPos::max($lpos)]);
      }
      $this->min = $min;
      $this->max = $max;
    }
    else
      throw new \SExcept("Erreur de BBox::__construct(".json_encode($param).")", self::ErrorIncorrectParams);
  }

  /** renvoit vrai ssi la bbox est vide */
  function empty(): bool { return (count($this->min) == 0); }
  
  /** retourne la BBox contenant à la fois $this et la position
   * @param TPos $pos
   * @return static */
  function bound(array $pos): BBox {
    if (!$pos)
      return $this;
    if (!Pos::is($pos))
      throw new \SExcept("Erreur dans bound sur ".json_encode($pos), self::ErrorIncorrectPosTypeInBound);
    if (!$this->min) {
      $called_class = get_called_class();
      return new $called_class($pos);
    }
    else {
      $called_class = get_called_class();
      return new $called_class([$this->min, $this->max, $pos]);
    }
  }

  /** si $this est indéfini alors le renvoit
   * sinon crée un nouvel objet de la classe appelée avec des coord. arrondies
   * en fonction de la $precision définie dans la classe appelée
   * @return static */
  function round(): BBox {
    if (!$this->min)
      return $this;
    else {
      $called_class = get_called_class();
      $dixpprec = 10 ** $called_class::$precision;
      return new $called_class([
        floor($this->min[0] * $dixpprec) / $dixpprec, floor($this->min[1] * $dixpprec) / $dixpprec, 
        ceil($this->max[0] * $dixpprec) / $dixpprec,  ceil($this->max[1] * $dixpprec) / $dixpprec, 
      ]);
    }
  }
  
  /** @return array<string, TPos> */
  function asArray(): array { return ['min'=> $this->min, 'max'=> $this->max]; }
  
  /** affiche la BBox en utilisant le format GeoJSON d'un Bbox
   * avec des coord. arrondies en fonction de la $precision définie dans la classe appelée */
  function __toString(): string {
    if (!$this->min)
      return '[]';
    $p = (get_called_class())::$precision;
    return sprintf("[%.{$p}f, %.{$p}f, %.{$p}f, %.{$p}f]", $this->min[0], $this->min[1], $this->max[0], $this->max[1]); 
  }
  
  function west(): ?float  { return $this->min ? $this->min[0] : null; }
  function south(): ?float { return $this->min ? $this->min[1] : null; }
  function east(): ?float  { return $this->min ? $this->max[0] : null; }
  function north(): ?float { return $this->min ? $this->max[1] : null; }
  
  /** retourne le centre de la BBox ou [] si elle est vide
   * @return TPos */
  function center(): array {
    return $this->min ? [($this->min[0]+$this->max[0])/2, ($this->min[1]+$this->max[1])/2] : [];
  }
  
  /** retourne un array d'array avec les 5 positions du polygone de la BBox ou [] si elle est vide
   * @return TLLPos */
  function polygon(): array {
    if (!$this->min)
      return [];
    else
      return [[
        [$this->min[0], $this->min[1]],
        [$this->max[0], $this->min[1]],
        [$this->max[0], $this->max[1]],
        [$this->min[0], $this->max[1]],
        [$this->min[0], $this->min[1]],
      ]];
  }
  
  // Retourne l'union de $this et de $b2, la BBox vide est un élément neutre pour l'union
  function unionVerbose(BBox $b2): BBox {
    $u = $this->union($b2);
    echo "BBox::union(b2=$b2)@$this -> $u<br>\n";
    return $u;
  }
  /** Retourne l'union de 2 BBox
  * @param $this $b2
  * @return static
  */
  function union(BBox $b2): BBox {
    if (!$b2->min)
      return $this;
    elseif (!$this->min) {
      return $b2;
    }
    else {
      $called_class = get_called_class();
      return new $called_class([$this->min, $this->max, $b2->min, $b2->max]);
    }
  }
  
  /** intersection de 2 bbox, si $this intersecte $b2 alors retourne le GBox/EBox d'intersection, sinon retourne null.
   *
   * @param $this $b2
   * @return static
   */
  function intersects(BBox $b2): ?BBox {
    if (!$this->min || !$b2->min)
      return null;
    $xmin = max($b2->min[0], $this->min[0]);
    $ymin = max($b2->min[1], $this->min[1]);
    $xmax = min($b2->max[0], $this->max[0]);
    $ymax = min($b2->max[1], $this->max[1]);
    if (($xmax >= $xmin) && ($ymax >= $ymin))
      return new (get_called_class())([$xmin, $ymin, $xmax, $ymax]);
    else
      return null;
  }
  function intersectsVerbose(BBox $b2): ?BBox {
    $i = $this->intersects($b2);
    echo "BBox::intersects(b2=$b2)@$this -> ",$i ? 'true' : 'false',"<br>\n";
    return $i;
  }
  // Test unitaire de la méthode intersects
  static function intersectsTest(): void {
    // cas d'intersection d'un point avec un rectangle
    $b1 = new GBox([[0, 0], [1, 1]]);
    $b2 = new GBox([0, 0]);
    $b1->intersectsVerbose($b2);
    
    // cas de non intersection entre 2 rectangles
    $b1 = new GBox([[0, 0], [1, 1]]);
    $b2 = new GBox([[2, 2], [3, 3]]);
    $b1->intersectsVerbose($b2);
    
    // cas de non intersection entre 2 rectangles
    $b1 = new GBox([[0, 0], [2, 2]]);
    $b2 = new GBox([[1, 1], [3, 3]]);
    $b1->intersectsVerbose($b2);
  }
  
  /** version bouléenne de intersects() */
  function inters(BBox $b2): bool { return $this->intersects($b2) ? true : false; }

  /** teste si $small est strictement inclus dans $this */
  function includes(BBox $small, bool $show=false): bool {
    $result = ($this->min[0] < $small->min[0]) && ($this->min[1] < $small->min[1])
           && ($this->max[0] > $small->max[0]) && ($this->max[1] > $small->max[1]);
    if ($show)
      echo $this,($result ? " includes " : " NOT includes "),$small,"<br>\n";
    return $result;
  }
};

/** BBox en coord. géo., chaque position codée comme [lon, lat]
 *
 * Par convention, on cherche à respecter:
 *   (-180 <= lon <= 180) && (-90 <= lat <= 90)
 * sauf pour les boites à cheval sur l'anti-méridien où:
 *   (-180 <= lonmin <= 180 < lonmax <= 180+360 )
 * Cette convention est différente de celle utilisée par GeoJSON.
 * Toutefois, uGeoJSON génère des bbox avec des coord. qqc, y compris lonmin < -180
 */
class GBox extends BBox {
  const ErrorParamInConstruct = 'GBox::ErrorParamInConstruct';
  const ErrorDistOfEmptyGBox = 'GBox::ErrorDistOfEmptyGBox';
  const ErrorDistanceOfEmptyGBox = 'GBox::ErrorDistanceOfEmptyGBox';
  
  static int $precision = 6; // nbre de chiffres après la virgule à conserver pour les positions
  
  /** ajoute au mécanisme de création de BBox la possibilité de créer une GBox à partir d'un array
   * respectant le format Spatial défini dans MapCat et shomgt.yaml
   *
   * Remplace la méthode statique fromGeoDMd() conservée pour la compatibilité avec le code existant
   * @param string|TPos|TLPos|TLLPos|TMapCatSpatial $param
   */
  function __construct(array|string $param=[]) {
    //echo "GBox::__construct(",json_encode($param),")<br>\n";
    if (is_array($param) && array_key_exists('SW', $param) && array_key_exists('NE', $param)) {
      foreach(['SW','NE'] as $cornerId) {
        if (is_string($param[$cornerId])) {
          $param[$cornerId] = Pos::fromGeoDMd($param[$cornerId]);
        }
        elseif (is_null($param[$cornerId])) {
          throw new \SExcept("Paramètre $cornerId mal défini dans GBox::__construct()", self::ErrorParamInConstruct);
        }
        elseif (!Pos::is($param[$cornerId]))
          throw new \SExcept("Paramètre $cornerId mal défini dans GBox::__construct()", self::ErrorParamInConstruct);
      }
      // cas d'un rectangle intersectant l'anti-méridien
      if ($param['NE'][0] < $param['SW'][0]) // la longitude Est < longitude West
        $param['NE'][0] += 360; // la longitude Est est augmentée de 360° et devient comprise entre 180° et 360+180°
      parent::__construct([$param['SW'], $param['NE']]);
    }
    else {
      parent::__construct($param);
    }
  }
  
  static function constructTest(): void {
    echo "<table border=1><th></th><th>paramètre</th><th>résultat</th>";
    foreach ([
      ['SW'=> "42°39,93'N - 9°00,93'E", 'NE'=> "43°08,95'N - 9°28,64'E", 'descr'=> "ok DM"],
      ['SW'=> "42°39,93'N - 9°00'E", 'NE'=> "43°08,95'N - 9°28,64'E", 'descr'=> "ok DM ss partie décimale"],
      ['SW'=> "30°S - 153°E", 'NE'=> "8°S - 174°W", 'descr'=> "ok DM ss minute"],
      ['SW'=> [9.0155,42.6655], 'NE'=> [9.477333,43.149167], 'descr'=> "ok dd"],
      ['descr'=> "error"],
      ['SW'=> "42°39,93'N - 9°00,93'", 'NE'=> "43°08,95'N - 9°28,64'E", 'descr'=> "error, no match"],
      ['SW'=> 9],
    ] as $rect) {
      echo "<tr><td>";
      try {
        $gbox = new self($rect);
      }
      catch(\SExcept $e) {
        echo "SExcept: {c: ",$e->getSCode(),", m: '",$e->getMessage(),"'}";
        $gbox = null;
      }
      echo "</td><td>"; print_r($rect); echo "</td>";
      echo "<td>$gbox</td></tr>\n";
    }
    echo "</table>\n";
  }
  
  function dLon(): ?float  { return $this->min ? $this->max[0] - $this->min[0] : null; }
  function dLat(): ?float  { return $this->min ? $this->max[1] - $this->min[1] : null; }
 
  /** maintien de la méthode fromGeoDMd() pour conserver la compatibilité avec le code existant
   *
   * @param TMapCatSpatial $spatial */
  static function fromGeoDMd(array $spatial): self { return new self($spatial); }
  
  /** Teste l'intersection avec l'AM */
  function intersectsAntiMeridian(): bool { return ($this->east() > 180); }

  static function intersectsAntiMeridianTest(): void {
    foreach ([
      ['SW'=> "42°39,93'N - 9°00,93'E", 'NE'=> "43°08,95'N - 9°28,64'E"],
      ['SW'=> "30°S - 153°E", 'NE'=> "8°S - 174°W"],
    ] as $rect) {
      print_r($rect); echo " -> ",self::fromGeoDMd($rect)->intersectsAntiMeridian() ? 'T' : 'F',"<br>\n";
      echo self::fromGeoDMd($rect)->translate360West(),"<br>\n";
    }
  }
  
  /** renvoie un array de 4 coord [west, south, east, north] avec east < 180 conforme à la structuration dans GeoJSON
   * @return array<int, float> */
  function asGeoJsonBbox(): array {
    return [$this->west(), $this->south(), ($this->east() > 180) ? $this->east() - 360 : $this->east(), $this->north()];
  }
  
  /** retourne le GBox translaté de 360° vers l'ouest
   * @return static */
  function translate360West(): self {
    $called_class = get_called_class();
    return new $called_class([$this->west()-360, $this->south(), $this->east()-360, $this->north()]);
  }
  
  /** retourne le GBox translaté de 360° vers l'est
   * @return static */
  function translate360East(): self {
    $called_class = get_called_class();
    return new $called_class([$this->west()+360, $this->south(), $this->east()+360, $this->north()]);
  }
  
  /** taille max en degrés de longueur constante (Zoom::Size0 / 360) ou rettourne 0 si la BBox est vide */
  function size(): float {
    if (!$this->min)
      return 0;
    $cos = cos(($this->max[1] + $this->min[1])/2 / 180 * pi()); // cosinus de la latitude moyenne
    return max($this->dlon() * $cos, $this->dlat());
  }
  
  /** distance la plus courte entre les position des 2 GBox, génère une exception si une des 2 BBox est vide
   *
   * N'est pas une réelle distance entre GBox */
  function dist(GBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new \SExcept("Erreur de GBox::dist() avec une des GBox vide", self::ErrorDistOfEmptyGBox);
    $xmin = max($b2->min[0],$this->min[0]);
    $ymin = max($b2->min[1],$this->min[1]);
    $xmax = min($b2->max[0],$this->max[0]);
    $ymax = min($b2->max[1],$this->max[1]);
    if (($xmax >= $xmin) && ($ymax >= $ymin))
      return 0;
    else {
      $cos = cos(($this->max[1] + $this->min[1])/2 / 180 * pi()); // cosinus de la latitude moyenne
      return max(($xmin-$xmax),0)*$cos + max(($ymin-$ymax), 0);
    }
  }
  function distVerbose(GBox $b2): float {
    $d = $this->dist($b2);
    echo "GBox::dist(b2=$b2)@$this -> ",$d,"<br>\n";
    return $d;
  }
  static function distTest(): void {
    $b1 = new GBox([[0,0], [2,2]]);
    $b2 = new GBox([[1,1], [3,3]]);
    $b1->distVerbose($b2);
  }
  
  /** distance entre 2 boites, nulle ssi les 2 boites sont identiques */
  function distance(GBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new \SExcept("Erreur de GBox::distance() avec une des GBox vide", self::ErrorDistanceOfEmptyGBox);
    $cos = cos(($this->max[1] + $this->min[1] + $b2->max[1] + $b2->min[1])/2 / 180 * pi()); // cos de la lat. moyenne
    return max(
      abs($b2->min[0] - $this->min[0]),
      abs($b2->min[1] - $this->min[1]) * $cos,
      abs($b2->max[0] - $this->max[0]),
      abs($b2->max[1] - $this->max[1]) * $cos
    );
  }
  
  /** teste si $small est strictement inclus dans $this
   *
   * redéfinie pour gérer des cas particuliers sur l'antiméridien
   * @param GBox $small */
  function includes(BBox $small, bool $show=false): bool {
    // $small doit avoir pour classe soit GBox soit une sous-classe de GBox
    $classOfSmall = get_class($small);
    //echo "get_class(small)=$classOfSmall<br>\n";
    //echo "class_parents($classOfSmall)=",implode(', ', class_parents($classOfSmall)),"<br>\n";
    if (($classOfSmall <> 'gegeom\GBox') && !in_array('gegeom\GBox', class_parents($classOfSmall)))
      throw new \Exception("Appel interdit GBox::includes($classOfSmall)");
    
    if (!$this->intersectsAntiMeridian())
      return parent::includes($small, $show); // aucun des 2 n'intersecte l'AM
    
    if ($show)
      echo "this intersecte l'AM<br>\n";
    if ($small->intersectsAntiMeridian())
      return parent::includes($small, $show); // les 2 intersectent l'AM

    if ($show) {
      echo "small=$small<br>\n";
      echo "small n'intersecte pas l'AM<br>\n";
    }
    if ($small->east() == 180) {
      if ($show)
        echo "small->east == 180<br>\n";
      return parent::includes($small, $show); // le bord Est de small est l'AM
    }
    else {
      if ($show)
        echo "small->east <> 180<br>\n";
      $small = $small->translate360East();
      return parent::includes($small, $show);
    }
  }
  
  static function includesTest(): void {
    foreach ([
      "cas std d'inclusion"=> ['big'=> new GBox([0, 43, 4, 50]), 'small'=> new GBox([1, 44, 3, 49])],
      "cas std de non inclusion"=> ['big'=> new GBox([0, 43, 4, 49]), 'small'=> new GBox([1, 44, 3, 50])],
      "cas particulier d'inclusion avec la grande qui intersecte l'AM et pas la petite"=> [
        'big' => new GBox([177, 7, 252, 74]),
        'small' => new GBox([-173, 15, -116, 71]),
      ],
      "cas particulier de non inclusion avec la grande qui intersecte l'AM et pas la petite"=> [
        'big' => new GBox([177, 7, 200, 74]),
        'small' => new GBox([-173, 15, -116, 71]),
      ],
      "Cas 6671, le bord Est de l'extension == 180°"=> [
        'big'=> new GBox([143.435716, -23.753623, 183.231767, 4.287820]),
        'small'=> new GBox([146.666667, -20.984333, 180.000000, 0.100000]),
      ],
    ] as $title => $boxes) {
      echo "<em>$title</em><br>\n";
      $boxes['big']->includes($boxes['small'], true);
    }
  }
  
  /** calcule la projection d'un GBox en utilisant $proj qui doit être défini comme projection dans coordsys */
  function proj(string $proj): EBox {
    if (strncmp($proj, 'UTM-', 4)==0) { // cas particulier de l'UTM
      $zone = substr($proj, 4);
      return new EBox([
        \coordsys\UTM::proj($this->min, $zone),
        \coordsys\UTM::proj($this->max, $zone)
      ]);
    }
    else {
      $proj = '\coordsys\\'.$proj;
      return new EBox([
        $proj::proj($this->min),
        $proj::proj($this->max)
      ]);
    }
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe GBox
  if (!isset($_GET['test']))
    echo "<a href='?test=GBox'>Test unitaire de la classe GBox</a><br>\n";
  elseif ($_GET['test']=='GBox') {
    echo "<b>Test de BBox::intersects</b><br>\n";
    BBox::intersectsTest();
    echo "<b>Test de GBox::dist</b><br>\n";
    GBox::distTest();
    echo "<b>Test de GBox::includes</b><br>\n";
    GBox::includesTest();
    echo "<b>Test de GBox::__construct</b><br>\n";
    GBox::constructTest();
    echo "<b>Test de GBox::intersectsAntiMeridian</b><br>\n";
    GBox::intersectsAntiMeridianTest();
  }
}

/** Vérification que includes() peut être appelée avec small objet d'une sous-classe de GBox */
class GBoxSubClass extends GBox {
  static function includesTest(): void {
    foreach ([
      "cas particulier d'inclusion avec la grande qui intersecte l'AM et pas la petite"=> [
        'big' => new GBox([177, 7, 252, 74]),
        'small' => new GBoxSubClass([-173, 15, -116, 71]),
      ],
    ] as $title => $boxes) {
      echo "<em>$title</em><br>\n";
      $boxes['big']->includes($boxes['small'], true);
    }
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe GBoxSubClass
  if (!isset($_GET['test']))
    echo "<a href='?test=GBoxSubClass'>Test unitaire de la classe GBoxSubClass</a><br>\n";
  elseif ($_GET['test']=='GBoxSubClass') {
    echo "<b>Test de GBox::includes</b><br>\n";
    GBoxSubClass::includesTest();
  }
}

/** BBox en coord. projetées euclidiennes, chaque position codée comme [x, y]
 *
 * On fait l'hypothèse que la projection fait correspondre l'axe X à la direction Ouest->Est
 * et l'axe Y à la direction Sud->Nord
 */
class EBox extends BBox {
  const ErrorDistOnEmptyEBox = 'EBox::ErrorDistOnEmptyEBox';
  
  /** nbre de chiffres après la virgule à conserver pour les positions */
  static int $precision = 1;
  
  function dx(): ?float  { return $this->min ? $this->max[0] - $this->min[0] : null; }
  function dy(): ?float  { return $this->min ? $this->max[1] - $this->min[1] : null; }
   
  /** renvoie le rectangle translaté en X */
  function translateInX(float $dx): self {
    return new self([$this->min[0] + $dx, $this->min[1], $this->max[0] + $dx, $this->max[1]]);
  }

  /** taille max en unité ou retourne 0 si la EBox est vide */
  function size(): float {
    if (!$this->min)
      return 0;
    return max($this->dx(), $this->dy());
  }
  
  /** distance min. entre les positions de 2 BBox, génère une erreur si une des 2 BBox est vide */
  function dist(EBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new \SExcept("Erreur de EBox::dist() avec une des EBox vide", self::ErrorDistOnEmptyEBox);
    $xmin = max($b2->min[0],$this->min[0]);
    $ymin = max($b2->min[1],$this->min[1]);
    $xmax = min($b2->max[0],$this->max[0]);
    $ymax = min($b2->max[1],$this->max[1]);
    if (($xmax >= $xmin) && ($ymax >= $ymin))
      return 0;
    else
      return max(($xmin-$xmax),0) + max(($ymin-$ymax), 0);
  }
  function distVerbose(EBox $b2): float {
    $d = $this->dist($b2);
    echo "EBox::dist(b2=$b2)@$this -> ",$d,"<br>\n";
    return $d;
  }
  static function distTest(): void {
    $b1 = new EBox([[0,0], [2,2]]);
    $b2 = new EBox([[1,1], [3,3]]);
    $b1->distVerbose($b2);
  }
  
  /** surface du Bbox */
  function area(): float { return $this->dx() * $this->dy(); }

  /** taux de couverture du Bbox $right par le Bbox $this */
  function covers(EBox $right): float {
    if (!($int = $this->intersects($right)))
      return 0;
    else
      return $int->area()/$right->area();
  }
  
  /** retourne le rectangle dilaté de $dilate */
  function dilate(float $dilate): self {
    $min = [$this->min[0] - $dilate, $this->min[1] - $dilate];
    $max = [$this->max[0] + $dilate, $this->max[1] + $dilate];
    return new self([$min, $max]);
  }
  
  /** calcule les coord.géo. d'un EBox en utilisant $proj qui doit être défini comme projection dans coordsys */
  function geo(string $proj): GBox {
    if (strncmp($proj, 'UTM-', 4)==0) { // cas particulier de l'UTM
      $zone = substr($proj, 4);
      return new GBox([
        \coordsys\UTM::geo($this->min, $zone),
        \coordsys\UTM::geo($this->max, $zone)
      ]);
    }
    else {
      $proj = '\coordsys\\'.$proj;
      return new GBox([
        $proj::geo($this->min),
        $proj::geo($this->max)
      ]);
    }
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe EBox
  require_once __DIR__.'/zoom.inc.php';
  if (!isset($_GET['test'])) {
    echo "<a href='?test=EBox'>Test unitaire de la classe EBox</a><br>\n";
  }
  elseif ($_GET['test']=='EBox') {
    echo "<b>Vérification que GBox::includes() génère une exception si small est un EBox</b><br>\n";
    $big = new GBox([177, 7, 252, 74]);
    $small = new EBox([-173, 15, -116, 71]);
    try{
      $big->includes($small, true); // @phpstan-ignore-line
    }
    catch (\Exception $e) {
      echo "Interception de l'exception: ",$e->getMessage(),"</p>\n";
    }
      
    echo "</p><b>Test de EBox::dist</b><br>\n";
    EBox::distTest();
    echo "</p><b>Test de EBox::geo</b><br>\n";
    $ebox = \Zoom::tileEBox(9, 253, 176);
    echo "$ebox ->geo(WebMercator) = ", $ebox->geo('WebMercator'),"<br>\n";
    echo "<b>Test de GBox::proj()</b><br>\n";
    $gbox = new GBox([-2,48, -1,49]);
    echo "$gbox ->proj(WebMercator) = ", $gbox->proj('WebMercator'),"<br>\n";
    echo "$gbox ->proj(WorldMercator) = ", $gbox->proj('WorldMercator'),"<br>\n";
    echo "$gbox ->proj(Lambert93) = ", $gbox->proj('Lambert93'),"<br>\n";
    
    echo "$gbox ->center() = ", json_encode($gbox->center()),"<br>\n";
    echo "UTM::zone($gbox ->center()) = ", $zone = \coordsys\UTM::zone($gbox->center()),"<br>\n";
    echo "$gbox ->proj(UTM-$zone) = ", $eboxutm = $gbox->proj("UTM-$zone"),"<br>\n";
    echo "$eboxutm ->geo(UTM-$zone) = ", $eboxutm->geo("UTM-$zone"),"<br>\n";
  }
}
