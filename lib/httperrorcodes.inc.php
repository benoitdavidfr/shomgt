<?php
/** Liste de qqs codes d'erreur Http et leur label
 * @package shomgt\lib
 */

/** liste de qqs codes d'erreur Http avec leur label */
const HTTP_ERROR_CODES = [
  204 => 'No Content', // Requête traitée avec succès mais pas d’information à renvoyer. 
  400 => 'Bad Request', // paramètres en entrée incorrects
  401 => 'Unauthorized', // Une authentification est nécessaire pour accéder à la ressource. 
  403	=> 'Forbidden', // accès interdit
  404 => 'File Not Found', // ressource demandée non disponible
  410 => 'Gone', // La ressource n'est plus disponible et aucune adresse de redirection n’est connue
  500 => 'Internal Server Error', // erreur interne du serveur
];
