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
  20/6/2022:
    - réécriture du calcul des versions des cartes pour
      - décomposer le calcul par livraison et ainsi accélérer le calcul global pour une nouvelle livraison
      - fusionner les fichiers conservés pour /maps.json et lastDelivery
  19/6/2022:
    - gestion d'une version pour /maps.json pour exclure la carte 8523 si version=0
  12/6/2022:
    - ajout du champ modified 
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

define('EXCLUDED_MAPS', ['8523']); // cartes exclues du service en V0 car incompatble avec sgupdt v0.6

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
?>
<h2>Serveur de cartes GéoTiff du Shom au format 7z</h2>
L'utilisation de ce serveur est réservée aux agents de l'Etat et de ses Etablissements publics à caractère Administratif (EPA)
pour leurs missions de service public et un usage interne.
L'utilisation est soumise aux conditions générales d’utilisation des produits numériques, services et prestations du Shom
que vous trouverez en annexe 1
du <a href='http://diffusion.shom.fr/media/wysiwyg/catalogues/repertoire_2017_web.pdf'>Répertoire des principaux documents
dans lesquels figurent les informations publiques produites par le Shom disponible ici page 52</a>.
En utilisant ce site ou l'une de ses API, vous acceptez ces conditions d'utilisation.</p>

Ce site est expérimental propose différents mécanismes d'accès au contenu des cartes du Shom.</p>

<h3>Exemples d'utilisation du serveur:</h3><ul>
<li><a href='index.php/api.json'>Documentation de l'API conforme aux spécifications OpenAPI 3</a></li>
<li><a href='index.php/cat.json'>Catalogue des cartes ShomGT</a></li>
<li><a href='index.php/cat/schema.json'>Schéma du catalogue de cartes</a></li>
<li><a href='index.php/maps.json'>Liste des cartes exposées par le serveur</a></li>

<li><a href='index.php/maps/6969.7z'>Exemple de téléchargement de la dernière version de la carte no 6969</a></li>
<li><a href='index.php/maps/6969.json'>Exemple de la liste des versions de la carte no 6969</a></li>
<li><a href='index.php/maps/6969-2016c2.7z'>Exemple de téléchargement la version 2016c2 de la carte 6969</a></li>
<li><a href='index.php/maps/6969-2016c2.png'>Exemple de vignette de la version 2016c2 de la carte 6969</a></li>
<li><a href='index.php/maps/7330-undefined.7z'>Exemple de téléchargement de la carte 7330 non versionnée</a></li>
</ul>
<?php
  die();
}

if ($_SERVER['PATH_INFO'] == '/server') {
  echo "<pre>\n"; print_r($_SERVER); die();
}

