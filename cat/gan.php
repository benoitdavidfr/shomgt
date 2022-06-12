<?php
/*PhpDoc:
name: gan.php
title: cat2/gan.php - gestion des gan
classes:
functions:
doc: |
  L'objectif est de moissonner les GAN des cartes définies dans $INCOMIN_PATH/../maps.json
  et de fabriquer un fichier gans.yaml/pser de synthèse

  Le script propose en CLI:
    - de moissonner les GANs de manière incrémentale, cad uniq. les GAN absents
    - de moissonner les GANs en effacant les précédentes moissons
    - de fabriquer la synthèse en yaml/pser
    - d'afficher cette synthèse

  Certains traitements se font en cli (moissonnage), d'autres en non-CLI (affichage).

  Erreur 500 semble signfier que la carte n'est pas gérée dans le GAN, il s'agit visiblement surtout de cartes outre-mer
  Ex: https://gan.shom.fr/diffusion/qr/gan/6280/1931 - Partie Nord de Raiatea - Port d'Uturoa (1/12000)
  Le qrcode donne:
    Error Page
    status code: 404
    Exception Message: N/A

  Si un proxy est nécessaire pour interroger les GANs, il doit être défini dans ../lib/secretconfig.inc.php
journal: |
  12/6/2022:
    - fork dans ShomGt3
  31/5/2022:
    - désactivation de la vérification SSL
includes: [../lib/config.inc.php, mapcat.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
//require_once __DIR__.'/../lib/config.inc.php';
//require_once __DIR__.'/mapcat.php';

use Symfony\Component\Yaml\Yaml;

function http_error_code($http_response_header): ?string { // extrait le code d'erreur Http 
  if (!isset($http_response_header))
    return 'indéfini';
  $http_error_code = null;
  foreach ($http_response_header as $line) {
    if (preg_match('!^HTTP/.\.. (\d+) !', $line, $matches))
      $http_error_code = $matches[1];
  }
  return $http_error_code;
}

function httpContext() { // fabrique un context Http si un proxy est défini, sinon renvoie null
  if (1 || !($proxy = config('proxy'))) {
    //return null;
    return stream_context_create([
      'http'=> [
        'method'=> 'GET',
      ],
      'ssl'=> [
        'verify_peer'=> false,
        'verify_peer_name'=> false,
      ],
    ]);
  }
  
  return stream_context_create([
    'http'=> [
      'method'=> 'GET',
      'proxy'=> str_replace('http://', 'tcp://', $proxy),
    ]
  ]);
}

function maps(): array { // liste les cartes actives du portefeuille 
  if (!($INCOMING_PATH = getenv('SHOMGT3_INCOMING_PATH')))
    throw new Exception("Variable d'env. SHOMGT3_INCOMING_PATH non définie");
  $maps = json_decode(file_get_contents("$INCOMING_PATH/../maps.json"), true);
  foreach ($maps as $num => $map) {
    //var_dump($num);
    if (($map['status'] <> 'ok') || !(is_int($num) || ctype_digit($num)))
      unset($maps[$num]);
  }
  return $maps;
}

/*PhpDoc: classes
name: Territoire
title: class Territoire - définit la doctrine d'importance des différents territoires pour la mise à jour
doc: |
  A chaque territoire, j'associe un statut et à chaque statut un coefficient d'importance des corrections.
  Les statuts sont '', FX, DOM, COM et TOM
    - '' indique inconnu
    - FX pour la métropole
    - DOM pour un DOM ou un pseudo-DOM dans lequel un service de l'Etat est en charge de questions maritimes
    - COM pour un COM, cad que l'administration est de la responsabilité d'une collectivité territoriale
    - TOM pour un territoire pour lequel il n'existe pas de collectivité
  Le coeff vaut
    - 1 pour FX ou ''
    - 1/2 pour DOM ou TOM
    - 1/4 pour COM
    - 1/4 si aucun territoire n'est couvert
*
class Territoire {
  // enum: [FR, FX, GP, GF, MQ, YT, RE, PM, BL, MF, TF, PF, WF, NC, CP]
  const STATUTS = [ // définition des statuts des territoires 
    'FR'=> '',
    'FX'=> 'FX',
    'FX-Atl'=> 'FX',
    'FX-MMN'=> 'FX',
    'FX-Med'=> 'FX',
    'GP'=> 'DOM',
    'GF'=> 'DOM',
    'MQ'=> 'DOM',
    'YT'=> 'DOM',
    'RE'=> 'DOM',
    'PM'=> 'DOM', // géré comme un DOM <=> DDAM équiv. DEAL
    'BL'=> 'COM',
    'MF'=> 'COM',
    'TF'=> 'TOM', // pas de collectivité
    'PF'=> 'COM',
    'WF'=> 'COM',
    'NC'=> 'COM',
    'CP'=> 'TOM', // pas de collectivité
  ];
  // ordre de priorité de mise à jour
  // FX en premier car c'est là où j'ai constaté des utilisateurs
  // DOM ensuite en raison de la présence des services déconcentrés de l'Etat
  // TOM ensuite en raison de la responsabilité de l'Etat
  // COM enfin en raison de l'autonomie de ces collectivités
  // aucun territoire couvert
  static function coeff(array $mapsFrance): float {
    $statuts=[]; // [statut => 1]
    foreach ($mapsFrance as $terr) {
      $statuts[self::STATUTS[$terr]] = 1;
    }
    if (isset($statuts[''])) return 1;
    if (isset($statuts['FX'])) return 1;
    if (isset($statuts['DOM'])) return 1/2;
    if (isset($statuts['TOM'])) return 1/2;
    if (isset($statuts['COM'])) return 1/4;
    return 1/4; // aucun territoire couvert
  }
};
*/

