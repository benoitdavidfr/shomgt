<?php
/*PhpDoc:
name: dl.php
title: dl.php - téléchargement d'une image png ou tiff, éventuellement rognée, à partir de l'archive de la carte
doc: |
  Ss paramètre génère un document JSON listant les GéoTiff du portefeuille avec des liens vers les différents formats
  Le paramètre est fourni en PATH_INFO, plusieurs sont possibles:
    - /html : le listing en HTML
    - /{manum}.7z : téléchargement de l'archive de la carte {mapnum}
    - /{manum}.png : affichage de la mini vue de la carte {mapnum}
    - /{gtname}.tif : téléchargement du GéoTiff {gtname} en format GéoTiff
    - /{gtname}.png : affichage du GéoTiff {gtname} en format PNG
    - /{gtname}.xml : affichage des MD ISO du GéoTiff {gtname} en format XML
    - /{gtname}.json : affichage de MD du catalogue shomgt.yaml du GéoTiff {gtname} en format JSON
    - /{gtname}.crop.tif : téléchargement du GéoTiff {gtname} rogné en format GéoTiff
    - /{gtname}.crop.png : affichage du GéoTiff {gtname} rogné en format PNG
  Le script utilise le catalogue shomgt.yaml et les données contenues dans l'archive 7z qui est dézippée à la volée
  pour en extraire le fichier nécessaire qui est ensuite éventuellement converti.
journal: |
  30/3/2019
    création
includes: [geotiff.inc.php]
*/
//ini_set('memory_limit', '12800M');
require_once __DIR__.'/geotiff.inc.php';

