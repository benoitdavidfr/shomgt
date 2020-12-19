<?php
/*PhpDoc:
name: hgan.php
title: cat2/hgan.php - identifier les cartes à mettre à jour en interrogeant le GAN
doc: |
  L'objectif est d'identifier les cartes à mettre à jour en interrogeant le GAN.

  Certains traitements se font en cli (moissonnage), d'autres en non-CLI (affichage).

  Erreur 500 semble signfier que la carte n'est pas gérée dans le GAN, il s'agit visiblement surtout de cartes outre-mer
  Ex: https://gan.shom.fr/diffusion/qr/gan/6280/1931 - Partie Nord de Raiatea - Port d'Uturoa (1/12000)
  Le qrcode donne:
    Error Page
    status code: 404
    Exception Message: N/A

journal: |
  18/12/2020:
    - création
    - 1ère étape - moissonner le GAN et afficher pour chaque carte les corrections mentionnées par le GAN
    - il faut encore
      - décider si une carte doit ou non être mise à jour
      - faire des priorités ? utiliser la distinction métropole / DOM / COM/TOM ? nbre de corrections ?
      - packager pour en faire un process simple de mise à jour
    - plusieurs erreurs détectées
      - FR6284, FR6420, FR6823, FR7040, FR7135, FR7154, FR7436
    - des particularités (mise à jour ultérieure)
      - FR6713, FR6821, FR6930, FR7271
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/mapcat.inc.php';

use Symfony\Component\Yaml\Yaml;

function ganWeek(string $modified): string {
  $time = strtotime($modified);
  return substr(date('o', $time), 2) . date('W', $time);
}

// analyse du html du Gan notamment pour identifier les corrections et l'édition d'une carte - fonction complètement réécrite / V1
// retourne un array avec les champs title, edition et corrections + analyzeErrors en cas d'erreur d'analyse
// J'ai essayé de minimiser la dépendance au code Html !
function analyzeGanHtml(string $mapid, string $ganWeek): array {
  //if ($mapid <> 'FR6118') return [];
  //echo "<tr><td colspan=6><pre>";
  $record = [];
  $html = file_get_contents("gan/$mapid-$ganWeek.html");
  
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

class GanPart {
  protected string $title;
  protected array $bbox; // sous la forme ['SW'=> sw, 'NE'=> ne]
  
  function __construct(string $html) {
    //echo "html=$html\n";
    if (!preg_match('!^{div}\s*([^{]*){/div}{div}\s*([^{]*){/div}{div}\s*([^{]*){/div}\s*$!', $html, $matches))
      throw new Exception("Erreur de construction de GanPart sur '$html'");
    $this->title = trim($matches[1]);
    $this->bbox = ['SW'=> trim($matches[2]), 'NE'=> trim($matches[3])];
  }
  
  function asArray(): array {
    return [
      'title'=> $this->title,
      'bbox'=> $this->bbox,
    ];
  }
};

// synthèse des GAN par carte à la date de moisson des GAN / catalogue ou indication d'erreur d'interrogation des GAN
class Gan {
  const GAN_DIR = __DIR__.'/gan';
  const PATH = __DIR__.'/gans.'; // chemin des fichiers stockant la synthèse en pser ou en yaml, lui ajouter l'extension
  const PATH_PSER = self::PATH.'pser'; // chemin du fichier stockant le catalogue en pser
  const PATH_YAML = self::PATH.'yaml'; // chemin du fichier stockant le catalogue en  Yaml
  static string $modified=''; // date de la moisson des GAN
  static array $gans=[]; // dictionnaire [$mapid => Gan]
  
  protected ?string $groupTitle=null; // sur-titre optionnel identifiant un ensemble de cartes
  protected string $title='';
  protected ?string $edition=null;
  protected array $bbox=[]; // sous la forme ['SW'=> sw, 'NE'=> ne]
  protected array $hasPart=[]; // cartouches
  protected array $corrections=[];
  protected array $analyzeErrors=[]; // erreurs éventuelles d'analyse du résultat du moissonnage
  protected string $harvestError=''; // erreur éventuelle du moissonnage
  
  static function build() { // synhèse des GAN pour une moisson donnée enregistre en pser et en Yaml
    self::$modified = date(DATE_ATOM, filemtime(self::GAN_DIR));
    $gans = [];
    foreach (Mapcat::maps() as $mapid => $map) {
      if ($map->obsolete()) continue;
      $mapa = $map->asArray();
      if (!isset($mapa['modified'])) continue;
      $ganWeek = ganWeek($mapa['modified']);
      if (!file_exists(self::GAN_DIR."/$mapid-$ganWeek.html")) continue;
      self::$gans[$mapid] = new self(analyzeGanHtml($mapid, $ganWeek));
    }
    $errors = file_exists(self::GAN_DIR.'/errors.yaml') ? Yaml::parsefile(self::GAN_DIR.'/errors.yaml') : [];
    //print_r($errors);
    foreach ($errors as $id => $errorMessage) {
      $mapid = substr($id, 0, 6);
      self::$gans[$mapid] = new self(['harvestError'=> $errorMessage]);
    }
    ksort(self::$gans);
  }

  function __construct(array $record) {
    $this->oldTitle = $record['title'] ?? [];
    $this->postProcessTitle($record['title'] ?? []);
    $this->edition = $record['edition'] ?? null;
    $this->corrections = $record['corrections'] ?? [];
    $this->analyzeErrors = $record['analyzeErrors'] ?? [];
    $this->harvestError = $record['harvestError'] ?? '';
  }
  
  function postProcessTitle(array $record) {
    if (!$record) return;
    $title = array_shift($record);
    foreach ($record as $part)
      $this->hasPart[] = new GanPart($part);
    if (!preg_match('!^([^{]*){div}([^{]*){/div}({div}([^{]*){/div})?({div}([^{]*){/div})?\s*$!', $title, $matches))
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

  static function allAsArray(): array {
    $all = [];
    foreach (self::$gans as $mapid => $gan)
      $all[$mapid] = $gan->asArray();
    return [
      'title'=> "Synthèse du résultat du moissonnage des GAN des cartes du catalogue",
      'description'=> "Seules sont présentes les cartes non obsolètes ayant une date de dernière correction (modified)",
      '$id'=> 'https://geoapi.fr/shomgt/cat2/gans',
      '$schema'=> __DIR__.'/gans',
      'modified'=> self::$modified,
      'gans'=> $all,
      'eof'=> null,
    ];
  }
  
  function asArray(): array {
    if ($this->harvestError)
      return ['harvestError'=> $this->harvestError];
    elseif (!$this->title)
      return [];
    else
      return
        [] // ['oldTitle'=> $this->oldTitle]
      + ($this->groupTitle ? ['groupTitle'=> $this->groupTitle] : [])
      + ['title'=> $this->title]
      + ['edition'=> $this->edition]
      + ($this->bbox ? ['bbox'=> $this->bbox] : [])
      + ($this->hasPart ? ['hasPart'=> array_map(function (GanPart $part): array { return $part->asArray(); }, $this->hasPart)] : [])
      + ($this->corrections ? ['corrections'=> $this->corrections] : [])
      + ($this->analyzeErrors ? ['analyzeErrors'=> $this->analyzeErrors] : [])
      ;
  }
  
  static function storeAsPser() { // enregistre le catalogue comme pser 
    file_put_contents(self::PATH_PSER, serialize([
      'modified'=> self::$modified,
      'gans'=> self::$gans,
    ]));
  }
};

if (php_sapi_name() <> 'cli') {
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>hgan</title></head><body>\n";
  if (!isset($_GET['action'])) {
    echo "hgan.php - Actions proposées:<ul>\n";
    echo "<li><a href='?action=list'>Lister les cartes avec lien vers Gan</a></li>\n";
    //echo "<li><a href='?action=harvest'>Moissonner les Gan</a></li>\n";
    //echo "<li><a href='?action=rename'>Renommer les fichiers</a></li>\n";
    echo "<li><a href='?action=yamlpser'>Fabrique les fichiers gans.yaml et gans.pser</a></li>\n";
    echo "<li><a href='?action=yaml'>Affiche le Yaml</a></li>\n";
    echo "</ul>\n";
    die();
  }
  else
    $action = $_GET['action'];
}
else {
  //echo "argc=$argc\n"; print_r($argv);
  if ($argc == 1) {
    echo "usage: hgan.php {action}\n";
    echo "{action}\n";
    echo "  - harvest - Moissonner les Gan\n";
    die();
  }
  else
    $action = $argv[1];
}

$gandir = __DIR__.'/gan';

if ($action == 'list') {
  $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
  echo "<table border=1>\n",
       "<th>",implode('</th><th>', ['mapid','title','lastUpdate','gan','harvest','analyze']),"</th>\n";
  foreach (Mapcat::maps() as $mapid => $map) {
    $mapa = $map->asArray();
    $sStart = $map->obsolete() ? '<s>' : '';
    $sEnd = $map->obsolete() ? '</s>' : '';
    $ganHref = null; // href vers le Shom
    if (isset($mapa['modified']) && !$map->obsolete()) {
      $ganWeek = ganWeek($mapa['modified']);
      $url = "https://www.shom.fr/qr/gan/$mapid/$ganWeek";
      $ganHref = "<a href='$url'>$ganWeek</a>";
    }
    $ganHHref = null; // href local
    $ganAnalyze = null; // résultat de l'analyse
    if (file_exists("$gandir/$mapid-$ganWeek.html")) {
      $ganHHref = "<a href='gan/$mapid-$ganWeek.html'>$ganWeek</a>";
      $ganAnalyze = analyzeGanHtml($mapid, $ganWeek);
    }
    elseif ($errors["$mapid-$ganWeek"] ?? null) {
      $ganHHref = 'error';
    }
    echo "<tr><td>$mapid</td><td>$sStart$mapa[title]$sEnd<br>$mapa[edition]</td>",
          "<td>",$mapa['lastUpdate'] ?? 'indéfini',"</td>",
          "<td>",$ganHref ?? 'indéfini',"</td>",
          "<td>",$ganHHref ?? 'indéfini',"</td>",
          "<td><pre>",$ganAnalyze ? Yaml::dump($ganAnalyze) : '',"</pre></td>",
          "</tr>\n";
  }
  echo "</table>\n";
  die();
}


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

if ($action == 'harvest') {
  if (!file_exists($gandir))
    mkdir($gandir);
  elseif (0) { // suppression des fichiers existants
    if (!$dh = opendir($gandir))
      die("Ouverture de $gandir impossible");
    while (($filename = readdir($dh)) !== false) {
      if (!in_array($filename, ['.','..']))
        unlink("$gandir/$filename");
    }
  }
  $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
  //print_r($errors);
  Mapcat::init();
  foreach (Mapcat::$maps as $mapid => $map) {
    $mapa = $map->asArray();
    if (isset($mapa['modified']) && !$map->obsolete()) {
      $ganWeek = ganWeek($mapa['modified']);
      if (!file_exists("$gandir/$mapid-$ganWeek.html") && !isset($errors["$mapid-$ganWeek"])) {
        $url = "https://www.shom.fr/qr/gan/$mapid/$ganWeek";
        if (($contents = @file_get_contents($url)) === false) {
          $message = "Erreur ".(http_error_code($http_response_header) ?? 'erreur http_error_code()')." de lecture de $url";
          echo "$message\n";
          file_put_contents("$gandir/errors.yaml", "$mapid-$ganWeek: $message\n", FILE_APPEND);
        }
        else {
          file_put_contents("$gandir/$mapid-$ganWeek.html", $contents);
          echo "Lecture $url ok\n";
        }
      }
    }
  }
  die();
}

/*if ($action == 'rename') {
  if (!$dh = opendir($gandir))
    die("Ouverture de $gandir impossible");
  while (($filename = readdir($dh)) !== false) {
    if (in_array($filename, ['.','..'])) continue;
    if (!preg_match('!\.html$!', $filename)) {
      echo "rename $gandir/$filename $gandir/$filename.html\n";
      rename("$gandir/$filename", "$gandir/$filename.html");
    }
  }
  die();
}*/

if ($action == 'yaml') {
  echo "<pre>\n";
  Gan::build();
  //print_r(Gan::$gans);
  echo Yaml::dump(Gan::allAsArray(), 4, 2);
  die();
}

if ($action == 'yamlpser') {
  Gan::build();
  file_put_contents(Gan::PATH.'yaml', Yaml::dump(Gan::allAsArray(), 4, 2));
  Gan::storeAsPser();
  die("Enregistrement des fichiers Yaml et pser ok\n");
}
