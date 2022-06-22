<?php
/*PhpDoc:
title: mapversion.inc.php - gère les versions des cartes du portefeuille
name: mapversion.inc.php
doc: |
*/
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/SevenZipArchive.php';
require_once __DIR__.'/readmapversion.inc.php';

use Symfony\Component\Yaml\Yaml;

// gère les versions des cartes, permet de déduire maps.json et lastdelivery
// construction d'un fichier par livraison pour éviter d'avoir tout à recalculer
// Chaque objet correspond à l'état d'une carte pour une livraison ou l'ensemble des livraisons
class MapVersion {
  protected string $status; // 'ok' | 'obsolete'
  protected int $nbre=0; // nbre de téléchargements disponibles pour la carte
  protected ?string $lastVersion=null; // identifiant de la dernière version
  protected ?string $modified=null; // date de dernière modification de la dernière version (issue du champ dateStamp des MD)
  protected ?string $lastDelivery=null; // nom du répertoire de livraison correspondant à la version
  
  static function deliveries(string $INCOMING_PATH): array { // liste des livraisons triées 
    $deliveries = []; // liste des livraisons qu'il est nécessaire de trier 
    foreach (new DirectoryIterator($INCOMING_PATH) as $delivery) {
      if (($delivery->getType() == 'dir') && !$delivery->isDot()) {
        $deliveries[] = $delivery->getFilename();
      }
    }
    sort($deliveries, SORT_STRING);
    return $deliveries;
  }
  
  // fabrique un objet à partir soit du status 'ok' et de l'info ['version'=> {version}, 'dateStamp'=> {dateStamp}]
  // retournée par self::getFrom7z(), soit du status 'obsolete'
  private function __construct(string $status, array $mapVersion=[]) {
    if ($status == 'obsolete')
      $this->status = 'obsolete';
    else {
      $this->status = 'ok';
      $this->nbre = 1;
      $this->lastVersion = $mapVersion['version'];
      $this->modified = $mapVersion['dateStamp'] ?? '';
    }
  }
  
  // prend en compte une nouvelle version en combinant les objets
  private function update(self $new): void {
    //echo "<pre>update, this="; print_r($this); print_r($new);
    if ($new->status == 'obsolete')
      $this->status = 'obsolete';
    else {
      $this->status = 'ok';
      $this->nbre++;
      $this->lastVersion = $new->lastVersion;
      $this->modified = $new->modified;
    }
    //print_r($this);
  }
  
  private function asArray(string $mapnum): array { // fabrique un array pour allAsArray()
    $https = (($_SERVER['HTTPS'] ?? '') == 'on') ? 'https' : 'http';
    return [
      'status'=> $this->status,
      'nbre'=> $this->nbre,
      'lastVersion'=> $this->lastVersion,
      'modified'=> $this->modified,
      'url'=> isset($_SERVER['HTTP_HOST']) ? "$https://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]/maps/$mapnum.json" : '',
    ];
  }
  
  // Renvoit pour la carte formattée comme archive 7z située à $pathOf7zun
  // le dict. ['version'=> {version}, 'dateStamp'=> {dateStamp}] où {version} est le libellé de la version
  // et {dateStamp} est la date de dernière modification du fichier des MD de la carte
  // Renvoit ['version'=> 'undefined'] si la carte ne comporte pas de MDISO et donc pas de version.
  static function getFrom7z(string $pathOf7z): array {
    //echo "MapVersion::getFrom7z($pathOf7z)<br>\n";
    $archive = new SevenZipArchive($pathOf7z);
    foreach ($archive as $entry) {
      if (preg_match('!^\d+/CARTO_GEOTIFF_[^.]+\.xml$!', $entry['Name'])) {
        //print_r($entry);
        if (!is_dir(__DIR__.'/temp'))
          if (!mkdir(__DIR__.'/temp'))
            throw new Exception("Erreur de création du répertoire __DIR__/temp");
        $archive->extractTo(__DIR__.'/temp', $entry['Name']);
        $mdPath = __DIR__."/temp/$entry[Name]";
        $mapVersion = readMapVersion($mdPath);
        unlink($mdPath);
        rmdir(dirname($mdPath));
        //echo "getMapVersionFrom7z()-> $mapVersion<br>\n";
        return $mapVersion;
      }
    }
    //echo "getMapVersionFrom7z()-> undefined<br>\n";
    return ['version'=> 'undefined'];
  }

