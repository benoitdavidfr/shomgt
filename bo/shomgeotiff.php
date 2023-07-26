<?php
/* bo/shomgeotiff.php - accès aux fichiers de SHOMGEOTIFF à l'intérieur d'une archive 7z
** Le PATH_INFO est composé de la concaténation
**  - du chemin du fichier 7z,
**  - du caractère '/' et
**  - de l'entrée dans le fichier 7z
*/

require_once __DIR__.'/../sgserver/SevenZipArchive.php';

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

// Teste si $fileName est une entrée de $archive
function entryInArchive(string $fileName, SevenZipArchive $archive): bool {
  foreach ($archive as $entry) {
    //print_r($entry);
    if ($entry['Name'] == $fileName)
      return true;
  }
  return false;
}

$pos = strpos($_SERVER['PATH_INFO'], '.7z');
//echo "pos=$pos\n";
$pathOf7z = $PF_PATH.substr($_SERVER['PATH_INFO'], 0, $pos+3);
//echo "pathOf7z=$pathOf7z\n";
if (!is_file($pathOf7z)) {
  header('HTTP/1.1 404 Not Found');
  header('Content-type: text/plain; charset="utf-8"');
  echo "Fichier $pathOf7z non trouvé\n";
  die();
}

$archive = new SevenZipArchive($pathOf7z);
$fileName = substr($_SERVER['PATH_INFO'], $pos+4);
//echo "fileName=$fileName\n";

if (!entryInArchive($fileName, $archive)) {
  header('HTTP/1.1 404 Not Found');
  header('Content-type: text/plain; charset="utf-8"');
  echo "Entrée $fileName non trouvé dans l'archive\n";
  die();
}

if (!is_dir(__DIR__.'/temp'))
  if (!mkdir(__DIR__.'/temp'))
    throw new Exception("Erreur de création du répertoire __DIR__/temp");
$archive->extractTo(__DIR__.'/temp', $fileName);

define ('MIME_TYPES', [
  '.png'=> 'image/png',
  '.tif'=> 'image/tiff',
  '.pdf'=> 'application/pdf',
  '.xml'=> 'text/xml; charset="utf-8"',
]
);

if (isset(MIME_TYPES[substr($fileName, -4)])) {
  header("Content-type: ".MIME_TYPES[substr($fileName, -4)]);
}
$stream = fopen(__DIR__.'/temp/'.$fileName, 'r');
fpassthru($stream);
unlink(__DIR__.'/temp/'.$fileName);
rmdir(__DIR__.'/temp/'.dirname($fileName));
