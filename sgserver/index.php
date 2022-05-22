<?php
/*PhpDoc:
title: sgserver/index.php - serveur de carte 7z
name: index.php
doc: |
  Le serveur stocke toutes cartes disponibles sous la forme d'une archive 7z ainsi que le catalogue mapcat.yaml
  et les met à disposition selon l'API définie ci-dessous.
  Le code Http de retour est utilisé pour signaler les diffrents cas.
  
  api:
    /api: retourne la description de l'api (à faire)
    /cat.json: retourne mapcat.json 
    /cat/{date}.json: retourne mapcat.yaml si version postérieure à celle définie dans les paramètres sinon retourne 204 
      la {date} doit être au format DATE_ATOM avec timezone UTC (+00:00)
    /maps.json: liste en JSON l'ensemble des cartes avec un lien vers l'entrée suivante
    /map/{numCarte}.json: liste en JSON l'ensemble des versions disponibles avec un lien vers les 3 entrées suivantes
    /map/{numCarte}-{annéeEdition}c{derCorr}.7z: retourne le 7z de la carte dans la version définie en paramètre
    /map/{numCarte}-{annéeEdition}c{derCorr}.png: retourne la vignette de la carte dans la version définie en paramètre
    /map/{numCarte}.7z: retourne le 7z de la carte dans sa dernière version
    /map/{numCarte}/newer/{annéeEdition}c{derCorr}.7z:
      retourne le 7z de la carte si sa version est différente de celle fournie en paramètre sinon retourne 204
    
  Utilisation du code Http de retour pour gérer les erreurs pour cat et newer:
    - 200 - Ok - il existe bien une carte non obsolète et plus récente et la voici
    - 204 - No Content - la carte n'est pas obsolète mais la version du serveur n'est pas plus récente que celle du client
    - 400 - Bad Request - requête incorrecte
    - 404 - Not Found - la carte n'a jamais existé
    - 410 - Gone - la carte a existé mais est maintenant obsolète

  Les 7z sont stockés dans le répertoire ../../../shomgeotiff/incoming avec un répertoire par livraison nommé avec un nom
  commencant par la date de livraison sous la forme YYYYMM et idéalement un fichier index.yaml
journal: |
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
    Yaml::dump([ date(DATE_ATOM)=> array_merge(['path_info'=> $_SERVER['PATH_INFO'] ?? null], $log)]),
    FILE_APPEND|LOCK_EX);
}

logRecord($_SERVER);

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
  sendHttpCode(500, "Erreur dans le contrôle d'accès ", '', $e->getMessage());
}


define ('INCOMING_PATH', __DIR__.'/../../../shomgeotiff/incoming');  // chemin du répertoire des livraisons

date_default_timezone_set('UTC');

if (!($_SERVER['PATH_INFO'] ?? null)) {
  echo "Menu:<br>\n";
  echo " - <a href='index.php/cat.json'>cat.json</a><br>\n";
  echo date(DATE_ATOM),"<br>\n";
  $date = urlencode(date(DATE_ATOM));
  $url = "index.php/cat/$date.json";
  echo " - <a href='$url'>$url</a><br>\n";

  $error = error_get_last();
  print_r($error);

  $url = "index.php/map/6969.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  $url = "index.php/map/6969-2021c0.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  $url = "index.php/map/6969-2020c9.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  $url = "index.php/map/7330-undefined.7z";
  echo " - <a href='$url'>$url</a><br>\n";
  die();
}

if ($_SERVER['PATH_INFO'] == '/cat.json') {
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode(
    Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml'),
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  logRecord(['done'=> "ok - mapcat.json"]);
  die();
}

if (preg_match('!^/cat/(\d\d\d\d)-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)\+00:00\.json$!', $_SERVER['PATH_INFO'], $matches)) {
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
}


require_once __DIR__.'/lib/SevenZipArchive.php';
require_once __DIR__.'/lib/readmapversion.inc.php';

// Renvoie le chemin du 7z de la dernière version de la carte $mapnum
// Si la carte n'existe pas alors renvoie ''. Si la carte est obsolete alors renvoie 'obsolete'
function findNewerMap(string $mapnum): string {
  //echo "findNewerMap($mapnum)<br>\n";
  // construction du fichier newermap.pser contenant pour chaque numéro de carte la livraison contenant sa dernière version
  if (!is_file(__DIR__.'/newermap.pser') || (filemtime(INCOMING_PATH) > filemtime(__DIR__.'/newermap.pser'))) {
    $newermap = []; // [{mapnum} => ({deliveryName} | 'obsolete')]
    foreach (new DirectoryIterator(INCOMING_PATH) as $delivery) {
      if ($delivery->isDot()) continue;
      if ($delivery->getType() == 'dir') {
        //echo "* $delivery<br>\n";
        // Prise en compte des cartes que cette livraison rend obsolètes
        if (is_file(INCOMING_PATH."/$delivery/index.yaml")) {
          $index = Yaml::parseFile(INCOMING_PATH."/$delivery/index.yaml");
          foreach (array_keys($index['toDelete'] ?? []) as $mapid) {
            $mn = substr($mapid, 2);
            $newermap[$mn] = 'obsolete';
          }
        }
        foreach (new DirectoryIterator(INCOMING_PATH."/$delivery") as $map7z)  {
          if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z')) {
            //echo "- carte $map7z<br>\n";
            $mn = $map7z->getBasename('.7z');
            $newermap[$mn] = $delivery->getFilename();
          }
        }
      }
    }
    file_put_contents(__DIR__.'/newermap.pser', serialize($newermap));
  }
  else {
    $newermap = unserialize(file_get_contents(__DIR__.'/newermap.pser'));
  }
  
  //echo "<pre>incoming="; print_r($incoming); die("Fin ligne ".__LINE__."\n");
  if (!($delivery = ($newermap[$mapnum] ?? null))) {
    /*logRecord(['findNewerMap'=> [
      'params'=> ['mapnum'=> $mapnum],
      'return'=> '',
    ]]);*/
    return '';
  }
  elseif ($delivery == 'obsolete') {
    /*logRecord(['findNewerMap'=> [
      'params'=> ['mapnum'=> $mapnum],
      'return'=> 'obsolete',
    ]]);*/
    return 'obsolete';
  }
  else {
    /*logRecord(['findNewerMap'=> [
      'params'=> ['mapnum'=> $mapnum],
      'return'=> realpath(INCOMING_PATH)."/$delivery/$mapnum.7z",
    ]]);*/
    return realpath(INCOMING_PATH)."/$delivery/$mapnum.7z";
  }
}

