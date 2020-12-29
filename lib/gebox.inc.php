<?php
{/*PhpDoc:
name:  gebox.inc.php
title: gebox.inc.php - définition de classes définissant un BBox avec des coord. géographiques ou euclidiennes
functions:
classes:
doc: |
  La classe abstraite BBox implémente des fonctionnalités génériques valables en coord. géo. comme euclidiennes
  Des classes héritées concrètes GBox et EBox implémentent les fonctionnalités spécifiques aux coord. géo.
  ou euclidiennes.
  Comme dans GeoJSON, on distingue la notion de Point, qui est une primitive géométrique, de la notion de position
  qui permet de construire les primitives géométriques.
  Une position est stockée comme un array de 2 ou 3 nombres.
  On gère aussi une liste de positions comme array de positions et une liste de listes de positions
  comme array d'array de positions.
  La classe GBox soulève des difficultés pour les objets a proximité de l'anti-méridien, il vaut alors mieux utiliser la classe GjBox.
journal: |
  9/3/2019:
  - scission de gegeom.inc.php
  7/3/2019:
  - création
includes: [coordsys.inc.php, pos.inc.php, zoom.inc.php]
*/}
require_once __DIR__.'/coordsys.inc.php';
require_once __DIR__.'/pos.inc.php';

// teste si une variable correspond à une position
//function is_pos($pos): bool { return is_array($pos) && isset($pos[0]) && is_numeric($pos[0]); }

// teste si une variable correspond à une liste d'au moins une position
//function is_Lpos($lpos): bool { return is_array($lpos) && isset($lpos[0]) && is_pos($lpos[0]); }

// teste si une variable correspond à une liste de listes de positions en contenant au moins une
// peut contenir des listes vides avant de contenir une liste non vide de positions
/*function is_LLpos($llpos): bool {
  if (!is_array($llpos)) // si ce n'est pas un array alors ne c'est pas une liste
    return false;
  foreach ($llpos as $lpos) {
    if (is_Lpos($lpos)) // si un élément de la liste est une liste de positions alors ok
      return true;
    elseif ($lpos) // sinon si un des éléments est une liste non vide alors KO
      return false;
  }
  return false; // sinon KO
}*/

{/*PhpDoc: classes
name: BBox
title: abstract class BBox - Gestion d'une BBox en coord. géo. ou euclidiennes, chaque point codé comme [lon, lat] ou [x, y]
doc: |
  Cette classe est abstraite.
  2 classes concrètes en héritent, l'une avec des coord. géographiques, l'autre des coord. euclidiennes
  Il existe une BBox particulière qui est indéfinie, on peut l'interpréter comme l'espace entier
  A sa création sans paramètre la BBox est indéfinie
*/}
abstract class BBox {
  protected $min=[]; // [number, number] ou []
  protected $max=[]; // [number, number] ou [], [] ssi $min == []
  
  // Soit ne prend pas de paramètre et créée alors une BBox indéterminée,
  // soit prend en paramètre un array de 2 ou 3 nombres interprété comme une position,
  // soit prend en paramètre un string dont l'explode donne 2 ou 3 nombres, interprété comme une position,
  // soit un array de 4 ou 6 nombres, soit un string dont l'explode donne 4 ou 6 nombres, interprétés comme 2 pos.
  // soit un array d'array, interprété comme une liste de positions,
  // soit un array d'array d'array, interprété comme une liste de listes de positions,
  function __construct(...$params) {
    $this->min = [];
    $this->max = [];
    if (count($params) == 0)
      return;
    if (count($params) <> 1)
      throw new Exception("Erreur trop de paramètres pour BBox::__construct()");
    $param = $params[0];
    if (is_array($param) && in_array(count($param), [2,3]) && is_numeric($param[0])) // 1 pos
      $this->bound($param);
    elseif (is_array($param) && (count($param)==4) && is_numeric($param[0])) { // 2 pos
      $this->bound([$param[0], $param[1]]);
      $this->bound([$param[2], $param[3]]);
    }
    elseif (is_array($param) && (count($param)==6) && is_numeric($param[0])) { // 2 pos
      $this->bound([$param[0], $param[1]]);
      $this->bound([$param[3], $param[4]]);
    }
    elseif (is_string($param)) {
      $params = explode(',', $param);
      if (in_array(count($params), [2,3]))
        $this->bound([(float)$params[0], (float)$params[1]]);
      elseif (count($params)==4) {
        $this->bound([(float)$params[0], (float)$params[1]]);
        $this->bound([(float)$params[2], (float)$params[3]]);
      }
      elseif (count($params)==6) {
        $this->bound([(float)$params[0], (float)$params[1]]);
        $this->bound([(float)$params[3], (float)$params[4]]);
      }
      else
       throw new Exception("Erreur de BBox::__construct(".json_encode($param).")");
    }
    elseif (Lpos::is($param)) { // $param est une liste de positions en cont. au moins une 
      foreach ($param as $pos)
        $this->bound($pos);
    }
    elseif (LLpos::is($param)) { // $param est une liste de listes de positions en contenant au moins une
      foreach ($param as $lpos)
        foreach ($lpos as $pos)
          $this->bound($pos);
    }
    else
      throw new Exception("Erreur de BBox::__construct(".json_encode($param).")");
  }

