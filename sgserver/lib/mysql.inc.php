<?php
/*PhpDoc:
name: mysql.inc.php
title: mysql.inc.php - classes MySql et MySqlResult utilisées pour exécuter des requêtes MySql
classes:
doc: |
  Simplification de l'utilisation de MySql.
  La méthode statique MySql::open() prend en paramètre les paramètres MySql
  sous la forme mysql://{user}:{passwd}@{host}/{database}
  Voir utilisation en fin de fichier
  Sur localhost si la base utilisée n'existe pas alors elle est créée.
journal: |
  16/4/2022:
    - correction d'un bug dans MySql::query()
  7-9/2/2022:
    utilisation des exceptions de MySqli en interne à la classe MySql
    ajout des codes aux exceptions avec des codes simplifiés par rapport à ceux de MySqli
  23/11/2019:
    sur localhost si la base à ouvrir n'existe pas alors elle est créée pour simplifier le redémérrage d'un serveur docker vide
  3/8/2018 15:00
    ajout MySql::server()
  3/8/2018
    création
includes: [sexcept.inc.php]
*/
require_once __DIR__.'/sexcept.inc.php';

// Activation du rapport d'erreur - Lance une exception mysqli_sql_exception pour les erreurs, au lieu d'émettre des alertes 
mysqli_report(MYSQLI_REPORT_STRICT);

class MySql {
  const ErrorOpen = 'MySql::ErrorOpen';
  const ErrorServer = 'MySql::ErrorServer';
  const ErrorQuery = 'MySql::ErrorQuery';
  const ErrorTableDoesntExist = 'MySql::ErrorTableDoesntExist';

  static ?mysqli $mysqli=null; // handle MySQL
  static ?string $server=null; // serveur MySql
    
  // ouvre une connexion MySQL et enregistre le handle en variable de classe
  static function open(string $mysqlParams) {
    if (!preg_match('!^mysql://([^:]+):([^@]+)@([^/]+)/(.*)$!', $mysqlParams, $matches))
      throw new SExcept("Erreur: dans MySql::open() params \"".$mysqlParams."\" incorrect", self::ErrorOpen);
    //print_r($matches);
    
    try {
      self::$mysqli = new mysqli($matches[3], $matches[1], $matches[2], $matches[4]);
      self::$server = $matches[3];
      // La ligne ci-dessous ne s'affiche pas correctement si le serveur est arrêté !!!
      //    throw new SExcept("Connexion MySQL impossible pour $server_name : ".mysqli_connect_error());
    }
    catch (mysqli_sql_exception $e) {
      //echo $e,"\ncode=",$e->getCode(),"\n";
      if (($e->getCode() <> 1049) || ($_SERVER['HTTP_HOST'] <> 'localhost'))
        throw new SExcept(
          "Erreur: dans MySql::open() connexion MySQL impossible sur mysql://$matches[1]:***@$matches[3]/$matches[4]",
          self::ErrorOpen);
      try {
        // si la base n'existe pas sur localhost alors je la crée
        self::$mysqli = new mysqli($matches[3], $matches[1], $matches[2], 'sys');
        $sql = 'CREATE DATABASE IF NOT EXISTS `shomgt` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci';
        $result = self::$mysqli->query($sql);
      }
      catch (mysqli_sql_exception $e) {
        throw new SExcept(
          "Erreur: dans MySql::open() connexion MySQL impossible sur mysql://$matches[1]:***@$matches[3]/$matches[4]",
          self::ErrorOpen);
      }
      if (!self::$mysqli->select_db($matches[4]))
        throw new SExcept("select_db($matches[4]) invalide: ".self::$mysqli->error, self::ErrorOpen);
    }
    if (!self::$mysqli->set_charset ('utf8'))
      throw new SExcept(
        "Erreur: dans MySql::open() mysqli->set_charset() impossible : ".self::$mysqli->error,
        self::ErrorOpen);
  }
  
  static function server(): string {
    if (!self::$server)
      throw new SExcept("Erreur: dans MySql::server() server non défini", self::ErrorServer);
    return self::$server;
  }
  
  // exécute une requête MySQL, soulève une exception en cas d'erreur, renvoie le résultat
  static function query(string $sql) {
    if (!self::$mysqli)
      throw new SExcept("Erreur: dans MySql::query() mysqli non défini", self::ErrorQuery);
    if (!($result = self::$mysqli->query($sql, MYSQLI_USE_RESULT))) {
      //echo "sql:$sql\n";
      if (strlen($sql) > 1000)
        $sql = substr($sql, 0, 800)." ...";
      if (self::$mysqli->errno == 1146)
        throw new SExcept(self::$mysqli->error.' ('.self::$mysqli->errno.')', self::ErrorTableDoesntExist);
      else
        throw new SExcept("Req. \"$sql\" invalide: ".self::$mysqli->error.' ('.self::$mysqli->errno.')', self::ErrorQuery);
    }
    if ($result === TRUE)
      return TRUE;
    else
      return new MySqlResult($result);
  }
};

// la classe MySqlResult permet d'utiliser le résultat d'une requête comme un itérateur
class MySqlResult implements Iterator {
  const ErrorRewind = 'MySqlResult::ErrorRewind';

  private ?mysqli_result $result = null; // l'objet mysqli_result
  private ?array $ctuple = null; // le tuple courant ou null
  private bool $firstDone = false; // vrai ssi le first rewind a été effectué
  
  function __construct(mysqli_result $result) { $this->result = $result; }
  
  function rewind(): void {
    if ($this->firstDone) // nouveau rewind
      throw new SExcept("Erreur dans MySqlResult::rewind() : un seul rewind() autorisé", self::ErrorRewind);
    $this->firstDone = true;
    $this->next();
  }
  function current(): array { return $this->ctuple; }
  function key(): int { return 0; }
  function next(): void { $this->ctuple = $this->result->fetch_array(MYSQLI_ASSOC); }
  function valid(): bool { return ($this->ctuple <> null); }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


require_once __DIR__.'/config.inc.php';

echo "<html><head><meta charset='UTF-8'><title>mysql</title></head><body><pre>";

MySql::open(config('mysqlParams')['geoapi.fr']);
$sql = "select * from log limit 10";
//$sql = "describe log";
if (0) {  // Test 2 rewind 
  $result = MySql::query($sql);
  foreach ($result as $tuple) {
    print_r($tuple);
  }
  echo "relance\n";
  foreach ($result as $tuple) {
    print_r($tuple);
  }
}
else {
  try {
    $result = MySql::query($sql);
  }
  catch (SExcept $e) {
    if ($e->getCode() == MySql::ErrorTableDoesntExist) {
      try {
        $sql_create_table = "create table log(
            logdt datetime not null comment 'date et heure',
            ip varchar(255) not null comment 'adresse IP appelante',
            referer longtext comment 'referer appelant',
            login varchar(255) comment 'login appelant éventuel issu du cookie',
            user varchar(255) comment 'login appelant éventuel issu de l\'authentification HTTP',
            request_uri longtext comment 'requete appelée sans le host',
            access char(1) comment 'acces accordé T ou refusé F'
          )";
        MySql::query($sql_create_table);
        $result = MySql::query($sql);
      }
      catch (SExcept $e) {}
    }
    else {
      echo "$e\n";
    }
  }
  foreach ($result as $tuple) {
    print_r($tuple);
  }
}
