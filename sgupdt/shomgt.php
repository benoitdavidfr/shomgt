<?php
/*PhpDoc:
title: shomgt.php - génération du fichier shomgt.yaml
name: shomgt.php
doc: |
  Ce script génère le fichier shomgt.yaml soit s'il est défini dans le fichier en paramètre soit sinon sur STDOUT 
  Il prend en entrée:
    - le catalogue des cartes téléchargé depuis le serveur ${SHOMGT3_SERVER_URL}
    - les paramètres dans update.yaml
    - la liste des géotiffs prévus dans shomgt, avec pour certains leur géoréférencement (GdalInfo)
  Il retourne:
    - le code 0 ssi la sortie est du Yaml correct et est conforme au schéma shomgt.schema.yaml
    - le code 1 ssi la sortie n'est pas du Yaml
    - le code 2 ssi la sortie n'est pas conforme au schéma shomgt.schema.yaml
    - le code >= 3 ssi une autre erreur est rencontrée

  La liste des cartes prévues dans shomgt correspond à:
    - les cartes existantes dans shomgt (que je vais trouver dans ../data/)
    - moins les cartes obsolètes à supprimer (que je trouve dans maps.json)

  Les couches avec leur seuil d'échelles sont définies dans la classe LayerDef.

  L'algorithme est le suivant:
    - j'initialise Map à partir de mapcat.yaml téléchargé depuis le serveur ${SHOMGT3_SERVER_URL}
    - j'initialise ShomGt avec la liste des couches définie dans LayerDef
    - je construis la liste des géotiffs à mettre dans shomgt en scannant le répertoire de ../data/curent
    - pour chacun de ces géotiffs
      - je l'ajoute à ShomGt
      - pour cela je recherche dans Map les infos sur ce GéoTiff
      - à partir de ces infos je construis l'objet ShomGt
      - je récupère l'échelle et j'en déduis avec LayerDef la couche à laquelle il appartient
      - enfin, je l'insère dans ShomGt
    - enfin, j'affiche ShomGt en Yaml

  La classe LayerDef définit les couches à créer dans shomgt et permet d'associer une couche à un géotiff.
  La classe Map gère le catalogue des cartes et retrouve pour un géotiff les infos correspondantes.
  La classe ShomGt contient une représentation de shomgt.yaml qui se construit progressivement.

  A faire:
    - remplacer l'accès à mapcat.yaml par un téléchargement
    - transférer jsonschema.inc.php dans lib/
    - implémenter un mécanisme z-ordrer

journal: |
  22/5/2022:
    - ajout possibilité d'écrire shomgt.yaml dans un fichier et pas uniquement sur STDOUT
  18/5/2022:
    - adaptation à l'utilisation dans le conteneur
  17/5/2022:
    - modif georefrect() car il n'y a plus de GéoTiffs dans temp
  16/5/2022:
    - transfert dans sgupdt
    - chgt de méthode pour vérifier la conformité Yaml du flux de sortie ainsi que sa conformité à son schéma
  8/5/2022:
    - chgt du nom de la classe LayerName en layerDef
    - vérif. que le flux de sortie est du yaml correct et qu'il est conforme à son schéma
  7/5/2022:
    - création
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/schema/jsonschema.inc.php';
require_once __DIR__.'/lib/envvar.inc.php';
require_once __DIR__.'/lib/execdl.inc.php';
require_once __DIR__.'/lib/geotiffs.inc.php';
require_once __DIR__.'/lib/gdalinfo.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if ($argc == 1)
  $fout = STDOUT;
elseif ($argc == 2) {
  if ($argv[1] == '-v') {
    echo "Dates de dernière modification des fichiers sources:\n";
    echo Yaml::dump($VERSION);
    die();
  }
  elseif (!($fout = fopen($argv[1], 'w')))
    throw new Exception("Erreur d'ouverture du fichier $argv[1]");
}
  
// récupère le rectangle de géoréférencement du GéoTiff $gtname stocké dans ../data
function georefrect(string $gtname): GBox {
  //echo "georefrect($gtname)\n";
  //$gtinfopath = __DIR__.'/temp/'.substr($gtname, 0, 4)."/$gtname.info";
  //if (!is_file($gtinfopath)) {
    //echo "le fichier $gtinfopath n'existe pas dans temp\n";
    $gtinfopath = GdalInfo::filepath($gtname);
    //}
  /*else {
    echo "le fichier $gtinfopath existe dans temp\n";
  }*/
  $gdalinfo = new GdalInfo($gtinfopath);
  //print_r($gdalinfo);
  //print_r($gdalinfo->ebox()->geo('WorldMercator'));
  return $gdalinfo->ebox()->geo('WorldMercator');
}

