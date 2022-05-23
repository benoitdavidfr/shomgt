<?php
/*PhpDoc:
title: main.php
name: main.php
doc: |
  Variable_d'Environnement:
    SHOMGT3_SERVER_URL: url du serveur de cartes en 7z
    SHOMGT3_MAPS_DIR_PATH: répertoire dans lequel les cartes expansées doivent être copiées
  algorithme:
    TANTQUE vrai FAIRE # boucle perpétuelle
      télécharger {SHOMGT3_SERVER_URL}/maps.json
      déduire de maps.json la liste des cartes non obsolètes
      POUR CHAQUE carte FAIRE
        SI la carte n'existe pas ALORS
          télécharger dans temp la carte depuis le serveur sans paramètre
        SINON
          télécharger la carte dans temp en indiquant en paramètres son édition et sa dernière correction
        FINSI
        SI une carte 7z a été téléchargée ALORS
          dézipper le 7z
          effacer le 7z
          POUR chaque tif/pdf FAIRE
            générer le .info
            générer le .png
            effacer le .tif/pdf
            daller le png
            effacer le png
          FINFAIRE
          transférer le répertoire de la carte dans les données courantes
        FINSI
      FIN FAIRE 
      SI au moins une carte a été téléchargée ou il existe au moins une carte obsolète ALORS
        générer shomgt_temp.yaml en en excluant les cartes obsolètes
        SI shomgt_temp.yaml n'est pas conforme à son schéma ALORS
          transmettre une erreur et s'arrêter
        FINSI
        remplacer shomgt.yaml par shomgt_temp.yaml
        effacer les cartes obsolètes
        effacer le contenu du cache de tuiles
      FINSI
      s'endormir SHOMGT3_UPDATE_DURATION
    FIN_FAIRE

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
    - tester dans un conteneur
journal: |
  19/5/2022:
    - ajout création du répertoire $SHOMGT3_MAPS_DIR_PATH s'il n'existe pas
    - définition de valeurs par défaut pour $SHOMGT3_SERVER_URL et $SHOMGT3_MAPS_DIR_PATH
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
require_once __DIR__.'/lib/envvar.inc.php';
require_once __DIR__.'/lib/execdl.inc.php';
require_once __DIR__.'/lib/readmapversion.inc.php';
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

define ('CMDE_VERBOSE', 1); // degré de verbosité de l'exécution des cmdes

// phase d'initialisation
$SHOMGT3_SERVER_URL = EnvVar::val('SHOMGT3_SERVER_URL');

$SHOMGT3_MAPS_DIR_PATH = EnvVar::val('SHOMGT3_MAPS_DIR_PATH');
// créée le répertoire $SHOMGT3_MAPS_DIR_PATH s'il n'existe pas déjà
if (!is_dir($SHOMGT3_MAPS_DIR_PATH))
  if (!mkdir($SHOMGT3_MAPS_DIR_PATH))
    throw new Exception("Erreur de création du répertoire $SHOMGT3_MAPS_DIR_PATH");

$TEMP = "$SHOMGT3_MAPS_DIR_PATH/../temp";
echo "TEMP=$TEMP\n";
// créée le répertoire temp s'il n'existe pas déjà
if (!is_dir($TEMP))
  if (!mkdir($TEMP))
    throw new Exception("Erreur de création du répertoire $TEMP");
$TEMP = realpath($TEMP);

if (0) {
  echo "SHOMGT3_SERVER_URL='$SHOMGT3_SERVER_URL'\n";
  echo "SHOMGT3_MAPS_DIR_PATH='$SHOMGT3_MAPS_DIR_PATH'\n";
  echo "TEMP='$TEMP'\n";
  while (($s = readline("quoi ?")) != 'q') {
    echo "s='$s'\n";
  }
  die();
}

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

 
class Maps { // stocke les informations téléchargées de {SHOMGT3_SERVER_URL}/maps.json
  static array $mapNums=[]; // liste des numéros de cartes non obsoletes trouvés dans maps.json
  static array $obsoleteMaps=[]; // liste des numéros de cartes obsolètes trouvés dans maps.json
  static array $downloaded=[]; // liste des numéros de cartes effectivement téléchargées
  
  static function init(): void {
    global $SHOMGT3_SERVER_URL;
    if (!is_dir(__DIR__.'/temp')) mkdir(__DIR__.'/temp');
    $httpCode = download("$SHOMGT3_SERVER_URL/maps.json", __DIR__.'/temp/maps.json', CMDE_VERBOSE);
    if ($httpCode <> 200)
      throw new Exception("Erreur de download sur maps.json, httpCode=$httpCode");
    $maps = json_decode(file_get_contents(__DIR__.'/temp/maps.json'), true, 512, JSON_THROW_ON_ERROR);
    //unlink(__DIR__.'/temp/maps.json'); // ne pas le détruire car utilisé dans shomgt.php
    foreach ($maps as $mapnum => $map) {
      if (is_int($mapnum) || ctype_digit($mapnum)) { // on se limite aux cartes dont l'id est un nombre
        if ($map['status'] == 'ok') // et uniquement ces cartes non obsolètes
          self::$mapNums[] = $mapnum;
        else
          self::$obsoleteMaps[] = $mapnum;
      }
    }
    //self::$mapNums = [];
  }
};
Maps::init();

// Renvoit le libellé de la version courante de la carte $mapnum ou '' si la carte n'existe pas
// ou 'undefined' si aucun fichier de MD n'est trouvé
function findCurrentMapVersion(string $mapnum): string {
  global $SHOMGT3_MAPS_DIR_PATH;
  /* cherche un des fichiers de MD ISO dans le répertoire de carte et en extrait la version */
  $mappath = '';
  if (!is_dir("$SHOMGT3_MAPS_DIR_PATH/$mapnum")) {
    // la carte est absente des cartes courantes
    return '';
  }
  foreach (new DirectoryIterator("$SHOMGT3_MAPS_DIR_PATH/$mapnum") as $filename) {
    if (preg_match('!^CARTO_GEOTIFF_\d+_[^.]+\.xml!', $filename)) {
      //echo "$filename\n";
      $currentMapVersion = readMapVersion("$SHOMGT3_MAPS_DIR_PATH/$mapnum/$filename");
      //echo "currentMapVersion=$currentMapVersion\n";
      return $currentMapVersion;
    }
  }
  return 'undefined';
}