// /map/{numCarte}.7z: retourne le 7z de la dernière version de la carte
if (preg_match('!^/map/(\d\d\d\d)\.7z$!', $_SERVER['PATH_INFO'], $matches)) {
  $mapnum = $matches[1];
  $mappath = findNewerMap($mapnum);
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


// Renvoit le libellé de la version de la carte sous forme de 7z définie par $pathOf7z
// Renvoit 'undefined' si cette carte ne comporte pas de MDISO et donc pas de version.
function findMapVersionIn7z(string $pathOf7z): string {
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
      //echo "findMapVersionIn7z()-> $mapVersion<br>\n";
      return $mapVersion;
    }
  }
  //echo "findMapVersionIn7z()-> undefined<br>\n";
  return 'undefined';
}

// /map/{numCarte}/newer/{annéeEdition}c{derCorr}.7z:
//   retourne le 7z de la carte si sa version est différente de celle fournie en paramètre sinon retourne 204
if (preg_match('!^/map/(\d\d\d\d)/newer/((\d\d\d\dc\d+)|(undefined))\.7z$!', $_SERVER['PATH_INFO'], $matches)) {
  //echo "<pre>"; print_r($matches);
  $mapnum = $matches[1];
  $mapVersion = $matches[2];
  $mappath = findNewerMap($mapnum);
  if (!$mappath) {
    sendHttpCode(404, "Carte $mapnum non trouvée");
  }
  if ($mappath == 'obsolete') {
    sendHttpCode(410, "Carte $mapnum obsolete");
  }
  //echo "<pre>";
  $mapVersionIn7z = findMapVersionIn7z($mappath);
  if ($mapVersionIn7z == $mapVersion) {
    sendHttpCode(DEBUG ? 404 : 204, "La version de la carte disponible est la même que celle fournie en paramètre");
  }
  else {
    if (DEBUG)
      echo "Simulation de téléchargement de $mappath\n";
    else {
      header('Content-type: application/x-7z-compressed');
      //echo file_get_contents($mappath);
      fpassthru(fopen($mappath, 'r'));
    }
    logRecord(['done'=> "OK - $mappath transmis"]);
    die();
  }
}


