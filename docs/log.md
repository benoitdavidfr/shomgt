# Enregistrement de logs

Lorsque la variable d'environnement `SHOMGT3_LOG_MYSQL_URI` est définie, des logs sont enregistrés dans
la table log de la base MySQL définie par la variable.

Cette table correspond au schéma suivant:

    create table log(
      logdt datetime not null comment 'date et heure',
      ip varchar(255) not null comment 'adresse IP appelante',
      referer longtext comment 'referer appelant',
      login varchar(255) comment 'login appelant éventuel issu du cookie',
      user varchar(255) comment 'login appelant éventuel issu de l\'authentification HTTP',
      request_uri longtext comment 'requete appelée sans le host',
      access char(1) comment 'acces accordé T ou refusé F'
    )

Elle est créée automatiquement lorsqu'elle n'existe pas.

L'écriture d'un log se fait par la fonction `write_log(bool $access): bool`
définie dans le fichier `lib/log.inc.php`.
