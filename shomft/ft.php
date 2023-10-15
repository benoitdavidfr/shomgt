<?php
/** serveur d'objets géographiques exposant qqs collections en provenance du Shom + frzee
 *
 * Le protocole s'inspire de celui d'API Features sans en reprendre tous les détails.
 * Les collections suivantes sont définies:
 *   - frzee - ZEE française simplifiée et sous la forme de polygones
 *   - delmar - délimitations maritimes
 *   - aem - cartes AEM
 *   - gt - regroupe toutes les cartes GT normales
 *   - gt10M - les cartes GT normales dont l'échelle est comprise entre 1/14M et 1/6M
 *   - gt4M - les cartes GT normales dont l'échelle est comprise entre 1/6M et 1/3M
 *   - gt2M - les cartes GT normales dont l'échelle est comprise entre 1/3M et 1/1.4M
 *   - gt1M - les cartes GT normales dont l'échelle est comprise entre 1/1.4M et 1/700k
 *   - gt500k - les cartes GT normales dont l'échelle est comprise entre 1/700k et 1/300k
 *   - gt250k - les cartes GT normales dont l'échelle est comprise entre 1/300k et 1/180k
 *   - gt100k - les cartes GT normales dont l'échelle est comprise entre 1/180k et 1/90k
 *   - gt50k - les cartes GT normales dont l'échelle est comprise entre 1/90k et 1/45k
 *   - gt25k - les cartes GT normales dont l'échelle est comprise entre 1/45k et 1/22k
 *   - gt12k - les cartes GT normales dont l'échelle est comprise entre 1/22k et 1/11k
 *   - gt5k - les cartes GT normales dont l'échelle est supérieure 1/11k
 * 
 * Les points d'entrée sont:
 *   - ft.php - page d'accueil
 *   - ft.php/collections - liste les collections
 *   - ft.php/collections/{coll} - décrit la collection {coll}
 *   - ft.php/collections/{coll}/items - retourne le contenu GéoJSON de la collection {coll}
 *
 * Si le fichier json n'existe pas alors les données sont téléchargées depuis le serveur WFS du Shom
 * et le fichier json est créé.
 * Si le fichier existe déjà les données sont récupérées dans le fichier json.
 *    
 * Pb - très peu d'info dans le serveur WFS du Shom, notamment a priori pas le numéro de la carte ni l'échelle !!!
 * Probablement garder le découpage d'échelles du Shom
 *
 * journal:
 * - 1/10/2023
 *   - ajout utilisation de la classe par inclusion du fichier
 * - 21/4/2023:
 *   - ajout des cartes par intervalle d'échelles
 *   - modif du contenu du fichier gt.json
 * - 13/6/2022:
 *   - création
 * @package shomgt\shomft
 */
namespace shomft;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/httperrorcodes.inc.php';
require_once __DIR__.'/../bo/lib.inc.php';
require_once __DIR__.'/wfsserver.inc.php';

use Symfony\Component\Yaml\Yaml;

/** enregistrement d'un log temporaire pour aider au déverminage
 * @param array<mixed> $log */
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

/** Génère une erreur Http et un message utilisateur avec un content-type text ; enregistre un log avec un éventuel message sys */
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

/** Retourne l'URL appellé */
function self(): string { return "$_SERVER[REQUEST_SCHEME]://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"; }

