<?php
/*PhpDoc:
name: cache.inc.php
title: cache.inc.php - gestion d'un cache simple des tuiles
classes:
doc: |
journal: |
  6/3/2019
    fork depuis shomgt et simplification
  9/7/2017
    création
*/
/*PhpDoc: classes
name: Cache
methods:
title: class Cache - classe statique implémentant la gestion du cache des tuiles
doc: |
  Définit 3 méthodes statiques:
  - test() : définit les conditions de mise en cache
  - readAndSend() : si la tuile demandée est présente dans le cache alors l'affiche
  - write() : écrit la tuile dans le cache si les conditions de mise en cache sont remplies
*/
class Cache {
  static $path = __DIR__.'/../tilecache'; // chemin de stockage du cache
  
/*PhpDoc: methods
name: test
title: static function test($layer, $z, $x, $y) - définit les conditions de mise en cache
*/
  static function test(string $layer, int $z, int $x, int $y): bool { return (($layer=='gtpyr') && ($z<10)); }
  
/*PhpDoc: methods
name: readAndSend
title: static function readAndSend($layer, $z, $x, $y, $format) - Si la tuile est présente alors l'affiche
*/
  static function readAndSend(string $layer, int $z, int $x, int $y): void {
    //echo "Cache::readAndSend($layer, $z, $x, $y, $format)<br>\n";
    //return;
    if (!self::test($layer, $z, $x, $y)) return;
    if (!is_file(self::$path."/$layer/$z/$x/$y.png")) return;
    $nbDaysInCache = 0.5;
    $nbSecondsInCache = $nbDaysInCache*24*60*60;
    //$nbSecondsInCache = 1;
    header('Cache-Control: max-age='.$nbSecondsInCache); // mise en cache pour $nbDaysInCache jours
    header('Expires: '.date('r', time() + $nbSecondsInCache)); // mise en cache pour $nbDaysInCache jours
    header('Last-Modified: '.date('r'));
    header("Content-type: image/png");
// envoi de l'image
    die(readfile(self::$path."/$layer/$z/$x/$y.png"));
  }
  
/*PhpDoc: methods
name: Cache
title: static function write($layer, $z, $x, $y, $image) - Si les conditions sont remplies alors stocke la tuile
*/
  static function write(string $layer, int $z, int $x, int $y, $image) {
    if (!self::test($layer, $z, $x, $y)) return;
    $path = self::$path;
    if (!is_dir($path) && !mkdir($path))
      throw new Exception("Erreur de création de $path");
    if (!is_dir("$path/$layer") && !mkdir("$path/$layer"))
      throw new Exception("Erreur de création de $path/$layer");
    if (!is_dir("$path/$layer/$z") && !mkdir("$path/$layer/$z"))
      throw new Exception("Erreur de création de $path/$layer/$z");
    if (!is_dir("$path/$layer/$z/$x") && !mkdir("$path/$layer/$z/$x"))
      throw new Exception("Erreur de création de $path/$layer/$z/$x");
    if (!@imagepng($image, "$path/$layer/$z/$x/$y.png"))
      throw new Exception("Erreur d'écriture de $path/$layer/$z/$x/$y.png");
  }
}
