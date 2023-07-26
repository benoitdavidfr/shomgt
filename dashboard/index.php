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
 * 2/7/2023:
 *  - correction lecture AvailOnTheShop pour que la lecture du fichier disponible.tsv fonctionne avec des lignes vides à la fin
 * 28/6/2023:
 *  - **BUG** Attention, l'action perempt plante lorsqu'une nouvelle carte est ajoutée dans le patrimoine
 *    sans que le GAN soit moissonné sur cette carte
 * 25/6/2023:
 *  - ajout de l'affichage de la disponibilité de la carte dans la boutique
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
title: dashboard/index.php - Tableau de bord de mise à jour des cartes - 25/6/2023
*/

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/gan.inc.php';
require_once __DIR__.'/portfolio.inc.php';
require_once __DIR__.'/../lib/gegeom.inc.php';

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
      //if ($gmap['properties']['carte_id'] == '0101') continue; // Pour test du code
      self::$fts[$gmap['properties']['carte_id']] = new self($gmap);
    }
    ksort(self::$fts);
  }
  
  /** @param TGeoJsonFeature $gmap */
  function __construct(array $gmap) {
    $this->prop = $gmap['properties'];
    $this->mpol = MultiPolygon::fromGeoArray($gmap['geometry']);
  }
  
  function mawwcatUrl(): string { // construction de l'URL vers mapwcat.php bien centré et avec le bon niveau de zoom
    $center = $this->mpol->center();
    $center = "center=$center[1],$center[0]";
    
    if (isset($this->prop['scale'])) {
      $zoom = round(log(1e7 / (int)$this->prop['scale'], 2));
      if ($zoom < 3) $zoom = 3;
    }
    else {
      $zoom = 6;
    }
    return "../mapwcat.php?options=wfs&zoom=$zoom&$center";
  }
  
  function showOne(): void { // affiche une carte 
    //echo '<pre>gmap = '; print_r($gmap); echo "</pre>\n";
    $array = [
      'title'=> '{a}'.$this->prop['name'].'{/a}',
      'scale'=> isset($this->prop['scale']) ? '1:'.addUndescoreForThousand((int)$this->prop['scale']) : 'undef',
    ];
    
    if (!isset($this->prop['scale']))
      $array['status'] = 'sans échelle';
    elseif ($this->prop['scale'] > 6e6)
      $array['status'] = 'à petite échelle (< 1/6M)';
    elseif ($mapsFr = Zee::inters($this->mpol))
      $array['status'] = 'intersecte '.implode(',',$mapsFr);
    else
      $array['status'] = "Hors ZEE française";
    
    $url = $this->mawwcatUrl();
    //echo "<a href='$url'>lien zoom=$zoom</a>\n";
    echo str_replace(["-\n ",'{a}','{/a}'], ['-',"<a href='$url'>","</a>"], Yaml::dump([$array]));
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
  protected ?string $pfDate; // info du portefeuille 
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
    $this->pfDate = $map['date']['value'];
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
    $headers = [
      '#'=> "numéro de la carte",
      'titre'=> "titre de la carte",
      'zone ZEE'=> "zone de la ZEE intersectant la carte",
      'date Pf'=> "date de création ou de révision de la carte du portefeuille, issue des MD ISO associée à la carte",
      'version Pf'=> "version de la carte dans le portefeuille, identifiée par l'année d'édition de la carte"
          ." suivie du caractère 'c' et du numéro de la dernière correction apportée à la carte",
      'version GAN'=> "version de la carte trouvée dans les GANs à la date de validité ci-dessus ;"
          ." le lien permet de consulter les GANs du Shom pour cette carte",
      'degré'=> "degré de péremption de la carte exprimant l'écart entre les 2 versions ci-dessus ;"
          ." la table ci-dessous est triée par degré décroissant ; "
          ." l'objectif de gestion est d'éviter les degrés supérieurs ou égaux à 5",
      'corrections'=> "liste des corrections reconnues dans les GANs, avec en première colonne "
          ." le numéro de la correction et, dans la seconde colonne, avant le tiret, le no de semaine de la correction"
          ." (année sur 2 caractères et no de semmaine sur 2 caractères)"
          ." et après le tiret le numéro d'avis dans le GAN de cette semaine.",
    ];
    foreach ($headers as $name => $label)
        echo "<tr><td><b>$name</b></td><td>$label</td></tr>\n";
    if (AvailOnTheShop::exists())
      echo "<tr><td><b>boutique</b></td><td>disponibilité sur la boutique du Shom avec info de mise à jour</td></tr>\n";
    echo "</table></p>\n";
    echo "<p>Attention, certains écarts de version sont dus à des informations incomplètes ou incorrectes",
      " sur les sites du Shom</p>\n";
    echo "<table border=1><th>",implode('</th><th>', array_keys($headers)),"</th>\n";
    if (AvailOnTheShop::exists())
      echo "<th>boutique</th>\n";
    foreach (Perempt::$all as $p) {
      $p->showAsRow();
    }
    echo "</table>\n";
  }
  
  function showAsRow(): void { // Affichage d'une ligne du tableau des degrés de péremption
    echo "<tr><td>$this->mapNum</td>";
    echo "<td>",$this->title(),"</td>";
    echo "<td>",implode(', ', $this->mapsFrance()),"</td>";
    echo "<td>$this->pfDate</td>";
    echo "<td>$this->pfVersion</td>";
    $href = "https://gan.shom.fr/diffusion/qr/gan/$this->mapNum";
    echo "<td><a href='$href' target='_blank'>$this->ganVersion</a></td>";
    printf("<td>%.2f</td>", $this->degree);
    echo "<td><table border=1>";
    foreach ($this->ganCorrections as $c) {
      echo "<tr><td>$c[num]</td><td>$c[semaineAvis]</td></tr>";
    }
    echo "</table></td>\n";
    if (AvailOnTheShop::exists())
      echo "<td>",AvailOnTheShop::maj($this->mapNum),"</td>\n";
    //echo "<td><pre>"; print_r($this); echo "</pre></td>";
    echo "</tr>\n";
  }
};

