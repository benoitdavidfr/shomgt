<?php
/*PhpDoc:
title: sgserver/index.php - serveur de carte 7z
name: index.php
doc: |
  Le serveur expose les cartes disponibles sous la forme d'archives 7z ainsi que le catalogue mapcat.yaml
  selon l'API définie ci-dessous.
    
  La variable d'environnement SHOMGT3_INCOMING_PATH est utilisée pour définir un serveur pour les tests ne contenant
  que quelques cartes.
  
  api:
    /: page d'accueil utilisée pour proposer des URL de tests
    /api: retourne la description de l'api (à faire)
    /cat.json: retourne mapcat.json 
    /maps.json: liste en JSON l'ensemble des cartes disponibles indexées par leur numéro et avec les informations suivantes
      status: 'ok' | 'obsolete'
      nbre: nbre de versions disponibles
      lastVersion: identifiant de la dernière version 
      url: lien vers la page associée au numéro de carte
    /maps/{numCarte}.json: liste en JSON l'ensemble des versions disponibles avec un lien vers les 2 entrées suivantes
    /maps/{numCarte}-{annéeEdition}c{derCorr}.7z: retourne le 7z de la carte dans la version définie en paramètre
    /maps/{numCarte}-{annéeEdition}c{derCorr}.png: retourne la vignette de la carte dans la version définie en paramètre
    /maps/{numCarte}.7z: retourne le 7z de la carte dans sa dernière version
    
  Utilisation du code Http de retour pour gérer les erreurs pour cat et newer:
    - 200 - Ok - il existe bien un document et le voici
    - 400 - Bad Request - requête incorrecte
    - 404 - Not Found - le document demandé n'a pas été trouvé

  Les 7z sont stockés dans le répertoire défini par la var d'env. SHOMGT3_INCOMING_PATH avec un répertoire par livraison
  nommé avec un nom commencant par la date de livraison sous la forme YYYYMM et idéalement un fichier index.yaml
journal: |
  30/5/2022:
    - correction d'un bug
    - ajout d'un champ 'lastVersion' pour chaque carte dans le document maps.json
    - stockage temporaire de maps.json maintenant long à calculer
    - tri des répertoires de incoming pas forcément bien ordonnés
    - rempl. /map/... par /maps/...
    - suppression entrée API /map/{numCarte}/newer/{annéeEdition}c{derCorr}.7z
    - suppression entrée API /cat/{date}.json
  24/5/2022:
    - modification de la gestion du fichier newermap.pser car gestion buggé
    - correction d'un bug
  22/5/2022:
    - mise en variable d'environnement de SHOMGT3_INCOMING_PATH pour permettre des tests sur moins de cartes
  19/5/2022:
    - ajout gestion du contrôle d'accès et modif gestion erreurs Http
  18/5/2022:
    - passage des 2 entrées catalogue en JSON
    - correction de 2 bugs
  16/5/2022:
    - utilisation fpassthru() à la place de passthru() pour améliorer la restitution du 7z
    - ajout de logRecord()
    - correction d'un bug dans findNewerMap()
  15/5/2022:
    - ajout de la gestion des cartes obsolètes
    - chgt de l'API pour la carte plus récente que la version fournie
    - ajout liste des cartes, liste des versions d'ue carte, téléchargement PNG ou 7z d'une version particulière d'une carte
    - utilisation passthru() à la place de file_get_contents() pour éviter une explosion mémoire
  11/5/2022:
    - création
*/
//define ('DEBUG', true); // le mode DEBUG facilite le test interactif du serveur
if (!defined('DEBUG'))
  define ('DEBUG', false); // le mode !DEBUG doit être utilisé pour fonctionner avec sgupdt

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib/accesscntrl.inc.php';

use Symfony\Component\Yaml\Yaml;

