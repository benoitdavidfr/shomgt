<?php
/*PhpDoc:
name: slaveupdt.php
title: slaveupdt.php - installe dans le portefeuille de shomgt une livraison issue du maitre
doc: |
  script à appeler en ligne de commande
  si le catalogue du fil Atom est plus récent que celui stocké alors le télécharge
  puis télécharge les cartes zippées plus récentes que les éventuelles cartes existantes
  puis appelle updt.php sur cette livraison
  et enfin efface les éventuelles cartes périmées

  Fonctionne avec un éventuel proxy qui doit alors être défini comme variable globale Shell http_proxy,
  exemple: export http_proxy="http://172.17.0.8:3128"

  L'authentification par login/passwd sur le maitre s'effectue en définissant la variable globale Shell shomgtuserpwd,
  exemple: export shomgtuserpwd='demo:demo'
journal: |
  8/1/2021:
    - ajout authentification par login/passwd
  7/1/2021:
    - possibilité de limiter à une zone
    - tests
  4/1/2021:
    - gestion du proxy
  3/1/2021:
    - création
    - première version minimum
    - écrire la doc
includes:
  - ../lib/xmltoarrayparser.inc.php
  - ../lib/store.inc.php
*/
require_once __DIR__.'/../lib/xmltoarrayparser.inc.php';
require_once __DIR__.'/../lib/store.inc.php';

//$atomfeedUrl = 'http://localhost/geoapi/shomgt/master/atomfeed.php'; // test en localhost
$atomfeedUrl = 'https://geoapi.fr/shomgt/master/atomfeed.php'; // fonctionnement normal

function unix_env(): array { // retourne les variables d'environnement du shell 
  $env = [];
  foreach (explode("\n", `env`) as $var) {
    if ($pos = strpos($var, '='))
      $env[substr($var, 0, $pos)] = substr($var, $pos+1);
  }
  return $env;
}

// Proxy éventuellement défini dans l'environnement shell
function http_proxy(): string { return unix_env()['http_proxy'] ?? ''; }

// login/passwd éventuellement défini dans l'environnement shell et si défini restructuré en login={login}&password={password}
function shomgtloginpwd(): string {
  if (!($shomgtuserpwd = unix_env()['shomgtuserpwd'] ?? ''))
    return '';
  $pos = strpos($shomgtuserpwd, ':');
  $login = substr($shomgtuserpwd, 0, $pos);
  $passwd = substr($shomgtuserpwd, $pos+1);
  return "login=".urlencode($login)."&password=".urlencode($passwd);
}

// classe regroupant les infos de mise à jour 
class UpdtSlave {
  public array $catalog=[]; // [href, updated]
  public array $todelete=[]; // [mapid => title]
  public array $toadd=[]; // [mapid => ['href'=> href, 'updated'=> updated, 'zonesGeo'=> listeDeZones]]

  /*static function streamContext() { // fabrique un context si un proxy est défini, sinon renvoie null
    if (!http_proxy())
      return null;
    return stream_context_create([
      'http'=> [
        'method'=> 'GET',
        'proxy'=> str_replace('http://', 'tcp://', http_proxy()),
      ]
    ]);
  }*/

 /*static function streamContext() { // fabrique un context si un proxy ou un userpwd est défini, sinon renvoie null
    if (!($http_proxy = http_proxy()) && !($shomgtuserpwd = shomgtuserpwd()))
      return null;
    $httpOpts = ['method'=> 'GET'];
    if ($http_proxy) {
      $httpOpts['proxy'] = str_replace('http://', 'tcp://', $http_proxy);
    }
    if ($shomgtuserpwd) {
      $httpOpts['header'] = "Accept-language: en\r\n"."Cookie: shomusrpwd=$shomgtuserpwd\r\n";
    }
    return stream_context_create(['http'=> $httpOpts]);
  }*/
  
