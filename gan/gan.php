<?php
/** IHM de gestion des GAN
 * L'objectif est de moissonner les GAN des cartes définies dans le portefeuille
 * et de fabriquer un fichier gans.yaml/pser de synthèse
 *
 * Le chemin du portefeuille est défini par:
 *   - la var. d'env. SHOMGT3_DASHBOARD_PORTFOLIO_PATH si elle est définie
 *   - sinon la var. d'env. SHOMGT3_PORTFOLIO_PATH si elle est définie
 *   - sinon erreur
 *
 * Le script propose en CLI:
 *   - de moissonner les GANs de manière incrémentale, cad uniq. les GAN absents
 *   - de moissonner les GANs en effacant les précédentes moissons
 *   - de fabriquer la synthèse en yaml/pser
 *   - d'afficher cette synthèse
 *
 * Certains traitements se font en cli (moissonnage), d'autres en non-CLI (affichage).

 * Erreur 500 semble signfier que la carte n'est pas gérée dans le GAN, il s'agit visiblement surtout de cartes outre-mer
 * Ex: https://gan.shom.fr/diffusion/qr/gan/6280/1931 - Partie Nord de Raiatea - Port d'Uturoa (1/12000)
 * Le qrcode donne:
 *   Error Page
 *   status code: 404
 *   Exception Message: N/A
 *
 * Si un proxy est nécessaire pour interroger les GANs, il doit être défini dans ../secrets/secretconfig.inc.php (non effectif)
 *
 * Tests de analyzeHtml() sur qqs cartes types (incomplet):
 *   - 6616 - carte avec 2 cartouches et une correction
 *   - 7330 - carte sans GAN
 *
 * journal: |
 * 12/6/2023:
 *   - prise en compte de la restructuration du portefeuille
 * 2/8/2022:
 *   - corrections suite à PhpStan level 6
 * 2/7/2022:
 *   - ajout de la var;env. SHOMGT3_DASHBOARD_INCOMING_PATH qui permet de référencer des cartes différentes de sgserver
 *   - ajout dans le GAN du champ scale
 * 12/6/2022:
 *   - fork dans ShomGt3
 *   - restriction fonctionnelle au moissonnage du GAN et à la construction des fichiers gans.yaml/pser
 *     - le calcul du degré de péremption est transféré dans dashboard/index.php
 *   - le lien avec la liste des cartes du portefuille est effectué par la fonction maps() qui lit la liste des cartes
 *     exposées par sgserver
 * 31/5/2022:
 *   - désactivation de la vérification SSL
 * @package shomgt\gan
 */
namespace gan;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/gan.inc.php';
require_once __DIR__.'/../bo/portfolio.inc.php';

use Symfony\Component\Yaml\Yaml;

/** Verrou d'utilisation pour garantir que le script n'est pas utilisé plusieurs fois simultanément
 * 3 opérations:
 *  - locked() pour connaitre l'état du verrou
 *  - lock() pour le vérouiller
 *  - unlock() pour le dévérouiller
 * Le verrou est implémenté par l'existence d'un fichier.
*/
class Lock {
  /** chemin du fichier utilisé pour le verrou */
  const LOCK_FILEPATH = __DIR__.'/LOCK.txt';
  
  /** Si le verrou existe alors renvoie le contenu du fichier avec la date de verrou */
  static function locked(): ?string {
    if (is_file(self::LOCK_FILEPATH))
      return file_get_contents(self::LOCK_FILEPATH);
    else
      return null;
  }
  
  /** verouille, renvoie vrai si ok, false si le verrou existait déjà */
  static function lock(): bool {
    if (is_file(self::LOCK_FILEPATH))
      return false;
    else {
      file_put_contents(self::LOCK_FILEPATH, "Verrou déposé le ".date('c')."\n");
      return true;
    }
  }
  
  /** dévérouille */
  static function unlock(): void {
    unlink(self::LOCK_FILEPATH);
  }
};

/** extrait le code d'erreur Http 
 * @param array<int, string> $http_response_header */
function http_error_code(?array $http_response_header): ?string {
  if (!isset($http_response_header))
    return 'indéfini';
  $http_error_code = null;
  foreach ($http_response_header as $line) {
    if (preg_match('!^HTTP/.\.. (\d+) !', $line, $matches))
      $http_error_code = $matches[1];
  }
  return $http_error_code;
}

