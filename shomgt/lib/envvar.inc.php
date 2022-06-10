<?php
/*PhpDoc:
title: envvar.inc.php - gestion des variables d'environnement et de leur valeur par défaut
name: envvar.inc.php
journal:
  10/6/2022:
    - chgt de la valeur par défaut pour SHOMGT3_MAPS_DIR_PATH
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

class EnvVar {
  const DEFAULTS = [
    'SHOMGT3_SERVER_URL' => 'https://sgserver.geoapi.fr/index.php',
    'SHOMGT3_MAPS_DIR_PATH' => __DIR__.'/../../data/maps',
  ];
  
  static function val(string $name): string {
    if ($val = getenv($name))
      return $val;
    else
      return self::DEFAULTS[$name] ?? null;
  }
};