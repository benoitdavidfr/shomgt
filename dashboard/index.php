<?php
// dashboard/index.php - 24/6/2022

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/mapversion.inc.php';
require_once __DIR__.'/gan.inc.php';
require_once __DIR__.'/../lib/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE HTML><html><head><title>dashboard</title></head><body>\n";

if (!isset($_GET['a'])) {
  echo "<h2>Menu:</h2><ul>\n";
  //echo "<li><a href='?a=listOfInterest'>listOfInterest</li>\n";
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
    return addUndescoreForThousand(intval(floor($scaleden/1000)))
      .'_'.sprintf('%03d', $scaleden - 1000 * floor($scaleden/1000));
}

class Zee { // liste des polygones de la ZEE chacun associé à une zoneid
  protected string $id;
  protected Polygon $polygon;
  /** @var array<int, Zee> $all */
  static array $all=[]; // [ Zee ]
  
  /** @param TGeoJsonFeature $ft */
  static function add(array $ft): void { // ajoute un feature
    if ($ft['geometry']['type'] == 'Polygon')
      self::$all[] = new self($ft['properties']['zoneid'], new Polygon($ft['geometry']['coordinates']));
    else { // MultiPolygon
      foreach ($ft['geometry']['coordinates'] as $pol) {
        self::$all[] = new self($ft['properties']['zoneid'], new Polygon($pol));
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
  
  /** @return array<int, string> */
  static function inters(MultiPolygon $mpol): array { // retourne la liste des zoneid des polygones intersectant la géométrie
    if (!self::$all)
      throw new Exception("Erreur, Zee doit être initialisé\n");  
    $result = [];
    foreach (self::$all as $zee) {
      if ($mpol->inters($zee->polygon))
        $result[$zee->id] = 1;
    }
    ksort($result);
    return array_keys($result);
  }
};

/**
 * MapFromWfs - liste des cartes définies dans le WFS 
 *
 * Chaque carte est définie par ses propriétés et sa géométrie
 * La liste des cartes est fournie dans la variable $fts indexée sur la propriété carte_id
 */
class MapFromWfs {
  /** @var array<string, string> $prop */
  protected array $prop;
  protected MultiPolygon $mpol;
  
  /** @var array<string, MapFromWfs> $fts */
  static array $fts; // liste des MapFromWfs indexés sur carte_id
  
  static function init(): void {
    $fc = json_decode(file_get_contents(__DIR__.'/../shomft/gt.json'), true);
    foreach ($fc['features'] as $gmap) {
      self::$fts[$gmap['properties']['carte_id']] = new self($gmap);
    }
  }
  
  /** @param TGeoJsonFeature $gmap */
  function __construct(array $gmap) {
    $this->prop = $gmap['properties'];
    $this->mpol = MultiPolygon::fromGeoArray($gmap['geometry']);
  }
  
  static function show(): void { // affiche le statut de chaque carte Wfs
    foreach (self::$fts as $gmap) {
      //print_r($gmap);
      if ($gmap->prop['scale'] > 6e6)
        echo '"',$gmap->prop['name'],"\" est à petite échelle<br>\n";
      elseif ($mapsFr = Zee::inters($gmap->mpol))
        echo '"',$gmap->prop['name'],"\" intersecte ",implode(',',$mapsFr),"<br>\n";
      else
        echo '"',$gmap->prop['name'],"\" N'intersecte PAS la ZEE<br>\n";
    }
  }
  
  /** @return array<int, string> */
  static function interest(): array { // liste des cartes d'intérêt
    $list = [];
    foreach (self::$fts as $id => $gmap) {
      if ((isset($gmap->prop['scale']) && ($gmap->prop['scale'] > 6e6)) || Zee::inters($gmap->mpol))
        $list[] = $gmap->prop['carte_id'];
    }
    return $list;
  }
};

class Portfolio { // Portefeuille des cartes exposées sur ShomGt issu de maps.json
  /** @var array<string, string|array<string, int|string>> $all */
  static array $all; // contenu du fichier maps.json
  
  static function init(): void {
    if (!(($INCOMING_PATH = getenv('SHOMGT3_DASHBOARD_INCOMING_PATH')) || ($INCOMING_PATH = getenv('SHOMGT3_INCOMING_PATH'))))
      throw new Exception("Variables d'env. SHOMGT3_DASHBOARD_INCOMING_PATH et SHOMGT3_INCOMING_PATH non définies");
    self::$all = MapVersion::allAsArray($INCOMING_PATH);
  }
  
  static function isActive(string $mapnum): bool {
    return isset(self::$all[$mapnum]) && (self::$all[$mapnum]['status']=='ok');
  }
  
  /** @return array<string, string|array<string, int|string>> */
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

class DbMapCat { // chargement d'un extrait de mapcat.yaml
  protected string $title;
  /** @var array<int, string> $mapsFrance */
  protected array $mapsFrance;
  
  /** @var array<string, DbMapCat> $all */
  static array $all; // [mapNum => DbMapCat]
  
  static function init(): void {
    $mapcat = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml');
    foreach ($mapcat['maps'] as $mapid => $map) {
      $mapNum = substr($mapid, 2);
      self::$all[$mapNum] = new self($map);
    }
  }
  
  static function item(string $mapNum): ?self { return self::$all[$mapNum] ?? null; }
  
  function title(): string { return $this->title; }
  /** @return array<int, string> */
  function mapsFrance(): array { return $this->mapsFrance; }
  
  /** @param array<string, mixed> $map */
  function __construct(array $map) {
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

class Perempt { // croisement entre le portefeuille et les GANs en vue d'afficher le tableau des degrés de péremption
  protected string $mapNum;
  protected string $pfVersion; // info du portefeuille 
  protected string $pfModified; // info du portefeuille 
  protected string $ganVersion=''; // info du GAN 
  /** @var array<int, array<string, string>> $ganCorrections */
  protected array $ganCorrections=[]; // info du GAN
  protected float $degree; // degré de péremption
  
  /** @var array<string, Perempt> $all */
  static array $all; // [mapNum => Perempt]

  static function init(): void { // construction à partir du portefeuille 
    foreach (Portfolio::$all as $mapnum => $map) {
      if ($map['status'] <> 'ok') continue;
      self::$all[$mapnum] = new self($mapnum, $map);
    }
  }
  
  /** @param array<string, string> $map */
  function __construct(string $mapNum, array $map) {
    //echo "<pre>"; print_r($map);
    $this->mapNum = $mapNum;
    $this->pfVersion = $map['lastVersion'];
    $this->pfModified = substr($map['modified'], 0, 10);
  }
  
  function setGan(Gan $gan): void { // Mise à jour de perempt à partir du GAN
    $this->ganVersion = $gan->version();
    $this->ganCorrections = $gan->corrections();
    $this->degree = $this->degree();
  }

  function title(): string { return DbMapCat::item($this->mapNum)->title(); }
  /** @return array<int, string> */
  function mapsFrance(): array { return DbMapCat::item($this->mapNum)->mapsFrance(); }
    
  function degree(): float { // calcul du degré de péremption 
    if (($this->pfVersion == 'undefined') && ($this->ganVersion == 'undefined'))
      return -1;
    $spc = DbMapCat::item($this->mapNum)->spatialCoeff();
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

  static function showAll(): void { // Affichage du tableau des degrés de péremption
    usort(self::$all,
      function(Perempt $a, Perempt $b) {
        if ($a->degree() < $b->degree()) return 1;
        elseif ($a->degree() == $b->degree()) return 0;
        else return -1;
      });
    //echo "<pre>Perempt="; print_r(Perempt::$all);
    echo "<h2>Degrés de péremption des cartes du portefeuille</h2>\n";
    echo "<p>On appelle <b>portefeuille</b>, l'ensemble des dernières versions des cartes non obsolètes",
      " exposées par le serveur de cartes 7z.<br>",
      "L'objectif du grand tableau ci-dessous est de fournir le degré de péremption des cartes du portefeuille",
      " mesuré par rapport aux <a href='https://gan.shom.fr/' target='_blank'>GAN (Groupes d'Avis aux Navigateurs)</a>",
      " qui indiquent chaque semaine notamment les corrections apportées aux cartes.</p>\n";
    echo "<table border=1><tr><td><b>Validité du GAN</b></td><td>",Gan::$hvalid,"</td></tr></table>\n";
    echo "<h3>Signification des colonnes du tableau ci-dessous</h3>\n";
    echo "<table border=1>\n";
    echo "<tr><td><b>#</b></td><td>numéro de la carte</td></tr>\n";
    echo "<tr><td><b>titre</b></td><td>titre de la carte</td></tr>\n";
    echo "<tr><td><b>zone géo.</b></td><td>zone géographique dans laquelle se situe la carte</td></tr>\n";
    echo "<tr><td><b>modif.</b></td><td>date de mise à jour de la dernière version de la carte dans le portefeuille,",
      " approximée par la date de modification des métadonnées ISO de cette version</td></tr>\n";
    echo "<tr><td><b>v. Pf</b></td><td>version de la carte dans le portefeuille, identifiée par l'année d'édition de la carte",
      " suivie du caractère 'c' et du numéro de la dernière correction apportée à la carte</td></tr>\n";
    echo "<tr><td><b>v. GAN</b></td><td>version de la carte trouvée dans les GANs à la date de validité ci-dessus ;",
      " le lien permet de consulter les GANs du Shom pour cette carte</td></tr>\n";
    echo "<tr><td><b>degré</b></td><td>degré de péremption de la carte exprimant l'écart entre les 2 versions ci-dessus ;",
      " la table ci-dessous est triée par degré décroissant ; ",
      " l'objectif de gestion est d'éviter les degrés supérieurs ou égaux à 5</td></tr>\n";
    echo "<tr><td><b>corrections</b></td><td>liste des corrections reconnues dans les GANs, avec en première colonne ",
      " le numéro de la correction et, dans la seconde colonne, avant le tiret, le no de semaine de la correction",
      " (année sur 2 caractères et no de semmaine sur 2 caractères)",
      " et après le tiret le numéro d'avis dans le GAN de cette semaine.</td></tr>\n";
    echo "</table></p>\n";
    echo "<p>Attention, certains écarts de version sont dus à des informations incomplètes ou incorrectes",
      " sur les sites du Shom</p>\n";
    echo "<table border=1>",
         "<th>#</th><th>titre</th><th>zone géo.</th><th>modif.</th><th>v. Pf</th>",
         "<th>v. GAN</th><th>degré</th><th>corrections</th>\n";
    foreach (Perempt::$all as $p) {
      $p->showAsRow();
    }
    echo "</table>\n";
  }
  
  function showAsRow(): void { // Affichage d'une ligne du tableau des degrés de péremption
    echo "<tr><td>$this->mapNum</td>";
    echo "<td>",$this->title(),"</td>";
    echo "<td>",implode(', ', $this->mapsFrance()),"</td>";
    echo "<td>$this->pfModified</td>";
    echo "<td>$this->pfVersion</td>";
    $ganWeek = GanStatic::week($this->pfModified);
    $href = "https://gan.shom.fr/diffusion/qr/gan/$this->mapNum/$ganWeek";
    echo "<td><a href='$href' target='_blank'>$this->ganVersion</a></td>";
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

if ($_GET['a'] == 'perempt') { // appel du croisement 
  Portfolio::init(); // initialisation à partir du portefeuille
  DbMapCat::init(); // chargement du fichier mapcat.yaml
  GanStatic::loadFromPser(); // chargement de la synthèse des GANs
  Perempt::init(); // construction à partir du portefeuille
  // Mise à jour de perempt à partir du GAN
  foreach (Perempt::$all as $mapNum => $perempt) {
    if (!($gan = GanStatic::item($mapNum)))
      echo "Erreur, Gan absent pour carte $mapNum\n";
    else
      $perempt->setGan($gan);
  }
  Perempt::showAll(); // Affichage du tableau des degrés de péremption
}
