<?php
/* my7zarchive.inc.php - ajout de 2 méthodes à SevenZipArchive pour simplifier l'extraction temporaire d'un fichier
  - extract() extrait une entrée de l'archive et retourne le chemin constitué
  - remove() supprime le fichier extrait ainsi que les répertoires créés
 Le fichier est extrait dans un répertoire unique afin d'éviter les collisions.
*/
require_once __DIR__.'/SevenZipArchive.php';

class My7zArchive extends SevenZipArchive {
  // retourne le chemin du l'entrée extraite
  function extract(string $entryName): string {
    if (!is_dir(__DIR__.'/temp') && !mkdir(__DIR__.'/temp'))
      throw new Exception("Erreur de création du répertoire __DIR__/temp");
    $uniqid = uniqid();
    if (!is_dir(__DIR__."/temp/$uniqid") && !mkdir(__DIR__."/temp/$uniqid"))
      throw new Exception("Erreur de création du répertoire __DIR__/temp/$uniqid");
    $this->extractTo(__DIR__."/temp/$uniqid", $entryName);
    return __DIR__."/temp/$uniqid/$entryName"; 
  }

  function remove(string $path): void {
    unlink($path);
    $path = dirname($path);
    //echo "path=$path<br>\n";
    while ($path <> __DIR__.'/temp') {
      rmdir($path);
      $path = dirname($path);
      //echo "path=$path<br>\n";
    }
  }
};

if (__FILE__ == "$_SERVER[DOCUMENT_ROOT]$_SERVER[SCRIPT_NAME]") { // TEST de la classe
  if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
    throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

  $archive = new My7zArchive("$PF_PATH/incoming/20230710/7090.7z");
  if (0) {
    foreach ($archive as $entry) {
      echo "<pre>"; print_r($entry); echo "</pre>\n";
    }
  }
  elseif (1) {
    $path = $archive->extract('7090/CARTO_GEOTIFF_7090_pal300.xml');
    echo '<pre>',str_replace(['<','>'],['{','}'], file_get_contents($path)),"</pre>\n";
    $archive->remove($path);
  }
}
