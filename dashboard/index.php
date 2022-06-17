<?php
// dashboard/index.php

require_once __DIR__.'/lib/gegeom.inc.php';
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE HTML><html><head><title>dashboard</title></head><body>\n";
if (!isset($_GET['a'])) {
  echo "<h2>Menu:</h2><ul>\n";
  echo "<li><a href='?a=listOfInterest'>listOfInterest</li>\n";
  echo "<li><a href='?a=newObsoleteMaps'>Nouvelles cartes et cartes obsolètes dans le portefeuille par rapport au WFS</li>\n";
  echo "<li><a href='?a=perempt'>Degrés de péremption des cartes du portefeuille</li>\n";
  echo "</ul>\n";
  die();
}

// pour un entier fournit une représentation avec un '_' comme séparateur des milliers 
function addUndescoreForThousand(?int $scaleden): string {
  if ($scaleden === null) return 'undef';
  if ($scaleden < 0)
    return '-'.addUndescoreForThousand(-$scaleden);
  elseif ($scaleden < 1000)
    return sprintf('%d', $scaleden);
  else
    return addUndescoreForThousand(floor($scaleden/1000)).'_'.sprintf('%03d', $scaleden - 1000 * floor($scaleden/1000));
}

class Zee { // liste de polygones de la ZEE chacun associé à une zoneid
  protected string $id;
  protected Polygon $polygon;
  static array $all=[]; // [ Zee ]
  
  static function add(array $ft): void { // ajoute un feature
    if ($ft['geometry']['type'] == 'Polygon')
      self::$all[] = new self($ft['properties']['zoneid'], Geometry::fromGeoJSON($ft['geometry']));
    else { // MultiPolygon
      foreach ($ft['geometry']['coordinates'] as $pol) {
        self::$all[] = new self($ft['properties']['zoneid'], Geometry::fromGeoJSON(['type'=>'Polygon', 'coordinates'=>$pol]));
       }
    }
  }
  
  static function init(): void { // initialise Zee
    $frzee = json_decode(file_get_contents(__DIR__.'/../shomft/frzee.geojson'), true);
    foreach ($frzee['features'] as $ftzee) {
      Zee::add($ftzee);
    }
  }
  
  function __construct(string $id, Polygon $polygon) {
    $this->id = $id;
    $this->polygon = $polygon;
  }
  
  static function inters(Geometry $geom): array { // retourne la liste des zoneid des polygones intersectant la géométrie
    if (!self::$all)
      die("Erreur, Zee doit être initialisé\n");  
    $result = [];
    foreach (self::$all as $zee) {
      if ($geom->inters($zee->polygon))
        $result[$zee->id] = 1;
    }
    ksort($result);
    return array_keys($result);
  }
};

class MapFromWfs {
  static array $fts; // liste des features indexés sur carte_id
  
  static function init(): void {
    $fc = json_decode(file_get_contents(__DIR__.'/../shomft/gt.json'), true);
    foreach ($fc['features'] as $gmap)
      self::$fts[$gmap['properties']['carte_id']] = $gmap;
  }
  
  static function show(): void { // affiche le statut de chaque carte Wfs
    foreach (self::$fts as $gmap) {
      //print_r($gmap);
      if ($gmap['properties']['scale'] > 6e6)
        echo '"',$gmap['properties']['name'],"\" est à petite échelle<br>\n";
      elseif ($mapsFr = Zee::inters(Geometry::fromGeoJSON($gmap['geometry'])))
        echo '"',$gmap['properties']['name'],"\" intersecte ",implode(',',$mapsFr),"<br>\n";
      else
        echo '"',$gmap['properties']['name'],"\" N'intersecte PAS la ZEE<br>\n";
    }
  }
  
  static function interest(): array { // liste des cartes d'intérêt
    $list = [];
    foreach (self::$fts as $gmap) {
      if ((isset($gmap['properties']['scale']) && ($gmap['properties']['scale'] > 6e6))
          || Zee::inters(Geometry::fromGeoJSON($gmap['geometry'])))
        $list[] = $gmap['properties']['carte_id'];
    }
    return $list;
  }
};

class Portfolio { // Portefeuille des cartes exposées sur ShomGt
  static array $all; // contenu du fichier maps.json
  
  static function init(): void {
    $INCOMING_PATH = getenv('SHOMGT3_INCOMING_PATH');
    self::$all = json_decode(file_get_contents("$INCOMING_PATH/../maps.json"), true);
  }
  
  static function isActive(string $mapnum): bool {
    return isset(self::$all[$mapnum]) && (self::$all[$mapnum]['status']=='ok');
  }
  
  static function actives(): array { // sélection des cartes actives 
    $actives = [];
    foreach (self::$all as $mapnum => $map) {
      if ($map['status']=='ok')
        $actives[$mapnum] = $map;
    }
    return $actives;
  }
};

