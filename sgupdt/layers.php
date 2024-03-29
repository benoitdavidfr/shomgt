<?php
/** génération du fichier layers.yaml
 *
 * Ce script génère le fichier layers.yaml soit s'il est défini dans le fichier en paramètre soit sinon sur STDOUT 
 * Il prend en entrée:
 *   - le catalogue des cartes téléchargé depuis le serveur ${SHOMGT3_SERVER_URL}
 *   - la liste des géotiffs prévus dans view, avec pour certains leur géoréférencement (GdalInfo)
 * Il retourne:
 *   - le code 0 ssi la sortie est du Yaml correct et est conforme au schéma layers.schema.yaml
 *   - le code 1 ssi la sortie n'est pas du Yaml
 *   - le code 2 ssi la sortie n'est pas conforme au schéma layers.schema.yaml
 *   - le code >= 3 ssi une autre erreur est rencontrée
 *
 * La liste des cartes prévues correspond à:
 *   - les cartes existantes (que je vais trouver dans ../data/)
 *   - moins les cartes obsolètes à supprimer (que je trouve dans maps.json)
 *
 * Les couches avec leur seuil d'échelles sont définies dans la classe LayerDef.
 *
 * L'algorithme est le suivant:
 *   - j'initialise Map à partir de mapcat.yaml téléchargé depuis le serveur ${SHOMGT3_SERVER_URL}
 *   - j'initialise ShomGt avec la liste des couches définie dans LayerDef
 *   - je construis la liste des géotiffs à mettre dans layers en scannant le répertoire de ../data/curent
 *   - pour chacun de ces géotiffs
 *     - je l'ajoute à ShomGt
 *     - pour cela je recherche dans Map les infos sur ce GéoTiff
 *     - à partir de ces infos je construis l'objet ShomGt
 *     - je récupère l'échelle et j'en déduis avec LayerDef la couche à laquelle il appartient
 *     - enfin, je l'insère dans ShomGt
 *   - je vérifie que la structure ShomGT est conforme à son schéma
 *   - enfin, j'affiche ShomGt en Yaml
 *
 * La classe LayerDef définit les couches à créer dans layers et permet d'associer une couche à un géotiff.
 * La classe Map gère le catalogue des cartes et retrouve pour un géotiff les infos correspondantes.
 * La classe ShomGt contient une représentation de layers.yaml qui se construit progressivement.
 *
 * journal: |
 * 3/9/2023:
 *   - chgt du nom du fichier Yaml en layers.yaml
 *   - chgt du nom de ce script
 * 22/8/2023:
 *   - déplacement des 3 fichiers de schema/ dans ../lib/ et chgt du nom de predef.yaml en jsonschpredef.yaml
 * 2/8/2022:
 *   - corrections suites à PhpStan level 6
 * 17/6/2022:
 *   - adaptation au transfert de update.yaml dans mapcat.yaml
 * 6/6/2022:
 *   - correction bug
 * 3/6/2022:
 *   - si update.yaml contient une info spatial, elle remplace celle du catalogue
 *   - l'idée est que le catalogue définit le rectangle officiel mais que ce rectangle peut devoir être modifié
 *     pour effacer une partie de la carte et dans ce cas cette info est dans update.yaml
 * 22/5/2022:
 *   - ajout possibilité d'écrire shomgt.yaml dans un fichier et pas uniquement sur STDOUT
 * 18/5/2022:
 *   - adaptation à l'utilisation dans le conteneur
 * 17/5/2022:
 *   - modif georefrect() car il n'y a plus de GéoTiffs dans temp
 * 16/5/2022:
 *   - transfert dans sgupdt
 *   - chgt de méthode pour vérifier la conformité Yaml du flux de sortie ainsi que sa conformité à son schéma
 * 8/5/2022:
 *   - chgt du nom de la classe LayerName en layerDef
 *   - vérif. que le flux de sortie est du yaml correct et qu'il est conforme à son schéma
 * 7/5/2022:
 *   - création
 * @package shomgt\sgupdt
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/jsonschema.inc.php';
require_once __DIR__.'/../lib/geotiffs.inc.php';
require_once __DIR__.'/../lib/mapcat.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if ($argc == 1) {
  $fout = STDOUT;
}
elseif ($argc == 2) {
  if (!($fout = fopen($argv[1], 'w')))
    throw new Exception("Erreur d'ouverture du fichier $argv[1]");
}
else
  throw new Exception("Cas non prévu argc=$argc");

/** Définit les couches std et spéciales, permet de savoir à laquelle un GéoTiff appartient */
class LayerDef {
  const ErrorScaleDenNotFound = 'LayerDef::ErrorScaleDenNotFound';
  /** liste des couches regroupant les GéoTiff avec pour chacune la valeur max du dénominateur d'échelle des GéoTiff
   * contenus dans la couche. */
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
  /** liste des noms des couches spéciales */
  const SPECIAL_LAYERS = [
    'gtaem',
    'gtMancheGrid',
    'gtZonMar',
  ];
  