/*PhpDoc: classes
name: GanInSet
title: class GanInSet - description d'un cartouche dans la synthèse d'une carte
*/
class GanInSet {
  protected string $title;
  protected array $spatial; // sous la forme ['SW'=> sw, 'NE'=> ne]
  
  function __construct(string $html) {
    //echo "html=$html\n";
    if (!preg_match('!^\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*$!', $html, $matches))
      throw new Exception("Erreur de construction de GanInSet sur '$html'");
    $this->title = trim($matches[1]);
    $this->spatial = ['SW'=> trim($matches[2]), 'NE'=> trim($matches[3])];
  }
  
  function asArray(): array {
    return [
      'title'=> $this->title,
      'spatial'=> $this->spatial,
    ];
  }
};

/*PhpDoc: classes
name: Gan
title: class Gan - synthèse des GAN par carte à la date de moisson des GAN / catalogue ou indication d'erreur d'interrogation des GAN
methods:
doc: |
  Moisonne le GAN des cartes de MapCat non obsolètes et présentes dans ShomGt (et donc le champ modified est connu).
  Analyse les fichiers Html moissonnés et en produit une synthèse.
  Calcule pour chaque carte un indicateur, appelé degré de péremption, reflétant la nécessité de mettre à jour la carte.
  Affiche le résultat pour permettre le choix des cartes à mettre à jour.
*/
class Gan {
  const GAN_DIR = __DIR__.'/gan';
  const PATH = __DIR__.'/gans.'; // chemin des fichiers stockant la synthèse en pser ou en yaml, lui ajouter l'extension
  const PATH_PSER = self::PATH.'pser'; // chemin du fichier stockant le catalogue en pser
  const PATH_YAML = self::PATH.'yaml'; // chemin du fichier stockant le catalogue en  Yaml
  static string $hvalid=''; // intervalles des dates de la moisson des GAN
  static array $gans=[]; // dictionnaire [$mapnum => Gan]
  
  protected string $mapnum;
  protected ?string $groupTitle=null; // sur-titre optionnel identifiant un ensemble de cartes
  protected string $title=''; // titre
  protected ?string $ganEdition=null; // edition
  protected array $spatial=[]; // sous la forme ['SW'=> sw, 'NE'=> ne]
  protected array $inSets=[]; // cartouches
  protected array $corrections=[]; // liste des corrections
  protected array $analyzeErrors=[]; // erreurs éventuelles d'analyse du résultat du moissonnage
  protected string $valid; // date de moissonnage du GAN en format ISO
  protected string $harvestError=''; // erreur éventuelle du moissonnage

  static function week(string $modified): string { // transforme une date en semaine sur 4 caractères comme utilisé par le GAN 
    $time = strtotime($modified);
    return substr(date('o', $time), 2) . date('W', $time);
  }
  
