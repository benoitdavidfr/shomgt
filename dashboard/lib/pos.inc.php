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
  const GEOCOORDS_PATTERN = '!^(\d+)°((\d\d)(,(\d+))?\')?(N|S) - (\d+)°((\d\d)(,(\d+))?\')?(E|W)$!';
  
  const ErrorParamInFromGeoCoords = 'Pos::ErrorParamInFromGeoCoords';
  
  // teste si une variable correspond à une position
  static function is($pos): bool {
    return is_array($pos) && in_array(count($pos),[2,3]) && is_numeric($pos[0] ?? null) && is_numeric($pos[1] ?? null);
  }
  
  static function fromGeoCoords(string $geocoords): array { // décode un point en coords géo. degré minutes
    if (!preg_match(self::GEOCOORDS_PATTERN, $geocoords, $matches))
      throw new SExcept("No match in Pos::fromGeoCoords($geocoords)", self::ErrorParamInFromGeoCoords);
    //echo "<pre>matches="; print_r($matches); echo "</pre>\n";
    $lat = ($matches[6]=='N' ? 1 : -1) * 
      ($matches[1] + (($matches[3] ? $matches[3] : 0) + ($matches[5] ? ".$matches[5]" : 0))/60);
    //echo "lat=$lat";
    $lon = ($matches[12]=='E' ? 1 : -1) * 
      ($matches[7] + (($matches[9] ? $matches[9] : 0) + ($matches[11] ? ".$matches[11]" : 0))/60);
    //echo ", lon=$lon";
    return [$lon, $lat];
  }
  
  // Formate une coord. lat ou lon
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
  
  // Formate une position (lon,lat) en lat,lon degrés, minutes décimales
  static function formatInDMd(array $pos, float $resolution): string {
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

echo Pos::formatInDMd([0.5647, -12.5437], 1e-5),"<br><br>\n";
echo Pos::formatInDMd([-4.095260926966, 47.215410583557], 0.04),"<br><br>\n";

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
