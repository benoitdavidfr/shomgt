<?php
// shomgt/bo/index.php - BO de ShomGT - Benoit DAVID - 17/7/2023

require_once __DIR__.'/login.inc.php';

define ('HTML_HEAD', "<!DOCTYPE html>\n<html><head><title>shomgt-bo</title></head><body>\n");

if (!($login = Login::login())) { // code en cas de non loggin
  switch ($_GET['action'] ?? null) {
    case null:
    case 'login': 
    case 'logout': {
      if (!isset($_POST['login']) || !isset($_POST['password'])) {
        echo HTML_HEAD,"<h2>Interface de gestion de ShomGt</h2>\n";
        echo "Site à accès restreint, veuillez vous loguer",Login::FORM,
             "</p>ou <a href='?action=signup'>créer un compte</a><br>\n";
        die();
      }
      // cas d'appel 1.2: appel avec paramètres de login et login non conforme -> affichage d'un message d'erreur et du formulaire
      elseif (!Access::cntrl("$_POST[login]:$_POST[password]")) {
        echo HTML_HEAD,"<h2>Interface de gestion de ShomGt</h2>\n";
        echo "Identifiant/mot de passe incorrect<br>Site à accès restreint, veuillez vous loguer",Login::FORM,
             "</p>ou <a href='?action=signup'>créer un compte</a><br>\n";
        die();
      }
      elseif (setcookie(Login::COOKIE_NAME, "$_POST[login]:$_POST[password]", time()+60*60*24*30)) {
        echo HTML_HEAD,"<h2>Interface de gestion de ShomGt ($_POST[login])</h2>\n";
        echo "Login/mot de passe correct, vous êtes authentifiés pour 30 jours<br>\n";
        break;
      }
      else {
        echo HTML_HEAD,"<h2>Interface de gestion de ShomGt</h2>\n";
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
      echo HTML_HEAD,"<h2>Interface Back Office (BO) de ShomGt ($login)</h2>\n";
    echo "<ul>\n";
    echo "<li><a href='?action=logout'>Se déloguer</a></li>\n";
    echo "<li><a href='addmaps.php'>Déposer de nouvelles versions de cartes dans le portefeuille</a></li>\n";
    //echo "<li><a href='?action=mapcat'>Modifier le catalogue des cartes</li>\n";
    //echo "<li><a href='?action=obsoleteMap'>Déclarer une carte obsolète</a></li>\n";
    echo "</ul>\n";
    echo "<h3>Fonctions d'administration</h3>\n";
    echo "<ul>\n";
    echo "<li><a href='pfcurrent.php'>Gérer l'activation des cartes du portefeuille</a></li>\n";
    echo "<li><a href='pfweight.php'>Gérer le poids du portefeuille</a></li>\n";
    echo "</ul>\n";
    die();
  }
  case 'logout': {
    if (setcookie(Login::COOKIE_NAME, 'authorized', -3600)) {
      echo HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
      die("Vous êtes bien délogué<br>\n<a href='?action=login'>Se reloguer ?<br>\n");
    }
    else
      die("Erreur de suppression du cookie<br>\n");
  }
  case 'mapcat': {
    echo HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
    die("<a href='?action=menu'>Retour au menu</a>\n");
  }
  case 'obsoleteMap': {
    echo HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
    die("<a href='?action=menu'>Retour au menu</a>\n");
  }
  default: {
    echo HTML_HEAD,"<h2>Interface de gestion de ShomGt ($login)</h2>\n";
    echo "Erreur, action '$_GET[action]' non prévue<br>\n";
    die("<a href='?action=menu'>Retour au menu</a>\n");
  }
}