/** fabrique un context Http */
function httpContext(): mixed {
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


/** Classe regroupant des méthodes statiques de gestion des GAN */
class GanStatic {
  const GAN_DIR = __DIR__.'/gan';
  
  /** transforme une date en semaine sur 4 caractères comme utilisé par le GAN */
  static function week(string $modified): string {
    // Il y a des dates avant 2000 qui font planter le GAN
    if ($modified < '2017-01-01') { // Si la date est avant le 1/1/2017
      //echo "modified = $modified\n";
      return '1701'; // alors je démarre à la semaine 1 de 2017
    }
    $time = strtotime($modified);
    $ganWeek = substr(date('o', $time), 2) . date('W', $time);
    //echo "week($modified) -> $ganWeek\n";
    return $ganWeek;
  }
  
  /**
   * function harvest() - moissonne les GAN par carte dans le répertoire self::GAN_DIR
   *
   * Les cartes interrogées sont celles de Portfolio::$all
   *
   * @param array<string, bool> $options
   */
  static function harvest(array $options=[]): void {
    //echo "Harvest ligne ",__LINE__,"\n";
    $gandir = self::GAN_DIR;
    if (!file_exists(self::GAN_DIR))
      mkdir(self::GAN_DIR);
    elseif ($options['reinit'] ?? false) { // suppression des fichiers existants
      foreach (new \DirectoryIterator(self::GAN_DIR) as $filename) {
        if (!in_array($filename, ['.','..']))
          unlink("$gandir/$filename");
      }
    }
    $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
    //print_r($errors);
    $i = 0;
    $n = count(\bo\Portfolio::$all);
    foreach (\bo\Portfolio::$all as $mapnum => $map) {
      //echo "mapnum=$mapnum\n"; print_r($map);
      if ($modified = $map['dateMD']['value'] ?? $map['dateArchive']) {
        $ganWeek = GanStatic::week($modified);
        if (!file_exists("$gandir/$mapnum-$ganWeek.html") && !isset($errors["$mapnum-$ganWeek"])) {
          //$url = "https://www.shom.fr/qr/gan/$mapnum/$ganWeek";
          $url = "https://gan.shom.fr/diffusion/qr/gan/$mapnum/$ganWeek";
          //echo "url=$url\n";
          if (($contents = file_get_contents($url, false, httpContext())) === false) {
            $message = "Erreur "
              .(isset($http_response_header) ? http_error_code($http_response_header) : 'non définie') // @phpstan-ignore-line
              ." de lecture de $url";
            echo "$message\n";
            file_put_contents("$gandir/errors.yaml", "$mapnum-$ganWeek: $message\n", FILE_APPEND);
          }
          else {
            file_put_contents("$gandir/$mapnum-$ganWeek.html", $contents);
            echo "Lecture $url ok [$i/$n]\n";
          }
        }
        elseif (0) { // @phpstan-ignore-line // déverminage 
          echo "le fichier $gandir/$mapnum-$ganWeek.html existe || errors[$mapnum-$ganWeek]\n";
        }
      }
      $i++;
    }
  }
  
  /**
   * analyzeHtml() - analyse du Html du GAN
   *
   * analyse du html du Gan notamment pour identifier les corrections et l'édition d'une carte
   * retourne un array avec les champs title, edition et corrections + analyzeErrors en cas d'erreur d'analyse
   * J'ai essayé de minimiser la dépendance au code Html !
   *
   * @return array{}|array{analyzeErrors: list<string>}|array{title: list<string>, edition: string, scale: list<string>}
   */
  static function analyzeHtml(string $html): array {
    //echo "<tr><td colspan=6><pre>";
    $record = [];
    $html = preg_replace('!(<font [^>]*>|</font>|<b>|</b>)!', '', $html);

    //echo $html;
    
    // lit les cellules de la colonne scale du tableau du haut
    $pattern = '!<td class="column-scale align-top">([^<]*)</td>!';
    while (preg_match($pattern, $html, $matches)) {
      if (!isset($record['scale']))
        $record['scale'] = [];
      $record['scale'][] = $matches[1];
      $html = preg_replace($pattern, '', $html, 1);
    }
      
    // lit la colonne title du tableau du haut qui contient titre, cartouches, édition, coordonnées
    $pattern = '!<td class="column-title align-top">(([^<]*|<div|</div)*)</td>!';
    while (preg_match($pattern, $html, $matches)) {
      if (!isset($record['title']))
        $record['title'] = [];
      $record['title'][] = str_replace(['<','>'], ['{','}'], $matches[1]);
      $html = preg_replace($pattern, '', $html, 1);
    }
    if (isset($record['title']))
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
  
  /** pour mise au point effectue l'analyse du GAN pour une carte */
  static function analyzeHtmlOfMap(string $mapnum): void {
    $map = \bo\Portfolio::$all[$mapnum];
    echo 'map='; print_r($map);
    $modified = $map['dateMD']['value'] ?? $map['dateArchive'];
    $ganWeek = GanStatic::week($modified);
    $gandir = self::GAN_DIR;
    $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
    if (isset($errors["$mapnum-$ganWeek"])) {
      echo $errors["$mapnum-$ganWeek"];
    }
    elseif (!file_exists(self::GAN_DIR."/$mapnum-$ganWeek.html")) {
      echo "moisson $mapnum-$ganWeek absente à moissonner\n";
    }
    else {
      $mtime = filemtime(self::GAN_DIR."/$mapnum-$ganWeek.html");
      $html = file_get_contents(self::GAN_DIR."/$mapnum-$ganWeek.html");
      $analyze = self::analyzeHtml($html);
      echo 'analyzeHtml='; print_r($analyze);
      //echo 'analyzeHtml='; var_dump($analyze);
      //echo Yaml::dump(['analyzeHtml'=> $analyze], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      $gan = new Gan($mapnum, $analyze, /*$map,*/ $mtime);
      echo Yaml::dump(['gan'=> $gan->asArray()], 4, 2);
    }
  }
  
  /** construit la synhèse des GAN de la moisson existante */
  static function build(): void {
    $minvalid = null;
    $maxvalid = null;

    // Ce code permet de détecter les fichiers Html manquants nécessitant une moisson
    $gandir = self::GAN_DIR;
    $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
    foreach (\bo\Portfolio::$all as $mapnum => $map) {
      if ($modified = $map['dateMD']['value'] ?? $map['dateArchive']) {
        $ganWeek = GanStatic::week($modified);
        if (isset($errors["$mapnum-$ganWeek"])) { }
        elseif (!file_exists("$gandir/$mapnum-$ganWeek.html")) {
          echo "moisson $mapnum-$ganWeek absente à moissonner\n";
        }
        else {
          $mtime = filemtime("$gandir/$mapnum-$ganWeek.html");
          if (!$minvalid || ($mtime < $minvalid))
            $minvalid = $mtime;
          if (!$maxvalid || ($mtime > $maxvalid))
            $maxvalid = $mtime;
          $html = file_get_contents("$gandir/$mapnum-$ganWeek.html");
          Gan::$all[$mapnum] = new Gan($mapnum, self::analyzeHtml($html), /*$map,*/ $mtime);
        }
      }
    }
    Gan::$hvalid = date('Y-m-d', $minvalid).'/'.date('Y-m-d', $maxvalid);

    $errors = file_exists(self::GAN_DIR.'/errors.yaml') ? Yaml::parsefile(self::GAN_DIR.'/errors.yaml') : [];
    //print_r($errors);
    foreach ($errors as $id => $errorMessage) {
      $mapid = substr($id, 0, 4);
      Gan::$all[$mapid] = new Gan($mapid, ['harvestError'=> $errorMessage], /*$mapa,*/ null);
    }
  }
};


if (php_sapi_name() == 'cli') {
  //echo "argc=$argc\n"; print_r($argv);
  if ($argc == 1) {
    echo "usage: gan.php {action}\n";
    echo "{action}\n";
    echo "  - harvest - Moissonne les Gan de manière incrémentale\n";
    echo "  - newHarvest - Moissonne les Gan en réinitialisant au péalable\n";
    echo "  - showHarvest - Affiche la moisson en Yaml\n";
    echo "  - storeHarvest - Enregistre la moisson en Yaml/pser\n";
    echo "  - buildPserFromYaml - Reconstruit le pser à partir du Yaml\n";
    echo "  - analyzeHtml {mapNum} - Analyse le GAN de la carte {mapNum}\n";
    die();
  }
  else
    $a = $argv[1];
}
else { // non CLI
  $a = $_GET['a'] ?? 'showHarvest'; // si $a vaut null alors action d'afficher dans le format $f
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>gan</title></head><body><pre>\n";
}

switch ($a) {
  case 'menu': { // menu non CLI
    echo "gan.php - Menu:<ul>\n";
    echo "<li>La moisson des GAN doit être effectuée en CLI</li>\n";
    echo "<li><a href='?a=showHarvest'>Affiche la moisson en Yaml</a></li>\n";
    echo "<li><a href='?a=storeHarvest'>Enregistre la moisson en Yaml/pser</a></li>\n";
    //echo "<li><a href='?a=listMaps'>Affiche en Html les cartes avec synthèse moisson et lien vers Gan</a></li>\n";
    //echo "<li><a href='?f=html'>Affiche en Html les cartes à mettre à jour les plus périmées d'abord</a></li>\n";
    echo "<li><a href='?a=unlock'>Supprime le verrou</a></li>\n";
    echo "</ul>\n";
    die();
  }
  case 'harvest': { // moisson des GAN depuis le Shom en repartant de 0 
    if ($lock = Lock::locked()) {
      die("Exécution impossible, un autre utilisateur est en train d'utiliser ce script<br>\n$lock<br>\n");
    }
    Lock::lock();
    GanStatic::harvest();
    Lock::unlock();
    die();
  }
  case 'newHarvest': { // moisson des GAN depuis le Shom réinitialisant au préalable 
    if ($lock = Lock::locked()) {
      die("Exécution impossible, un autre utilisateur est en train d'utiliser ce script<br>\n$lock<br>\n");
    }
    Lock::lock();
    GanStatic::harvest(['reinit'=> true]);
    Lock::unlock();
    die();
  }
  case 'showHarvest': { // Affiche la moisson en Yaml 
    GanStatic::build();
    echo Yaml::dump(Gan::allAsArray(), 4, 2);
    die();
  }
  case 'storeHarvest': { // Enregistre la moisson en Yaml/pser 
    GanStatic::build();
    file_put_contents(Gan::PATH_YAML, Yaml::dump(Gan::allAsArray(), 4, 2));
    Gan::storeAsPser();
    die("Enregistrement des fichiers Yaml et pser ok\n");
  }
  case 'harvestAndStore': { // moisson des GAN depuis le Shom puis enregistrement en Yaml/pser
    if ($lock = Lock::locked()) {
      die("Exécution impossible, un autre utilisateur est en train d'utiliser ce script<br>\n$lock<br>\n");
    }
    Lock::lock();
    GanStatic::harvest();
    GanStatic::build();
    file_put_contents(Gan::PATH_YAML, Yaml::dump(Gan::allAsArray(), 4, 2));
    Gan::storeAsPser();
    Lock::unlock();
    die("Moisson puis enregistrement des fichiers Yaml et pser ok\n");
  }
  case 'newHarvestAndStore': { // moisson des GAN en réinit. puis enregistrement en Yaml/pser
    if ($lock = Lock::locked()) {
      die("Exécution impossible, un autre utilisateur est en train d'utiliser ce script<br>\n$lock<br>\n");
    }
    Lock::lock();
    GanStatic::harvest(['reinit'=> true]);
    GanStatic::build();
    file_put_contents(Gan::PATH_YAML, Yaml::dump(Gan::allAsArray(), 4, 2));
    Gan::storeAsPser();
    Lock::unlock();
    die("Moisson puis enregistrement des fichiers Yaml et pser ok\n");
  }
  case 'buildPserFromYaml': { // Reconstruit le pser à partir du Yaml
    $array = Yaml::parseFile(Gan::PATH_YAML);
    Gan::buildFromArrayOfAll($array);
    //echo Yaml::dump(Gan::allAsArray(), 4, 2);
    Gan::storeAsPser();
    die("Fin ok, fichier pser créé\n");
  }
  case 'analyzeHtml': { // analyse l'Html du GAN d'une carte particulière 
    if (!($mapNum = $argv[2] ?? null))
      die("Errue, la commande nécessite en paramètre le numéro de la carte\n");
    GanStatic::analyzeHtmlOfMap($mapNum);
    die();
  }
  case 'unlock': {
    Lock::unlock();
    die("Verrou supprimé\n");
  }
  default: die("Action $a inconnue");
}

