<?php
/*PhpDoc:
name: gan.inc.php
title: dashboard/gan.inc.php - définition des classes GanStatic, Gan et GanInSet pour gérer et utiliser les GAN
classes:
doc: |
  restructuration des classes Gan et GanInSet pour fusionner les définitions de gan.php et index.php
*/
require_once __DIR__.'/portfolio.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: GanInSet
title: class GanInSet - description d'un cartouche dans la synthèse d'une carte
*/
class GanInSet {
  protected string $title;
  protected ?string $scale=null; // échelle
  /** @var array<string, string> $spatial */
  protected array $spatial; // sous la forme ['SW'=> sw, 'NE'=> ne]
  
  function __construct(string $html) {
    //echo "html=$html\n";
    if (!preg_match('!^\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*$!', $html, $matches))
      throw new Exception("Erreur de construction de GanInSet sur '$html'");
    $this->title = trim($matches[1]);
    $this->spatial = ['SW'=> trim($matches[2]), 'NE'=> trim($matches[3])];
  }
  
  function scale(): ?string { return $this->scale; }
  function setScale(string $scale): void { $this->scale = $scale; }
  /** @return array<string, string> */
  function spatial(): array { return $this->spatial; }
  
  /** @return array<string, mixed> */
  function asArray(): array {
    return [
      'title'=> $this->title,
      'scale'=> $this->scale,
      'spatial'=> $this->spatial,
    ];
  }
};

/*PhpDoc: classes
name: Gan
title: class Gan - synthèse des GAN par carte à la date de moisson des GAN / catalogue ou indication d'erreur d'interrogation des GAN
methods:
doc: |
  Moisonne le GAN des cartes du portefeuille non obsolètes (et donc le champ modified est connu).
  Analyse les fichiers Html moissonnés et en produit une synthèse.
*/
class Gan {
  // le champ édition du GAN comporte des erreurs qui perturbent le TdB, ci-dessous corrections
  // Il se lit {{num}=> [{edACorriger}=> {edCorrigée}]}
  // Liste d'écarts transmise le 15/6/2022 au Shom
  // Les écarts ci-dessous sont ceux restants après corrections du Shom
  const CORRECTIONS = [
    '6942'=> ["Edition n°3 - 2015"=> "Edition n°3 - 2016"],
    '7143'=> ["Edition n°2 - 2002"=> "Edition n°2 - 2003"],
    '7268'=> ["Publication 1992"=> "Publication 1993"],
    '7411'=> ["Edition n°2 - 2002"=> "Edition n°2 - 2003"],
    '7414'=> ["Edition n°3 - 2013"=> "Edition n°3 - 2014"],
    '7507'=> ["Publication 1995"=> "Publication 1996"],
    '7593'=> ["Publication 2002"=> "Publication 2003"],
    '7755'=> ["Publication 2015"=> "Publication 2016"],
  ];

  static string $hvalid=''; // intervalles des dates de la moisson des GAN
  /** @var array<string, Gan> $gans */
  static array $gans=[]; // dictionnaire [$mapnum => Gan]
  
  protected string $mapnum;
  protected ?string $groupTitle=null; // sur-titre optionnel identifiant un ensemble de cartes
  protected string $title=''; // titre
  protected ?string $scale=null; // échelle
  protected ?string $edition=null; // edition
  /** @var array<int, array<string, string>> $corrections */
  protected array $corrections=[]; // liste des corrections
  /** @var array<string, string> $spatial */
  protected array $spatial=[]; // sous la forme ['SW'=> sw, 'NE'=> ne]
  /** @var array<int, GanInSet> $inSets */
  protected array $inSets=[]; // cartouches
  /** @var array<int, string> $analyzeErrors */
  protected array $analyzeErrors=[]; // erreurs éventuelles d'analyse du résultat du moissonnage
  protected string $valid; // date de moissonnage du GAN en format ISO
  protected string $harvestError=''; // erreur éventuelle du moissonnage