if ($_GET['a'] == 'listOfInterest') { // vérification de la liste des cartes d'intérêt 
  MapFromWfs::init();
  Zee::init();
  $listOfInterest = MapFromWfs::interest();
  echo "<pre>listOfInterest="; print_r($listOfInterest); echo "</pre>\n";
  MapFromWfs::show();
  die();
}

if ($_GET['a'] == 'newObsoleteMaps') { // détecte de nouvelles cartes à ajouter au portefeuille et les cartes obsolètes 
  //echo "<pre>";
  Zee::init();
  MapFromWfs::init();
  Portfolio::init();
  //MapFromWfs::show();
  $listOfInterest = MapFromWfs::interest();
  //echo count($list)," / ",count(MapFromWfs::$fc['features']),"\n";
  $newMaps = [];
  foreach ($listOfInterest as $mapid) {
    if (!Portfolio::isActive($mapid)) {
      $newMaps[] = $mapid;
      //echo "$mapid dans WFS et pas dans sgserver<br>\n";
      //echo "<pre>"; print_r(MapFromWfs::$fts[$mapid]['properties']); echo "</pre>\n"; 
    }
  }
  if (!$newMaps)
    echo "<h2>Toutes les cartes d'intérêt du flux WFS sont dans le portefeuille</h2>>\n";
  else {
    echo "<h2>Cartes d'intérêt présentes dans le flux WFS et absentes du portefeuille</h2>\n";
    foreach ($newMaps as $mapid) {
      $map = MapFromWfs::$fts[$mapid]['properties'];
      echo "- $map[name] (1/",addUndescoreForThousand($map['scale'] ?? null),")<br>\n";
    }
  }
  
  $obsoletes = [];
  foreach (Portfolio::actives() as $mapid => $map) {
    if (!in_array($mapid, $listOfInterest))
      $obsoletes[] = $mapid;
  }
  if (!$obsoletes)
    echo "<h2>Toutes les cartes du portefeuille sont présentes dans le flux WFS</h2>\n";
  else {
    echo "<h2>Cartes du portefeuille absentes du flux WFS</h2>\n";
    foreach (Portfolio::actives() as $mapid => $map) {
      if (!in_array($mapid, $listOfInterest))
        echo "- $mapid<br>\n";
    }
  }
}

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

class Gan {
  const GAN_DIR = __DIR__.'/gan';
  const PATH = __DIR__.'/gans.'; // chemin des fichiers stockant la synthèse en pser ou en yaml, lui ajouter l'extension
  const PATH_PSER = self::PATH.'pser'; // chemin du fichier stockant le catalogue en pser
  const PATH_YAML = self::PATH.'yaml'; // chemin du fichier stockant le catalogue en  Yaml
  // le champ édition du GAN comporte des erreurs qui perturbent le TdB, ci-dessous corrections
  // Il se lit {{num}=> [{edACorriger}=> {edCorrigée}]}
  // Liste d'écarts transmise le 15/6/2022 au Shom
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
  static array $gans=[]; // dictionnaire [$mapnum => Gan]
  
  protected string $mapnum;
  protected ?string $groupTitle; // sur-titre optionnel identifiant un ensemble de cartes
  protected string $title; // titre
  protected ?string $edition=''; // edition
  protected array $spatial; // sous la forme ['SW'=> sw, 'NE'=> ne]
  protected array $inSets; // cartouches
  public readOnly array $corrections; // liste des corrections
  protected array $analyzeErrors; // erreurs éventuelles d'analyse du résultat du moissonnage
  protected string $valid; // date de moissonnage du GAN en format ISO
  protected string $harvestError; // erreur éventuelle du moissonnage

  static function init(): void {
    $contents = unserialize(file_get_contents(self::PATH_PSER));
    self::$hvalid = $contents['valid'];
    self::$gans = $contents['gans'];
  }
  
  static function item(string $mapnum): ?self { return self::$gans[$mapnum] ?? null; }
  
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
    echo "mapnum=$this->mapnum, edition=$this->edition<br>\n";
    echo "<pre>corrections = "; print_r($this->corrections); echo "</pre>";
  }
};

class Mapcat {
  protected string $title;
  protected array $mapsFrance;
  
  static array $all; // [mapNum => MapCat]
  
  static function init() {
    $mapcat = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml');
    foreach ($mapcat['maps'] as $mapid => $map) {
      $mapNum = substr($mapid, 2);
      self::$all[$mapNum] = new self($mapNum, $map);
    }
  }
  
  static function item(string $mapNum): ?self { return self::$all[$mapNum] ?? null; }
  
  function title(): string { return $this->title; }
  function mapsFrance(): array { return $this->mapsFrance; }
  
  function __construct(string $mapNum, array $map) {
    $this->title = $map['title'];
    $this->mapsFrance = $map['mapsFrance'];
  }
  
