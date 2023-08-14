<?php
/*PhpDoc:
name: pfcurrent.php
title: bo/pfcurrent.php - gestion des versions courantes des cartes du portefeuille - 1/8/2023
doc: |
  La version courante d'une carte est la version de la carte diffusée par sgserver
  Cela peut soit être un des versions conservées, soit par extension l'info que la carte est obsolète,
  soit encore par extension la disparition de la carte dans sgserver.
  Si la carte est marquée obsolète, le client sgupdt supprime la carte localement ;
  si la carte n'apparait pas dans sgserver alors le client conserve la carte qu'il détient.
  L'obsolescence peut être décidée soit par ce que la carte a été retirée par le Shom de son propre portefeuille,
  soit par ce que on décide que la carte n'est plus d'intérêt pour ShomGT.
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
//require_once __DIR__.'/mapmetadata.inc.php';
//require_once __DIR__.'/maparchive.php';

use Symfony\Component\Yaml\Yaml;

if (!($login = Login::loggedIn())) {
  die("Accès non autorisé\n");
}

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

echo "<!DOCTYPE html><html><head><title>bo/activation</title></head><body>\n";
//echo "<pre>_POST="; print_r($_POST); echo "</pre>\n";
//echo "<pre>_GET="; print_r($_GET); echo "</pre>\n";

switch ($action = $_POST['action'] ?? $_GET['action'] ?? null) { // action à réaliser
  case null: break; // pas d'action de modification à exécuter 
  case 'chooseVersion': { // choix de la version courante de la carte ou indication d'obsolescence ou absence de mention
    if (is_file("$PF_PATH/current/$_GET[map].7z"))
      unlink("$PF_PATH/current/$_GET[map].7z");
    if (is_file("$PF_PATH/current/$_GET[map].md.json"))
      unlink("$PF_PATH/current/$_GET[map].md.json");
    if ($_GET['mapVersion'] <> 'none') {
      //echo "symlink('../archives/$_GET[map]/$_GET[mapVersion].md.json', '$PF_PATH/current/$_GET[map].md.json')<br>\n";
      symlink("../archives/$_GET[map]/$_GET[mapVersion].md.json", "$PF_PATH/current/$_GET[map].md.json");
      if (is_file("$PF_PATH/archives/$_GET[map]/$_GET[mapVersion].7z"))
        symlink("../archives/$_GET[map]/$_GET[mapVersion].7z", "$PF_PATH/current/$_GET[map].7z");
    }
    break;
  }
  default: {
    echo "<b>Erreur: action $action inconnue</b><br>\n";
    break;
  }
}

if (!($_GET['map'] ?? null)) { // liste des cartes du portefeuille avec possibilité d'en sélectionner une
 echo "<h2>Gestion de l'activation des cartes du portefeuille</h2>\n";
 $activated = []; // liste des cartes activées cad présented dans current
 foreach (new DirectoryIterator("$PF_PATH/current") as $map) {
   if (!in_array($map, ['.','..','.DS_Store']))
     $activated[substr($map, 0, 4)] = 1;
 }
 echo "<table border=1>\n";
 foreach (directoryEntries("$PF_PATH/archives") as $mapNum) {
   $mapCat = Mapcat::get($mapNum);
   $ss = $mapCat->obsoleteDate ? '<s>' : '';
   $se = $mapCat->obsoleteDate ? '</s>' : '';
   echo "<tr><td>",(!($activated[$mapNum] ?? null)) ? 'N' : '',"</td>\n", // carte activée ou non
        "<td>$ss<a href='?map=$mapNum'>$mapNum</a> - $mapCat->title$se</td>", //num, lien et titre
        "<td align='right'>{$ss}1:",$mapCat->scaleDenominator(),"$se</td>", // échelle
        "<td>$ss",implode(', ',$mapCat->mapsFrance),"$se</td>", // zone ZEE
        //<td>$ss<pre>",Yaml::dump($mapCat->asArray()),"</pre>$se</td>",
        "</tr>\n";
   }
  echo "</table>\n";
  echo "<a href='index.php'>Retour au menu du BO</a></p>\n";
}
else { // liste de versions pour la carte $_GET['map']
  $mapCat = MapCat::get($_GET['map']);
  echo "<h2>Carte $_GET[map] - $mapCat->title</h2>\n";
  echo "<pre>",Yaml::dump($mapCat->asArray(), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
  
  // Détermination de la version courante
  $currentVersion = '';
  if (is_file("$PF_PATH/current/$_GET[map].md.json")) {
    $currentVersion = basename(readlink("$PF_PATH/current/$_GET[map].md.json"),'.md.json');
    //echo "currentVersion=$currentVersion<br>\n";
  }
  
  // Sélection de la version courante
  echo "<form><fieldset>\n";
  echo "  <legend>Sélectionner la version courante:</legend>\n";
  echo "  <div>\n";
  foreach (directoryEntries("$PF_PATH/archives/$_GET[map]") as $mapVersion) {
    if (substr($mapVersion, -8) <> '.md.json') continue;
    $md = json_decode(file_get_contents("$PF_PATH/archives/$_GET[map]/$mapVersion"), true);
    $mapVersion = substr($mapVersion, 0, -8);
    if ($md['status'] ?? null) {
      $label = 'Carte obsolète (indiquant au client de supprimer la carte)';
    }
    elseif ($md['edition'] ?? null) {
      $label = "$md[edition] (version: $md[version], dateArchive: $md[dateArchive])";
    }
    else {
      $label = "version: $md[version], dateArchive: $md[dateArchive]";
    }
    echo "    <input type='radio' id='$mapVersion' name='mapVersion' value='$mapVersion' ",
         ($mapVersion==$currentVersion ? 'checked' : ''),"/>\n";
    echo "    <label for='$mapVersion'>",
         "<a href='viewtiff.php?path=/archives/$_GET[map]&map=$mapVersion'>$label</a></label><br>\n";
  }
  echo "    <input type='radio' id='none' name='mapVersion' value='none' ",(''==$currentVersion ? 'checked' : ''),"/>\n";
  echo "    <label for='none'>Carte retirée de la liste des cartes (n'indiquant rien au client)</label><br>\n";
  echo "  </div>\n";
  echo "  <div>",Html::button('Sélection', ['map'=>$_GET['map'], 'action'=>'chooseVersion'], '', 'get'),"</div>\n";
  echo "</fieldset></form>\n";
  echo "<a href='pfweight.php?map=$_GET[map]'>Gestion de la suppression de versions</a><br>\n";
  echo "<a href='pfcurrent.php'>Retour à la liste des cartes</a></p>\n";
}
