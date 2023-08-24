<?php
die("PERIME");
/*PhpDoc:
title: bo/viewmap.php - Visualisation d'une carte Shom 7z pour éventuelle validation- Benoit DAVID - 7-8/2023
 * Utilisé de 3 manières:
 *  - en autonome propose de visualiser les livraisons et les archives
 *  - appelé par addmaps.php pour visualiser et valider une carte 7z
 *  - appelé par pfcurrent.php et pfweight.php pour visualiser une carte 7z
 *
 * Utilise georaster-layer-for-leaflet pour visualiser des tif dans une carte Leaflet
 * Permet aussi de visualiser les extensions spatiales fournies dans MapCat
 *
 * paramètres GET
 *  - path - chemin du répertoire contenant des 7z de cartes ou d'autres répertoires
 *  - map  - nom de base du fichier 7z d'une carte (sans l'extension .7z)
 *  - return - permet de définir un éventuel mécanisme de retour
 *
 * Faire des tests de viewmap avec:
 *  - 2 cartes normales standard sans cartouches
 *  - 2 cartes normales standard avec cartouches
 *  - toutes les cartes spéciales anciennes et nouvelles
 *  - la carte normale mal géoréférencée
 *  - toutes les cartes normales à cheval sur l'antiméridien
*/
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/mapmetadata.inc.php';
require_once __DIR__.'/maparchive.php';

define ('HTML_HEAD', "<!DOCTYPE html>\n<html><head><title>viewmap</title></head><body>\n");
//define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
define ('TEST_MAPS', [
    "2 cartes normales sans cartouche" => [
      'path=/incoming/20230710&map=6735' =>
        "6735 - Pas de Calais - De Boulogne-sur-Mer à Zeebrugge, estuaire de la Tamise (Thames)",
      'path=/archives/7441&map=7441-2009' => "7441-2009 - Abords et Ports de Monaco",
    ],
    "2 cartes normales avec partie principale et cartouches"=> [
      'path=/archives/7594&map=7594-1903' => "7594-1903 - De la Pointe Ebba au Cap de la Découverte",
      'path=/incoming/20230710&map=7090' => "7090 - De la Pointe de Barfleur à Saint-Vaast-la-Hougue",
    ],
    "2 cartes normales avec cartouches mais sans partie principale" => [
      'path=/archives/7207&map=7207-2303'=> "7207-2303 - Ports de Fécamp et du Tréport",
      'path=/archives/7427&map=7427-1724'=>
        "7427-1724 - La Gironde- De Mortagne-sur-Gironde au Bec d'Ambès"
        ." - La Garonne et La Dordogne jusqu'à Bordeaux et Libourne",
    ],
    "Carte 7620 mal géoréférencée" => [
      'path=/archives/7620&map=7620-1903'=> "7620-1903 - Approches d'Anguilla",
      'path=/archives/7620&map=7620-2242'=> "7620-2242 - Approches d'Anguilla",
    ],
    "Les anciennes cartes spéciales" => [
      'path=/archives/7330&map=7330-1726'=> 
        "7330-1726 - De Cherbourg à Hendaye - Action de l'Etat en Mer en Zone Maritime Atlantique",
      'path=/archives/7344&map=7344-1726'=>
        "7344-1726 - De Brest à la frontière belge - Action de l'Etat en Mer - Zone Manche et Mer du Nord",
      'path=/archives/7360&map=7360-1726'=>
        "7360-1726 - De Cerbère à Menton - Action de l'Etat en Mer - Zone Méditerranée",
      'path=/archives/8101&map=8101-1726'=> "8101-1726 - MANCHEGRID - Carte générale",
      'path=/archives/8502&map=8502-1726'=> "8502-1726 - Action de l'Etat en Mer en ZMSOI",
      'path=/archives/8509&map=8509-1944'=> 
        "8509-1944 - Action de l''Etat en Mer - Nouvelle-Calédonie - Wallis et Futun",
      'path=/archives/8510&map=8510-1944'=> "8510-1944 - Délimitations des zones maritimes",
      'path=/archives/8517&map=8517-1944'=>
        "8517-1944 - Carte simplifiée de l''action de l''Etat en Mer des ZEE Polynésie Française et Clipperton",
      'path=/archives/8523&map=8523-2224'=>
        "8523-2224 - Carte d''Action de l'État en Mer - Océan Atlantique Nord - Zone maritime Antilles-Guyane",
    ],
    "Les nouvelles cartes spéciales" => [
      'path=/incoming/20230628aem&map=7330'=> 
        "7330 - De Cherbourg à Hendaye - Action de l'Etat en Mer en Zone Maritime Atlantique",
      'path=/doublons/20230626&map=7344'=>
        "7344 - Carte d’Action de l’État en Mer Zone Manche et Mer du Nord - \"De Brest à la frontière Belge\"",
      'path=/doublons/20230626&map=7360'=>
        "7360 - Carte d’Action de l’État en Mer Zone Méditerranée - \"De Cerbère à Menton\"",
      'path=/attente/20230628aem&map=8502'=>
        "8502 - Carte d’Action de l'État en Mer en Zone Maritime Sud de l'Océan Indien ZMSOI",
      'path=/attente/20230628aem&map=8509'=>
        "8509 - Carte d’Action de l’État en Mer - Nouvelle-Calédonie - Wallis et Futuna",
      'path=/attente/20230628aem&map=8510'=> "8510 - Délimitation des zones maritimes",
      'path=/attente/20230628aem&map=8517'=> 
        "8517 - Carte simplifiée d’Action de l’État en Mer des ZEE Polynésie française et Clipperton",
      'path=/attente/20230628aem&map=8523'=>
        "8523 - Carte d’Action de l’État en Mer - Océan Atlantique Nord - Zone maritime Antilles-Guyane",
    ],
    "Cartes à cheval sur l'antiméridien" => [
      'path=/archives/6835&map=6835-2311'=> "6835-2311 - Océan Pacifique Nord - Partie Est",
      'path=/archives/6977&map=6977-2304'=> "6977-2304 - Océan Pacifique Nord - Partie Nord-Ouest",
      'path=/archives/7021&map=7021-2308'=> "7021-2308 - Océan Pacifique Nord - Partie Sud-Ouest",
      'path=/archives/7271&map=7271-1726'=> "7271-1726 - Australasie et mers adjacentes",
      /*
      7271
      7166
      6671
      6670
      6817
      7283
      */
    ],
    "Tests d'erreurs"=> [
      'path=/attente/20230628aem&map=xx'=> "Le fichier n'existe pas",
    ],
  ]
); // cartes de tests 
define ('MIN_FOR_DISPLAY_IN_COLS', 100); // nbre min d'objets pour affichage en colonnes
define ('NBCOLS_FOR_DISPLAY', 24); // nbre de colonnes si affichage en colonnes

