<?php
// bo/user.php - création de comptes et gestion de son compte par un utilisateur
// Mettre en variable d'env. le passwd du premier utilisateur

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/login.inc.php';

use Symfony\Component\Yaml\Yaml;

$LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI') or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
MySql::open($LOG_MYSQL_URI);

function createUserTable(): void { // création de la table des utilisateurs 
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
  // initialisation de la table des utilisateurs
  foreach ([
    'benoit.david@free.fr' => ['passwd'=> 'grqjr*kslth17:54!', 'role'=> 'admin'],
    'oldUser' => ['passwd'=> 'oldUser', 'role'=> 'admin', 'valid'=> "'2022-09-01 19:57:10'"],
    'veryOldUser' => ['passwd'=> 'veryOldUser', 'role'=> 'admin', 'valid'=> "'2020-09-01 19:57:10'"],
  ] as $email => $user) {
    $epasswd = password_hash($user['passwd'], PASSWORD_DEFAULT);
    $valid = $user['valid'] ?? 'now()';
    $query = "insert into user(email, epasswd, role, createdt, valid) "
             ."values('$email', '$epasswd', '$user[role]', now(), $valid)";
    MySql::query($query);
    
  }
}

// renvoit le role de l'utilisateur $user
function userRole(?string $user): ?string {
  if (!$user) {
    return null;
  }
  else {
    try {
      $roles = MySql::getTuples("select role from user where email='$user'");
      return $roles[0]['role'] ?? null;
    }
    catch (SExcept $e) {
      if ($e->getSCode() == 'MySql::ErrorTableDoesntExist') {
        createUserTable();
        return null;
      }
      else
        throw new SExcept($e->getMessage(), $e->getSCode());
    }
  }
}


if (!callingThisFile(__FILE__)) return; // n'exécute pas la suite si le fichier est inclus


$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>shomgt-bo/user@$_SERVER[HTTP_HOST]</title></head><body>\n";
echo $HTML_HEAD,"<h2>Gestion utilisateur</h2>\n";

$role = userRole(Login::loggedIn());

// validation de l'email, renvoit null si ok, sinon l'erreur
function notValidEmail(string $passwd): ?string {
  return null;
}

// validation du mot de passe, renvoit null si ok, sinon l'erreur
function notValidPasswd(string $passwd): ?string {
  return null;
}

// Envoie un email avec le lien contenant le secret
function sendMail(string $action, string $email, string $secret, ?string $passwd=null): void { 
  $link = "?action=$action&email=".urlencode($email)."&secret=$secret";
  echo "mail to: $email, <a href='$link'>$action</a>",$passwd ? ", passwd=$passwd" : '',"<br>\n";
}

function showMenu(?string $role): void {
  $user = Login::loggedIn();
  echo "Logué comme '$user' avec un role '$role'.<br>\n";
  $diff = MySql::getTuples("select valid, now() now, DATEDIFF(now(), valid) diff from user where email='$user'")[0];
  //echo '<pre>',Yaml::dump([$user]),"</pre>\n";
  if ($diff['diff'] > 6*30)
    printf("La dernière validation du compte remonte à %.0f mois<br>\n", $diff['diff']/30); 
  
  echo "<h3>Menu</h3><ul>\n";
  if ($role) {
    echo "<li><a href='?action=changePasswd'>Changer son mot de passe</a></li>\n";
    echo "<li><a href='?action=reValidateByUser'>Revalider son compte</a></li>\n";
    echo "<li><a href='?action=closeAccount'>Fermer son compte</a></li>\n";
  }
  echo "<li><a href='index.php'>Retour au menu principal du BO.</a></li>\n";
  if ($role == 'admin') {
    echo "</ul><b>Fonction d'admin</b><ul>\n";
    echo "<li><a href='?action=register'>Enregistrer un nouvel utilisateur</a></li>\n";
    echo "<li><a href='?action=reinitUserBase'>Réinitialiser la base des utilisateurs</a></li>\n";
    echo "<li><a href='?action=listUsers'>Lister les utilisateurs</a></li>\n";
    echo "<li><a href='?action=reValidateOldUsers'>Demander aux vieux utilisateurs de se revalider</a></li>\n";
    echo "<li><a href='?action=suspendOldUsers'>Suspendre les utilisateurs</a></li>\n";
  }
  echo "</ul>\n";
}

