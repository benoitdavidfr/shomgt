<?php
/** procédure principale de mise à jour des cartes ShomGT V4 - 4/8/2023
 *
 * Variable_d'Environnement:
 *   SHOMGT3_SERVER_URL: url du serveur de cartes en 7z
 *   SHOMGT3_MAPS_DIR_PATH: répertoire dans lequel les cartes expansées doivent être copiées
 *
 * A faire:
 *   - afficher à la fin une synthèse du traitement
 * journal: |
 * - 17/8/2023:
 *   - correction de la version des cartes spéciales
 * - 8/8/2023:
 *   - ajout d'un verrou pour interdire des exécutions simultanées
 * - 3/8/2023:
 *   - chgt de la constante VERSION en '4' pour obtenir les vraies versions des cartes spéciales
 *   - modif de findCurrentMapVersion() sur les cartes spéciales
 * - 18/6/2023:
 *   - déplacement lib dans ../lib
 * - 1/8/2022:
 *   - ajout déclarations PhpStan pour level 6
 * - 22/6/2022:
 *   - correction d'un bug
 * - 20/6/2022:
 *   - correction d'un bug
 * - 19/6/2022:
 *   - ajout mention d'une version dans l'appel à $SERVER_URL/maps.json
 * - 17/6/2022:
 *   - adaptation au transfert de update.yaml dans mapcat.yaml
 * - 30/5/2022:
 *   - ajout suppression du cache de tuiles
 *   - passage en paramètres des variables globales
 *   - mise en oeuvre du nouveau protocole du serveur de ce jour
 * - 19/5/2022:
 *   - ajout création du répertoire $MAPS_DIR_PATH s'il n'existe pas
 *   - définition de valeurs par défaut pour $SERVER_URL et $MAPS_DIR_PATH
 * - 18/5/2022:
 *   - évolution du code pour fonctionner en contenenur
 *   - utilisation des 2 variables d'environnement
 *     - formalisation sous la forme de variables globales en majuscules - plus faciles à utiliser
 *   - en php:cli le répertoire par défaut est / et non le répertoire dans lequel php est lancé
 *     - il faut donc que les références aux fichiers soient toutes absolues
 *   - création du dossier temp au démarrage s'il n'existe pas
 *   - transfert de temp dans SHOMGT3_MAPS_DIR_PATH
 *     - pour permettre le déplacement du répertoire de carte de temp vers SHOMGT3_MAPS_DIR_PATH
 * - 16/5/2022:
 *   - détection de la liste des cartes obsolètes dans maps.json
 *   - construction du layers.yaml et transfert dans le répertoire data
 *   - effacement des cartes obsolètes
 *   - le bug sur 0101 provenait d'un bug de sgserver
 * - 15/5/2022:
 *   - initialisation de la liste des cartes par interrogation du serveur sur /maps.json
 *   - test KO sur nouveau patrimoine
 *     - pourquoi avec ajout de incoming/20200225, obsoleteMaps=Array([0] => 0101) ?
 *     - l'utilisation de mapcat pour fabriquer la liste des cartes ne permet pas de tester la gestion des cartes obsolètes
 * - 13/5/2022:
 *   - création initiale
 *   - gestion du cas particuliers des cartes sans métadonnées
 *   - test OK sur un patrimoine courant identique au patrimoine archivé
 * @package shomgt\sgupdt
 */
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/../lib/envvar.inc.php';
require_once __DIR__.'/../lib/execdl.inc.php';
require_once __DIR__.'/../lib/readmapversion.inc.php';
require_once __DIR__.'/../lib/mapcat.inc.php';
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

define ('SGSERVER_VERSION', '4'); // no de version utilisée dans l'appel au serveur sgserver
define ('CMDE_VERBOSE', 1); // degré de verbosité de l'exécution des cmdes

// phase d'initialisation
$SERVER_URL = EnvVar::val('SHOMGT3_SERVER_URL');
$MAPS_DIR_PATH = EnvVar::val('SHOMGT3_MAPS_DIR_PATH');
$UPDATE_DURATION = EnvVar::val('SHOMGT3_UPDATE_DURATION');
  
// créée le répertoire $MAPS_DIR_PATH s'il n'existe pas déjà
if (!@is_dir($MAPS_DIR_PATH))
  if (!@mkdir($MAPS_DIR_PATH))
    throw new Exception("Erreur de création du répertoire $MAPS_DIR_PATH");