// Liste des GéoTiff du catalogue en JSON
if (!isset($_SERVER['PATH_INFO']) || !$_SERVER['PATH_INFO']) {
  //echo "<!DOCTYPE HTML><html><head><title>crop</title><meta charset='UTF-8'></head><body><pre>\n";
  GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');
  $gts = [];
  foreach (GeoTiff::geojson()['features'] as $feature) {
    $prop = $feature['properties'];
    if (in_array($prop['gtname'], ['0101bis/0101_pal300']))
      continue;
    if (preg_match('!-East$!', $prop['gtname']))
      continue;
    $href = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/$prop[gtname]";
    $hrefmap = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/".substr($prop['gtname'],0,4);
    $shortTitle = (mb_strlen($prop['title']) < 45) ? $prop['title'] : (mb_substr($prop['title'], 0, 40).' ...');
    $gts[$shortTitle] = [
      'title'=> $prop['title'],
      'edition'=> $prop['edition'],
      'scaleden'=> $prop['scaleden'],
      '7z'=> $hrefmap.'.7z',
      'mini'=> $hrefmap.'.png',
      'geotiff'=> $href.'.tif',
      'png'=> $href.'.png',
      'xml'=> $href.'.xml',
      'json'=> $href.'.json',
      'crop.geotiff'=> $href.'.crop.tif',
      'crop.png'=> $href.'.crop.png',
    ];
  }
  //print_r($gts); //die();
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode([
    'title'=> "Liste des GéoTiffs et URL de téléchargement",
    'fieldDocunmentation'=> [
      'title'=> "titre du GéoTiff",
      'edition'=> "édition de la carte",
      'scaleden'=> "dénominateur de l'échelle",
      '7z'=> "URL de l'archive 7z de la carte",
      'mini'=> "URL de l'image PNG allégée de la carte",
      'geotiff'=> "URL de l'image complète du GéoTiff au format GéoTiff",
      'png'=> "URL de l'image complète du GéoTiff au format PNG",
      'xml'=> "URL des méta-données ISO du GéoTiff au format XML",
      'json'=> "URL des méta-données du GéoTiff au format JSON",
      'crop.geotiff'=> "URL de l'image rognée du GéoTiff au format GéoTiff",
      'crop.png'=> "URL de l'image rognée du GéoTiff au format PNG",
    ],
    'geotiffs'=> $gts,
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if (json_last_error())
    echo json_last_error_msg();
  die();
}

// Liste des GéoTiff du catalogue en HTML
if ($_SERVER['PATH_INFO'] == '/html') {
  echo "<!DOCTYPE HTML><html><head><title>crop</title><meta charset='UTF-8'></head><body>\n";
  $href = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/json";
  echo "<h2>Liste des GéoTiff (<a href='$href'>en JSON</a>)</h2>\n";
  GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');
  foreach (GeoTiff::geojson()['features'] as $feature) {
    $prop = $feature['properties'];
    if (in_array($prop['gtname'], ['0101bis/0101_pal300']))
      continue;
    if (preg_match('!-East$!', $prop['gtname']))
      continue;
    $href = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/$prop[gtname]";
    $hrefmap = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/".substr($prop['gtname'],0,4);
    echo "<li>$prop[title] - $prop[edition] (1/$prop[scaleden]) - ",
         "<a href='$hrefmap.7z'>7z</a>/",
         "<a href='$hrefmap.png'>mini</a>/",
         "<a href='$href.tif'>geotiff</a>/",
         "<a href='$href.png'>png</a>/",
         "<a href='$href.xml'>xml</a>/",
         "<a href='$href.json'>json</a>/",
         "<a href='$href.crop.tif'>crop geotiff</a>/",
         "<a href='$href.crop.png'>crop png</a><br>\n";
  }
  die("\n");
}

// retourne le chemin de l'archive 7z correspondant à la carte $mapnum
function archive(string $mapnum): string {
  $dirpath = realpath(__DIR__.'/../../../shomgeotiff/incoming');
  if (!($dir = opendir($dirpath))) {
    header('HTTP/1.1 500 Internal Server Error');
    die("Erreur d'ouverture du répertoire $dirpath");
  }
  $archive = '';
  while (($filename = readdir($dir)) !== false) {
    if (!in_array($filename, ['.','..','all']) && is_file("$dirpath/$filename/$mapnum.7z")) {
      //echo "filename=$filename<br>\n";
      if (!$archive || strcmp($filename, $archive) > 0) // sélectionner l'archive la plus récente
        $archive = $filename;
    }
  }
  closedir($dir);

  if (!$archive) {
    header('HTTP/1.1 500 Internal Server Error');
    die("Erreur: archive non trouvée pour $mapnum");
  }
  //echo "return $dirpath/$archive/$mapnum.7z<br>\n"; die();
  return "$dirpath/$archive/$mapnum.7z";
}

// extrait un fichier de l'archive
function unzip(string $mapnum, string $filename): void {
  $archive = archive($mapnum);
   
  //echo "archive=$archive<br>\n";
  $cmde = "7z x $archive $filename";
  exec($cmde, $output, $return);
  if ($return) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Erreur $return dans $cmde<br>\n";
    echo "<pre>output="; print_r($output);
    die();
  }
}

// cas du téléchargement de l'archive de la carte
if (preg_match('!^/(\d+)\.7z$!', $_SERVER['PATH_INFO'], $matches)) {
  $mapnum = $matches[1];
  $archive = archive($mapnum);
  header('Content-type: application/x-7z-compressed');
  readfile($archive);
  die();
}

// cas du téléchargement de la mini vue de la carte
elseif (preg_match('!^/(\d+)\.png$!', $_SERVER['PATH_INFO'], $matches)) {
  $mapnum = $matches[1];
  unzip($mapnum, "$mapnum/$mapnum.png");
  header('Content-type: image/png');
  readfile("$mapnum/$mapnum.png");
  unlink(__DIR__."/$mapnum/$mapnum.png");
  rmdir(__DIR__."/$mapnum");
  die();
}

// téléchargement d'un GéoTiff ou de ses MD
elseif (!preg_match('!^/((\d+)/[^.]*)(\.crop)?\.(tif|png|xml|json)$!', $_SERVER['PATH_INFO'], $matches)) {
  header('HTTP/1.1 404 File Not Found');
  die("Erreur: paramètre $_SERVER[PATH_INFO] incorrect\n");
}
$gtname = $matches[1];
$mapnum = $matches[2];
$crop = $matches[3];
$fmt = $matches[4];
//echo "gtname=$gtname, crop=",$crop?1:0,", fmt=$fmt<br>\n"; die();

GeoTiff::init(__DIR__.'/../ws/shomgt.yaml');
if (!($gt = GeoTiff::get($gtname))) {
  header('HTTP/1.1 404 File Not Found');
  die("Erreur: $gtname ne correspond pas à un GéoTiff\n");
}
$gt = $gt->asArray();
//echo "<pre>gt="; print_r($gt); echo "</pre>\n";

if ($fmt == 'json') {
  header('Content-type: application/json; charset="utf-8"');
  die(json_encode($gt, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

if ($fmt == 'xml') {
  $xmlpath = str_replace('/', '/CARTO_GEOTIFF_', $gtname);
  //echo "xmlpath=$xmlpath<br>\n";
  unzip($mapnum, "$xmlpath.xml");
  header('Content-type: application/xml ');
  readfile(__DIR__."/$xmlpath.xml");
  unlink(__DIR__."/$xmlpath.xml");
  rmdir(__DIR__."/$mapnum");
  die();
}

// extraction du tif
if (!is_file(__DIR__."/$gtname.tif")) {
  unzip($mapnum, "$gtname.tif");
}

$mimetypes = [
  'tif'=> 'image/tiff',
  'png'=> 'image/png',
];

if (($fmt == 'tif') && !$crop) { // pas de conversion
  header('Content-type: image/tiff');
  readfile(__DIR__."/$gtname.tif");
  unlink(__DIR__."/$gtname.tif");
  rmdir(__DIR__."/$mapnum");
  die();
}

elseif (!$crop) { // conversion en PNG
  $cmde = "gdal_translate -of PNG ".__DIR__."/$gtname.tif ".__DIR__."/$gtname.$fmt";
}

elseif ($crop) { // conversion et rognage
  $cmde = sprintf('gdal_translate%s -srcwin %d %d %d %d%s %s %s',
                  ($fmt == 'png') ? ' -of PNG' : '',
                  $gt['left'], $gt['top'],
                  $gt['width']-$gt['left']-$gt['right'],
                  $gt['height']-$gt['top']-$gt['bottom'],
                  ($fmt == 'tif') ? ' -co "COMPRESS=PACKBITS"' : '',
                  __DIR__."/$gtname.tif", __DIR__."/$gtname.crop.$fmt");
}
//echo "cmde=$cmde<br>\n";
exec($cmde, $output, $return);
if ($return) {
  header('HTTP/1.1 500 Internal Server Error');
  echo "Erreur $return dans $cmde<br>\n";
  echo "<pre>output="; print_r($output);
  die();
}

header('Content-type: '.$mimetypes[$fmt]);
readfile(__DIR__."/$gtname$crop.$fmt");
unlink(__DIR__."/$gtname$crop.$fmt");
unlink(__DIR__."/$gtname$crop.$fmt.aux.xml");
unlink(__DIR__."/$gtname.tif");
rmdir(__DIR__."/$mapnum");
die();
