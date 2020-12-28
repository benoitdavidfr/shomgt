<?php
/*PhpDoc:
name: shomwfs.php
title: cat2/shomwfs.php - utilisation du WFS du Shom dans la carte llmap
classes:
doc: |
includes:
  - ../lib/config.inc.php
  - ../lib/gjbox.inc.php
  - ../lib/gegeom.inc.php
  - ../lib/feature.inc.php
  - ../lib/wfs/wfsserver.inc.php
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/config.inc.php';
require_once __DIR__.'/../lib/gjbox.inc.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/../lib/feature.inc.php';
require_once __DIR__.'/../lib/wfs/wfsserver.inc.php';

use Symfony\Component\Yaml\Yaml;

http://localhost/geoapi/shomgt/cat2/shomwfs.php/collections/DELMAR_BDD_WFS:au_maritimeboundary_nonagreedmaritimeboundary/items

class ShomWfs extends FeaturesApi { // 
  // simplification des id sous la forme [{collId}=> ['search'=> {search}, 'replace' => {replace}]]
  const REPLACE_ID = [
    'DELMAR_BDD_WFS:au_maritimeboundary_nonagreedmaritimeboundary' => [
      'search'=> 'au_maritimeboundary_nonagreedmaritimeboundary.http://www.shom.fr/BDML/DELMAR/FR',
      'replace' => 'FR',
    ],
  ];
  
  function __construct() {
    $wfsOptions = ($proxy = config('proxy')) ? ['proxy'=> str_replace('http://', 'tcp://', $proxy)] : [];
    parent::__construct('https://services.data.shom.fr/INSPIRE/wfs', $wfsOptions);
  }
  
  // adapte le feature
  function items(string $collId, array $bbox=[], int $count=100, int $startindex=0): array {
    $items = parent::items($collId, $bbox, $count, $startindex);
    if ($replace = (self::REPLACE_ID[$collId] ?? null)) {
      foreach ($items['features'] as $no => $item) {
        $items['features'][$no]['id'] = str_replace($replace['search'], $replace['replace'], $item['id']);
      }
    }
    if ($collId == 'DELMAR_BDD_WFS:au_maritimeboundary_nonagreedmaritimeboundary') {
      foreach ($items['features'] as $no => $item) {
        $props = $item['properties'];
        $title = $props['nature'];
        unset($props['nature']);
        $items['features'][$no]['properties'] = ['title'=> $title] + $props;
      }
    }
    return $items;
  }
};


if (__FILE__ <> $_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME']) return; // Utilisation de la classe - OGCFeatures simplifié


switch ($f = $_GET['f'] ?? 'yaml') {
  case 'yaml': {
    echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>shomwfs</title></head><body><pre>\n";
    break;
  }
  case 'json':
  case 'geojson': {
    header('Content-type: application/json; charset="utf8"');
    //header('Content-type: text/plain; charset="utf8"');
    $f = 'json';
    break;
  }
  default: {
    $f = 'yaml';
  }
}

if (!isset($_SERVER['PATH_INFO'])) {
  ShomWfs::output($f, ['home'=> 'home']);
}

if (!preg_match('!^/collections(/([^/]+))?(/items)?$!', $_SERVER['PATH_INFO'], $matches)) {
  ShomWfs::output($f, ['error'=> 'no match']);
}

//echo 'matches='; print_r($matches);
$collId = $matches[2] ?? null;
$items = $matches[3] ?? null;

$shomWfs = new ShomWfs;

if (!$collId) { // /collections
  ShomWfs::output($f, $shomWfs->collections(), 4);
}
elseif (!$items) { // /collections/{collId}
  ShomWfs::output($f, $shomWfs->collection($collId), 6);
}
else { // /collections/{collId}/items
  ShomWfs::output($f,
    $shomWfs->items(
      collId: $collId,
      bbox: $_GET['bbox'] ?? [],
      count: $_GET['count'] ?? 100,
      startindex: $_GET['startindex'] ?? 0
    ), 6
  );
}
