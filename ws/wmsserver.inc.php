<?php
/*PhpDoc:
name: wmsserver.inc.php
title: wmsserver.inc.php - définition de la classe abstraite WmsServer
classes:
doc: |
  Cette classe est indépendante des fonctionnalités du serveur de shomgt
journal: |
  9/11/2016
    migration shomgt v2
  9/11/2016
    première version
*/ 

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);


/*PhpDoc: classes
name: class WmsServer
title: abstract class WmsServer - classe abstraite WmsServer de gestion du dialogue entre le client et le serveur
doc: |
*/
abstract class WmsServer {
  static $debug=0;
  static $logfilename = __DIR__.'/wmsserver_logfile.txt'; // nom du fichier de logs
  
  static function init(array $params) {
    if (isset($params['logfilename']))
      self::$logfilename = $params['logfilename'];
  }
  
  // écrit un message dans le fichier des logs
  static function log(string $message): void {
    file_put_contents(
      self::$logfilename,
      date('Y-m-d').'T'.date('H:i:s')." : $message\n",
      FILE_APPEND
    )
    or die("Erreur d'ecriture dans le fichier de logs dans WmsServer");
  }
  
  /* Envoi d'une exception WMS
  httpErrorCode : code d'erreur HTTP
  mesUti : message destiné à l'utilisateur
  mesSys : message système s'il est différent, sinon null
  wmsErrorCode : code erreur à renvoyer dans l'Exception, si null pas de code d'erreur
  */
  static function exception(int $httpErrorCode, string $mesUti, string $wmsErrorCode='', string $mesSys='') {
    $httpErrorCodes = [
      400 => 'Bad Request', // paramètres en entrée incorrects
      401 => 'Unauthorized', // non utilisé
      403	=> 'Forbidden', // accès interdit
      404 => 'File Not Found', // ressource demandée non disponible
      500 => 'Internal Server Error', // erreur interne du serveur
    ];
    if (!$mesSys)
      $mesSys = $mesUti;
    self::log($mesSys);
    if (isset($httpErrorCodes[$httpErrorCode]))
      header(sprintf('HTTP/1.1 %d %s', $httpErrorCode, $httpErrorCodes[$httpErrorCode]));
    else
      header(sprintf('HTTP/1.1 500 %s', $httpErrorCodes[500]));
    if (!self::$debug)
      header('Content-type: text/xml');
    $format = <<<EOT
<?xml version='1.0' encoding="UTF-8"?>
<ExceptionReport>
<ServiceException%s>
%s
</ServiceException>
</ExceptionReport>
EOT;
    die(sprintf($format, $wmsErrorCode?' exceptionCode="'.$wmsErrorCode.'"' : '', $mesUti));
  }
  
  abstract function getCapabilities(string $version='');
  
  abstract function getMap(string $version, array $layers, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor);
  
  // traite une requête WMS
  function process(array $params) {
    // copie de _GET avec les noms des paramètres en majuscules
    $GET = [];
    foreach ($params as $k=>$v)
      $GET[strtoupper($k)] = $v;

    // Il s'agit d'un serveur WMS
    if (!isset($GET['SERVICE']) || (strtoupper($GET['SERVICE']<>'WMS')))
      self::exception(400, "Le paramètre SERVICE doit valoir WMS", 'MissingParameter');

    // Toute requete doit au moins avoir un parametre REQUEST
    if (!isset($GET['REQUEST']))
      self::exception(400, "Parametre REQUEST non defini", 'MissingParameter');

    if (strtoupper($GET['REQUEST'])=='GETCAPABILITIES') {
      $this->getCapabilities(isset($GET['VERSION']) ? $GET['VERSION'] : '');
      die();
    }
    
// Vérification des paramètres pour le GetMap
    foreach (['VERSION','LAYERS','BBOX','WIDTH','HEIGHT','FORMAT'] as $param)
      if (!isset($GET[$param]))
        self::exception(400, "Parametre $param non defini", 'MissingParameter');
    
    if (!in_array($GET['VERSION'],['1.1.1','1.3.0']))
      self::exception(400, "VERSION $GET[VERSION] non acceptée");
    
    if (!isset($GET[($GET['VERSION']=='1.3.0'?'CRS':'SRS')]))
      self::exception(400, "Parametre CRS/SRS non defini", 'MissingParameter');
    
    $this->getMap($GET['VERSION'], explode(',',$GET['LAYERS']), explode(',',$GET['BBOX']),
                  $GET[($GET['VERSION']=='1.3.0'?'CRS':'SRS')],
                  $GET['WIDTH'], $GET['HEIGHT'], $GET['FORMAT'],
                  isset($GET['TRANSPARENT']) ? $GET['TRANSPARENT'] : '',
                  isset($GET['BGCOLOR']) ? $GET['BGCOLOR'] : '');
    die();
  }
}


// Code de test unitaire de cette classe
if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


class WmsServerTest extends WmsServer {
  function getCapabilities(string $version='') {
    die("WmsServer::getCapabilities(version=$version)");
  }
  
  function getMap($version, $layers, $bbox, $crs, $width, $height, $format, $transparent, $bgcolor) {
    die("WmsServer::getMap(version=".$version.", layers=".implode(',',$layers).", bbox=".implode(',',$bbox) 
        .", crs=$crs, width=$width, height=$height, format=$format, transparent=$transparent)");
  }
};

if (!isset($_GET['SERVICE'])) {
  echo <<<EOT
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><title>wmsserver</title></head>
<h3>URL de Test</h3>
<a href='?SERVICE=xxx'>SERVICE=xxx</a><br>
<a href='?SERVICE=WMS'>REQUEST non défini</a><br>
<a href='?SERVICE=WMS&REQUEST=xxx'>REQUEST=xxx</a><br>
<a href='?SERVICE=WMS&REQUEST=GetCapabilities'>GetCapabilities</a><br>
<a href='?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetCapabilities'>GetCapabilities version 1.3.0</a><br>
<a href='?SERVICE=WMS&version=1.3.0&request=GetMap&layers=LAYER'>GetMap partiel</a><br>
<a href='?SERVICE=WMS&version=0&request=GetMap&layers=LAYER&bbox=838000,6661600,838150,6661690&crs=EPSG:2154&width=1000&height=600&format=image/png&styles='>GetMap version incorrecte</a><br>
<a href='?SERVICE=WMS&version=1.3.0&request=GetMap&layers=LAYER&bbox=838000,6661600,838150,6661690&srs=EPSG:2154&width=1000&height=600&format=image/png&styles='>GetMap CRS non défini</a><br>
<a href='?SERVICE=WMS&version=1.3.0&request=GetMap&layers=LAYER&bbox=838000,6661600,838150,6661690&crs=EPSG:2154&width=1000&height=600&format=image/png&styles='>GetMap</a><br>

EOT;
} else {
  $server = new WmsServerTest;
  $server->process($_GET);
  die("OK ligne ".__LINE__);
}
?>