  // record est le résultat de l'analyse du fichier Html, $map est l'enregistrement de maps.json
  /** @param array<string, mixed> $record */
  function __construct(string $mapnum, array $record, /*array $map,*/ ?int $mtime) {
    echo "mapnum=$mapnum\n";
    //echo '$record='; print_r($record);
    //echo '$mapa='; print_r($mapa);
    $this->mapnum = $mapnum;
    // cas où soit le GAN ne renvoie aucune info signifiant qu'il n'y a pas de corrections, soit il renvoie une erreur
    if (!$record || isset($record['harvestError'])) {
      $this->edition = null;
    }
    else { // cas où il existe des corrections
      $this->postProcessTitle($record['title']);
      $this->edition = $record['edition'];
    }
    
    // transfert des infos sur l'échelle des différents espaces
    if (isset($record['scale'][0])) {
      $this->scale = $record['scale'][0];
      array_shift($record['scale']);
    }
    foreach ($this->inSets ?? [] as $i => $inSet) {
      $inSet->setScale($record['scale'][$i]);
    }
    
    $this->corrections = $record['corrections'] ?? [];
    $this->analyzeErrors = $record['analyzeErrors'] ?? [];
    $this->harvestError = $record['harvestError'] ?? '';
    $this->valid = $mtime ? date('Y-m-d', $mtime) : '';
  }
  
  function scale(): ?string { return $this->scale; }
  /** @return array<string, string> */
  function spatial(): array { return $this->spatial; }
  
  /** @return array<int, array<string, string>> $corrections */
  function corrections(): array { return $this->corrections; }
  
