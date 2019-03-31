<?php
/*PhpDoc:
name: build.php
title: build.php - cmdes produisant mapcat.pser à partir du WFS du Shom et des GANs
doc: |
  Il reste à gérer l'invalidation d'une carte
journal: |
  16/3/2019:
    refonte importante
  8/3/2019:
    fork dans gt
  11/12/2018
    scission de index.php
*/
use Symfony\Component\Yaml\Yaml;
require_once 'lib.inc.php';

// initialisation de $action
if (php_sapi_name() == 'cli') { // en CLI 
  if ($argc==1) {
    echo "shomCatBuild - Actions proposées:\n";
    echo " - harvestGan - moissonne les pages GAN du Shom dans le répertoire adhoc\n";
    echo " - displayGan - analyse les pages GAN et les affiche comme objet JSON\n";
    echo " - store - analyse les pages GAN et les enregistre dans le pser\n";
    echo " - display - affiche le contenu du pser en JSON\n";
    die();
  }
  else
    $action = $argv[1];
}
else { // en non CLI 
  if (!isset($_GET['action'])) {
    echo "shomCatBuild - Actions proposées:<ul>\n";
    echo "<li><a href='?action=harvestGan'>moissonne les pages GAN du Shom dans le répertoire adhoc</a>\n";
    echo "<li><a href='?action=displayGan'>analyse les pages GAN et les affiche comme objet JSON</a>\n";
    echo "<li><a href='?action=verifGan'>vérifie l'analyse des pages GAN</a>\n";
    echo "<li><a href='?action=store'>analyse les pages GAN et les enregistre dans le pser</a>\n";
    echo "<li><a href='?action=display'>affiche le contenu du pser en JSON</a>\n";
    echo "</ul>\n";
    die();
  }
  else
    $action = $_GET['action'];
}


// lecture de la fiche GAN pour un id de carte (de la forme "FR{num}") et enregistrement dans le répertoire adhoc
function harvestGan(string $id) {
  $url = "http://www.shom.fr/qr/gan/$id";
  if (($html = file_get_contents($url)) === FALSE)
    die("Erreur de lecture de $url");
  file_put_contents("gan/$id.html", $html);
  echo "gan/$id.html téléchargé<br>\n";
}

// moissonnage des GANs des cartes exposées par le flux WFS
if ($action == 'harvestGan') {
  if (!is_dir(__DIR__.'/gan') && !mkdir(__DIR__.'/gan'))
    die("Erreur de création du répertoire gan\n");
  foreach (array_keys(wfsdl()) as $id)
    harvestGan($id);
  die("FIN OK<br>\n");
}

require_once 'mapcat.inc.php';

// analyse des pages GAN et affiche le résultat
if ($action == 'displayGan') {
  header('Content-type: application/json; charset="utf-8"');
  //header('Content-type: text/plain');
  $dh = opendir('gan');
  echo "{\n";
  $no = 0;
  while (($file = readdir($dh)) !== false) {
    if (in_array($file, ['.','..','.DS_Store']))
      continue;
  
    //echo "file=$file<br>\n";
    $html = file_get_contents("gan/$file");
    if (!preg_match('!^FR([^.]*)\.html$!', $file, $matches))
      throw new Exception("No match on file name $file");
    $num = $matches[1];
    $mapinfo = new MapCat($num, $html);
    echo ($no++ ? ",\n" : ''),
         "\"FR$num\" : ",
         json_encode(
            $mapinfo->asArray(),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
  }
  die("\n}\n");
}

// vérifie l'analyse de quelques cartes prototypes
if ($action == 'verifGan') {
  $prototypes = [
    'FR3127.html' => "proto standard avec carte principale sans cartouche",
    'FR4233.html' => "proto standard avec carte principale et un cartouche",
    'FR6033.html' => "proto standard avec carte principale et plusieurs cartouches",
    'FR3976.html' => "proto sans super-titre",
    'FR6207.html' => "proto avec nota",
    'FR7037.html' => "proto avec facsimile",
    'FR7003.html' => "proto absence de carte principale, uniquement des cartouches",
    'FR4232.html' => "proto absence de carte principale, uniquement des cartouches",
    'FR6643.html' => "correction des BBox",
  ];
  $dh = opendir('gan');
  while (($file = readdir($dh)) !== false) {
    if (in_array($file, ['.','..']))
      continue;
    if (!isset($prototypes[$file]))
      continue;
  
    //echo "file=$file<br>\n";
    $html = file_get_contents("gan/$file");
    if (!preg_match('!^FR([^.]*)\.html$!', $file, $matches))
      throw new Exception("No match on file name $file");
    $mapinfo = new MapCat($matches[1], $html);
    echo "<b>",$prototypes[$file]," (<a href='gan/$file'>gan</a>)</b><br>\n",
         '<pre>',
          json_encode(
            $mapinfo->asArray(),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          "</pre>\n"; 
  }
  die("FIN OK<br>\n");
}

// enregistrement de mapinfo.pser
if ($action == 'store') {
  MapCat::load();
  $modified = filemtime('gan');
  $nbmodif = 0;
  $dh = opendir('gan');
  while (($file = readdir($dh)) !== false) {
    if (in_array($file, ['.','..','.DS_Store']))
      continue;
  
    echo "file=$file<br>\n";
    $html = file_get_contents("gan/$file");
    if (!preg_match('!^FR([^.]*)\.html$!', $file, $matches))
      throw new Exception("No match on file name $file");
    $num = $matches[1];
    if (MapCat::add($num, $html, $modified))
      $nbmodif++;
  }
  if ($nbmodif) {
    MapCat::store();
    echo MapCat::count()," cartes dans le pser dont $nbmodif modifiées<br>\n";
  }
  else {
    MapCat::store();
    echo MapCat::count()," cartes dans le pser dont aucune modifiée<br>\n";
  }
  die("FIN OK<br>\n");
}

// affiche de mapinfo.pser
if ($action == 'display') {
  MapCat::load();
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode(
          MapCat::allAsArray(),
          JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
  die("\n");
}

die("Erreur: action $action inconnue\n");