class AvailOnTheShop { // lit le fichier disponible.tsv s'il existe et stoke les cartes dispo. dans la boutique
  const FILE_NAME = __DIR__.'/disponible.tsv';
  const MAX_DURATION = 2*24*60*60; // durée pendant laquelle le fichier FILE_NAME reste valide
  //const MAX_DURATION = 60; // Pour test
  
  /** @var array<string, string> $all */
  static array $all=[]; // [{mapNum} => {maj}]
  
  static function exists(): bool { return (self::$all <> []); } // indique s'il existe au moins une carte disponible 
  
  static function init(): void {
    // si le fichier n'existe pas ou s'il date de plus de 2 jours alors abandon 
    if (!is_file(self::FILE_NAME) || (time() - filemtime(self::FILE_NAME) > self::MAX_DURATION))
      return;
    $ftsv = fopen(self::FILE_NAME, 'r');
    while ($record = fgetcsv($ftsv, 256, "\t")) {
      //echo "<pre>"; print_r($record); echo "</pre>\n";
      //echo "<pre>"; var_dump($record); echo "</pre>\n";
      if ($record[0] == 'Commande ') continue;
      if ($record[0] == null) break; // ligne vide à la fin du fichier
      if (!preg_match('! - (\d{4}) !', $record[1], $matches))
        die("No match on $record[1]\n");
      $mapnum = $matches[1];
      self::$all[$mapnum] = $record[2];
    }
    fclose($ftsv);
  }
  
  // retourne le champ 'Informations de mise à jour ' pour la carte de numéro $mapNum
  static function maj(string $mapNum): string { return self::$all[$mapNum] ?? ''; }
};

switch ($_GET['a']) {
  case 'listWfs': { // liste des cartes du serveur WFS du Shom avec intérêt pour ShomGT3
    echo "<h2>Liste des cartes du serveur WFS du Shom avec intérêt pour ShomGT3</h2><pre>\n";
    MapFromWfs::init();
    Zee::init();
    foreach (MapFromWfs::$fts as $gmap) {
      $gmap->showOne();
    }
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
        $scale = '1/'.addUndescoreForThousand(isset($map['scale']) ? (int)$map['scale'] : null);
        echo "- $map[name] ($scale) intersecte ",implode(',', $zeeIds),"<br>\n";
      }
    }
  
    $obsoletes = [];
    foreach (array_keys(Portfolio::$all) as $mapid) {
      if (!isset($listOfInterest[$mapid]))
        $obsoletes[] = $mapid;
    }
    if (!$obsoletes)
      echo "<h2>Toutes les cartes du portefeuille sont présentes dans le flux WFS</h2>\n";
    else {
      echo "<h2>Cartes du portefeuille absentes du flux WFS</h2>\n";
      //echo "<pre>"; print_r($listOfInterest); echo "</pre>\n";
      foreach ($obsoletes as $mapid) {
        echo "- $mapid<br>\n";
      }
    }
    die();
  }
  case 'perempt': { // construction puis affichage des degrés de péremption 
    Portfolio::init(); // initialisation à partir du portefeuille
    DbMapCat::init(); // chargement du fichier mapcat.yaml
    GanStatic::loadFromPser(); // chargement de la synthèse des GANs
    AvailOnTheShop::init();
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
  case 'availOnShop': { // Test de AvailOnTheShop::init();
    echo "<pre>";
    AvailOnTheShop::init();
    die();
  }
  default: { die("Action $_GET[a] non définie\n"); }
}
