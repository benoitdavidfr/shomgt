<?php
/*PhpDoc:
name: wfsserver.inc.php
title: lib/wfsserver.inc.php - fonctionnalités communes au serveur WFS Gml et au serveur WFS GeoJSON
functions:
doc: |
  Code repris de YamlDoc
  Définition de 3 classes:
    - WfsServer - classe abstraite des fonctionnalités communes Gml et GeoJSON
    - WfsGeoJson - serveur WFS retournat du GeoJSON comme ceux du Shom ou de l'IGN
    - FeaturesApi - interface Feature API d'un serveur WfsGeoJson
journal: |
  28/12/2020:
    - reprise de YamlDoc
*/
require_once __DIR__.'/../../vendor/autoload.php';
//require_once __DIR__.'/../config.inc.php';

use Symfony\Component\Yaml\Yaml;

abstract class WfsServer {
  const LOG = __DIR__.'/wfsserver.log.yaml'; // nom du fichier de log ou false pour pas de log
  const CAP_CACHE = __DIR__.'/wfscapcache'; // nom du répertoire dans lequel sont stockés les fichiers XML
                                            // de capacités ainsi que les DescribeFeatureType en json
  protected string $serverUrl; // URL du serveur
  protected array $options; // sous la forme ['option'=> valeur]
  
  function __construct(string $serverUrl, array $options=[]) {
    $this->serverUrl = $serverUrl;
    $this->options = $options;
  }
  
  // construit l'URL de la requête à partir des paramètres
  function url(array $params): string {
    if (self::LOG) { // log
      file_put_contents(
          self::LOG,
          Yaml::dump([
            'date'=> date(DateTime::ATOM),
            'appel'=> 'WfsServer::url',
            'params'=> $params,
          ]),
          FILE_APPEND
      );
    }
    $url = $this->serverUrl;
    $url .= ((strpos($url, '?') === false) ? '?' : '&').'SERVICE=WFS';
    foreach($params as $key => $value)
      $url .= "&$key=$value";
    if (self::LOG) { // log
      file_put_contents(self::LOG, Yaml::dump(['url'=> $url]), FILE_APPEND);
    }
    return $url;
  }
  
  // envoi une requête et récupère la réponse sous la forme d'un texte
  function query(array $params): string {
    $url = $this->url($params);
    $context = null;
    if ($this->options) {
      $httpOptions = [];
      if ($referer = ($this->options['referer'] ?? null)) {
        $httpOptions['header'] = "referer: $referer\r\n";
      }
      if ($proxy = ($this->options['proxy'] ?? null)) {
        $httpOptions['proxy'] = $proxy;
      }
      if ($httpOptions) {
        if (self::LOG) { // log
          file_put_contents(
              self::LOG,
              Yaml::dump([
                'appel'=> 'WfsServer::query',
                'httpOptions'=> $httpOptions,
              ]),
              FILE_APPEND
          );
        }
        $httpOptions['method'] = 'GET';
        $context = stream_context_create(['http'=> $httpOptions]);
      }
    }
    if (($result = @file_get_contents($url, false, $context)) === false) {
      if (isset($http_response_header)) {
        echo "http_response_header="; var_dump($http_response_header);
      }
      throw new Exception("Erreur dans WfsServer::query() : sur url=$url");
    }
    //die($result);
    //if (substr($result, 0, 17) == '<ExceptionReport>') {
    if (preg_match('!ExceptionReport!', $result)) {
      if (preg_match('!<ExceptionReport><[^>]*>([^<]*)!', $result, $matches)) {
        throw new Exception ("Erreur dans WfsServer::query() : $matches[1]");
      }
      if (preg_match('!<ows:ExceptionText>([^<]*)!', $result, $matches)) {
        throw new Exception ("Erreur dans WfsServer::query() : $matches[1]");
      }
      echo $result;
      throw new Exception("Erreur dans WfsServer::query() : message d'erreur non détecté");
    }
    return $result;
  }
  
  // effectue un GetCapabities et retourne le XML. Utilise le cache sauf si force=true
  function getCapabilities(bool $force=false): string {
    if (!is_dir(self::CAP_CACHE) && !mkdir(self::CAP_CACHE))
      throw new Exception("Erreur de création du répertoire ".self::CAP_CACHE);
    $wfsVersion = $this->options['version'] ?? '2.0.0';
    $filepath = self::CAP_CACHE.'/wfs'.md5($this->serverUrl.$wfsVersion).'-cap.xml';
    if (!$force && file_exists($filepath))
      return file_get_contents($filepath);
    else {
      $cap = $this->query(['request'=> 'GetCapabilities','VERSION'=> $wfsVersion]);
      file_put_contents($filepath, $cap);
      return $cap;
    }
  }

