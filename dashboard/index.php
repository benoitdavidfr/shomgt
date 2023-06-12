<?php
/**
 * dashboard/index.php - 12/6/2023
 *
 * Tableau de bord de mise à jour des cartes.
 * Affiche:
 *  1) les cartes manquantes ou en excès dans le portefeuille par rapport au flux WFS du Shom
 *  2) le degré de péremption des différentes cartes du portefeuille
 *
 * Une carte du flux WFS est d'intérêt ssi
 *  - soit elle est à petite échelle (< 1/6M)
 *  - soit elle intersecte la ZEE
 * Les cartes d'intérêt qui n'appartient pas au portefeuille sont signalées pour vérification.
 *
 * 12/6/2023:
 *  - réécriture de l'interface avec le portefeuille à la suite de sa réorganisation
 * 23/4/2023:
 *  - ajout aux cartes manquantes la ZEE intersectée pour facilier leur localisation
 * 21/4/2023:
 *  - prise en compte évol de ../shomft sur la définition du périmètre de gt.json
 *  - modif fonction spatialCoeff() pour mettre à niveau les COM à la suite mail D. Bon
 * 6/1/2023: modif fonction spatialCoeff()
 * 22/8/2022: correction bug
 */
/*PhpDoc:
title: dashboard/index.php - Tableau de bord de mise à jour des cartes - 23/4/2023
*/

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/gan.inc.php';
require_once __DIR__.'/portfolio.inc.php';
require_once __DIR__.'/../shomgt/lib/gegeom.inc.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE HTML><html><head><title>dashboard</title></head><body>\n";

if (!isset($_GET['a'])) { // menu 
  echo "<h2>Menu:</h2><ul>\n";
  echo "<li><a href='?a=newObsoleteMaps'>Nouvelles cartes et cartes obsolètes dans le portefeuille par rapport au WFS</li>\n";
  echo "<li><a href='?a=perempt'>Degrés de péremption des cartes du portefeuille</li>\n";
  echo "<li><a href='?a=listOfInterest'>liste des cartes d'intérêt issue du serveur WFS du Shom</li>\n";
  echo "<li><a href='?a=listWfs'>liste des cartes du serveur WFS du Shom avec degré d'intérêt</li>\n";
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
  public readonly array $prop; // properties 
  public readonly MultiPolygon $mpol; // géométrie comme MultiPolygon
  
  /** @var array<string, MapFromWfs> $fts */
  static array $fts; // liste des MapFromWfs indexés sur carte_id
  
  static function init(): void {
    $gt = json_decode(file_get_contents(__DIR__.'/../shomft/gt.json'), true);
    $aem = json_decode(file_get_contents(__DIR__.'/../shomft/aem.json'), true);
    foreach (array_merge($gt['features'], $aem['features']) as $gmap) {
      self::$fts[$gmap['properties']['carte_id']] = new self($gmap);
    }
  }
  
  /** @param TGeoJsonFeature $gmap */
  function __construct(array $gmap) {
    $this->prop = $gmap['properties'];
    $this->mpol = MultiPolygon::fromGeoArray($gmap['geometry']);
  }
  
  static function show(): void { // affiche le statut de chaque carte Wfs
    $maps = [];
    foreach (self::$fts as $gmap) {
      //echo '<pre>gmap = '; print_r($gmap); echo "</pre>\n";
      $array = ['title'=> $gmap->prop['name']];
      
      if (!isset($gmap->prop['scale']))
        $array['status'] = 'sans échelle';
      elseif ($gmap->prop['scale'] > 6e6)
        $array['status'] = 'à petite échelle (< 1/6M)';
      elseif ($mapsFr = Zee::inters($gmap->mpol))
        $array['status'] = 'intersecte '.implode(',',$mapsFr);
      else
        $array['status'] = "Hors ZEE française";
      $maps[$gmap->prop['carte_id']] = $array;
    }
    ksort($maps);
    echo '<pre>',Yaml::dump(array_values($maps));
  }
  
  /** @return array<string, array<int, string>> */
  static function interest(): array { // liste des cartes d'intérêt sous la forme [carte_id => ZeeIds]
    $list = [];
    foreach (self::$fts as $id => $gmap) {
      if (isset($gmap->prop['scale']) && ($gmap->prop['scale'] > 6e6))
        $list[$gmap->prop['carte_id']] = ['SmallScale'];
      elseif ($zeeIds = Zee::inters($gmap->mpol))
        $list[$gmap->prop['carte_id']] = $zeeIds;
    }
    return $list;
  }
};

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
};

