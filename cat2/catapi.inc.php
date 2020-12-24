<?php
/*PhpDoc:
name: catapi.inc.php
title: cat2 / catapi.inc.php - interface du module cat2 pour les autres modules
classes:
doc: |
  Pour mieux contrôler les dépendances entre modules, les modules extérieurs ne doivent utiliser que cette classe
journal: |
  20/12/2020:
    créations
includes: [mapcat.php]
*/
if (file_exists(__DIR__.'/versionV2Active.yaml')) { // utilisation V2
  require_once __DIR__.'/mapcat.php';
}
else { // utilisation V1
  require_once __DIR__.'/../cat/mapcat.inc.php';
}
use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: CatApi
title: class CatApi - interface du module cat2 pour les autres modules
methods:
*/
class CatApi {
  /*PhpDoc: methods
  name: getCatInfoFromGtName
  title: "function getCatInfoFromGtName(string $name, GBox $bbox): array - retourne des infos correspondant au GeoTiff $name"
  doc: |
    retourne les infos du catalogue correspondant au GeoTiff $name, ex '6815/6815_pal300', '7282/7282_1_gtw'
    Retourne un array ayant comme propriétés
    'title': titre
    'edition': edition
    'scaleDenominator': dénominateur de l'échelle avec un . comme séparateur des milliers
    'gbox': GBox
    'bboxDM': array avec 2 string en dégrés minutes, exemple ['SW'=> "16°42,71''S - 151°33,15''W", 'NE'=>"16°38,39''S - 151°26,58''W"]
    retourne [] si le fichier est absent
  */
  static function getCatInfoFromGtName(string $name, GBox $bbox=null): array {
    if (!file_exists(__DIR__.'/versionV2Active.yaml')) { // utilisation V1
      return MapCat::getCatInfoFromGtName($name, $bbox);
    }
    
    // Exceptions
    if ($name == '5825/5825_1_gtw') {
      // carte "Ilot Clipperton" avec un cartouche et sans espace principal, traitée différemment entre GAN et GéoTiff
      $num = '5825'; // no de la carte
      $sid = '';
    }
    elseif (preg_match('!^(\d\d\d\d)/\d\d\d\d_pal300$!', $name, $matches)) {
      $num = $matches[1]; // no de la carte
      $sid = '';
    }
    elseif (preg_match('!^(\d\d\d\d)/\d\d\d\d_(\d+|[A-Z])_gtw$!', $name, $matches)) {
      $num = $matches[1]; // no de la carte
      $sid = $matches[2]; // id de l'espace secondaire dans GéoTiff
    }
    // Le nom des cartes AEM et MancheGrid est structuré différemment
    elseif (preg_match('!^(\d\d\d\d)/\d\d\d\d(_\d\d\d\d)?!', $name, $matches)) {
      $num = $matches[1]; // no de la carte
      $sid = '';
    }
    else
      throw new Exception("No match on $name in ".__FILE__." line ".__LINE__);
    if (!($map = MapCat::maps()["FR$num"] ?? null))
      return [];
    //echo "map="; print_r($map);
    $mapa = $map->asArray();
    if (!$sid) {
      return [
        'num'=> $num,
        'title'=> $mapa['title'],
        //'edition'=> $mapa['edition'],
        'scaleDenominator'=> $mapa['scaleDenominator'],
        'gbox'=> $map->bbox()->asGBoxes()[0],
        'bboxDM'=> $mapa['bboxDM'],
      ];
    }
    else
      return self::getGTFromGBox($map, $bbox);
  }
  
  /* retourne le cartouche correspondant au GBox dans le catalogue sous la forme:
    'num': numéro de la carte
    'title': titre
    'issued': edition
    'scaleDenominator': dénominateur de l'échelle
    'gbox': GBox
    'bboxDM': bboxDM
  */
  private static function getGTFromGBox(Mapcat $map, GBox $gtbbox): array {
    if (count($map->hasPart()) == 0)
      throw new Exception("Erreur, aucun cartouche dans la carte ".$map->num());
      
    if (count($map->hasPart()) == 1) {
      $part = $map->hasPart()[0];
      $parta = $part->asArray();
      return [
        'num'=> $map->num(),
        'title'=> $parta['title'],
        //'issued'=> $map->edition(),
        'scaleDenominator'=> $parta['scaleDenominator'],
        'gbox'=> $part->bbox()->asGBoxes()[0],
        'bboxDM'=> $part->bbox()->asArray(),
      ];
    }
  
    // je cherche le cartouche qui correspond le mieux au GeoTiff
    $parts = [];
    // je prend le cartouche le plus proche en utilisant distbbox()
    $dmin = 9e999;
    foreach ($map->hasPart() as $i => $part) {
      //echo "<pre>box="; print_r($box); echo "</pre>\n";
      $partbbox = $part->bbox();
      $dist = $gtbbox->distance($partbbox->asGBoxes()[0]);
      if ($dist < $dmin) {
        $parta = $part->asArray();
        //echo "le cartouche $parta[title] correspond dist=$dist < $dmin<br>\n";
        $dmin = $dist;
        $nearestPart = [
          'num'=> $map->num(),
          'title'=> $parta['title'],
          //'issued'=> $map->edition(),
          'scaleDenominator'=> $parta['scaleDenominator'],
          'gbox'=> $partbbox->asGBoxes()[0],
          'bboxDM'=> $partbbox->asArray(),
        ];
      }
    }
    if ($dmin == 9e999)
      throw new Eception("Aucun cartouche ne correspond");
    return $nearestPart;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire de la classe CatApi


echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>catapi</title></head><body><pre>\n";

foreach ([
  '7053/7053_pal300'=> ['gbox'=> new GBox, 'title'=> "cas partie principale"],
  '7053/7053_1_gtw'=> ['gbox'=> new GBox, 'title'=> "cas partie inexistante"],
  '7024/7024_1_gtw'=> ['gbox'=> new GBox/*([9.426881, 41.094761, 9.481197, 41.148094])*/, 'title'=> "cas une seule partie"],
  '7282/7282_1_gtw'=> ['gbox'=> new GBox([6.153289, 43.074667, 6.164564, 43.087761]), 'title'=> "cas plusieurs parties"],
] as $name => $p) {
  try {
    echo "<h2>$p[title]</h2>\n";
    $catapi = CatApi::getCatInfoFromGtName($name, $p['gbox']);
    $catapi['gbox'] = $catapi['gbox']->asArray();
    echo Yaml::dump([$name => $catapi]);
  } catch (Exception $e) {
    echo "$name => ", $e->getMessage(),"\n";
  }
}