if ($_SERVER['PATH_INFO'] == '/maps.json') { // liste en JSON l'ensemble des cartes avec un lien vers l'entrée suivante
  $maps = []; // [{mapnum} => {lien}]
  //echo "<pre>"; print_r($_SERVER);
  foreach (new DirectoryIterator(INCOMING_PATH) as $delivery) {
    if ($delivery->isDot()) continue;
    if ($delivery->getType() == 'dir') {
      //echo "* $delivery<br>\n";
      // Prise en compte des cartes que cette livraison rend obsolètes
      if (is_file(INCOMING_PATH."/$delivery/index.yaml")) {
        $index = Yaml::parseFile(INCOMING_PATH."/$delivery/index.yaml");
        foreach (array_keys($index['toDelete'] ?? []) as $mapid) {
          $mapnum = substr($mapid, 2);
          if (!isset($maps[$mapnum])) {
            $maps[$mapnum] = [
              'status'=> 'obsolete',
              'nbre'=> 1,
              'url'=> "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/map/$mapnum.json",
            ];
          }
          else {
            $maps[$mapnum]['status'] = 'obsolete';
          }
        }
      }
      foreach (new DirectoryIterator(INCOMING_PATH."/$delivery") as $map7z)  {
        if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z')) {
          //echo "- carte $map7z<br>\n";
          $mapnum = $map7z->getBasename('.7z');
          if (!isset($maps[$mapnum])) {
            $maps[$mapnum] = [
              'status'=> 'ok',
              'nbre'=> 1,
              'url'=> "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/map/$mapnum.json",
            ];
          }
          else {
            $maps[$mapnum]['nbre']++;
          }
        }
      }
    }
  }
  ksort($maps);
  //echo "<pre>maps="; print_r($maps);
  header('Content-type: application/json');
  echo json_encode($maps);
  logRecord(['done'=> "OK - maps.json transmis"]);
  die();
}


// /map/{numCarte}.json: liste en JSON l'ensemble des versions disponibles avec un lien vers les 2 entrées suivantes
if (preg_match('!^/map/(\d\d\d\d)\.json$!', $_SERVER['PATH_INFO'], $matches)) {
  $qmapnum = $matches[1];
  $map = []; // [{version} => ['num'=> {num}, 'status'=> 'obsolete'?, 'versions'=> [{idv}=> {path}]]]
  foreach (new DirectoryIterator(INCOMING_PATH) as $delivery) {
    if ($delivery->isDot()) continue;
    if ($delivery->getType() == 'dir') {
      //echo "* $delivery<br>\n";
      // Prise en compte des cartes que cette livraison rend obsolètes
      if (is_file(INCOMING_PATH."/$delivery/index.yaml")) {
        $index = Yaml::parseFile(INCOMING_PATH."/$delivery/index.yaml");
        foreach (array_keys($index['toDelete'] ?? []) as $mapid) {
          if (substr($mapid, 2) == $qmapnum)
            $map = ['num'=> $qmapnum, 'status' => 'obsolete', 'versions'=> $map['versions']];
        }
      }
      foreach (new DirectoryIterator(INCOMING_PATH."/$delivery") as $map7z)  {
        if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z') && ($map7z->getBasename('.7z') == $qmapnum)) {
          $version = findMapVersionIn7z($map7z->getPathname());
          $path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/map/$qmapnum-$version";
          $map['num'] = $qmapnum;
          $map['versions'][$version]['archive'] = $path.'.7z';
          $map['versions'][$version]['thumbnail'] = $path.'.png';
        }
      }
    }
  }
  if (!$map)
    sendHttpCode(404, "Carte $qmapnum non trouvée");
  //echo "<pre>map="; print_r($map);
  header('Content-type: application/json');
  echo json_encode($map);
  logRecord(['done'=> "OK - map/$qmapnum.json transmis"]);
  die();
}


// /map/{numCarte}-{annéeEdition}c{derCorr}.(7z|png): renvoit le 7z ou la vignette de la carte dans la version définie en param
if (preg_match('!^/map/(\d\d\d\d)-((\d\d\d\dc\d+)|(undefined))\.(7z|png)$!', $_SERVER['PATH_INFO'], $matches)) {
  $qmapnum = $matches[1];
  $qversion = $matches[2];
  $qformat = $matches[5];
  //echo "qformat=$qformat\n"; die();
  foreach (new DirectoryIterator(INCOMING_PATH) as $delivery) {
    if ($delivery->isDot()) continue;
    if ($delivery->getType() == 'dir') {
      //echo "* $delivery<br>\n";
      foreach (new DirectoryIterator(INCOMING_PATH."/$delivery") as $map7z)  {
        if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z') && ($map7z->getBasename('.7z') == $qmapnum)) {
          $version = findMapVersionIn7z($map7z->getPathname());
          if ($version == $qversion) {
            $pathOf7z = realpath(INCOMING_PATH."/$delivery/$map7z");
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
