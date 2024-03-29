<?php
/** création de comptes et gestion de son compte par un utilisateur - 9-11/8/2023.
 *
 * @package shomgt\bo
 */
namespace bo;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/htmlform.inc.php';
require_once __DIR__.'/login.inc.php';

use Symfony\Component\Yaml\Yaml;

/** Définition du schéma de la table user selon la structuration définie dans son champ description.
 *
 * @var array{description: string, comment: string, columns: array<string,mixed>} USER_TABLE_SCHEMA
 */
const USER_TABLE_SCHEMA = [
  'description' => "Ce dictionnaire définit le schéma d'une table SQL avec:\n"
          ." - le champ 'comment' précisant la table concernée,\n"
          ." - le champ obligatoire 'columns' définissant le dictionnaire des colonnes avec pour chaque entrée:\n"
          ."   - la clé définissant le nom SQL de la colonne,\n"
          ."   - le champ 'type' obligatoire définissant le type SQL de la colonne,\n"
          ."   - le champ 'keyOrNull' définissant si la colonne est ou non une clé et si elle peut ou non être nulle\n"
          ."   - le champ 'comment' précisant un commentaire sur la colonne.\n"
          ."   - pour les colonnes de type 'enum' correspondant à une énumération le champ 'enum'\n"
          ."     définit les valeurs possibles dans un dictionnaire où chaque entrée a:\n"
          ."     - pour clé la valeur de l'énumération et\n"
          ."     - pour valeur une définition et/ou un commentaire sur cette valeur.",
  'comment' => "table des utilisateurs",
  'columns' => [
    'email' => [
      'type'=> 'varchar(256)',
      'keyOrNull'=> 'primary key',
      'comment'=> "adresse email",
    ],
    'epasswd'=> [
      'type'=> 'longtext',
      'comment'=> "mot de passe encrypté, null à la créatin d'un compte temporaire non valide",
    ],
    'newepasswd'=> [
      'type'=> 'longtext',
      'comment'=> "nouveau mot de passe encrypté, utilisé en cas de chgt de mot de passe",
    ],
    'role'=> [
      'type'=> 'enum',
      'enum'=> [
        'normal' => "utilisateur normal ayant le droit de consulter les cartes, d'en ajouter et d'en supprimer",
        'admin' => "administrateur ayant en plus de l'utilisateur normal des droits supplémentaires,\n"
                  ."notamment le droit de changer le rôle des utilisateurs",
        'restricted' => "utilisateur ayant le droit de consulter les cartes mais pas d'en ajouter, ni d'en supprimer",
        'banned' => "utilisateur banni n'ayant aucun droit, et n'ayant pas le droit de réactiver son compte",
        'suspended' => "utilisateur suspendu en l'absence de confirmation pendant un délai d'un an,\n"
                  ."il n'a plus aucun droit jusqu'à ce qu'il réactive son compte.\n"
                  ."Il peut réactiver son compte soit en cliquant sur le lien qui lui a été envoyé par mail,\n"
                  ."soit en exécutant le processus de création de compte",
        'closed' => "utilisateur ayant demandé à fermer son compte et pouvant le réactiver\n"
                  ."en exécutant à nouveau le processus de création de compte",
        'temp' => "utilisateur en cours de création dont la validité n'a pas été vérifiée,\n"
                  ."et n'ayant aucun droit en attendant sa validation par mail",
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
    'extra'=> [
      'type'=> 'JSON',
      'comment'=> "données complémentaires stockées en JSON, notamment la liste des codes des zones dans le champ zones",
    ],
    'comment'=> [
      'type'=> 'longtext',
      'comment'=> "commentaire en texte libre",
    ],
  ],
];

$LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI') or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
\MySql::open($LOG_MYSQL_URI);

/** création de la table des utilisateurs */
function createUserTable(): void {
  \MySql::query('drop table if exists user');
  $query = \MySql::createTableSql('user', USER_TABLE_SCHEMA);
  //echo "<pre>query=$query</pre>\n";
  \MySql::query($query);
  // initialisation de la table des utilisateurs
  foreach (config('loginPwds') as $email => $user) {
    if (!isset($user['passwd'])) {
      echo "Utilisateur '$email' non pris en compte dans l'init. de la table user car le champ passwd n'est pas défini<br>\n";
      continue;
    }
    $epasswd = password_hash($user['passwd'], PASSWORD_DEFAULT);
    $valid = $user['valid'] ?? 'now()';
    $role = $user['role'] ?? 'normal';
    $comment = isset($user['comment']) ? "'".mysqli_real_escape_string(\MySql::$mysqli, $user['comment'])."'" : 'null';
    $query = "insert into user(email, epasswd, role, createdt, valid, comment) "
             ."values('$email', '$epasswd', '$role', now(), $valid, $comment)";
    //echo "<pre>query$query</pre>\n";
    \MySql::query($query);
  }
}
//createUserTable(); die("FIN ligne ".__LINE__);

/** renvoit le role de l'utilisateur $user */
function userRole(?string $user): ?string {
  if (!$user) {
    return null;
  }
  else {
    try {
      $roles = \MySql::getTuples("select role from user where email='$user'");
      return $roles[0]['role'] ?? null;
    }
    catch (\SExcept $e) {
      if ($e->getSCode() == '\MySql::ErrorTableDoesntExist') {
        createUserTable();
        return null;
      }
      else
        throw new \SExcept($e->getMessage(), $e->getSCode());
    }
  }
}


if (!callingThisFile(__FILE__)) return; // n'exécute pas la suite si le fichier est inclus

/** Liste des zones de la ZEE sous la forme [{nom} => {label}].
 *
 * Pour la définition de la zone d'intérêt, la valeur 'FR' est inutile car elle correspond à quasiment toutes les cartes.
 *
 * @var array<string,string>
 */
const ZONES_ZEE = [
  //'FR'=> "France entière",
  'FX-MMN'=> "Métropole - Zone Manche et Mer du Nord",
  'FX-Atl'=> "Métropole - Zone Maritime Atlantique",
  'FX-Med'=> "Métropole - Zone Méditerranée",
  'GP'=> "Guadeloupe",
  'MQ'=> "Martinique",
  'GF'=> "Guyane",
  'BL'=> "Saint-Barthelémy",
  'MF'=> "Saint-Martin",
  'PM'=> "Saint-Pierre-et-Miquelon",
  'RE'=> "La Réunion",
  'YT'=> "Mayotte",
  'TF'=> "TAAF",
  'CP'=> "Clipperton",
  'PF'=> "Polynésie Française",
  'WF'=> "Wallis-et-Futuna",
  'NC'=> "Nouvelle-Calédonie",
];

$HTML_HEAD = "<!DOCTYPE html>\n<html><head><title>shomgt-bo/user@$_SERVER[HTTP_HOST]</title></head><body>\n";
//echo $HTML_HEAD,"<h2>Gestion utilisateur</h2>\n";

/** Fonction de migration d'ajout d'un champ extra dans la table user s'il n'existe pas */
function migrationAddExtraToUserTable(): void {
  foreach(\MySql::query("describe user") as $field)
    if ($field['Field']=='extra')
      return; // le champ extra a déjà été ajouté
  echo "<pre>";
  foreach(\MySql::query("describe user") as $field)
    print_r($field);
  $query = "alter table user add extra JSON 
    comment \"données complémentaires stockées en JSON, notamment la liste des codes des zones dans le champ zones\"
    after valid";
  \MySql::query($query);
  die("Fin migrationAddExtraToUserTable\n");
}
migrationAddExtraToUserTable();

/** validation de l'email, renvoit null si valid, sinon l'erreur */
function badEmail(string $email): ?string {
  // Vérification simplifiée d'une adresse email - see https://www.linuxjournal.com/article/9585
  if (!preg_match("!^[a-zA-Z0-9\!#\$%&'\*\+\-/=\?^_`\{\|\}~\.]{1,64}@[-a-zA-Z0-9\.]{1,255}$!", $email))
    return "L'adresse ne respecte pas le format d'une adresse mail";
  foreach (config('domains') as $domain) {
    if (substr($email, - strlen($domain)) == $domain)
      return null;
  }
  return "L'adresse ne correspond à aucun des domaines prévus";
}
if (0) { // @phpstan-ignore-line // test de badEmail()
  foreach (['xx@developpement-durable.gouv.fr','xxn@cotes-darmor.gouv.fr', 'xx@cerema.fr','xx@free.fr','xx',''] as $email) {
    $error = badEmail($email);
    echo "$email -> ",$error ?? 'ok',"<br>\n";
  }
  die("Fin Test badEmail");
}

/** validation du mot de passe, renvoit null si ok, sinon l'erreur */
function badPasswd(string $passwd, string $passwd2): ?string {
  if ($passwd2 <> $passwd)
    return "Les 2 mots de passe ne sont pas identiques<br>\n";
  elseif (strlen($passwd) < 8)
    return "Longueur du mot de passe insuffisante, il doit contenir au moins 8 caractères";
  else
    return null;
}

/** Envoie un email avec le lien contenant le secret */
function sendMail(string $action, string $email, int $secret, ?string $passwd=null): void {   
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
        "Vous vous êtes incrit sur $boLink ou avez changé votre mot de passe.",
        "Pour finaliser cette action, $clickOnLink."
      ],
      'validateCloseAccount' => [
        "Vous avez demandé à supprimer votre compte de $boLink.",
        "Pour finaliser cette action, $clickOnLink."
      ],
      'validateReValidation' => [
        "Vous avez demandé à revalider votre compte sur $boLink ou votre compte nécessite de l'être.",
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

/** Le script est décomposé en "actions" qui s'enchainent les unes après les autres
 * Chaque action est définie comme une entrée de la table $actions ci-dessous avec
 *  - commé clé le nom ou id de l'action
 *  - un champ title fournissant le titre de l'action
 *  - un champ from avec l'action ou les actions qui y conduisent (string ou [string])
 *  - un champ scenario avec le pseudo-code de l'action
 *  - un champ to avec l'action ou les actions auxquelles elle conduit (string ou [string])
 *    si l'action ne se termine pas par un die() et qu'il y a qu'une seule action suivante
 *    alors elle est enchainée automatiquement
 *  - un champ apply avec la fonction à exécuter pour réaliser l'action et/ou afficher l'écran correspondant
 *
 * @var array<string, array{
 *     title: string,
 *     from?: string|list<string>,
 *     fromDeduced?: list<string>,
 *     scenario?: list<string>,
 *     to?: string|list<string>,
 *     toDeduced?: list<string>,
 *     apply: callable(): void,
 *     sameAs?: string
 *  }> $actions
 */
$actions = [
  'user.menu'=> [
    'title'=> "menu du script user",
    'from'=> [
      'actionTestB',
      'editUsers',
      'reinitUserBase',
      'zoneSubmit',
    ],
    'to'=> [
      'actionTestA',
      'changePasswd',
      'setZone',
      'reValidateByUser',
      'closeAccount',
      'BO.menu',
      'editUsers',
      'reValidateOldUsers',
      'suspendOldUsers',
      'reinitUserBase',
      'showUserTableSchema',
    ],
    'apply'=> function(string $HTML_HEAD): void {
      $user = Login::loggedIn();
      $role = userRole($user);
      if ($role)
        echo "Logué comme '$user' avec un role '$role'.<br>\n";
      else
        echo "Utilisateur non logué.<br>\n";
      $diff = \MySql::getTuples("select valid, now() now, DATEDIFF(now(), valid) diff from user where email='$user'")[0] ?? null;
      //echo '<pre>',Yaml::dump([$user]),"</pre>\n";
      if ($diff && (intval($diff['diff']) > 6*30)) {
        printf("La dernière validation du compte remonte à %.0f mois<br>\n", intval($diff['diff'])/30);
        echo "Pensez à <a href='?action=reValidateByUser'>revalider mon compte</a><br>\n";
      }
  
      echo "<h3>Menu</h3><ul>\n";
      if ($role) {
        //echo "<li><a href='?action=actionTestA'>actionTestA</a></li>\n";
        echo "<li><a href='?action=changePasswd'>Changer mon mot de passe</a></li>\n";
        echo "<li><a href='?action=setZone'>Définir ma zone d'intérêt</a></li>\n";
        echo "<li><a href='?action=reValidateByUser'>Revalider mon compte</a></li>\n";
        echo "<li><a href='?action=closeAccount'>Fermer mon compte</a></li>\n";
      }
      else {
        echo "<li><a href='?action=register'>S'enregistrer comme nouvel utilisateur ou changer sont mot de passe</a></li>\n";
      }
      echo "<li><a href='index.php'>Retour au menu principal du BO.</a></li>\n";
      if ($role == 'admin') {
        echo "</ul><b>Fonctions d'admin</b><ul>\n";
        echo "<li><a href='?action=register'>Enregistrer un nouvel utilisateur</a></li>\n";
        echo "<li><a href='?action=editUsers'>Afficher/modifier les utilisateurs</a></li>\n";
        echo "<li><a href='?action=reValidateOldUsers'>Demander aux vieux utilisateurs de se revalider</a></li>\n";
        echo "<li><a href='?action=suspendOldUsers'>Suspendre les utilisateurs périmés</a></li>\n";
        echo "<li><a href='?action=reinitUserBase'>Réinitialiser la table des utilisateurs</a></li>\n";
        echo "</ul><b>Documentation du code</b><ul>\n";
        echo "<li><a href='?action=showUserTableSchema'>Afficher le schema de la table des utilisateurs</a></li>\n";
        echo "<li><a href='?action=showActions'>Afficher les actions</a></li>\n";
        echo "<li><a href='?action=showSequenceBetweenActions'>Afficher l'enchainement entre actions</a></li>\n";
        echo "<li><a href='?action=checkSequenceBetweenActions'>Vérifier les enchainements entre actions</a></li>\n";
      }
      echo "</ul>\n";
      
      die();
    },
  ],
  'actionTestA'=> [
    'title'=> "Action test A",
    'from'=> 'user.menu',
    'scenario'=> [
      "action test A",
      " -> appel de B",
    ],
    'to'=> 'actionTestB',
    'apply'=> function(string $HTML_HEAD): void {
      echo "Action Test A<br>\n";
      echo new \html\Form(submit: 'go', hiddens: ['action'=> 'actionTestB'], method: 'get');
    },
  ],
  'actionTestB'=> [
    'title'=> "Action test B",
    'from'=> 'actionTestA',
    'scenario'=> [
      "action test B appelée de actionTestA",
    ],
    'to'=> 'actionTestC',
    'apply'=> function(string $HTML_HEAD): void {
      echo "Action Test B<br>\n";
    },
  ],
  'actionTestC'=> [
    'title'=> "Action test C",
    'from'=> 'actionTestB',
    'scenario'=> [
      "action test C appelée de actionTestB",
    ],
    'to'=> 'user.menu',
    'apply'=> function(string $HTML_HEAD): void {
      echo "Action Test C<br>\n";
    },
  ],
  'editUsers' => [
    'title'=> "Afficher/modifier les utilisateurs",
    'from'=> 'user.menu',
    'to'=> 'user.menu',
    'apply'=> function (string $HTML_HEAD): void {
      echo '<pre>'; print_r($_GET); echo "</pre>\n";
      if (isset($_GET['role'])) {
        \MySql::query("update user set role='$_GET[role]' where email='$_GET[email]'");
      }
      if (isset($_GET['comment'])) {
        $comment = mysqli_real_escape_string(\MySql::$mysqli, $_GET['comment']);
        \MySql::query("update user set comment='$comment' where email='$_GET[email]'");
      }
      $choices = ['normal', 'admin','temp','restricted', 'banned','suspended','closed','system'];
      echo "<table border=1><th>email</th><th>role</th><th>création</th><th>validité</th><th>commentaire</th>\n";
      foreach (\MySql::query("select * from user order by email") as $user) {
        echo "<tr><td>$user[email]</td>",
            "<td>",new \html\Form(
                fields: ['role'=> new \html\Select(choices: $choices, selected: $user['role'])],
                submit: 'M',
                hiddens: ['action'=> 'editUsers', 'email'=> $user['email']]
              ),"</td>",
            "<td>$user[createdt]</td>",
            "<td>$user[valid]</td>",
            "<td>",new \html\Form(
                fields: ['comment'=> new \html\TextArea(text: $user['comment'] ?? '')],
                submit: 'M',
                hiddens: ['action'=> 'editUsers', 'email'=> $user['email']]
              ),"</td>",
            "</tr>\n";
      }
      echo "</table></p>\n";
  
      echo "<a href='user.php'>Revenir au menu de la gestion des utilisateurs</a><br>\n";
  
      echo "</p><b>Rappel du schéma de la table des utilisateurs</b>:<br>",
           "<pre>",Yaml::dump(USER_TABLE_SCHEMA, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
      die();
    },
  ],
  'register'=> [
    'title'=> "formulaire d'inscription d'un nouvel utilisateur ou de changement de mot de passe oublié",
    'from'=> 'BO.login',
    'scenario'=> [
      "un utilisateur non logué demande à ouvrir un compte ou à changer son mot de passe qu'il a oublié",
      "il fournit une adresse email et un mot de passe en 2 exemplaires",
    ],
    'to'=> 'registerSubmit',
    'apply'=> function (string $HTML_HEAD): void {
      echo $HTML_HEAD,"<h2>Inscription d'un nouvel utilisateur ou changement d'un mot de passe oublié</h2>\n";
      echo "Ce formulaire vous permet de vous inscrire sur ShomGT avec votre adresse professionelle
        de courrier électronique.</p>
        En vous innscrivant, vous vous engagez à utiliser les services de ShomGT uniquement dans le cadre de votre activité
        professionnelle pour le compte de l'Etat ou d'un de ses Etablissements publics à caractère administratif.<br>
        Vous vous engagez aussi à garder secret votre mot de passe et à ne pas le fournir à une autre personne.</p>
        En cas de non respect de ces contraintes, vous pourrez être bannis du site.</p>
        La liste des domaines des adresses électroniques autorisées est actuellement la suivante:<ul>
          <li>*.gouv.fr</li>
          <li>ofb.fr</li>
          <li>cerema.fr</li>
          <li>shom.fr</li>
          <li>ign.fr</li>
        </ul>
        Si votre adresse n'est pas dans un de ces domaines, vous pouvez contacter \"contact(at)geoapi.fr\"
        pour la faire ajouter.</p>
        Une fois votre adresse et votre mot de passe saisis, un lien vous sera envoyé à cette adresse afin de valider
        votre inscription.</p>
        Votre compte sur ShomGT vous permettra d'une part d'accéder au services de visualisation et,
        d'autre part, de mettre à jour les cartes Shom en les commandant au Shom.</p>
        ";
      echo "<table border=1><form method='post'>
          <input type='hidden' name='action' value='registerSubmit'>
          <tr><td>adresse email:</td><td><input type='text' size=80 name='email' /></td></tr>
          <tr><td>mot de passe:</td><td><input type='password' size=80 name='passwd' /></td></tr>
          <tr><td>mot de passe2:</td><td><input type='password' size=80 name='passwd2' /></td></tr>
          <tr><td colspan=2>
            <center>
              <input type='submit' 
                value=\"Je m'inscris et je m'engage à respecter les contraintes d'utilisation ci-dessus\" />
            </center>
          </td></tr>
        </form></table>\n";
      die();
    },
  ],
  'changePasswd'=> [
    'title'=> "formulaire de changement de son mot de passe par un utilisateur logué",
    'from'=> 'user.menu',
    'scenario'=> [
      "un utilisateur logué demande à changer son mot de passe",
      "il fournit le nouveau mot de passe souhaité en 2 exemplaires",
    ],
    'to'=> 'registerSubmit',
    'apply'=> function(string $HTML_HEAD): void  { // un utilisateur logué 
      $email = Login::loggedIn() or die("Erreur, pour changePasswd l'utilisateur doit être loggé");
      echo "<table border=1><form method='post'>
          <input type='hidden' name='action' value='registerSubmit'>
          <input type='hidden' name='email' value='$email'>
          <tr><td>Nouveau mot de passe:</td><td><input type='password' size=80 name='passwd' /></td></tr>
          <tr><td>Nouveau mot de passe2:</td><td><input type='password' size=80 name='passwd2' /></td></tr>
          <tr><td colspan=2><center><input type='submit' value='Envoi' /></center></td></tr>
        </form></table>\n";
      die();
    },
  ],
  'registerSubmit'=> [
    'title'=> "traitement du formulaire d'inscription ou de changement de mot de passe",
    'from'=> ['register', 'changePasswd'],
    'scenario'=> [
      "vérifications",
      "  que l'utilisateur n'a pas déjà un compte ou s'il en a un qu'il n'est pas banni",
      "  que son adresse email est valide et correspond aux suffixes définis",
      "  que son mot de passe est suffisamment long et que les 2 exemplaires sont identiques",
      "un secret est généré aléatoirement",
      "si le compte n'existe pas",
      "  alors un enregistrement est créé dans la table user avec",
      "    email, epasswd=null, newepasswd, role='temp', secret, create=now, sent=now, valid=null, comment=null",
      "sinon",
      "  alors modification newepasswd, secret, sent=now / email",
      "envoi d'un email avec un lien vers validateRegistration",
    ],
    'to'=> 'validateRegistration',
    'apply'=> function(string $HTML_HEAD): void {
      write_log(true);
      $email = $_POST['email'] ?? $_GET['email'] ?? die("Erreur, email non défini dans registerSubmit");
      $passwd = $_POST['passwd'] ?? $_GET['passwd'] ?? die("Erreur, passwd non défini dans registerSubmit");
      $passwd2 = $_POST['passwd2'] ?? $_GET['passwd2'] ?? die("Erreur, passwd2 non défini dans registerSubmit");
      $users = \MySql::getTuples("select role from user where email='$email'");
      if ($users && ($users[0]['role'] == 'banned')) {
        die("Erreur, l'utilisateur '$email' est banni.<br>\n");
      }
      if (($error = badEmail($email)) || ($error = badPasswd($passwd, $passwd2))) {
        echo "email ou mot de passe invalide: $error<br>\n";
        echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
        die();
      }
      // un secret est généré aléatoirement
      $secret = random_int(0, 1000000);
      // création/mise à jour de l'enregistrement
      $newepasswd = password_hash($passwd, PASSWORD_DEFAULT);
      if ($users) {
        $query = "update user set newepasswd='$newepasswd', secret='$secret', sent=now() where email='$email'";
      }
      else {
        $query = "insert into user(email, newepasswd, role, secret, createdt, sent)
                        values('$email', '$newepasswd', 'temp', '$secret', now(), now())";
      }
      \MySql::query($query);
  
      // un email lui est envoyé avec un lien contenant le secret
      sendMail('validateRegistration', $email, $secret, $passwd);
      die();
    },
  ],
  'validateRegistration'=> [
    'title'=> "Traitement de l'activation du lien envoyé par mail de validation de l'inscription ou changement de mot de passe",
    'from'=> 'registerSubmit',
    'scenario'=> [
      "nouvRole = ",
      "  si role=='banned' alors erreur",
      "  si role in('admin','normal','restricted','system') alors role",
      "  si role in('suspended','closed','temp') alors 'normal'",
      "modification table role=nouvRole, valid=now, epasswd=newepasswd, newepasswd=null, secret=null, sent=null / email+secret",
    ],
    'to'=> "BO.menu",
    'apply' => function(string $HTML_HEAD): void {
      $email = $_GET['email'] ?? die("Appel incorrect, paramètre absent<br>\n");
      $secret = $_GET['secret'] ?? die("Appel incorrect, paramètre absent<br>\n");
      // modification table valid=now, role='normal', secret=null / email+secret
      $user = \MySql::getTuples("select role from user where email='$email' and secret='$secret'")[0];
      $role = match ($user['role']) {
        'banned' => die("validateRegistration interdit pour role=='banned'"),
        'admin','normal','restricted','system' => $user['role'],
        'suspended','closed','temp' => 'normal',
        default => throw new \Exception("valeur $user[role] interdite"),
      };
      $query = "update user set role='$role', valid=now(), epasswd=newepasswd, newepasswd=null, secret=null, sent=null "
              ."where email='$email' and secret='$secret'";
      \MySql::query($query);
      if (mysqli_affected_rows(\MySql::$mysqli) == 1) {
        echo "Enregistrement validé<br>\n";
      }
      else {
        echo "Erreur, aucun enregistrement validé<br>\n";
      }
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    },
  ],
  'setZone'=> [
    'title'=> "formulaire de définition de sa zone par un utilisateur logué",
    'from'=> 'user.menu',
    'scenario'=> [
      "un utilisateur logué demande à changer sa zone",
      "il fournit la nouvelle zone",
    ],
    'to'=> 'zoneSubmit',
    'apply'=> function(string $HTML_HEAD): void  { // un utilisateur logué demande à changer sa zone
      $email = Login::loggedIn() or die("Erreur, pour setZone l'utilisateur doit être loggé");
      try {
        $users = \MySql::getTuples("select extra from user where email='$email'");
      }
      catch(\SExcept $e) {
        echo "<pre>"; print_r($e);
        die();
      }
      //echo "<pre>users="; print_r($users); echo "</pre>\n";
      $extra = $users[0]['extra'] ?? '';
      $selected = [];
      if ($extra) {
        $extra = json_decode($extra, true, 512, JSON_INVALID_UTF8_IGNORE|JSON_THROW_ON_ERROR);
        $selected = $extra['zones'] ?? [];
      }
      
      echo "<b>Choix de la zone d'intérêt</b><br>\n";
      echo new \html\Form(
        hiddens: ['action'=> 'zoneSubmit'],
        fields: [
          'zee' => new \html\checkBox(
            label: 'zones',
            choices: ZONES_ZEE,
            selected: $selected,
            long: true
          ),
        ],
        submit: 'définir',
        action: '',
        method: 'get'
      );
      die();
    },
  ],
  'zoneSubmit'=> [
    'title'=> "traitement du formulaire de définition de sa zone par un utilisateur logué",
    'from'=> ['setZone'],
    'scenario'=> [
      "enregistrement de la zone en base dans le champ extra et retour au menu user",
    ],
    'to'=> 'user.menu',
    'apply'=> function(string $HTML_HEAD): void {
      write_log(true);
      $email = Login::loggedIn() or die("Erreur, pour setZone l'utilisateur doit être loggé");
      $users = \MySql::getTuples("select extra from user where email='$email'");
      //echo "<pre>users="; print_r($users); echo "</pre>\n";
      $extra = $users[0]['extra'] ?? '';
      $extra = $extra ? json_decode($extra, true, 512, JSON_INVALID_UTF8_IGNORE|JSON_THROW_ON_ERROR) : [];
      $zones = \html\checkBox::selected('zee', $_GET);
      if ($zones)
        $extra['zones'] = $zones;
      else
        unset($extra['zones']);
      if ($extra)
        $query = "update user set extra='".json_encode($extra)."' where email='$email'";
      else
        $query = "update user set extra=null where email='$email'";
      //echo "query=$query<br>\n";
      \MySql::query($query);
      //echo "<a href='user.php'>Retour au menu de gestion des utilisateurs</a><br>\n";
      echo "Votre zone d'intérêt est définie comme ",json_encode($zones),".<br>\n";
    },
  ],
  'closeAccount'=> [
    'title'=> "demande de fermeture de son compté par un utilisateur loggué",
    'from'=> 'user.menu',
    'scenario'=> [
      "un utilisateur loggué demande à fermer son compte",
      "un secret est généré aléatoirement",
      "modification user sent=now, secret / email",
      "envoi d'un email avec un lien vers validateCloseAccount",
    ],
    'to'=> 'validateCloseAccount',
    'apply'=> function(string $HTML_HEAD): void {
      $email = Login::loggedIn() or die("Erreur, pour fermer son compte un utilisateur soit être loggé");
      $secret = random_int(0, 1000000); // un secret est généré aléatoirement
      \MySql::query("update user set secret='$secret', sent=now() where email='$email'");
      // un email lui est envoyé avec un lien contenant le secret
      sendMail('validateCloseAccount', $email, $secret);
      die();
    },
  ],
  'validateCloseAccount'=> [
    'title'=> "Traitement de l'activation du lien de validation de fermeture de compte envoyé par mail",
    'from'=> 'closeAccount',
    'scenario'=> [
      "update user set role='closed', valid=null, secret=null, sent=null where email='\$email' and secret='\$secret'",
    ],
    'to'=> 'BO.menu',
    'apply'=> function(string $HTML_HEAD): void {
      $email = $_GET['email'] ?? die("Erreur: email non défini dans validateCloseAccount");
      $secret = $_GET['secret'] ?? die("Erreur: secret non défini dans validateCloseAccount");
      // modification table valid=now, role='normal', secret=null / email+secret
      $query = "update user set role='closed', valid=null, secret=null, sent=null "
              ."where email='$email' and secret='$secret'";
      \MySql::query($query);
      if (mysqli_affected_rows(\MySql::$mysqli) == 1) {
        echo "Cloture validée<br>\n";
      }
      else {
        echo "Erreur, cloture NON validée<br>\n";
      }
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    },
  ],
  'reValidateByUser'=> [
    'title'=> "Demande de revalidation de son compte par un utilisateur logué",
    'from'=> "user.menu",
    'scenario'=> [
      "génération secret",
      "update user set secret='\$secret', sent=now() where email='\$email'",
      "envoi email avec lien vers validateReValidation",
    ],
    'to'=> 'validateReValidation',
    'apply'=> function(string $HTML_HEAD): void { 
      $email = Login::loggedIn();
      $secret = random_int(0, 1000000);
      \MySql::query("update user set secret='$secret', sent=now() where email='$email'");
      // un email est envoyé avec un lien contenant le secret
      sendMail('validateReValidation', $email, $secret);
      die();
    },
  ],
  'reValidateOldUsers'=> [
    'title'=> "fonction admin pour lister les vieux utilisateurs et leur demander de revalider leur compte",
    'from'=> 'user.menu',
    'scenario'=> [
      "pour chaque utilisateur dont now-valid > \$maxDelayInDays",
      "  génération secret",
      "  update user set secret='\$secret', sent=now() where email='\$email'",
      "  envoi email avec lien vers validateReValidation",
    ],
    'to'=> 'validateReValidation',
    'apply'=> function(string $HTML_HEAD): void {
      if ($email = $_GET['email'] ?? null) {
        $secret = random_int(0, 1000000);
        \MySql::query("update user set secret='$secret', sent=now() where email='$email'");
        // un email est envoyé avec un lien contenant le secret
        sendMail('validateReValidation', $email, $secret);
        echo "Un mail a été envoyé à $email<br>\n";
      }
    
      $maxDelayInDays = 365 - 30; // 11 mois
      echo "<table border=1>";
      $query = "select email, role, sent, comment, valid, DATEDIFF(now(), valid) diff from user
                where DATEDIFF(now(), valid) > $maxDelayInDays -- validé il y a plus de 11 mois
                  and (sent is null or DATEDIFF(now(), sent) > 7) -- et à qui un rappel n'a pas été envoyé récemment";
      $emptyResult = true;
      foreach (\MySql::query($query) as $user) {
        $emptyResult = false;
        $hiddens = ['action'=> 'reValidateOldUsers', 'email'=> $user['email']];
        $button = new \html\Form(submit: 'envoyer un email', hiddens: $hiddens, method: 'get');
        echo '<tr><td><pre>',Yaml::dump([$user]),"</pre></td></tr>\n";
        echo "<tr><td>$user[email]</td><td>$user[role]</td><td>$user[comment]</td>",
              "<td>$user[valid]</td><td>$user[diff]</td><td>$button</td></tr>\n";
      }
      echo "</table>\n";
      if ($emptyResult)
        echo "Aucun utilisateur à revalider.</p>\n";
    
      echo "<a href='user.php'>Retour au menu de la gestion des utilisateurs</a><br>\n";
      die();
    }
  ],
  'validateReValidation'=> [
    'title'=> "Traitement de l'activation du lien de revalidation envoyé par mail",
    'from'=> ['reValidateByUser', 'reValidateOldUsers'],
    'scenario'=> [
      "update user set valid=now(), secret=null, sent=null where email='\$email' and secret='\$secret'"
    ],
    'to'=> 'BO.menu',
    'apply'=> function(string $HTML_HEAD): void  {
      $email = $_GET['email'] ?? die("Erreur paramètre email absent dans validateReValidation");
      $secret = $_GET['secret'] ?? die("Erreur paramètre secret absent dans validateReValidation");
      $query = "update user set valid=now(), secret=null, sent=null where email='$email' and secret='$secret'";
      \MySql::query($query);
      if (mysqli_affected_rows(\MySql::$mysqli) == 1) {
        echo "ReValidation validée<br>\n";
      }
      else {
        echo "Erreur, reValidation non validé<br>\n";
      }
      echo "<a href='index.php'>Revenir au menu du BO.</a><br>\n";
      die();
    },
  ],
  'suspendOldUsers'=> [
    'title'=> "fonction admin pour lister les très vieux utilisateurs et suspendre leur compte en leur envoyant un mail",
    'from'=> 'user.menu',
    'scenario'=> [
      "pour chaque utilisateur dont now-valid > \$maxDelayInDays",
      "  génération secret",
      "  update user set role='suspended', secret='\$secret', sent=now() where email='\$email'",
      "  envoi email avec lien -> validateAfterSuspension",
    ],
    'to'=> 'validateAfterSuspension',
    'apply'=> function(string $HTML_HEAD): void {
      if ($email = $_GET['email'] ?? null) {
        $secret = random_int(0, 1000000);
        \MySql::query("update user set role='suspended', secret='$secret', sent=now() where email='$email'");
        // un email est envoyé avec un lien contenant le secret
        sendMail('validateAfterSuspension', $email, $secret);
        echo "Un mail a été envoyé à $email<br>\n";
      }
    
      $maxDelayInDays = 365; // 1 an
      echo "<table border=1>";
      $query = "select email, role, sent, comment, valid, DATEDIFF(now(), valid) diff
                from user
                where DATEDIFF(now(), valid) > $maxDelayInDays -- validé il y a plus d'un an
                  and (sent is null or DATEDIFF(now(), sent) > 7) -- et à qui un rappel n'a pas été envoyé récemment";
      $emptyResult = true;
      foreach (\MySql::query($query) as $user) {
        $emptyResult = false;
        $button = new \html\Form(
          submit: 'envoyer un email', 
          hiddens: ['action'=> 'suspendOldUsers', 'email'=> $user['email']], 
          method: 'get');
        echo '<tr><td><pre>',Yaml::dump([$user]),"</pre></td></tr>\n";
        echo "<tr><td>$user[email]</td><td>$user[role]</td><td>$user[comment]</td>",
              "<td>$user[valid]</td><td>$user[diff]</td><td>$button</td></tr>\n";
      }
      echo "</table>\n";
      if ($emptyResult)
        echo "Aucun utilisateur à suspendre.</p>\n";
    
      echo "<a href='user.php'>Retour au menu de la gestion des utilisateurs</a><br>\n";
      die();
    },
  ],
  'reinitUserBase'=> [
    'title'=> "Réinitialiser la base des utilisateurs",
    'from'=> 'user.menu',
    'to'=> 'user.menu',
    'apply'=> function(string $HTML_HEAD): void {
      createUserTable();
      echo "<a href='user.php'>Retour au menu de la gestion des utilisateurs</a><br>\n";
      die();
    },
  ],
  'showUserTableSchema'=> [
    'title'=> "Affiche le schéma de la table user",
    'from'=> 'user.menu',
    'apply'=> function(string $HTML_HEAD): void {
      echo '<pre>',Yaml::dump(USER_TABLE_SCHEMA, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
    },
  ],
  'BO.login'=> [
    'title'=> "fantome du login du BO",
    'to'=> 'register',
  ],
  'BO.menu'=> [
    'title'=> "fantome du menu du BO",
    'from'=> [
      'user.menu',
      'validateRegistration',
      'validateCloseAccount',
      'validateReValidation',
    ],
    'to'=> [],
  ],
];
$actions['validateAfterSuspension'] = [
  'title'=> "Traitement de l'activation du lien de validation du compte envoyé par mail",
  'from'=> 'suspendOldUsers',
  'sameAs'=> 'validateRegistration',
  'apply'=> $actions['validateRegistration']['apply'],
];
$actions['showActions'] = [
  'title'=> "Affiche les actions",
  'apply'=> function(string $HTML_HEAD) use($actions): void {
    echo "<h3>Scenarios</h3>",
          "<pre>",
            Yaml::dump(
              array_map(function(array $action):array { unset($action['apply']); return $action; }, $actions),
              4, 2
            ),
          "</pre>\n";
    die();
  },
];
$actions['showSequenceBetweenActions'] = [
  'title'=> "Affiche l'enchainement entre actions",
  'apply'=> function(string $HTML_HEAD) use($actions): void {
    echo "<h3>Scenarios simplifiés (title, from, to) pour vérifier les enchainements</h3>",
          "<pre>",
            Yaml::dump(
              array_map(
                function(array $action):array { unset($action['apply']); unset($action['scenario']); return $action; },
                $actions
              ), 4, 2
            ),
          "</pre>\n";
    die();
  },
];
$actions['checkSequenceBetweenActions'] = [
  'title'=> "Vérifie les enchainements entre actions",
  'apply'=> function(string $HTML_HEAD) use($actions): void {
    foreach ($actions as $action => $actionDef) {
      if (isset($actionDef['from'])) {
        if (is_string($actionDef['from']) && isset($actions[$actionDef['from']])) {
          $actions[$actionDef['from']]['toDeduced'][] = $action;
        }
        elseif (is_array($actionDef['from'])) {
          foreach ($actionDef['from'] as $from) {
            if (isset($actions[$from])) {
              $actions[$from]['toDeduced'][] = $action;
            }
          }
        }
      }
    }
    echo "<h3>Vérification de toDeduced</h3>",
          "<pre>",
            Yaml::dump(
              array_map(
                function(array $action) {
                  if (!isset($action['toDeduced']))
                    $action['toDeduced'] = '';
                  else {
                    sort($action['toDeduced']);
                    $action['toDeduced'] = implode(',', $action['toDeduced']);
                  }
                  if (!isset($action['to']))
                    $action['to'] = '';
                  elseif (is_array($action['to'])) {
                    sort($action['to']);
                    $action['to'] = implode(',',$action['to']);
                  }
                  if ($action['toDeduced'] == $action['to'])
                    return 'ok';
                  unset($action['apply']);
                  unset($action['scenario']);
                  return $action;
                },
                $actions
              ), 4, 2
            ),
          "</pre>\n";
    foreach ($actions as $action=> $actionDef) {
      if (isset($actionDef['to'])) {
        if (is_string($actionDef['to']) && isset($actions[$actionDef['to']])) {
          $actions[$actionDef['to']]['fromDeduced'][] = $action;
        }
        elseif (is_array($actionDef['to'])) {
          foreach ($actionDef['to'] as $to) {
            if (isset($actions[$to])) {
              $actions[$to]['fromDeduced'][] = $action;
            }
          }
        }
      }
    }
    echo "<h3>Vérification de fromDeduced</h3>",
          "<pre>",
            Yaml::dump(
              array_map(
                function(array $action) {
                  $action['fromDeduced'] = isset($action['fromDeduced']) ? implode(',', $action['fromDeduced']) : '';
                  $action['from'] = !isset($action['from']) ? ''
                      : (is_string($action['from']) ? $action['from'] : implode(',',$action['from']));
                  if ($action['fromDeduced'] == ($action['from'] ?? [])) // @phpstan-ignore-line
                    return 'ok';
                  else
                    return ['title'=> $action['title'], 'from'=> $action['from'], 'fromDeduced'=> $action['fromDeduced']];
                },
                $actions
              ), 4, 2
            ),
          "</pre>\n";
    die();
    
  },
];

$action = $_POST['action'] ?? $_GET['action'] ?? 'user.menu';
//echo "action='$action'<br>\n";
while ($action) {
  if (!isset($actions[$action]['apply']))
    die("<b>Erreur, action '$action' inconnue ou champ apply non défini</b><br>\n");
  //echo "exécution de $action ->apply()<br>\n";
  $actions[$action]['apply']($HTML_HEAD); // @phpstan-ignore-line
  $action = $actions[$action]['to'] ?? null;
}
