<?php
/**
 * fonction d'enregistrement d'un log
 *
 * journal: |
 * - 22/8/2023:
 *   - ajout champ host dans la table log
 * - 2/7/2022:
 *   - changement de logique
 *   - le log n'est plus paramétré dans config('mysqlParams') mais au travers de la var. d'env. SHOMGT3_LOG_MYSQL_URI
 * - 5/5/2022:
 *   - correction bug
 * - 7/2/2022:
 *   - ajout de code aux exceptions, une constante par méthode
 * - 15/12/2018:
 *   - ajout de la création de la table si elle n'existe pas
 *   - de plus dans openMySQL() sur localhost la base est créée si elle n'existe pas
 * - 20/7/2017:
 *   - suppression de l'utilisation du champ phpserver
 * @package shomgt\lib
 */
require_once __DIR__.'/mysql.inc.php';
require_once __DIR__.'/sexcept.inc.php';

/** retourne la commande SQL de création de la table */
function log_table_schema(): string {
  return "create table log(
      logdt datetime not null comment 'date et heure',
      ip varchar(255) not null comment 'adresse IP appelante',
      referer longtext comment 'referer appelant',
      login varchar(255) comment 'login appelant éventuel issu du cookie',
      user varchar(255) comment 'login appelant éventuel issu de l\'authentification HTTP',
      host varchar(255) comment 'host appelé',
      request_uri longtext comment 'requete appelée sans le host',
      access char(1) comment 'acces accordé T ou refusé F'
    )";
}

/** nom du cookie utilisé pour le login */
const COOKIE_NAME = 'shomusrpwd';

/** enregistrement d'un log */
function write_log(bool $access): bool {
  // si la variable d'env. n'est pas définie alors le log est désactivé
  if (!($LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')))
    return $access;
  
  try {
    MySql::open($LOG_MYSQL_URI);
  }
  catch (SExcept $e) {
    throw new SExcept($e->getMessage(), $e->getSCode());
  }

//  echo "<pre>"; print_r($_SERVER); die();
  $login = isset($_COOKIE[COOKIE_NAME]) ? "'".substr($_COOKIE[COOKIE_NAME], 0, strpos($_COOKIE[COOKIE_NAME], ':'))."'" : 'NULL';
  $user = isset($_SERVER['PHP_AUTH_USER']) ? "'".$_SERVER['PHP_AUTH_USER']."'" : 'NULL';
  //  $phpserver = json_encode($_SERVER);
  $referer = isset($_SERVER['HTTP_REFERER']) ? "'$_SERVER[HTTP_REFERER]'" : 'NULL';
  // Creation d'une enregistrement dans le log
  $sql = "insert into log(logdt, ip, referer, login, user, host, request_uri, access) "
        ."values (now(), '$_SERVER[REMOTE_ADDR]', $referer, $login, $user,"
          ." '$_SERVER[HTTP_HOST]', '$_SERVER[REQUEST_URI]', '".($access?'T':'F')."')";
  //echo "<pre>",$sql,"</pre>\n";
  try {
    MySql::query($sql);
  }
  catch (SExcept $e) {
    if ($e->getSCode() == MySql::ErrorTableDoesntExist) {
      MySql::query(log_table_schema());
      MySql::query($sql);
    }
    else {
      throw new SExcept($e->getMessage(), $e->getSCode());
    }
  }
  return $access;
}