// $TEMP est le répertoire dans lequel sont créées les cartes avant d'être déplacées une fois terminées dans $MAPS_DIR_PATH
$TEMP = "$MAPS_DIR_PATH/../temp";
// créée le répertoire temp s'il n'existe pas déjà
if (!@is_dir($TEMP))
  if (!@mkdir($TEMP))
    throw new Exception("Erreur de création du répertoire $TEMP");
$TEMP = realpath($TEMP);

// __DIR__.'/temp' est le répertoire dans lequel sont téléchargées maps.json et cat.json
if (!@is_dir(__DIR__.'/temp')) {
  if (!@mkdir(__DIR__.'/temp'))
    throw new Exception("Erreur de création du répertoire ".__DIR__.'/temp');
}

if (($argc > 1) && ($argv[1]=='-v')) { // génération des infos de version 
  echo "Dates de dernière modification des fichiers sources:\n";
  foreach (['maketile.php', 'layers.php'] as $phpScript) {
    $result_code = null;
    $output = [];
    exec("php ".__DIR__."/$phpScript -v", $output, $result_code);
    array_shift($output);
    $VERSION[$phpScript] = Yaml::parse(implode("\n",$output));
  }
  echo Yaml::dump($VERSION);
  die();
}

/** Verrou d'utilisation pour garantir que le script n'est pas utilisé plusieurs fois simultanément.
 * 3 opération:
 *  - locked() pour connaitre l'état du verrou
 *  - lock() pour le vérouiller
 *  - unlock() pour le dévérouiller
 */
class Lock {
  const LOCK_FILEPATH = __DIR__.'/LOCK.txt';
  
  static function locked(): ?string { // Si le verrou existe alors renvoie le contenu du fichier avec la date de verrou
    if (is_file(self::LOCK_FILEPATH))
      return file_get_contents(self::LOCK_FILEPATH);
    else
      return null;
  }
  
  static function lock(): bool { // verouille, renvoie vrai si ok, false si le verrou existait déjà
    if (is_file(self::LOCK_FILEPATH))
      return false;
    else {
      file_put_contents(self::LOCK_FILEPATH, "Verrou déposé le ".date('c')."\n");
      return true;
    }
  }
  
  static function unlock(): void {
    unlink(self::LOCK_FILEPATH);
  }
};

/** stocke les informations téléchargées de $SERVER_URL/maps.json */
class UpdtMaps {
  /** @var array<string, string> $validMaps */
  static array $validMaps=[]; // liste des numéros de cartes non obsoletes trouvés dans maps.json avec leur version
  /** @var array<int, string> $obsoleteMaps */
  static array $obsoleteMaps=[]; // liste des numéros de cartes obsolètes trouvés dans maps.json
  /** @var array<int, string> $downloaded */
  static array $downloaded=[]; // liste des numéros de cartes effectivement téléchargées
  
  static function init(string $SERVER_URL): void {
    $httpCode = download("$SERVER_URL/maps.json?version=".SGSERVER_VERSION, __DIR__.'/temp/maps.json', CMDE_VERBOSE);
    if ($httpCode <> 200)
      throw new Exception("Erreur de download sur maps.json, httpCode=$httpCode");
    $maps = json_decode(file_get_contents(__DIR__.'/temp/maps.json'), true, 512, JSON_THROW_ON_ERROR);
    //unlink(__DIR__.'/temp/maps.json'); // ne pas le détruire car utilisé dans layers.php
    foreach ($maps as $mapnum => $map) {
      if (is_int($mapnum) || ctype_digit($mapnum)) { // on se limite aux cartes dont l'id est un nombre
        if ($map['status'] == 'ok') // on distingue les cartes valides de celles qui sont obsolètes
          self::$validMaps[$mapnum] = $map['lastVersion'];
        else
          self::$obsoleteMaps[] = $mapnum;
      }
    }
    //echo '$validMaps='; print_r(self::$validMaps);
    //echo '$obsoleteMaps='; print_r(self::$obsoleteMaps);
  }
};

/** lit dans le fichier layers.yaml les zones effacées et permet de les comparer par mapnum avec celles à effacer de mapcat.yaml */
class ShomGtDelZone {
  /** @var array<string, array<string, array<string, mixed>>> $deleted */
  static array $deleted=[]; // [{mapnum} => [{gtname} => {toDel}]]
  
