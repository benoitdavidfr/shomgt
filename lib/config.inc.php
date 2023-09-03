<?php
/** fichier de config par défaut
 *
 * Le vrai fichier de config est secretconfig.inc.php qui contient des infos confidentielles
 * S'il existe, c'est lui qui est utilisé
 * Sinon ce fichier contient une configuration par défaut
 *
 * journal:
 * - 2/7/2022:
 *   - suppression de la rubrique mySqlParams transférée en var. d'env.
 * - 27/12/2020:
 *   - ajout rubrique admins
 * - 23/5/2020:
 *   - ajout du contrôle IPv6
 * - 9/11/2019
 *   - amélioration du controle d'accès
 */
if (is_file(__DIR__.'/../secrets/secretconfig.inc.php'))
  require_once __DIR__.'/../secrets/secretconfig.inc.php';
else {
  // Accès à une des rubriques du fichier de config
  /** @return string|array<string, mixed> */
  function config(string $rubrique): array|string {
    static $config = [
      // controle activé au non par fonctionnalité
      'cntrlFor'=> [
        'wms'=> false, # désactivé pour WMS
        'tile'=> false, # désactivé pour l'accès par tuiles
        'homePage'=> false, # désactivé pour la page d'accueil
        'geoTiffCatalog'=> false, # désactivé pour le catalogue des GéoTiff
        'sgServer'=> true,
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
      /* Les rôles sont:
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
      */
      'loginPwds' => [
        'user' => ['passwd'=> 'user', 'role'=> 'normal'],
        'admin' => ['passwd'=> 'admin', 'role'=> 'admin'],
        'demo' => ['passwd'=> 'demo', 'role'=> 'normal'],
      ],
      
      // liste des noms de domaine acceptés pour le mail d'inscription
      // cette chaine correspond à la fin de l'adresse mail
      'domains'=> [
        '.gouv.fr',
        '@ofb.fr',
        '@cerema.fr',
        '@shom.fr',
        '@ign.fr',
      ],
      
      // Paramétrage d'un éventuel proxy
      'proxy'=> 'http://172.17.0.8:3128',
    ];

    return $config[$rubrique] ?? [];
  };
}
