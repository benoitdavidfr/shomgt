<?php
namespace gegeom;
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
  31/7/2022:
    - ajout déclarations PhpStan pour level 6
  23/12/2020:
    - ajout roundToIntIfPossible()
  17/12/2020:
    - création
*/}
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

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
if (0) { // @phpstan-ignore-line // Tests unitaires 
  echo "<pre>\n";
  var_dump(['15.00000001' => roundToIntIfPossible(15.00000001)]);
  var_dump(['0' => roundToIntIfPossible(0)]);
  die();
}


{/*PhpDoc: classes
name: LElts
title: class LElts - Fonctions de gestion de liste d'éléments
*/}
class LElts {
  /** Nbre d'élts d'une liste de listes d'élts
   * @param array<int, array<int, mixed>> $llelts */
  static function LLcount(array $llelts): int {
    $nbElts = 0;
    foreach ($llelts as $lelts)
      $nbElts += count($lelts);
    return $nbElts;
  }

  /** Nbre d'élts d'une liste de listes de listes d'élts
   * @param array<int, array<int, array<int, mixed>>> $lllelts */
  static function LLLcount(array $lllelts): int {
    $nbElts = 0;
    foreach ($lllelts as $llelts)
      $nbElts += self::LLcount($llelts);
    return $nbElts;
  }
}


// Fonctions sur les positions représentée par une liste de 2 nombres
class Pos {
  const GEODMD_PATTERN = '!^(\d+)°((\d\d)(,(\d+))?\')?(N|S) - (\d+)°((\d\d)(,(\d+))?\')?(E|W)$!';
  
  const ErrorParamInFromGeoDMd = 'Pos::ErrorParamInFromGeoDMd';
  
  // teste si une variable correspond à une position
  static function is(mixed $pos): bool {
    return is_array($pos) && in_array(count($pos),[2,3]) && is_numeric($pos[0] ?? null) && is_numeric($pos[1] ?? null);
  }
  
  /** décode une position en coords géo. degré minutes
   * @return TPos */
  static function fromGeoDMd(string $geoDMd): array {
    if (!preg_match(self::GEODMD_PATTERN, $geoDMd, $matches))
      throw new \SExcept("No match in Pos::fromGeoDMd($geoDMd)", self::ErrorParamInFromGeoDMd);
    //echo "<pre>matches="; print_r($matches); echo "</pre>\n";
    $lat = ($matches[6]=='N' ? 1 : -1) * 
      ( intval($matches[1])
        + ( ($matches[3] ? intval($matches[3]) : 0)
            + ($matches[5] ? floatval(".$matches[5]") : 0)
          ) / 60
      );
    //echo "lat=$lat";
    $lon = ($matches[12]=='E' ? 1 : -1) * 
      ( intval($matches[7])
        + ( ($matches[9] ? intval($matches[9]) : 0)
            + ($matches[11] ? floatval(".$matches[11]") : 0)
          ) / 60
      );
    //echo ", lon=$lon";
    return [$lon, $lat];
  }
  
  // Formatte une coord. lat ou lon
  static function formatCoordInDMd(float $coord, int $nbposMin): string {
    $min = number_format(($coord-floor($coord))*60, $nbposMin, ','); // minutes formattées
    //echo "min=$min<br>\n";
    if ($nbposMin <> 0) {
      if (preg_match('!^\d,!', $min)) // si il n'y a qu'un seul chiffre avant la virgule
        $min = '0'.$min; // alors je rajoute un zéro avant
    }
    elseif (preg_match('!^\d$!', $min)) // si il n'y a qu'un seul chiffre avant la virgule
      $min = '0'.$min; // alors je rajoute un zéro avant

    $string = sprintf("%d°%s'", floor($coord), $min);
    return $string;
  }
  
  /** Formate une position (lon,lat) en lat,lon degrés, minutes décimales
   * $resolution est la résolution de la position en degrés à conserver
   * @param TPos $pos */
  static function formatInGeoDMd(array $pos, float $resolution): string {
    //return sprintf("[%f, %f]",$pos[0], $pos[1]);
    $lat = $pos[1];
    $lon = $pos[0];
    if ($lon > 180)
      $lon -= 360;
    
    $resolution *= 60;
    //echo "resolution=$resolution<br>\n";
    //echo "log10=",log($resolution,10),"<br>\n";
    $nbposMin = ceil(-log($resolution,10));
    if ($nbposMin < 0)
      $nbposMin = 0;
    //echo "nbposMin=$nbposMin<br>\n";
    
    return self::formatCoordInDMd(abs($lat), $nbposMin).(($lat >= 0) ? 'N' : 'S')
      .' - '.self::formatCoordInDMd(abs($lon), $nbposMin).(($lon >= 0) ? 'E' : 'W');
  }

