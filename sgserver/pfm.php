<?php
{/*PhpDoc:
title: pfm.php - gestionnaire de portefeuille - 19/6/2023
doc: |
  Implémente un gestionnaire de portefeuille mettant en oeuvre la structure du portefeuille
  notamment pour ajouter une nouvelle livraison.
  La structuration du portefeuille est définie dans phpdoc.yaml
  
  Utilisé en CLI.
  
  La classe MapMetadata regoupe des méthodes pour récupérer des MD essentielles dans le fichier ISO 19139 de la carte
  y compris en allant le chercher dans le fichier 7z
  
  opérations:
    add:
    cancel:
    purge:
      - supprime les archives avant strictement une certaine livraison
        - pour chaque archive avant strictement une certaine livraison
          - pour chaque carte de l'archive
          - si la carte ne correspond pas à une carte de current
            - alors suppression de la carte
*/}
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/SevenZipArchive.php';

define('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

function ganWeek2iso(string $ganWeek): string { // traduit une semaine GAN en date ISO 
  $date = new DateTimeImmutable();
  // public DateTimeImmutable::setISODate(int $year, int $week, int $dayOfWeek = 1): DateTimeImmutable
  $newDate = $date->setISODate('20'.substr($ganWeek,0,2), substr($ganWeek, 2), 3);
  return $newDate->format('Y-m-d');
}

// entrées systématiques dans les répertoires à sauter lors du parcours d'un répertoire 
function stdEntry(string $entry): bool { return in_array($entry, ['.','..','.DS_Store']); }

class MapMetadata { // construit les MD d'une carte à partir des MD ISO dans le 7z 
  // La date de revision n'est pas celle de la correction mais ed l'édition de la carte
  static function extractFromIso19139(string $mdpath): array { // lit les MD dans le fichier ISO 19138
    if (!($xmlmd = @file_get_contents($mdpath)))
      throw new Exception("Fichier de MD non trouvé pour mdpath=$mdpath");
    $xmlmd = str_replace(['gmd:','gco:'], ['gmd_','gco_'], $xmlmd);
    $mdSxe = new SimpleXMLElement($xmlmd);
    
    $citation = $mdSxe->gmd_identificationInfo->gmd_MD_DataIdentification->gmd_citation->gmd_CI_Citation;
    //print_r($citation);
    //var_dump($citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['@attributes']['codeListValue']);
    //var_dump((string)$citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['codeListValue']);
    $md = ['title'=>
      str_replace("Image numérique géoréférencée de la carte marine ", '',
        (string)$citation->gmd_title->gco_CharacterString)];
    $md['alternate'] = (string)$citation->gmd_alternateTitle->gco_CharacterString;
    
    $edition = (string)$citation->gmd_edition->gco_CharacterString;
    // ex: Edition n° 4 - 2015 - Dernière correction : 12
    // ou: Edition n° 4 - 2022 - Dernière correction : 0 - GAN : 2241
    if (!preg_match('!^[^-]*- (\d+) - [^\d]*(\d+)( - GAN : (\d+))?$!', $edition, $matches)
    // ex: Publication 1984 - Dernière correction : 101
    // ou: Publication 1989 - Dernière correction : 149 - GAN : 2250
     && !preg_match('!^[^\d]*(\d+) - [^\d]*(\d+)( - GAN : (\d+))?$!', $edition, $matches))
       throw new Exception("Format de l'édition inconnu pour \"$edition\"");
    $anneeEdition = $matches[1];
    $lastUpdate = $matches[2];
    $gan = $matches[4] ?? '';
 
    $md['version'] = $anneeEdition.'c'.$lastUpdate;
    $md['edition'] = $edition;
    $md['ganWeek'] = $gan;
    $md['ganDate'] = $gan ? ganWeek2iso($gan) : '';
    
    $date = (string)$citation->gmd_date->gmd_CI_Date->gmd_date->gco_Date;
    $dateType = (string)$citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['codeListValue'];
    $md[$dateType] = $date;
    
    return $md;
  }

  static function getFrom7z(string $pathOf7z): array { // retourne les MD ISO à partir du chemin de la carte en 7z 
    //echo "MapVersion::getFrom7z($pathOf7z)<br>\n";
    $creation = [];
    $archive = new SevenZipArchive($pathOf7z);
    foreach ($archive as $entry) {
      if (preg_match('!^\d+/CARTO_GEOTIFF_\d{4}_pal300\.xml$!', $entry['Name'])) { // CARTO_GEOTIFF_7107_pal300.xml
        //print_r($entry);
        if (!is_dir(__DIR__.'/temp'))
          if (!mkdir(__DIR__.'/temp'))
            throw new Exception("Erreur de création du répertoire __DIR__/temp");
        $archive->extractTo(__DIR__.'/temp', $entry['Name']);
        $mdPath = __DIR__."/temp/$entry[Name]";
        $md = self::extractFromIso19139($mdPath);
        unlink($mdPath);
        rmdir(dirname($mdPath));
        //echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
        return $md;
      }
      elseif (preg_match('!\.(tif|pdf)$!', $entry['Name'], $matches)) {
        $creation[$matches[1]] = $entry['DateTime'];
      }
    }
    // s'il n'existe pas de fichier de MD ISO alors retourne la version 'undefined'
    // et comme date de création la date de modification du fichier tif ou sinon pdf
    $creation = $creation['tif'] ?? $creation['pdf'] ?? null;
    //echo "getMapVersionFrom7z()-> undefined<br>\n";
    return [
      'version'=> 'undefined',
      'creation'=> $creation ? substr($creation, 0, 10) : null,
    ];
  }
  
  static function test(string $PF_PATH): void { // Test de la classe 
    if (0) { // Test sur une carte 
      $md = self::getFrom7z("$PF_PATH/current/7107.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (1) { // Test d'une carte spéciale 
      $md = self::getFrom7z("$PF_PATH/current/7330.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (0) { // test de toutes les cartes de current 
      foreach (new DirectoryIterator("$PF_PATH/current") as $entry) {
        if (substr($entry, -3) <> '.7z') continue;
        $md = self::getFrom7z("$PF_PATH/current/$entry");
        echo "getMapVersionFrom7z($entry)-> ",json_encode($md, JSON_OPTIONS),"\n";
      }
    }
    elseif (0) { // Test de ttes les cartes de archives
      foreach (new DirectoryIterator("$PF_PATH/archives") as $archive) {
        if (stdEntry($archive)) continue;
        foreach (new DirectoryIterator("$PF_PATH/archives/$archive") as $entry) {
          if (substr($entry, -3) <> '.7z') continue;
          $md = self::getFrom7z("$PF_PATH/archives/$archive/$entry");
          echo "getMapVersionFrom7z($archive/$entry)-> ",json_encode($md, JSON_OPTIONS),"\n";
        }
      }
    }
  }
};

if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH'))) { // vérifie que la var. d'env. est définie  
  die("Erreur SHOMGT3_PORTFOLIO_PATH non défini, utiliser 'export SHOMGT3_PORTFOLIO_PATH=xxx'\n");
}
if (!is_dir($PF_PATH)) { // vérifie que le path défini par la var. d'env. correspond bien à un répertoire 
  die("Erreur PORTFOLIO_PATH n'est pas un répertoire\n");
}

if ($argc == 1) { // Menu 
  echo "usage: php $argv[0] {action} [{params}]\n";
  echo " où {action} vaut:\n";
  echo "  - ls - liste les cartes courantes du PF (portefeuille)\n";
  echo "  - lsa - liste les archives et leurs cartes\n";
  echo "  - lsd - liste les cartes des livraisons\n";
  echo "  - lsdm - liste les cartes des livraisons et leurs cartes\n";
  echo "  - add {répertoire de livraison} ... {répertoire de livraison} - ajoute au PF les cartes des livraisons indiquées\n";
  echo "  - addAll - ajoute au PF les cartes de toutes les livraisons\n";
  echo "  - cancel - annule le dernier ajout et place les cartes dans son répertoire de livraison\n";
  echo "  - cancelAll - annule tous les ajouts et replace les cartes dans les répertoires de livraison\n";
  //echo "  - purge [{date}] - supprime définitivement les versions archivées antérieures à la date indiquée\n";
  echo "  - trace - indique pour chaque carte les évènements recensés\n";
  die();
}

function addDelivery(string $PF_PATH, string $deliveryName): void { // ajoute la livraison en paramètre 
  echo "renommage de $deliveryName dans archives\n";
  rename("$PF_PATH/incoming/$deliveryName", "$PF_PATH/archives/$deliveryName");
  foreach (new DirectoryIterator("$PF_PATH/archives/$deliveryName") as $mapname) { // traitement de chaque carte de la livraison
    if (substr($mapname, -3) <> '.7z') continue;
    //echo "fichier $mapname\n";
    if (is_file("$PF_PATH/current/$mapname"))
      unlink("$PF_PATH/current/$mapname");
    symlink("../archives/$deliveryName/$mapname", "$PF_PATH/current/$mapname");
    // fabrication .md.json
    $mapMdName = substr($mapname, 0, -3).'.md.json';
    if (!is_file("$PF_PATH/archives/$deliveryName/$mapMdName")) {
      $mapMd = MapMetadata::getFrom7z("$PF_PATH/archives/$deliveryName/$mapname");
      file_put_contents("$PF_PATH/archives/$deliveryName/$mapMdName", json_encode($mapMd, JSON_OPTIONS));
    }
    if (is_file("$PF_PATH/current/$mapMdName"))
      unlink("$PF_PATH/current/$mapMdName");
    symlink("../archives/$deliveryName/$mapMdName", "$PF_PATH/current/$mapMdName");
  }
  
  // supprimer les cartes retirées et marquer le retrait par un md.json avec {status: obsolete}
  if (is_file("$PF_PATH/archives/$deliveryName/index.yaml")) {
    $params = Yaml::parse(file_get_contents("$PF_PATH/archives/$deliveryName/index.yaml"));
    $toDelete = array_keys($params['toDelete'] ?? []);
    //echo 'toDelete='; print_r($toDelete);
    foreach ($toDelete as $mapName) {
      $mapName = substr($mapName, 2); // suppression du 'FR'
      if (is_file("$PF_PATH/current/$mapName.7z")) {
        echo "Retrait de de $mapName\n";
        unlink("$PF_PATH/current/$mapName.7z");
        @unlink("$PF_PATH/current/$mapName.md.json");
        file_put_contents("$PF_PATH/current/$mapName.md.json", json_encode(['status'=>'obsolete'], JSON_OPTIONS));
      }
      else {
        echo "Erreur de retrait de $mapName dans $deliveryName car absente\n";
      }
    }
  }
}

function lsad(string $path, bool $maps=true): void { // liste les archives ou les livraisons
  foreach (new DirectoryIterator($path) as $ad) {
    if (stdEntry($ad)) continue;
    if ($maps) {
      echo "$ad:\n";
      foreach (new DirectoryIterator("$path/$ad") as $entry) {
        if (substr($entry, -3) <> '.7z') continue;
        echo "  $entry\n";
      }
    }
    else {
      echo "$ad\n";
    }
  }
}

array_shift($argv); // $argv[0] devient l'action, les autres sont les éventuels paramètres

switch ($argv[0]) { // les différents traitements en fonction de l'action demandée 
  case 'testMapMetadata': {
    MapMetadata::test($PF_PATH);
    break;
  }
  case 'special': { // reconstruit les MD des cartes spéciales pour prendre en compte la modif. de MapMetadata::getFrom7z()
    foreach (
      [
        '201707cartesAEM'=> ['7330','7344','7360','8101','8502'],
        '201911cartesAEM'=> ['8509','8510','8517'],
        '20220615'=> ['8523'],
      ] as $deliveryName => $mapnames) {
        foreach ($mapnames as $mapnum) {
          $mapMd = MapMetadata::getFrom7z("$PF_PATH/archives/$deliveryName/$mapnum.7z");
          file_put_contents("$PF_PATH/archives/$deliveryName/$mapnum.md.json", json_encode($mapMd, JSON_OPTIONS));
        }
    }
    break;
  }
  case 'ls': { // liste les cartes courantes du PF (portefeuille)
    foreach (new DirectoryIterator("$PF_PATH/current") as $entry) {
      if (substr($entry, -3) <> '.7z') continue;
      echo "$entry -> ",readlink("$PF_PATH/current/$entry"),"\n";
    }
    break;
  }
  case 'lsa': { // liste les archives et leurs cartes
    lsad("$PF_PATH/archives");
    break;
  }
  case 'lsd': { // liste les livraisons 
    lsad("$PF_PATH/incoming", false);
    break;
  }
  case 'lsdm': { // liste les livraisons et leurs cartes
    lsad("$PF_PATH/incoming");
    break;
  }
  case 'add': { // ajout d'un ou plusieurs répertoires de livraison nommé incoming/{YYYYMMDD}
    {/* - renommer le répertoire incoming/{YYYYMMDD} en archives/{YYYYMMDD}
        - pour chaque carte de la livraison
          - si la carte existe déjà dans current
            - alors suppression du lien symbolique dans current
          - création d'un lien symbolique de current/{no}.7z vers archives/{YYYYMMDD}/{no}.7z
        - pour chaque carte retirée du catalogue suppression du lien symbolique dans current
    */}
    array_shift($argv); // $argv devient la liste des paramètres
    foreach ($argv as $deliveryName) {
      addDelivery($PF_PATH, $deliveryName);
    }
    break;
  }
  case 'addAll': { // ajoute au PF les cartes de toutes les livraisons 
    foreach (new DirectoryIterator("$PF_PATH/incoming") as $delivery) {
      if (stdEntry($delivery)) continue;
      addDelivery($PF_PATH, $delivery);
    }
    break;
  }
  case 'cancel': { // annule l'ajout de la dernière livraison uniquement en local
    {/* - déplace la dernière livraison de archives vers incoming
        - supprime tous les liens de current
        - ajoute chaque livraison de incoming dans l'ordre chronologique
    */}
    $lastArchive = null;
    foreach (new DirectoryIterator("$PF_PATH/archives") as $archive) {
      if (!stdEntry($archive))
        $lastArchive = (string)$archive;
    }
    if (!$lastArchive)
      die("Erreur, il n'existe plus aucune archive\n");
    echo "Transfert de $lastArchive des archives vers les livraisons\n";
    //echo "rename($PF_PATH/archives/$lastArchive, $PF_PATH/incoming/$lastArchive);\n";
    rename("$PF_PATH/archives/$lastArchive", "$PF_PATH/incoming/$lastArchive");
    
    // effacement du contenu de current
    foreach (new DirectoryIterator("$PF_PATH/current") as $mapname) { // traitement de chaque carte de current
      if (stdEntry($mapname)) continue;
      @unlink("$PF_PATH/current/$mapname");
    }
    
    // ajoute chaque livraison de incoming dans l'ordre chronologique
    foreach (new DirectoryIterator("$PF_PATH/archives") as $archive) {
      if (stdEntry($archive)) continue;
      rename("$PF_PATH/archives/$archive", "$PF_PATH/incoming/$archive");
      addDelivery($PF_PATH, $archive);
    }
    break;
  }
  case 'cancelAll': { // annule tous les ajouts de livraison et reconstitue les livraisons dans incoming
    // transfère chaque archive dans incoming
    foreach (new DirectoryIterator("$PF_PATH/archives") as $archive) {
      if (stdEntry($archive)) continue;
      rename("$PF_PATH/archives/$archive", "$PF_PATH/incoming/$archive");
    }
    // efface le contenu de current
    foreach (new DirectoryIterator("$PF_PATH/current") as $mapname) { // traitement de chaque carte de current
      if (stdEntry($mapname)) continue;
      @unlink("$PF_PATH/current/$mapname");
    }
    break;
  }
  case 'trace': {
    $maps = []; // [FR{noDeCarte} => [{deliveryId} => {mvt}]] / {mvt} ::= 'ADD' {version} | 'WITHDRAW'
    $deliveries = [];
    foreach (["$PF_PATH/archives", "$PF_PATH/incoming"] as $path) {
      foreach (new DirectoryIterator($path) as $ad) { // traitement chaque archive ou livraison
        if (stdEntry($ad)) continue;
        //if (!in_array(substr($ad,0,4), ['2022'])) continue;
        //$mapMds = [];
        echo "Traitement de $ad\n";
        foreach (new DirectoryIterator("$path/$ad") as $entry) { // chaque carte 
          if (substr($entry, -3)<>'.7z') continue;
          $entry = substr($entry, 0, -3);
          $mapMd = json_decode(file_get_contents("$path/$ad/$entry.md.json"), true);
          //$mapMds["FR$entry"] = $mapMd;
          $add = "ADD $mapMd[version]".(($mapMd['gan'] ?? null) ? '@'.$mapMd['ganDate'] : '');
          $maps["FR$entry"]["$ad"] = $add;
          //printf("memory_get_usage=%.2f Mo\n", memory_get_usage()/1024/1024);
        }
        if (is_file("$path/$ad/index.yaml")) { // mentionner les cartes retirées 
          $params = Yaml::parse(file_get_contents("$path/$ad/index.yaml"));
          $toDelete = array_keys($params['toDelete'] ?? []);
          //echo 'toDelete='; print_r($toDelete);
          foreach ($toDelete as $mapName) {
            $mapMds[$mapName] = 'RETRAIT';
            $maps[$mapName]["$ad"] = 'WITHDRAW';
          }
        }
      }
      //if (++$nbIter >= 1) die("Fin pour $nIter\n");
    }
    ksort($maps);
    echo Yaml::dump($maps);
    break;
  }
  case 'purge': { // TO BE IMPLEMENTED 
    throw new Exception("TO BE IMPLEMENTED");
  }
  default: { // Erreur, aucune action 
    die("Ereur, $argv[0] ne correspond à aucune action\n"); 
  }
}
