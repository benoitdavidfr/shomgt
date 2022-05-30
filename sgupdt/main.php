<?php
/*PhpDoc:
title: main.php
name: main.php
doc: |
  Variable_d'Environnement:
    SHOMGT3_SERVER_URL: url du serveur de cartes en 7z
    SHOMGT3_MAPS_DIR_PATH: répertoire dans lequel les cartes expansées doivent être copiées

  cas particuliers:
    Quelques cartes ne contiennent pas de MD ISO, notamment les cartes spéciales (AEM, MANCHEGRID et limites).
    Dans ce cas, le libellé de la version est 'undefined'.
    Ainsi:
      - la carte sera initialement téléchargée et ne sera jamais mise à jour
       - il est possible de forcer une mise à jour on ajoutant cette version
         par exemple sous la forme d'un fichier mdcarte.yaml à spécifier
         Il faudra alors de lire ce fichier dans sgupdt/findCurrentMapVersion() et dans sgserver/findMapVersionIn7z()
  
  A faire:
    - ajouter une synthèse du traitement à afficher à la fin
journal: |
  30/5/2022:
    - ajout suppression du cache de tuiles
    - passage en paramètres des variables globales
    - mise en oeuvre du nouveau protocole du serveur de ce jour
  19/5/2022:
    - ajout création du répertoire $MAPS_DIR_PATH s'il n'existe pas
    - définition de valeurs par défaut pour $SERVER_URL et $MAPS_DIR_PATH
  18/5/2022:
    - évolution du code pour fonctionner en contenenur
    - utilisation des 2 variables d'environnement
      - formalisation sous la forme de variables globales en majuscules - plus faciles à utiliser
    - en php:cli le répertoire par défaut est / et non le répertoire dans lequel php est lancé
      - il faut donc que les références aux fichiers soient toutes absolues
    - création du dossier temp au démarrage s'il n'existe pas
    - transfert de temp dans SHOMGT3_MAPS_DIR_PATH
      - pour permettre le déplacement du répertoire de carte de temp vers SHOMGT3_MAPS_DIR_PATH
  16/5/2022:
    - détection de la liste des cartes obsolètes dans maps.json
    - construction du shomgt.yaml et transfert dans le répertoire data
    - effacement des cartes obsolètes
    - le bug sur 0101 provenait d'un bug de sgserver
  15/5/2022:
    - initialisation de la liste des cartes par interrogation du serveur sur /maps.json
    - test KO sur nouveau patrimoine
      - pourquoi avec ajout de incoming/20200225, obsoleteMaps=Array([0] => 0101) ?
      - l'utilisation de mapcat pour fabriquer la liste des cartes ne permet pas de tester la gestion des cartes obsolètes
  13/5/2022:
    - création initiale
    - gestion du cas particuliers des cartes sans métadonnées
    - test OK sur un patrimoine courant identique au patrimoine archivé
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/lib/envvar.inc.php';
require_once __DIR__.'/lib/execdl.inc.php';
require_once __DIR__.'/lib/readmapversion.inc.php';
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

define ('CMDE_VERBOSE', 1); // degré de verbosité de l'exécution des cmdes

// phase d'initialisation
$SERVER_URL = EnvVar::val('SHOMGT3_SERVER_URL');

$MAPS_DIR_PATH = EnvVar::val('SHOMGT3_MAPS_DIR_PATH');
// créée le répertoire $MAPS_DIR_PATH s'il n'existe pas déjà
if (!is_dir($MAPS_DIR_PATH))
  if (!mkdir($MAPS_DIR_PATH))
    throw new Exception("Erreur de création du répertoire $MAPS_DIR_PATH");

$TEMP = "$MAPS_DIR_PATH/../temp";
// créée le répertoire temp s'il n'existe pas déjà
if (!is_dir($TEMP))
  if (!mkdir($TEMP))
    throw new Exception("Erreur de création du répertoire $TEMP");
$TEMP = realpath($TEMP);

if (0) { // Test 
  echo "exécution de main.php\n";
  echo "dir=",__DIR__,"\n";
  chdir(__DIR__);
  file_put_contents('essai.txt', "essai\n");
  execCmde("whoami", 2);
  execCmde("pwd", 2);
  while (($s = readline("quoi ?")) != 'q') {
    echo "s='$s'\n";
  }
  die();
}

if (($argc > 1) && ($argv[1]=='-v')) {
  echo "Dates de dernière modification des fichiers sources:\n";
  foreach (['maketile.php', 'shomgt.php'] as $phpScript) {
    $result_code = null;
    $output = [];
    exec("php ".__DIR__."/$phpScript -v", $output, $result_code);
    array_shift($output);
    $VERSION[$phpScript] = Yaml::parse(implode("\n",$output));
  }
  echo Yaml::dump($VERSION);
  die();
}

 
class Maps { // stocke les informations téléchargées de {SHOMGT3_SERVER_URL}/maps.json
  static array $validMaps=[]; // liste des numéros de cartes non obsoletes trouvés dans maps.json avec leur version
  static array $obsoleteMaps=[]; // liste des numéros de cartes obsolètes trouvés dans maps.json
  static array $downloaded=[]; // liste des numéros de cartes effectivement téléchargées
  
  static function init(string $SERVER_URL): void {
    if (!is_dir(__DIR__.'/temp')) mkdir(__DIR__.'/temp');
    $httpCode = download("$SERVER_URL/maps.json", __DIR__.'/temp/maps.json', CMDE_VERBOSE);
    if ($httpCode <> 200)
      throw new Exception("Erreur de download sur maps.json, httpCode=$httpCode");
    $maps = json_decode(file_get_contents(__DIR__.'/temp/maps.json'), true, 512, JSON_THROW_ON_ERROR);
    //unlink(__DIR__.'/temp/maps.json'); // ne pas le détruire car utilisé dans shomgt.php
    foreach ($maps as $mapnum => $map) {
      if (is_int($mapnum) || ctype_digit($mapnum)) { // on se limite aux cartes dont l'id est un nombre
        if ($map['status'] == 'ok') // on distingue les cartes valides de celles qui sont obsolètes
          self::$validMaps[$mapnum] = $map['mostRecentVersion'];
        else
          self::$obsoleteMaps[] = $mapnum;
      }
    }
    //echo '$validMaps='; print_r(self::$validMaps);
    //echo '$obsoleteMaps='; print_r(self::$obsoleteMaps);
  }
};
Maps::init($SERVER_URL);

// Renvoit le libellé de la version courante de la carte $mapnum ou '' si la carte n'existe pas
// ou 'undefined' si aucun fichier de MD n'est trouvé
function findCurrentMapVersion(string $MAPS_DIR_PATH, string $mapnum): string {
  /* cherche un des fichiers de MD ISO dans le répertoire de carte et en extrait la version */
  $mappath = '';
  if (!is_dir("$MAPS_DIR_PATH/$mapnum")) {
    // la carte est absente des cartes courantes
    return '';
  }
  foreach (new DirectoryIterator("$MAPS_DIR_PATH/$mapnum") as $filename) {
    if (preg_match('!^CARTO_GEOTIFF_\d+_[^.]+\.xml!', $filename)) {
      //echo "$filename\n";
      $currentMapVersion = readMapVersion("$MAPS_DIR_PATH/$mapnum/$filename");
      //echo "currentMapVersion=$currentMapVersion\n";
      return $currentMapVersion;
    }
  }
  return 'undefined';
}

