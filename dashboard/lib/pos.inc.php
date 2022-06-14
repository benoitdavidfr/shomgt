<?php
{/*PhpDoc:
name:  pos.inc.php
title: pos.inc.php - définition des classes statiques Pos, LPos, LLPos
functions:
classes:
doc: |
  Comme dans GeoJSON, on distingue la notion de Point, qui est une primitive géométrique, de la notion de position
  qui permet de construire les primitives géométriques.
  Une position est stockée comme un array de 2 ou 3 nombres.
  On gère aussi une liste de positions comme array de positions
  et une liste de listes de positions comme array d'array de positions.
journal: |
  23/12/2020:
    - ajout roundToIntIfPossible()
  17/12/2020:
    - création
*/}
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

  
function roundToIntIfPossible(float $v): float|int { // arrondit si possible comme entier un flottant pour simplifier le Yaml
  static $epsilon = 1e-8; // pour arrondir éventuellement en entier pour la sortie Yaml
  if ($v == 0)
    return (int)$v;
  $r = round($v);
  if (abs(($v-$r)/$v) < $epsilon)
    return (int)$r;
  else
    return $v;
}
if (0) { // Tests unitaires 
  echo "<pre>\n";
  var_dump(['15.00000001' => roundToIntIfPossible(15.00000001)]);
  var_dump(['0' => roundToIntIfPossible(0)]);
  die();
}

class Pos {
  // teste si une variable correspond à une position
  static function is($pos): bool {
    return is_array($pos) && in_array(count($pos),[2,3]) && is_numeric($pos[0] ?? null) && is_numeric($pos[1] ?? null);
  }
};

class LPos {
  // teste si une variable correspond à une liste d'au moins une position
  static function is($lpos): bool { return is_array($lpos) && Pos::is($lpos[0] ?? null); }
};

class LLPos {
  // teste si une variable correspond à une liste de listes de positions dont la première en contient au moins une
  static function is($llpos): bool { return is_array($llpos) && LPos::is($llpos[0] ?? null); }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


foreach ([
  "liste vide"=> [], 
  "une pos"=> [1,2], 
  "une lpos"=> [[1,2],[3,4]], 
  "une llpos"=> [[[1,2],[3,4]],[[5,6],[7,8]]], 
] as $label => $item) {
  echo "$label ",Pos::is($item) ? "est" : "n'est PAS"," une position<br>\n";
  echo "$label ",LPos::is($item) ? "est" : "n'est PAS"," une liste de positions<br>\n";
  echo "$label ",LLPos::is($item) ? "est" : "n'est PAS"," une liste de listes de positions<br>\n";
}
