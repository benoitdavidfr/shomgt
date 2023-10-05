<?php
/** Fonctionnalités de login dans le BO - 19/7/2023
 * @package shomgt\bo
 *
 * journal:
 *    -rajout du paramètre $domain dans setcookie() pour prendre en compte les sous-domaines
 */
namespace bo;

require_once __DIR__.'/../lib/accesscntrl.inc.php';

/** Gestion du login */
class Login {
  /** le nom du cookie utilisé pour enregistrer le login/passwd */
  const COOKIE_NAME = 'shomusrpwd';
  /** durée de validité du cookie en nbre de jours */
  const COOKIE_DURATION_IN_DAYS = 30; 
  /** Formulaire de login */
  const FORM = "<form method='post'>
    identifiant:  <input type='text' size=80 name='login' /><br>
    mot de passe: <input type='password' size=80 name='password' /><br>
    <input type='submit' value='Envoi' />
  </form>\n";
  
  /** Si logué retourne le login en cookie, sinon retourne null */
  static function loggedIn(): ?string {
    return (isset($_COOKIE[self::COOKIE_NAME]) && \Access::cntrl($_COOKIE[self::COOKIE_NAME])) ? 
      substr($_COOKIE[self::COOKIE_NAME], 0, strpos($_COOKIE[self::COOKIE_NAME], ':'))
        : null;
  }
  
  /** Si logué retourne le login, sinon propose à l'utilisateur de se loguer en affichant $htmlHeadAndTitle.
   * si login Ok alors retourne le login, sinon arrête l'exécution avec un message proposant de s'enregistrer
   * à l'URL définie dans $registerUrl */
  static function login(string $htmlHeadAndTitle, string $registerUrl): ?string {
    // si l'utilisateur est déjà logué alors renvoie son identifiant de login
    if ($login = Login::loggedIn()) {
      return $login;
    }
    
    // pas de paramètre de login -> affichage du formulaire de login
    if (!isset($_POST['login']) || !isset($_POST['password'])) {
      echo $htmlHeadAndTitle,
           "Site à accès restreint, veuillez vous loguer",Login::FORM,
           "</p>ou <a href='$registerUrl'>vous inscrire ou changer votre mot de passe si vous l'avez oublié.</a>.<br>\n";
      die();
    }
    
    // appel avec paramètres de login incorrects -> affichage d'un message d'erreur et du formulaire
    if (!\Access::cntrl("$_POST[login]:$_POST[password]")) {
      echo $htmlHeadAndTitle,
           "Identifiant/mot de passe incorrect<br>Site à accès restreint, veuillez vous loguer",Login::FORM,
           "</p>ou <a href='$registerUrl'>vous inscrire sur cette plateforme (en développement)</a>.<br>\n";
      die();
    }
    
    // appel avec paramètres de login corrects -> création de 2 cookies
    // nécessité
    //   - d'utiliser le paramètre path pour que le cookie soit aussi disponible dans le front
    //   - de faire 2 appels, l'un avec le paramètre domain et l'autre sans afin que le cookie soit défini
    //     dans le domaine principal et les sous-domaines
    $parent = dirname(dirname($_SERVER['SCRIPT_NAME']));
    if ($parent <> '/') $parent .= '/';
    setcookie(
      Login::COOKIE_NAME, // string $name,
      "$_POST[login]:$_POST[password]", // string $value = "",
      time()+60*60*24*self::COOKIE_DURATION_IN_DAYS, // int $expires_or_options = 0,
      $parent, //  string $path = "",
      '') // string $domain = "",
        // Erreur de création du cookie
        or die("$htmlHeadAndTitle Erreur de création du cookie<br>\n");
    setcookie(
      Login::COOKIE_NAME, // string $name,
      "$_POST[login]:$_POST[password]", // string $value = "",
      time()+60*60*24*self::COOKIE_DURATION_IN_DAYS, // int $expires_or_options = 0,
      $parent, //  string $path = "",
      $_SERVER['HTTP_HOST']) // string $domain = "",
        // Erreur de création du cookie
        or die("$htmlHeadAndTitle Erreur de création du cookie<br>\n");
  
    // login ok
    echo "Login/mot de passe correct, vous êtes authentifiés pour ",self::COOKIE_DURATION_IN_DAYS," jours<br>\n";
    return $_POST['login'];
  }
  
  /* Réalise un logout en effacant le cookie adhoc */
  static function logout(string $HTML_HEAD, string $login): never {
    $parent = dirname(dirname($_SERVER['SCRIPT_NAME']));
    if ($parent <> '/') $parent .= '/';
    setcookie(Login::COOKIE_NAME, '', -1, $parent)
      or die("Erreur de suppression du cookie<br>\n");
    setcookie(Login::COOKIE_NAME, '', -1, $parent, $_SERVER['HTTP_HOST'])
      or die("Erreur de suppression du cookie<br>\n");
    echo "$HTML_HEAD<h2>Interface de gestion de ShomGt ($login)</h2>\n";
    die("Vous êtes bien délogué<br>\n<a href='index.php'>Se reloguer ?<br>\n");
  }
};
