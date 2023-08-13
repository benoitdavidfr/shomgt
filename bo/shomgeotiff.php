<?php
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
]
);

if (!($login = Login::loggedIn())) {
  die("Accès non autorisé\n");
}

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

// Teste si $fileName est une entrée de $archive
function entryInArchive(string $fileName, My7zArchive $archive): bool {
  foreach ($archive as $entry) {
    //print_r($entry);
    if ($entry['Name'] == $fileName)
      return true;
  }
  return false;
}

if (!isset($_SERVER['PATH_INFO'])) {
  header('HTTP/1.1 400 Bad Request');
  header('Content-type: text/plain; charset="utf-8"');
  die("Syntaxe incorrecte\n");
}

$pos = strpos($_SERVER['PATH_INFO'], '.7z');
//echo "pos=$pos\n";
$pathOf7z = $PF_PATH.substr($_SERVER['PATH_INFO'], 0, $pos+3);
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
