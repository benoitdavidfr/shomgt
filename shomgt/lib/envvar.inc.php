<?php
// envvar.inc.php - gestion des variables d'environnement et de leur valeur par défaut

class EnvVar {
  const DEFAULTS = [
    'SHOMGT3_SERVER_URL' => 'https://sgserver.geoapi.fr/index.php',
    'SHOMGT3_MAPS_DIR_PATH' => '/var/www/data/maps',
  ];
  
  static function val(string $name): string {
    if ($val = getenv($name))
      return $val;
    else
      return self::DEFAULTS[$name] ?? null;
  }
};