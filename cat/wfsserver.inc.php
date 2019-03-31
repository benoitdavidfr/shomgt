<?php
/*PhpDoc:
name: wfsserver.inc.php
title: wfsserver.inc.php - document correspondant à un serveur WFS
functions:
doc: <a href='/yamldoc/?action=version&name=wfsserver.inc.php'>doc intégrée en Php</a>
*/
{ // doc 
$phpDocs['wfsserver.inc.php']['file'] = <<<'EOT'
name: wfsserver.inc.php
title: wfsserver.inc.php - document correspondant à un serveur WFS
doc: |
journal: |
  8/3/2019:
    fork dans gt
  3-4/11/2018:
    - ajout de WfsServer::defaultCrs()
    - remplacement de WfsServer::bboxWktLatLng() par WfsServer::bboxWktCrs()
    - WfsServer::bboxWktCrs() fonctionne avec EPSG:2154, EPSG:3857 & EPSG:3395
  9/10/2018:
    - éclatement du fichier en 3
  17-19/9/2018:
    - modification du format intermédiaire pour passage de GML en GeoJSON
    - l'utilisation d'un pseudo JSON ne fonctionnait pas dans certains cas
    - traitement de certaines erreurs rencontrées dans Géo-IDE
  15/9/2018:
    - ajout gestion Point en GML 2
  12/9/2018:
    - transfert des fichiers Php dans ydclasses
    - chgt urlWfs en wfsUrl
    - structuration wfsOptions avec l'option referer et l'option gml
    - ajout option version et possibilité d'interroger le serveur en WFS 1.0.0
  5-9/9/2018:
    - développement de la classe WfsServerGml implémentant les requêtes pour un serveur WFS EPSG:4326 + GML
    - mise en oeuvre du filtrage défini plus haut
  4/9/2018:
    - remplacement du prefixe t par ft pour featureType
    - refonte de la gestion du cache indépendamment du stockage du document car le doc peut être volatil
    - ajout de la récupération du nom de la propriété géométrique qui n'est pas toujours le même
  3/9/2018:
    - ajout d'une classe WfsServerGml implémentant les requêtes pour un serveur WFS GML + EPSG:4326
    en cours
  15/8/2018:
    - création
EOT;
}

//require_once __DIR__.'/../yd.inc.php';
//require_once __DIR__.'/../store.inc.php';
#require_once __DIR__.'/inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

{ // doc 
$phpDocs['wfsserver.inc.php']['classes']['WfsServer'] = <<<'EOT'
title: classe abstraite de documents correspondants à un serveur WFS
doc: |
  La classe abstraite WfsServer implémente qqs méthodes communes aux classes concrètes.
  
  évolutions à réaliser:
  
    - adapter au zoom le nbre de chiffres transmis dans les coordonnées
  
  Outre les champs de métadonnées, le document doit définir les champs suivants:
  
    - wfsUrl: fournissant l'URL du serveur à compléter avec les paramètres,
  
  Il peut aussi définir les champs suivants:
  
    - wfsOptions: définit des options parmi les suivantes
      - referer: définissant le referer à transmettre à chaque appel du serveur,
      - gml: booléen indiquant si le retour est en GML et non en GeoJSON (par défaut)
      - version: version WFS, par défaut 2.0.0, possible '1.0.0'
      - coordOrderInGml: 'lngLat' pour indiquer que les coordonnées GML sont en LngLat et non en LatLng
        
  Résolution:
    zoom = 0, image 256x256
    resolution(zoom=0) Lng à l'équateur = 360/256
    A chaque zoom supérieur, division par 2 de la résolution
    256 = 2 ** 8
    => resolution = 360 / 2**(zoom+8) degrés
EOT;
}
abstract class WfsServer {
  static $log = __DIR__.'/wfsserver.log.yaml'; // nom du fichier de log ou false pour pas de log
  static $capCache = __DIR__.'/wfscapcache'; // nom du répertoire dans lequel sont stockés les fichiers XML
                                           // de capacités ainsi que les DescribeFeatureType en json
  protected $_c; // contient les champs
  
