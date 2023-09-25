<?php
/** Fonctionnalités de login dans le BO - 19/7/2023
 * @package shomgt\bo
 */
namespace bo;

require_once __DIR__.'/../lib/accesscntrl.inc.php';

class Login { // Fonctionnalités de login 
  const COOKIE_NAME = 'shomusrpwd'; // le nom du cookie utilisé pour enregistrer le login/passwd
  const COOKIE_DURATION_IN_DAYS = 30; 
  // Formulaire de login
  const FORM = "<form method='post'>
    identifiant:  <input type='text' size=80 name='login' /><br>
    mot de passe: <input type='password' size=80 name='password' /><br>
    <input type='submit' value='Envoi' />
  </form>\n";
  
  static function loggedIn(): ?string { // Si logué retourne le login en cookie, sinon retourne null
    return (isset($_COOKIE[self::COOKIE_NAME]) && \Access::cntrl($_COOKIE[self::COOKIE_NAME])) ? 
      substr($_COOKIE[self::COOKIE_NAME], 0, strpos($_COOKIE[self::COOKIE_NAME], ':'))
        : null;
  }
  
  // Si logué retourne le login, sinon propose à l'utilisateur de se loguer en affichant $htmlHeadAndTitle
  // si login Ok alors retourne le login, sinon arrête l'exécution avec un message proposant de s'enregistrer
  // à l'URL définie dans $registerUrl
  static function login(string $htmlHeadAndTitle, string $registerUrl): ?string {
    if ($login = Login::loggedIn()) {
      return $login;
    }
    elseif (!isset($_POST['login']) || !isset($_POST['password'])) { // pas de paramètre de login
      echo $htmlHeadAndTitle,
           "Site à accès restreint, veuillez vous loguer",Login::FORM,
           "</p>ou <a href='$registerUrl'>vous inscrire ou changer votre mot de passe si vous l'avez oublié.</a>.<br>\n";
      die();
    }
    // appel avec paramètres de login incorrects -> affichage d'un message d'erreur et du formulaire
    elseif (!\Access::cntrl("$_POST[login]:$_POST[password]")) {
      echo $htmlHeadAndTitle,
           "Identifiant/mot de passe incorrect<br>Site à accès restreint, veuillez vous loguer",Login::FORM,
           "</p>ou <a href='$registerUrl'>vous inscrire sur cette plateforme (en développement)</a>.<br>\n";
      die();
    }
    // appel avec paramètres de login corrects -> création d'un cookie
    // nécessité d'utiliser le paramètre path pour que le cookie soit aussi disponible dans le front
    else {
      /*if (!setcookie(Login::COOKIE_NAME, "$_POST[login]:$_POST[password]",
                       time()+60*60*24*self::COOKIE_DURATION_IN_DAYS,)) {
        // Erreur de création du cookie
        echo $htmlHeadAndTitle;
        echo "Erreur de création du cookie courant<br>\n";
      }
      else {
        echo "création ok du cookie courant<br>\n";
      }*/
      
      $parent = dirname(dirname($_SERVER['SCRIPT_NAME']));
      if ($parent <> '/') $parent .= '/';
      if (!setcookie(Login::COOKIE_NAME, "$_POST[login]:$_POST[password]",
                       time()+60*60*24*self::COOKIE_DURATION_IN_DAYS,
                       $parent)) {
        // Erreur de création du cookie
        echo $htmlHeadAndTitle;
        die("Erreur de création du cookie<br>\n");
      }
      else {
        //echo "création ok du cookie sur $parent<br>\n";
      }
    }
    // login ok
    echo "Login/mot de passe correct, vous êtes authentifiés pour ",self::COOKIE_DURATION_IN_DAYS," jours<br>\n";
    return $_POST['login'];
  }
  
  static function logout(string $HTML_HEAD, string $login): never {
    /*if (setcookie(Login::COOKIE_NAME, 'authorized', -1)) {
      echo "$HTML_HEAD<h2>Interface de gestion de ShomGt ($login)</h2>\n";
      echo "Vous êtes bien délogué sur le répertoire par défaut<br>\n";
    }
    else
      die("Erreur de suppression du cookie<br>\n");
    */
    $parent = dirname(dirname($_SERVER['SCRIPT_NAME']));
    if ($parent <> '/') $parent .= '/';
    if (setcookie(Login::COOKIE_NAME, 'authorized', -1, $parent)) {
      echo "$HTML_HEAD<h2>Interface de gestion de ShomGt ($login)</h2>\n";
      die("Vous êtes bien délogué<br>\n<a href='index.php'>Se reloguer ?<br>\n");
    }
    else
      die("Erreur de suppression du cookie<br>\n");
  }
};