function expand(string $map7zpath) { // expansion d'une carte téléchargée comme 7z au path indiqué
  /*
  echo "map=$map"
  7z x -y $map # dezip le fichier du Shom
  mapdir=`basename $map .7z`
  for gtiff in `ls $mapdir/?(*.tif|*.pdf)`; do
    # echo "gtiff=$gtiff"
    gtname=`basename $gtiff .tif`
    gdalinfo $gtiff > $mapdir/$gtname.info # sauvegarde du géoréférencement du GéoTiff/PDF
    echo "conversion $gtiff en PNG"
    gdal_translate -of PNG $gtiff $mapdir/$gtname.png || exit # conversion du GéoTiff/PDF en PNG
    rm $gtiff # suppression du fichier GéoTiff/PDF
    php ../maketile.php $mapdir/$gtname.png || exit # découpage en dalles du PNG
    rm $mapdir/$gtname.png # suppression du gros fichier .png
  done
  */
  
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
function dlExpandInstallMap(string $mapnum): string {
  global $SHOMGT3_SERVER_URL, $SHOMGT3_MAPS_DIR_PATH, $TEMP;
  $version = findCurrentMapVersion($mapnum);
  $url = "$SHOMGT3_SERVER_URL/map/$mapnum" . ($version ? "/newer/$version" : '').'.7z';
  //echo "\$url=$url\n";
  switch ($httpCode = download($url, "$TEMP/$mapnum.7z", CMDE_VERBOSE)) {
    case 200: { // OK
      expand("$TEMP/$mapnum.7z");
      // On copie l'ancien répertoire dans un .bak pour que le remplacement soit le plus rapide possible
      // Le répertoire .bak est supprimé ensuite
      if (is_dir("$SHOMGT3_MAPS_DIR_PATH/$mapnum"))
        rename("$SHOMGT3_MAPS_DIR_PATH/$mapnum", "$SHOMGT3_MAPS_DIR_PATH/$mapnum.bak")
          or throw new Exception("Erreur rename($SHOMGT3_MAPS_DIR_PATH/$mapnum, $SHOMGT3_MAPS_DIR_PATH/$mapnum.bak)");
      rename("$TEMP/$mapnum", "$SHOMGT3_MAPS_DIR_PATH/$mapnum")
        or throw new Exception("Erreur rename($TEMP/$mapnum, $SHOMGT3_MAPS_DIR_PATH/$mapnum)");
      if (is_dir("$SHOMGT3_MAPS_DIR_PATH/$mapnum.bak"))
        execCmde("rm -r $SHOMGT3_MAPS_DIR_PATH/$mapnum.bak &", CMDE_VERBOSE);
      unlink("/$TEMP/$mapnum.7z");
      return 'OK';
    }
    case 204: { // No Content
      echo "Pas de téléchargement pour la carte $mapnum.7z car la version du serveur est déjà présente\n";
      return 'No Content';
    }
    case 400: { // Bad Request
      die("Erreur $httpCode sur $mapnum.7z ligne ".__LINE__."\n");
    }
    case 404: { // Not Found - la carte n'a jamais existé
      echo "La carte $mapnum.7z n'a pas été téléchargée car elle n'existe pas sur le serveur\n";
      return 'Not Found';
    }
    case 410: { // Gone - la carte a existé mais est maintenant obsolète
      echo "La carte $mapnum.7z est obsolète, elle sera supprimée\n";
      return 'Gone';
    }
  }
}

// téléchargement des cartes et transfert au fur et à mesure dans SHOMGT3_MAPS_DIR_PATH
foreach (Maps::$mapNums as $mapnum) {
  echo "mapnum=$mapnum\n";
  if (dlExpandInstallMap($mapnum) == 'OK')
    Maps::$downloaded[] = $mapnum;
}

// construction du shomgt.yaml dans $TEMP et si ok alors transfert dans SHOMGT3_MAPS_DIR_PATH/../
!execCmde("php ".__DIR__."/shomgt.php $TEMP/shomgt.yaml", CMDE_VERBOSE)
  or throw new Exception("Erreur dans la génération de shomgt.yaml");
rename("$TEMP/shomgt.yaml", "$SHOMGT3_MAPS_DIR_PATH/../shomgt.yaml")
  or throw new Exception("Erreur rename($TEMP/shomgt.yaml, $SHOMGT3_MAPS_DIR_PATH/../shomgt.yaml)");

// effacement des cartes obsolètes
foreach (Maps::$obsoleteMaps as $mapnum) {
  if (is_dir("$SHOMGT3_MAPS_DIR_PATH/$mapnum"))
    execCmde("rm -r $SHOMGT3_MAPS_DIR_PATH/$mapnum &", CMDE_VERBOSE);
}

echo "Fin Ok de mise à jour des cartes\n";