function expand(string $map7zpath) { // expansion d'une carte téléchargée comme 7z au path indiqué
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
    !execCmde("gdalinfo $mapdir/$gtiff > $mapdir/$gtname.info", CMDE_VERBOSE) // sauvegarde du géoréférencement du GéoTiff/PDF
      or throw new Exception("erreur dans gdalinfo $mapdir/$gtiff");
    //if (1) continue; // Pour le test je n'effectue pas les commandes suivantes
    !execCmde("gdal_translate -of PNG $mapdir/$gtiff $mapdir/$gtname.png", CMDE_VERBOSE) # conversion du GéoTiff/PDF en PNG
      or throw new Exception("erreur dans gdal_translate sur $mapdir$gtiff");
    //echo "unlink(\"$mapdir/$gtiff\"); // suppression du fichier GéoTiff/PDF\n";
    unlink("$mapdir/$gtiff"); // suppression du fichier GéoTiff/PDF
    !execCmde("php ".__DIR__."/maketile.php $mapdir/$gtname.png", CMDE_VERBOSE)
      or throw new Exception("erreur dans php maketile.php $mapdir/$gtname.png");
    //echo "unlink(\"$mapdir/$gtname.png\");\n";
    unlink("$mapdir/$gtname.png");
  }
}

// télécharge la carte, l'expanse et l'installe dans le répertoire courant, retourne le libellé du code http
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
    case 404: { // Not Found - la carte n'a jamais existé
      echo "La carte $mapnum.7z n'a pas été téléchargée car elle n'existe pas sur le serveur\n";
      return 'Not Found';
    }
  }
}

// téléchargement des cartes et transfert au fur et à mesure dans SHOMGT3_MAPS_DIR_PATH
foreach (Maps::$validMaps as $mapnum => $mapVersion) {
  echo "mapnum=$mapnum\n";
  $currentVersion = findCurrentMapVersion($MAPS_DIR_PATH, $mapnum);
  if ($currentVersion == $mapVersion)
    echo "Pas de téléchargement pour la carte $mapnum.7z car la version $mapVersion est déjà présente\n";
  elseif (dlExpandInstallMap($SERVER_URL, $MAPS_DIR_PATH, $TEMP, $mapnum) == 'OK')
    Maps::$downloaded[] = $mapnum;
}

// construction du shomgt.yaml dans $TEMP et si ok alors transfert dans SHOMGT3_MAPS_DIR_PATH/../
!execCmde("php ".__DIR__."/shomgt.php $TEMP/shomgt.yaml", CMDE_VERBOSE)
  or throw new Exception("Erreur dans la génération de shomgt.yaml");
rename("$TEMP/shomgt.yaml", "$MAPS_DIR_PATH/../shomgt.yaml")
  or throw new Exception("Erreur rename($TEMP/shomgt.yaml, $MAPS_DIR_PATH/../shomgt.yaml)");

// effacement du cache des tuiles s'il existe
if (is_dir("$MAPS_DIR_PATH/../tilecache")) {
  execCmde("rm -r $MAPS_DIR_PATH/../tilecache &", CMDE_VERBOSE);
}

// effacement des cartes obsolètes
foreach (Maps::$obsoleteMaps as $mapnum) {
  if (is_dir("$MAPS_DIR_PATH/$mapnum"))
    execCmde("rm -r $MAPS_DIR_PATH/$mapnum &", CMDE_VERBOSE);
}

echo "Fin Ok de mise à jour des cartes\n";