use Symfony\Component\Yaml\Yaml;

$login = Login::loggedIn() or die("Accès non autorisé\n");

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH'))) {
  die("Erreur variable d'environnement SHOMGT3_PORTFOLIO_PATH non définie\n");
}

if (!isset($_GET['path'])) { // affichage de la liste des livraisons 
  echo HTML_HEAD,"<h2>Livraisons et archives</h2>\n";
  $groups = [
    '/incoming'=> "Livraisons",
    '/attente'=> "Livraisons en attente",
    '/archives'=> "Archives de cartes",
  ];
  foreach ($groups as $gname => $title) {
    echo "<h3>$title</h3>\n";
    $incomings = []; // liste des livraisonw ou archives
    foreach (new DirectoryIterator($PF_PATH.$gname) as $incoming) {
      if (in_array($incoming, ['.','..','.DS_Store'])) continue;
      $incomings[] = (string)$incoming;
    }
    $nbincomings = count($incomings);
    if ($nbincomings < MIN_FOR_DISPLAY_IN_COLS) { // affichage sans colonne
      echo "<ul>\n";
      foreach ($incomings as $incoming) {
        echo "<li><a href='?path=$gname/$incoming'>$incoming</a></li>\n";
      }
      echo "</ul>\n";
    }
    else { // affichage en colonnes
      //echo "nbincomings=$nbincomings<br>\n";
      echo "<table border=1><tr>\n";
      $i = 0;
      for ($nocol=0; $nocol < NBCOLS_FOR_DISPLAY; $nocol++) {
        echo "<td valign='top'>\n";
        //echo "max=",$nbincomings / NBCOLS_FOR_DISPLAY * ($nocol+1),"<br>\n";
        //echo "floor(max)=",floor($nbincomings / NBCOLS_FOR_DISPLAY * ($nocol+1)),"<br>\n";
        while ($i < round($nbincomings / NBCOLS_FOR_DISPLAY * ($nocol+1))) {
          //echo "i=$i\n";
          $incoming = $incomings[$i];
          //echo "<li><a href='?path=$gname/$incoming'>$incoming</a></li>\n";
          echo "&nbsp;<a href='?path=$gname/$incoming'>$incoming</a>&nbsp;<br>\n";
          $i++;
        }
        echo "</td>\n";
      }
      echo "</tr></table>\n";
    }
  }
  echo "</p><a href='?path=tests&action=tests'>Cartes de tests</a></p>\n";
  die();
}

if (($_GET['action'] ?? null) == 'tests') {
  echo HTML_HEAD,"<h3>Cartes de test</h3>\n";
  foreach (TEST_MAPS as $gtitle => $group) {
    echo "<h4>$gtitle</h4><ul>\n";
    foreach ($group as $path => $title)
      echo "<li><a href='?$path'>$title</a></li>\n";
    echo "</ul>\n";
  }
  die();
}