  static function streamContext() { // fabrique un context si un proxy ou un userpwd est défini, sinon renvoie null
    if (!($http_proxy = http_proxy()) && !($shomgtloginpwd = shomgtloginpwd()))
      return null;
    if (!$shomgtloginpwd) {
      return stream_context_create([
        'http'=> [
          'method'=> 'GET',
          'proxy'=> str_replace('http://', 'tcp://', $http_proxy),
        ]
      ]);
    }
    else {
      $httpOpts = [
        'method'=> 'POST',
        'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
            ."Content-Length: ".strlen($shomgtloginpwd)."\r\n",
        'content' => $shomgtloginpwd,
      ];
      if ($http_proxy) {
        $httpOpts['proxy'] = str_replace('http://', 'tcp://', $http_proxy);
      }
      return stream_context_create(['http'=> $httpOpts]);
    }
  }
   
  static function zonesGeo(array $category): array { // transforme un mot-clé ou une liste de codes ISO2
    //echo 'category='; print_r($category);
    if (isset($category['attrib']))
      return [substr($category['attrib']['term'], strlen('https://id.georef.eu/dc-spatial/'))];
    else {
      $list = [];
      foreach ($category as $categ) {
        $list[] = substr($categ['attrib']['term'], strlen('https://id.georef.eu/dc-spatial/'));
      }
      return $list;
    }
  }
  
  function __construct(string $url, array $zonesGeoDemandees) {
    if (!($xml = @file_get_contents($url, false, self::streamContext()))) {
      $error = "Erreur ouverture de $url impossible";
      $error .= http_proxy() ? ", avec proxy ".http_proxy() : ", sans proxy";
      if (isset($http_response_header))
        $error .= ", raison $http_response_header[0]";
      die("echo '$error'\n");
    }

    //var_dump($xml);
    //echo "$xml\n"; die();
    $domObj = new xmlToArrayParser($xml);
    $atomfeed = $domObj->array;
    //echo '$atomfeed='; print_r($atomfeed);

    if($domObj->parse_error)
      die($domObj->get_xml_error());

    // S'il ya qu'une seule entrée je la met dans un tableau
    if (isset($atomfeed['feed']['entry']['title']))
      $atomfeed['feed']['entry'] = [ $atomfeed['feed']['entry'] ];
    
    foreach ($atomfeed['feed']['entry'] as $entry) {
      if (isset($entry['link']['attrib'])) { // 1 seul lien => suppression
        //echo '$entry='; print_r($entry);
        $mapid = substr($entry['link']['attrib']['href'], -6);
        $this->todelete[$mapid] = $entry['title'];
        //echo "$entry[title]\n";
      }
      else {
        //print_r($entry);
        foreach ($entry['link'] as $link) {
          //print_r($link);
          if ($link['attrib']['type'] == 'text/vnd.yaml') { // catalog 
            //echo "Catalogue ",$link['attrib']['href'],"\n";
            $this->catalog = [
              'href'=> $link['attrib']['href'],
              'updated'=> $entry['updated'],
            ];
          }
          elseif ($link['attrib']['type'] == 'application/x-7z-compressed') { // carte à ajouter 
            //echo '$entry='; print_r($entry);
            $mapid = substr($entry['id'], -6);
            //echo "Ajout ",$link['attrib']['href'],"\n";
            $zonesGeo = isset($entry['category']) ? self::zonesGeo($entry['category']) : [];
            if (!$zonesGeoDemandees || ($zonesGeo==['FR']) || array_intersect($zonesGeoDemandees, $zonesGeo)) {
              $this->toadd[$mapid] = [
                'href'=> $link['attrib']['href'],
                'updated'=> $entry['updated'],
                'zonesGeo'=> $zonesGeo,
              ];
              //echo '$toadd='; print_r($this->toadd);
            }
          }
        }
      }
    }
  }
  
  // indique si la carte doit être mise à jour
  function updateMap(string $mapid): bool {
    $mapnum = substr($mapid, 2);
    if (!CurrentGeoTiff::mapExists($mapnum))
      return true;
    $mdiso19139 = CurrentGeoTiff::mdiso19139FromNum($mapnum); // les MD ISO de current ou []
    //print_r($mdiso19139);
    $mdDate = $mdiso19139 ? substr($mdiso19139['mdDate'], 0, 10) : null; // la date des MD ou null
    $updated = substr($this->toadd[$mapid]['updated'], 0, 10); // la partie date
    return (!$mdDate || ($updated > $mdDate));
  }
};