  function defined(): bool { return (count($this->min) <> 0); }
  
  // intègre une position à la BBox, renvoie la BBox modifiée
  function bound(array $pos): BBox {
    if (!$pos)
      return $this;
    if (!is_numeric($pos[0]) || !is_numeric($pos[1]))
      throw new Exception("Erreur dans bound sur ".json_encode($pos));
    if (!$this->min) {
      $this->min = $pos;
      $this->max = $pos;
    } else {
      $this->min = [ min($this->min[0], $pos[0]), min($this->min[1], $pos[1])];
      $this->max = [ max($this->max[0], $pos[0]), max($this->max[1], $pos[1])];
    }
    return $this;
  }

  // crée un nouvel objet de la classe appelée avec des coord. arrondies
  // en fonction de la $precision définie dans la classe appelée
  function round() {
    $called_class = get_called_class();
    if (!$this->min)
      return new $called_class;
    else
      return new $called_class([
        round($this->min[0], $called_class::$precision), round($this->min[1], $called_class::$precision),
        round($this->max[0], $called_class::$precision), round($this->max[1], $called_class::$precision)
      ]);
  }
  
  function asArray() { return ['min'=> $this->min, 'max'=> $this->max]; }
  
  // affiche la BBox avec des coord. arrondies
  function __toString(): string {
    if (!$this->min)
      return '{}';
    else
      return json_encode(['min'=> $this->round()->min, 'max'=> $this->round()->max]);
  }
  
  function west(): ?float  { return $this->min ? $this->min[0] : null; }
  function south(): ?float { return $this->min ? $this->min[1] : null; }
  function east(): ?float  { return $this->min ? $this->max[0] : null; }
  function north(): ?float { return $this->min ? $this->max[1] : null; }
  
  function setWest(float $val)  { $this->min[0] = $val; }
  function setSouth(float $val) { $this->min[1] = $val; }
  function setEast(float $val)  { $this->max[0] = $val; }
  function setNorth(float $val) { $this->max[1] = $val; }
  
  // retourne le centre de la BBox ou [] si elle est indéterminée
  function center(): array {
    return $this->min ? [($this->min[0]+$this->max[0])/2, ($this->min[1]+$this->max[1])/2] : [];
  }
  
  // retourne un array d'array avec les 5 positions du polygone de la BBox ou [] si elle est indéterminée
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
  
  // modifie $this pour qu'il soit l'union de $this et de $b2, renvoie $this
  // la BBox indéterminée est un élément neutre pour l'union
  function unionVerbose(BBox $b2): BBox {
    $u = $this->union($b2);
    echo "BBox::union(b2=$b2)@$this -> $u<br>\n";
    return $u;
  }
  function union(BBox $b2): BBox {
    if (!$b2->min)
      return $this;
    elseif (!$this->min) {
      $this->min = $b2->min;
      $this->max = $b2->max;
      return $this;
    }
    else {
      $this->min[0] = min($this->min[0], $b2->min[0]);
      $this->min[1] = min($this->min[1], $b2->min[1]);
      $this->max[0] = max($this->max[0], $b2->max[0]);
      $this->max[1] = max($this->max[1], $b2->max[1]);
      return $this;
    }
  }
  
