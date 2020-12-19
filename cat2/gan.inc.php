<?php
/*PhpDoc:
name: gan.inc.php
title: cat2/gan.inc.php - gestion des gan
doc: |
journal: |
  19/12/2020:
    - définition de la classe Gan, stockage en Yaml et en pser
    - utilisation du pser pour la liste
    - définition du concept d'age pour hiérarchiser des priorités de mise à jour, plus agé <=> plus important à mettre à jour
    - affichage des cartes du catalogue par age décroissant
    - formalisation d'une doctrine d'importance des territoires poura la mise à jour
    - fork de hgan.php
includes: [mapcat.inc.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/mapcat.inc.php';

use Symfony\Component\Yaml\Yaml;

class Territoire { // définit la doctrine d'importance des différents territoires pour la mise à jour 
  // enum: [FR, FX, GP, GF, MQ, YT, RE, PM, BL, MF, TF, PF, WF, NC, CP]
  static $statuts = [ // définition des statuts
    'FR'=> '',
    'FX'=> 'FX',
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
  // FX en premier car c'est là où j'ai constaté des clients
  // DOM ensuite en raison de la présence des services déconcentrés de l'Etat
  // TOM ensuite en raison la responsabilité de l'Etat
  // COM enfin en raison de l'autonomie de ces collectivités
  static function coeff(array $mapsFrance): float {
    $statuts=[]; // [statut => 1]
    foreach ($mapsFrance as $terr) {
      $statuts[self::$statuts[$terr]] = 1;
    }
    if (isset($statuts[''])) return 1;
    if (isset($statuts['FX'])) return 1;
    if (isset($statuts['DOM'])) return 1/2;
    if (isset($statuts['COM'])) return 1/4;
    if (isset($statuts['TOM'])) return 1/2;
  }
};

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
  protected array $mapsFrance=[]; // provient de Mapcat
  protected array $hasPart=[]; // cartouches
  protected array $corrections=[];
  protected array $analyzeErrors=[]; // erreurs éventuelles d'analyse du résultat du moissonnage
  protected string $harvestError=''; // erreur éventuelle du moissonnage
  protected ?float $age=null; // note reflétant la nécessité de mettre à jour la carte
    // calculée en fonction du chgt d'édition, du nbre de corrections et du territoire
    // -1 ssi erreur de moissonnage
    // 0 ssi pas de mise à jour nécessaire
  
  static function build() { // construit la synhèse des GAN pour une moisson donnée
    self::$modified = date(DATE_ATOM, filemtime(self::GAN_DIR));
    $gans = [];
    foreach (Mapcat::maps() as $mapid => $map) {
      if ($map->obsolete()) continue;
      $mapa = $map->asArray();
      if (!isset($mapa['modified'])) continue;
      $ganWeek = ganWeek($mapa['modified']);
      if (!file_exists(self::GAN_DIR."/$mapid-$ganWeek.html")) continue;
      self::$gans[$mapid] = new self($mapid, analyzeGanHtml($mapid, $ganWeek));
    }
    $errors = file_exists(self::GAN_DIR.'/errors.yaml') ? Yaml::parsefile(self::GAN_DIR.'/errors.yaml') : [];
    //print_r($errors);
    foreach ($errors as $id => $errorMessage) {
      $mapid = substr($id, 0, 6);
      self::$gans[$mapid] = new self($mapid, ['harvestError'=> $errorMessage]);
    }
    // tri pour mettre en début de tableau les plus agés
    uasort(self::$gans, function(Gan $a, Gan $b) { return ($a->age == $b->age) ? 0 : (($a->age > $b->age) ? -1 : 1); });
  }
  
  function __construct(string $mapid, array $record) {
    $this->oldTitle = $record['title'] ?? [];
    $this->postProcessTitle($record['title'] ?? []);
    $this->edition = $record['edition'] ?? null;
    $this->mapsFrance = Mapcat::maps($mapid)['mapsFrance'];
    $this->corrections = $record['corrections'] ?? [];
    $this->analyzeErrors = $record['analyzeErrors'] ?? [];
    $this->harvestError = $record['harvestError'] ?? '';
    $this->age = $this->calcAge();
  }
  
  private function postProcessTitle(array $record) {
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

  function calcAge(): float { // retourne -1 si erreur, 0 si carte à jour
    if ($this->harvestError)
      return -1;
    if (count($this->corrections)==0)
      return 0;
    else
      return count($this->corrections) * Territoire::coeff($this->mapsFrance);
  }
  
  static function gans(?string $mapid=null): array { // retourne soit un array de tous les gans soit le gan demandé comme array
    if (!self::$gans)
      self::loadFromPser();
    if (!$mapid)
      return self::$gans;
    elseif (isset(self::$gans[$mapid]))
      return self::$gans[$mapid]->asArray();
    else
      return [];
  }
  
  static function allAsArray(): array {
    $gans = [];
    foreach (self::gans() as $mapid => $gan)
      $gans[$mapid] = $gan->asArray();
    return [
      'title'=> "Synthèse du résultat du moissonnage des GAN des cartes du catalogue",
      'description'=> "Seules sont présentes les cartes non obsolètes ayant une date de dernière correction (modified)",
      '$id'=> 'https://geoapi.fr/shomgt/cat2/gans',
      '$schema'=> __DIR__.'/gans',
      'modified'=> self::$modified,
      'gans'=> $gans,
      'eof'=> null,
    ];
  }
  
  function asArray(): array {
    if ($this->harvestError)
      return ['harvestError'=> $this->harvestError, 'age'=> $this->calcAge()];
    elseif (!$this->title)
      return ['age'=> $this->calcAge()];
    else
      return
        ['age'=> $this->calcAge()]
      // ['oldTitle'=> $this->oldTitle]
      + ($this->groupTitle ? ['groupTitle'=> $this->groupTitle] : [])
      + ['title'=> $this->title]
      + ['edition'=> $this->edition]
      + ['mapsFrance'=> $this->mapsFrance]
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
  
  static function loadFromPser() { // charge les données depuis le pser
    $contents = unserialize(file_get_contents(self::PATH_PSER));
    self::$modified = $contents['modified'];
    self::$gans = $contents['gans'];
  }
};