  function spatialCoeff(): int {
    if (in_array('FR', $this->mapsFrance)) return 1;
    if (in_array('FX-Med', $this->mapsFrance)) return 1;
    if (in_array('FX-Atl', $this->mapsFrance)) return 1;
    if (in_array('FX-MMN', $this->mapsFrance)) return 1;
    if (in_array('GP', $this->mapsFrance)) return 2;
    if (in_array('GF', $this->mapsFrance)) return 2;
    if (in_array('MQ', $this->mapsFrance)) return 2;
    if (in_array('YT', $this->mapsFrance)) return 2;
    if (in_array('RE', $this->mapsFrance)) return 2;
    if (in_array('PM', $this->mapsFrance)) return 2;
    if (in_array('TF', $this->mapsFrance)) return 2;
    return 4;
  }
};

class Perempt {
  protected string $mapNum;
  protected string $pfVersion; // info du portefeuille 
  protected string $pfModified; // info du portefeuille 
  protected string $ganVersion=''; // info du GAN 
  protected array $ganCorrections=[]; // info du GAN
  protected float $degree; // degré de péremption
  static array $all; // [mapNum => Perempt]

  static function init(): void {
    foreach (Portfolio::$all as $mapnum => $map) {
      if ($map['status'] <> 'ok') continue;
      self::$all[$mapnum] = new self($mapnum, $map);
    }
  }
  
  function __construct(string $mapNum, array $map) {
    //echo "<pre>"; print_r($map);
    $this->mapNum = $mapNum;
    $this->pfVersion = $map['lastVersion'];
    $this->pfModified = substr($map['modified'], 0, 10);
  }
  
  function setGan(Gan $gan): void {
    $this->ganVersion = $gan->version();
    $this->ganCorrections = $gan->corrections;
    $this->degree = $this->degree();
  }

  function title(): string { return MapCat::item($this->mapNum)->title(); }
  function mapsFrance(): array { return MapCat::item($this->mapNum)->mapsFrance(); }
    
  function degree(): float {
    if (($this->pfVersion == 'undefined') && ($this->ganVersion == 'undefined'))
      return -1;
    $spc = MapCat::item($this->mapNum)->spatialCoeff();
    if (preg_match('!^(\d+)c(\d+)$!', $this->pfVersion, $matches)) {
      $pfYear = $matches[1];
      $pfNCor = $matches[2];
    }
    else
      return 100 / $spc;
    if (preg_match('!^(\d+)c(\d+)$!', $this->ganVersion, $matches)) {
      $ganYear = $matches[1];
      $ganNCor = $matches[2];
    }
    else
      return 100 / $spc;
    if ($pfYear == $ganYear) {
      $d = $ganNCor - $pfNCor;
      if ($d < 0) $d = 0;
      return $d / $spc;
    }
    else
      return 100 / $spc;
  }

  static function showAll(): void {
    usort(self::$all,
      function(Perempt $a, Perempt $b) {
        if ($a->degree() < $b->degree()) return 1;
        elseif ($a->degree() == $b->degree()) return 0;
        else return -1;
      });
    //echo "<pre>Perempt="; print_r(Perempt::$all);
    echo "<h2>Degrés de péremption des cartes du portefeuille</h2>\n";
    echo "<table border=1><tr><td><b>Validité du GAN</b></td><td>",Gan::$hvalid,"</td></tr></table>\n";
    echo "<table border=1>",
         "<th>num</th><th>title</th><th>maps</th><th>pfModified</th><th>pfVersion</th>",
         "<th>ganVersion</th><th>degré</th><th>corrections</th>\n";
    foreach (Perempt::$all as $p) {
      $p->showAsRow();
    }
    echo "</table>\n";
    
  }
  
  function showAsRow(): void {
    echo "<tr><td>$this->mapNum</td>";
    echo "<td>",$this->title(),"</td>";
    echo "<td>",implode(', ', $this->mapsFrance()),"</td>";
    echo "<td>$this->pfModified</td>";
    echo "<td>$this->pfVersion</td>";
    echo "<td>$this->ganVersion</td>";
    printf("<td>%.2f</td>", $this->degree);
    echo "<td><table border=1>";
    foreach ($this->ganCorrections as $c) {
      echo "<tr><td>$c[num]</td><td>$c[semaineAvis]</td></tr>";
    }
    echo "</table></td>\n";
    //echo "<td><pre>"; print_r($this); echo "</pre></td>";
    echo "</tr>\n";
  }
};

if ($_GET['a'] == 'perempt') {
  Portfolio::init();
  MapCat::init();
  Gan::init();
  //echo "<pre>Gan="; print_r(Gan::$gans);
  Perempt::init();
  // Mise à jour de perempt à partir du GAN
  foreach (Perempt::$all as $mapNum => $perempt) {
    if (!($gan = Gan::item($mapNum)))
      echo "Erreur, Gan absent pour carte $mapNum\n";
    else
      $perempt->setGan($gan);
  }
  Perempt::showAll();
}