  // intersection de 2 bbox, génère une erreur si une des 2 BBox est indéfinie
  // si $this intersecte $b2 retourne le GBox/EBox d'intersection, sinon retourne null
  function intersectsVerbose(BBox $b2) {
    $i = $this->intersects($b2);
    echo "BBox::intersects(b2=$b2)@$this -> ",$i ? 'true' : 'false',"<br>\n";
    return $i;
  }
  function intersects(BBox $b2) {
    if (!$this->min || !$b2->min)
      throw new Exception("Erreur intersection avec une des BBox indéterminée");
    $xmin = max($b2->min[0], $this->min[0]);
    $ymin = max($b2->min[1], $this->min[1]);
    $xmax = min($b2->max[0], $this->max[0]);
    $ymax = min($b2->max[1], $this->max[1]);
    $called_class = get_called_class();
    if (($xmax >= $xmin) && ($ymax >= $ymin))
      return new $called_class([$xmin, $ymin, $xmax, $ymax]);
    else
      return null;
  }
  // Test unitaire de la méthode intersects
  static function intersectsTest() {
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
  
  // version bouléenne de intersects()
  function inters(BBox $b2): bool { return $this->intersects($b2) ? true : false; }
};

{/*PhpDoc: classes
name: GBox
title: class GBox extends BBox - Gestion d'une BBox en coord. géo., chaque point codé comme [lon, lat]
doc: |
  (-180 <= lon <= 180) && (-90 <= lat <= 90)
  sauf pour les boites à cheval sur l'antiméridien où:
    (-180 <= lonmin <= 180) && (lonmin <= lonmax <= 180+360 )
*/}
class GBox extends BBox {
  static $precision = 6; // nbre de chiffres après la virgule à conserver pour les positions
  
  function dLon(): ?float  { return $this->min ? $this->max[0] - $this->min[0] : null; }
  function dLat(): ?float  { return $this->min ? $this->max[1] - $this->min[1] : null; }
  
  // taille max en degrés de longueur constante (Zoom::$size0 / 360) ou lève une exception si la BBox est indéterminée
  function size(): float {
    if (!$this->min)
      throw new Exception("Erreur de GBox::size()  sur une GBox indéterminée");
    $cos = cos(($this->max[1] + $this->min[1])/2 / 180 * pi()); // cosinus de la latitude moyenne
    return max($this->dlon() * $cos, $this->dlat());
  }
  
  // distance la plus courte entre les points des 2 GBox, génère une erreur si une des 2 BBox est indéterminée
  // N'est pas une réelle distance entre GBox
  function distVerbose(GBox $b2): float {
    $d = $this->dist($b2);
    echo "GBox::dist(b2=$b2)@$this -> ",$d,"<br>\n";
    return $d;
  }
  function dist(GBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new Exception("Erreur de GBox::dist() avec une des GBox indéterminée");
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
  static function distTest() {
    $b1 = new GBox([[0,0], [2,2]]);
    $b2 = new GBox([[1,1], [3,3]]);
    $b1->distVerbose($b2);
  }
  
  // distance entre 2 boites, nulle ssi les 2 boites sont identiques
  function distance(GBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new Exception("Erreur de GBox::distance() avec une des GBox indéterminée");
    $cos = cos(($this->max[1] + $this->min[1] + $b2->max[1] + $b2->min[1])/2 / 180 * pi()); // cos de la lat. moyenne
    return max(
      abs($b2->min[0] - $this->min[0]),
      abs($b2->min[1] - $this->min[1]) * $cos,
      abs($b2->max[0] - $this->max[0]),
      abs($b2->max[1] - $this->max[1]) * $cos
    );
  }
  
  // calcule la projection d'un GBox en utilisant $proj qui doit être défini comme projection dans coordsys
  function proj(string $proj): EBox {
    if (strncmp($proj, 'UTM-', 4)==0) { // cas particulier de l'UTM
      $zone = substr($proj, 4);
      return new EBox([
        UTM::proj($zone, $this->min),
        UTM::proj($zone, $this->max)
      ]);
    }
    else
      return new EBox([
        $proj::proj($this->min),
        $proj::proj($this->max)
      ]);
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
  }
}

{/*PhpDoc: classes
name: EBox
title: class EBox extends BBox - Gestion d'une BBox en coord. projetées euclidiennes, chaque point codé comme [x, y]
doc: |
  On fait l'hypothèse que la projection fait correspondre l'axe X à la direction Ouest->Est
  et l'axe Y à la direction Sud->Nord
*/}
class EBox extends BBox {
  static $precision = 1; // nbre de chiffres après la virgule à conserver pour les positions
  