  // crée un nouveau doc, $yaml est le contenu Yaml externe issu de l'analyseur Yaml
  function __construct($yaml, string $docid) {
    $this->_c = [];
    $this->_id = $docid;
    foreach ($yaml as $prop => $value) {
      $this->_c[$prop] = $value;
    }
    if (!$this->wfsUrl)
      throw new Exception("Erreur dans WfsServer::__construct(): champ wfsUrl obligatoire");
  }
  
  // effectue soit un new WfsServerJson soit un new WfsServerGml
  static function new_WfsServer(array $wfsParams, string $docid) {
    if ($wfsParams['featureModifier'])
      return new WfsServerJsonAugmented($wfsParams, $docid);
    elseif (isset($wfsParams['wfsOptions']['gml']))
      return new WfsServerGml($wfsParams, $docid);
    else
      return new WfsServerJSON($wfsParams, $docid);
  }
    
  // lit les champs
  function __get(string $name) { return isset($this->_c[$name]) ? $this->_c[$name] : null; }

  // affiche le sous-élément de l'élément défini par $ypath
  function show(string $ypath=''): void {
    $docid = $this->_id;
    echo "WfsServerJson::show($docid, $ypath)<br>\n";
    if (!$ypath || ($ypath=='/'))
      showDoc($docid, $this->_c);
    else
      showDoc($docid, $this->extract($ypath));
    //echo "<pre>"; print_r($this->_c); echo "</pre>\n";
  }
  
  // décapsule l'objet et retourne son contenu sous la forme d'un array
  function asArray() { return array_merge(['_id'=> $this->_id], $this->_c); }

  // extrait le fragment du document défini par $ypath
  function extract(string $ypath) { return YamlDoc::sextract($this->_c, $ypath); }
  
  // retourne bbox [lngMin, latMin, lngMax, latMax] à partir d'un bbox sous forme de chaine
  static function decodeBbox(string $bboxstr): array {
    if (!$bboxstr)
      return [];
    $bbox = explode(',', $bboxstr);
    if ((count($bbox)<>4) || !is_numeric($bbox[0]) || !is_numeric($bbox[1]) || !is_numeric($bbox[2]) || !is_numeric($bbox[3]))
      throw new Exception("Erreur dans WfsServer::decodeBbox() : bbox '$bboxstr' incorrect");
    return [(float)$bbox[0], (float)$bbox[1], (float)$bbox[2], (float)$bbox[3]];
  }
  
  // retourne un polygon WKT dans le CRS crs à partir d'un bbox [lngMin, latMin, lngMax, latMax]
  static function bboxWktCrs(array $bbox, string $crs) {
    $epsg = [
      'EPSG:2154' => 'L93',
      'EPSG:3857' => 'WebMercator',
      'EPSG:3395' => 'WorldMercator',
    ];
    if (!$bbox)
      return '';
    if ($crs == 'EPSG:4326')
      return "POLYGON(($bbox[1] $bbox[0],$bbox[1] $bbox[2],$bbox[3] $bbox[2],$bbox[3] $bbox[0],$bbox[1] $bbox[0]))";
    if (!isset($epsg[$crs]))
      throw new Exception("Erreur dans WfsServer::bboxWktCrs(), CRS $crs inconnu");
    $bbox = new Bbox(implode(',',$bbox));
    $bbox = $bbox->chgCoordSys('geo', $epsg[$crs]);
    return $bbox->asPolygon()->wkt();
  }
  
  // retourne un polygon WKT LngLat à partir d'un bbox [lngMin, latMin, lngMax, latMax]
  static function bboxWktLngLat(array $bbox) {
    if (!$bbox)
      return '';
    return "POLYGON(($bbox[0] $bbox[1],$bbox[2] $bbox[1],$bbox[2] $bbox[3],$bbox[0] $bbox[3],$bbox[0] $bbox[1]))";
  }
  
