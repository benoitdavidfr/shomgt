<?php
/** gestion du poids des cartes du portefeuille - 1/8/2023
 * @package shomgt\bo
 */
namespace bo;

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

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH'))) {
  die("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
}

echo "<!DOCTYPE html><html><head><title>bo/pfweight@$_SERVER[HTTP_HOST]</title></head><body>\n";

switch ($action = $_POST['action'] ?? $_GET['action'] ?? null) { // action à réaliser
  case null: break; // pas d'action de modification à exécuter 
  case 'deleteVersion': { // demande de confirmation pour la suppression d'une version
    echo "Suppression de la version $_POST[version] de la carte $_POST[map]<br>\n";
    $hiddens = ['action'=> 'confirmDeleteVersion', 'map'=> $_POST['map'], 'version'=> $_POST['version']];
    echo "<table><tr><td>",new \html\Form(submit: 'confirmer', hiddens: $hiddens, method: 'post'),
         "</td><td>",new \html\Form(submit: 'annuler', hiddens: ['map'=> $_POST['map']], method: 'post'),
         "</td></tr></table>\n";
    break;
  }
  case 'confirmDeleteVersion': { // suppression confirmée d'une version de carte 
    $unlinkError = 0;
    if (is_file("$PF_PATH/archives/$_POST[map]/$_POST[version].7z")) {
      if (!unlink("$PF_PATH/archives/$_POST[map]/$_POST[version].7z")) {
        $unlinkError = 1;
        echo "<b>Erreur de suppression de $PF_PATH/archives/$_POST[map]/$_POST[version].7z</b><br>\n";
      }
    }
    if (!unlink("$PF_PATH/archives/$_POST[map]/$_POST[version].md.json")) {
      $unlinkError = 1;
      echo "<b>Erreur de suppression de $PF_PATH/archives/$_POST[map]/$_POST[version].md.json</b><br>\n";
    }
    if (!$unlinkError)
      echo "La version $_POST[version] de la carte $_POST[map] a bien été supprimée<br>\n";
    break;
  }
  default: {
    echo "<pre>_POST="; print_r($_POST); echo "</pre>\n";
    echo "<pre>_GET="; print_r($_GET); echo "</pre>\n";
    echo "<b>Erreur: action $action inconnue</b><br>\n";
    break;
  }
}

if (!($_GET['map'] ?? null)) { // liste des cartes du portefeuille avec possibilité d'en sélectionner une
  echo "<h2>Gestion du poids des cartes du portefeuille</h2>\n";
  $activated = []; // liste des cartes activées cad présentes dans current
  foreach (new \DirectoryIterator("$PF_PATH/current") as $map) {
    if (!in_array($map, ['.','..','.DS_Store']))
      $activated[substr($map, 0, 4)] = 1;
  }
 
  exec("du -s $PF_PATH/archives/*", $output, $result_code);
  $maps = []; // [{maNum} => ['duMb'=> poids de la carte en Mo, 'nbVersions'=> nbre de versions]
  $duMbSum = 0; // poids total des cartes
  foreach ($output as $outputl) {
    if (!preg_match("!^(\d+)\s$PF_PATH/archives/(\d+)$!", $outputl, $matches))
      throw new \Exception("No match for $outputl");
    $duMb = intval($matches[1])/1024;
    $mapNum = $matches[2];
    $duMbSum += $duMb;
    $maps[$mapNum] = ['duMb' => $duMb, 'nbVersions'=> 0];
    foreach (new \DirectoryIterator("$PF_PATH/archives/$mapNum") as $version) {
      if (substr($version, -3)=='.7z')
        $maps[$mapNum]['nbVersions']++;
    }
  }
  arsort($maps);
  echo "<table border=1>\n",
       "<tr><td></td><td align='right'>",sprintf('%.1f Mb', $duMbSum),"</td>",
        "<td></td><td>Total des cartes</td></tr>\n",
       "<tr><td></td><td align='right'>",sprintf('%.1f Mb', $duMbSum/count($maps)),"</td>",
        "<td></td><td>Moyenne par carte</td></tr>\n",
       "<th></th><th>Mb</th><th>#vers</th><th>Num et titre de la carte</th>\n";
  foreach ($maps as $mapNum => $map) {
    $mapCat = \mapcat\MapCat::get($mapNum);
    $ss = $mapCat->obsoleteDate ? '<s>' : '';
    $se = $mapCat->obsoleteDate ? '</s>' : '';
    echo "<tr><td>",(!($activated[$mapNum] ?? null)) ? 'N' : '',"</td>\n", // carte activée ou non
         "<td align='right'>",sprintf('%.1f Mb', $map['duMb']),"</td>",
         "<td align='right'>$map[nbVersions]</td>",
         "<td>$ss<a href='?map=$mapNum'>$mapNum</a> - $mapCat->title$se</td>", //num, lien et titre
         //"<td align='right'>{$ss}1:",$mapCat->scaleDenominator(),"$se</td>", // échelle
         //"<td>$ss",implode(', ',$mapCat->mapsFrance),"$se</td>", // zone ZEE
         //<td>$ss<pre>",Yaml::dump($mapCat->asArray()),"</pre>$se</td>",
         "</tr>\n";
  }
  echo "</table>\n";
}

