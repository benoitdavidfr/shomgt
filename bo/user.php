<?php
// bo/user.php - création de comptes et gestion de son compte par un utilisateur

require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/../lib/sexcept.inc.php';

if (!($LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI'))) {
  die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
}

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
  valid datetime comment "date et heure de dernière validation, null ssi compte non validé",
  comment longtext comment "commentaire"
);
EOT;
  MySql::query($query);
  $epasswd = password_hash('htpqrs28', PASSWORD_DEFAULT);
  $query = <<<EOT
insert into user(email, epasswd, role, createdt, valid)
values('benoit.david@free.fr', '$epasswd', 'admin', now(), now())
EOT;
  MySql::query($query);
}
//MySql::open($LOG_MYSQL_URI); createTableUser();

function sendMail(string $email, string $secret): void {
  $encEmail = urlencode($email);
  $link = "?action=validateRegistration&email=$encEmail&secret=$secret";
  echo "mail to: $email, <a href='$link'>lien</a><br>\n";
}

$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>shomgt-bo/user@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo $HTML_HEAD;

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
    //  - que l'adresse email est valide et correspond aux suffixes définis
    //  - que le mot de passe est suffisamment long
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
      echo "Erreur, l'utilisateur '$email' existe déjà et ne peut donc être créé<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    
    // un secret est généré aléatoirement
    $secret = random_int(0, 1000000);
    
    // création/mise à jour de l'enregistrement
    $epasswd = password_hash($_POST['passwd'] ?? $_GET['passwd'], PASSWORD_DEFAULT);
    if ($users) {
      $query = "update user set secret='$secret', sent=now() where email='$email'";
    }
    else {
      $query = "insert into user(email, epasswd, role, secret, createdt, sent)
                      values('$email', '$epasswd', 'temp', '$secret', now(), now())";
    }
    MySql::query($query);
    
    // un email lui est envoyé avec un lien contenant le secret
    sendMail($email, $secret);
    echo "Un mail vous a été envoyé, cliquer sur l'URL pour valider votre enregistrement<br>\n";
    die();
  }
  case 'validateRegistration': {
    MySql::open($LOG_MYSQL_URI);
    if (!isset($_GET['email']) || !isset($_GET['secret'])) {
      echo "Appel incorrect, paramètres absents<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    // modification table valid=now, role='normal', secret=null / email+secret
    $query = "update user set role='normal', valid=now(), secret=null where email='$_GET[email]' and secret='$_GET[secret]'";
    MySql::query($query);
    if (mysqli_affected_rows(MySql::$mysqli) == 1) {
      echo "Enregistrement validé<br>\n";
    }
    else {
      echo "Erreu, aucun enregistrement validé<br>\n";
    }
    echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
    die();
  }
  
  default: echo "action '$action' inconnue<br>\n";
}