// Gère la définition des couches std et spéciales
// permet de savoir si un gtname correspond à une couche spéciale et sinon la couche à laquelle il appartient
class LayerDef {
  // liste des couches regroupant les GéoTiff avec pour chacune la valeur max du dénominateur d'échelle des GéoTiff
  // contenus dans la couche
  const LAYERS_SCALE_DEN_MAX = [
    '5k'=> 1.1e4,
    '12k'=> 2.2e4,
    '25k'=> 4.5e4,
    '50k'=> 9e4,
    '100k'=> 1.8e5,
    '250k'=> 3.8e5,
    '500k'=> 7e5,
    '1M'=> 1.4e6,
    '2M'=> 3e6,
    '4M'=> 6e6,
    '10M'=> 1.4e7,
    '40M'=> 9e999,
  ];
  // liste des noms des couches supplémentaires avec la liste des noms de géotiif de la couche
  const SPECIAL_LAYERS = [
    'gtaem' => ['7330_2016', '7344_2016', '7360_2016', '8502_2010', '8509_2015.pdf', '8517_2015.pdf'],
    'gtMancheGrid' => ['8101'],
    'gtZonMar' => ['8510_2015.pdf'],
  ];
  
  static function specialGt(string $gtname): ?string {
    foreach (self::SPECIAL_LAYERS as $lyrname => $gtnames)
      if (in_array($gtname, $gtnames))
        return $lyrname;
    return null;
  }
  
  static function getFromScaleDen(int $scaleDen): string { // retourne le nom de la couche en fonction du dén. de l'échelle
    foreach (self::LAYERS_SCALE_DEN_MAX as $lyrId => $scaleDenMax) {
      if ($scaleDen <= $scaleDenMax)
        return "gt$lyrId";
    }
  }
};

// gère le catalogue de cartes et sait retourner pour un gtname les infos correspondantes
class Map {
  protected string $name; // le nom de la carte
  protected array $map; // les caractéristiques de la carte correspondant au fichier mapcat.yaml
  
  static $cat; // catalogue [{mapName} => Map]
  
  static function init(): void { // initialise à partir du fichier cat.json téléchargé de $SHOMGT3_SERVER_URL
    $url = EnvVar::val('SHOMGT3_SERVER_URL').'/cat.json';
    if (!is_dir(__DIR__.'/temp')) mkdir(__DIR__.'/temp');
    $tempPath = __DIR__.'/temp/maps.json';
    $httpCode = download($url, $tempPath, 0);
    if ($httpCode <> 200)
      throw new Exception("Erreur de download de cat.json");
    $mapcat = json_decode(file_get_contents($tempPath), true);
    unlink($tempPath);
    foreach ($mapcat['maps'] as $name => $map) {
      self::$cat[$name] = new Map($name, $map);
    }
  }
  
  function __construct(string $name, array $map) {
    $this->name = $name;
    $this->map = $map;
  }
  
  //function title(): string { return $this->map['title']; }
  //function spatial(): array { return $this->map['bboxDM'] ?? []; }
  function scaleDen(): ?int {
    return isset($this->map['scaleDenominator']) ? str_replace('.', '', $this->map['scaleDenominator']) : null;
  }
  function insetMaps(): array { return $this->map['insetMaps'] ?? []; }
  
  function insetMap(int $no): Map {
    return new Map("inset $no of $this->name", $this->map['insetMaps'][$no]);
  }
  
  // fabrique ['title'=> {title}, 'spatial'=> {spatial}, 'scaleDen'=> {scaleDen}, 'borders'=> {borders}]
  function gtInfo(): array {
    return [
      'title'=> $this->map['title'],
      'spatial'=> $this->map['bboxDM'] ?? [],
      'scaleDen'=> $this->scaleDen(),
    ];
  }
  
  // sélectionne le cartouche qui correspond le mieux au rectangle passé en paramètre et en construit un objet Map
  function insetMapFromRect(GBox $georefrect): Map {
    //echo "Map::insetMap($georefrect)\n";
    //echo "map="; print_r($this->map);
    $best = -1;
    foreach ($this->map['insetMaps'] as $no => $insetMap) {
      //echo "insetMaps[$no]="; print_r($insetMap);
      $dist = GBox::fromShomGt($insetMap['bboxDM'])->distance($georefrect);
      //echo "distance=$dist\n";
      if (($best == -1) || ($dist < $distmin)) {
        $best = $no;
        $distmin = $dist;;
      }
    }
    //  echo "best="; print_r($this->map['insetMaps'][$best]);
    return new Map("inset $best of $this->name", $this->map['insetMaps'][$best]);
  }
  
