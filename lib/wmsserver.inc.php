<?php
/** définition de la classe abstraite WmsServer
 *
 * journal:
 * - 28-31/7/2022:
 *   - correction suite à analyse PhpStan level 6
 * - 8/6/2022:
 *   - migration shomgt v3
 *   - modif. gestion du log
 * - 9/11/2016
 *   - migration shomgt v2
 * - 9/11/2016
 *   - première version
 * @package shomgt\lib
 */ 

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);


/** classe abstraite WmsServer de gestion du dialogue du serveur avec le client
 *
 * Cette classe gère de manière minimum les protocole WMS 1.1.1 et 1.3.0 et fournit qqs méthodes génériques ;
 * elle est indépendante des fonctionnalités du serveur de shomgt.
 * Elle génère un fichier temporaire de log utile au déverminage
*/
abstract class WmsServer {
  static int $debug=0;
  static string $logfilename = __DIR__.'/wmsserver_logfile.txt'; // nom du fichier de logs par défaut
  
  /** possibilité de modifier le nom du fichier de log
   * @param array<string, string> $params */
  static function init(array $params): void {
    if (isset($params['logfilename']))
      self::$logfilename = $params['logfilename'];
  }
  
  /** écrit un message dans le fichier des logs */
  static function log(string $message): void {
    // Si le fichier de log n'a pas été modifié depuis plus de 5' alors il est remplacé
    $flag_append = (is_file(self::$logfilename) && (time() - filemtime(self::$logfilename) > 5*60)) ? 0 : FILE_APPEND;
    file_put_contents(
      self::$logfilename,
      date(DATE_ATOM)." : $message\n",
      $flag_append|LOCK_EX
    )
    or die("Erreur d'ecriture dans le fichier de logs dans WmsServer");
  }
  
