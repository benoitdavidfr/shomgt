<?php
/*PhpDoc:
name: slaveupdt.php
title: slaveupdt.php - installe dans le portefeuille de shomgt une livraison issue du maitre, ne fonctionne que sur le RIE
doc: |
  script à appeler en ligne de commande
  si le catalogue du fil est plus récent que celui stocké alors le télécharge
  puis télécharge les cartes zippées plus récentes que les éventuelles cartes existantes
  puis appelle updt.php sur cette livraison
  et enfin efface les éventuelles cartes périmées

  Fonctionne avec un éventuel proxy mais sans possibilité d'autentification dans un premier temps.
  Correspond au cas d'usage potentiellement fréquent de mise en place d'un serveur à l'intérieur du RIE.
  
  Le proxy doit être défini comme variable globale Shell, exemple: export http_proxy="http://172.17.0.8:3128"

  L'authentification par login/passwd n'est pas prévue à ce stade.

journal: |
  7/1/2021:
    -limitation à une zone
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

$atomfeedUrl = ((($_SERVER['HTTP_HOST'] ?? null) == 'localhost') || (unix_env()['HOSTNAME']=='dmac')) ?
  'http://localhost/geoapi/shomgt/master/atomfeed.php' // test en localhost
  : 'https://geoapi.fr/shomgt/master/atomfeed.php'; // fonctionnement normal

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

// classe regroupant les infos de mise à jour 
class UpdtSlave {
  public array $catalog=[]; // [href, updated]
  public array $todelete=[]; // [mapid => title]
  public array $toadd=[]; // [mapid => ['href'=> href, 'updated'=> updated, 'zonesGeo'=> listeDeZones]]

  static function streamContext() { // fabrique un context si un proxy est défini, sinon renvoie null
    if (!http_proxy())
      return null;
    return stream_context_create([
      'http'=> [
        'method'=> 'GET',
        'proxy'=> str_replace('http://', 'tcp://', http_proxy()),
      ]
    ]);
  }
 
  static function zonesGeo(array $category): array { // transforme un mot-clé ou une liste en liste de codes ISO2
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

// Liste des codes et libellés des zones
define ('ZONES', [
  'WLD'=> "toutes les cartes",
  'FR'=> "France",
  'FX'=> "France métropolitaine",
  'GP'=> "Guadeloupe",
  'MQ'=> "Martinique",
  'GF'=> "Guyane",
  'RE'=> "La Réunion",
  'YT'=> "Mayotte",
  'PM'=> "Saint-Pierre-et-Miquelon",
  'BL'=> "Saint-Barthélémy",
  'MF'=> "Saint-Martin",
  'TF'=> "Terres australes et antarctiques françaises",
  'PF'=> "Polynésie française",
  'WF'=> "Wallis-et-Futuna",
  'NC'=> "Nouvelle-Calédonie",
  'CP'=> "Île Clipperton",
]
);
if (php_sapi_name() == 'cli') {
  header('Content-type: text/plain; charset="utf8"');
  if ($argc <= 1) {
    echo "echo 'Mettre à jour sur quelle zone ?'\n";
    foreach (ZONES as $id => $label)
      echo "echo '  - $id pour $label'\n";
    echo "echo 'Possibilité de définir plusieurs zones séparées par des virgules, ee: GP,MQ,BL,MF'\n";
    die("\n");
  }
  else {
    $zonesGeo = ($argv[1] == 'WLD') ? [] : explode(',', $argv[1]);
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

$updtSlave = new UpdtSlave($atomfeedUrl, $zonesGeo);
//print_r($atomfeed);

$wget_proxy = http_proxy() ? ' -e use_proxy=on -e http_proxy='.http_proxy() : '';
$mapcatpath = __DIR__.'/../cat2/mapcat.yaml';
if (!file_exists($mapcatpath)
  || ($updtSlave->catalog['updated'] > date('Y-m-d\TH:i:s\Z', filemtime($mapcatpath)))) {
  echo "echo 'Mise à jour du catalogue'\n";
  $href = $updtSlave->catalog['href'];
  echo "wget $href -O $mapcatpath$wget_proxy\n";
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
    echo "wget $newMap[href] -O $shomgeotiff/incoming/slave/$mapnum.7z$wget_proxy\n";
  }
  else {
    echo "echo 'La carte $mapid est à jour'\n";
  }
}

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
