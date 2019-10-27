<?php
/*PhpDoc:
name: ontop.inc.php
title: ontop.inc.php - chgt d'ordre des GT dans les couches pour respecter les contraintes définies dans updt.yaml
classes:
doc: |
  La classe OnTop définit des méthodes pour effectuer le changement d'ordre des couches.
  Elle utilise le fichier updt.yaml
  Le code de cette classe est testé par le code en fin de ce fichier.
journal: |
  22/9/2019:
    - création du fichier à partir de shomgt.php pour tester l'algorithme de changement d'ordre des cartes
    - correction d'un bug dans OnTop::chgOrder()
*/
/*PhpDoc: classes
name: OnTop
title: class OnTop
doc: |
  classe regroupant les méthodes statiques pour gérer le changement d'ordre
    - init() initialise la classe à partir d'un fichier Yaml contenant les données
    - assess() effectue le changement d'ordre sur une couche
*/
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

class OnTop {
  static $onTop; // couples (gt1, gt2) où gt1 est au dessus de gt2, cad gt1 doit être après gt2 dans la liste
  
  // initialise la classe statique à partir du fichier Yaml
  static function init(string $yamlpath) {
    $updt = Yaml::parseFile($yamlpath);
    self::$onTop = $updt['onTop'];
  }
  
  // retourne la clé à partir de la valeur
  // permet dans gtnames qui contient la liste des clés d'obtenir le numéro d'ordre
  static function num(array $array, string $key): int {
    foreach ($array as $num => $value)
      if ($value == $key)
        return $num;
    return -1;
  }
  
  // change l'ordre du tableau $gtnames en mettant l'élément topNum juste après bellowNum
  static function chgOrder(array $gtnames, int $topNum, int $bellowNum): array {
    // recopie des élts avant topnum
    if ($topNum == 0)
      $result = [];
    else
      $result = array_slice($gtnames, 0, $topNum);
    if ($bellowNum > $topNum + 1)
      $result = array_merge($result, array_slice($gtnames, $topNum+1, $bellowNum - $topNum - 1));
    $result[] = $gtnames[$bellowNum];
    $result[] = $gtnames[$topNum];
    if ($bellowNum < count($gtnames)-1)
      $result = array_merge($result, array_slice($gtnames, $bellowNum +1));
    //echo "result="; print_r($result);
    if (count($result) <> count($gtnames))
      throw new Exception("Erreur dans chgOrder: ".count($result)." <> ".count($gtnames));
    return $result;
  }
  
  // L'algorithme consiste pour chaque couple (top, bellow) à mettre top juste après bellow
  static function assess(string $lyrname, array $layer): array {
    //echo "layer="; print_r($layer);
    $gtnames = array_keys($layer); // la liste des clés des GéoTiff
    //echo "layer $lyrname, gtnames="; print_r($gtnames);
    foreach (self::$onTop as $top => $bellow) {
      // $top et $bellow sont les identifiants des GéoTiff
      if (!isset($layer[$top]) || !isset($layer[$bellow]))
        continue;
      $topNum = self::num($gtnames, $top);
      $bellowNum = self::num($gtnames, $bellow);
      //echo "top=$top doit être après bellow=$bellow\n";
      //echo "topNum=$topNum, bellowNum=$bellowNum\n";
      if ($bellowNum > $topNum)
        $gtnames = self::chgOrder($gtnames, $topNum, $bellowNum);
      //echo "layer $lyrname, gtnames="; print_r($gtnames);
    }
    // fabrication d'une nouvelle layer respectant le nouvel ordre des gtnames
    $newLayer = [];
    foreach ($gtnames as $gtname)
      $newLayer[$gtname] = $layer[$gtname];
    return $newLayer;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire de la classe OnTop

$layers = [
  [ 'a'=> 0,
    '6623/6623_pal300' => 0,
    '6757/6757_pal300' => 0,
    'b'=> 0,
  ],
  [ 'a'=> 0,
    '6623/6623_pal300' => 0,
    '6757/6757_pal300' => 0,
  ],
  [ '6623/6623_pal300' => 0,
    '6757/6757_pal300' => 0,
    'b'=> 0,
  ],
  [ '6623/6623_pal300' => 0,
    '6757/6757_pal300' => 0,
  ],
  [ 'a'=> 0,
    '6623/6623_pal300' => 0,
    'c'=> 0,
    '6757/6757_pal300' => 0,
    'b'=> 0,
  ],
  [ 'a'=> 0,
    '6623/6623_pal300' => 0,
    'c'=> 0,
    'd'=> 0,
    '6757/6757_pal300' => 0,
    'b'=> 0,
  ],
];

OnTop::init(__DIR__.'/updt.yaml');
foreach ($layers as $layer) {
  echo "entrée: "; print_r($layer);
  $layer = OnTop::assess('lyrname', $layer);
  echo "sortie: "; print_r($layer);
}
