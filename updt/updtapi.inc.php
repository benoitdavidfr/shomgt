<?php
/*PhpDoc:
name: updtapi.inc.php
title: updtapi.inc.php - interface du module updt pour les autres modules
classes:
doc: |
  Pour mieux contrôler les dépendances entre modules, les modules extérieurs ne doivent utiliser que cette classe
journal: |
  20/12/2020:
    création
includes: [mdiso19139.inc.php]
*/
require_once __DIR__.'/mdiso19139.inc.php';

/*PhpDoc: classes
name: UpdtApi
title: class UpdtApi - interface du module updt pour les autres modules
methods:
*/
class UpdtApi {
  /*PhpDoc: methods
  name: mdiso19139
  title: "function mdiso19139(string $gtname): array - récupère des éléments des MD ISO19139 du GéoTIFF"
  doc: |
    Prend en paramètre $gtname qui est la clé du géotiff dans shomgt.yaml, ex '6815/6815_pal300'
    Retourne un array ayant comme propriétés
      - mdDate - date de mise à jour des métadonnées string en ISO 8601
      - édition - édition de la carte, ex: Edition n° 4 - 2015, Publication 1984
      - dernièreCorrection - dernière correction indiquée dans les MD , un entier transmis comme string
    retourne [] si le fichier est absent
  */
  static function mdiso19139(string $gtname): array { return mdiso19139($gtname); } 
};
