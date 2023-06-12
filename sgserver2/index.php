<?php
/*PhpDoc:
title: sgserver2/index.php - serveur de carte 7z (v2 simplifiée)
name: index.php
doc: |
  Le serveur expose les cartes disponibles sous la forme d'archives 7z ainsi que le catalogue mapcat.yaml
  selon l'API définie ci-dessous.
    
  La variable d'environnement SHOMGT3_PORTFOLIO_PATH est utilisée pour définir le portefeuille.
  
  api:
    /: page d'accueil utilisée pour proposer des URL de tests
    /api: retourne la description de l'api
    /cat.json: retourne mapcat.json 
    /maps.json: liste en JSON l'ensemble des cartes disponibles indexées par leur numéro et avec les informations suivantes
      status: 'ok'
      lastVersion: identifiant de la dernière version 
    /maps/{numCarte}.7z: retourne le 7z de la carte dans sa dernière version
    
  Utilisation du code Http de retour pour gérer les erreurs pour cat et newer:
    - 200 - Ok - il existe bien un document et le voici
    - 400 - Bad Request - requête incorrecte
    - 401 - Unauthorized - authentification nécessaire
    - 404 - Not Found - le document demandé n'a pas été trouvé

  Les cartes 7z sont stockées dans le sous répertoire current du répertoire défini par la var d'env. SHOMGT3_INCOMING_PATH.
  A chaque carte est associé un fichier .md.json qui contient en JSON la propriété version.
journal: |
  11/6/2023:
    - nouvelle version simplifiée correspondant à la restructuration de shomgeotiff
  2/8/2022:
    - corrections indiquées par PhpStan level 6
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
require_once __DIR__.'/../shomgt/lib/accesscntrl.inc.php';

use Symfony\Component\Yaml\Yaml;

// enregistrement d'un log temporaire pour aider au déverminage
/** @param array<mixed> $log */
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
); // liste des codes d'erreur et de leur label 

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

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new Exception("Erreur, variable d'env. SHOMGT3_PORTFOLIO_PATH non définie");
if (!is_dir($PF_PATH))
  throw new Exception("Erreur, SHOMGT3_PORTFOLIO_PATH ne correspond pas au chemin d'un répertoire");

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

Ce site est expérimental propose l'accès au contenu des cartes du Shom.</p>

<h3>Exemples d'utilisation du serveur:</h3><ul>
<li><a href='index.php/api.json'>Documentation de l'API conforme aux spécifications OpenAPI 3</a></li>
<li><a href='index.php/cat.json'>Catalogue des cartes ShomGT</a></li>
<li><a href='index.php/cat/schema.json'>Schéma du catalogue de cartes</a></li>
<li><a href='index.php/maps.json'>Liste des cartes exposées par le serveur</a></li>

<li><a href='index.php/maps/6969.7z'>Exemple de téléchargement de la dernière version de la carte no 6969</a></li>
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

if ($_SERVER['PATH_INFO'] == '/cat/schema.json') { // envoi du schema de mapcat 
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

if ($_SERVER['PATH_INFO'] == '/maps.json') { // liste en JSON l'ensemble des cartes avec un lien vers l'entrée suivante
  //echo '<pre>'; print_r($_SERVER); die();
  $scriptUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";
  //echo "<pre>scriptUrl=$scriptUrl\n"; die();
  $maps = [];
  foreach (new DirectoryIterator("$PF_PATH/current") as $map) {
    if (substr($map, -8) <> '.md.json') continue;
    $mapMd = json_decode(file_get_contents("$PF_PATH/current/$map"), true);
    $maps[substr($map, 0, -8)] = [
      'status'=> 'ok',
      'lastVersion'=> $mapMd['version'],
      'url'=> "$scriptUrl/maps/".substr($map, 0, -8).'.7z',
    ];
  }
  ksort($maps, SORT_STRING);
  header('Content-type: application/json');
  echo json_encode($maps, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  logRecord(['done'=> "OK - maps.json transmis"]);
  die();
}

// /maps/{numCarte}.7z: retourne le 7z de la dernière version de la carte
if (preg_match('!^/maps/(\d{4})\.7z$!', $_SERVER['PATH_INFO'], $matches)) {
  $mapnum = $matches[1];
  
  $mappath = "$PF_PATH/current/$mapnum.7z";
  //echo "mappath=$mappath<br>\n"; die();
  if (!is_file($mappath)) {
    sendHttpCode(404, "Carte $mapnum non trouvée");
  }
  else {
    header('Content-type: application/x-7z-compressed');
    fpassthru(fopen($mappath, 'r'));
    logRecord(['done'=> "OK - $mappath transmis"]);
    die();
  }
}


sendHttpCode(400, "Erreur, requête incorrecte");
