<?php
/*PhpDoc:
name: accesscntrl.inc.php
title: accesscntrl.inc.php - contrôle d'accès
includes: [ log.inc.php, config.inc.php ]
doc: |
  Le contrôle d'accès utilise 3 modes de contrôle distincts:
    1) vérification que l'IP d'appel appartient à une liste blanche prédéfinie, ce mode permet notamment d'autoriser
      les requêtes provenant du RIE. Il est utilisé pour toutes les fonctionnalités.
    2) vérification qu'un cookie contient un login/mot de passe, utilisé pour les accès Web depuis un navigateur.
    3) authentification HTTP Basic, utilisé pour le service WMS.
  Pour la vérification du cookie, la page de login du BO permet de stocker dans le cookie le login/mdp
  Toute la logique de contrôle d'accès est regroupée dans la classe Access qui:
    - exploite le fichier de config
    - expose la méthode cntrlFor(what) pour tester si une fonctionnalité est ou non soumise au contrôle
    - expose la méthode cntrl() pour réaliser le contrôle 
journal: |
  10/8/2023:
    - utilisation des logins, passwd et role en base de données
  19/5/2022:
    - adaptation pour sgserver de ShomGT3
  23/1/2022:
    ajout ipInBlackList() pour tile.php
  27/12/2020:
    ajout test admins
  23/5/2020:
    ajout du contrôle sur préfixe IPv6
  30/3/2019:
    adaptation pour ShomGt v2
  16/12/2018:
    détection d'une forte utilisation du service WMS par referer=http://10.56.204.34/seamis-sig/ & ip= 185.24.184.194
    réactivation du contrôle d'accès sur le WMS
  10/8/2018:
    Ajout de la constante Access::CNTRLFORTILE pour déasctiver le controle d'accès par tuiles
  22/7/2018:
    ajout d'un mécanisme d'accès par referer
    transformation en classe
  6/6/2018:
    prise en compte dans $whiteIpList de nouvelles adresses IP RIE
    indiquées sur http://pne.metier.i2/adresses-presentees-sur-internet-a1012.html
    page mise à jour le 5 avril 2018
  25/6/2017:
    ajout d'un paramètre nolog pour controler le log dans le wms
  23/6/2017:
    l'inclusion du fichier n'exécute plus la fonction
  14/6/2017:
    intégration du log pour tracer les refus d'accès
  10/6/2017:
    refonte
  8/6/2017:
    création
*/
//die("OK ligne ".__LINE__." de ".__FILE__);
require_once __DIR__.'/log.inc.php';
require_once __DIR__.'/config.inc.php';

//echo "<pre>"; print_r($_SERVER);

class Access {
  const COOKIENAME = 'shomusrpwd'; // nom du cookie utilisé pour stocker le login/mdp dans le navigateur
  const FORBIDDEN_ACCESS_MESSAGE = "<body>Bonjour,</p>
      <b>Ce site est réservé aux agents de l'Etat et de ses Etablissements publics administratifs (EPA).</b><br>
      L'accès s'effectue normalement au travers d'une adresse IP correspondant à un intranet de l'Etat
      ou d'un de ses EPA (RIE, ...).<br>
      Vous accédez actuellement à ce site au travers de l'adresse IP <b>{adip}</b> qui n'est pas enregistrée
      comme une telle adresse IP.<br>
      Si vous souhaitez accéder à ce site et que vous appartenez à un service de l'Etat ou à un de ses EPA,
      vous pouvez transmettre cette adresse IP à Benoit DAVID du MTE/CGDD (contact at geoapi.fr)
      qui regardera la possibilité d'autoriser votre accès.</p>
      Si vous avez un compte sur ce site,
      vous pouvez <a href='bo/index.php' target='_parent'>y accéder en vous authentifiant ici</a>.  
  ";
  
  // activation ou non du controle d'accès par fonctionnalité
  static function cntrlFor(string $what): bool {
    return config('cntrlFor')[$what] ?? true;
  }
  
  // teste si la l'adresse IP dans la liste blanche
  private static function ipInWhiteList(): bool {
    if (in_array($_SERVER['REMOTE_ADDR'], config('ipV4WhiteList')))
      return true;
    foreach (config('ipV6PrefixWhiteList') as $ipV6Prefix)
      if (substr($_SERVER['REMOTE_ADDR'], 0, strlen($ipV6Prefix)) == $ipV6Prefix)
        return true;
    return false;
  }
  
  // teste si la l'adresse IP dans la liste noire, utilisée pour tile.php
  static function ipInBlackList(): bool {
    if (in_array($_SERVER['REMOTE_ADDR'], config('ipV4BlackList')))
      return true;
    foreach (config('ipV6PrefixBlackList') as $ipV6Prefix)
      if (substr($_SERVER['REMOTE_ADDR'], 0, strlen($ipV6Prefix)) == $ipV6Prefix)
        return true;
    return false;
  }
  
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
      createUserTable();
      $epasswds = MySql::getTuples($sql);
    }
    //echo '<pre>'; print_r($epasswds); echo "</pre>\n";
    $access = isset($epasswds[0]['epasswd']) && password_verify($passwd, $epasswds[0]['epasswd']);
    //echo "access=",$access ? 'true' : 'false',"<br>\n";
    //die("Fin dans ".__FILE__.", ligne ".__LINE__."<br>\n");
    return $access;
  }
  
  private static function loginPwdInCookie(): bool {
    return isset($_COOKIE[SELF::COOKIENAME]) && self::loginPwdInTable($_COOKIE[SELF::COOKIENAME]);
  }
  
  // si $usrpwd est défini cntrl() teste si le couple est correct
  // s'il n'est pas défini alors teste l'accès par l'adresse IP, l'existence d'un cookie
  // si $nolog est passé à true alors pas de log de l'accès
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
  
  // teste si le rôle admin est autorisé
  /*static function roleAdmin(): bool {
    if (!self::USERS_IN_MYSQL) {
      $access = in_array($_COOKIE[SELF::COOKIENAME] ?? null, config('admins'));
    }
    else {
      $usrpwd = $_COOKIE[SELF::COOKIENAME] ?? null;
      //echo "usrpwd=$usrpwd<br>\n";
      $pos = strpos($usrpwd, ':');
      $email = substr($usrpwd, 0, $pos);
      $passwd = substr($usrpwd, $pos+1);
      //echo "email=$email, passswd=$passwd<br>\n";
      $epasswds = MySql::getTuples("select epasswd from user where email='$email'");
      //echo '<pre>'; print_r($epasswds); echo "</pre>\n";
      $access = isset($epasswds[0]['epasswd']) && password_verify($passwd, $epasswds[0]['epasswd']);
      //echo "access=",$access ? 'true' : 'false',"<br>\n";
      die("Fin dans ".__FILE__.", ligne ".__LINE__."<br>\n");
    }
    
    write_log($access);
    return $access;
  }*/
  
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
