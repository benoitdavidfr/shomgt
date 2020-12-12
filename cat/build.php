<?php
/*PhpDoc:
name: build.php
title: build.php - cmdes produisant mapcat.pser à partir du WFS du Shom et des GANs
doc: |
  Il reste à gérer l'invalidation d'une carte
journal: |
  19/11/2019:
    correction de l'URL générique des GAN suite à erreur rencontrée
    cette correction basique doit être améliorée
  8-9/11/2019:
    possibilité de construire un pser à partir du GeoJSON
  29/10/2019:
    ajout des cartes AEM et MancheGrid
  28/10/2019:
    suppression de la gestion de l'historique
  16/3/2019:
    refonte importante
  8/3/2019:
    fork dans gt
  11/12/2018
    scission de index.php
includes: [lib.inc.php, ../ws/accesscntrl.inc.php, mapcat.inc.php]
*/
use Symfony\Component\Yaml\Yaml;
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/../ws/accesscntrl.inc.php';

// initialisation de $action
if (php_sapi_name() == 'cli') { // en CLI 
  if ($argc==1) {
    echo "shomCatBuild - Actions proposées:\n";
    echo " - harvestGan - moissonne les pages GAN du Shom dans le répertoire adhoc\n";
    echo " - displayGan - analyse les pages GAN et les affiche comme objet JSON\n";
    echo " - store - analyse les pages GAN et les enregistre dans le pser\n";
    echo " - storeFromJSON - génère le fichier mapcat.pser à partir du fichier mapcat.json\n";
    echo " - display - affiche le contenu du pser en JSON\n";
    die();
  }
  else
    $action = $argv[1];
}
else { // en non CLI 
  if (!Access::cntrl()) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-type: text/plain; charset="utf-8"');
    die("Accès interdit");
  }
  if (!isset($_GET['action'])) {
    echo "shomCatBuild - Actions proposées:<ul>\n";
    echo "<li><a href='?action=harvestGan'>moissonne les pages GAN du Shom dans le répertoire adhoc</a>\n";
    echo "<li><a href='?action=displayGan'>analyse les pages GAN et les affiche comme objet JSON</a>\n";
    echo "<li><a href='?action=verifGan'>vérifie l'analyse des pages GAN</a>\n";
    echo "<li><a href='?action=store'>analyse les pages GAN et les enregistre dans le pser</a>\n";
    echo "<li><a href='?action=storeFromJSON'>génère le fichier mapcat.pser à partir du fichier mapcat.json</a>\n";
    echo "<li><a href='?action=display'>affiche le contenu du pser en JSON</a>\n";
    echo "</ul>\n";
    die();
  }
  else
    $action = $_GET['action'];
}


// lecture de la fiche GAN pour un id de carte (de la forme "FR{num}") et enregistrement dans le répertoire adhoc
function harvestGan(string $id) {
  echo "id:$id\n";
  //$url = "http://www.shom.fr/qr/gan/$id";
  //$url = "https://gan.shom.fr/qr/gan/$id"; // correction de l'URL le 19/11/2019 suite à erreur rencontrée. Il ne s'agit plus a priori de l'URL du QR Code
  $url = "https://www.shom.fr/qr/gan/$id/1937"; // ajout https et num premier n° de GAN à consulter
  if (($html = @file_get_contents($url)) === FALSE) {
    //die("Erreur de lecture de $url");
    echo "<b>Erreur de lecture de $url</b><br>\n";
  }
  else {
    file_put_contents("gan/$id.html", $html);
    echo "gan/$id.html téléchargé<br>\n";
  }
}

// moissonnage des GANs des cartes exposées par le flux WFS
if ($action == 'harvestGan') {
  if (!is_dir(__DIR__.'/gan') && !mkdir(__DIR__.'/gan'))
    die("Erreur de création du répertoire gan\n");
  $dh = opendir('gan');
  while (($file = readdir($dh)) !== false) {
    if (in_array($file, ['.','..','.DS_Store']))
      continue;
    unlink(__DIR__."/gan/$file");
  }
  foreach (array_keys(wfsdl()) as $id)
    harvestGan($id);
  die("FIN OK<br>\n");
}

require_once __DIR__.'/mapcat.inc.php';

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
    $mapinfo = new MapCat();
    $mapinfo->analyzeFromHtml($num, $html);
    echo ($no++ ? ",\n" : ''),
         "  \"FR$num\" : ",
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
    'FR6497.html' => "carte ajoutée dans le WFS et présente dans le GAN",
    'FR8502.html' => "carte ajoutée dans le WFS et absente du GAN",
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
    $mapinfo = new MapCat;
    $mapinfo->analyzeFromHtml($matches[1], $html);
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
  $modified = filemtime('gan');
  $dh = opendir('gan');
  while (($file = readdir($dh)) !== false) {
    if (in_array($file, ['.','..','.DS_Store']))
      continue;
  
    echo "file=$file<br>\n";
    $html = file_get_contents("gan/$file");
    if (!preg_match('!^FR([^.]*)\.html$!', $file, $matches))
      throw new Exception("No match on file name $file");
    $num = $matches[1];
    MapCat::add($num, $html, $modified);
  }
  MapCat::store();
  echo MapCat::count()," cartes dans le pser<br>\n";
  die("FIN OK<br>\n");
}

// Génère le fichier mapcat.pser à partir du fichier mapcat.json
if ($action == 'storeFromJSON') {
  if (!is_file(__DIR__.'/mapcat.json'))
    die("Erreur, pas de fichier JSON<br>\n");
  $json = json_decode(file_get_contents(__DIR__.'/mapcat.json'), true);
  foreach ($json['maps'] as $frnum => $map) {
    MapCat::addFromJson(substr($frnum, 2), $map, $json['modified']);
  }
  // Je n'efface pas le JSON initial pour pouvoir le comparer avec celui issu du PSER produit
  MapCat::store(false);
  echo MapCat::count()," cartes dans le pser<br>\n";
  
  if (0) {
    // Partie test de vérification en ressortant le JSON à partir du PSER pour le comparer au JSON ancien
    MapCat::load();
    echo json_encode(
            MapCat::allAsArray(),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
  }
  die("FIN OK<br>\n");
}

// affiche de mapcat.pser
if ($action == 'display') {
  MapCat::load();
  header('Content-type: application/json; charset="utf-8"');
  echo json_encode(
          MapCat::allAsArray(),
          JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
  die("\n");
}

die("Erreur: action $action inconnue\n");