if (in_array($_SERVER['PATH_INFO'], ['/api','/api.json'])) { // envoi de de la doc de l'API 
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode(
    Yaml::parseFile(__DIR__.'/api.yaml'),
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  logRecord(['done'=> "ok - api.json"]);
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

if (in_array($_SERVER['PATH_INFO'], ['/cat/schema', '/cat/schema.json'])) { // envoi du schema de mapcat 
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode(
    Yaml::parseFile(__DIR__.'/../mapcat/mapcat.schema.yaml'),
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  logRecord(['done'=> "ok - mapcat/schema.json"]);
  die();
}

if ($_SERVER['PATH_INFO'] == '/logout') { // action complémentaire pour raz le login en interactif 
  header('WWW-Authenticate: Basic realm="Authentification pour acces aux ressources du SHOM"');
  sendHttpCode(401, isset($_SERVER['PHP_AUTH_USER']) ? "Logout, user: $_SERVER[PHP_AUTH_USER]" : 'Logout, no user');
}

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

// gère les versions des cartes, permet de déduire maps.json et lastdelivery
// construction d'un fichier par livraison pour éviter d'avoir tout à recalculer
// Chaque objet correspond à l'état d'une carte pour une livraison ou l'ensemble des livraisons
class MapVersion {
  protected string $status; // 'ok' | 'obsolete'
  protected int $nbre=0; // nbre de téléchargements disponibles pour la carte
  protected ?string $lastVersion=null; // identifiant de la dernière version
  protected ?string $modified=null; // date de dernière modification de la dernière version (issue du champ dateStamp des MD)
  protected ?string $lastDelivery=null; // nom du répertoire de livraison correspondant à la version
  
  // fabrique un objet à partir soit du status 'ok' et de l'info ['version'=> {version}, 'dateStamp'=> {dateStamp}]
  // retournée par self::getFrom7z(), soit du status 'obsolete'
  private function __construct(string $status, array $mapVersion=[]) {
    if ($status == 'obsolete')
      $this->status = 'obsolete';
    else {
      $this->status = 'ok';
      $this->nbre = 1;
      $this->lastVersion = $mapVersion['version'];
      $this->modified = $mapVersion['dateStamp'] ?? '';
    }
  }
  
  // prend en compte une nouvelle version en combinant les objets
  private function update(self $new): void {
    //echo "<pre>update, this="; print_r($this); print_r($new);
    if ($new->status == 'obsolete')
      $this->status = 'obsolete';
    else {
      $this->status = 'ok';
      $this->nbre++;
      $this->lastVersion = $new->lastVersion;
      $this->modified = $new->modified;
    }
    //print_r($this);
  }
  
  private function asArray(string $mapnum): array { // fabrique un array pour allAsArray()
    $https = (($_SERVER['HTTPS'] ?? '') == 'on') ? 'https' : 'http';
    return [
      'status'=> $this->status,
      'nbre'=> $this->nbre,
      'lastVersion'=> $this->lastVersion,
      'modified'=> $this->modified,
      'url'=> "$https://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/maps/$mapnum.json",
    ];
  }
  
  // Renvoit pour la carte formattée comme archive 7z située à $pathOf7zun
  // le dict. ['version'=> {version}, 'dateStamp'=> {dateStamp}] où {version} est le libellé de la version
  // et {dateStamp} est la date de dernière modification du fichier des MD de la carte
  // Renvoit ['version'=> 'undefined'] si la carte ne comporte pas de MDISO et donc pas de version.
  static function getFrom7z(string $pathOf7z): array {
    //echo "MapVersion::getFrom7z($pathOf7z)<br>\n";
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
    return ['version'=> 'undefined'];
  }

  // construit si nécessaire et enregistre les versions de carte pour la livraison
  private static function buildForADelivery(string $deliveryPath): array {
    //echo "buildForADelivery($deliveryPath)<br>\n";
    if (is_file("$deliveryPath/mapversions.pser") && (filemtime("$deliveryPath/mapversions.pser") > filemtime($deliveryPath))) {
      return unserialize(file_get_contents("$deliveryPath/mapversions.pser"));
    }
    
    $mapVersions = []; // [{manum} => MapVersion] - la dernière version pour chaque carte + nbre de téléchargements
    if (is_file("$deliveryPath/index.yaml")) {
      $index = Yaml::parseFile("$deliveryPath/index.yaml");
      foreach (array_keys($index['toDelete'] ?? []) as $mapid) {
        $mapnum = substr($mapid, 2);
        //echo "** $mapnum obsolete<br>\n";
        $mapVersions[$mapnum] = new self('obsolete');
      }
    }
    foreach (new DirectoryIterator($deliveryPath) as $map7z)  {
      if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z')) {
        //echo "- carte $map7z<br>\n";
        $mapnum = $map7z->getBasename('.7z');
        //echo "** $mapnum valide<br>\n";
        $mapVersion = self::getFrom7z("$deliveryPath/$map7z");
        $mapVersions[$mapnum] = new self('ok', $mapVersion);
      }
    }
    file_put_contents("$deliveryPath/mapversions.pser", serialize($mapVersions));
    //echo '<pre>$mapVersions='; print_r($mapVersions); echo "</pre>\n";
    return $mapVersions;
  }
  
  // construit si nécessaire et enregistre les versions de cartes pour toutes les livraisons
  private static function buildAll(string $INCOM_PATH): array {
    if (is_file("$INCOM_PATH/mapversions.pser") && (filemtime("$INCOM_PATH/mapversions.pser") > filemtime($INCOM_PATH))) {
      return unserialize(file_get_contents("$INCOM_PATH/mapversions.pser"));
    }
    
    $mapVersions = []; // [{manum} => MapVersion] - la dernière version pour chaque carte + nbre de téléchargements
    foreach (deliveries($INCOM_PATH) as $delivery) {
      $mapVDel = self::buildForADelivery("$INCOM_PATH/$delivery");
      foreach ($mapVDel as $mapnum => $mapVersion) {
        if (!isset($mapVersions[$mapnum]))
          $mapVersions[$mapnum] = $mapVersion;
        else
          $mapVersions[$mapnum]->update($mapVersion);
        $mapVersions[$mapnum]->lastDelivery = $delivery;
      }
    }
    ksort($mapVersions, SORT_STRING);
    file_put_contents("$INCOM_PATH/mapversions.pser", serialize($mapVersions));
    return $mapVersions;
  }
  
  // construit l'array à fournir pour maps.json
  static function allAsArray(string $INCOMING_PATH): array {
    $allAsArray = [];
    $mapVersions = self::buildAll($INCOMING_PATH);
    foreach($mapVersions as $mapnum => $mapVersion)
      $allAsArray[$mapnum] = $mapVersion->asArray($mapnum);
    return $allAsArray;
  }
  
  // Renvoie pour une carte $mapnum le chemin du 7z de sa dernière livraison ou '' s'il n'y en a aucune.
  static function lastDelivery(string $INCOMING_PATH, string $mapnum): string {
    $mapVersions = self::buildAll($INCOMING_PATH);
    if (isset($mapVersions[$mapnum]))
      return $INCOMING_PATH.'/'.$mapVersions[$mapnum]->lastDelivery."/$mapnum.7z";
    else
      return '';
  }
};


if ($_SERVER['PATH_INFO'] == '/maps.json') { // liste en JSON l'ensemble des cartes avec un lien vers l'entrée suivante
  $mapVersions = MapVersion::allAsArray($INCOMING_PATH);
  switch($_GET['version'] ?? 0) {
    case 0: {
      foreach (EXCLUDED_MAPS as $em)
        unset($mapVersions[$em]);
      break;
    }
    case 1: break;
  }
  //echo '<pre>$mapVersions='; print_r($mapVersions); die();
  header('Content-type: application/json');
  echo json_encode($mapVersions, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  logRecord(['done'=> "OK - maps.json transmis"]);
  die();
}

// /maps/{numCarte}.7z: retourne le 7z de la dernière version de la carte
if (preg_match('!^/maps/(\d\d\d\d)\.7z$!', $_SERVER['PATH_INFO'], $matches)) {
  $mapnum = $matches[1];
  $mappath = MapVersion::lastDelivery($INCOMING_PATH, $mapnum);
  //echo "mappath=$mappath<br>\n"; die();
  if (!$mappath) {
    sendHttpCode(404, "Carte $mapnum non trouvée");
  }
  else {
    header('Content-type: application/x-7z-compressed');
    fpassthru(fopen($mappath, 'r'));
    logRecord(['done'=> "OK - $mappath transmis"]);
    die();
  }
}


// /maps/{numCarte}.json: liste en JSON l'ensemble des versions disponibles avec un lien vers les 2 entrées suivantes
if (preg_match('!^/maps/(\d\d\d\d)\.json$!', $_SERVER['PATH_INFO'], $matches)) {
  $qmapnum = $matches[1];
  $https = (($_SERVER['HTTPS'] ?? '') == 'on') ? 'https' : 'http';
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
        $mapversion = MapVersion::getFrom7z($map7z->getPathname());
        $version = $mapversion['version'];
        $path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/maps/$qmapnum-$version";
        $map['num'] = $qmapnum;
        $map['status'] = 'ok';
        $map['lastVersion'] = "$https://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/maps/$qmapnum.7z";
        $map['versions'][$version] = [
          'archive'=> $path.'.7z',
          'modified' => $mapversion['dateStamp'],
          'thumbnail'=> $path.'.png',
        ];
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
          $version = MapVersion::getFrom7z($map7z->getPathname());
          if ($version['version'] == $qversion) {
            $pathOf7z = realpath("$INCOMING_PATH/$delivery/$map7z");
            if ($qformat == '7z') {
              if (defined('DEBUG'))
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
