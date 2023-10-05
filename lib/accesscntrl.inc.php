<?php
/** contrôle d'accès
 *
 * journal:
 * - 10/8/2023:
 *   - utilisation des logins, passwd et role en base de données
 * - 19/5/2022:
 *   - adaptation pour sgserver de ShomGT3
 * - 23/1/2022:
 *   - ajout ipInBlackList() pour tile.php
 * - 27/12/2020:
 *   - ajout test admins
 * - 23/5/2020:
 *   - ajout du contrôle sur préfixe IPv6
 * - 30/3/2019:
 *   - adaptation pour ShomGt v2
 * - 16/12/2018:
 *   - détection d'une forte utilisation du service WMS par referer=http://10.56.204.34/seamis-sig/ & ip= 185.24.184.194
 *   - réactivation du contrôle d'accès sur le WMS
 * - 10/8/2018:
 *   - Ajout de la constante Access::CNTRLFORTILE pour déasctiver le controle d'accès par tuiles
 * - 22/7/2018:
 *   - ajout d'un mécanisme d'accès par referer
 *   - transformation en classe
 * - 6/6/2018:
 *   - prise en compte dans $whiteIpList de nouvelles adresses IP RIE
 *   - indiquées sur http://pne.metier.i2/adresses-presentees-sur-internet-a1012.html
 *   - page mise à jour le 5 avril 2018
 * - 25/6/2017:
 *   - ajout d'un paramètre nolog pour controler le log dans le wms
 * - 23/6/2017:
 *   - l'inclusion du fichier n'exécute plus la fonction
 * - 14/6/2017:
 *   - intégration du log pour tracer les refus d'accès
 * - 10/6/2017:
 *   - refonte
 * - 8/6/2017:
 *   - création
 * @package shomgt\lib
 */
//die("OK ligne ".__LINE__." de ".__FILE__);
require_once __DIR__.'/log.inc.php';
require_once __DIR__.'/config.inc.php';

//echo "<pre>"; print_r($_SERVER);

/** Regroupe la logique du contrôle d'accès
 *
 * Le contrôle d'accès de base (hors accès aux tuiles) s'effectue selon 3 modes de contrôle distincts:
 *   1) pour tous les types d'accès, vérification que l'IP d'appel appartient à une liste blanche prédéfinie,
 *      ce mode permet notamment d'autoriser les requêtes provenant du RIE. Il est utilisé pour la plupart des fonctionnalités.
 *   2) pour les accès Web depuis un navigateur, vérification que le cookie adhoc contient un login/mot de passe valide,
 *   3) pour le service WMS authentification HTTP Basic.
 *
 * Le cookie adhoc est créé lors du login effectué dans le BO.
 *
 * Le contrôle de l'accès aux tuiles est différent ; il est par défaut autorisé sauf pour les IP en liste noire
 * et pour les referer en liste noire.
 *
 * Toute la logique de contrôle d'accès est regroupée dans la classe Access qui:
 *   - exploite le fichier de config
 *   - définit la méthode cntrlFor(what) pour tester si une fonctionnalité est ou non soumise au contrôle
 *   - définit la méthode cntrl() pour réaliser le contrôle
 */
class Access {
  /** nom du cookie utilisé pour stocker le login/mdp dans le navigateur */
  const COOKIENAME = 'shomusrpwd';
  /* message à afficher lors d'un refus d'accès en mode Web
  const FORBIDDEN_ACCESS_MESSAGE = "
      <body>Bonjour,</p>
      <b>Ce site est réservé aux personnels de l'Etat et de ses Etablissements publics administratifs (EPA)</b>
      en application de la
      <a href='https://www.legifrance.gouv.fr/eli/loi/2016/10/7/2016-1321/jo/texte' target='LRN'>loi pour une
      République Numérique</a>.</p>
      
      L'accès s'effectue généralement au travers d'une adresse IP correspondant à un intranet de l'Etat
      ou d'un de ses EPA (RIE, ...).<br>
      Vous accédez actuellement à ce site au travers de l'adresse IP <b>{adip}</b> qui n'est pas enregistrée
      comme une telle adresse IP.</p>
      
      Si vous avez un compte sur ce site,
      vous pouvez <a href='bo/index.php' target='_parent'>y accéder en vous authentifiant ici</a>.<br>
      
      Si vous appartenez à un service de l'Etat ou à un de ses EPA et que vous n'avez pas encore de compte,
      ou que vous avez oublié votre mot de passe,<br>
      vous pouvez vous
      <a href='bo/user.php?action=register'>enregistrer ici
      au moyen de votre adresse professionelle de courrier électronique</a>.</p>
      </body>
  ";*/
  
  /** test si le contrôle est ou non activé pour une fonctionnalité */
  static function cntrlFor(string $what): bool {
    return config('cntrlFor')[$what] ?? true;
  }
  
