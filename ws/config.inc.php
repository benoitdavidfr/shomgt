<?php
/*PhpDoc:
name: config.inc.php
title: config.inc.php - fichier de config par défaut
doc: |
  Le vrai fichier de config est secretconfig.inc.php qui contient des infos confidentielles
  S'il existe, c'est lui qui est utilisé
  Sinon ce fichier congtient une configuration par défaut
*/
if (is_file(__DIR__.'/secretconfig.inc.php'))
  require_once __DIR__.'/secretconfig.inc.php';
else {
  // Accès à une des rubriques du fichier de config
  function config(string $rubrique): array {
    static $config = [
      // controle activé au non par fonctionnalité
      'cntrlFor'=> [
        'wms'=> true, # activé pour WMS
        'tile'=> false, # DESACTIVE pour l'accès par tuiles
        'homePage'=> true, # activé pour la page d'accueil
        'geoTiffCatalog'=> true, # activé pour le catalogue des GéoTiff
      ],
    
      // liste des adresses IP autorisées
      'ipWhiteList'=> [
        '127.0.0.1', // Accès local sur le Mac
        '172.17.0.1', // accès entre machines docker
      ],
    
      // liste des login/mdp autorisés
      'loginPwds' => [
        'demo:demo',
      ],
    
      # Paramétrage du serveur MySQL en fonction du serveur hébergeant l'application
      # mysql://{user}:{passwd}@{host}/{database}
      'mysqlParams'=> [
        'MODELE'=> 'mysql://{user}:{passwd}@{host}/{database}',
      ],
    ];

    return isset($config[$rubrique]) ? $config[$rubrique] : [];
  };
}