  static function fromGtname(string $gtname): ?self { // retourne la carte ou le cartouche correspondant à $gtname
    $mapnum = substr($gtname, 0, 4);
    $map = self::$cat["FR$mapnum"] ?? null;
    if (!$map)
      return null;
    if (preg_match('!^\d+_pal300$!', $gtname) || LayerDef::specialGt($gtname)) {
      return $map;
    }
    elseif (count($map->insetMaps())==1) {
      return $map->insetMap(0);
    }
    else {
      return $map->insetMapFromRect(georefrect($gtname));
    }
  }
};
Map::init();
//print_r(Map::$cat); die();

class ShomGt { // construction progressive du futur contenu de shomgt.yaml
  protected string $gtname;
  protected string $title;
  protected array $spatial; // sous la forme ['SW'=> {pos}, 'NE'=> {pos}]
  protected int $scaleDen;
  //protected array $borders; // [] ou sous la forme ['left'=> {border}, 'bottom'=>{border}, 'right'=> {border}, 'top'=> {border}]
  static array $shomgt=[]; // contenu de shomgt.yaml sous la forme [{layername}=> [{gtname} => ShomGt]]
  static array $params=[]; // chargement du fichier update.yaml

  static function init(): void {
    foreach (array_reverse(LayerDef::LAYERS_SCALE_DEN_MAX) as $lyrId => $scaleDenMax) {
      //echo "$lyrId => $scaleDenMax\n";
      self::$shomgt["gt$lyrId"] = [];
    }
    foreach (array_keys(LayerDef::SPECIAL_LAYERS) as $lyrname)
      self::$shomgt[$lyrname] = [];
    
    self::$params = Yaml::parseFile(__DIR__.'/update.yaml');
  }
  
  static function addGt(string $gtname): void { // ajoute un GT par son nom
    //echo "ShomGt::addGt($gtname)\n";
    $map = Map::fromGtname($gtname);
    if (!$map) {
      fprintf(STDERR, "Alerte: le GéoTiff $gtname n'existe pas dans le catalogue, il n'apparaitra donc pas dans shomgt.yaml\n");
      return;
    }
    $gtinfo = $map->gtInfo();
    if (!$gtinfo) { echo "skip $gtname\n"; return; }
    if (!$gtinfo['spatial']) {
      fprintf(STDERR, "Info: le GéoTiff $gtname n'a pas de zone principale et n'apparaitra donc pas dans shomgt.yaml\n");
      return;
    }
    $gt = new self($gtname, $gtinfo);
    self::$shomgt[$gt->lyrname()][$gtname] = $gt;
  }
  
  function __construct(string $gtname, array $info) {
    //echo "ShomGt::__construct(gtname=$gtname, info="; print_r($info); echo ")\n";
    $this->gtname = $gtname;
    $this->title = $info['title'];
    $this->spatial = $info['spatial'];
    $this->scaleDen = $info['scaleDen'];
    //$this->borders = $info['borders']; // Les borders devraient venir de build.yaml !!!
  }
  
  function lyrname(): string {
    if ($lyrname = LayerDef::specialGt($this->gtname))
      return $lyrname;
    else
      return LayerDef::getFromScaleDen($this->scaleDen);
  }
  
  static function allInYaml(): string { // génère la représentation Yaml de tous les ShomGt dans un string
    $yaml = "title: liste de GéoTiffs préparée pour le container shomgt\n";
    $yaml .= "description: fichier généré par " .__FILE__."\n";
    $yaml .= "created: '".date(DATE_ATOM)."'\n";
    $yaml .= "\$schema: shomgt\n";
    foreach (self::$shomgt as $lyrname => $gtiffs) {
      $yaml .= "$lyrname:\n";
      foreach ($gtiffs as $gtname => $gtiff) {
        $yaml .= $gtiff->yaml();
      }
    }
    return $yaml;
  }
  
  function yaml(): string { // génère la représentation Yaml d'un ShomGt dans un string
    //print_r($this);
    if (preg_match('!^[\d_]+$!', $this->gtname))
      $yaml = "  '$this->gtname':\n";
    else
      $yaml = "  $this->gtname:\n";
    $mapnum = substr($this->gtname, 0, 4);
    $yaml .= "    title: $mapnum - $this->title\n";
    $yaml .= "    spatial: {SW: \"".$this->spatial['SW']."\", NE: \"".$this->spatial['NE']."\"}\n";
    if ($borders = self::$params[$this->gtname]['borders'] ?? []) {
      //$out[] = "    borders: {left: $borders[left], bottom: $borders[bottom], right: $borders[right], top: $borders[top]}\n";
      $yaml .= "    borders: ".Yaml::dump($borders, 0)."\n";
    }
    return $yaml;
  }
};
ShomGt::init(); //print_r(ShomGt::$shomgt); die();

