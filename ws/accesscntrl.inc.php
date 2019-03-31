<?php
/*PhpDoc:
name: accesscntrl.inc.php
title: accesscntrl.inc.php - contrôle d'accès
includes: [ log.inc.php ]
doc: |
  Le contrôle d'accès utilise 3 modes de contrôle distincts:
    1) vérification que l'IP d'appel appartient à une liste blanche prédéfinie, ce mode permet notamment d'autoriser
      les requêtes provenant du RIE. Il est utilisé pour toutes les fonctionnalités.
    2) vérification qu'un cookie contient un login/mot de passe, utilisé pour les accès Web depuis un navigateur.
    3) authentification HTTP Basic, utilisé pour le service WMS.
  Pour la varification du cookie, la page login.php permet de stocker dans le cookie le login/mdp
  Toute la logique de controle d'accès est regroupée dans la classe Access qui:
    - exploite le fichier de config
    - expose la méthode cntrlFor(what) pour tester si une fonctionnalité est ou non soumise au contrôle
    - expose la méthode cntrl() pour réaliser le contrôle 
journal: |
  30/3/2019:
    dadaptation pour ShomGt v2
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

  // activation ou non du controle d'accès par fonctionnalité
  static function cntrlFor(string $what): bool {
    return isset(config('cntrlFor')[$what]) ? config('cntrlFor')[$what] : true;
  }
  
  // liste des adresses IP autorisées
  private static function ipInWhiteList() { return in_array($_SERVER['REMOTE_ADDR'], config('ipWhiteList')); }
  
  private static function loginPwdInCookie() {
    return isset($_COOKIE[SELF::COOKIENAME]) && in_array($_COOKIE[SELF::COOKIENAME], config('loginPwds'));
  }
  
  // si $usrpwd est défini cntrl() teste s'il le couple est correct
  // s'il n'est pas défini alors teste l'accès par l'adresse IP, l'existence d'un cookie
  // si $nolog est passé à true alors pas de log de l'accès
  static function cntrl(string $usrpwd=null, bool $nolog=false): bool {
    //return true; // désactivation du controle d'accès
    //$verbose = true;
    // Si $usrpwd alors vérification du login/mdp
    if ($usrpwd) {
      if (isset($verbose)) echo "Fichier ",__FILE__,", ligne ",__LINE__;
      $access = in_array($usrpwd, config('loginPwds'));
      if (!$nolog) write_log($access);
      return $access;
    }
    // Vérification de l'accès par IP
    if (self::ipInWhiteList()) {
      if (isset($verbose)) echo "Fichier ",__FILE__,", ligne ",__LINE__;
      if (!$nolog) write_log(true);
      return true;
    }
    // Cas d'utilisation de vérification de l'accès par login/mdp en cookie
    if (self::loginPwdInCookie()) {
      if (isset($verbose)) echo "Fichier ",__FILE__,", ligne ",__LINE__;
      if (!$nolog) write_log(true);
      return true;
    }
    // refus d'accès
    if (isset($verbose)) {
      echo "Fichier ",__FILE__,", ligne ",__LINE__,"<br>\n";
      echo "<pre>_SERVER="; print_r($_SERVER); echo "</pre>\n";
    }
    if (!$nolog) write_log(false);
    return false;
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>accesscntrl</title></head>\n";
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
