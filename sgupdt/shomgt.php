<?php
/*PhpDoc:
title: shomgt.php - génération du fichier shomgt.yaml
name: shomgt.php
doc: |
  Ce script génère le fichier shomgt.yaml soit s'il est défini dans le fichier en paramètre soit sinon sur STDOUT 
  Il prend en entrée:
    - le catalogue des cartes téléchargé depuis le serveur ${SHOMGT3_SERVER_URL}
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
    - je vérifie que la structure ShomGT est conforme à son schéma
    - enfin, j'affiche ShomGt en Yaml

  La classe LayerDef définit les couches à créer dans shomgt et permet d'associer une couche à un géotiff.
  La classe Map gère le catalogue des cartes et retrouve pour un géotiff les infos correspondantes.
  La classe ShomGt contient une représentation de shomgt.yaml qui se construit progressivement.

journal: |
  17/6/2022:
    - adaptation au transfert de update.yaml dans mapcat.yaml
  6/6/2022:
    - correction bug
  3/6/2022:
    - si update.yaml contient une info spatial, elle remplace celle du catalogue
    - l'idée est que le catalogue définit le rectangle officiel mais que ce rectangle peut devoir être modifié
      pour effacer une partie de la carte et dans ce cas cette info est dans update.yaml
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
#require_once __DIR__.'/lib/envvar.inc.php';
#require_once __DIR__.'/lib/execdl.inc.php';
require_once __DIR__.'/lib/geotiffs.inc.php';
require_once __DIR__.'/lib/mapcat.inc.php';
#require_once __DIR__.'/lib/gdalinfo.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if ($argc == 1) {
  $fout = STDOUT;
}
elseif ($argc == 2) {
  if ($argv[1] == '-v') {
    echo "Dates de dernière modification des fichiers sources:\n";
    echo Yaml::dump($VERSION);
    die();
  }
  elseif (!($fout = fopen($argv[1], 'w')))
    throw new Exception("Erreur d'ouverture du fichier $argv[1]");
}

// Définit les couches std et spéciales, permet de savoir à laquelle un GéoTiff appartient
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
  // liste des noms des couches spéciales
  const SPECIAL_LAYERS = [
    'gtaem',
    'gtMancheGrid',
    'gtZonMar',
  ];
  
  static function getFromScaleDen(int $scaleDen): string { // retourne le nom de la couche en fonction du dén. de l'échelle
    foreach (self::LAYERS_SCALE_DEN_MAX as $lyrId => $scaleDenMax) {
      if ($scaleDen <= $scaleDenMax)
        return "gt$lyrId";
    }
  }
};

class ShomGt { // construction progressive du futur contenu de shomgt.yaml
  protected string $gtname;
  protected string $title; // titre issu du catalogue de cartes
  protected array $spatial; // sous la forme ['SW'=> {pos}, 'NE'=> {pos}], issu du catalogue de cartes
  protected int $scaleDen; // dénominateur de l'échelle issu du catalogue de cartes
  protected int $zorder; // z-order issu du catalogue de cartes
  protected array $deleted; // zones effacées dans le GéoTiff
  protected ?string $layer; // nom de la couche pour les cartes spéciales, null sinon
  protected array $borders=[]; // bordures au cas où le GéoTiff n'est pas géoréférencé

  static array $all=[]; // contenu de shomgt.yaml sous la forme [{layername}=> [{gtname} => ShomGt]]

  static function init(): void {
    foreach (array_reverse(LayerDef::LAYERS_SCALE_DEN_MAX) as $lyrId => $scaleDenMax) {
      //echo "$lyrId => $scaleDenMax\n";
      self::$all["gt$lyrId"] = [];
    }
    foreach (LayerDef::SPECIAL_LAYERS as $lyrname)
      self::$all[$lyrname] = [];
  }
  
  static function addGt(string $gtname): void { // ajoute un GT par son nom
    //echo "ShomGt::addGt($gtname)\n";
    $map = MapCat::fromGtname($gtname, false);
    if (!$map) {
      fprintf(STDERR, "Alerte: le GéoTiff $gtname n'existe pas dans le catalogue, il n'apparaitra donc pas dans shomgt.yaml\n");
      return;
    }
    $gtinfo = $map->gtInfo();
    if (!$gtinfo) {
      fprintf(STDERR, "Erreur: le GéoTiff $gtname n'est pas géoréférencé, il n'apparaitra donc pas dans shomgt.yaml\n");
      return;
    }
    if (!$gtinfo['spatial']) {
      fprintf(STDERR, "Info: le GéoTiff $gtname n'a pas de zone principale et n'apparaitra donc pas dans shomgt.yaml\n");
      return;
    }
    //echo 'gtinfo='; print_r($gtinfo);
    $gt = new self($gtname, $gtinfo);
    self::$all[$gt->layer][$gtname] = $gt;
  }
  
  function __construct(string $gtname, array $info) {
    $this->gtname = $gtname;
    $this->title = $info['title'];
    $this->spatial = $info['spatial'];
    $this->scaleDen = $info['scaleDen'];
    $this->zorder = $info['z-order'] ?? 0;
    $this->deleted = $info['toDelete'] ?? [];
    unset($this->deleted['geotiffname']);
    $this->layer = $info['layer'] ?? LayerDef::getFromScaleDen($this->scaleDen);
    $this->borders = $info['borders'];
    //echo 'info='; print_r($info);
    //echo 'ShomGt='; print_r($this);
  }
  
  static function sortwzorder(): void { // tri de chaque couche selon zorder et gtname
    foreach (self::$all as $layername => &$gts) {
      uksort($gts,
        function($a, $b) use($gts) {
          //echo "function($a, $b)\n";
          if ($gts[$a]->zorder == $gts[$b]->zorder) {
            //echo "zorder égaux, return ",strcmp($a, $b),"\n";
            return strcmp($a, $b);
          }
          elseif ($gts[$a]->zorder < $gts[$b]->zorder) { // $a < $b
            //echo "$a ->zorder < $b ->zorder => return -1;\n";
            return -1;
          }
          else { // $a > $b
            //echo "$a ->zorder > $b ->zorder => return 1;\n";
            return 1;
          }
        }
      );
    }
  }
  
  static function allAsArray(): array { // génère la représentation Yaml de tous les ShomGt dans un string
    $array = [
      'title'=> "liste de GéoTiffs préparée pour le container shomgt",
      'description'=> "fichier généré par " .__FILE__,
      'created'=> date(DATE_ATOM),
      '$schema'=> "shomgt",
    ];
    foreach (self::$all as $lyrname => $shomGts) {
      $array[$lyrname] = [];
      foreach ($shomGts as $gtname => $shomGt) {
        $array[$lyrname][$gtname] = $shomGt->asArray();
      }
    }
    return $array;
  }
  
  function asArray(): array { // génère la représentation Yaml d'un ShomGt dans un array
    $mapnum = substr($this->gtname, 0, 4);
    $array = [
      'title'=> "$mapnum - $this->title",
      'spatial'=> $this->spatial,
    ];
    if ($this->deleted)
      $array['deleted'] = $this->deleted;
    if ($this->borders)
      $array['borders'] = $this->borders;
    return $array;
  }
};
ShomGt::init(); //print_r(ShomGt::$shomgt); die();

// lecture du fichier mapcat.json
MapCat::init();

if (0) { // Test de ShomGt::sortwzorder()
  ShomGt::addGt('6822_pal300');
  ShomGt::addGt('6823_pal300');
  ShomGt::addGt('6969_pal300');
  print_r(ShomGt::$shomgt['gt50k']);
  ShomGt::sortwzorder();
  print_r(ShomGt::$shomgt['gt50k']);
  die("Fin ligne ".__LINE__."\n");
}

$geotiffs = []; // liste des géotiffs structurés par carte et type [{mapnum} => [('tif'|'pdf') => [{gtname} => 1]]]

// initialisation $geotiffs à partir de la liste des géotiffs dans data/maps
foreach (geotiffs() as $gtname) {
  $mapnum = substr($gtname, 0, 4);
  if (substr($gtname, -4)=='.pdf')
    $geotiffs[$mapnum]['pdf'][$gtname] = 1;  
  else
    $geotiffs[$mapnum]['tif'][$gtname] = 1;  
}

function obsoleteMaps(): array { // Lecture dans maps.json de la liste des nums des cartes obsolètes
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

ShomGt::sortwzorder();
//print_r(ShomGt::$all); die();

// Génération dans $yaml du fichier shomgt.yaml en vérifiant sa validité Yaml et sa conformité au schéma
$yaml = Yaml::dump(ShomGt::allAsArray(), 6, 2);
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
