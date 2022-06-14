<?php
/*PhpDoc:
title: shomft/ft.php - serveur d'objets géographiques principalement en provenance du Shom
name: ft.php
doc: |
  Le protocole s'inspire de celui d'API Features sans en reprendre tous les détails.
  Les collections suivantes sont définies:
    - gt qui regroupe toutes les cartes
    - gt{xx} qui reprennent les noms des couches de shomgt et qui correspondent aux silhouettes des cartes
    - delmar - délimitations maritimes
    - frzee - ZEE française simplifiée et sous la forme de polygones
  
  Les points d'entrée sont:
    - ft.php - page d'accueil
    - ft.php/collections - liste les collections
    - ft.php/collections/{coll} - décrit la collection {coll}
    - ft.php/collections/{coll}/items - retourne le contenu GéoJSON de la collection {coll}

  Pb - très peu d'info dans le serveur WFS du Shom, notamment a priori pas le numéro de la carte ni l'échelle !!!
  Probablement garder le découpage d'échelles du Shom
journal: |
  13/6/2022:
    - création
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib/wfsserver.inc.php';

use Symfony\Component\Yaml\Yaml;

// enregistrement d'un log temporaire pour aider au déverminage
function logRecord(array $log): void {
  // Si le log n'a pas été modifié depuis plus de 5' alors il est remplacé
  $append = (is_file(__DIR__.'/log.yaml') && (time() - filemtime(__DIR__.'/log.yaml') > 5*60)) ? 0 : FILE_APPEND;
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
    $append|LOCK_EX);
}

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

function self(): string { return "$_SERVER[REQUEST_SCHEME]://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"; }

class FtServer {
  static $collections = [
    'gt'=> [
      'title'=> "Silhouettes des GéoTiffs",
      'url'=> '',
      'shomIds'=> [
        //'graem'=> 'GRILLE_CARTES_SPECIALES_AEM_WFS:emprises_aem_3857_table',
        'gt800'=> 'CARTES_MARINES_GRILLE:grille_geotiff_800',
        'gt300-800'=> 'CARTES_MARINES_GRILLE:grille_geotiff_300_800',
        'gt30-300'=> 'CARTES_MARINES_GRILLE:grille_geotiff_30_300',
        'gt30'=> 'CARTES_MARINES_GRILLE:grille_geotiff_30',
      ],
    ],
  ];
  
  function getGt(): void { // lit les GT dans ShomWfs et les copie dans gt.json, si erreur envoi Exception
    $shomFt = new FeaturesApi('https://services.data.shom.fr/INSPIRE/wfs');
    //echo json_encode($shomFt->collections());
    
    if (0) { // affiche les FeatureTypes
      $cols = [];
      foreach (self::$collections['gt']['shomIds'] as $shomId) {
        $cols[$shomId] = $shomFt->collection($shomId);
      }
      header('Content-type: application/json; charset="utf-8"');
      echo json_encode($cols);
      die();
    }
    else {
      $features = []; // liste des features à construire
      foreach (self::$collections['gt']['shomIds'] as $shomId) {
        $startindex = 0;
        $count = 1000;
        $numberReturned = 0;
        while (1) {
          $items = $shomFt->items($shomId, [], $count, $startindex);
          //$gt[$sid][$startindex] = $items;
          foreach ($items['features'] as $ft) {
            $features[] = [
              'type'=> 'Feature',
              'id'=> $ft['id'],
              'properties'=> array_merge(
                ['layerName'=> $shomId],
                $ft['properties']
              ),
              'geometry'=> $ft['geometry'],
            ];
          }
          $numberReturned += $items['numberReturned'];
          if ($numberReturned >= $items['totalFeatures'])
            break;
          $startindex += $count;
        }
      }
      file_put_contents(__DIR__."/gt.json",
        json_encode(
          ['type'=>'FeatureCollection', 'features'=> $features],
          JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
  }
  
  function collections() {
    foreach (self::$collections as $colName => &$coll) {
      $coll['url'] = self()."/$colName";
    }
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(
      self::$collections,
      JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    logRecord(['done'=> "ok - collections.json"]);
    die();
  }
  
  function collection(string $colName) {
    self::$collections[$colName]['url'] = self()."/items";
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(
      self::$collections[$colName],
      JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    logRecord(['done'=> "ok - collections.json"]);
    die();
  }
  
  function items(string $colName) {
    if ($colName <> 'gt') {
      sendHttpCode(400, "collection non prévue");
    }
    elseif (!is_file(__DIR__.'/gt.json')) {
      $this->getGt();
    }
    header('Content-type: application/json; charset="utf-8"');
    fpassthru(fopen(__DIR__.'/gt.json',  'r'));
    die();
  }
  
  function run(string $path_info) {
    if (!$path_info)
      $this->home();
    elseif ($path_info == '/collections') {
      $this->collections();
    }
    elseif (preg_match('!^/collections/([^/]+)$!', $path_info, $matches))
      $this->collection($matches[1]);
    elseif (preg_match('!^/collections/([^/]+)/items$!', $path_info, $matches))
      $this->items($matches[1]);
    else
      sendHttpCode(400, "commande non prévue");
  }
};

$server = new FtServer;
$server->run($_SERVER['PATH_INFO'] ?? null);
