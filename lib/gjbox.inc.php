<?php
/*PhpDoc:
name: gjbox.inc.php
title: gjbox.inc.php - Bbox à la GeoJSON pour les bbox à cheval sur l'anti-méridien
classes:
doc: |
  La classe GjBox gère un bbox selon les conventions du standard GéoJSON qui spécifie qu'une bbox est définie
  par [west, south, east, north] avec -180 <= west <= 180 et -180 <= east <= 180
  Si le bbox est à cheval sur l'anti-méridien alors west > east sinon west <= east
  La classe GBox ne gère pas l'anti-méridien de cette manière et son utilisation génère de faux Bbox.
  
  Il existe une bbox particulière indéfinie codée par (!$ws && !$en)
  La Terre entière est codée [-180, -90, 180, 90]
journal: |
  7/1/2021:
    correction d'un bug dans GjBox::bound()
  17/12/2020:
    création
includes: [gegeom.inc.php, pos.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/gegeom.inc.php';
require_once __DIR__.'/pos.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: class GjBox
title: class GjBox - Bbox à la GeoJSON
methods:
doc: |
*/
class GjBox {
  protected array $ws=[]; // position WS ou [] ssi bbox indéfinie
  protected array $en=[]; // position EN ou [] ssi bbox indéfinie
  
  /*PhpDoc: methods
  name: __construct
  title: function __construct($p=null) - créée une bbox
  doc: |
    Sans paramètre créée une bbox indéterminée,
    avec, le paramètre est
      soit un array de 2 ou 3 nombres interprété comme une position,
      soit une string contenant de 2 ou 3 nombres, interprétée comme une position,
      soit un array de 4 ou 6 nombres, soit une string contenant 4 ou 6 nombres, interprétés comme les 2 pos WS et EN.
      soit un array d'array, interprété comme une liste de positions qui ne doivent pas être à cheval sur l'anti-méridien,
  */
  function __construct($p=null) {
    if ($p === null) // ne prend pas de paramètre et créée alors une GjBox indéterminée
      return;
    elseif (Pos::is($p)) { // 1 pos
      $this->ws = $this->en = [$p[0]+0, $p[1]+0];
    }
    elseif (is_array($p) && (count($p)==4) && Pos::is([$p[0],$p[1]]) && Pos::is([$p[2],$p[3]])) { // 4 nbre -> 2 pos
      $this->ws = [$p[0]+0, $p[1]+0];
      $this->en = [$p[2]+0, $p[3]+0];
    }
    elseif (is_array($p) && (count($p)==6) && Pos::is([$p[0],$p[1]]) && Pos::is([$p[3],$p[4]])) { // 6 nbre -> 2 pos
      $this->ws = [$p[0]+0, $p[1]+0];
      $this->en = [$p[3]+0, $p[4]+0];
    }
    elseif (is_string($p)) {
      if (preg_match('!^([-.\d]+),([-.\d]+)(,[-.\d]+)?$!', $p, $m) && Pos::is([$m[1], $m[2]])) { // str de 2 ou 3 nbres - 1 pos
        $this->ws = $this->en = [$m[1]+0, $m[2]+0];
      }
      elseif (preg_match('!^([-.\d]+),([-.\d]+),([-.\d]+),([-.\d]+)$!', $p, $m) // string de 4 nbres - 2 pos
      && Pos::is([$m[1], $m[2]]) && Pos::is([$m[3], $m[4]])) {
        $this->ws = [$m[1]+0, $m[2]+0];
        $this->en = [$m[3]+0, $m[4]+0];
      }
      elseif (preg_match('!^([-.\d]+),([-.\d]+),[-.\d]+,([-.\d]+),([-.\d]+),[-.\d]+$!', $p, $m) // str de 6 nbres - 2 pos
      && Pos::is([$m[1], $m[2]]) && Pos::is([$m[3], $m[4]])) {
        $this->ws = [$m[1]+0, $m[2]+0];
        $this->en = [$m[3]+0, $m[4]+0];
      }
      else
        throw new Exception("Erreur de GjBox::__construct('$p')");
    }
    elseif (LPos::is($p)) { // une liste d'au moins une position
      foreach ($p as $i => $pos) {
        if ($i == 0) {
          $this->ws = $this->en = $pos;
        }
        else {
          if (!Pos::is($pos))
            throw new Exception("Erreur de GjBox::__construct(".json_encode($p).")");
          if ($pos[0] < $this->ws[0])
            $this->ws[0] = $pos[0];
          if ($pos[1] < $this->ws[1])
            $this->ws[1] = $pos[1];
          if ($pos[0] > $this->en[0])
            $this->en[0] = $pos[0];
          if ($pos[1] > $this->en[1])
            $this->en[1] = $pos[1];
        }
      }
    }
    else
      throw new Exception("Erreur de GjBox::__construct(".json_encode($p).")");
  }
  
