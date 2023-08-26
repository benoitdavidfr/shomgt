<?php
namespace bo;
/*PhpDoc:
name: shomgeotiff.php
title: bo/shomgeotiff.php - accès aux fichiers de SHOMGEOTIFF à l'intérieur d'une archive 7z - 3/8/2023
doc: |
  Le PATH_INFO est composé de la concaténation
   - du chemin du fichier 7z dans SHOMGT3_PORTFOLIO_PATH,
   - du caractère '/' et
   - de l'entrée dans le fichier 7z
  Permet aussi:
   - de télécharger l'archive 7z
   - de convertir en .png un .tif ou un .pdf
*/
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/my7zarchive.inc.php';

define ('MIME_TYPES', [
  '.png'=> 'image/png',
  '.jpg'=> 'image/jpeg',
  '.tif'=> 'image/tiff',
  '.pdf'=> 'application/pdf',
  '.xml'=> 'text/xml; charset="utf-8"',
  '.7z' => 'application/x-7z-compressed',
  '.txt'=> 'text/plain; charset="utf-8"',
  '.json'=> 'application/json; charset="utf-8"',
]
);

if (!($login = Login::loggedIn())) {
  die("Accès non autorisé\n");
}

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

// Teste si $fileName est une entrée de $archive
function entryInArchive(string $fileName, My7zArchive $archive): bool {
  foreach ($archive as $entry) {
    //print_r($entry);
    if ($entry['Name'] == $fileName)
      return true;
  }
  return false;
}

define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

$baseUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]$_SERVER[PHP_SELF]";
switch ($_SERVER['PATH_INFO'] ?? null) {
  case null: { // propose soit de consulter les différentes archives disponibles, soit de consulter la liste des versions courantes
    header('Content-type: application/json; charset="utf-8"');
    die(json_encode([
      'archives'=> "$baseUrl/archives",
      'current'=> "$baseUrl/current",
    ], JSON_OPTIONS));
  }
  case '/archives': { // liste les cartes cotenues dans les archives et propose de consulter les versions de cahque carte
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')
      or throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    
    foreach (new \DirectoryIterator("$PF_PATH/archives") as $entry) {
      if (in_array($entry, ['.','..','.DS_Store'])) continue;
      $archives[(string)$entry] = "$baseUrl/$entry";
    }
    header('Content-type: application/json; charset="utf-8"');
    die(json_encode(['archives'=> $archives], JSON_OPTIONS));
  }
  case '/current': { // liste les versions courantes des cartes et propose de consuler la version
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')
      or throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    
    foreach (new \DirectoryIterator("$PF_PATH/current") as $entry) {
      if (in_array($entry, ['.','..','.DS_Store'])) continue;
      if (substr($entry, -8)=='.md.json') {
        $link = readLink("$PF_PATH/current/$entry");
        $link = substr($link, 0, -8);
        $current[substr($entry, 0, -8)] = "$baseUrl/$link";
      }
    }
    header('Content-type: application/json; charset="utf-8"');
    die(json_encode($current, JSON_OPTIONS));
  }
  default: {
    $PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')
      or throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

    // liste les versions de la carte mapNum
    if (preg_match('!^/archives/(\d{4})$!', $_SERVER['PATH_INFO'], $matches)) {
      $mapNum = $matches[1];
      $versions = [];
      foreach (new \DirectoryIterator("$PF_PATH/archives/$mapNum") as $entry) {
        if (in_array($entry, ['.','..','.DS_Store'])) continue;
        if (substr($entry, -8)=='.md.json') {
          $version = substr($entry, 0, -8);
          $versions[(string)$entry] = "$baseUrl/$version";
        }
      }
      $mapCatUrl = dirname(dirname("$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]"))."/mapcat/index.php";
      header('Content-type: application/json; charset="utf-8"');
      die(json_encode([$mapNum => [
          'mapCat'=> "$mapCatUrl/$mapNum",
          'versions'=> $versions,
          //'$_SERVER'=> $_SERVER,
        ]], JSON_OPTIONS));
    }
    // propose pour une version de carte de consulter les MD, de télécharger l'archive ou de lister son contenu
    if (preg_match('!^/archives/(\d{4})/\d{4}-([^/.]+)$!', $_SERVER['PATH_INFO'], $matches)) {
      //print_r($matches);
      $mapNum = $matches[1];
      $version = $matches[2];
      $v = [
        'metadata' => "$baseUrl.md.json",
        'download' => "$baseUrl.7z",
        '7zContent' => "$baseUrl/7zContent",
      ];
      header('Content-type: application/json; charset="utf-8"');
      die(json_encode($v, JSON_OPTIONS));
    }
    // retourne le .md.json de la version
    if (preg_match('!^/archives/(\d{4})/\d{4}-([^/.]+)\.md\.json$!', $_SERVER['PATH_INFO'], $matches)) {
      header('Content-type: application/json; charset="utf-8"');
      die(file_get_contents("$PF_PATH$_SERVER[PATH_INFO]"));
    }
    // retourne le contenu du 7z de la version et propose d'en consulter ou télécharger les éléments
    if (preg_match('!^/archives/(\d{4})/\d{4}-([^/.]+)/7zContent$!', $_SERVER['PATH_INFO'], $matches)) {
      $mapNum = $matches[1];
      $version = $matches[2];
      $baseUrl = substr($baseUrl, 0, -strlen('/7zContent'));
      $content = [];
      foreach (new \SevenZipArchive("$PF_PATH/archives/$mapNum/$mapNum-$version.7z") as $entry) {
        if ($entry['Attr'] <> '....A') continue;
        if (substr($entry['Name'], -4) == '.tif') {
          $content["$entry[Name].png"] = "$baseUrl.7z/".substr($entry['Name'], 0, -4).'.png';
        }
        $content[$entry['Name']] = "$baseUrl.7z/$entry[Name]";
      }
      header('Content-type: application/json; charset="utf-8"');
      die(json_encode($content, JSON_OPTIONS));
    }
    //die("PATH_INFO=$_SERVER[PATH_INFO]");
  }
}