  /*PhpDoc: methods
  name: harvest
  title: static function harvest(array $options=[]) - moissonne les GAN par carte dans le répertoire self::GAN_DIR
  doc: |
    Les cartes interrogées sont celles de MapCat ayant un champ modified et n'étant pas obsolètes.
  */
  static function harvest(array $options=[]): void {
    //echo "Harvest ligne ",__LINE__,"\n";
    $gandir = self::GAN_DIR;
    if (!file_exists(self::GAN_DIR))
      mkdir(self::GAN_DIR);
    elseif ($options['reinit'] ?? false) { // suppression des fichiers existants
      if (!$dh = opendir(self::GAN_DIR))
        die("Ouverture de $gandir impossible");
      while (($filename = readdir($dh)) !== false) {
        if (!in_array($filename, ['.','..']))
          unlink("$gandir/$filename");
      }
    }
    $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
    //print_r($errors);
    //print_r(maps());
    foreach (maps() as $mapnum => $map) {
      //echo "mapnum=$mapnum\n"; print_r($map);
      if (isset($map['modified'])) {
        $ganWeek = Gan::week($map['modified']);
        if (!file_exists("$gandir/$mapnum-$ganWeek.html") && !isset($errors["$mapnum-$ganWeek"])) {
          //$url = "https://www.shom.fr/qr/gan/$mapnum/$ganWeek";
          $url = "https://gan.shom.fr/diffusion/qr/gan/$mapnum/$ganWeek";
          echo "url=$url\n";
          if (($contents = file_get_contents($url, false, httpContext())) === false) {
            $message = "Erreur ".(isset($http_response_header) ? http_error_code($http_response_header) : 'non définie')
              ." de lecture de $url";
            echo "$message\n";
            file_put_contents("$gandir/errors.yaml", "$mapnum-$ganWeek: $message\n", FILE_APPEND);
          }
          else {
            file_put_contents("$gandir/$mapnum-$ganWeek.html", $contents);
            echo "Lecture $url ok\n";
          }
        }
      }
    }
  }
  
  /*PhpDoc: methods
  name: analyzeHtml
  title: "static function analyzeHtml(string $html): array - analyse du Html du GAN"
  doc: |
    analyse du html du Gan notamment pour identifier les corrections et l'édition d'une carte - fonction complètement réécrite /
    V1
    retourne un array avec les champs title, edition et corrections + analyzeErrors en cas d'erreur d'analyse
    J'ai essayé de minimiser la dépendance au code Html !
  */
  static function analyzeHtml(string $html): array {
    //echo "<tr><td colspan=6><pre>";
    $record = [];
    $html = preg_replace('!(<font [^>]*>|</font>|<b>|</b>)!', '', $html);

    // lit la colonne title du tableau du haut qui contient titre, cartouches, édition, coordonnées
    $pattern = '!<td class="column-title align-top">(([^<]*|<div|</div)*)</td>!';
    while (preg_match($pattern, $html, $matches)) {
      if (!isset($record['title'])) {
        $record['title'] = [str_replace(['<','>'], ['{','}'], $matches[1])];
      }
      else {
        $record['title'][] = str_replace(['<','>'], ['{','}'], $matches[1]);
      }
      $html = preg_replace($pattern, '', $html, 1);
    }
    if ($record['title'] ?? null)
      $record['edition'] = array_pop($record['title']);
  
    // modèle de no de correction + semaineAvis
    $pattern = '!<tr class="mapNumber-[^ ]+ externalId-(\d+-\d+)[^>]*>'
      .'<td [^>]*>\s*<\!-- [^>]*>[^(]*\((\d+)\)!';
    // modèle: <tr class="mapNumber-6643 externalId-1938-184-6643"><td width="60" align="left"> <!-- COUPER ICI 54-->6643 (16)</td><td colspan="6" width="538"><p align="left">Cartouche A </p></td></tr>
    // modèle: <tr class="mapNumber-5417 externalId-1739-267-5417"><td width="60" align="left"><!-- COUPER ICI 25--><a href="INTERNET/2017/1739/calques/1739_FR5417.pdf" target="blank">5417</a> (87)
    while (preg_match($pattern, $html, $matches)) {
      if (!isset($record['corrections'])) {
        $record['corrections'] = [['num'=> intval($matches[2]), 'semaineAvis'=> $matches[1]]];
      }
      else {
        $record['corrections'][] = ['num'=> intval($matches[2]), 'semaineAvis'=> $matches[1]];
      }
      $html = preg_replace($pattern, '', $html, 1);
    }
  
    // un autre modèle de no de correction
    $pattern = '!<td>(<a href=[^>]*>)?<span class="correction_map_FR-strong">[^<]*</span>(</a>)?\s*\((\d+)\)(<br>|</td>)!';
    // modèle: <td><span class="correction_map_FR-strong">4232</span> (1)</td>
    // modèle: <td><a href="INTRADEF/2020/2012/calques/2012_FR4233.pdf" target="_blank"><span class="correction_map_FR-strong">4233</span></a> (5)</td>
    // modèle: <td><a href="INTRADEF/2020/2007/calques/2007_FR6608.pdf" target="_blank"><span class="correction_map_FR-strong">6608</span></a> (7)<br><span class="correction_map_FR-strong">INT 140</span></td>
    while (preg_match($pattern, $html, $matches)) {
      if (!isset($record['corrections'])) {
        $record['corrections'] = [['num'=> intval($matches[3])]];
      }
      else {
        $record['corrections'][] = ['num'=> intval($matches[3])];
      }
      $html = preg_replace($pattern, '', $html, 1);
    }
  
    // modèle de semaineAvis
    $pattern = '!<table class="mapNumber-[^ ]+ externalId-(\d+-\d+)!';
    //modèle: <table class="mapNumber-4233 externalId-2012-39-4233 correction_map_FR-avoidPageBreak">
    $semaineAvis = [];
    while (preg_match($pattern, $html, $matches)) {
      $semaineAvis[] = $matches[1];
      $html = preg_replace($pattern, '', $html, 1);
    }
    /*if ($semaineAvis) {
      $record['semaineAvisInitiales'] = $semaineAvis;
      $record['correctionsInitiales'] = $record['corrections'];
    }*/
  
    foreach ($record['corrections'] ?? [] as $no => $correction) {
      if (!isset($correction['semaineAvis'])) {
        if ($semaineAvis) {
          $record['corrections'][$no]['semaineAvis'] = array_shift($semaineAvis);
        }
        else {
          $record['analyzeErrors'][] = "semaineAvis insuffisant pour $no";
          //echo "semaineAvis insuffisant pour $no\n";
        }
      }
    }
    if ($semaineAvis)
      $record['analyzeErrors'][] = "semaineAvis supplémentaires";
    //echo "</pre></td></tr>";
    return $record;
  }
  
