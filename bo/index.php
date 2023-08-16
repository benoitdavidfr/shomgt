<?php
/*PhpDoc:
name: index.php
title: shomgt/bo/index.php - BO de ShomGT4 - Benoit DAVID - 5-13/8/2023
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/login.inc.php';
require_once __DIR__.'/user.php';

use Symfony\Component\Yaml\Yaml;

$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>shomgt-bo@$_SERVER[HTTP_HOST]</title></head><body>\n";
$HTML_TITLE = "<h2>Interface Back Office (BO) de ShomGT</h2>\n";

//createUserTable(); // pour réinitialiser la base en cas de bug

// Si loggé retourne le login, sinon propose de se loguer et si ! ok alors arrête l'exécuction et propose de s'enregistrer
$login = Login::login($HTML_HEAD.$HTML_TITLE, 'user.php?action=register');

switch ($action = ($_GET['action'] ?? null)) {
  case null:
  case 'deleteTempOutputBatch': { // Menu après login avec éventuelle action préalable
    if ($action == 'deleteTempOutputBatch'){ // revient de runbatch.php et efface, s'il existe, le fichier temporaire créé 
      $filename = basename($_GET['filename']);
      //echo "filename=$filename<br>\n";
      if (is_file(__DIR__."/temp/$filename"))
        unlink(__DIR__."/temp/$filename");
    }
    $role = userRole($login);
    $roleDisplay = ($role <> 'normal') ? "/role=$role" : '';
    echo "$HTML_HEAD<h2>Interface Back Office (BO) de ShomGT ($login$roleDisplay)</h2>\n";
    echo "<ul>\n";
    echo "<li><a href='?action=logout'>Se déloguer</a>, <a href='user.php'>gérer son compte</a></li>\n";
    if ($role == 'restricted') die();
    echo "<li><a href='../dashboard/' target='_blank'>",
         "Identifier les cartes à actualiser grâce au tableau de bord de l'actualité des cartes</a></li>\n";
    echo "<li><a href='https://diffusion.shom.fr' target='_blank'>",
         "Télécharger une nouvelle version de cartes sur le site du Shom</a></li>\n";
    echo "<li><a href='addmaps.php'>Déposer cette nouvelle version de carte dans le portefeuille</a></li>\n";
    //echo "<li><a href='?action=mapcat'>Modifier le catalogue des cartes</li>\n";
    //echo "<li><a href='?action=obsoleteMap'>Déclarer une carte obsolète</a></li>\n";
    echo "<li><a href='runbatch.php?batch=sgupdt'>",
         "Prendre en compte les nouvelles versions dans la consultation des cartes</a></li>\n";
    echo "</ul>\n";
    if ($role <> 'admin') die();
    echo "<h3>Fonctions d'administration</h3>\n";
    echo "<ul>\n";
    echo "<li><a href='pfcurrent.php'>Gérer l'activation des cartes du portefeuille</a></li>\n";
    echo "<li><a href='pfweight.php'>Gérer le poids du portefeuille</a></li>\n";
    echo "<li><a href='user.php'>Gérer les utilisateurs</a></li>\n";
    echo "<li><a href='mapcat.php'>Gérer le catalogue</a></li>\n";
    echo "<li><a href='maparchivestore.php'>Gèrer le stockage du portefeuille (liens, clone, ..)</a></li>\n";
    echo "<li><a href='clonedatamaps.php'>Créer dans sgpp/data/maps un clone de shomgt/data/maps</a></li>\n";
    echo "<li><a href='?action=getenv'>Afficher les variables d'environnement</a></li>\n";
    //echo "<li><a href='?action=upgrade1'>Modification des versions des cartes spéciales - 3/8/2023</a></li>\n";
    echo "</ul><h3>Fonctions de test</h3><ul>\n";
    echo "<li><a href='runbatch.php?batch=test'>batchtest</a></li>\n";
    echo "</ul><h3>Documentation du code</h3><ul>\n";
    echo "<li><a href='requiregraph.php'>Graphe des inclusions Php</a></li>\n";
    echo "</ul>\n";
    die();
  }
  case 'logout': {
    if (setcookie(Login::COOKIE_NAME, 'authorized', -3600)) {
      echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
      die("Vous êtes bien délogué<br>\n<a href='index.php'>Se reloguer ?<br>\n");
    }
    else
      die("Erreur de suppression du cookie<br>\n");
  }
  case 'getenv': {
    echo "<pre>getenv()="; print_r(getenv()); echo "</pre>\n";
    die("<a href='index.php'>Retour au menu</a>\n");
  }
  /*case 'upgrade1': { // Modification des versions des cartes spéciales - 3/8/2023
    define('SPECIAL_MAPS', ['7330','7344','7360','8101','8502','8509','8510','8517','8523']); 
    define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
      throw new Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    echo $HTML_HEAD,"<pre>";
    if (0) { // modif .md.json
      foreach (SPECIAL_MAPS as $mapNum) {
        foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $mapVersion) {
          if (!in_array(substr($mapVersion, -8), ['.md.json'])) continue;
          $md = json_decode(file_get_contents("$PF_PATH/archives/$mapNum/$mapVersion"), true);
          if (isset($md['title'])) continue;
          echo "$mapNum/$mapVersion -> "; print_r($md);
          if (substr($md['version'], -4) == '.tif') {
            $md['version'] = substr($md['version'], 0, -4);
            print_r($md);
            file_put_contents("$PF_PATH/archives/$mapNum/$mapVersion", json_encode($md, JSON_OPTIONS));
          }
          if (substr($mapVersion, -12)=='.tif.md.json') {
            $newMapVersion = "$mapNum-$md[version].md.json";
            echo "newMapVersion=$newMapVersion\n";
            rename("$PF_PATH/archives/$mapNum/$mapVersion", "$PF_PATH/archives/$mapNum/$newMapVersion");
          }
          else
            $newMapVersion = $mapVersion;
          unlink("$PF_PATH/current/$mapNum.md.json");
          if (!symlink("../archives/$mapNum/$newMapVersion", "$PF_PATH/current/$mapNum.md.json"))
            throw new Exception("Erreur sur symlink(../archives/$mapNum/$newMapVersion, $PF_PATH/current/$mapNum.md.json)");
        }
      }
    }
    elseif (0) { // renommage fichiers .tif.7z dans archives
      foreach (SPECIAL_MAPS as $mapNum) {
        foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $mapVersion) {
          if (substr($mapVersion, -7)=='.tif.7z') {
            $newMapVersion = substr($mapVersion, 0, -7).'.7z';
            echo "mapVersion=$mapVersion, newMapVersion=$newMapVersion\n";
            rename("$PF_PATH/archives/$mapNum/$mapVersion", "$PF_PATH/archives/$mapNum/$newMapVersion");
            echo "rename($PF_PATH/archives/$mapNum/$mapVersion, $PF_PATH/archives/$mapNum/$newMapVersion)\n";
            //unlink("$PF_PATH/archives/$mapNum/$newMapVersion");
            //echo "unlink($PF_PATH/archives/$mapNum/$newMapVersion);\n";
          }
        }
      }
    }
    elseif (0) { // refaire les liens des .7z en fonction des versions
      foreach (SPECIAL_MAPS as $mapNum) {
        $md = json_decode(file_get_contents("$PF_PATH/current/$mapNum.md.json"), true);
        if (isset($md['title'])) continue;
        print_r($md);
        if (is_file("$PF_PATH/current/$mapNum.7z")) {
          unlink("$PF_PATH/current/$mapNum.7z");
          echo "unlink($PF_PATH/current/$mapNum.7z);\n";
        }
        $target = "../archives/$mapNum/$mapNum-$md[version].7z";
        if (!is_file("$PF_PATH/archives/$mapNum/$mapNum-$md[version].7z"))
          throw new Exception("Erreur fichier $PF_PATH/archives/$mapNum/$mapNum-$md[version].7z absent");
        $link = "$PF_PATH/current/$mapNum.7z";
        if (!symlink($target, $link))
          throw new Exception("Erreur sur symlink($target, $link)");
      }
    }
    else { // controle
      foreach (SPECIAL_MAPS as $mapNum) {
        echo "$mapNum:\n";
        echo "  archives:\n";
        foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $entry) {
          if (in_array($entry, ['.','..','.DS_Store'])) continue;
          echo "    $entry\n";
        }
        echo "  current:\n";
        foreach (['7z','md.json'] as $ext) {
          if (!($link = readlink("$PF_PATH/current/$mapNum.$ext")))
            throw new Exception("Erreur sur readlink($PF_PATH/current/$mapNum.$ext)");
          echo "    $mapNum.$ext -> $link\n";
        }
      }
      foreach (SPECIAL_MAPS as $mapNum) {
        foreach (new DirectoryIterator("$PF_PATH/archives/$mapNum") as $entry) {
          if (substr($entry, -8)<>'.md.json') continue;
          $md[$mapNum][(string)$entry] = json_decode(file_get_contents("$PF_PATH/archives/$mapNum/$entry"), true);
        }
      }
      echo Yaml::dump(['md.json'=> $md], 4, 2);
    }
    break;
  }*/
  default: {
    echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
    echo "Erreur, action '$_GET[action]' non prévue<br>\n";
    die("<a href='index.php'>Retour au menu</a>\n");
  }
}