  static function api(): array {
    return [
      'class'=> get_class(), 
      'title'=> "description de l'API de la classe ".get_class(), 
      'abstract'=> "document correspondant à un serveur WFS en version 1.0.0 ou 2.0.0",
      'api'=> [
        '/'=> "retourne le contenu du document ".get_class(),
        '/api'=> "retourne les points d'accès de ".get_class(),
        '/query?{params}'=> "envoi une requête construite avec les paramètres GET et affiche le résultat en XML ou JSON, le paramètre SERVICE est prédéfini",
        '/getCap(abilities)?'=> "envoi une requête GetCapabilities, affiche le résultat en XML et raffraichit le cache",
        '/cap(abilities)?'=> "affiche en XML le contenu du cache s'il existe, sinon envoi une requête GetCapabilities, affiche le résultat en XML et l'enregistre dans le cache",
        '/ft'=> "retourne la liste des couches (FeatureType) exposées par le serveur avec pour chacune son titre et son résumé",
        '/ft/{typeName}'=> "retourne la description de la couche {typeName}",
        '/ft/{typeName}/geom(PropertyName)?'=> "retourne le nom de la propriété géométrique pour la couche {typeName}",
        '/ft/{typeName}/defaultCrs'=> "retourne le nom du CRS par défaut pour la couche {typeName}",
        '/ft/{typeName}/num(berMatched)?bbox={bbox}&where={where}'=> "retourne le nombre d'objets de la couche {typeName} correspondant à la requête définie par les paramètres en GET ou POST, where est encodé en UTF-8",
        '/ft/{typeName}/getFeature?bbox={bbox}&zoom={zoom}&where={where}'=> "affiche en GeoJSON les objets de la couche {typeName} correspondant à la requête définie par les paramètres en GET ou POST, limité à 1000 objets",
        '/ft/{typeName}/getAllFeatures?bbox={bbox}&zoom={zoom}&where={where}'=> "affiche en GeoJSON les objets de la couche {typeName} correspondant à la requête définie par les paramètres en GET ou POST, utilise la pagination si plus de 100 objets",
      ]
    ];
  }
   