  /** lit le fichier et structure les zones à effacer par mapnum et gtname */
  static function init(): void {
    if (is_file(__DIR__.'/../data/layers.yaml'))
      $yaml = Yaml::parseFile(__DIR__.'/../data/layers.yaml');
    else
      $yaml = [];
    foreach ($yaml as $layerName => $layer) {
      if (substr($layerName, 0, 2)<>'gt') continue; // ce n'est pas une couche
      foreach ($layer as $gtname => $gt) {
        if (isset($gt['deleted'])) {
          $mapnum = substr($gtname, 0, 4);
          self::$deleted[$mapnum][$gtname] = $gt['deleted'];
        }
      }
    }
  }
  
  // retourne pour un mapnum la définition par gtname des zones effacées de layers.yaml ou [] si aucune zone n'est définie
  /** @return array<string, array<string, array<string, mixed>>> */
  static function deleted(string $mapnum): array {
    if (isset(self::$deleted[$mapnum])) {
      $deleted = self::$deleted[$mapnum];
      ksort($deleted);
      return $deleted;
    }
    else
      return [];
  }

  /** Teste si pour un $mapnum les zones à effacer de TempMapCat sont ou non identiques aux zones effacées de layers.yaml */
  static function sameDelZones(string $mapnum): bool {
    //echo "ShomGtDelZone::deleted="; print_r(ShomGtDelZone::deleted($mapnum));
    //echo "TempMapCat::toDeleteByGtname="; print_r(TempMapCat::toDeleteByGtname("FR$mapnum"));
    return TempMapCat::toDeleteByGtname("FR$mapnum") == ShomGtDelZone::deleted($mapnum);
  }
};

/** Renvoit le libellé de la version courante de la carte $mapnum ou '' si la carte n'existe pas */
function findCurrentMapVersion(string $MAPS_DIR_PATH, string $mapnum): string {
  /* cherche un des fichiers de MD ISO dans le répertoire de carte et en extrait la version */
  $mappath = '';
  if (!is_dir("$MAPS_DIR_PATH/$mapnum")) {
    // la carte est absente des cartes courantes
    return '';
  }
  foreach (new DirectoryIterator("$MAPS_DIR_PATH/$mapnum") as $filename) {
    if ($filename == "CARTO_GEOTIFF_{$mapnum}_pal300.xml") {
      //echo "$filename\n";
      $currentMapVersion = readMapVersion("$MAPS_DIR_PATH/$mapnum/$filename");
      //echo "findCurrentMapVersion() returns $currentMapVersion[version]\n";
      return $currentMapVersion['version'];
    }
  }
  // Cas où il n'existe pas de fichier de MD ISO standard
  // j'utilise l'extension .tfw à la place de .tif car en local le .tif a été effacé
  //echo "il n'existe pas de fichier de MD ISO standard\n";
  $fileNamePerType = [];
  foreach (new DirectoryIterator("$MAPS_DIR_PATH/$mapnum") as $filename) {
    //echo "  filename=$filename\n";
    if (in_array(substr($filename, -4), ['.xml','.tfw','.pdf']) && (substr($filename, -12)<>'.png.aux.xml')) {
      //echo "$filename match condition\n";
      $fileNamePerType[substr($filename, -3)][] = (string)$filename;
    }
  }
  //print_r($fileNamePerType);
  // si il existe un seul fichier .xml je l'utilise
  if (count($fileNamePerType['xml'] ?? []) == 1) {
    $filename = $fileNamePerType['xml'][0];
    //echo "j'utilise le seul fichier .xml '$filename'\n";
    $currentMapVersion = readMapVersion("$MAPS_DIR_PATH/$mapnum/$filename");
    //echo "findCurrentMapVersion() returns $currentMapVersion[version]\n";
    return $currentMapVersion['version'];
  }
  // sinon, si il existe un seul fichier .tif
  if (count($fileNamePerType['tfw'] ?? []) == 1) {
    $filename = $fileNamePerType['tfw'][0];
    //echo "j'utilise le seul fichier .tfw '$filename'\n";
    $version = substr($filename, 5, -4); // je prends le nom du .tfw sans le numéro de carte et sans l'extension
    //echo "findCurrentMapVersion() returns $version\n";
    return $version;
  }
  // sinon, si il existe un seul fichier .pdf
  if (count($fileNamePerType['pdf'] ?? []) == 1) {
    $filename = $fileNamePerType['pdf'][0];
    $version = substr($filename, 5, -4); // je prends le nom du .pdf sans le numéro de carte et sans l'extension
    return $filename; // je prends le nom du .pdf AVEC l'extension
  }
  //echo "findCurrentMapVersion() returns 'undefined'\n";
  return 'undefined'; // sinon undefined
}