  // liste les couches exposées evt filtré par l'URL des MD
  function featureTypeList(string $metadataUrl=null) {
    //echo "WfsServerJson::featureTypeList()<br>\n";
    $cap = $this->getCapabilities();
    $cap = str_replace(['xlink:href'], ['xlink_href'], $cap);
    $featureTypeList = [];
    $cap = new SimpleXMLElement($cap);
    foreach ($cap->FeatureTypeList->FeatureType as $featureType) {
      $name = (string)$featureType->Name;
      if (!$metadataUrl || ($featureTypeRec['MetadataURL'] == $metadataUrl))
        $featureTypeList[$name] = [
          'Title'=> (string)$featureType->Title,
          'MetadataURL'=> (string)$featureType->MetadataURL['xlink_href'],
        ];
    }
    //echo '<pre>$featureTypeList = '; print_r($featureTypeList);
    return $featureTypeList;
  }
  
  abstract function describeFeatureType(string $typeName): array;
  
  abstract function geomPropertyName(string $typeName): ?string;
  
  abstract function getNumberMatched(string $typename, array $bbox=[], string $where=''): int;
  
  abstract function getFeature(string $typename, array $bbox=[], int $zoom=-1, string $where='', int $count=100, int $startindex=0): string;

  abstract function printAllFeatures(string $typename, array $bbox=[], int $zoom=-1, string $where=''): void;
};

class WfsGeoJson extends WfsServer { // gère les fonctionnalités d'un serveur WFS retournant du GeoJSON

  function describeFeatureType(string $typeName): array {
    $filepath = self::CAP_CACHE.'/wfs'.md5($this->serverUrl."/$typeName").'-ft.json';
    if (is_file($filepath)) {
      $featureType = file_get_contents($filepath);
    }
    else {
      $featureType = $this->query([
        'VERSION'=> '2.0.0',
        'REQUEST'=> 'DescribeFeatureType',
        'OUTPUTFORMAT'=> 'application/json',
        'TYPENAME'=> $typeName,
      ]);
      if (!is_dir(self::CAP_CACHE) && !mkdir(self::CAP_CACHE))
        throw new Exception("Erreur de création du répertoire ".self::CAP_CACHE);
      file_put_contents($filepath, $featureType);
    }
    $featureType = json_decode($featureType, true);
    return $featureType;
  }
  
  
  // nom de la propriété géométrique du featureType
  function geomPropertyName(string $typeName): ?string {
    $featureType = $this->describeFeatureType($typeName);
    //var_dump($featureType);
    foreach($featureType['featureTypes'] as $featureType) {
      foreach ($featureType['properties'] as $property) {
        if (preg_match('!^gml:!', $property['type']))
          return $property['name'];
      }
    }
    return null;
  }
    
  // retourne le nbre d'objets correspondant au résultat de la requête
  function getNumberMatched(string $typename, array $bbox=[], string $where=''): int {
    $geomPropertyName = $this->geomPropertyName($typename);
    $request = [
      'VERSION'=> '2.0.0',
      'REQUEST'=> 'GetFeature',
      'TYPENAMES'=> $typename,
      'SRSNAME'=> 'CRS:84', // système de coordonnées nécessaire pour du GeoJSON
      'RESULTTYPE'=> 'hits',
    ];
    $cql_filter = '';
    if ($bbox) {
      $bboxwkt = self::bboxWktCrs($bbox, $this->defaultCrs($typename));
      $cql_filter = "Intersects($geomPropertyName,$bboxwkt)";
    }
    if ($where) {
      $where = utf8_decode($where); // expérimentalement les requêtes doivent être encodées en ISO-8859-1
      $cql_filter .= ($cql_filter ? ' AND ':'').$where;
    }
    if ($cql_filter)
      $request['CQL_FILTER'] = urlencode($cql_filter);
    $result = $this->query($request);
    if (!preg_match('! numberMatched="(\d+)" !', $result, $matches)) {
      //echo "result=",$result,"\n";
      throw new Exception("Erreur dans WfsServerJson::getNumberMatched() : no match on result $result");
    }
    return (int)$matches[1];
  }
  