  // construit si nécessaire et enregistre les versions de carte pour la livraison
  private static function buildForADelivery(string $deliveryPath): array {
    //echo "buildForADelivery($deliveryPath)<br>\n";
    if (is_file("$deliveryPath/mapversions.pser") && (filemtime("$deliveryPath/mapversions.pser") > filemtime($deliveryPath))) {
      return unserialize(file_get_contents("$deliveryPath/mapversions.pser"));
    }
    
    $mapVersions = []; // [{manum} => MapVersion] - la dernière version pour chaque carte + nbre de téléchargements
    if (is_file("$deliveryPath/index.yaml")) {
      $index = Yaml::parseFile("$deliveryPath/index.yaml");
      foreach (array_keys($index['toDelete'] ?? []) as $mapid) {
        $mapnum = substr($mapid, 2);
        //echo "** $mapnum obsolete<br>\n";
        $mapVersions[$mapnum] = new self('obsolete');
      }
    }
    foreach (new DirectoryIterator($deliveryPath) as $map7z)  {
      if (($map7z->getType() == 'file') && ($map7z->getExtension()=='7z')) {
        //echo "- carte $map7z<br>\n";
        $mapnum = $map7z->getBasename('.7z');
        //echo "** $mapnum valide<br>\n";
        $mapVersion = self::getFrom7z("$deliveryPath/$map7z");
        $mapVersions[$mapnum] = new self('ok', $mapVersion);
      }
    }
    file_put_contents("$deliveryPath/mapversions.pser", serialize($mapVersions));
    //echo '<pre>$mapVersions='; print_r($mapVersions); echo "</pre>\n";
    return $mapVersions;
  }
  
  // construit si nécessaire et enregistre les versions de cartes pour toutes les livraisons
  private static function buildAll(string $INCOM_PATH): array {
    if (is_file("$INCOM_PATH/mapversions.pser") && (filemtime("$INCOM_PATH/mapversions.pser") > filemtime($INCOM_PATH))) {
      return unserialize(file_get_contents("$INCOM_PATH/mapversions.pser"));
    }
    
    $mapVersions = []; // [{manum} => MapVersion] - la dernière version pour chaque carte + nbre de téléchargements
    foreach (self::deliveries($INCOM_PATH) as $delivery) {
      $mapVDel = self::buildForADelivery("$INCOM_PATH/$delivery");
      foreach ($mapVDel as $mapnum => $mapVersion) {
        if (!isset($mapVersions[$mapnum]))
          $mapVersions[$mapnum] = $mapVersion;
        else
          $mapVersions[$mapnum]->update($mapVersion);
        $mapVersions[$mapnum]->lastDelivery = $delivery;
      }
    }
    ksort($mapVersions, SORT_STRING);
    file_put_contents("$INCOM_PATH/mapversions.pser", serialize($mapVersions));
    return $mapVersions;
  }
  
  // construit l'array à fournir pour maps.json
  static function allAsArray(string $INCOMING_PATH): array {
    $allAsArray = [];
    $mapVersions = self::buildAll($INCOMING_PATH);
    foreach($mapVersions as $mapnum => $mapVersion)
      $allAsArray[$mapnum] = $mapVersion->asArray($mapnum);
    return $allAsArray;
  }
  
  // Renvoie pour une carte $mapnum le chemin du 7z de sa dernière livraison ou '' s'il n'y en a aucune.
  static function lastDelivery(string $INCOMING_PATH, string $mapnum): string {
    $mapVersions = self::buildAll($INCOMING_PATH);
    if (isset($mapVersions[$mapnum]))
      return $INCOMING_PATH.'/'.$mapVersions[$mapnum]->lastDelivery."/$mapnum.7z";
    else
      return '';
  }
};