// enregistrement d'un log temporaire pour aider au déverminage
function logRecord(array $log): void {
  // Si le log n'a pas été modifié depuis plus de 5' alors il est effacé
  if (is_file(__DIR__.'/log.yaml') && (time() - filemtime(__DIR__.'/log.yaml') > 5*60))
    unlink(__DIR__.'/log.yaml');
  file_put_contents(__DIR__.'/log.yaml',
    Yaml::dump([
      date(DATE_ATOM)=>
        array_merge(
          [
            'path_info'=> $_SERVER['PATH_INFO'] ?? null,
            'PHP_AUTH_USER'=> $_SERVER['PHP_AUTH_USER'] ?? null,
            'REMOTE_ADDR'=> $_SERVER['REMOTE_ADDR'] ?? null,
            'HTTP_X_FORWARDED_FOR'=> $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            //'SERVER'=> $_SERVER,
          ],
          $log
        )
      ]
    ),
    FILE_APPEND|LOCK_EX);
}

//logRecord($_SERVER);

define ('HTTP_ERROR_CODES', [
  204 => 'No Content', // Requête traitée avec succès mais pas d’information à renvoyer. 
  400 => 'Bad Request', // paramètres en entrée incorrects
  401 => 'Unauthorized', // Une authentification est nécessaire pour accéder à la ressource. 
  403	=> 'Forbidden', // accès interdit
  404 => 'File Not Found', // ressource demandée non disponible
  410 => 'Gone', // La ressource n'est plus disponible et aucune adresse de redirection n’est connue
  500 => 'Internal Server Error', // erreur interne du serveur
]
);

// Génère une erreur Http et un message utilisateur avec un content-type text ; enregistre un log avec un éventuel message sys
function sendHttpCode(int $httpErrorCode, string $mesUti, string $mesSys=''): void {
  logRecord([
    'httpErrorCode'=> $httpErrorCode,
    'mesUti'=> $mesUti,
    'mesSys'=> $mesSys ? $mesSys : HTTP_ERROR_CODES[$httpErrorCode] ?? HTTP_ERROR_CODES[500]
  ]);
  header('Access-Control-Allow-Origin: *');
  header('Content-type: text/plain; charset="utf-8"');
  if (isset(HTTP_ERROR_CODES[$httpErrorCode]))
    header(sprintf('HTTP/1.1 %d %s', $httpErrorCode, HTTP_ERROR_CODES[$httpErrorCode]));
  else
    header(sprintf('HTTP/1.1 500 %s', HTTP_ERROR_CODES[500]));
  die("$mesUti\n");
}

// Mécanisme de contrôle d'accès sur l'IP et le login / mdp
// Si le contrôle est activé et s'il est refusé alors demande d'authentification
try {
  if (Access::cntrlFor('sgServer') && !Access::cntrl(null,true)) {
    // Si la requete ne comporte pas d'utilisateur, alors renvoie d'une demande d'authentification 401
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
      write_log(false);
      header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
      sendHttpCode(401, "Erreur, depuis cette adresse IP ($_SERVER[REMOTE_ADDR]), ce service nécessite une authentification");
    }
    // Si la requête comporte un utilisateur alors vérification du login/mdp
    elseif (!Access::cntrl("$_SERVER[PHP_AUTH_USER]:$_SERVER[PHP_AUTH_PW]")) {
      write_log(false);
      header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
      sendHttpCode(401, "Erreur d'authentification pour \"$_SERVER[PHP_AUTH_USER]\"");
    }
  }
  write_log(true);
}
// notamment si les paramètres MySQL sont corrects mais que la base MySql correspondante n'existe pas
catch (Exception $e) {
  sendHttpCode(500, "Erreur dans le contrôle d'accès", $e->getMessage());
}

if (!($INCOMING_PATH = getenv('SHOMGT3_INCOMING_PATH')))
  throw new Exception("Erreur, variable d'env. SHOMGT3_INCOMING_PATH non définie");
if (!is_dir($INCOMING_PATH))
  throw new Exception("Erreur, SHOMGT3_INCOMING_PATH ne correspond pas au chemin d'un répertoire");

//date_default_timezone_set('UTC');

if (!($_SERVER['PATH_INFO'] ?? null)) {
  echo "Menu:<br>\n";
  echo " - <a href='index.php/cat.json'>cat.json</a><br>\n";
  echo date(DATE_ATOM),"<br>\n";
  $date = urlencode(date(DATE_ATOM));
  $url = "index.php/cat/$date.json";
  echo " - <a href='$url'>$url</a><br>\n";

  $error = error_get_last();
  print_r($error);

  $url = "index.php/maps/6969.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  $url = "index.php/maps/6969-2021c0.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  $url = "index.php/maps/6969-2020c9.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  $url = "index.php/maps/7330-undefined.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  die();
}

