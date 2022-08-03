<?php
/*PhpDoc:
name: login.php
title: login.php - page de login
doc: |
  Gère le process de login et de logout
  Pour se loguer au site, il faut un compte défini dans secrets/secretconfig.inc.php
  Ce script correspond aux cas d'utilisation suivants:
    1) L'action de se loguer correspond à demander à l'utilisateur son login/pwd
       et s'il est correct à créer un cookie avec cette information.
       Si le login est correctement réalisé alors l'utilisateur est renvoyé vers la page d'accueil du site
    //2) login par appel d'un URL
    3) lorsque le script est appelé alors que l'utilisateur est déjà logué:
      - on lui indique qu'il est logué
      - on lui propose de se déloguer
    4) action de logout : le cookie est supprimé
  
journal: |
  8/1/2021:
    réécriture comme classe statique
  22/7/2018:
    remplacement des références vers http://localhost/~benoit/ par http://localhost/
  11/6/2017:
    login avec une URL
  10/6/2017:
    création
includes: [ lib/accesscntrl.inc.php ]
*/
require_once __DIR__.'/shomgt/lib/accesscntrl.inc.php';

class Login {
  const COOKIE_NAME = 'shomusrpwd'; // le nom du cookie utilisé pour enregistrer le login/passwd
  // Formulaire de login
  const FORM = "<form method='post'>
    identifiant:  <input type='text' size=80 name='login' /><br>
    mot de passe: <input type='password' size=80 name='password' /><br>
    <input type='submit' value='Envoi' />
  </form>\n";

  /*static function loginUsingGet() { // login avec paramètre en GET
    if (!Access::cntrl($_GET['login']))
      die("Identifiant/mot de passe incorrect<br>Site à accès restreint, veuillez vous loguer".self::FORM);
    elseif (setcookie(self::COOKIE_NAME, $_GET['login'], time()+60*60*24*30)) {
      header('HTTP/1.1 302 Moved Temporarily');
      header("Location: index.php");
      die("Login/mot de passe correct, vous êtes authentifiés pour 30 jours<br>\n");
    } else
      die("Erreur de création du cookie<br>\n");
  }*/
  
  static function loginUsingPost(): never { // Cas d'utilisation no 1 : appel du script par un utilisateur non logué
    // 3 cas d'appels:
    // 1.1) appel sans paramètre de login -> affichage du formulaire
    // 1.2) appel avec paramètres de login et login non conforme -> affichage d'un message d'erreur et du formulaire
    // 1.3) appel avec paramètres de login et login conforme -> xxxx
    // cas d'appel 1.1: appel sans paramètre de login -> affichage du formulaire
    if (!isset($_POST['login']) || !isset($_POST['password']))
      die("Site à accès restreint, veuillez vous loguer".self::FORM);
    // cas d'appel 1.2: appel avec paramètres de login et login non conforme -> affichage d'un message d'erreur et du formulaire
    elseif (!Access::cntrl("$_POST[login]:$_POST[password]"))
      die("Identifiant/mot de passe incorrect<br>Site à accès restreint, veuillez vous loguer".self::FORM);
    elseif (setcookie(self::COOKIE_NAME, "$_POST[login]:$_POST[password]", time()+60*60*24*30)) {
      header('HTTP/1.1 302 Moved Temporarily');
      header("Location: index.php");
      die("Login/mot de passe correct, vous êtes authentifiés pour 30 jours<br>\n");
    }
    else
      die("Erreur de création du cookie<br>\n");
  }
  
  static function logout(): never { // logout
    if (setcookie(self::COOKIE_NAME, 'authorized', -3600))
      die("Vous êtes bien délogué<br>\n<a href='?action=login'>Se reloguer ?<br>\n");
    else
      die("Erreur de suppression du cookie<br>\n");
  }
  
  static function loggedIn(): void {
    if (isset($_COOKIE[self::COOKIE_NAME]) && Access::cntrl($_COOKIE[self::COOKIE_NAME])) {
      $login = substr($_COOKIE[self::COOKIE_NAME], 0, strpos($_COOKIE[self::COOKIE_NAME], ':'));
      die("Vous êtes déjà logué comme $login<br><a href='?action=logout'>Se déloguer ?<br>\n");
    }
  }
};


// Cas d'utilisation no 4 : action de logout
if (isset($_GET['action']) && ($_GET['action']=='logout')) {
  Login::logout();
}

// Cas d'utilisation no 2 : login par appel d'un URL avec login en paramètre GET
/*if (isset($_GET['login'])) {
  Login::loginUsingGet();
}*/

// Cas d'utilisation no 3 : appel du script par un utilisateur déjà logué
Login::loggedIn();

// Cas d'utilisation no 1 : appel du script par un utilisateur non logué
Login::loginUsingPost();