  function dx(): ?float  { return $this->min ? $this->max[0] - $this->min[0] : null; }
  function dy(): ?float  { return $this->min ? $this->max[1] - $this->min[1] : null; }
   
  // taille max en unité ou lève une exception si la EBox est indéterminée
  function size(): float {
    if (!$this->min)
      throw new Exception("Erreur de EBox::size()  sur une EBox indéterminée");
    return max($this->dx(), $this->dy());
  }
  
  // distance entre 2 BBox, génère une erreur si une des 2 BBox est indéterminée
  function distVerbose(EBox $b2): float {
    $d = $this->dist($b2);
    echo "EBox::dist(b2=$b2)@$this -> ",$d,"<br>\n";
    return $d;
  }
  function dist(EBox $b2): float {
    if (!$this->min || !$b2->min)
      throw new Exception("Erreur de EBox::dist() avec une des EBox indéterminée");
    $xmin = max($b2->min[0],$this->min[0]);
    $ymin = max($b2->min[1],$this->min[1]);
    $xmax = min($b2->max[0],$this->max[0]);
    $ymax = min($b2->max[1],$this->max[1]);
    if (($xmax >= $xmin) && ($ymax >= $ymin))
      return 0;
    else
      return max(($xmin-$xmax),0) + max(($ymin-$ymax), 0);
  }
  static function distTest() {
    $b1 = new EBox([[0,0], [2,2]]);
    $b2 = new EBox([[1,1], [3,3]]);
    $b1->distVerbose($b2);
  }
  
  // surface du Bbox
  function area(): float { return $this->dx() * $this->dy(); }

// taux de couverture du Bbox $right par le Bbox $this
  function covers(Bbox $right): float {
    if (!($int = $this->intersects($right)))
      return 0;
    else
      return $int->area()/$right->area();
  }
  
  // calcule les coord.géo. d'un EBox en utilisant $proj qui doit être défini comme projection dans coordsys
  function geo(string $proj): GBox {
    if (strncmp($proj, 'UTM-', 4)==0) { // cas particulier de l'UTM
      $zone = substr($proj, 4);
      return new GBox([
        UTM::geo($zone, $this->min),
        UTM::geo($zone, $this->max)
      ]);
    }
    else
      return new GBox([
        $proj::geo($this->min),
        $proj::geo($this->max)
      ]);
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe EBox
  require_once __DIR__.'/zoom.inc.php';
  if (!isset($_GET['test']))
    echo "<a href='?test=EBox'>Test unitaire de la classe EBox</a><br>\n";
  elseif ($_GET['test']=='EBox') {
    echo "Test de EBox::dist<br>\n";
    EBox::distTest();
    echo "Test de EBox::geo<br>\n";
    $ebox = Zoom::tileEBox(9, 253, 176);
    echo "$ebox ->geo(WebMercator) = ", $ebox->geo('WebMercator'),"<br>\n";
    echo "<b>Test de GBox::proj()</b><br>\n";
    $gbox = new GBox([-2,48, -1,49]);
    echo "$gbox ->proj(WebMercator) = ", $gbox->proj('WebMercator'),"<br>\n";
    echo "$gbox ->proj(WorldMercator) = ", $gbox->proj('WorldMercator'),"<br>\n";
    echo "$gbox ->proj(Lambert93) = ", $gbox->proj('Lambert93'),"<br>\n";
    
    echo "$gbox ->center() = ", json_encode($gbox->center()),"<br>\n";
    echo "UTM::zone($gbox ->center()) = ", $zone = UTM::zone($gbox->center()),"<br>\n";
    echo "$gbox ->proj(UTM-$zone) = ", $eboxutm = $gbox->proj("UTM-$zone"),"<br>\n";
    echo "$eboxutm ->geo(UTM-$zone) = ", $eboxutm->geo("UTM-$zone"),"<br>\n";
  }
}