/** expanse une carte téléchargée comme 7z au path indiqué */
function expand(string $map7zpath): void {
  echo "expand($map7zpath)\n";
  $mapdir = dirname($map7zpath);
  $mapbasename = basename($map7zpath);
  !execCmde("7z x -y -o$mapdir $map7zpath", CMDE_VERBOSE) // dezip le fichier du Shom
    or throw new Exception("Erreur dans 7z x -y -o$mapdir $map7zpath");
  $mapdir = dirname($map7zpath).'/'.basename($map7zpath, '.7z');
  echo "mapdir=$mapdir\n";
  $gtiffs = []; // liste des fichiers (*.tif|*.pdf)
  foreach (new DirectoryIterator($mapdir) as $gtiff) {
    $gtiff = $gtiff->getBasename();
    //echo "gtiff=$gtiff, substr=",substr($gtiff, -4),"\n";
    if (!in_array(substr($gtiff, -4), ['.tif','.pdf'])) continue;
    $gtiffs[] = $gtiff;
  }
  //echo "gtiffs="; print_r($gtiffs);
  foreach ($gtiffs as $gtiff) {
    echo "  gtiff=$gtiff\n";
    $gtname = basename($gtiff, '.tif'); // pour un .tif je prends le basename, pour un .pdf je garde le nom entier
    !execCmde("gdalinfo -json $mapdir/$gtiff > $mapdir/$gtname.info.json", CMDE_VERBOSE) // sauvegarde du géoréf. du GéoTiff/PDF
      or throw new Exception("erreur dans gdalinfo -json $mapdir/$gtiff");
    //if (1) continue; // Pour le test je n'effectue pas les commandes suivantes
    !($result = execCmde("gdal_translate -of PNG $mapdir/$gtiff $mapdir/$gtname.png", CMDE_VERBOSE)) // conversion en PNG
      or throw new Exception("erreur dans gdal_translate sur $mapdir$gtiff, result=".json_encode($result));
    //echo "unlink(\"$mapdir/$gtiff\"); // suppression du fichier GéoTiff/PDF\n";
    unlink("$mapdir/$gtiff"); // suppression du fichier GéoTiff/PDF
    !($result = execCmde("php ".__DIR__."/maketile.php $mapdir/$gtname.png", CMDE_VERBOSE))
      or throw new Exception("erreur dans php maketile.php $mapdir/$gtname.png, result=".json_encode($result));
    //echo "unlink(\"$mapdir/$gtname.png\");\n";
    unlink("$mapdir/$gtname.png");
  }
}

/** télécharge la carte, l'expanse et l'installe dans le répertoire courant, retourne le libellé du code http */
function dlExpandInstallMap(string $SERVER_URL, string $MAPS_DIR_PATH, string $TEMP, string $mapnum): string {
  $url = "$SERVER_URL/maps/$mapnum.7z";
  //echo "\$url=$url\n";
  switch ($httpCode = download($url, "$TEMP/$mapnum.7z", CMDE_VERBOSE)) {
    case 200: { // OK
      expand("$TEMP/$mapnum.7z");
      // On copie l'ancien répertoire dans un .bak pour que le remplacement soit le plus rapide possible
      // Le répertoire .bak est supprimé ensuite
      if (is_dir("$MAPS_DIR_PATH/$mapnum"))
        rename("$MAPS_DIR_PATH/$mapnum", "$MAPS_DIR_PATH/$mapnum.bak")
          or throw new Exception("Erreur rename($MAPS_DIR_PATH/$mapnum, $MAPS_DIR_PATH/$mapnum.bak)");
      rename("$TEMP/$mapnum", "$MAPS_DIR_PATH/$mapnum")
        or throw new Exception("Erreur rename($TEMP/$mapnum, $MAPS_DIR_PATH/$mapnum)");
      if (is_dir("$MAPS_DIR_PATH/$mapnum.bak"))
        execCmde("rm -r $MAPS_DIR_PATH/$mapnum.bak &", CMDE_VERBOSE);
      unlink("/$TEMP/$mapnum.7z");
      return 'OK';
    }
    case 400: { // Bad Request
      die("Erreur $httpCode sur $mapnum.7z ligne ".__LINE__."\n");
    }
    case 401: { // Unauthorized
      die("Erreur $httpCode sur $mapnum.7z ligne ".__LINE__."\n");
    }
    case 404: { // Not Found - la carte n'a jamais existé
      echo "La carte $mapnum.7z n'a pas été téléchargée car elle n'a pas été trouvée sur le serveur\n";
      return 'Not Found';
    }
    default: return '';
  }
}

