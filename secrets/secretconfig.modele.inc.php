<?php
/*PhpDoc:
name: secretconfig.modele.inc.php
title: secretconfig.modele.inc.php - fichier modèle de config contenant des infos confidentielles
doc: |
journal: |
  10/6/2023:
    création de ce modèle
*/
/** @return array<string>|string */
function config(string $rubrique): array|string {
  static $config = [
    // controle activé au non par fonctionnalité
    'cntrlFor'=> [
      'wms'=> true, # activé pour WMS
      'tile'=> false, # DESACTIVE pour l'accès par tuiles
      'homePage'=> true, # activé pour la page d'accueil
      'geoTiffCatalog'=> true, # activé pour le catalogue des GéoTiff
      'sgServer'=> true, # activé pour le serveur de cartes 7z de ShomGT3
    ],
    
    // liste des adresses IP V4 autorisées utilisée lorsque le contrôle est activé
    'ipV4WhiteList'=> [
      '199.19.249.196',  // RIE
      '185.24.185.194',  // RIE
      '185.24.186.194',  // RIE
      '185.24.184.194',  // RIE
      '185.24.184.209',  // RIE
      '185.24.187.196',  // RIE
      '185.2.196.196',   // RIE
      '185.24.187.194',
      '185.24.184.208',
      '185.24.184.212',
      '185.24.185.212',
      '185.24.186.212',
      '185.24.187.212',
      '185.24.186.192',
      '185.24.187.124',
      '185.24.187.191',
      '83.206.157.137',
      '86.246.91.34',
    ],
    
    // liste des prefixes d'adresses IP V6 autorisées utilisée lorsque le contrôle est activé
    'ipV6PrefixWhiteList'=> [
    ],
    
    // liste des adresses IP V4 interdites utilisée pour le contrôle à tile.php
    'ipV4BlackList'=> [
      '81.252.156.117',	// IP abusive: 122675 entre 2021-06-22 et 2022-01-20 - restriction mise en place le 6/2/2022
    ],
  
    // liste des prefixes d'adresses IP V6 interdites utilisée pour le contrôle à tile.php
    'ipV6PrefixBlackList'=> [
    ],
    
    // liste des login/mdp autorisés comme utilisateurs
    'loginPwds' => [
      'login:passwd', // exemple de compte avec 'login' comme login et 'passwd' comme mot de passe
    ],
    
    // liste des login/mdp autorisés comme administrateurs
    'admins'=> [
      'login:passwd', // exemple de compte avec 'login' comme login et 'passwd' comme mot de passe
    ],
  ];
  

  // Accès à une des rubriques du fichier de config
  return $config[$rubrique] ?? [];
};