  // extrait le fragment défini par $ypath, utilisé pour générer un retour à partir d'un URI
  function extractByUri(string $ypath) {
    $docuri = $this->_id;
    //echo "WfsServer::extractByUri($docuri, $ypath)<br>\n";
    $params = !isset($_GET) ? $_POST : (!isset($_POST) ? $_GET : array_merge($_GET, $_POST));
    if (!$ypath || ($ypath=='/')) {
      return array_merge(['_id'=> $this->_id], $this->_c);
    }
    elseif ($ypath == '/api') {
      return self::api();
    }
    elseif ($ypath == '/query') {
      //$params = isset($_GET) ? $_GET : (isset($_POST) ? $_POST : []);
      if (isset($params['OUTPUTFORMAT']) && ($params['OUTPUTFORMAT']=='application/json'))
        header('Content-type: application/json');
      else
        header('Content-type: application/xml');
      echo $this->query($params);
      die();
    }
    // met à jour le cache des capacités et retourne les capacités
    elseif (preg_match('!^/getCap(abilities)?$!', $ypath, $matches)) {
      header('Content-type: application/xml');
      die($this->getCapabilities(true));
    }
    // retourne les capacités sans forcer la mise à jour du cache
    elseif (preg_match('!^/cap(abilities)?$!', $ypath, $matches)) {
      header('Content-type: application/xml');
      die($this->getCapabilities(false));
    }
    elseif ($ypath == '/ft') {
      $cap = $this->getCapabilities();
      $cap = new SimpleXMLElement($cap);
      $typeNames = [];
      foreach ($cap->FeatureTypeList->FeatureType as $FeatureType) {
        $typeNames[(string)$FeatureType->Name] = [
          'title'=> (string)$FeatureType->Title,
          'abstract'=> (string)$FeatureType->Abstract,
        ];
      }
      return $typeNames;
    }
    // accès à la layer /ft/{typeName}
    // effectue la requête DescribeFeatureType et retourne le résultat
    elseif (preg_match('!^/ft/([^/]+)$!', $ypath, $matches)) {
      return $this->describeFeatureType($matches[1]);
    }
    elseif (preg_match('!^/ft/([^/]+)/geom(PropertyName)?$!', $ypath, $matches)) {
      return $this->geomPropertyName($matches[1]);
    }
    elseif (preg_match('!^/ft/([^/]+)/defaultCrs$!', $ypath, $matches)) {
      return $this->defaultCrs($matches[1]);
    }
    // accès à /t/{typeName}/numberMatched
    elseif (preg_match('!^/ft/([^/]+)/num(berMatched)?$!', $ypath, $matches)) {
      $typeName = $matches[1];
      $bbox = isset($params['bbox']) ? $params['bbox'] : '';
      $bbox = self::decodeBbox($bbox);
      $where = isset($params['where']) ? $params['where'] : '';
      return [ 'numberMatched'=> $this->getNumberMatched($typeName, $bbox, $where) ];
    }
    elseif (preg_match('!^/ft/([^/]+)/getFeature$!', $ypath, $matches)) {
      $typeName = $matches[1];
      header('Content-type: application/json');
      $bbox = isset($params['bbox']) ? $params['bbox'] : '';
      $bbox = self::decodeBbox($bbox);
      $zoom = isset($params['zoom']) ? $params['zoom'] : -1;
      $where = isset($params['where']) ? $params['where'] : '';
      //echo "where=$where\n";
      echo $this->getFeature($typeName, $bbox, $zoom, $where);
      die();
    }
    elseif (preg_match('!^/ft/([^/]+)/getAllFeatures$!', $ypath, $matches)) {
      $typeName = $matches[1];
      header('Content-type: application/json');
      $bbox = isset($params['bbox']) ? $params['bbox'] : '';
      $bbox = self::decodeBbox($bbox);
      $zoom = isset($params['zoom']) ? $params['zoom'] : -1;
      $where = isset($params['where']) ? $params['where'] : '';
      $this->printAllFeatures($typeName, $bbox, $zoom, $where);
      die();
    }
    else
      return null;
  }
  
  // renvoi l'URL de la requête
  function url(array $params): string {
    if (self::$log) { // log
      file_put_contents(
          self::$log,
          Yaml::dump([
            'date'=> date(DateTime::ATOM),
            'appel'=> 'WfsServer::url',
            'params'=> $params,
          ]),
          FILE_APPEND
      );
    }
    $url = $this->wfsUrl;
    $url .= ((strpos($url, '?') === false) ? '?' : '&').'SERVICE=WFS';
    foreach($params as $key => $value)
      $url .= "&$key=$value";
    if (self::$log) { // log
      file_put_contents(self::$log, Yaml::dump(['url'=> $url]), FILE_APPEND);
    }
    return $url;
  }
  
