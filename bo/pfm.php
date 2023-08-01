<?php
// bo/pfm.php - gestion du portefeuille, notamment de la version courante - 1/8/2023

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
//require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/maparchive.php';

use Symfony\Component\Yaml\Yaml;

define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

if (!($login = Login::login())) {
  die("Accès non autorisé\n");
}

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
  throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");

echo "<!DOCTYPE html><html><head><title>pfm</title></head><body>\n";
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
 echo "<h2>Gestion du portefeuille de cartes</h2>\n";
 echo "<table border=1>\n";
 foreach (new DirectoryIterator("$PF_PATH/archives") as $mapNum) {
   if (in_array($mapNum, ['.','..','.DS_Store'])) continue;
   $mapCat = new Mapcat($mapNum);
   $ss = $mapCat->obsoleteDate ? '<s>' : '';
   $se = $mapCat->obsoleteDate ? '</s>' : '';
   echo "<tr><td>$ss<a href='?map=$mapNum'>$mapNum</a> - $mapCat->title$se</td>",
        "<td align='right'>{$ss}1:",$mapCat->scaleDenominator(),"$se</td>",
        "<td>$ss",implode(', ',$mapCat->mapsFrance),"$se</td>",
        //<td>$ss<pre>",Yaml::dump($mapCat->asArray()),"</pre>$se</td>",
        "</tr>\n";
   }
  echo "</table>\n";
}
else { // liste de versions pour la carte $_GET['map']
  $mapCat = new MapCat($_GET['map']);
  echo "<h2>Carte $_GET[map] - $mapCat->title</h2>\n";
  $mapCat = new MapCat($_GET['map']);
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
  foreach (new DirectoryIterator("$PF_PATH/archives/$_GET[map]") as $mapVersion) {
    if (substr($mapVersion, -8) <> '.md.json') continue;
    $md = json_decode(file_get_contents("$PF_PATH/archives/$_GET[map]/$mapVersion"), true);
    $mapVersion = substr($mapVersion, 0, -8);
    if (!($md['status'] ?? null)) {
      $label = "$md[edition] (version: $md[version], dateArchive: $md[dateArchive])";
    }
    else {
      $label = 'Carte obsolète (indiquant au client de supprimer la carte)';
    }
    echo "    <input type='radio' id='$mapVersion' name='mapVersion' value='$mapVersion' ",
         ($mapVersion==$currentVersion ? 'checked' : ''),"/>\n";
    echo "    <label for='$mapVersion'>",
         "<a href='viewtiff.php?path=/archives/$_GET[map]&map=$mapVersion'>$label</a></label><br>\n";
  }
  echo "    <input type='radio' id='none' name='mapVersion' value='none' ",(''==$currentVersion ? 'checked' : ''),"/>\n";
  echo "    <label for='none'>Carte retirée de la liste des cartes (n'indiquant rien au client)</label><br>\n";
  echo "  </div>\n";
  echo "  <div>",button('Sélection', ['map'=>$_GET['map'], 'action'=>'chooseVersion'], '', 'get'),"</div>\n";
  echo "</fieldset></form>\n";
}
/*Modèle de formulaire bouttons radio<form>
  <fieldset>
    <legend>Please select your preferred contact method:</legend>
    <div>
      <input type="radio" id="contactChoice1" name="contact" value="email" />
      <label for="contactChoice1">Email</label>

      <input type="radio" id="contactChoice2" name="contact" value="phone" />
      <label for="contactChoice2">Phone</label>

      <input type="radio" id="contactChoice3" name="contact" value="mail" />
      <label for="contactChoice3">Mail</label>
    </div>
    <div>
      <button type="submit">Submit</button>
    </div>
  </fieldset>
</form>*/
echo "<a href='pfm.php'>Retour à la liste des cartes</a></p>\n";