  /*PhpDoc: methods
  name: build
  title: static function build() - construit la synhèse des GAN de la moisson existante
  */
  static function build() {
    $minvalid = null;
    $maxvalid = null;

    // Ce code permet de détecter les fichiers Html manquants nécessitant une moisson
    $gandir = self::GAN_DIR;
    $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
    foreach (maps() as $mapnum => $map) {
      if (isset($map['modified'])) {
        $ganWeek = Gan::week($map['modified']);
        if (isset($errors["$mapnum-$ganWeek"])) { }
        elseif (!file_exists("$gandir/$mapnum-$ganWeek.html")) {
          echo "moisson $mapid-$ganWeek absente à moissonner\n";
        }
        else {
          $mtime = filemtime("$gandir/$mapnum-$ganWeek.html");
          if (!$minvalid || ($mtime < $minvalid))
            $minvalid = $mtime;
          if (!$maxvalid || ($mtime > $maxvalid))
            $maxvalid = $mtime;
          $html = file_get_contents("$gandir/$mapnum-$ganWeek.html");
          self::$gans[$mapnum] = new self($mapnum, self::analyzeHtml($html), $map, $mtime);
        }
      }
    }
    self::$hvalid = date('Y-m-d', $minvalid).'/'.date('Y-m-d', $maxvalid);

    $errors = file_exists(self::GAN_DIR.'/errors.yaml') ? Yaml::parsefile(self::GAN_DIR.'/errors.yaml') : [];
    //print_r($errors);
    foreach ($errors as $id => $errorMessage) {
      $mapid = substr($id, 0, 6);
      if ($mapa = MapCat::maps($mapid))
        self::$gans[$mapid] = new self($mapid, ['harvestError'=> $errorMessage], $mapa);
    }
  }
  
