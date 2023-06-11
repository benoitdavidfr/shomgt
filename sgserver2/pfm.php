<?php
{/*PhpDoc:
title: pfm.php - gestionnaire de portefeuille - 10/6/2022
doc: |
  glossaire:
    portefeuille: l'ensemble des cartes gérées avec leurs versions
    portefeuilleCourant: version plus récente des cartes gérées à l'exclusion des cartes retirées
    carteRetirée: carte retirée du catalogue par le Shom
    livraison: ensemble de cartes livrées à une date donnée + fichier de métadonnées de la livraison
  principes:
    - cartes courantes exposées dans le répertoire current
    - livraisons stockées chacune dans un répertoire archives/{YYYYMMDD} (ou au moins commençant par YYYYMMDD)
    - en local
      - conservation des livraisons précédentes dans archives
      - création dans current de liens symboliques vers les cartes adhoc des archives
      - possibilité de reconstruire current si nécessaire en utilisant les MD index.yaml
    - sur geoapi.fr, stockage des versions en cours dans current, et pas de stockage des archives
    - le code de sgserver est le même dans les 2 environnements
    - associer à chaque .7z un fichier .md.json avec les MD de la carte
    - simplifier sgserver en excluant l'utilisation des archives
  pourquoi:
    - les versions précédentes des cartes ne sont utiles que pour la gestion du portefeuille
    - complexité de la structure pour le serveur avec les fichiers mapversions.pser
    - complexité du code de gestion des versions de carte
    - nécessité de purge régulière sur geoapi
    - inutilité de stocker les archives sur geoapi
  objectif:
    - avoir la même code Php de shomgt en local et sur geoapi
    - possibilité d'annuler le dépôt d'une livraison en local
    - être compatible avec le client actuel
    - être efficace pour sgserver
  
  nouvelleStructure:
    ~/shomgeotiff/current:
      - contient les cartes courantes cad ni remplacées ni retirées, chacune avec un fichier .7z et un .md.json
      - soit
        - en local un lien symbolique par carte vers la carte dans archives, nommé par le no suivi de .7z et .md.json
        - sur geoapi stockage de la carte nommée par le no suivi de .7z et .md.json
    ~/shomgeotiff/incoming/{YYYYMMDD}:
      - un répertoire par livraison à effectuer, nommé par la date de livraison
        - ou au moins qu'un ls donne le bon ordre (tri alphabétique)
      - dans chaque répertoire de livraison les cartes de la livraison, chacune comme fichier 7z
    ~/shomgeotiff/archives/{YYYYMMDD}:
      - quand une livraison est déposée, son répertoire est déplacé dans archives
      - dans chaque répertoire d'archive les cartes de la livraison
        - chacune nommée par le no suivi de .7z
        - chacune associée à un .md.json
    avantages:
      - proche de la version actuelle
      - pas de redondance
      - plus performante que la version actuelle, 1 seul répertoire à ouvrir en Php (à vérifier)
      - possibilité de code Php identique en local et sur geoapi
    inconvénients:
      - nécessité de scripts de gestion du portefeuille en local uniquement
      - vérifier comment se passe le téléchargement sur geoapi.fr
        - soit copier l'archive et détruire si nécessaire les cartes retirées (peu fréquent)
        - soit copier current en fonction des dates de création des liens
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
require_once __DIR__.'/../main/lib/SevenZipArchive.php';

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
    }
    //echo "getMapVersionFrom7z()-> undefined<br>\n";
    return ['version'=> 'undefined'];
  }
  
  static function test(string $PF_PATH): void { // Test de la classe 
    if (0) { // Test sur une carte 
      $md = self::getFrom7z("$PF_PATH/current/7107.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (0) { // test de toutes les cartes de current 
      foreach (new DirectoryIterator("$PF_PATH/current") as $entry) {
        if (substr($entry, -3) <> '.7z') continue;
        $md = self::getFrom7z("$PF_PATH/current/$entry");
        echo "getMapVersionFrom7z($entry)-> ",json_encode($md, JSON_OPTIONS),"\n";
      }
    }
    else { // Test de ttes les cartes de archives
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
  echo "  - add [{répertoire de livraison}] - ajoute au PF les cartes de la livraison indiquée\n";
  echo "  - addAll - ajoute au PF les cartes de toutes les livraisons\n";
  echo "  - cancel - annule le dernier ajout et place les cartes dans son répertoire de livraison\n";
  echo "  - cancelAll - annule tous les ajouts et replace les cartes dans les répertoires de livraison\n";
  echo "  - purge [{date}] - supprime définitivement les versions archivées antérieures à la date indiquée\n";
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
  
  // supprimer les cartes retirées
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
      }
      else {
        echo "Erreur de retrait de $mapName, carte retirée dans $deliveryName mais absente\n";
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
  default: {
    die("Ereur, $argv[0] ne correspond à aucune action\n"); 
  }
}