class Perempt { // croisement entre le portefeuille et les GANs en vue d'afficher le tableau des degrés de péremption
  protected string $mapNum;
  protected string $pfVersion; // info du portefeuille 
  protected ?string $pfRevision; // info du portefeuille 
  protected string $ganVersion=''; // info du GAN 
  /** @var array<int, array<string, string>> $ganCorrections */
  protected array $ganCorrections=[]; // info du GAN
  protected float $degree; // degré de péremption déduit de la confrontation entre portefeuille et GAN
  
  /** @var array<string, Perempt> $all */
  static array $all; // [mapNum => Perempt]

  static function init(): void { // construction à partir du portefeuille 
    foreach (Portfolio::$all as $mapnum => $map) {
      self::$all[$mapnum] = new self($mapnum, $map);
    }
  }
  
  /** @param array<string, string> $map */
  function __construct(string $mapNum, array $map) {
    //echo "<pre>"; print_r($map);
    $this->mapNum = $mapNum;
    $this->pfVersion = $map['version'];
    $this->pfRevision = $map['revision'] ?? $map['creation'] ?? null;
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
    $mapcatItem = DbMapCat::item($this->mapNum);
    if (!$mapcatItem) {
      die("Erreur la carte $this->mapNum n'est pas décrite dans mapcat.yaml");
    }
    if (preg_match('!^(\d+)c(\d+)$!', $this->pfVersion, $matches)) {
      $pfYear = $matches[1];
      $pfNCor = $matches[2];
    }
    else
      return 100;
    if (preg_match('!^(\d+)c(\d+)$!', $this->ganVersion, $matches)) {
      $ganYear = $matches[1];
      $ganNCor = $matches[2];
    }
    else
      return 100;
    if ($pfYear == $ganYear) {
      $d = $ganNCor - $pfNCor;
      if ($d < 0) $d = 0;
      return $d;
    }
    else
      return 100;
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
    echo "<tr><td><b>revision</b></td><td>date de création ou de révision de la carte du portefeuille,",
      " issue des MD ISO associée à la carte</td></tr>\n";
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
         "<th>#</th><th>titre</th><th>zone géo.</th><th>revision</th><th>v. Pf</th>",
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
    echo "<td>$this->pfRevision</td>";
    echo "<td>$this->pfVersion</td>";
    $href = "https://gan.shom.fr/diffusion/qr/gan/$this->mapNum";
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

switch ($_GET['a']) {
  case 'listWfs': { // liste des cartes du serveur WFS du Shom avec degré d'intérêt
    echo "<h2>Liste des cartes du serveur WFS du Shom avec degré d'intérêt</h2>\n";
    MapFromWfs::init();
    Zee::init();
    MapFromWfs::show();
    die();
  }
  case 'listOfInterest': { // liste des cartes d'intérêt 
    MapFromWfs::init();
    Zee::init();
    $listOfInterest = MapFromWfs::interest();
    ksort($listOfInterest);
    //echo "<pre>listOfInterest="; print_r($listOfInterest); echo "</pre>\n";
    echo "<h2>Liste des cartes d'inérêt</h2><pre>\n",Yaml::dump($listOfInterest, 1),"</pre>\n";
    die();
  }
  case 'newObsoleteMaps': { // détecte de nouvelles cartes à ajouter au portefeuille et les cartes obsolètes
    //echo "<pre>";
    Zee::init();
    MapFromWfs::init();
    Portfolio::init();
    //MapFromWfs::show();
    $listOfInterest = MapFromWfs::interest();
    //echo count($list)," / ",count(MapFromWfs::$fc['features']),"\n";
    $newMaps = [];
    foreach ($listOfInterest as $mapid => $zeeIds) {
      if (!Portfolio::exists($mapid)) {
        $newMaps[$mapid] = $zeeIds;
        //echo "$mapid dans WFS et pas dans sgserver<br>\n";
        //echo "<pre>"; print_r(MapFromWfs::$fts[$mapid]['properties']); echo "</pre>\n"; 
      }
    }
    if (!$newMaps)
      echo "<h2>Toutes les cartes d'intérêt du flux WFS sont dans le portefeuille</h2>>\n";
    else {
      echo "<h2>Cartes d'intérêt présentes dans le flux WFS et absentes du portefeuille</h2>\n";
      foreach ($newMaps as $mapid => $zeeIds) {
        $map = MapFromWfs::$fts[$mapid]->prop;
        echo "- $map[name] (1/",addUndescoreForThousand($map['scale'] ?? null),") intersecte ",implode(',', $zeeIds),"<br>\n";
      }
    }
  
    $obsoletes = [];
    foreach (Portfolio::$all as $mapid => $map) {
      if (!isset($listOfInterest[$mapid]))
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
    die();
  }
  case 'perempt': { // construction puis affichage des degrés de péremption 
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
    die();
  }
  default: { die("Action $_GET[a] non définie\n"); }
}
