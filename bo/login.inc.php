<?php

class Login { // Fonctionnalités de login 
  const COOKIE_NAME = 'shomusrpwd'; // le nom du cookie utilisé pour enregistrer le login/passwd
  // Formulaire de login
  const FORM = "<form method='post'>
    identifiant:  <input type='text' size=80 name='login' /><br>
    mot de passe: <input type='password' size=80 name='password' /><br>
    <input type='submit' value='Envoi' />
  </form>\n";
  
  static function login() {
    return (isset($_COOKIE[self::COOKIE_NAME]) && Access::cntrl($_COOKIE[self::COOKIE_NAME])) ? 
      substr($_COOKIE[self::COOKIE_NAME], 0, strpos($_COOKIE[self::COOKIE_NAME], ':'))
        : null;
  }
};