if (!isset($_GET['map'])) { // affichage du contenu de la livraison ou du répertoire d'archives 
  echo HTML_HEAD;
  if (!is_dir($PF_PATH.$_GET['path']))
    die("<b>Erreur, le répertoire $_GET[path] n'existe pas<br>\n");
  echo "<h2>Répertoire $_GET[path]</h2>\n";
  echo "<ul>\n";
  foreach (new DirectoryIterator($PF_PATH.$_GET['path']) as $map) {
    if (substr($map, -3) <> '.7z') continue;
    $mapNum = substr($map, 0, 4);
    $md = MapMetadata::getFrom7z("$PF_PATH$_GET[path]/$map");
    $label = $md['title'] ?? substr($map, 0, -3);
    if (isset($md['version']))
      $label .= " (version $md[version])";
    echo "<li><a href='?path=$_GET[path]&map=$mapNum'>$label</a></li>\n";
  }
  echo "</ul>\n";
  
  $first = true;
  foreach (new DirectoryIterator($PF_PATH.$_GET['path']) as $entry) {
    //echo "$entry<br>\n";
    if (in_array($entry, ['.','..','.DS_Store'])) continue;
    if (!is_dir("$PF_PATH$_GET[path]/$entry")) continue;
    if ($first) {
      echo "<h3>Sous-répertoires</h3><ul>\n";
      $first = false;
    }
    echo "<li><a href='?path=$_GET[path]/$entry'>$entry</li>\n";
  }
  die();
}

if (!is_file("$PF_PATH$_GET[path]/$_GET[map].7z")) {
  die("Erreur le fichier $PF_PATH$_GET[path]/$_GET[map].7z n'existe pas\n");
}

switch ($_GET['action'] ?? null) {
  case null: { // affichage des caractéristiques de la carte
    echo HTML_HEAD;
    $mapNum = substr($_GET['map'], 0, 4);
    $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
    $map->showAsHtml($_GET['return'] ?? null);
    die();
  }
  case 'gdalinfo': { // affichage du gdalinfo correspondant à un tif
    $archive = new My7zArchive("$PF_PATH$_GET[path]/$_GET[map].7z");
    $path = $archive->extract($_GET['tif']);
    $gdalinfo = new GdalInfoBo($path);
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode($gdalinfo->asArray(), JSON_OPTIONS);
    $archive->remove($path);
    die();
  }
  case 'insetMapping': { // affiche le détail de la correspondance entre cartouches 
    $mapNum = substr($_GET['map'], 0, 4);
    $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
    $mappingInsetsWithMapCat = $map->mappingInsetsWithMapCat(true);
    echo "<pre>mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
    sort($mappingInsetsWithMapCat);
    echo "mappingInsetsWithMapCat = "; print_r($mappingInsetsWithMapCat);
    $mapCat = MapCat::get($mapNum);
    echo "insetTitlesSorted = "; print_r($mapCat->insetTitlesSorted());
    if ($mappingInsetsWithMapCat <> $mapCat->insetTitlesSorted())
      echo "Il n'y a pas de bijection entre les cartouches définis dans l'archive et ceux définis dans MapCat";
    die();
  }
  case 'show7zContents': { // affiche le contenu de l'archive
    $archive = new My7zArchive("$PF_PATH$_GET[path]/$_GET[map].7z");
    echo HTML_HEAD,
         "<b>Contenu de l'archive $_GET[map].7z:</b><br>\n",
         "<pre><table border=1><th>DateTime</th><th>Attr</th><th>Size</th><th>Compressed</th><th>Name</th>\n";
    foreach ($archive as $entry) {
      //echo Yaml::dump([$entry]);
      if ($entry['Attr'] == '....A') {
        $href = "shomgeotiff.php/$_GET[path]/$_GET[map].7z/$entry[Name]";
        echo "<tr><td>$entry[DateTime]</td><td>$entry[Attr]</td><td align='right'>$entry[Size]</td>",
             "<td align='right'>$entry[Compressed]</td><td><a href='$href'>$entry[Name]</a></td></tr>\n";
      }
      else {
        echo "<tr><td>$entry[DateTime]</td><td>$entry[Attr]</td><td align='right'>$entry[Size]</td>",
             "<td align='right'>$entry[Compressed]</td><td>$entry[Name]</td></tr>\n";
      }
    }
    echo "</table></pre>";
    die();
  }
  case 'dumpPhp': { // affiche le print_r() Php
    $mapNum = substr($_GET['map'], 0, 4);
    $map = new MapArchive("$PF_PATH$_GET[path]/$_GET[map].7z", $mapNum);
    echo HTML_HEAD,"<pre>"; print_r($map); echo "</pre>";
    die();
  }
  default: {
    die("Action $_GET[action] inconnue\n");
  }
}