  // retourne le résultat de la requête en GeoJSON
  function getFeature(string $typename, array $bbox=[], int $zoom=-1, string $where='', int $count=100, int $startindex=0): string {
    $geomPropertyName = $this->geomPropertyName($typename);
    $request = [
      'VERSION'=> '2.0.0',
      'REQUEST'=> 'GetFeature',
      'TYPENAMES'=> $typename,
      'OUTPUTFORMAT'=> 'application/json',
      'SRSNAME'=> 'CRS:84', // système de coordonnées nécessaire pour du GeoJSON
      'COUNT'=> $count,
      'STARTINDEX'=> $startindex,
    ];
    $cql_filter = '';
    if ($bbox) {
      $bboxwkt = self::bboxWktCrs($bbox, $this->defaultCrs($typename));
      $cql_filter = "Intersects($geomPropertyName,$bboxwkt)";
    }
    if ($where) {
      $where = utf8_decode($where); // expérimentalement les requêtes doivent être encodées en ISO-8859-1
      $cql_filter .= ($cql_filter ? ' AND ':'').$where;
    }
    if ($cql_filter)
      $request['CQL_FILTER'] = urlencode($cql_filter);
    return $this->query($request);
  }
  
  // retourne le résultat de la requête en GeoJSON encodé en array Php
  function getFeatureAsArray(string $typename, array $bbox=[], int $zoom=-1, string $where='', int $count=100, int $startindex=0): array {
    $result = $this->getFeature($typename, $bbox, $zoom, $where, $count, $startindex);
    return json_decode($result, true);
  }
  
  // affiche le résultat de la requête en GeoJSON
  function printAllFeatures(string $typename, array $bbox=[], int $zoom=-1, string $where=''): void {
    //echo "WfsServerJson::printAllFeatures()<br>\n";
    $numberMatched = $this->getNumberMatched($typename, $bbox, $where);
    if ($numberMatched <= 100) {
      echo $this->getFeature($typename, $bbox, $zoom, $where);
      return;
    }
    //$numberMatched = 12; POUR TESTS
    echo '{"type":"FeatureCollection","numberMatched":'.$numberMatched.',"features":[',"\n";
    $startindex = 0;
    $count = 100;
    while ($startindex < $numberMatched) {
      $fc = $this->getFeature($typename, $bbox, $zoom, $where, $count, $startindex);
      $fc = json_decode($fc, true);
      foreach ($fc['features'] as $nof => $feature) {
        if (($startindex <> 0) || ($nof <> 0))
          echo ",\n";
        echo json_encode($feature, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      }
      $startindex += $count;
    }
    echo "\n]}\n";
  }
};


class FeaturesApi extends WfsGeoJson { // transforme un serveur WFS en Api Features
  
  function collections(): array { // retourne la liste des collections
    $collections = [];
    foreach ($this->featureTypeList() as $typeId => $type) {
      $collections[] = [
        'id'=> $typeId,
        'title'=> $type['Title'],
      ];
    }
    return $collections;
  }
  
  function collection(string $id): array { // retourne la description du FeatureType de la collection
    return $this->describeFeatureType($id);
  }
  
  // retourne les items de la collection comme array Php
  function items(string $collId, array $bbox=[], int $count=100, int $startindex=0): array {
    $items = $this->getFeatureAsArray(
      typename: $collId,
      bbox: $bbox,
      count: $count,
      startindex: $startindex
    );
    return $items;
  }
  
  // génère un affichage en JSON ou Yaml en fonction du paramètre $f
  static function output(string $f, array $array, int $levels=3) {
    switch ($f) {
      case 'yaml': die(Yaml::dump($array, $levels, 2));
      case 'json': die(json_encode($array, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
  }
};


if ((__FILE__ <> $_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME']) && (($argv[0] ?? '') <> basename(__FILE__))) return;


switch ($f = $_GET['f'] ?? 'yaml') {
  case 'yaml': {
    echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>shomwfs</title></head><body><pre>\n";
    break;
  }
  case 'json':
  case 'geojson': {
    header('Access-Control-Allow-Origin: *');
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
  FeaturesApi::output($f, ['home'=> 'home']);
}

if (!preg_match('!^/collections(/([^/]+))?(/items)?$!', $_SERVER['PATH_INFO'], $matches)) {
  FeaturesApi::output($f, ['error'=> 'no match']);
}

//echo 'matches='; print_r($matches);
$collId = $matches[2] ?? null;
$items = $matches[3] ?? null;

$wfsOptions = ($proxy = config('proxy')) ? ['proxy'=> str_replace('http://', 'tcp://', $proxy)] : [];
$shomWfs = new FeaturesApi('https://services.data.shom.fr/INSPIRE/wfs', $wfsOptions);

if (!$collId) { // /collections
  FeaturesApi::output($f, $shomWfs->collections(), 4);
}
elseif (!$items) { // /collections/{collId}
  FeaturesApi::output($f, $shomWfs->collection($collId), 6);
}
else { // /collections/{collId}/items
  FeaturesApi::output($f,
    $shomWfs->items(
      collId: $collId,
      bbox: $_GET['bbox'] ?? [],
      count: $_GET['count'] ?? 100,
      startindex: $_GET['startindex'] ?? 0
    ), 6
  );
}