  /** Envoi d'une exception WMS
   * @param int $httpErrorCode code d'erreur HTTP
   * @param string $mesUti message destiné à l'utilisateur
   * @param string $wmsErrorCode='' code erreur à renvoyer dans l'Exception, si '' pas de code d'erreur
   * @param string $mesSys='' message système écrit dans le log s'il est différent du message destiné à l'utilisateur, sinon ''
   */
  static function exception(int $httpErrorCode, string $mesUti, string $wmsErrorCode='', string $mesSys=''): never {
    static $httpErrorCodes = [
      400 => 'Bad Request', // paramètres en entrée incorrects
      401 => 'Unauthorized', // non utilisé
      403	=> 'Forbidden', // accès interdit
      404 => 'File Not Found', // ressource demandée non disponible
      500 => 'Internal Server Error', // erreur interne du serveur
    ];
    self::log($mesSys ? $mesSys : $mesUti);
    header('Access-Control-Allow-Origin: *');
    if (!isset($httpErrorCodes[$httpErrorCode]))
      $httpErrorCode = 500;
    header(sprintf('HTTP/1.1 %d %s', $httpErrorCode, $httpErrorCodes[$httpErrorCode]));
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
  
  /** La classe concrète doit fournir une méthode getCapabilities() */
  abstract function getCapabilities(string $version=''): never;
  
  /** La classe concrète doit fournir une méthode getMap() 
  * @param string $version
  * @param list<string> $lyrnames
  * @param list<string> $styles
  * @param list<string> $bbox
  * @param string $crs
  * @param int $width
  * @param int $height
  * @param string $format
  * @param string $transparent
  * @param string $bgcolor
  */
  abstract function getMap(string $version, array $lyrnames, array $styles, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor): never;
  
  /** La classe concrète peut fournir une méthode getFeatureInfo() 
  * @param array<int, string> $lyrnames
  * @param string $crs
  * @param TPos $pos
  * @param int $featureCount
  * @param list<float> $pixelSize
  * @param string $format
  */
  function getFeatureInfo(array $lyrnames, string $crs, array $pos, int $featureCount, array $pixelSize, string $format): never {
    die('');
  }
  
  /** traite une requête WMS
   * @param array<string, string> $params copie de _GET */
  function process(array $params): never {
    $GET = [];
    foreach ($params as $k=>$v)
      $GET[strtoupper($k)] = $v;

    // Il s'agit d'un serveur WMS
    if (!isset($GET['SERVICE']) || (strtoupper($GET['SERVICE']) <> 'WMS'))
      self::exception(400, "Le paramètre SERVICE doit valoir WMS", 'MissingParameter');

    // Toute requete doit au moins avoir un parametre REQUEST
    if (!isset($GET['REQUEST']))
      self::exception(400, "Parametre REQUEST non defini", 'MissingParameter');
    elseif (strtoupper($GET['REQUEST'])=='GETCAPABILITIES') {
      $this->getCapabilities(isset($GET['VERSION']) ? $GET['VERSION'] : '');
    }
    elseif (strtoupper($GET['REQUEST'])=='GETFEATUREINFO') {
      foreach (['QUERY_LAYERS','CRS','BBOX','WIDTH','HEIGHT','INFO_FORMAT'] as $param)
        if (!isset($GET[$param]))
          self::exception(400, "Parametre $param non defini", 'MissingParameter');
      $bbox = explode(',', $GET['BBOX']);
      $x = (intval($GET['I']) * (floatval($bbox[2])-floatval($bbox[0])) / intval($GET['WIDTH'])) + floatval($bbox[0]);
      $y = floatval($bbox[3]) - (intval($GET['J']) * (floatval($bbox[3])-floatval($bbox[1])) / intval($GET['HEIGHT']));
      $this->getFeatureInfo(
        lyrnames: explode(',', $GET['QUERY_LAYERS']),
        crs: $GET['CRS'],
        pos: [$x, $y],
        featureCount: $GET['FEATURE_COUNT'] ?? 10,
        pixelSize: [
          (floatval($bbox[2]) - floatval($bbox[0])) / intval($GET['WIDTH']),
          (floatval($bbox[3]) - floatval($bbox[1])) / intval($GET['HEIGHT'])],
        format: $GET['INFO_FORMAT']
      );
    }
    elseif (strtoupper($GET['REQUEST'])<>'GETMAP')
      self::exception(400, "Parametre REQUEST doit valoir GetCapabilities ou GetMap", 'InvalidRequest');

    // Vérification des paramètres pour le GetMap
    foreach (['VERSION','LAYERS','STYLES','BBOX','WIDTH','HEIGHT','FORMAT'] as $param)
      if (!isset($GET[$param]))
        self::exception(400, "Parametre $param non defini", 'MissingParameter');
    
    if (!in_array($GET['VERSION'],['1.1.1','1.3.0']))
      self::exception(400, "VERSION $GET[VERSION] non acceptée", 'InvalidRequest');
    
    if (!isset($GET[($GET['VERSION']=='1.3.0'?'CRS':'SRS')]))
      self::exception(400, "Parametre CRS/SRS non defini", 'MissingParameter');

    if (!in_array($GET['FORMAT'],['image/png','image/jpeg']))
      self::exception(400, "FORMAT $GET[FORMAT] non acceptée", 'InvalidRequest');
    
    $this->getMap(
      version: $GET['VERSION'],
      lyrnames: explode(',', $GET['LAYERS']),
      styles: (isset($GET['STYLES']) && $GET['STYLES']) ? explode(',', $GET['STYLES']) : [],
      bbox: explode(',',$GET['BBOX']),
      crs: $GET[($GET['VERSION']=='1.3.0'?'CRS':'SRS')],
      width: intval($GET['WIDTH']),
      height: intval($GET['HEIGHT']),
      format: $GET['FORMAT'],
      transparent: $GET['TRANSPARENT'] ?? '',
      bgcolor: $GET['BGCOLOR'] ?? '');
  }
}


// Code de test unitaire de cette classe
if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


/** Test d'utilisation de la classe WmsServer */
class WmsServerTest extends WmsServer {
  function getCapabilities(string $version=''): never {
    die("WmsServer::getCapabilities(version=$version)");
  }
  
  function getMap(string $version, array $lyrnames, array $styles, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor): never {
    die("WmsServer::getMap(version=".$version.", lyrnames=".implode(',',$lyrnames).", bbox=".implode(',',$bbox) 
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
<a href='?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=-180,-80,180,80&CRS=CRS:84&WIDTH=42718&HEIGHT=18986&LAYERS=gtpyr&STYLES=&FORMAT=image/png&DPI=96&MAP_RESOLUTION=96&FORMAT_OPTIONS=dpi:96&TRANSPARENT=TRUE'>width et height trop grands</a><br>
EOT;
} else {
  $server = new WmsServerTest;
  $server->process($_GET);
}
?>