// à partir de là - téléchargement du .7z ou d'un fichier contenu dans l'archive 7z
if (($pos = strpos($_SERVER['PATH_INFO'], '.7z')) === false) {
  header('HTTP/1.1 400 Bad Request');
  header('Content-type: text/plain; charset="utf-8"');
  die("Requête incorrecte");
}

$pathOf7z = $PF_PATH.substr($_SERVER['PATH_INFO'], 0, $pos+3);
//echo "pos="; var_dump($pos);
//echo "pathOf7z=$pathOf7z\n";
if (!is_file($pathOf7z)) {
  header('HTTP/1.1 404 Not Found');
  header('Content-type: text/plain; charset="utf-8"');
  echo "Fichier '$pathOf7z' non trouvé\n";
  die();
}

$fileName = substr($_SERVER['PATH_INFO'], $pos+4);
if (!$fileName) {
  header("Content-type: ".MIME_TYPES['.7z']);
  fpassthru(fopen($pathOf7z, 'r'));
  die();
}

$archive = new My7zArchive($pathOf7z);
//echo "fileName=$fileName\n";

function notFound(string $fileName): void {
  header('HTTP/1.1 404 Not Found');
  header('Content-type: text/plain; charset="utf-8"');
  echo "Entrée '$fileName' non trouvée dans l'archive\n";
  die();
}

// Si je demande du .png, qu'il est absent et qu'il existe un .tif ou un .pdf avec le même nom de base
// alors je fais la conversion de ce dernier fichier en .png
$fileName2 = null;
if (entryInArchive($fileName, $archive))
  $fileName2 = $fileName;
elseif (substr($fileName, -4) == '.png') {
  foreach (['.tif','.pdf'] as $ext) {
    if (entryInArchive(substr($fileName, 0, -4).$ext, $archive)) {
      $fileName2 = substr($fileName, 0, -4).$ext;
      break;
    }
  }
}
if (!$fileName2)
  notFound($fileName);

$path = $archive->extract($fileName2);

if ($fileName2 <> $fileName) { // conversion .tif/pdf -> .png
  $path2 = substr($path, 0, -4).'.png';
  $cmde = "gdal_translate -of PNG $path $path2";
  exec($cmde, $output, $retval);
}
else { // pas de conversion
  $path2 = $path;
}

if (isset(MIME_TYPES[substr($path2, -4)])) {
  header("Content-type: ".MIME_TYPES[substr($path2, -4)]);
}
$stream = fopen($path2, 'r');
fpassthru($stream);
if ($path2 <> $path) {
  unlink($path2);
  @unlink("$path2.aux.xml");
}
$archive->remove($path);