  // envoi une requête et récupère la réponse sous la forme d'un texte
  function query(array $params): string {
    $url = $this->url($params);
    $context = null;
    if ($this->wfsOptions && isset($this->wfsOptions['referer'])) {
      $referer = $this->wfsOptions['referer'];
      if (self::$log) { // log
        file_put_contents(
            self::$log,
            YamlDoc::syaml([
              'appel'=> 'WfsServer::query',
              'referer'=> $referer,
            ]),
            FILE_APPEND
        );
      }
      $context = stream_context_create(['http'=> ['header'=> "referer: $referer\r\n"]]);
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
    //echo "wfsUrl=",$this->wfsUrl,"<br>\n";
    //print_r($this); die();
    $wfsVersion = ($this->wfsOptions && isset($this->wfsOptions['version'])) ? $this->wfsOptions['version'] : '';
    $filepath = self::$capCache.'/wfs'.md5($this->wfsUrl.$wfsVersion).'-cap.xml';
    if ((!$force) && file_exists($filepath))
      return file_get_contents($filepath);
    else {
      $query = ['request'=> 'GetCapabilities'];
      if ($wfsVersion)
        $query['VERSION'] = $wfsVersion;
      $cap = $this->query($query);
      if (!is_dir(self::$capCache) && mkdir(self::$capCache))
        throw new Exception("Erreur de création du répertoire ".self::$capCache);
      file_put_contents($filepath, $cap);
      return $cap;
    }
  }

  // liste les couches exposées evt filtré par l'URL des MD
  function featureTypeList(string $metadataUrl=null) {
    //echo "WfsServerJson::featureTypeList()<br>\n";
    $cap = $this->getCapabilities();
    $cap = str_replace(['xlink:href'], ['xlink_href'], $cap);
    //echo "<a href='/yamldoc/wfscapcache/",md5($this->wfsUrl),".xml'>capCache</a><br>\n";
    $featureTypeList = [];
    $cap = new SimpleXMLElement($cap);
    foreach ($cap->FeatureTypeList->FeatureType as $featureType) {
      $name = (string)$featureType->Name;
      $featureTypeRec = [
        'Title'=> (string)$featureType->Title,
        'MetadataURL'=> (string)$featureType->MetadataURL['xlink_href'],
      ];
      if (!$metadataUrl || ($featureTypeRec['MetadataURL'] == $metadataUrl))
        $featureTypeList[$name] = $featureTypeRec;
    }
    //echo '<pre>$featureTypeList = '; print_r($featureTypeList);
    return $featureTypeList;
  }
  
  // retourne le defaultCrs du typeName sous la forme EPSG:xxxx
  function defaultCrs(string $typeName): string {
    $cap = $this->getCapabilities();
    $cap = new SimpleXMLElement($cap);
    foreach ($cap->FeatureTypeList->FeatureType as $featureType) {
      $name = (string)$featureType->Name;
      if ($name == $typeName) {
        //echo "<pre>"; print_r($featureType);
        $crs = (string)$featureType->DefaultCRS;
        if (preg_match('!^urn:ogc:def:crs:EPSG::(\d+)$!', $crs, $matches))
          return 'EPSG:'.$matches[1];
        else
          return $crs;
      }
    }
    throw new Exception("Erreur dans WfsServer::defaultCrs, typeName $typeName non trouvé");
  }
  
  abstract function describeFeatureType(string $typeName): array;
  
  abstract function geomPropertyName(string $typeName): ?string;
  
  abstract function getNumberMatched(string $typename, array $bbox=[], string $where=''): int;
  
  abstract function getFeature(string $typename, array $bbox=[], int $zoom=-1, string $where='', int $count=100, int $startindex=0): string;

  abstract function printAllFeatures(string $typename, array $bbox=[], int $zoom=-1, string $where=''): void;
};

if (basename(__FILE__)<>basename($_SERVER['SCRIPT_NAME'])) return;

ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

if (!isset($_SERVER['PATH_INFO'])) {
  echo "<h3>Tests unitaires</h3><ul>\n";
  echo "<li><a href='$_SERVER[SCRIPT_NAME]/wfs2GeoJsonTest'>Test de la méthode WfsServerGml::wfs2GeoJson()</a>\n";
  echo "<li><a href='$_SERVER[SCRIPT_NAME]/getFeatureTest'>Test de la méthode WfsServerGml::getFeature()</a>\n";
  echo "</ul>\n";
  die();
}

$testMethod = substr($_SERVER['PATH_INFO'], 1);
$wfsDoc = ['wfsUrl'=>'test'];
$wfsServer = new WfsServerGml($wfsDoc, 'test');
$wfsServer->$testMethod();
