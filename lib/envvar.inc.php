<?php
/** gestion des variables d'environnement et de leur valeur par défaut
 * journal:
 * - 10/6/2022:
 *   - chgt de la valeur par défaut pour SHOMGT3_MAPS_DIR_PATH
 * @package shomgt\lib
 */
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

class EnvVar {
  const DEFAULTS = [
    'SHOMGT3_SERVER_URL' => 'https://sgserver.geoapi.fr/index.php',
    'SHOMGT3_MAPS_DIR_PATH' => __DIR__.'/../data/maps',
    'SHOMGT3_UPDATE_DURATION'=> '0',
  ];
  
  static function val(string $name): ?string {
    if ($val = getenv($name))
      return $val;
    else
      return self::DEFAULTS[$name] ?? null;
  }
};