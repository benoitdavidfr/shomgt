<?php
/* bo/user.php - création de comptes et gestion de son compte par un utilisateur - 9-11/8/2023
   Améliorations à apporter:
     - au moins faire un log des actions
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/login.inc.php';

use Symfony\Component\Yaml\Yaml;


class SqlSchema {
  const USER_TABLE = [
    'comment' => "table des utilisateurs",
    'columns' => [
      'email' => [
        'type'=> 'varchar(256)',
        'keyOrNull'=> 'primary key',
        'comment'=> "adresse email",
      ],
      'epasswd'=> [
        'type'=> 'longtext',
        'keyOrNull'=> 'not null',
        'comment'=> "mot de passe encrypté",
      ],
      'newepasswd'=> [
        'type'=> 'longtext',
        'comment'=> "nouveau mot de passe encrypté, utilisé en cas de chgt de mot de passe",
      ],
      'role'=> [
        'type'=> 'enum',
        'enum'=> [
          'normal' => "utilisateur normal ayant le droit d'ajouter et de supprimer des cartes",
          'admin' => "administrateur ayant en plus de l'utilisateur normal des droits supplémentaires,\n"
                    ."notamment le droit de changer le rôle d'un utilisateur",
          'restricted' => "utilisateur ayant le droit de consulter les cartes mais pas d'en ajouter ou d'en supprimer",
          'banned' => "utilisateur banni ayant aucun droit, et qui ne peut réactiver son compte",
          'suspended' => "utilisateur suspendu car , n'a plus aucun droit jusqu'à ce qu'il réactive son compte\n"
                    ."Il peut réactiver son compte",
          'closed' => "utilisateur ayant demandé à fermer son compte et pouvant le réactiver en effectuant à nouveau\n"
                    ."le process de création de compte",
          'temp' => "utilisateur en cours de création dont la validité n'a pas été vérifiée",
          'system' => "utilisateur utilisé en interne à ShomGT",
        ],
        'comment'=> "rôle de l'utilisateur",
      ],
      'secret'=> [
        'type'=> 'varchar(256)',
        'comment'=> "secret envoyé par email et attendu en retour, null ssi le secret a été utilisé",
      ],
      'createdt'=> [
        'type'=> 'datetime',
        'keyOrNull'=> 'not null',
        'comment'=> "date et heure de création initiale du compte",
      ],
      'sent'=> [
        'type'=> 'datetime',
        'comment'=> "date et heure d'envoi du dernier mail de validation, null ssi le lien du mail a été activé",
      ],
      'valid'=> [
        'type'=> 'datetime',
        'comment'=> "date et heure de dernière validation, null ssi compte non validé",
      ],
      'comment'=> [
        'type'=> 'longtext',
        'comment'=> "commentaire",
      ],
    ],
  ]; // Définition du schéma de la table user

  // fabrique le code SQL de création de la table à partir d'une des constantes de définition du schéma
  static function sql(string $tableName, array $schema): string {
    $cols = [];
    foreach ($schema['columns'] as $cname => $col) {
      $cols[] = "  $cname "
        .match($col['type'] ?? null) {
          'enum' => "enum('".implode("','", array_keys($col['enum']))."')",
          default => "$col[type] ",
          null => "NoType"
      }
      .($col['keyOrNull'] ?? '')
      .(isset($col['comment']) ? " comment \"$col[comment]\"" : '');
    }
    return "create table $tableName (\n"
      .implode(",\n", $cols)."\n)"
      .(isset($schema['comment']) ? " comment \"$schema[comment]\"\n" : '');
  }
};

$LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI') or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
MySql::open($LOG_MYSQL_URI);


function createUserTable(): void { // création de la table des utilisateurs 
  MySql::query('drop table if exists user');
  $query = SqlSchema::sql('user', SqlSchema::USER_TABLE);
  //echo "<pre>query=$query</pre>\n";
  MySql::query($query);
  // initialisation de la table des utilisateurs
  foreach (config('loginPwds') as $email => $user) {
    if (!isset($user['passwd'])) {
      echo "Utilisateur '$email' non pris en compte dans l'init. de la table user car le champ passwd n'est pas défini<br>\n";
      continue;
    }
    $epasswd = password_hash($user['passwd'], PASSWORD_DEFAULT);
    $valid = $user['valid'] ?? 'now()';
    $role = $user['role'] ?? 'normal';
    $comment = isset($user['comment']) ? "'".mysqli_real_escape_string(MySql::$mysqli, $user['comment'])."'" : 'null';
    $query = "insert into user(email, epasswd, role, createdt, valid, comment) "
             ."values('$email', '$epasswd', '$role', now(), $valid, $comment)";
    //echo "<pre>query$query</pre>\n";
    MySql::query($query);
  }
}
//createUserTable(); die("FIN ligne ".__LINE__);

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
function notValidEmail(string $email): ?string {
  // Vérification simplifiée d'une adresse email - see https://www.linuxjournal.com/article/9585
  if (!preg_match("!^[a-zA-Z0-9\!#\$%&'\*\+\-/=\?^_`\{\|\}~\.]{1,64}@[-a-zA-Z0-9\.]{1,255}$!", $email))
    return "L'adresse ne respecte pas le format d'une adresse mail";
  foreach (config('domains') as $domain) {
    if (substr($email, - strlen($domain)) == $domain)
      return null;
  }
  return "L'adresse ne correspond à aucun des domaines prévus";
}
if (0) { // test de notValidEmail()
  foreach (['xx@developpement-durable.gouv.fr','xxn@cotes-darmor.gouv.fr', 'xx@cerema.fr','xx@free.fr','xx',''] as $email) {
    $error = notValidEmail($email);
    echo "$email -> ",$error ?? 'ok',"<br>\n";
  }
  die("Fin Test notValidEmail");
}

// validation du mot de passe, renvoit null si ok, sinon l'erreur
function notValidPasswd(string $passwd, string $passwd2): ?string {
  if ($passwd2 <> $passwd)
    return "Les 2 mots de passe ne sont pas identiques<br>\n";
  elseif (strlen($passwd) < 8)
    return "Longueur du mot de passe insuffisante, il doit contenir au moins 8 caractères";
  else
    return null;
}

// Envoie un email avec le lien contenant le secret
function sendMail(string $action, string $email, string $secret, ?string $passwd=null): void {   
  // le lien de confirmation
  $link = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]"
         ."?action=$action&email=".urlencode($email)."&secret=$secret";
  // la partie de phrase demandant à cliquer sur le lien.
  $clickOnLink = "veuillez <a href='$link'>cliquer sur ce lien</a>";
  //echo "link=$link<br>\n";
  // Sujet
  $subject = "action ShomGT";
  // le lien vers le BO utilisé dans $request
  $boLink = "<a href='https://geoapi.fr/shomgt/bo/'>https://geoapi.fr/shomgt/bo/</a>";
  // Les deux phrases du mail
  // 1) Rappel de la demande pour laquelle une confirmation est demandée
  // 2) Demande de cliquer sur le lien
  $phrases = match ($action) {
      'validateRegistration' => [
        "Vous vous êtes incrit sur $boLink.",
        "Pour finaliser cette action, $clickOnLink."
      ],
      'validateCloseAccount' => [
        "Vous avez demandé à supprimer votre compte de $boLink.",
        "Pour finaliser cette action, $clickOnLink."
      ],
      'validatePasswdChange' => [
        "Vous avez demandé à changer de mot de passe sur $boLink.",
        "Pour finaliser cette action, $clickOnLink."
      ],
      'validateReValidation' => [
        "Votre compte sur $boLink nécessite d'être revalidé.",
        "Pour réaliser cette action, $clickOnLink."
      ],
      'validateAfterSuspension' => [
        "Votre compte sur $boLink a été suspendu et doit être re-activé.",
        "Pour réaliser cette action, $clickOnLink."
      ],
      default => "Action $action",
  };
  // le message à envoyer composé des 2 pharses
  $message = "
  <html><head><title>Action ShomGT</title></head>
   <body>
    <p>Bonjour</p>
    <p>$phrases[0]</p>
    <p>$phrases[1]</p>
    <p>Bien cordialement.</p>
    <p>Le robot ShomGT</p>
   </body>
  </html>
  ";
  // Pour envoyer un mail HTML, l'en-tête Content-type doit être défini
  $headers = [
    'MIME-Version: 1.0',
    'Content-type: text/html; charset=utf-8',
    //'Content-type: text/plain; charset=utf-8',
    // En-têtes additionnels
    //"To: $email", Ne pas définir de champ To, GMail indique que c'est une erreur de dupliquer le champ To
    'From: ShomGT <contact@geoapi.fr>',
    'Cc: sentmail@geoapi.fr',
  ];
  // Envoi du mail
  if ($_SERVER['HTTP_HOST'] == 'localhost') { // sur localhost, l'envoi est simulé
    echo "mail to: $email, <a href='$link'>$action</a>",$passwd ? ", passwd=$passwd" : '',"<br>\n";
    echo $message;
  }
  elseif (mail($email, $subject, $message, implode("\r\n", $headers))) // envoi réel
    echo "Un mail vous a été envoyé à l'adresse '$email',"
        ." cliquez sur l'URL contenu dans ce mail pour valider cette demande.<br>\n";
  else
    echo "Erreur d'envoi du mail à $email refusé<br>\n";
}

function showMenu(?string $role): void { // Affichage du menu 
  $user = Login::loggedIn();
  echo "Logué comme '$user' avec un role '$role'.<br>\n";
  $diff = MySql::getTuples("select valid, now() now, DATEDIFF(now(), valid) diff from user where email='$user'")[0];
  //echo '<pre>',Yaml::dump([$user]),"</pre>\n";
  if ($diff['diff'] > 6*30) {
    printf("La dernière validation du compte remonte à %.0f mois<br>\n", $diff['diff']/30);
    echo "Pensez à <a href='?action=reValidateByUser'>revalider votre compte</a><br>\n";
  }
  
  echo "<h3>Menu</h3><ul>\n";
  if ($role) {
    echo "<li><a href='?action=changePasswd'>Changer mon mot de passe</a></li>\n";
    echo "<li><a href='?action=reValidateByUser'>Revalider mon compte</a></li>\n";
    echo "<li><a href='?action=closeAccount'>Fermer mon compte</a></li>\n";
  }
  echo "<li><a href='index.php'>Retour au menu principal du BO.</a></li>\n";
  if ($role == 'admin') {
    echo "</ul><b>Fonction d'admin</b><ul>\n";
    echo "<li><a href='?action=register'>Enregistrer un nouvel utilisateur</a></li>\n";
    echo "<li><a href='?action=reinitUserBase'>Réinitialiser la base des utilisateurs</a></li>\n";
    echo "<li><a href='?action=showUsers'>Afficher/modifier les utilisateurs</a></li>\n";
    echo "<li><a href='?action=reValidateOldUsers'>Demander aux vieux utilisateurs de se revalider</a></li>\n";
    echo "<li><a href='?action=suspendOldUsers'>Suspendre les utilisateurs périmés</a></li>\n";
  }
  echo "</ul>\n";
}

// création d'un formulaire de choix d'une valeur
function htmlSelect(string $name, array $choices, string $selected='', string $submitValue='submit', array $hiddenValues=[], string $action='', string $method='get'): string {
  $spaces = '    ';
  $form =  "$spaces<form action='$action' method='$method'>\n";
  foreach ($hiddenValues as $hvname => $value) {
    $form .= "$spaces  <input type='hidden' name='$hvname' value='$value' />\n";
  }
  $form .= "$spaces  <select name='$name'>\n";
  foreach ($choices as $choice => $label) {
    if (is_int($choice)) $choice = $label;
    $form .= "$spaces    <option value='$choice'".($choice==$selected ? ' selected' : '').">$label</option>\n";
  }
  return $form
    ."$spaces  </select>\n"
    ."$spaces  <input type='submit' value='$submitValue'>\n"
    ."$spaces</form>\n";
}

function showUsers(): void {
  echo '<pre>'; print_r($_GET); echo "</pre>\n";
  if (isset($_GET['role'])) {
    MySql::query("update user set role='$_GET[role]' where email='$_GET[email]'");
  }
  if (isset($_GET['comment'])) {
    $comment = mysqli_real_escape_string(MySql::$mysqli, $_GET['comment']);
    MySql::query("update user set comment='$comment' where email='$_GET[email]'");
  }
  echo "<table border=1><th>email</th><th>role</th><th>création</th><th>validité</th><th>commentaire</th>\n";
  foreach (MySql::query("select * from user") as $user) {
    $roleSelect = htmlSelect(
      'role', // name
      ['normal', 'admin','temp','restricted', 'banned','suspended','closed','system'], // choices
      $user['role'], 'M',
      ['action'=> 'showUsers', 'email'=> $user['email']] // $hiddenValues
    );
    $commentTextArea = "<form>"
      ."<input type='hidden' name='action' value='showUsers' />"
      ."<input type='hidden' name='email' value='$user[email]' />"
      ."<textarea name='comment' rows='3' cols='50'>".htmlspecialchars($user['comment'] ?? '')."</textarea>"
      ."<input type='submit' value='M'>"
      ."</form>";
    echo "<tr><td>$user[email]</td><td>$roleSelect</td>",
         "<td>$user[createdt]</td><td>$user[valid]</td>",
         "<td>$commentTextArea</td></tr>\n";
  }
  echo "</table></p>\n";
  
  echo "<a href='user.php'>Revenir au menu de la gestion des utilisateurs</a><br>\n";
  
  echo "</p><b>Rappel du schéma de la table des utilisateurs</b>:<br>",
       "<pre>",Yaml::dump(SqlSchema::USER_TABLE, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
}

switch ($action = $_POST['action'] ?? $_GET['action'] ?? null) {
  case null: { // affichage du menu 
    showMenu($role);
    die();
  }
  case 'reinitUserBase': { /// Réinitialiser la base des utilisateurs
    createUserTable();
    showMenu($role);
    die();
  }
  case 'showUsers': { // Afficher les utilisateurs
    showUsers();
    die();
  }
  case 'register': { // formulaire d'inscription 
    echo "<table border=1><form method='post'>
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
    $passwd2 = $_POST['passwd2'] ?? $_GET['passwd2'] ?? null or die("Erreur, passwd2 non défini dans registerSubmit");
    if (($error = notValidEmail($email)) || ($error = notValidPasswd($passwd, $passwd2))) {
      echo "email ou mot de passe invalide: $error<br>\n";
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
    die();
  }
  case 'validateRegistration':
  case 'validateAfterSuspension': { // Traitemnt de l'activation du lien de validation envoyé par mail 
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
    die();
  }
  case 'validateCloseAccount': { // Traitemnt de l'activation du lien de validation envoyé par mail 
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
    $passwd2 = $_POST['passwd2'] ?? $_GET['passwd2'] ?? null or die("Erreur, passwd2 non défini dans registerSubmit");
    if ($error = notValidPasswd($passwd, $passwd2)) {
      echo "mot de passe invalide: $error<br>\n";
      echo "<a href='?action=changePasswd'>Revenir au formulaire de changement de mot de passe</a><br>\n";
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    }
    $epasswd = password_hash($passwd, PASSWORD_DEFAULT);
    // un secret est généré aléatoirement
    $secret = random_int(0, 1000000);
    MySql::query("update user set newepasswd='$epasswd', secret='$secret', sent=now() where email='$email'");
    
    // un email lui est envoyé avec un lien contenant le secret
    sendMail('validatePasswdChange', $email, $secret, $passwd);
    die();
  }
  case 'validatePasswdChange': { // Traitemnt de l'activation du lien de validation envoyé par mail 
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
  case 'reValidateByUser': { // Traitemnt de l'activation du lien de validation envoyé par mail 
    $email = Login::loggedIn();
    $secret = random_int(0, 1000000);
    MySql::query("update user set secret='$secret', sent=now() where email='$email'");
    // un email est envoyé avec un lien contenant le secret
    sendMail('validateReValidation', $email, $secret);
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
  case 'validateReValidation': { // Traitemnt de l'activation du lien de validation envoyé par mail 
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
  case 'suspendOldUsers': { // Suspendre les utilisateurs
    if ($email = $_GET['email'] ?? null) {
      $secret = random_int(0, 1000000);
      MySql::query("update user set role='suspended', secret='$secret', sent=now() where email='$email'");
      // un email est envoyé avec un lien contenant le secret
      sendMail('validateAfterSuspension', $email, $secret);
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