  /** teste si la l'adresse IP dans la liste blanche */
  private static function ipInWhiteList(): bool {
    if (in_array($_SERVER['REMOTE_ADDR'], config('ipV4WhiteList')))
      return true;
    foreach (config('ipV6PrefixWhiteList') as $ipV6Prefix)
      if (substr($_SERVER['REMOTE_ADDR'], 0, strlen($ipV6Prefix)) == $ipV6Prefix)
        return true;
    return false;
  }
  
  /** teste si la l'adresse IP dans la liste noire, utilisée pour tile.php */
  static function ipInBlackList(): bool {
    if (in_array($_SERVER['REMOTE_ADDR'], config('ipV4BlackList')))
      return true;
    foreach (config('ipV6PrefixBlackList') as $ipV6Prefix)
      if (substr($_SERVER['REMOTE_ADDR'], 0, strlen($ipV6Prefix)) == $ipV6Prefix)
        return true;
    return false;
  }
  
  /** Teste si le referer est dans la liste noire, utilisée pour tile.php */
  static function refererInBlackList(): bool {
    return in_array($_SERVER['HTTP_REFERER'] ?? null, config('refererBlackList'));
  }
  
  /* Teste si le login est enregistré dans la table MySql des utilisateurs */
  private static function loginPwdInTable(string $usrpwd): bool {
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    MySql::open($LOG_MYSQL_URI);
    //echo "usrpwd=$usrpwd<br>\n";
    $pos = strpos($usrpwd, ':');
    $email = substr($usrpwd, 0, $pos);
    $passwd = substr($usrpwd, $pos+1);
    //echo "email=$email, passswd=$passwd<br>\n";
    $sql = "select epasswd from user where email='$email' and role in ('normal','admin','restricted','system')";
    try {
      $epasswds = MySql::getTuples($sql);
    }
    catch (SExcept $e) {
      if ($e->getSCode() <> 'MySql::ErrorTableDoesntExist')
        throw new SExcept($e->getMessage(), $e->getSCode());
      \bo\createUserTable();
      $epasswds = MySql::getTuples($sql);
    }
    //echo '<pre>'; print_r($epasswds); echo "</pre>\n";
    $access = isset($epasswds[0]['epasswd']) && password_verify($passwd, $epasswds[0]['epasswd']);
    //echo "access=",$access ? 'true' : 'false',"<br>\n";
    //die("Fin dans ".__FILE__.", ligne ".__LINE__."<br>\n");
    return $access;
  }
  
  /* Teste si le login est enregistré dans lle cookie */
  private static function loginPwdInCookie(): bool {
    return isset($_COOKIE[SELF::COOKIENAME]) && self::loginPwdInTable($_COOKIE[SELF::COOKIENAME]);
  }
  
  /** Effectue le contôle.
   *
   * si $usrpwd est défini alors cntrl() teste si le couple est correct
   * s'il n'est pas défini alors teste l'accès par l'adresse IP, l'existence d'un cookie
   * si $nolog est passé à true alors pas de log de l'accès. */
  static function cntrl(string $usrpwd=null, bool $nolog=false): bool {
    //return true; // désactivation du controle d'accès
    //$verbose = true;
    // Si $usrpwd alors vérification du login/mdp
    if ($usrpwd) {
      if (isset($verbose)) echo "Fichier ",__FILE__,", ligne ",__LINE__; // @phpstan-ignore-line
      $access = self::loginPwdInTable($usrpwd);
      if (!$nolog) write_log($access);
      return $access;
    }
    // Vérification de l'accès par IP
    if (self::ipInWhiteList()) {
      if (isset($verbose)) echo "Fichier ",__FILE__,", ligne ",__LINE__; // @phpstan-ignore-line
      if (!$nolog) write_log(true);
      return true;
    }
    // Cas d'utilisation de vérification de l'accès par login/mdp en cookie
    if (self::loginPwdInCookie()) {
      if (isset($verbose)) echo "Fichier ",__FILE__,", ligne ",__LINE__; // @phpstan-ignore-line
      if (!$nolog) write_log(true);
      return true;
    }
    // refus d'accès
    if (isset($verbose)) { // @phpstan-ignore-line
      echo "Fichier ",__FILE__,", ligne ",__LINE__,"<br>\n";
      echo "<pre>_SERVER="; print_r($_SERVER); echo "</pre>\n";
    }
    if (!$nolog) write_log(false);
    return false;
  }
  
  static function test(): void {
    echo "L'adresse IP est $_SERVER[REMOTE_ADDR]<br>\n";
    if (Access::ipInWhiteList())
      echo "Elle est dans la white list<br>\n";
    else
      echo "Elle N'est PAS dans la white list<br>\n";
    echo "Login/pwd en cookie ",isset($_COOKIE['shomusrpwd']) ? $_COOKIE['shomusrpwd'] : 'ABSENT';
    echo Access::loginPwdInCookie() ? '':' NON'," autorisé<br>\n";
    if (Access::cntrl())
      echo "cntrl ok<br>\n";
    else
      echo "cntrl KO<br>\n";
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>accesscntrl</title></head>\n";
Access::test();