// initialise la ou les zones souhaitées
if (php_sapi_name() == 'cli') {
  header('Content-type: text/plain; charset="utf8"');
  if ($argc <= 1) {
    echo "echo 'Mettre à jour sur quelle(s) zone(s) ?'\n";
    echo "echo '  - WLD pour toutes les cartes'\n";
    foreach (CurrentGeoTiff::ZONES as $id => $label)
      echo "echo '  - $id pour $label'\n";
    echo "echo 'Possibilité de définir plusieurs zones séparées par des virgules, ee: GP,MQ,BL,MF'\n";
    die("\n");
  }
  else {
    $zonesGeo = ($argv[1] == 'WLD') ? [] : explode(',', $argv[1]);
    $zonesInconnues = [];
    foreach ($zonesGeo as $zid) {
      if (!isset(CurrentGeoTiff::ZONES[$zid]))
        $zonesInconnues[] = $zid;
    }
    if ($zonesInconnues)
      die("echo 'Erreur: zone(s) ".implode(',',$zonesInconnues)." inconnue(s)'\n");
  }
}
else {
  if (!isset($_GET['geo'])) {
    echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>slaveupdt</title></head><body>\n";
    echo "Mettre à jour sur quelle zone ?<ul>\n";
    foreach (ZONES as $id => $label) {
      echo "<li><a href='?geo=$id'>$label</a></li>\n";
    }
    die("</ul>\n");
  }
  else {
    echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>slaveupdt</title></head><body><pre>\n";
    $zonesGeo = ($_GET['geo'] == 'WLD') ? [] : explode(',', $_GET['geo']);
  }
}

// Définit le fuseau horaire par défaut à utiliser.
date_default_timezone_set('UTC');

// Lit le fil Atom et structure le résultat comme un objet
$updtSlave = new UpdtSlave($atomfeedUrl, $zonesGeo);

$wgetOptions = (http_proxy() ? ' -e use_proxy=on -e http_proxy='.http_proxy() : '')
  .(($loginpwd = shomgtloginpwd()) ? " --post-data='$loginpwd'" : '');
$mapcatpath = __DIR__.'/../cat2/mapcat.yaml';
if (!file_exists($mapcatpath)
  || ($updtSlave->catalog['updated'] > date('Y-m-d\TH:i:s\Z', filemtime($mapcatpath)))) {
  echo "echo 'Mise à jour du catalogue'\n";
  $href = $updtSlave->catalog['href'];
  echo "wget$wgetOptions -O $mapcatpath $href\n";
  // pour que le yaml soit bien pris en compte le pser doit être effacé
  if (file_exists(__DIR__.'/../cat2/mapcat.pser'))
    unlink(__DIR__.'/../cat2/mapcat.pser');
}

$shomgeotiff = __DIR__.'/../../../shomgeotiff';
if (!file_exists($shomgeotiff))
  mkdir($shomgeotiff);
$shomgeotiff = realpath($shomgeotiff);
if (!file_exists("$shomgeotiff/incoming"))
  mkdir("$shomgeotiff/incoming");
if (!file_exists("$shomgeotiff/incoming/slave"))
  mkdir("$shomgeotiff/incoming/slave");
if (!file_exists("$shomgeotiff/current"))
  mkdir("$shomgeotiff/current");
foreach ($updtSlave->toadd as $mapid => $newMap) {
  if ($updtSlave->updateMap($mapid)) {
    echo "echo 'Mise à jour de la carte $mapid'\n";
    $mapnum = substr($mapid, 2);
    echo "wget$wgetOptions $newMap[href] -O $shomgeotiff/incoming/slave/$mapnum.7z\n";
  }
  else {
    echo "echo 'La carte $mapid est à jour'\n";
  }
}

// installe les cartes stockées dans la livraison slave
echo "php updt.php slave | sh\n";

echo "echo 'Suppression du répertoire $shomgeotiff/incoming/slave'\n";
echo "rm -r $shomgeotiff/incoming/slave\n";

foreach (array_keys($updtSlave->todelete) as $mapid) {
  $mapnum = substr($mapid, 2);
  if (file_exists("$shomgeotiff/current/$mapnum")) {
    echo "echo 'Suppression de la carte $mapid obsolète'\n";
    echo "rm -r $shomgeotiff/current/$mapnum\n";
  }
}