$geotiffs = []; // liste des géotiffs structurés par carte et type [{mapnum} => [('tif'|'pdf') => [{gtname} => 1]]]

// lecture des géotiffs de shomgt
foreach (geotiffs() as $gtname) {
  $mapnum = substr($gtname, 0, 4);
  if (substr($gtname, -4)=='.pdf')
    $geotiffs[$mapnum]['pdf'][$gtname] = 1;  
  else
    $geotiffs[$mapnum]['tif'][$gtname] = 1;  
}

function obsoleteMaps(): array {
  $obsoleteMaps = [];
  if (($maps = @file_get_contents(__DIR__.'/temp/maps.json')) === false) {
    if (!is_dir(__DIR__.'/temp')) mkdir(__DIR__.'/temp');
    $SHOMGT3_SERVER_URL = EnvVar::val('SHOMGT3_SERVER_URL');
    $httpCode = download("$SHOMGT3_SERVER_URL/maps.json", __DIR__.'/temp/maps.json', 0);
    if ($httpCode <> 200) {
      fprintf(STDERR, "Erreur de téléchargement du fichier $SHOMGT3_SERVER_URL/maps.json\n");
      exit(3);
    }
    if (($maps = @file_get_contents(__DIR__.'/temp/maps.json')) === false) {
      fprintf(STDERR, "Erreur douverture no 2 du fichier $SHOMGT3_SERVER_URL/maps.json\n");
      exit(4);
    }
  }
  $maps = json_decode($maps, true);
  foreach ($maps as $mapnum => $map) {
    if (is_int($mapnum) || ctype_digit($mapnum)) { // on se limite aux cartes dont l'id est un nombre
      if ($map['status'] <> 'ok')
        $obsoleteMaps[] = $mapnum;
    }
  }
  return $obsoleteMaps;
}

// suppression dans shomgt.yaml des cartes obsoletes
foreach (obsoleteMaps() as $mapnum) {
  unset($geotiffs[$mapnum]);
}

// ajout des géotiffs de temp - plus utile car toutes les cartes ont été transférées dans data/maps
/*foreach (new DirectoryIterator(__DIR__.'/temp') as $map) {
  if ($map->isDot() || !$map->isDir()) continue;
  foreach (new DirectoryIterator(__DIR__."/temp/$map") as $gt) {
    if ($gt->isDot() || !$gt->isDir()) continue;
    //echo "ajout $map $gt\n";
    $gtname = $gt->getFilename();
    if (substr($gtname, -4)=='.pdf')
      $geotiffs[$map->getFilename()]['pdf'][$gtname] = 1;  
    else
      $geotiffs[$map->getFilename()]['tif'][$gtname] = 1;  
  }
}*/

// Suppression des pdf en double avec un tif et suppression du type
foreach ($geotiffs as $mapnum => &$gtnamesByType) {
  //echo "$mapnum: "; print_r($gtnamesByType);
  if (isset($gtnamesByType['pdf']) && !isset($gtnamesByType['tif']))
    $gtnamesByType = $gtnamesByType['pdf'];
  else
    $gtnamesByType = $gtnamesByType['tif'];
}
//print_r($geotiffs);

// ajout de chaque GéoTiff à ShomGt
foreach ($geotiffs as $mapnum => $gtnames) {
  foreach (array_keys($gtnames) as $gtname) {
    ShomGt::addGt($gtname);
  }
}

// Génération dans $yaml du fichier shomgt.yaml en vérifiant sa validité Yaml et sa conformité au schéma
$yaml = ShomGt::allInYaml();
fwrite($fout, $yaml);
try {
  $parsed = Yaml::parse($yaml);
}
catch (ParseException $e) {
  fprintf(STDERR, "Erreur dans l'analyse Yaml du flux de sortie : %s\n", $e->getMessage());
  exit(1);
}

$parsed['$schema'] = __DIR__.'/'.$parsed['$schema'];
$status = JsonSchema::autoCheck($parsed);
if ($status->ok()) {
  fprintf(STDERR, "Ok, shomgt.yaml conforme à son schéma\n");
  exit(0);
}
else {
  fprintf(STDERR, "Erreur, shomgt.yaml NON conforme à son schéma\n");
  foreach ($status->errors() as $error)
    fprintf(STDERR, "%s\n", $error);
  exit(2);
}