  // record est le résultat de l'analyse du fichier Html, $map est l'enregistrement de maps.json
  function __construct(string $mapnum, array $record, array $map, int $mtime=null) {
    //echo '$record='; print_r($record);
    //echo '$mapa='; print_r($mapa);
    $this->mapnum = $mapnum;
    // cas où soit le GAN ne renvoie aucune info signifiant qu'il n'y a pas de corrections, soit il renvoie une erreur
    if (!$record || isset($record['harvestError'])) {
      $this->ganEdition = null;
    }
    else { // cas où il existe des corrections
      $this->postProcessTitle($record['title']);
      $this->ganEdition = $record['edition'];
    }
    
    $this->corrections = $record['corrections'] ?? [];
    $this->analyzeErrors = $record['analyzeErrors'] ?? [];
    $this->harvestError = $record['harvestError'] ?? '';
    $this->valid = $mtime ? date('Y-m-d', $mtime) : '';
  }
  
  private function postProcessTitle(array $record) { // complète __construct()
    if (!$record) return;
    $title = array_shift($record);
    foreach ($record as $inSet)
      $this->inSets[] = new GanInSet($inSet);
    if (!preg_match('!^([^{]*){div}([^{]*){/div}\s*({div}([^{]*){/div}\s*)?({div}([^{]*){/div})?\s*$!', $title, $matches))
      throw new Exception("Erreur d'analyse du titre '$title'");
    //echo '$matches='; print_r($matches);
    switch (count($matches)) {
      case 3: { // sur-titre + titre sans bbox
        $this->groupTitle = trim($matches[1]);
        $this->title = trim($matches[2]);
        break;
      }
      case 7: { // sur-titre + titre + bbox
        $this->groupTitle = trim($matches[1]);
        $this->title = trim($matches[2]);
        $this->bbox = ['SW'=> trim($matches[4]), 'NE'=> trim($matches[6])];
        break;
      }
      default: throw new Exception("Erreur d'analyse du titre '$title', count=".count($matches));
    }
    //echo '$this='; print_r($this);
  }
  
  /*static function gans(?string $mapid=null): array { // retourne soit un array de tous les gans soit le gan demandé comme array
    if (!self::$gans)
      self::loadFromPser();
    if (!$mapid)
      return self::$gans;
    elseif (isset(self::$gans[$mapid]))
      return self::$gans[$mapid]->asArray();
    else
      return [];
  }*/
  
  static function allAsArray(): array { // retourne l'ensemble de la classe comme array 
    if (!self::$gans)
      Gan::loadFromPser();
    return [
      'title'=> "Synthèse du résultat du moissonnage des GAN des cartes du catalogue",
      'description'=> "Seules sont présentes les cartes non obsolètes présentes sur sgserver",
      '$id'=> 'https://geoapi.fr/shomgt3/cat2/gans',
      '$schema'=> __DIR__.'/gans',
      'valid'=> self::$hvalid,
      'gans'=> array_map(function(Gan $gan) { return $gan->asArray(); }, self::$gans),
      'eof'=> null,
    ];
  }
  
  function asArray(): array { // retourne un objet comme array 
    return
      ($this->groupTitle ? ['groupTitle'=> $this->groupTitle] : [])
    + ($this->title ? ['title'=> $this->title] : [])
    + ($this->spatial ? ['spatial'=> $this->spatial] : [])
    + ($this->inSets ?
        ['inSets'=> array_map(function (GanInSet $inset): array { return $inset->asArray(); }, $this->inSets)]
        : [])
    + ($this->ganEdition ? ['ganEdition'=> $this->ganEdition] : [])
    + ($this->corrections ? ['corrections'=> $this->corrections] : [])
    + ($this->analyzeErrors ? ['analyzeErrors'=> $this->analyzeErrors] : [])
    + ($this->valid ? ['valid'=> $this->valid] : [])
    + ($this->harvestError ? ['harvestError'=> $this->harvestError] : [])
    ;
  }
  
  static function storeAsPser() { // enregistre le catalogue comme pser 
    file_put_contents(self::PATH_PSER, serialize([
      'valid'=> self::$hvalid,
      'gans'=> self::$gans,
    ]));
  }
  
  static function loadFromPser() { // charge les données depuis le pser 
    $contents = unserialize(file_get_contents(self::PATH_PSER));
    self::$hvalid = $contents['valid'];
    self::$gans = $contents['gans'];
  }
};

//echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>gan</title></head><body><pre>\n";
//echo Yaml::dump(Gan::allAsArray()); die();
//Gan::loadFromPser(); print_r(Gan::$modified); die();