switch($action = $_POST['action'] ?? $_GET['action'] ?? null) {
  case null: {
    showMenu($role);
    die();
  }
  case 'reinitUserBase': {
    createUserTable();
    showMenu($role);
    die();
  }
  case 'listUsers': {
    foreach (MySql::query("select * from user") as $user) {
      echo '<pre>',Yaml::dump([$user]),"</pre>\n";
    }
    showMenu($role);
    die();
  }
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
    $email = $_POST['email'] ?? $_GET['email'] ?? null or die("Erreur, email non défini dans registerSubmit");
    $passwd = $_POST['passwd'] ?? $_GET['passwd'] ?? null or die("Erreur, passwd non défini dans registerSubmit");
    if ($error = notValidEmail($email)) { // vérification que l'adresse email est valide et correspond aux suffixes définis
      echo "email invalide: $error<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    if ($error = notValidPasswd($passwd)) { // vérification que le mot de passe est suffisamment long
      echo "mot de passe invalide: $error<br>\n";
      echo "<a href='?action=changePasswd'>Retour au formulaire de changement de mot de passe</a><br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    // vérification qu'il n'a pas déjà un compte ou que le compte est fermé ou suspendu
    $users = MySql::getTuples("select * from user where email='$email'");
    //$users = MySql::getTuples("select * from user");
    //echo "<pre>"; print_r($users); echo "</pre>\n";
    if ($users && !in_array($users[0]['role'], ['closed','suspended'])) {
      echo "Erreur, l'utilisateur '$email' existe déjà et ne peut donc être créé<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    
    // un secret est généré aléatoirement
    $secret = random_int(0, 1000000);
    // création/mise à jour de l'enregistrement
    $epasswd = password_hash($passwd, PASSWORD_DEFAULT);
    if ($users) {
      $query = "update user set secret='$secret', sent=now() where email='$email'";
    }
    else {
      $query = "insert into user(email, epasswd, role, secret, createdt, sent)
                      values('$email', '$epasswd', 'temp', '$secret', now(), now())";
    }
    MySql::query($query);
    
    // un email lui est envoyé avec un lien contenant le secret
    sendMail('validateRegistration', $email, $secret, $passwd);
    echo "Un mail vous a été envoyé, cliquer sur l'URL pour valider votre enregistrement<br>\n";
    die();
  }
  case 'validateRegistration': {
    if (!isset($_GET['email']) || !isset($_GET['secret'])) {
      echo "Appel incorrect, paramètres absents<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    // modification table valid=now, role='normal', secret=null / email+secret
    $query = "update user set role='normal', valid=now(), secret=null, sent=null "
            ."where email='$_GET[email]' and secret='$_GET[secret]'";
    MySql::query($query);
    if (mysqli_affected_rows(MySql::$mysqli) == 1) {
      echo "Enregistrement validé<br>\n";
    }
    else {
      echo "Erreur, aucun enregistrement validé<br>\n";
    }
    echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
    die();
  }
  case 'closeAccount': { // un utilisateur loggué demande à fermer son compte
    $email = Login::loggedIn() or throw new Exception("Erreur, pour fermer son compte un utilisateur soit être loggé");
    $secret = random_int(0, 1000000); // un secret est généré aléatoirement
    MySql::query("update user set secret='$secret', sent=now() where email='$email'");
    // un email lui est envoyé avec un lien contenant le secret
    sendMail('validateCloseAccount', $email, $secret);
    echo "Un mail vous a été envoyé, cliquer sur l'URL pour confirmer la fermeture du compte<br>\n";
    die();
  }
  case 'validateCloseAccount': {
    if (!isset($_GET['email']) || !isset($_GET['secret'])) {
      echo "Appel incorrect, paramètres absents<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    // modification table valid=now, role='normal', secret=null / email+secret
    $query = "update user set role='closed', valid=null, secret=null, sent=null "
            ."where email='$_GET[email]' and secret='$_GET[secret]'";
    MySql::query($query);
    if (mysqli_affected_rows(MySql::$mysqli) == 1) {
      echo "Cloture validée<br>\n";
    }
    else {
      echo "Erreur, cloture NON validée<br>\n";
    }
    echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
    die();
  }
  case 'changePasswd': { // un utilisateur logué demande à changer son mot de passe
    echo "<table border=1><form method='get'>
        <input type='hidden' name='action' value='changePasswdSubmit'>
        <tr><td>Nouveau mot de passe:</td><td><input type='password' size=80 name='passwd' /></td></tr>
        <tr><td>Nouveau mot de passe2:</td><td><input type='password' size=80 name='passwd2' /></td></tr>
        <tr><td colspan=2><center><input type='submit' value='Envoi' /></center></td></tr>
      </form></table>\n";
    die();
  }
  case 'changePasswdSubmit': { // traitement du formulaire de changement de mot de passe
    $email = Login::loggedIn();
    $passwd = $_POST['passwd'] ?? $_GET['passwd'] ?? null or die("Erreur, passwd non défini dans changePasswdSubmit");
    if ($error = notValidEmail($email, $passwd)) {
      echo "email invalide: $error<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    if ($error = notValidPasswd($passwd)) {
      echo "mot de passe invalide: $error<br>\n";
      echo "<a href='?action=changePasswd'>Retour au formulaire de changement de mot de passe</a><br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    $epasswd = password_hash($passwd, PASSWORD_DEFAULT);
    // un secret est généré aléatoirement
    $secret = random_int(0, 1000000);
    MySql::query("update user set newepasswd='$epasswd', secret='$secret', sent=now() where email='$email'");
    
    // un email lui est envoyé avec un lien contenant le secret
    sendMail('validatePasswdChange', $email, $secret, $passwd);
    echo "Un mail vous a été envoyé, cliquer sur l'URL pour valider votre enregistrement<br>\n";
    die();
  }
  case 'validatePasswdChange': {
    if (!isset($_GET['email']) || !isset($_GET['secret'])) {
      echo "Appel incorrect, paramètres absents<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    // modification table valid=now, role='normal', secret=null / email+secret
    $query = "update user set epasswd=newepasswd, newepasswd=null, valid=now(), secret=null, sent=null "
            ."where email='$_GET[email]' and secret='$_GET[secret]'";
    MySql::query($query);
    if (mysqli_affected_rows(MySql::$mysqli) == 1) {
      echo "Changement de mot de passe validé<br>\n";
    }
    else {
      echo "Erreur, changement de mot de passe non validé<br>\n";
    }
    echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
    die();
  }
  case 'reValidateByUser': {
    $email = Login::loggedIn();
    $secret = random_int(0, 1000000);
    MySql::query("update user set secret='$secret', sent=now() where email='$email'");
    // un email est envoyé avec un lien contenant le secret
    sendMail('validateReValidation', $email, $secret);
    echo "Un mail vous a été envoyé, cliquer sur l'URL pour valider votre enregistrement<br>\n";
    die();
  }
  case 'reValidateOldUsers': { // fonction admin pour lister les vieux utilisateurs et leur demander de revalider leur compte 
    if ($email = $_GET['email'] ?? null) {
      $secret = random_int(0, 1000000);
      MySql::query("update user set secret='$secret', sent=now() where email='$email'");
      // un email est envoyé avec un lien contenant le secret
      sendMail('validateReValidation', $email, $secret);
      echo "Un mail a été envoyé à $email<br>\n";
    }
    
    $maxDelayInDays = 365 - 30; // 11 mois
    echo "<table border=1>";
    $query = "select email, role, sent, comment, valid, DATEDIFF(now(), valid) diff
              from user
              where DATEDIFF(now(), valid) > $maxDelayInDays -- validé il y a plus de 11 mois
                and (sent is null or DATEDIFF(now(), sent) > 7) -- et à qui on n'a pas envoyé de rappel récemment";
    $emptyResult = true;
    foreach (MySql::query($query) as $user) {
      $emptyResult = false;
      // function button(string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='post'): string
      $button = button('envoyer un email', ['action'=> 'reValidateOldUsers', 'email'=> $user['email']], '', 'get');
      echo '<tr><td><pre>',Yaml::dump([$user]),"</pre></td></tr>\n";
      echo "<tr><td>$user[email]</td><td>$user[role]</td><td>$user[comment]</td>",
            "<td>$user[valid]</td><td>$user[diff]</td><td>$button</td></tr>\n";
    }
    echo "</table>\n";
    if ($emptyResult)
      echo "Aucun utilisateur à revalider.</p>\n";
    
    showMenu($role);
    die();
  }
  case 'validateReValidation': {
    if (!isset($_GET['email']) || !isset($_GET['secret'])) {
      echo "Appel incorrect, paramètres absents<br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    $query = "update user set valid=now(), secret=null, sent=null "
            ."where email='$_GET[email]' and secret='$_GET[secret]'";
    MySql::query($query);
    if (mysqli_affected_rows(MySql::$mysqli) == 1) {
      echo "ReValidation validée<br>\n";
    }
    else {
      echo "Erreur, reValidation non validé<br>\n";
    }
    echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
    die();
  }
  case 'suspendOldUsers': {
    if ($email = $_GET['email'] ?? null) {
      $secret = random_int(0, 1000000);
      MySql::query("update user set role='suspended', secret='$secret', sent=now() where email='$email'");
      // un email est envoyé avec un lien contenant le secret
      sendMail('validateRegistration', $email, $secret);
      echo "Un mail a été envoyé à $email<br>\n";
    }
    
    $maxDelayInDays = 365; // 1 an
    echo "<table border=1>";
    $query = "select email, role, sent, comment, valid, DATEDIFF(now(), valid) diff
              from user
              where DATEDIFF(now(), valid) > $maxDelayInDays -- validé il y a plus d'un an
                and (sent is null or DATEDIFF(now(), sent) > 7) -- et à qui on n'a pas envoyé de rappel récemment";
    $emptyResult = true;
    foreach (MySql::query($query) as $user) {
      $emptyResult = false;
      // function button(string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='post'): string
      $button = button('envoyer un email', ['action'=> 'suspendOldUsers', 'email'=> $user['email']], '', 'get');
      echo '<tr><td><pre>',Yaml::dump([$user]),"</pre></td></tr>\n";
      echo "<tr><td>$user[email]</td><td>$user[role]</td><td>$user[comment]</td>",
            "<td>$user[valid]</td><td>$user[diff]</td><td>$button</td></tr>\n";
    }
    echo "</table>\n";
    if ($emptyResult)
      echo "Aucun utilisateur à suspendre.</p>\n";
    
    showMenu($role);
    die();
  }
  default: echo "action '$action' inconnue<br>\n";
}