  /** retourne le nom de la couche en fonction du dén. de l'échelle */
  static function getFromScaleDen(int $scaleDen): string {
    foreach (self::LAYERS_SCALE_DEN_MAX as $lyrId => $scaleDenMax) {
      if ($scaleDen <= $scaleDenMax)
        return "gt$lyrId";
    }
    throw new SExcept("ScaleDen $scaleDen non trouvée", self::ErrorScaleDenNotFound);
  }
};

/** construction progressive du futur contenu de layers.yaml ; un objet ShomGT décrit un géoTiff */
class ShomGt {
  /** nom du GéoTiff */
  protected string $gtname;
  /** titre issu du catalogue de cartes */
  protected string $title;
  /** extension spatiale  sous la forme ['SW'=> {pos}, 'NE'=> {pos}], issu du catalogue de cartes
   * @var array<string, TPos> $spatial */
  protected array $spatial;
  /** liste d'excroissances sous la forme [['SW'=> {pos}, 'NE'=> {pos}]]
   * @var list<array<string, TPos>> $outgrowth */
  protected array $outgrowth;
  /** dénominateur de l'échelle issu du catalogue de cartes */
  protected int $scaleDen;
  /** z-order issu du catalogue de cartes */
  protected int $zorder;
  /** zones effacées dans le GéoTiff
   * @var array<string, array<int, mixed>> $deleted */
  protected array $deleted;
  /** nom de la couche pour les cartes spéciales, null sinon */
  protected ?string $layer;
  /** bordures au cas où le GéoTiff n'est pas géoréférencé
   * @var array<int, int|string> $borders */
  protected array $borders=[];

  /** contenu de layers.yaml sous la forme [{layername}=> [{gtname} => ShomGT]]
   * @var array<string, array<string, ShomGt>> $all */
  static array $all=[];

  /** Initialise self::$all */
  static function init(): void {
    foreach (array_reverse(LayerDef::LAYERS_SCALE_DEN_MAX) as $lyrId => $scaleDenMax) {
      //echo "$lyrId => $scaleDenMax\n";
      self::$all["gt$lyrId"] = [];
    }
    foreach (LayerDef::SPECIAL_LAYERS as $lyrname)
      self::$all[$lyrname] = [];
  }
  