// Utilisation de la classe Gan
if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;


if (php_sapi_name() == 'cli') {
  //echo "argc=$argc\n"; print_r($argv);
  if ($argc == 1) {
    echo "usage: gan.php {action}\n";
    echo "{action}\n";
    echo "  - harvest - Moissonne les Gan de manière incrémentale\n";
    echo "  - newHarvest - Moissonne les Gan en réinitialisant au péalable\n";
    echo "  - showHarvest - Affiche la moisson en Yaml\n";
    echo "  - storeHarvest - Enregistre la moisson en Yaml/pser\n";
    die();
  }
  else
    $a = $argv[1];
}
else { // non CLI
  $a = $_GET['a'] ?? null; // si $a vaut null alors action d'afficher dans le format $f
  $f = $_GET['f'] ?? 'html';
  if ($a) {
    if (!Access::roleAdmin()) {
      header('HTTP/1.1 403 Forbidden');
      die("Action interdite réservée aux administrateurs.");
    }
  }
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>gan</title></head><body>\n";
}

if ($a == 'menu') { // menu 
  echo "gan.php - Menu:<ul>\n";
  echo "<li>La moisson des GAN doit être effectuée en CLI</li>\n";
  echo "<li><a href='?a=showHarvest'>Affiche la moisson en Yaml</a></li>\n";
  echo "<li><a href='?a=storeHarvest'>Enregistre la moisson en Yaml/pser</a></li>\n";
  echo "<li><a href='?a=listMaps'>Affiche en Html les cartes avec synthèse moisson et lien vers Gan</a></li>\n";
  echo "<li><a href='?f=html'>Affiche en Html les cartes à mettre à jour les plus périmées d'abord</a></li>\n";
  echo "</ul>\n";
  die();
}

if ($a == 'harvest') { // moisson des GAN depuis le Shom en repartant de 0 
  //echo "Harvest ligne ",__LINE__,"\n";
  Gan::harvest();
  die();
}

if ($a == 'newHarvest') { // moisson des GAN depuis le Shom réinitialisant au préalable 
  //echo "fullHarvest ligne ",__LINE__,"\n";
  Gan::harvest(['reinit'=> true]);
  die();
}

if ($a == 'showHarvest') { // Affiche la moisson en Yaml 
  if (php_sapi_name() <> 'cli') 
    echo "<pre>\n";
  Gan::build();
  //print_r(Gan::$gans);
  echo Yaml::dump(Gan::allAsArray(), 4, 2);
  die();
}