/** Code du serveur d'objets géographiques */
class FtServer {
  /** définition des collections de ce serveur à partir de celles du serveur WFS du Shom
   * @var array<string, array<string, string|array<string, string>>> $collections */
  static array $collections = [
    'gt'=> [
      'title'=> "Silhouettes des GéoTiffs hors AEM",
      'url'=> '',
      'shomIds'=> [
        'gt800'=> 'CARTES_MARINES_GRILLE:grille_geotiff_800',
        'gt300-800'=> 'CARTES_MARINES_GRILLE:grille_geotiff_300_800',
        'gt30-300'=> 'CARTES_MARINES_GRILLE:grille_geotiff_30_300',
        'gt30'=> 'CARTES_MARINES_GRILLE:grille_geotiff_30',
      ],
    ],
    'aem'=> [
      'title'=> "Silhouettes des cartes AEM",
      'url'=> '',
      'shomIds'=> [
        'gtaem'=> 'GRILLE_CARTES_SPECIALES_AEM_WFS:emprises_aem_3857_table',
      ],
    ],
    'delmar'=> [
      'title'=> "Délimitations maritimes",
      'url'=> '',
      'shomIds'=> [
        'baseline'=> 'DELMAR_BDD_WFS:au_baseline',
        'agreedmaritimeboundary'=> 'DELMAR_BDD_WFS:au_maritimeboundary_agreedmaritimeboundary',
        'contiguouszone'=> 'DELMAR_BDD_WFS:au_maritimeboundary_contiguouszone',
        'continentalshelf'=> 'DELMAR_BDD_WFS:au_maritimeboundary_continentalshelf',
        'economicexclusivezone'=> 'DELMAR_BDD_WFS:au_maritimeboundary_economicexclusivezone',
        'nonagreedmaritimeboundary'=> 'DELMAR_BDD_WFS:au_maritimeboundary_nonagreedmaritimeboundary',
        'territorialsea'=> 'DELMAR_BDD_WFS:au_maritimeboundary_territorialsea',
      ],
    ],
  ];
  
  /** liste des ids de couche avec dénom. d'échelle max associé
   * @var array<string, float> $sdmax */
  static array $sdmax = [
    'gt10M'=>   14e6, // échelle comprise entre 1/14.000.000 et 1/6.000.000
    'gt4M'=>     6e6, // échelle comprise entre 1/6.000.000 et 1/3.000.000
    'gt2M'=>     3e6, // échelle comprise entre 1/3.000.000 et 1/1.400.000
    'gt1M'=>   1.4e6, // échelle comprise entre 1/1.400.000 et 1/700.000
    'gt500k'=> 700e3, // échelle comprise entre 1/700.000 et 1/380.000
    'gt250k'=> 380e3, // échelle comprise entre 1/380.000 et 1/180.000
    'gt100k'=> 180e3, // échelle comprise entre 1/180.000 et 1/90.000
    'gt50k'=>   90e3, // échelle comprise entre 1/90.000 et 1/45.000
    'gt25k'=>   45e3, // échelle comprise entre 1/45.000 et 1/22.000
    'gt12k'=>   22e3, // échelle comprise entre 1/22.000 et 1/11.000
    'gt5k'=>    11e3, // échelle supérieure au 1/11.000
  ];
  
  static function readFeatureTypes(): void {
    $shomFt = new FeaturesApi('https://services.data.shom.fr/INSPIRE/wfs');
    echo json_encode($shomFt->collections());
  }
  
