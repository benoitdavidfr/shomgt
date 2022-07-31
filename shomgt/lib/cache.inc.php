<?php
/*PhpDoc:
name: cache.inc.php
title: cache.inc.php - gestion d'un cache simple des tuiles
classes:
doc: |
journal: |
  26/5/2022:
    fork depuis ShomGT2
  6/3/2019
    fork depuis shomgt et simplification
  9/7/2017
    création
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/envvar.inc.php';

/*PhpDoc: classes
name: Cache
methods:
title: class Cache - classe statique implémentant la gestion du cache des tuiles
doc: |
  Définit 4 méthodes statiques:
  - path() : définit le chemin de stockage du cache
  - test() : définit les conditions de mise en cache
  - readAndSend() : si la tuile demandée est présente dans le cache alors l'affiche
  - write() : écrit la tuile dans le cache si les conditions de mise en cache sont remplies
*/
class Cache {
  const NB_SECONDS_IN_CACHE = 0.5*24*60*60; // durée de mise en cache par le navigateur
  
  static function path(): string { // chemin de stockage du cache
    return EnvVar::val('SHOMGT3_MAPS_DIR_PATH').'/../tilecache';
  }
  
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
    $path = self::path();
    if (!is_file("$path/$layer/$z/$x/$y.png")) {
      return;
    }
    //die("envoi de l'image");
    header('Cache-Control: max-age='.intval(self::NB_SECONDS_IN_CACHE)); // mise en cache pour NB_SECONDS_IN_CACHE s
    header('Expires: '.date('r', time() + intval(self::NB_SECONDS_IN_CACHE))); // mise en cache pour NB_SECONDS_IN_CACHE s
    header('Last-Modified: '.date('r'));
    header("Content-type: image/png");
    // envoi de l'image
    die(readfile("$path/$layer/$z/$x/$y.png"));
  }
  
/*PhpDoc: methods
name: Cache
title: static function write(string $layer, int $z, int $x, int $y, GdImage $image): void - Si les conditions sont remplies alors stocke la tuile
*/
  static function write(string $layer, int $z, int $x, int $y, GdImage $image): void {
    //echo "write($layer/$z/$x/$y)\n";
    if (!self::test($layer, $z, $x, $y)) return;
    $path = self::path();
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