if ($a == 'storeHarvest') { // Enregistre la moisson en Yaml/pser 
  Gan::build();
  file_put_contents(Gan::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  Gan::storeAsPser();
  die("Enregistrement des fichiers Yaml et pser ok\n");
}

if ($a == 'harvestAndStore') { // moisson des GAN depuis le Shom puis enregistrement en Yaml/pser
  Gan::harvest();
  Gan::build();
  file_put_contents(Gan::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  Gan::storeAsPser();
  die("Moisson puis enregistrement des fichiers Yaml et pser ok\n");
}

if ($a == 'fullHarvestAndStore') { // moisson des GAN depuis le Shom puis enregistrement en Yaml/pser
  Gan::harvest(['reinit'=> true]);
  Gan::build();
  file_put_contents(Gan::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  Gan::storeAsPser();
  die("Moisson puis enregistrement des fichiers Yaml et pser ok\n");
}

$gandir = __DIR__.'/gan';

if ($a == 'listMaps') { // Affiche en Html les cartes avec synthèse moisson et lien vers Gan
  $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
  echo "<table border=1>\n",
       "<th>",implode('</th><th>', ['mapid','title','FR','lastUpdt','gan','harvest','analyze','dp']),"</th>\n";
  foreach (Mapcat::maps() as $mapid => $map) {
    $mapa = $map->asArray();
    $ganHref = null; // href vers le Shom
    if (isset($mapa['modified'])) {
      $ganWeek = Gan::week($mapa['modified']);
      $url = "https://www.shom.fr/qr/gan/$mapid/$ganWeek";
      $ganHref = "<a href='$url'>$ganWeek</a>";
    }
    $ganHHref = null; // href local
    $ganAnalyze = Gan::gans($mapid); // résultat de l'analyse
    $age = $ganAnalyze['perempt'] ?? null;
    $ganAnalyze = (isset($ganAnalyze['edition']) ? ['edition'=> $ganAnalyze['edition']] : [])
      + (isset($ganAnalyze['corrections']) ? ['corrections'=> $ganAnalyze['corrections']] : []);
    if (file_exists("$gandir/$mapid-$ganWeek.html")) {
      $ganHHref = "<a href='gan/$mapid-$ganWeek.html'>$ganWeek</a>";
    }
    elseif ($errors["$mapid-$ganWeek"] ?? null) {
      $ganHHref = 'error';
    }
    echo "<tr><td>$mapid</td><td>$mapa[title]<br>",$mapa['edition'] ?? "édition indéfinie","</td>",
          "<td>",implode(', ', $mapa['mapsFrance']) ?? 'indéfini',"</td>",
          "<td>",$mapa['lastUpdate'] ?? 'indéfini',"</td>",
          "<td>",$ganHref ?? 'indéfini',"</td>",
          "<td>",$ganHHref ?? 'indéfini',"</td>\n",
          "<td><pre>",$ganAnalyze ? Yaml::dump($ganAnalyze) : '',"</pre></td>",
          "<td>",$age ?? 'indéfini',"</td>",
          "</tr>\n";
  }
  echo "</table>\n";
  die();
}

if (!$a && ($f == 'html')) {
  echo "<h2>Cartes à mettre à jour, les plus périmées d'abord (<a href='?a=menu'>?</a>)</h2>\n";
  Gan::loadFromPser();
  echo "- dates des moissons: ",Gan::$hvalid,"<br>\n";
  echo "- le degré de péremption (noté dp) est défini par le nbre de corrections non prises en compte et la zone couverte<br>\n";
  echo "<table border=1>\n","<th>",
    implode('</th><th>',
      ['mapid','title - édition - der. corr.','dp','FR','gan','liste des corr. du GAN depuis lastUpdt']),
    "</th>\n";
  foreach (Gan::gans() as $mapid => $gan) {
    $gana = $gan->asArray();
    $mapa = Mapcat::maps($mapid);
    $ganHref = null; // href vers le Shom
    $ganLHref = null; // href local
    if (isset($mapa['modified'])) {
      $ganWeek = Gan::week($mapa['modified']);
      $url = "https://www.shom.fr/qr/gan/$mapid/$ganWeek";
      $ganHref = "<a href='$url'>$ganWeek</a>";
      if (file_exists("$gandir/$mapid-$ganWeek.html"))
        $ganLHref = "<a href='gan/$mapid-$ganWeek.html'>$ganWeek</a>";
      elseif (isset($gana['harvestError']))
        $ganLHref = 'error';
    }
    if (isset($gana['ganEdition'])) {
      $mapa['edition'] = str_replace('° ','°', $mapa['edition']);
      if ($mapa['edition'] <> $gana['ganEdition']) {
        $ganec = ['ganEdition'=> $gana['ganEdition'], 'mapEdition'=> $mapa['edition']]
        + (isset($gana['corrections']) ? ['corrections'=> $gana['corrections']] : []);
      }
      else {
        $ganec = (isset($gana['ganEdition']) ? ['edition'=> $gana['ganEdition']] : [])
          + (isset($gana['corrections']) ? ['corrections'=> $gana['corrections']] : []);
      }
    }
    echo "<tr><td>$mapid</td><td>",$mapa['title'] ?? 'indéfini',
            "<br>$mapa[edition] - dern. corr.: ",$mapa['lastUpdate'] ?? 'indéfini',"</td>",
          "<td>",$gana['perempt'] ?? 'indéfini',"</td>",
          "<td>",implode(', ', $mapa['mapsFrance']) ?? 'indéfini',"</td>",
          //"<td>",$mapa['lastUpdate'] ?? 'indéfini',"</td>",
          "<td>",$ganHref ?? 'indéfini',"</td>",
          //"<td>",$ganLHref ?? 'indéfini',"</td>\n",
          "<td><pre>",$ganec ? Yaml::dump($ganec) : '',"</pre></td>",
          //"</tr><tr><td>gana[ganEdition]</td><td colspan=6>",dumpUtf8($gana['ganEdition']),"</td>",
          //"</tr><tr><td>mapa['edition']</td><td colspan=6>",dumpUtf8($mapa['edition']),"</td>",
          "</tr>\n";
  }
  echo "</table>\n";
  die();
}