  /** @param array<string, mixed> $record */
  private function postProcessTitle(array $record): void { // complète __construct()
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
        $this->spatial = ['SW'=> trim($matches[4]), 'NE'=> trim($matches[6])];
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
  
  /** @return array<string, mixed> */
  static function allAsArray(): array { // retourne l'ensemble de la classe comme array 
    if (!self::$gans)
      GanStatic::loadFromPser();
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
  
  /** @return array<string, mixed> */
  function asArray(): array { // retourne un objet comme array 
    return
      ($this->groupTitle ? ['groupTitle'=> $this->groupTitle] : [])
    + ($this->title ? ['title'=> $this->title] : [])
    + ($this->scale ? ['scale'=> $this->scale] : [])
    + ($this->spatial ? ['spatial'=> $this->spatial] : [])
    + ($this->inSets ?
        ['inSets'=> array_map(function (GanInSet $inset): array { return $inset->asArray(); }, $this->inSets)] : [])
    + ($this->edition ? ['edition'=> $this->edition] : [])
    + ($this->corrections ? ['corrections'=> $this->corrections] : [])
    + ($this->analyzeErrors ? ['analyzeErrors'=> $this->analyzeErrors] : [])
    + ($this->valid ? ['valid'=> $this->valid] : [])
    + ($this->harvestError ? ['harvestError'=> $this->harvestError] : [])
    ;
  }

  function version(): string { // calcule la version sous la forme {anneeEdition}c{noCorrection}
    // COORECTIONS DU GAN
    if (isset(self::CORRECTIONS[$this->mapnum][$this->edition]))
      $this->edition = self::CORRECTIONS[$this->mapnum][$this->edition];

    if (!$this->edition && !$this->corrections)
      return 'undefined';
    if (preg_match('!^Edition n°\d+ - (\d+)$!', $this->edition, $matches)) {
      $anneeEdition = $matches[1];
      // "anneeEdition=$anneeEdition<br>\n";
    }
    elseif (preg_match('!^Publication (\d+)$!', $this->edition, $matches)) {
      $anneeEdition = $matches[1];
      //echo "anneeEdition=$anneeEdition<br>\n";
    }
    else {
      throw new Exception("No match pour version edition='$this->edition'");
    }
    if (!$this->corrections) {
      return $anneeEdition.'c0';
    }
    else {
      $lastCorrection = $this->corrections[count($this->corrections)-1];
      $num = $lastCorrection['num'];
      return $anneeEdition.'c'.$num;
    }
    /*echo "mapnum=$this->mapnum, edition=$this->edition<br>\n";
    echo "<pre>corrections = "; print_r($this->corrections); echo "</pre>";*/
  }

  // retourne le cartouche correspondant au qgbox
  function inSet(GBox $qgbox): GanInSet {
    //echo "<pre>"; print_r($this);
    $dmin = INF;
    $pmin = -1;
    foreach ($this->inSets as $pnum => $part) {
      //try {
        $pgbox = GBox::fromGeoDMd([
          'SW'=> str_replace('—','-', $part->spatial()['SW']),
          'NE'=> str_replace('—','-', $part->spatial()['NE'])]);
      /*}
      catch (SExcept $e) {
        echo "<pre>SExcept::message: ",$e->getMessage(),"\n";
        //print_r($this);
        return null;
      }*/
      $d = $qgbox->distance($pgbox);
      //echo "pgbox=$pgbox, dist=$d\n";
      if ($d < $dmin) {
        $dmin = $d;
        $pmin = $pnum;
      }
    }
    if ($pmin == -1)
      throw new SExcept("Aucun Part");
    return $this->inSets[$pmin];
  }
};

class GanStatic {
  const GAN_DIR = __DIR__.'/gan';
  const PATH = __DIR__.'/gans.'; // chemin des fichiers stockant la synthèse en pser ou en yaml, lui ajouter l'extension
  const PATH_PSER = self::PATH.'pser'; // chemin du fichier stockant le catalogue en pser
  const PATH_YAML = self::PATH.'yaml'; // chemin du fichier stockant le catalogue en  Yaml
  
  static function week(string $modified): string { // transforme une date en semaine sur 4 caractères comme utilisé par le GAN 
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
      foreach (new DirectoryIterator(self::GAN_DIR) as $filename) {
        if (!in_array($filename, ['.','..']))
          unlink("$gandir/$filename");
      }
    }
    $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
    //print_r($errors);
    foreach (Portfolio::$all as $mapnum => $map) {
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
            echo "Lecture $url ok\n";
          }
        }
        elseif (0) { // @phpstan-ignore-line // déverminage 
          echo "le fichier $gandir/$mapnum-$ganWeek.html existe || errors[$mapnum-$ganWeek]\n";
        }
      }
    }
  }
  
  /**
   * analyzeHtml() - analyse du Html du GAN
   *
   * analyse du html du Gan notamment pour identifier les corrections et l'édition d'une carte
   * fonction complètement réécrite / V1
   * retourne un array avec les champs title, edition et corrections + analyzeErrors en cas d'erreur d'analyse
   * J'ai essayé de minimiser la dépendance au code Html !
   *
   * @return array<string, mixed>
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
  
  // pour mise au point effectue l'analyse du GAN pour une carte
  static function analyzeHtmlOfMap(string $mapnum): void {
    $map = Portfolio::$all[$mapnum];
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
  
  /*PhpDoc: methods
  name: build
  title: "static function build(): void - construit la synhèse des GAN de la moisson existante"
  */
  static function build(): void {
    $minvalid = null;
    $maxvalid = null;

    // Ce code permet de détecter les fichiers Html manquants nécessitant une moisson
    $gandir = self::GAN_DIR;
    $errors = file_exists("$gandir/errors.yaml") ? Yaml::parsefile("$gandir/errors.yaml") : [];
    foreach (Portfolio::$all as $mapnum => $map) {
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
          Gan::$gans[$mapnum] = new Gan($mapnum, self::analyzeHtml($html), /*$map,*/ $mtime);
        }
      }
    }
    Gan::$hvalid = date('Y-m-d', $minvalid).'/'.date('Y-m-d', $maxvalid);

    $errors = file_exists(self::GAN_DIR.'/errors.yaml') ? Yaml::parsefile(self::GAN_DIR.'/errors.yaml') : [];
    //print_r($errors);
    foreach ($errors as $id => $errorMessage) {
      $mapid = substr($id, 0, 4);
      Gan::$gans[$mapid] = new Gan($mapid, ['harvestError'=> $errorMessage], /*$mapa,*/ null);
    }
  }
 
  static function storeAsPser(): void { // enregistre le catalogue comme pser 
    file_put_contents(self::PATH_PSER, serialize([
      'valid'=> Gan::$hvalid,
      'gans'=> Gan::$gans,
    ]));
  }
  
  static function loadFromPser(): void { // charge les données depuis le pser 
    $contents = unserialize(file_get_contents(self::PATH_PSER));
    Gan::$hvalid = $contents['valid'];
    Gan::$gans = $contents['gans'];
  }
  
  static function item(string $mapnum): ?Gan { return Gan::$gans[$mapnum] ?? null; }
};