  static function test_new() {
    foreach([
      "null" => null,
      "2 nbres" => [1,2],
      "3 nbres str" => "1,2,3",
      "4 nbres" => [1,2,3,4],
      "4 nbres bis" => [1,'2','3',4],
      "6 nbres str" => "1,2,3,4,5,6",
      "6 nbres str KO" => "1,2,3,4,b,6",
      "6 nbres KO" => [1,2,3,4,'b',6],
      "5 nbres KO" => "1,2,3,4,5",
      "lpos" => [[1,2],[3,4],[5,6]],
      "lpos KO" => [[1,2],[3],[5,6]],
      "lpos KO 2" => [[1,2],[3,'a'],[5,6]],
    ] as $label => $val) {
      try {
        echo "$label -> ",new GjBox($val),"<br>\n";
      } catch (Exception $e) {
        echo $e->getMessage(),"<br>\n";
      }
    }
  }
  
  function asArray(): array { return $this->ws ? [$this->ws[0], $this->ws[1], $this->en[0], $this->en[1]] : []; }
  function __toString(): string { return json_encode($this->asArray()); }
  
  function ws(): array { return $this->ws; }
  function en(): array { return $this->en; }
  
  function asDcmiBox(): array { // utilise les conventions DCMI Bpx pour améliorer l'interopérabilité
    return [
      'southlimit'=> roundToIntIfPossible($this->ws[1]),
      'westlimit'=> roundToIntIfPossible($this->ws[0]),
      'northlimit'=> roundToIntIfPossible($this->en[1]),
      'eastlimit'=> roundToIntIfPossible($this->en[0]),
    ];
  }
  
  function straddlingTheAntimeridian(): ?bool { return !$this->ws ? null : ($this->ws[0] > $this->en[0]); } // west > east
  
  /*PhpDoc: methods
  name: bound
  title: "function bound(array $pos): self - ajoute une position à la bbox et renvoie la bbox modifiée"
  doc: |
    Gère la proximité avec l'anti-méridien.
    Pour les bbox de delta de longitude supérieure à 180°, le résultat peut dépendre de l'ordre d'insertion des positions.
  */
  function bound(array $pos): self {
    if (!Pos::is($pos))
      throw new Exception("Erreur de GjBox::bound(".json_encode($pos).")");
    if (!$this->ws) { // bbox indéterminée
      $this->ws = $this->en = [$pos[0]+0, $pos[1]+0];
      return $this;
    }
    
    // agrandissement de la boite en latitude
    if ($pos[1] < $this->ws[1])
      $this->ws[1] = $pos[1];
    if ( $pos[1] > $this->en[1])
      $this->en[1] = $pos[1];
    // gestion de la longitude
    if (!$this->straddlingTheAntimeridian()) {
      //echo "bbox !straddlingTheAntimeridian<br>\n";
      if (($pos[0] >= $this->ws[0]) && ($pos[0] <= $this->en[0])) // pos[0] dans l'intervalle défini par la bbox
        return $this; // la boite n'est pasmodifiée en longitude
      // dlon est la distance en longitude entre la box et la position sans traverser l'AM
      if ($pos[0] < $this->ws[0])
        $dlon = $this->ws[0] - $pos[0];
      else
        $dlon = $pos[0] - $this->en[0];
      //echo "dlon=$dlon<br>\n";
      // dlonAM est la distance en longitude entre la box et la position en traversant l'AM
      if ($pos[0] < $this->ws[0])
        $dlonAM = (180 - $this->en[0]) + ($pos[0] + 180);
      else
        $dlonAM = (180 - $pos[0]) + ($this->ws[0] + 180);
      //echo "dlonAM=$dlonAM<br>\n";
      if ($dlon < $dlonAM) { // agrandissement sans traverser l'AM
        if ($pos[0] < $this->ws[0])
          $this->ws[0] = $pos[0];
        else
          $this->en[0] = $pos[0];
      }
      else { // agrandissement en traversant l'AM, fabrique un straddlingTheAntimeridian bbox
        if ($pos[0] < $this->ws[0]) // pos à l'E de AM et bbox a l'W de AM
          $this->en[0] = $pos[0];
        else // pos à l'W de AM et bbox a l'E de AM
          $this->ws[0] = $pos[0];
      }
      return $this;
    }
    else { // bbox straddlingTheAntimeridian
      //echo "bbox straddlingTheAntimeridian<br>\n";
      if (($pos[0] >= $this->ws[0]) || ($pos[0] <= $this->en[0])) {
        //echo "pos[0] dans l'intervalle défini par la bbox\n";
        return $this;
      }
      $dlonE = $pos[0] - $this->en[0]; // distance du pos à la bbox en considérant pos à l'East de la bbox
      $dlonW = $this->ws[0] - $pos[0]; // distance du pos à la bbox en considérant pos à l'West de la bbox
      //echo "dlonE=$dlonE, dlonW=$dlonW<br>\n";
      if ($dlonE < $dlonW) {
        $this->en[0] = $pos[0];
      }
      else {
        $this->ws[0] = $pos[0];
      }
      return $this;
    }
  }
  