else { // liste de versions pour la carte $_GET['map']
  $mapCat = \mapcat\MapCat::get($_GET['map']);
  echo "<h2>Carte $_GET[map] - $mapCat->title</h2>\n";
  echo "<pre>",Yaml::dump($mapCat->asArray(), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
  
  // Détermination de la version courante
  $currentVersion = '';
  if (is_file("$PF_PATH/current/$_GET[map].md.json")) {
    $currentVersion = basename(readlink("$PF_PATH/current/$_GET[map].md.json"),'.md.json');
    //echo "currentVersion=$currentVersion<br>\n";
  }
  
  exec("du $PF_PATH/archives/$_GET[map]/*.7z", $output, $result_code);
  $duMbs = []; // poids de chaque version en Mo
  $duMbSum = 0; // poids total
  foreach ($output as $outputl) {
    if (!preg_match("!^(\d+)\s$PF_PATH/archives/$_GET[map]/(.+)\.7z$!", $outputl, $matches))
      throw new \Exception("No match for $outputl");
    $duMbs[$matches[2]] = intval($matches[1])/1024;
    $duMbSum += intval($matches[1])/1024;
  }
  //echo "<pre>"; print_r($duMbs); echo "</pre>\n";
  
  echo "<table border=1>\n",
       "<tr><td></td><td>Total</td><td></td><td align='right'>",sprintf('%.1f Mb', $duMbSum),"</td></tr>\n";
  foreach ($duMbs as $mapVersion => $duMb) {
    $md = [];
    if (is_file("$PF_PATH/archives/$_GET[map]/$mapVersion.md.json"))
      $md = json_decode(file_get_contents("$PF_PATH/archives/$_GET[map]/$mapVersion.md.json"), true);
    else
      echo "$PF_PATH/archives/$_GET[map]/$mapVersion.md.json absent<br>\n";
    $bs = ($mapVersion == $currentVersion) ? '<b>' : '';
    $be = ($mapVersion == $currentVersion) ? '</b>' : '';
    $hiddens = ['action'=> 'deleteVersion', 'map'=> $_GET['map'], 'version'=> $mapVersion];
    echo "<tr><td><a href='maparchive.php?rpath=/archives/$_GET[map]/$mapVersion.7z'>$bs$mapVersion$be</a></td>",
         //"<td>",json_encode($md),"</td>",
         "<td>",$md['edition'] ?? 'edition non définie',"</td>",
         "<td>",$md['dateArchive'] ?? '',"</td>",
         "<td>",sprintf('%.1f Mb', $duMb),"</td>",
         "<td><a href='shomgeotiff.php/archives/$_GET[map]/$mapVersion.7z'>Télécharger l'archive 7z</a></td>",
         "<td>",($mapVersion <> $currentVersion) ?
             new \html\Form(submit: 'supprimer', hiddens: $hiddens, method: 'post') : '',"</td>",
         "</tr>\n";
  }
  echo "</table>\n";
  echo "<a href='pfcurrent.php?map=$_GET[map]'>Gestion de la version courante</a><br>\n";
  echo "<a href='pfweight.php'>Retour à la liste des cartes</a><br>\n";
}
echo "<a href='index.php'>Retour au menu du BO</a></p>\n";
