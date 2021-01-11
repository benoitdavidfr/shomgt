<?php
/*PhpDoc:
name: store.inc.php
title: store.inc.php - regroupement des fonctions d'accès au stockage des cartes courantes et de l'historique des livraisons
classes:
doc: |
  Le stockage est dans le répertoire ./../../../shomgeotiff dans 2 sous-répertoires décrits ci-dessous.
journal: |
  4-5/1/2021:
    - création
includes: [SevenZipArchive.php]
*/

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/SevenZipArchive.php';

use Symfony\Component\Yaml\Yaml;

if (__FILE__ == realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) { // code de test des classes
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>store</title></head><body><pre>\n";
}


/*PhpDoc: classes
name: store.inc.php
title: class CurrentGeoTiff - Un objet correspond à un GéoTiff stocké dans un sous-répertoire du répertoire current
methods:
doc: |
  Les GéoTiff de la cartothèque courante (cad des cartes utilisées dans les services) sont stockés
  dans le répertoire ./../../../shomgeotiff/current
  avec 1 sous-répertoire par carte nommé par le numéro de la carte {num} contenant
    - {num}.png - miniature de la carte
    - {num}_pal300 - répertoire des dalles de l'espace principal (ssi il en existe un)
    - {num}_pal300.info - issu de galinfo (idem)
    - CARTO_GEOTIFF_{num}_pal300.xml - MD ISO du GéoTiff de l'espace principal (ssi il en existe un)
    - {num}_pal300.png.aux.xml - ???
    - {num}_pal300.gt - ???
    - {num}_{partid}_gtw - répertoire des dalles du cartouche {partid} (ssi il en existe)
      avec {partid} numéroté
        - soit par un entier à partir de 1, ex: 6948_1_gtw
        - soit par une lettre à partir de A, ex: 6903_A_gtw
    - {num}_{partid}_gtw.info
    - CARTO_GEOTIFF_{num}_{partid}_gtw.xml
    - {num}_{partid}_gtw.gt
    - {num}_{partid}_gtw.aux.xml
  Un GéoTiff est identifié par un nom de la forme {num}/{num}_pal300 ou {num}/{num}_{partid}_gtw
  
  Les cartes AEM 2017 et la carte MancheGrid ne respectent pas ces standards
    - le nom du géoTiff respecte le motif {num}/{num}_{YYYY}.tif pour les cartes AEM et {num}/{num}.tif pour la carte MancheGrid
      où {YYYY} est l'année de publication de la carte
    - il n'y a pas de fichier xml

  Les cartes AEM 2019 et la carte des zones ne respectent pas ces standards
    - il n'y a pas de GéoTiff mais un pdf non géoréférencé, son nom respecte le motif {num}/{num}_{YYYY}

  La classe CurrentGeoTiff permet d'accéder à la liste des cartes courantes
*/
class CurrentGeoTiff {
  const PATH = __DIR__.'/../../../shomgeotiff/current/';
  // traitement des cartes n'ayant pas de MD et portant un nom spécifique
  const MDISOAEM = [
    '7330/7330_2016'=> [
      'title'=> "Action de l'Etat en mer en zone maritime Atlantique",
      'mdDate'=> '2016-01-11',
      'edition'=> "Publication 1995 - Edition n° 6 - 2016",
      'lastUpdate'=> '0',
    ],
    '7344/7344_2016'=> [
      'title'=> "Action de l'Etat en mer, Zone Manche et mer du Nord",
      'mdDate'=> '2016-01-15',
      'edition'=> "Publication 1992 - Edition n° 6 - 2016",
      'lastUpdate'=> '0',
    ],
    '7360/7360_2016'=> [
      'title'=> "Action de l'Etat en mer, Zone Méditerranée",
      'mdDate'=> '2016-01-15',
      'edition'=> "Publication 1995 - Edition n° 6 - 2016",
      'lastUpdate'=> '0',
    ],
    '8502/8502_2010'=> [
      'title'=> "Action de l'Etat en mer en zone maritime Sud de l'Océan Indien (ZMSOI)",
      'mdDate'=> '2010-01-01',
      'edition'=> "Publication 2010",
      'lastUpdate'=> '0',
    ],
    '8509/8509_2015'=> [
      'title'=> "Action de l''Etat en Mer - Nouvelle-Calédonie - Wallis et Futuna",
      'mdDate'=> '2018-01-18',
      'edition'=> "Publication 2015",
      'lastUpdate'=> '0',
    ],
    '8517/8517_2015'=> [
      'title'=> "Carte simplifiée de l''action de l''Etat en Mer des ZEE Polynésie Française et Clipperton",
      'mdDate'=> '2018-01-18',
      'edition'=> "Publication 2015",
      'lastUpdate'=> '0',
    ],
    '8101/8101'=> [
      'title'=> "MancheGrid, carte générale",
      'mdDate'=> '2010-10-23',
      'edition'=> "Publication 2010",
      'lastUpdate'=> '0',
    ],
    '8510/8510_2015'=> [
      'title'=> "Délimitations des zones maritimes",
      'mdDate'=> '2015-03-10',
      'edition'=> "Publication 2015",
      'lastUpdate'=> '0',
    ],
  ];
  // association num -> gtname pour les cartes spéciales
  const NUMAEM = [
    '7330'=> '7330/7330_2016', // AEM Atlantique
    '7344'=> '7344/7344_2016', // AEM Manche et mer du Nord
    '7360'=> '7360/7360_2016', // AEM Méditerranée
    '8502'=> '8502/8502_2010', // AEM Sud de l'Océan Indien
    '8509'=> '8509/8509_2015', // AEM Nouvelle-Calédonie - Wallis et Futuna'
    '8517'=> '8517/8517_2015', // AEM Polynésie Française et Clipperton
    '8101'=> '8101/8101', // Manche Grid
    '8510'=> '8510/8510_2015', // Délimitations des zones maritimes
  ];
  
