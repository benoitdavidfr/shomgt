<?php
// bo/user.php - création de comptes et gestion de son compte par un utilisateur

require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/../lib/sexcept.inc.php';

function createTableUser(): void {
  MySql::query('drop table if exists user');
  $query = <<<EOT
create table user (
  email varchar(256) primary key comment "adresse email",
  epasswd longtext not null comment "mot de passe encrypté",
  newepasswd longtext comment "nouveau mot de passe encrypté, utilisé en cas de chgt de mot de passe",
  role enum('normal','admin','restricted', 'banned','suspended','closed','temp') not null comment "rôle de l'utilisateur",
  secret varchar(256) comment "secret envoyé par email et attendu en retour, null ssi le compte a été validé",
  createdt datetime not null comment "date et heure de création initiale du compte",
  sent datetime comment "date et heure d'envoi du dernier mail de validation, null ssi le compte a été validé",
  valid datetime comment "date et heure de dernière validation, null ssi compte non validé"
);
EOT;
  MySql::query($query);
}

$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>shomgt-bo/user@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo $HTML_HEAD;

if (!($LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI'))) {
  die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
}

switch($action = $_POST['action'] ?? $_GET['action'] ?? null) {
  case 'register': { // formulaire d'inscription 
    echo "<table border=1><form method='get'>
        <input type='hidden' name='action' value='registerSubmit'>
        <tr><td>adresse email:</td><td><input type='text' size=80 name='email' /></td></tr>
        <tr><td>mot de passe:</td><td><input type='password' size=80 name='passwd' /></td></tr>
        <tr><td>mot de passe2:</td><td><input type='password' size=80 name='passwd2' /></td></tr>
        <tr><td colspan=2><center><input type='submit' value='Envoi' /></center></td></tr>
      </form></table>\n";
    die();
  }
  case 'registerSubmit': { // traitement du formulaire d'inscription
    if (!($email = $_POST['email'] ?? $_GET['email'] ?? null)) {
      die("Erreur, email non défini dans registerSubmit");
    }
    MySql::open($LOG_MYSQL_URI);
    // vérifications
    //  - que son adresse email est valide et correspond aux suffixes définis
    //  - que son mot de passe est suffisamment long
    //  - qu'il n'a pas déjà un compte ou que le compte est fermé ou suspendu
    try {
      $users = MySql::getTuples("select * from user where email='$email'");
      //$users = MySql::getTuples("select * from user");
    }
    catch (SExcept $e) {
      if ($e->getMessage() == "Table 'shomgt.user' doesn't exist (1146)") {
        createTableUser();
        $users = [];
      }
      else
        throw new SExcept($e->getMessage(), $e->getSCode());
    }
    echo "<pre>"; print_r($users); echo "</pre>\n";
    if ($users && !in_array($users[0]['role'], ['closed','suspended'])) {
      echo "Erreur, l'utilisateur '$email' exiset déjà et ne peut donc être créé<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
    }
    /*
    - un secret est généré aléatoirement
    - si le compte n'existe pas
      - alors un enregistrement est créé dans la table user avec
        - email, epasswd, newepasswd=null, role='temp', secret, create=now, sent=now, valid=null, comment=null
    - sinonSi le role='closed' ou role='suspended'
      - alors modification secret
    - sinon erreur
    - un email lui est envoyé avec un lien contenant le secret
    - l'utilisateur clique sur le lien -> validateRegistration
    */
    die();
  }
  default: echo "action '$action' inconnue<br>\n";
}
