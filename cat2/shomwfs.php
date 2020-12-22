<?php
/*PhpDoc:
name: wfs.php
title: cat2/shomwfs.php - utilisation du WFS du Shom dans la carte llmap
classes:
doc: |
includes:
  - ../lib/gjbox.inc.php
  - ../lib/gegeom.inc.php
  - ../lib/feature.inc.php
  - wfsserver.inc.php
  - wfsjson.inc.php
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gjbox.inc.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/../lib/feature.inc.php';
require_once __DIR__.'/wfsserver.inc.php';
require_once __DIR__.'/wfsjson.inc.php';

use Symfony\Component\Yaml\Yaml;

class ShomWfs extends WfsServerJson {
  static $verbose = false;
  
  function collections(): array {
    $collections = [];
    foreach ($this->featureTypeList() as $typeId => $type) {
      $collections[] = [
        'id'=> $typeId,
        'title'=> $type['Title'],
      ];
    }
    return $collections;
  }
  
  function collection(string $id): array {
    return $this->describeFeatureType($id);
  }
  
  function items(string $collId, array $bbox=[], int $count=100, int $startindex=0) {
    // function getFeatureAsArray(string $typename, array $bbox=[], int $zoom=-1, string $where='', int $count=100, int $startindex=0): array
    $items = $this->getFeatureAsArray(
      typename: $collId,
      bbox: $bbox,
      count: $count,
      startindex: $startindex
    );
    foreach ($items['features'] as $no => $item) {
      //id: 'au_maritimeboundary_nonagreedmaritimeboundary.http://www.shom.fr/BDML/DELMAR/FR000030055900003'
      $items['features'][$no]['id'] = str_replace('.http://www.shom.fr/BDML/DELMAR','',$item['id']);
      $props = $item['properties'];
      $title = $props['nature'];
      unset($props['nature']);
      $items['features'][$no]['properties'] = ['title'=> $title] + $props;
    }
    return $items;
  }
};


if (__FILE__ <> $_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME']) return; // Utilisation de la classe - OGCFeatures simplifié


function output(string $f, array $array, int $levels=3) {
  switch ($f) {
    case 'yaml': die(Yaml::dump($array, $levels, 2));
    case 'json': die(json_encode($array, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
}

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
  output($f, ['home'=> 'home']);
}

if (!preg_match('!^/collections(/([^/]+))?(/items)?$!', $_SERVER['PATH_INFO'], $matches)) {
  output($f, ['error'=> 'no match']);
}

//echo 'matches='; print_r($matches);
$collId = $matches[2] ?? null;
$items = $matches[3] ?? null;

$shomWfs = new ShomWfs(['wfsUrl'=> 'http://services.data.shom.fr/INSPIRE/wfs'], 'shomwfs');

if (!$collId) {
  output($f, $shomWfs->collections(), 4);
}
elseif (!$items) {
  output($f, $shomWfs->collection($collId), 6);
}
else {
  output($f,
    $shomWfs->items(
      collId: $collId,
      bbox: $_GET['bbox'] ?? [],
      count: $_GET['count'] ?? 100,
      startindex: $_GET['startindex'] ?? 0
    ), 6
  );
}