if ($_SERVER['PATH_INFO'] == '/cat.json') { // envoi de mapcat 
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode(
    Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml'),
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  logRecord(['done'=> "ok - mapcat.json"]);
  die();
}

if ($_SERVER['PATH_INFO'] == '/logout') { // action complémentaire pour raz le login en interactif 
  header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
  sendHttpCode(401, isset($_SERVER['PHP_AUTH_USER']) ? "Logout, user: $_SERVER[PHP_AUTH_USER]" : 'Logout, no user');
}
  
/*if (preg_match('!^/cat/(\d\d\d\d)-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)\+00:00\.json$!', $_SERVER['PATH_INFO'], $matches)) {
  $ptime = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
  $dmfyaml = date(DATE_ATOM, filemtime(__DIR__.'/../mapcat/mapcat.yaml'));
  if (filemtime(__DIR__.'/../mapcat/mapcat.yaml') > $ptime) { // fichier plus récent 
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(
      Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml'),
      JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    logRecord(['done'=> "ok - mapcat.json"]);
    die();
  }
  else { // fichier pas plus récent
    sendHttpCode(DEBUG ? 404 : 204, "La date de dernière modification de mapcat.yaml est antérieure à ".date(DATE_ATOM, $ptime));
  }
}*/


require_once __DIR__.'/lib/SevenZipArchive.php';
require_once __DIR__.'/lib/readmapversion.inc.php';

function deliveries(string $INCOMING_PATH): array { // liste des livraisons triées 
  $deliveries = []; // liste des livraisons qu'il est nécessaire de trier 
  foreach (new DirectoryIterator($INCOMING_PATH) as $delivery) {
    if (($delivery->getType() == 'dir') && !$delivery->isDot()) {
      $deliveries[] = $delivery->getFilename();
    }
  }
  sort($deliveries, SORT_STRING);
  return $deliveries;
}

if ($_SERVER['PATH_INFO'] == '/maps.json') { // liste en JSON l'ensemble des cartes avec un lien vers l'entrée suivante
  $mapsPath = $INCOMING_PATH.'/../maps.json';
  if (is_file($mapsPath) && (filemtime($mapsPath) > filemtime($INCOMING_PATH))) {
    header('Content-type: application/json');
    fpassthru(fopen($mapsPath, 'r'));
    logRecord(['done'=> "OK - $mapsPath transmis"]);
    die();
  }

  $maps = []; /* [{mapnum} => [
    'status'=> 'ok' | 'obsolete'
    'nbre'=> nbre de téléchargements disponibles
    'lastVersion'=> identifiant de la dernière version 
    'url'=> lien vers la page associée au numéro de carte
  ]]*/
  foreach (deliveries($INCOMING_PATH) as $delivery) {
    //echo "* $delivery<br>\n";
    // Prise en compte des cartes que cette livraison rend obsolètes
    if (is_file("$INCOMING_PATH/$delivery/index.yaml")) {
      $index = Yaml::parseFile("$INCOMING_PATH/$delivery/index.yaml");
      foreach (array_keys($index['toDelete'] ?? []) as $mapid) {
        $mapnum = substr($mapid, 2);
        //echo "** $mapnum obsolete<br>\n";
        $maps[$mapnum]['status'] = 'obsolete';
      }
    }
    foreach (new DirectoryIterator("$INCOMING_PATH/$delivery") as $map7z)  {
      if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z')) {
        //echo "- carte $map7z<br>\n";
        $mapnum = $map7z->getBasename('.7z');
        //echo "** $mapnum valide<br>\n";
        if (!isset($maps[$mapnum])) {
          $maps[$mapnum] = [
            'status'=> 'ok',
            'nbre'=> 1,
            'lastVersion'=> getMapVersionFrom7z("$INCOMING_PATH/$delivery/$map7z"),
            'url'=> "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/maps/$mapnum.json",
          ];
        }
        else {
          $maps[$mapnum]['status'] = 'ok';
          $maps[$mapnum]['nbre']++;
          $maps[$mapnum]['lastVersion'] = getMapVersionFrom7z("$INCOMING_PATH/$delivery/$map7z");
        }
      }
    }
  }
  ksort($maps, SORT_STRING);
  //echo "<pre>maps="; print_r($maps);
  header('Content-type: application/json');
  echo json_encode($maps, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  file_put_contents($mapsPath,
    json_encode($maps, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
  logRecord(['done'=> "OK - maps.json transmis"]);
  die();
}

// Renvoie pour une carte $mapnum le chemin du 7z de sa dernière livraison ou '' s'il n'y en a aucune.
function findLastDelivery(string $INCOMING_PATH, string $mapnum): string {
  //echo "findLastDelivery($mapnum)<br>\n";
  // construction du fichier lastdelivery.pser contenant pour chaque numéro de carte le nom de sa dernière livraison
  // La localisation de ce fichier doit dépendre de $INCOMING_PATH pour ne pas confondre les données entre les diff. serveurs
  // De plus, il ne doit pas être dans $INCOMING_PATH car sa création modifierait la date de mise à jour d'$INCOMING_PATH
  $lastDeliveryPath = $INCOMING_PATH.'/../lastdelivery.pser';
  if (is_file($lastDeliveryPath) && (filemtime($lastDeliveryPath) > filemtime($INCOMING_PATH))) {
    $lastDeliveries = unserialize(file_get_contents($lastDeliveryPath));
  }
  else {
    foreach (deliveries($INCOMING_PATH) as $delivery) {
      //echo "* $delivery<br>\n";
      foreach (new DirectoryIterator("$INCOMING_PATH/$delivery") as $map7z)  {
        if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z')) {
          //echo "- carte $map7z<br>\n";
          $mn = $map7z->getBasename('.7z');
          $lastDeliveries[$mn] = $delivery;
        }
      }
    }
    file_put_contents($lastDeliveryPath, serialize($lastDeliveries));
  }
  
  if (!isset($lastDeliveries[$mapnum])) {
    return '';
  }
  else {
    $delivery = $lastDeliveries[$mapnum];
    return "$INCOMING_PATH/$delivery/$mapnum.7z";
  }
}

// /maps/{numCarte}.7z: retourne le 7z de la dernière version de la carte
if (preg_match('!^/maps/(\d\d\d\d)\.7z$!', $_SERVER['PATH_INFO'], $matches)) {
  $mapnum = $matches[1];
  $mappath = findLastDelivery($INCOMING_PATH, $mapnum);
  if (!$mappath) {
    sendHttpCode(404, "Carte $mapnum non trouvée");
  }
  else {
    header('Content-type: application/x-7z-compressed');
    //echo file_get_contents($mappath);
    fpassthru(fopen($mappath, 'r'));
    logRecord(['done'=> "OK - $mappath transmis"]);
    die();
  }
}


// Renvoit le libellé de la version de la carte organisée comme archive 7z située à $pathOf7z
// Renvoit 'undefined' si cette carte ne comporte pas de MDISO et donc pas de version.
function getMapVersionFrom7z(string $pathOf7z): string {
  $archive = new SevenZipArchive($pathOf7z);
  foreach ($archive as $entry) {
    if (preg_match('!^\d+/CARTO_GEOTIFF_[^.]+\.xml$!', $entry['Name'])) {
      //print_r($entry);
      if (!is_dir(__DIR__.'/temp'))
        if (!mkdir(__DIR__.'/temp'))
          throw new Exception("Erreur de création du répertoire __DIR__/temp");
      $archive->extractTo(__DIR__.'/temp', $entry['Name']);
      $mdPath = __DIR__."/temp/$entry[Name]";
      $mapVersion = readMapVersion($mdPath);
      unlink($mdPath);
      rmdir(dirname($mdPath));
      //echo "getMapVersionFrom7z()-> $mapVersion<br>\n";
      return $mapVersion;
    }
  }
  //echo "getMapVersionFrom7z()-> undefined<br>\n";
  return 'undefined';
}

// /maps/{numCarte}.json: liste en JSON l'ensemble des versions disponibles avec un lien vers les 2 entrées suivantes
if (preg_match('!^/maps/(\d\d\d\d)\.json$!', $_SERVER['PATH_INFO'], $matches)) {
  $qmapnum = $matches[1];
  $map = []; // [{version} => ['num'=> {num}, 'status'=> 'obsolete'?, 'versions'=> [{idVersion}=> {path}]]]
  foreach (deliveries($INCOMING_PATH) as $delivery) {
    //echo "* $delivery<br>\n";
    // Prise en compte des cartes que cette livraison rend obsolètes
    if (is_file("$INCOMING_PATH/$delivery/index.yaml")) {
      $index = Yaml::parseFile("$INCOMING_PATH/$delivery/index.yaml");
      foreach (array_keys($index['toDelete'] ?? []) as $mapid) {
        if (substr($mapid, 2) == $qmapnum) {
          $map['num'] = $qmapnum;
          $map['status'] = 'obsolete';
        }
      }
    }
    foreach (new DirectoryIterator("$INCOMING_PATH/$delivery") as $map7z)  {
      if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z') && ($map7z->getBasename('.7z') == $qmapnum)) {
        $version = getMapVersionFrom7z($map7z->getPathname());
        $path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/maps/$qmapnum-$version";
        $map['num'] = $qmapnum;
        $map['status'] = 'ok';
        $map['lastVersion'] = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/maps/$qmapnum.7z";
        $map['versions'][$version]['archive'] = $path.'.7z';
        $map['versions'][$version]['thumbnail'] = $path.'.png';
      }
    }
  }
  if (!$map)
    sendHttpCode(404, "Carte $qmapnum non trouvée");
  //echo "<pre>map="; print_r($map);
  header('Content-type: application/json');
  echo json_encode($map);
  logRecord(['done'=> "OK - maps/$qmapnum.json transmis"]);
  die();
}


// /maps/{numCarte}-{annéeEdition}c{derCorr}.(7z|png): renvoit le 7z ou la vignette de la carte dans la version définie en param
if (preg_match('!^/maps/(\d\d\d\d)-((\d\d\d\dc\d+)|(undefined))\.(7z|png)$!', $_SERVER['PATH_INFO'], $matches)) {
  $qmapnum = $matches[1];
  $qversion = $matches[2];
  $qformat = $matches[5];
  //echo "qformat=$qformat\n"; die();
  foreach (new DirectoryIterator($INCOMING_PATH) as $delivery) {
    if ($delivery->isDot()) continue;
    if ($delivery->getType() == 'dir') {
      //echo "* $delivery<br>\n";
      foreach (new DirectoryIterator("$INCOMING_PATH/$delivery") as $map7z)  {
        if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z') && ($map7z->getBasename('.7z') == $qmapnum)) {
          $version = getMapVersionFrom7z($map7z->getPathname());
          if ($version == $qversion) {
            $pathOf7z = realpath("$INCOMING_PATH/$delivery/$map7z");
            if ($qformat == '7z') {
              if (DEBUG)
                echo "Simulation de téléchargement de $pathOf7z\n";
              else {
                header('Content-type: application/x-7z-compressed');
                //die(file_get_contents($pathOf7z));
                fpassthru(fopen($pathOf7z, 'r'));
              }
              logRecord(['done'=> "OK - $pathOf7z transmis"]);
              die();
            }
            else {
              $archive = new SevenZipArchive($pathOf7z);
              foreach ($archive as $entry) {
                if (preg_match('!^\d+/\d+\.png$!', $entry['Name'])) {
                  $archive->extractTo(__DIR__.'/temp', $entry['Name']);
                  header('Content-type: image/png');
                  fpassthru(fopen(__DIR__."/temp/$entry[Name]", 'r'));
                  unlink(__DIR__."/temp/$entry[Name]");
                  rmdir(dirname(__DIR__."/temp/$entry[Name]"));
                  logRecord(['done'=> "OK - $entry[Name] transmis"]);
                  die();
                }
              }
              sendHttpCode(404, "Pas de vignette pour la carte $qmapnum version $qversion");
            }
          }
        }
      }
    }
  }
  sendHttpCode(404, "Carte $qmapnum version $qversion non trouvée");
}


sendHttpCode(400, "Erreur, requête incorrecte");