  static function test_bound() {
    if (0) {
      echo "<b>scénario 1: bbox à Wallis, pt en NC</b><br>\n";
      $bbox = new GjBox;
      foreach ([
        '1er pt à Wallis'=> [-178,0],
        '2e pt à Wallis'=> [-175,0],
        "1er pt en NC"=> [170,0],
        "2e pt en NC dans bbox"=> [171,0],
        "3e pt en NC hors bbox"=> [169,0],
        '3e pt à Wallis dans bbox'=> [-175,0],
        '4e pt à Wallis hors bbox'=> [-170,0],
      ] as $label => $pos)
        echo "$label -> ",$bbox->bound($pos),"<br>\n";
    }
    if (0) {
      echo "<b>scénario 2: bbox en NC, pt à Wallis</b><br>\n";
      $bbox = new GjBox;
      foreach ([
        "1er point en NC"=> [170,0],
        "2e point en NC"=> [175,0],
        "1er point à Wallis"=> [-178,0],
        "2è point à Wallis dans bbox"=> [-179,0],
        "3è point à Wallis hors bbox"=> [-170,0],
        "3è point en NC hors bbox"=> [165,0],
      ] as $label => $pos)
        echo "$label -> ",$bbox->bound($pos),"<br>\n";
    }
    if (0) {
      echo "<b>Scénario 3: bbox initiale en métro</b><br>\n";
      $bbox = new GjBox;
      foreach ([
        "1er point en métro"=> [0,45],
        "2e point en métro"=> [3,45],
        "1er point à Wallis"=> [-168,0],
        "1er point en NC"=> [170,0],
      ] as $label => $pos)
        echo "$label -> ",$bbox->bound($pos),"<br>\n";
    }
    if (0) {
      echo "<b>Scénario 4: bbox initiale en métro</b><br>\n";
      $bbox = new GjBox;
      foreach ([
        "1er point en métro"=> [0,45],
        "2e point en métro"=> [3,45],
        "1er point en NC"=> [170,0],
        "1er point à Wallis"=> [-168,0],
      ] as $label => $pos)
        echo "$label -> ",$bbox->bound($pos),"<br>\n";
    }
    if (0) {
      echo "<b>Scénario 5: métro + NC + Wallis + GLP</b><br>\n";
      $bbox = new GjBox;
      foreach ([
        "1er point en métro"=> [0,45],
        "2e point en métro"=> [3,45],
        "1er point en NC"=> [170,0],
        "1er point à Wallis"=> [-168,0],
        "1er point en Guadeloupe"=> [-60,0],
      ] as $label => $pos)
        echo "$label -> ",$bbox->bound($pos),"<br>\n";
    }
    if (1) {
      echo "<b>Scénario 6: Corse</b><br>\n";
      $bbox = new GjBox;
      foreach ([
        "A - Abords de l'Île-Rousse WS"=> [8.9223333333333, 42.624666666667],
        "A - Abords de l'Île-Rousse EN"=> [8.9753333333333, 42.669333333333],
      ] as $label => $pos)
        echo "$label -> ",$bbox->bound($pos),"<br>\n";
    }
  }
  
  function asGBoxes(): array { // si à cheval sur l'anti-méridien alors Retourne 2 GBox, sinon 1
    if (!$this->straddlingTheAntimeridian()) {
      return [ new GBox([$this->ws[0], $this->ws[1], $this->en[0], $this->en[1]]) ];
    }
    else {
      return [
         new GBox([$this->ws[0], $this->ws[1], $this->en[0]+360, $this->en[1]]), // west de l'anti-méridien
         new GBox([$this->ws[0]-360, $this->ws[1], $this->en[0], $this->en[1]]), // east de l'anti-méridien
      ];
    }
  }
    
  function asGeometry(): Geometry { // retourne un MultiPolygon si le bbox est à cheval sur l'anti-méridien, un Polygon sinon
    $gboxes = $this->asGBoxes();
    if (count($gboxes) == 1) {
      return Geometry::fromGeoJSON(['type'=> 'Polygon', 'coordinates'=> $gboxes[0]->polygon()]);
    }
    else {
      return Geometry::fromGeoJSON([
        'type'=> 'MultiPolygon',
        'coordinates'=> [$gboxes[0]->polygon(), $gboxes[1]->polygon(), ],
      ]);
    }
  }
  