  /** ajoute un GT par son nom */
  static function addGt(string $gtname): void {
    //echo "ShomGt::addGt($gtname)\n";
    $map = TempMapCat::fromGtname($gtname, false);
    //echo "map="; print_r($map);
    if (!$map) {
      fprintf(STDERR, "Alerte: le GéoTiff $gtname n'existe pas dans le catalogue, il n'apparaitra donc pas dans layers.yaml\n");
      return;
    }
    $gtinfo = $map->gtInfo();
    if (!$gtinfo) {
      fprintf(STDERR, "Erreur: le GéoTiff $gtname n'est pas géoréférencé, il n'apparaitra donc pas dans layers.yaml\n");
      return;
    }
    if (!$gtinfo['spatial']) {
      fprintf(STDERR, "Info: le GéoTiff $gtname n'a pas de zone principale et n'apparaitra donc pas dans layers.yaml\n");
      return;
    }
    //echo 'gtinfo='; print_r($gtinfo);
    $gt = new self($gtname, $gtinfo);
    self::$all[$gt->layer][$gtname] = $gt;
  }
  
  /** @param array<string, mixed> $info */
  function __construct(string $gtname, array $info) {
    $this->gtname = $gtname;
    $this->title = $info['title'];
    $this->spatial = $info['spatial'];
    $this->outgrowth = $info['outgrowth'];
    $this->scaleDen = $info['scaleDen'];
    $this->zorder = $info['z-order'] ?? 0;
    $this->deleted = $info['toDelete'] ?? [];
    unset($this->deleted['geotiffname']);
    $this->layer = $info['layer'] ?? LayerDef::getFromScaleDen($this->scaleDen);
    $this->borders = $info['borders'];
    //echo 'info='; print_r($info);
    //echo 'ShomGt='; print_r($this);
  }
  
  /** tri des GT de chaque couche selon zorder et gtname */
  static function sortwzorder(): void {
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
  
  /** génère la représentation array de tous les ShomGt pour sortie Yaml
   * @return array<string, array<string, array<string, mixed>>|string>  */
  static function allAsArray(): array {
    $array = [
      'title'=> "liste de GéoTiffs préparée pour le container view",
      'description'=> "fichier généré par " .__FILE__,
      'created'=> date(DATE_ATOM),
      '$schema'=> "layers",
    ];
    foreach (self::$all as $lyrname => $shomGts) {
      $array[$lyrname] = [];
      foreach ($shomGts as $gtname => $shomGt) {
        $array[$lyrname][$gtname] = $shomGt->asArray();
      }
    }
    return $array;
  }
  
  /** génère la représentation Yaml d'un ShomGt dans un array
   * @return array<string, mixed> */
  function asArray(): array {
    $mapnum = substr($this->gtname, 0, 4);
    $array = [
      'title'=> "$mapnum - $this->title",
      'spatial'=> $this->spatial,
    ];
    if ($this->outgrowth)
      $array['outgrowth'] = $this->outgrowth;
    if ($this->deleted)
      $array['deleted'] = $this->deleted;
    if ($this->borders)
      $array['borders'] = $this->borders;
    return $array;
  }
};
ShomGt::init(); //print_r(ShomGt::$shomgt); die();

// lecture du fichier mapcat.json
TempMapCat::init(); // print_r(TempMapCat::$cat); die();

if (0) { // @phpstan-ignore-line // Test de ShomGt::sortwzorder()
  ShomGt::addGt('6822_pal300');
  ShomGt::addGt('6823_pal300');
  ShomGt::addGt('6969_pal300');
  print_r(ShomGt::$all['gt50k']);
  ShomGt::sortwzorder();
  print_r(ShomGt::$all['gt50k']);
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

/** Lecture dans maps.json de la liste des nums des cartes obsolètes.
 * @return array<int, string> */
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

ShomGt::sortwzorder();
//print_r(ShomGt::$all); die();

// Génération dans $yaml du fichier layers.yaml en vérifiant sa validité Yaml et sa conformité au schéma
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
$status = \jsonschema\Schema::autoCheck($parsed);
if ($status->ok()) {
  fprintf(STDERR, "Ok, layers.yaml conforme à son schéma\n");
  exit(0);
}
else {
  fprintf(STDERR, "Erreur, layers.yaml NON conforme à son schéma\n");
  foreach ($status->errors() as $error)
    fprintf(STDERR, "%s\n", $error);
  exit(2);
}
