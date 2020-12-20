<?php
/*PhpDoc:
name: catapi.inc.php
title: cat2 / catapi.inc.php - interface du module cat2 pour les autres modules
classes:
doc: |
  Pour mieux contrôler les dépendances entre modules, les modules extérieurs ne doivent utiliser que cette classe
journal: |
  20/12/2020:
    création
*/
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
    retourne les infos du catalogue correspondant au GeoTiff $name, ex '6815/6815_pal300'
    Retourne un array ayant comme propriétés
    'title': titre
    'issued': edition
    'scaleDenominator': dénominateur de l'échelle avec un . comme séparateur des milliers
    'gbox': GBox
    'bboxDM': array avec 2 string en dégrés minutes, exemple ['SW'=> "16°42,71''S - 151°33,15''W", 'NE'=>"16°38,39''S - 151°26,58''W"]
    retourne [] si le fichier est absent
  */
  static function getCatInfoFromGtName(string $name, GBox $bbox): array {
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
    $map = self::all()["FR$num"] ?? null;
    if (!$map)
      throw new Exception("Erreur: carte $num absente du catalogue");
    //echo "map="; print_r($map);
    if ($sid === '') {
      return [
        'num'=> $num,
        'title'=> $map->boxes[0]['title'],
        'issued'=> $map->edition,
        'scaleDenominator'=> $map->boxes[0]['scaleD'],
        'gbox'=> $map->boxes[0]['bbox']->gbox(),
        'bboxDM'=> $map->boxes[0]['bbox']->asDM(),
      ];
    }
    else
      return $map->getGTFromGBox($bbox);
  }
};