  public string $gtname; // la clé du géotiff dans shomgt.yaml
  
  static function mapExists(string $num): string { // Si une carte existe alors retourne son répertoire dans current, sinon '' 
    return file_exists(self::PATH.$num) ? self::PATH.$num : '';
  }
  
  static function listOfMaps(): array { // retourne la liste des numéros des cartes présentes dans current
    $list = [];
    foreach (new DirectoryIterator(self::PATH) as $mapFileInfo) {
      $filename = $mapFileInfo->getFilename();
      if (!in_array($filename, ['.','..','.DS_Store']))
        $list[] = $filename;
    }
    return $list;
  }
  
  /*PhpDoc: methods
  name: mdiso19139FromFilePath
  title: "static function mdiso19139FromFilePath(string $path): array - récupère élts MD ISO19139 à partir du chemin du fichier XML"
  doc: |
    Code commun à CurrentGeoTiff::mdiso19139() et à SevenZipMap::mdiso19139()
    Retourne un array ayant comme propriétés
      - mdDate - date de mise à jour des métadonnées
      - edition - édition de la carte, ex: Edition n° 4 - 2015, Publication 1984
      - lastUpdate - dernière correction indiquée dans les MD , un entier transmis comme string
    retourne [] si le fichier est absent
  */
  static function mdiso19139FromFilePath(string $path): array {
    if (!file_exists($path))
      return [];
    if (!($xmlmd = @file_get_contents($path)))
      return [];
  
    $pattern = '!<gmd:dateStamp>\s*<gco:DateTime[^>]*>([^<]*)</gco:DateTime>\s*</gmd:dateStamp>!';
    if (!preg_match($pattern, $xmlmd, $matches)) {
      echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
      die();
    }
    $md['mdDate'] = $matches[1];

    $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
    if (!preg_match($pattern, $xmlmd, $matches)) {
      echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
      die();
    }
    $edition = $matches[1];
    if (preg_match('!^(.* - \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Edition n° 4 - 2015 - Dernière correction : 12
      $md += ['edition'=> $matches[1], 'lastUpdate'=> $matches[2]];
    elseif (preg_match('!^(.* \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Publication 1984 - Dernière correction : 101
      $md += ['edition'=> $matches[1], 'lastUpdate'=> $matches[2]];
    else
      $md += ['edition'=> $edition];

    return $md;
  }
  
  // récupère les MD ISO d'un des GéoTiff à partir du numéro de la carte
  static function mdiso19139FromNum(string $num): array {
    if (isset(self::NUMAEM[$num])) // 5 cartes présentes sans MDISO
      return (new self(self::NUMAEM[$num]))->mdiso19139();
    elseif ($mdiso19139 = (new self("$num/${num}_pal300"))->mdiso19139())
      return $mdiso19139;
    elseif ($mdiso19139 = (new self("$num/${num}_1_gtw"))->mdiso19139())
      return $mdiso19139;
    elseif ($mdiso19139 = (new self("$num/${num}_A_gtw"))->mdiso19139())
      return $mdiso19139;
    else
      throw new Exception("MD ISO absentes dans CurrentGeoTiff::mdiso19139FromNum() pour num='$num'");
  }
  
  function __construct(string $gtname) { $this->gtname = $gtname; }

  /*PhpDoc: methods
  name: mdiso19139
  title: "function mdiso19139(): array - récupère des éléments des MD ISO19139 du GéoTIFF"
  doc: |
    Retourne un array ayant comme propriétés
      - mdDate - date de mise à jour des métadonnées
      - edition - édition de la carte, ex: Edition n° 4 - 2015, Publication 1984
      - lastUpdate - dernière correction indiquée dans les MD , un entier transmis comme string
    retourne [] si le fichier est absent
  */
  function mdiso19139(): array {
    if (isset(self::MDISOAEM[$this->gtname]))
      return self::MDISOAEM[$this->gtname];
    $mdname = str_replace('/', '/CARTO_GEOTIFF_', $this->gtname);
    return self::mdiso19139FromFilePath(self::PATH."$mdname.xml");
  }
  
  static function testClass() {
    if (!isset($_GET['class']))
      echo "<a href='?class=CurrentGeoTiff'>Test CurrentGeoTiff</a>\n";
    elseif ($_GET['class']=='CurrentGeoTiff') {
      foreach (['7442/7442_pal300','8502/8502_2010'] as $gtname)
        echo "$gtname: ", json_encode(
          (new CurrentGeoTiff($gtname))->mdiso19139(),
          JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        ),"\n";
    }
  }
};

if (__FILE__ == realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) { // Tests unitaires de la classe
  CurrentGeoTiff::testClass();
}

/*PhpDoc: classes
name: SevenZipMap
title: "class SevenZipMap extends SevenZipArchive - une carte Shom 7zippée dans une livraison archivée"
methods:
doc: |
  Les livraisons sont archivées dans le répertoire ./../../../shomgeotiff/incoming
  avec 1 sous-répertoire par livraison
    - nommé par la date de mise à disposition des données Shom sous la forme YYYYMMDD
    - avec un suffixe a,b, ... si la livraison doit être répartie en plusieurs répertoires
    - ou par un intervalle de dates de la forme YYYYMMDD--YYYYMMDD si plusieurs livraison sont agrégées
    - ou d'autres motifs dans des cas particuliers
      - 201707cartesAEM pour les cartes AEM livrées en 2017 ainsi que la carte MancheGrid
      - 201911cartesAEM pour les cartes AEM livrées en 2919 ainsi que la carte des délimitations des zones maritimes
  Ce sous-répertoire de livraison contient
    - éventuellement un fichier index.yaml contenant:
      - un champ 'title' décrivant la livraison
      - un champ 'maps' listant sous la forme d'un texte la liste des cartes de la livraison
      - un éventuel champ 'toDelete' listant les cartes obsolètes à supprimer sous la forme d'un array
        dont les clés sont les id des cartes obsolètes et les valeurs décrivent la carte et la raison de son obsolescence
    - un fichier 7z par carte nommé {num}.7z contenant
      - {num}.png - miniature de la carte
      - ssi il existe un espace principal
        - {num}_pal300.tif - fichier GéoTiff de l'espace principal
        - {num}_pal300.gt - fichier de MD GéoTiff de l'espace principal
        - CARTO_GEOTIFF_{num}_pal300.xml - MD ISO du GéoTiff de l'espace principal
      - pour chaque cartouche {partid} (s'il en existe), avec {partid} soit un entier soit une lettre
        - {num}_{partid}_gtw.tif - fichier GéoTiff du cartouche {partid}
        - {num}_{partid}_gtw.gt - fichier de MD GéoTiff du cartouche {partid}
        - CARTO_GEOTIFF_{num}_{partid}_gtw.xml - MD ISO du GéoTiff du cartouche {partid}
    - les fichiers 7z des cartes spéciales (AEM + MoncheGrid + zones maritimes) ont une structure particulière, ils contiennent:
      - soit un fichier GéoTiff dont le nom respecte le motif {num}/{num}_{YYYY}.tif
      - soit un fichier PDF dont le nom respecte le motif {num}/{num}_{YYYY}.pdf
      - soit un fichier GéoTiff dont le nom respecte le motif {num}/{num}.tif pour la carte MoncheGrid

  La classe SevenZipMap permet d'accéder aux livraisons, au cartes des livraisosn et d'obtenir des caractéristiques de ces cartes.
*/
class SevenZipMap extends SevenZipArchive {
  const PATH = __DIR__.'/../../../shomgeotiff/incoming/';
  protected string $path; // "${delivName}/${mapnum}"
  
  static function listOfDeliveries(): array { // retourne la liste des livraisons, chacune définie par le nom du répertoire
    $list = [];
    foreach (new DirectoryIterator(self::PATH) as $delivFileInfo) { // $delivFileInfo correspond à une livraison
      $delivName = $delivFileInfo->getFilename();
      if (!in_array($delivName, ['.','..','.DS_Store']))
        $list[] = $delivName;
    }
    return $list;
  }
  
  static function obsoleteMaps(string $delivName): array { // retourne le champ todelete du fichier index.yaml s'il existe
    if (!is_file(self::PATH."$delivName/index.yaml"))
      return [];
    $yaml = Yaml::parseFile(self::PATH."$delivName/index.yaml");
    return $yaml['toDelete'] ?? [];
  }
  
  static function listOfmaps(string $delivName): array { // retourne la liste des cartes d'une livraison, chacune comme SevenZipMap 
    $list = [];
    foreach (new DirectoryIterator(SevenZipMap::PATH.$delivName) as $mapzFileInfo) { // $mapzFileInfo -> une carte zippée
      $mapzName = $mapzFileInfo->getFilename();
      if (preg_match('!^(\d+).7z$!', $mapzName, $matches))
        $list[] = new SevenZipMap("$delivName/$matches[1]");
    }
    return $list;
  }
  
  function __construct(string $path) { // path est composé du nom de la livraison suivi de '/' et du numéro de carte
    $this->path = $path;
    parent::__construct(realpath(self::PATH."$path.7z"));
  }
  
  function __toString() { return $this->path; }
  
  function mapnum(): string { return explode('/', $this->path)[1]; }
  
  /*PhpDoc: methods
  name: mdiso19139
  title: "function mdiso19139(): array - récupère des éléments des MD ISO19139 d'un des GéoTIFF de la carte"
  doc: |
    Retourne un array ayant comme propriétés
      - mdDate - date de mise à jour des métadonnées
      - édition - édition de la carte, ex: Edition n° 4 - 2015, Publication 1984
      - dernièreCorrection - dernière correction indiquée dans les MD , un entier transmis comme string
    retourne une exception si aucun fichier de MD n'est présent dans la carte
  */
  function mdiso19139(): array {
    if (isset(CurrentGeoTiff::NUMAEM[$this->mapnum()]))
      return CurrentGeoTiff::MDISOAEM[CurrentGeoTiff::NUMAEM[$this->mapnum()]];
    $filepath = null;
    foreach ($this as $entry) { // cherche une entrée ayant comme .xml comme suffixe
      //print_r($entry);
      if (preg_match('!\.xml$!', $entry['Name'])) {
        $filepath = $entry['Name'];
        break;
      }
    }
    if (!$filepath)
      throw new Exception("Erreur, aucun fichier xml trouvé dans CarteZip::mdiso19139()");
    $this->extractTo('.', $filepath);
    $mdiso19139 = CurrentGeoTiff::mdiso19139FromFilePath($filepath);
    //echo "filepath=$filepath\n";
    unlink($filepath);
    rmdir(dirname($filepath));
    return $mdiso19139;
  }
  
  function readfile(): void { // lit le fichier 7z pour un téléchargement
    header('Access-Control-Allow-Origin: *');
    header('Content-type: application/x-7z-compressed');
    readfile($this->file);
  }
  
  static function testClass() {
    if (!isset($_GET['class']))
      echo "<a href='?class=SevenZipMap'>Test SevenZipMap</a>\n";
    elseif (($_GET['class']=='SevenZipMap') && !isset($_GET['deliv']) && !isset($_GET['map'])) {
      echo "listOfDeliveries:\n";
      foreach (SevenZipMap::listOfDeliveries() as $delivName) {
        echo "  - <a href='?class=SevenZipMap&deliv=$delivName'>$delivName</a>\n";
      }
    }
    elseif (($_GET['class']=='SevenZipMap') && isset($_GET['deliv'])) {
      echo "listOfMaps($_GET[deliv]):\n";
      foreach (SevenZipMap::listOfMaps($_GET['deliv']) as $map) {
        echo "  - <a href='?class=SevenZipMap&map=$map'>$map</a>\n";
      }
    }
    elseif (($_GET['class']=='SevenZipMap') && isset($_GET['map'])) {
      $map = new SevenZipMap($_GET['map']);
      echo "$map: ", json_encode($map->mdiso19139(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
    }
  }
};



if (__FILE__ == realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) { // Tests unitaires de la classe
  SevenZipMap::testClass();
}