  /** lit dans ShomWfs les Features correspondant à la collection $colName clé dans self::$collections
   * et les copie dans le fichier $colName.json, si erreur envoi Exception */
  function get(string $colName): void {
    $shomFt = new FeaturesApi('https://services.data.shom.fr/INSPIRE/wfs');
    
    if (0) { // @phpstan-ignore-line // affiche les FeatureTypes
      $cols = [];
      foreach (self::$collections['gt']['shomIds'] as $shomId) {
        $cols[$shomId] = $shomFt->collection($shomId);
      }
      header('Content-type: application/json; charset="utf-8"');
      echo json_encode($cols);
      die();
    }
    if (!isset(self::$collections[$colName]))
      throw new \Exception("Erreur colName='$colName' non définie dans la liste des collections");
    $features = []; // liste des features à construire
    foreach (self::$collections[$colName]['shomIds'] as $sid => $shomId) {
      $startindex = 0;
      $count = 1000;
      $numberReturned = 0;
      while (1) {
        $items = $shomFt->items($shomId, $count, $startindex);
        //$gt[$sid][$startindex] = $items;
        foreach ($items['features'] as $ft) {
          if ($sid == 'gtaem') { // adaptation des propriétés des cartes spéciales 
            $ft['properties'] = [
              'name'=> $ft['properties']['name'],
              'id_md'=> $ft['properties']['id_md'],
              'carte_id'=> $ft['properties']['source'],
            ];
          }
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
    file_put_contents(__DIR__."/$colName.json",
      json_encode(
        ['type'=>'FeatureCollection', 'features'=> $features],
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
  }
  
  function collections(): never {
    // Les collections provenant du serveur WFS du Shom
    foreach (self::$collections as $colName => &$coll) {
      $coll['url'] = self()."/$colName";
    }
    // Ajout de collections dérivées selon les échelles
    foreach (array_keys(self::$sdmax) as $i => $colName) {
      $imin = ($i <> count(self::$sdmax) - 1) ? $i + 1 : -1;
      $sdmin = ($imin == -1) ? 0 : (array_values(self::$sdmax))[$imin];
      self::$collections[$colName] = [
        'title'=> "Silhouettes des GéoTiffs aux échelles comprises entre ".self::$sdmax[$colName]." et ".$sdmin,
        'url'=> self()."/$colName",
      ];
    }
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(
      self::$collections,
      JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    logRecord(['done'=> "ok - collections.json"]);
    die();
  }
  
  function collection(string $colName): never {
    self::$collections[$colName]['url'] = self()."/items";
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(
      self::$collections[$colName],
      JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    logRecord(['done'=> "ok - collections.json"]);
    die();
  }
  
  function items(string $colName): never {
    if (isset(self::$collections[$colName])) {
      if (!is_file(__DIR__."/$colName.json")) {
        $this->get($colName);
      }
      header('Content-type: application/json; charset="utf-8"');
      fpassthru(fopen(__DIR__."/$colName.json",  'r'));
    }
    elseif (isset(self::$sdmax[$colName])) { // couches correspondant à un découpage par échelles
      foreach (array_keys(self::$sdmax) as $i => $cname) {
        if ($cname == $colName) break;
      }
      $imin = ($i <> count(self::$sdmax) - 1) ? $i + 1 : -1; // @phpstan-ignore-line
      $sdmin = ($imin == -1) ? 0 : (array_values(self::$sdmax))[$imin];
      $sdmax = self::$sdmax[$colName];
      //echo "sdmin=$sdmin, sdmax=$sdmax<br>\n";
      if (!is_file(__DIR__."/gt.json")) {
        $this->get('gt');
      }
      $gtFc = json_decode(file_get_contents(__DIR__."/gt.json"), true);
      $result = [];
      foreach ($gtFc['features'] as $feature) {
        if (($feature['properties']['scale'] <= $sdmax) && ($feature['properties']['scale'] > $sdmin)) {
          $result[] = $feature;
          //echo "scale = ",$feature['properties']['scale']," -> IN<br>\n";
        }
        else {
          //echo "scale = ",$feature['properties']['scale']," -> OUT<br>\n";
        }
      }
      header('Content-type: application/json; charset="utf-8"');
      echo json_encode(
        ['type'=> 'FeatureCollection', 'features'=> $result],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    else {
      sendHttpCode(400, "collection $colName non prévue");
    }
    die();
  }
  
  function home(): never {
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(['collections'=> self().'/collections']);
    die();
  }
  
  function run(?string $path_info): void {
    if (!$path_info)
      $this->home();
    elseif ($path_info == '/collections') {
      $this->collections();
    }
    elseif (preg_match('!^/collections/([^/]+)$!', $path_info, $matches))
      $this->collection($matches[1]);
    elseif (preg_match('!^/collections/([^/]+)/items$!', $path_info, $matches))
      $this->items($matches[1]);
    elseif (preg_match('!^/collections/([^/]+)/get$!', $path_info, $matches))
      $this->get($matches[1]);
    else
      sendHttpCode(400, "commande non prévue");
  }
};


if (!\bo\callingThisFile(__FILE__)) return; // retourne si le fichier est inclus


//FtServer::readFeatureTypes(); die();

$server = new FtServer;
try {
  $server->run($_SERVER['PATH_INFO'] ?? null);
} catch (\Exception $e) {
  echo $e->getMessage();
}