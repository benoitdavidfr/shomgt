<?php
/*PhpDoc:
name: config.inc.php
title: config.inc.php - fichier de config par défaut
doc: |
  Le vrai fichier de config est secretconfig.inc.php qui contient des infos confidentielles
  S'il existe, c'est lui qui est utilisé
  Sinon ce fichier contient une configuration par défaut
journal: |
  27/12/2020:
    ajout rubrique admins
  23/5/2020:
    ajout du contrôle IPv6
  9/11/2019
    amélioration du controle d'accès
includes: [secretconfig.inc.php]
*/
if (is_file(__DIR__.'/../../secrets/secretconfig.inc.php'))
  require_once __DIR__.'/../../secrets/secretconfig.inc.php';
else {
  // Accès à une des rubriques du fichier de config
  function config(string $rubrique): array|string {
    static $config = [
      // controle activé au non par fonctionnalité
      'cntrlFor'=> [
        'wms'=> false, # désactivé pour WMS
        'tile'=> false, # désactivé pour l'accès par tuiles
        'homePage'=> false, # désactivé pour la page d'accueil
        'mapwcat'=> false, # désactivé pour la carte leaflet
        'geoTiffCatalog'=> false, # désactivé pour le catalogue des GéoTiff
        'cat'=> true,
      ],
    
      // liste des adresses IP V4 autorisées utilisée lorsque le contrôle est activé
      'ipV4WhiteList'=> [
        //'127.0.0.1', // Accès local
        '172.17.0.1', // accès entre machines docker
      ],
    
      // liste des prefixes d'adresses IP V6 autorisées utilisée lorsque le contrôle est activé
      'ipV6PrefixWhiteList'=> [
      ],
    
      // liste des adresses IP V4 interdites utilisée pour le contrôle à tile.php
      'ipV4BlackList'=> [
      ],
    
      // liste des prefixes d'adresses IP V6 interdites utilisée lpour le contrôle à tile.php
      'ipV6PrefixBlackList'=> [
      ],
    
      // liste des login/mdp autorisés comme utilisateurs utilisée lorsque le contrôle est activé
      'loginPwds' => [
        'user:user',
        'admin:admin',
        'demo:demo',
      ],
    
      // liste des login/mdp autorisés comme administrateurs
      'admins'=> [
        'admin:admin',
      ],
    
      // Paramétrage du serveur MySQL pour enregistrer les logs en fonction du serveur hébergeant l'application
      // Le nom_du_serveur est défini par $_SERVER['HTTP_HOST']
      'mysqlParams'=> [
        'nom_du_serveur'=> 'mysql://{user}:{passwd}@{host}/{database}',
      ],
      
      // Paramétrage d'un éventuel proxy
      'proxy'=> 'http://172.17.0.8:3128',
    ];

    return $config[$rubrique] ?? [];
  };
}