  /**
  * @param TPos $a
  * @param TPos $b
  */
  static function distance(array $a, array $b): float {
    return sqrt(($b[0]-$a[0])*($b[0]-$a[0]) + ($b[1]-$a[1])*($b[1]-$a[1]));
  }
};

// Fonctions sur les listes de positions représentée par une liste de Pos
class LPos {
  const ErrorCenterOfEmptyLPos = 'Pos::ErrorCenterOfEmptyLPos';

  // teste si une variable correspond à une liste d'au moins une position
  static function is(mixed $lpos): bool { return is_array($lpos) && Pos::is($lpos[0] ?? null); }

  /** Retourne la position avec le min des positions en paraaètres pour chaque coordonnée
  * @param TLPos $lpos
  * @return TPos */
  static function min(array $lpos): array {
    if (!$lpos)
      return [];
    else {
      $x = min(array_map(function(array $pos): float {return $pos[0]; }, $lpos));
      $y = min(array_map(function(array $pos): float {return $pos[1]; }, $lpos));
      return [$x, $y];
    }
  }
  
  /** Retourne la position avec le max des positions en paraaètres pour chaque coordonnée
  * @param TLPos $lpos
  * @return TPos */
  static function max(array $lpos): array {
    if (!$lpos)
      return [];
    else {
      $x = max(array_map(function(array $pos): float {return $pos[0]; }, $lpos));
      $y = max(array_map(function(array $pos): float {return $pos[1]; }, $lpos));
      return [$x, $y];
    }
  }
  
  /** calcule le centre d'une liste de positions, génère une exception si la liste est vide
  * @param TLPos $lpos
  * @return TPos
  */
  static function center(array $lpos): array {
    if (!$lpos)
      throw new \SExcept("Erreur: LPos::center() d'une liste de positions vide", self::ErrorCenterOfEmptyLPos);
    $c = [0, 0];
    $nbre = 0;
    foreach ($lpos as $pos) {
      $c[0] += $pos[0];
      $c[1] += $pos[1];
      $nbre++;
    }
    return [$c[0]/$nbre, $c[1]/$nbre];
  }
  
  /** reprojète une liste de positions et en retourne la liste
  * @param TLPos $lpos
  * @return TLPos
  */
  static function reproj(callable $reprojPos, array $lpos): array { return array_map($reprojPos, $lpos); }
};

// Fonctions sur les listes de listes de positions représentée par une liste de LPos
class LLPos {
  // teste si une variable correspond à une liste de listes de positions dont la première en contient au moins une
  static function is(mixed $llpos): bool { return is_array($llpos) && LPos::is($llpos[0] ?? null); }

  /** reprojète une liste de liste de positions et en retourne la liste
  * @param TLLPos $llpos
  * @return TLLPos
  */
  static function reproj(callable $reprojPos, array $llpos): array {
    $coords = [];
    foreach ($llpos as $i => $lpos)
      $coords[] = LPos::reproj($reprojPos, $lpos);
    return $coords;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;

echo Pos::formatInGeoDMd([0.5647, -12.5437], 1e-5),"<br><br>\n";
echo Pos::formatInGeoDMd([-4.095260926966, 47.215410583557], 0.04),"<br><br>\n";

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

echo '<pre>',Yaml::dump(['LPos::min()' => LPos::min([[10,20],[25,15]])]),"</pre>\n";
echo '<pre>',Yaml::dump(['LPos::max()' => LPos::max([[10,20],[25,15]])]),"</pre>\n";

echo '<h3>Test LPos::reproj()</h3><pre>',
  Yaml::dump(LPos::reproj(function(array $pos): array { return [$pos[0]+1, $pos[1]+1]; }, [[10,0], [0,10]])),"</pre>\n";

echo '<h3>Test LLPos::reproj()</h3><pre>',
    Yaml::dump(LLPos::reproj(function(array $pos): array { return [$pos[0]+1, $pos[1]+1]; }, [[[10,0], [0,10]]])),"</pre>\n";