<?php
// shomgt/bo/index.php - BO de ShomGT4 - Benoit DAVID - 5/8/2023
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/login.inc.php';

use Symfony\Component\Yaml\Yaml;

$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>shomgt-bo@$_SERVER[HTTP_HOST]</title></head><body>\n";

if (!($login = Login::login())) { // code en cas de non loggin
  switch ($_GET['action'] ?? null) {
    case null:
    case 'login': 
    case 'logout': {
      if (!isset($_POST['login']) || !isset($_POST['password'])) {
        echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt</h2>\n";
        echo "Site à accès restreint, veuillez vous loguer",Login::FORM,
             "</p>ou <a href='?action=signup'>créer un compte</a><br>\n";
        die();
      }
      // cas d'appel 1.2: appel avec paramètres de login et login non conforme -> affichage d'un message d'erreur et du formulaire
      elseif (!Access::cntrl("$_POST[login]:$_POST[password]")) {
        echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt</h2>\n";
        echo "Identifiant/mot de passe incorrect<br>Site à accès restreint, veuillez vous loguer",Login::FORM,
             "</p>ou <a href='?action=signup'>créer un compte</a><br>\n";
        die();
      }
      elseif (setcookie(Login::COOKIE_NAME, "$_POST[login]:$_POST[password]", time()+60*60*24*30)) {
        echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt ($_POST[login])</h2>\n";
        echo "Login/mot de passe correct, vous êtes authentifiés pour 30 jours<br>\n";
        break;
      }
      else {
        echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt</h2>\n";
        die("Erreur de création du cookie<br>\n");
      }
    }
    case 'signup': {
      if (!isset($_POST['email'])) {
        echo "<form method='post'>
          adresse email:  <input type='text' size=80 name='email' /><br>
          <input type='submit' value='Envoi' />
        </form>\n";
        die();
      }
      else {
        die("Création d'un compte avec $_POST[email]\n");
      }
    }
    default: {
      die("Erreur, action '$_GET[action]' non prévue\n");
    }
  }
}

if (!$login) {
  $login = $_POST['login'];
}
  
switch ($_GET['action'] ?? null) {
  case null:
  case 'login':
  case 'menu': { // Menu après login
    if (!isset($_POST['login']))
      echo $HTML_HEAD,"<h2>Interface Back Office (BO) de ShomGt ($login)</h2>\n";
    echo "<ul>\n";
    echo "<li><a href='?action=logout'>Se déloguer</a></li>\n";
    echo "<li><a href='../dashboard/' target='_blank'>",
         "Identifier les cartes à ajouter/supprimer/actualiser dans le portefeuille ShomGT</a></li>\n";
    echo "<li><a href='https://diffusion.shom.fr' target='_blank'>",
         "Télécharger de nouvelles versions de cartes sur le site du Shom</a></li>\n";
    echo "<li><a href='addmaps.php'>Déposer ces nouvelles versions de cartes dans le portefeuille</a></li>\n";
    //echo "<li><a href='?action=mapcat'>Modifier le catalogue des cartes</li>\n";
    //echo "<li><a href='?action=obsoleteMap'>Déclarer une carte obsolète</a></li>\n";
    echo "</ul>\n";
    echo "<h3>Fonctions d'administration</h3>\n";
    echo "<ul>\n";
    echo "<li><a href='pfcurrent.php'>Gérer l'activation des cartes du portefeuille</a></li>\n";
    echo "<li><a href='pfweight.php'>Gérer le poids du portefeuille</a></li>\n";
    echo "<li><a href='maparchivestore.php'>Gère le stockage du portefeuille (liens, clone, ..)</a></li>\n";
    //echo "<li><a href='?action=upgrade1'>Modification des versions des cartes spéciales - 3/8/2023</a></li>\n";
    echo "</ul>\n";
    die();
  }
  case 'logout': {
    if (setcookie(Login::COOKIE_NAME, 'authorized', -3600)) {
      echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
      die("Vous êtes bien délogué<br>\n<a href='?action=login'>Se reloguer ?<br>\n");
    }
    else
      die("Erreur de suppression du cookie<br>\n");
  }
  case 'mapcat': {
    echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
    die("<a href='?action=menu'>Retour au menu</a>\n");
  }
  case 'obsoleteMap': {
    echo $HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
    die("<a href='?action=menu'>Retour au menu</a>\n");
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
    die("<a href='?action=menu'>Retour au menu</a>\n");
  }
}