  // ligne WS-EN comme Geometry, un LineString si le bbox n'est pas à cheval sur l'anti-méridien, un MultiLineString s'il l'est
  function asLineWSEN(): Geometry {
    if (!$this->straddlingTheAntimeridian()) {
      return Geometry::fromGeoJSON(['type'=> 'LineString', 'coordinates'=> [$this->ws, $this->en]]);
    }
    else {
      return Geometry::fromGeoJSON([
        'type'=> 'MultiLineString',
        'coordinates'=> [
          [$this->ws, [$this->en[0]+360, $this->en[1]]],
          [[$this->ws[0]-360, $this->ws[1]], $this->en],
        ]
      ]);
    }
  }
  
  function multiPolygonCoords(): array {
    $gboxes = $this->asGBoxes();
    if (count($gboxes) == 1) {
      return [ $gboxes[0]->polygon() ];
    }
    else {
      return [
        $gboxes[0]->polygon(),
        $gboxes[1]->polygon(),
      ];
    }
  }

  function multiLSCoords(): array {
    if (!$this->straddlingTheAntimeridian()) {
      return [[$this->ws, $this->en]];
    }
    else {
      return [
        [$this->ws, [$this->en[0]+360, $this->en[1]]],
        [[$this->ws[0]-360, $this->ws[1]], $this->en],
      ];
    }
  }
  
  static function test_multiLSCoords() {
    echo "<pre>";
    $gjbox[0] = new GjBox([-152.826, -22.652, -152.78266666666667, -22.623666666666665]);
    //echo Yaml::dump(['multiPolygonCoords'=> $gjbox[0]->multiPolygonCoords()], 4, 2);
    echo Yaml::dump(['multiLSCoords'=> $gjbox[0]->multiLSCoords()], 3, 2);

    $gjbox[1] = new GjBox([-152.88433333333333, -22.687, -152.7555, -22.598666666666666]);
    echo Yaml::dump(['multiLSCoords'=> $gjbox[1]->multiLSCoords()], 3, 2);

    echo Yaml::dump(['array_merge'=> array_merge($gjbox[0]->multiLSCoords(), $gjbox[1]->multiLSCoords())], 3, 2);
  }
  
  // EBox en WebMercator du bbox
  function wembox(): EBox {
    $gboxes = $this->asGBoxes();
    $gbox = $gboxes[0];
    return $gbox->proj('WebMercator');
  }
  
  static function ofGeometry(Geometry $geom): self { // calcule le GjBox d'une géométrie, à AMELIORER
    switch ($geom->type()) {
      case 'Point': return new self($geom->coords());
      case 'MultiPoint': {
        $bbox = new self;
        foreach($geom->coords() as $pos)
          $bbox->bound($pos);
        return $bbox;
      }
      case 'LineString': return new self($geom->coords());
      case 'MultiLineString': {
        foreach($geom->coords() as $i => $lpos) {
          if ($i==0)
            $bbox = new self($lpos);
          else {
            foreach ($lpos as $pos)
              $bbox->bound($pos);
          }
        }
        return $bbox;
      }
      case 'Polygon': return new self($geom->coords()[0]);
      case 'MultiPolygon': {
        foreach($geom->coords() as $i => $llpos) {
          if ($i==0)
            $bbox = new self($llpos[0]);
          else {
            foreach ($llpos[0] as $pos)
              $bbox->bound($pos);
          }
        }
        return $bbox;
      }
      case 'GeometryCollection': throw new Exception("cas GeometryCollection de GjBox::ofGeometry() à faire");
    }
  }
  
  /*function intersects(self $right): bool { // test d'intersection de 2 boites
    $i = $this->intersects2($right);
    echo "$this ->intersects($right) -> ",$i ? "true\n" : "false\n";
    return $i;
  }
  function intersects2(self $right): bool {
    if (($right->northlimit >= $this->southlimit) && ($this->northlimit >= $right->southlimit)) {
      if (($right->eastlimit >= $this->westlimit)  && ($this->eastlimit >= $right->westlimit))
        return true;
      elseif ((($right->eastlimit == 180) && ($this->westlimit == -180)) || (($this->eastlimit == 180) && ($right->westlimit == -180)))
        return true;
      else
        return false;
    }
    else
      return false;
  }*/
};


if (__FILE__ <> $_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME']) return; // Tests unitaires de la classe 


echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>gjbox</title></head><body>\n";

if (!isset($_GET['test'])) {
  echo "<a href='?test=test_new'>test_new</a><br>\n";
  echo "<a href='?test=test_bound'>test_bound</a><br>\n";
  echo "<a href='?test=test_multiLSCoords'>test_multiLSCoords</a><br>\n";
}
else {
  $test = $_GET['test'];
  GjBox::$test();
}
die();