while (true) { // si $UPDATE_DURATION est défini alors le process boucle avec une attente de UPDATE_DURATION
  if ($lock = Lock::locked()) {
    die("Exécution impossible, un autre utilisateur est en train d'utiliser ce script<br>\n$lock<br>\n");
  }
  Lock::lock();
  try {
    // efface le contenu du répertoire $TEMP
    !execCmde("rm -rf $TEMP/*", CMDE_VERBOSE)
      or throw new Exception("erreur dans rm -r $TEMP/*");
    // efface le contenu du répertoire temp
    !execCmde("rm -rf ".__DIR__."/temp/*", CMDE_VERBOSE)
      or throw new Exception("erreur dans rm -r ".__DIR__."/temp/*");
  
    UpdtMaps::init($SERVER_URL); // télécharge ${SHOMGT3_SERVER_URL}/maps.json et stocke les informations
    TempMapCat::init(); // lit le fichier mapcat.yaml en le téléchargeant s'il n'existe pas
    ShomGtDelZone::init(); // lit dans le fichier layers.yaml les zones effacées pour les comparer avec celles à effacer
  
    // téléchargement des cartes et transfert au fur et à mesure dans SHOMGT3_MAPS_DIR_PATH
    foreach (UpdtMaps::$validMaps as $mapnum => $mapVersion) {
      echo "mapnum=$mapnum\n";
      $currentVersion = findCurrentMapVersion($MAPS_DIR_PATH, $mapnum);
      if ($currentVersion == $mapVersion) {
        if (ShomGtDelZone::sameDelZones($mapnum)) {
          echo "  Pas de téléchargement pour la carte $mapnum.7z car la version $mapVersion est déjà présente",
            " et les zones à effacer sont identiques\n";
          continue;
        }
        else {
          echo "Les zones à effacer dans la carte $mapnum.7z ont été modifiées donc la carte est rechargée\n";
        }
      }
      elseif (!$currentVersion) {
        echo "La carte $mapnum.7z est absente et la version proposée est $mapVersion\n";
      }
      else {
        echo "Pour la carte $mapnum.7z, la version présente est $currentVersion et la version proposée est $mapVersion\n";
      }
      if (dlExpandInstallMap($SERVER_URL, $MAPS_DIR_PATH, $TEMP, $mapnum) == 'OK')
        UpdtMaps::$downloaded[] = $mapnum;
    }

    // construction du layers.yaml dans $TEMP et si ok alors transfert dans SHOMGT3_MAPS_DIR_PATH/../
    if (execCmde("php ".__DIR__."/layers.php $TEMP/layers.yaml", CMDE_VERBOSE)) {
      echo "Erreur dans la génération de layers.yaml\n";
      Lock::unlock();
      die(1);
    }
    rename("$TEMP/layers.yaml", "$MAPS_DIR_PATH/../layers.yaml")
      or throw new Exception("Erreur rename($TEMP/layers.yaml, $MAPS_DIR_PATH/../layers.yaml)");

    // effacement du cache des tuiles s'il existe
    if (is_dir("$MAPS_DIR_PATH/../tilecache")) {
      execCmde("rm -r $MAPS_DIR_PATH/../tilecache &", CMDE_VERBOSE);
    }

    // effacement des cartes obsolètes
    foreach (UpdtMaps::$obsoleteMaps as $mapnum) {
      if (is_dir("$MAPS_DIR_PATH/$mapnum"))
        execCmde("rm -r $MAPS_DIR_PATH/$mapnum &", CMDE_VERBOSE);
    }

    echo "Fin Ok de mise à jour des cartes\n";
    Lock::unlock();
  }
  catch (Exception $e) {
    Lock::unlock();
    throw $e;
  }
  
  if (!$UPDATE_DURATION) {
    die(0);
  }
  else {
    echo "Endormissement pendant $UPDATE_DURATION jours à compter de ",date(DATE_ATOM),"\n";
    sleep(intval($UPDATE_DURATION) * 24 * 3600); // en jours
    //sleep($UPDATE_DURATION * 60); // en minutes pour tests
    echo "Réveil à ",date(DATE_ATOM),"\n";
  }
}

