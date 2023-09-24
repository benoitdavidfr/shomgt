<?php
/** PERIME */
/*PhpDoc:
name: purge.php
title: bo/purge.php - proto purge des vieilles livraisons - Benoit DAVID - 16-17/7/2023 (PERIME)
doc: |
  Pour chaque carte, je garde
    - les versions ayant moins d'un an et
    - la plus récente des versions ayant plus d'un an
  Je supprime donc les autres versions ayant plus d'un an
*/
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

define ('SHOMGEOTIFF', '/var/www/html/shomgeotiff');

// Organisation des cartes par no de carte avec la liste des archives dans lesquelles la carte apparait
class Map {
  public array $archives=[]; // [{archiveName}=> ('current'|'archive')]
  static array $all=[]; // [{nom}=> Map]
  static array $nbre=['delete'=>0, 'archive'=>0, 'current'=>0, 'total'=>0];
  static ?int $oneYAgo = null;

  function purge(): void {
    if (!self::$oneYAgo)
      self::$oneYAgo = date('Ym') - 100;
    $del = false;
    foreach (array_reverse($this->archives, true) as $arName => $status) {
      //echo "  $arName -> $status\n";
      if ($del) {
        //echo "    -> del\n";
        $this->archives[$arName] = 'delete';
        Map::$nbre['archive']--;
        Map::$nbre['delete']++;
        continue;
      }
      $Ym = substr($arName, 0, 6);
      if ($Ym <= self::$oneYAgo) {
        //echo "    <= oneYAgo -> keep first\n";
        $del = true;
      }
      else {
        //echo "    > oneYAgo -> keep\n";
      }
    }
  }
};

// lecture des archives
foreach (new DirectoryIterator(SHOMGEOTIFF."/archives") as $archiveName) {
  if (in_array($archiveName, ['.','..','.DS_Store'])) continue;
  //echo "$archiveName\n";
  foreach (new DirectoryIterator(SHOMGEOTIFF."/archives/$archiveName") as $mapName) {
    if (substr($mapName, -3) <> '.7z') continue;
    //echo "mapName=$mapName\n";
    if (!isset(Map::$all[(string)$mapName]))
      Map::$all[(string)$mapName] = new Map;
    Map::$all[(string)$mapName]->archives[(string)$archiveName] = 'archive';
    Map::$nbre['archive']++;
  }
}

// lecture des cartes courantes et des liens corepondants
//if (0)
foreach (new DirectoryIterator(SHOMGEOTIFF."/current") as $current) {
  if (substr($current, -3) <> '.7z') continue;
  $link = readlink(SHOMGEOTIFF."/current/$current");
  //echo "$current -> $link\n";
  if (!preg_match('!^\.\./archives/([^/]+)/(\d{4}\.7z)$!', $link, $matches))
    die("No match on $link\n");
  $archiveName = $matches[1];
  $mapName = $matches[2];
  if (!isset(Map::$all[$mapName]))
    Map::$all[$mapName] = new Map;
  Map::$all[$mapName]->archives[$archiveName] = 'current';
  Map::$nbre['archive']--;
  Map::$nbre['current']++;
}

ksort(Map::$all);
foreach(Map::$all as $mname => $map) {
  //echo "map: $mname\n";
  $map->purge();
  echo Yaml::dump([$mname => $map->archives]);
}
Map::$nbre['total'] = Map::$nbre['current'] + Map::$nbre['archive'] + Map::$nbre['delete'];
echo "nbre = "; print_r(Map::$nbre);
