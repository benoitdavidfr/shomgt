<?php
/** génération d'une MD simplifiée à partir du fichier XML ISO - 4/8/2023
 * @package shomgt\bo
 */
namespace bo;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/my7zarchive.inc.php';

use Symfony\Component\Yaml\Yaml;

/** construit les MD synthétiques d'une carte à partir des MD ISO dans le 7z  */
class MapMetadata {
  static function ganWeek2iso(string $ganWeek): string { // traduit une semaine GAN en date ISO 
    $date = new \DateTimeImmutable();
    // public DateTimeImmutable::setISODate(int $year, int $week, int $dayOfWeek = 1): DateTimeImmutable
    $newDate = $date->setISODate(intval('20'.substr($ganWeek,0,2)), intval(substr($ganWeek, 2)), 3);
    return $newDate->format('Y-m-d');
  }

  // La date de revision n'est souvent pas celle de la correction mais de l'édition de la carte
  // Le schéma des MD simplifiées est défini par md.schema.yaml
  // $mdpath est le chemin du fichier xml contenant les MD ISO 19139
  /** @return array<string, string> */
  static function extractFromIso19139(string $mdpath): array { // lit les MD dans le fichier ISO 19139
    $md = [];
    if (!($xmlmd = @file_get_contents($mdpath)))
      throw new \Exception("Fichier de MD non trouvé pour mdpath=$mdpath");
    $xmlmd = str_replace(['gmd:','gco:'], ['gmd_','gco_'], $xmlmd);
    $mdSxe = new \SimpleXMLElement($xmlmd);
    
    $citation = $mdSxe->gmd_identificationInfo->gmd_MD_DataIdentification->gmd_citation->gmd_CI_Citation;
    //print_r($citation);
    //var_dump($citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['@attributes']['codeListValue']);
    //var_dump((string)$citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['codeListValue']);
    $md['title'] = str_replace("Image numérique géoréférencée de la carte marine ", '',
        (string)$citation->gmd_title->gco_CharacterString);
    $md['alternate'] = (string)$citation->gmd_alternateTitle->gco_CharacterString;
    
    $edition = (string)$citation->gmd_edition->gco_CharacterString;
    // ex: Edition 2 - 2023
    // ex: Publication 2015
    if (preg_match('!^(Edition \d+ - |Publication )(\d+)$!', $edition, $matches)) {
      $anneeEdition = $matches[2];
      $lastUpdate = 0;
      $gan = '';
    }
    // ex: Edition n° 4 - 2015 - Dernière correction : 12
    // ou: Edition n° 4 - 2022 - Dernière correction : 0 - GAN : 2241
    // ou: Edition n° 2 - 2016 - Dernière correction :  - GAN : 2144
    elseif (preg_match('!^Edition [^-]*- (\d+) - Dernière correction : (\d+)?( - GAN : (\d+))?$!', $edition, $matches)) {
      $anneeEdition = $matches[1];
      $lastUpdate = ($matches[2]<>'') ? $matches[2] : '0';
      $gan = $matches[4] ?? '';
    }
    // ex: Publication 1984 - Dernière correction : 101
    // ou: Publication 1989 - Dernière correction : 149 - GAN : 2250
    elseif (preg_match('!^Publication (\d+) - Dernière correction : (\d+)( - GAN : (\d+))?$!', $edition, $matches)) {
      $anneeEdition = $matches[1];
      $lastUpdate = ($matches[2]<>'') ? $matches[2] : '0';
      $gan = $matches[4] ?? '';
    }
    else
       throw new \Exception("Format de l'édition inconnu pour \"$edition\"");
 
    $md['version'] = $anneeEdition.'c'.$lastUpdate;
    $md['edition'] = $edition;
    $md['gan'] = $gan ? ['week'=> $gan, 'date'=> self::ganWeek2iso($gan)] : null;
    
    //echo "gmd_CI_Date=",print_r($citation->gmd_date->gmd_CI_Date),"\n";
      
    if (!($date = (string)$citation->gmd_date->gmd_CI_Date->gmd_date->gco_Date))
      $date = (string)$citation->gmd_date->gmd_CI_Date->gmd_date->gco_DateTime;
    $md['dateMD'] = [
      'type'=> (string)$citation->gmd_date->gmd_CI_Date->gmd_dateType->gmd_CI_DateTypeCode['codeListValue'], 
      'value'=> $date,
    ];
    
    return $md;
  }
  
  /** retoune les MD simplifiées extraites d'une archive 7z.
   * 
   * SI $mdName n'est pas défini et le fichier XML standard existe ALORS
   *   retourne les MD ISO à partir du nom d'entrée standard des MD // MD d'une carte normale
   * SINON_SI $mdName est défini ALORS
   *   retourne les MD ISO à partir du fichier $mdName // cas où je veux les MD d'un cartouche d'une carte normale
   * SINON // carte spéciale
   *   S''il existe un et un seul fichier XML ALORS
   *     retourne les MD ISO à partir de ce fichier XML // cas d'une carte spéciale ayant des MD XML
   *   SINON // MD limitées construites avec un .tif/.pdf présent dans l'archive et servant de version
   *     S'il existe un seul .tif ou pas de .tif et un seul .pdf ALORS
   *       retourne MD dégradées carte spéciale à partir du .tif ou du .pdf
   *     SINON
   *       retourne [] // ERREUR
   * @return array<string, string>
   */
  static function getFrom7z(string $pathOf7z, ?string $mdName=null): array {
    //echo "MapMetadata::getFrom7z(pathOf7z=$pathOf7z, mdName=$mdName)<br>\n";
    if (!is_file($pathOf7z)) {
      throw new \Exception("Archive $pathOf7z inexistante");
    }
    $archive = new My7zArchive($pathOf7z);
    $dateArchive = null;
    if (!$mdName) { // Si $mdName n'est pas défini, je cherche dans l'archive les MD du fichier principal
      foreach ($archive as $entry) {
        if (preg_match('!^\d{4}/CARTO_GEOTIFF_\d{4}_pal300\.xml$!', $entry['Name'])) { // CARTO_GEOTIFF_7107_pal300.xml
          $mdName = $entry['Name'];
        }
        elseif (preg_match('!^\d{4}/\d{4}_pal300\.tif$!', $entry['Name'])) {
          $dateArchive = substr($entry['DateTime'], 0, 10);
        }
      }
    }
    
    if (!$mdName) { // carte spéciale avec fichier XML
      // Je cherche s'il existe un et un seul fichier XML dans le répertoire {mapNum}
      $mdNames = [];
      foreach ($archive as $entry) {
        if (preg_match('!^\d{4}/[^/]*\.xml$!', $entry['Name'])) {
          $mdNames[] = $entry['Name'];
        }
      }
      if (count($mdNames) == 1)
        $mdName = $mdNames[0];
    }
    
    if ($mdName) { // Si $mdName est défini alors j'extraie le fichier de l'archive puis j'extraie les MD du fichier 
      $mdPath = $archive->extract($mdName);
      $md = self::extractFromIso19139($mdPath);
      $archive->remove($mdPath);
      //echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
      
      // Ici j'ai récupéré $md qd il y a un fichier MD ISO
      if (!$dateArchive) { // Je cherche une dateArchive
        $entriesPerExt = ['tif'=>[], 'pdf'=>[]];
        foreach ($archive as $entry) { // recherche des .tif et des .pdf
          //print_r($entry);
          if (in_array(substr($entry['Name'], -4), ['.tif','.pdf'])) {
            $entriesPerExt[substr($entry['Name'], -3)][] = $entry;
          }
        }
        //echo '$entriesPerExt = '; print_r($entriesPerExt);
        if (count($entriesPerExt['tif']) > 0) { // Il existe au moins 1 .tif
          $dateArchive = substr($entriesPerExt['tif'][0]['DateTime'], 0, 10); // je prends le premier
        }
        elseif ((count($entriesPerExt['pdf']) > 0) && (count($entriesPerExt['pdf']) == 1)) { // Il existe au moins un .pdf
          $dateArchive = substr($entriesPerExt['pdf'][0]['DateTime'], 0, 10); // je prends le premier
        }
      }
      if (!$dateArchive)
        throw new \Exception("Cas non prévu dans MapMetadata::getFromArchive()");
      return array_merge($md, ['dateArchive'=> $dateArchive]);
    }
    
    
    // génération de MD limitées pour une carte spéciale n'ayant pas de MD ISO
    // il doit alors exister un seul .tif ou pas de .tif et un seul .pdf
    $entriesPerExt = ['tif'=>[], 'pdf'=>[]];
    foreach ($archive as $entry) { // recherche des .tif et des .pdf
      //print_r($entry);
      if (in_array(substr($entry['Name'], -4), ['.tif','.pdf'])) {
        $entriesPerExt[substr($entry['Name'], -3)][] = $entry;
      }
    }
    //echo '<pre>$entriesPerExt = '; print_r($entriesPerExt);
    foreach (['tif','pdf'] as $ext) {
      if (count($entriesPerExt[$ext]) == 1) { // Il existe un et un seul .{ext}
        $fbn = basename($entriesPerExt[$ext][0]['Name'], ".$ext");
        return [
          'version'=> substr($fbn, 5), // l'année dans le nom
          'dateArchive'=> substr($entriesPerExt[$ext][0]['DateTime'], 0, 10),
        ];
      }
    }
    
    // cas d'erreur
    return [];
  }
  
  static function test(string $PF_PATH): void { // Test de la classe 
    define ('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (0) { // @phpstan-ignore-line // Test sur une carte normale 
      $md = self::getFrom7z("$PF_PATH/current/7107.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (0) { // @phpstan-ignore-line // Test sur les anciennes cartes spéciales 
      foreach (['7330','7344','7360','8101','8502','8509','8510','8517','8523'] as $mapNum)
        echo "getMapVersionFrom7z($mapNum)-> ",json_encode(self::getFrom7z("$PF_PATH/current/$mapNum.7z"), JSON_OPTIONS),"\n";
    }
    elseif (0) { // @phpstan-ignore-line // Test sur la nouvelle carte spéciale 7330 
      echo "getMapVersionFrom7z(7330)-> ",
        json_encode(self::getFrom7z("$PF_PATH/incoming/20230628aem/7330.7z"), JSON_OPTIONS),"\n";
    }
    elseif (0) { // @phpstan-ignore-line // Test sur les nouvelles cartes spéciales 7344 et 7360
      foreach (['7344','7360'] as $mapNum)
        echo "getMapVersionFrom7z($mapNum)-> ",
          json_encode(self::getFrom7z("$PF_PATH/doublons/20230626/$mapNum.7z"), JSON_OPTIONS),"\n";
    }
    elseif (0) { // @phpstan-ignore-line // Test sur les nouvelles cartes spéciales 
      foreach (['8502','8509','8510','8517','8523'] as $mapNum)
        echo "getMapVersionFrom7z($mapNum)-> ",
          json_encode(self::getFrom7z("$PF_PATH/attente/20230628aem/$mapNum.7z"), JSON_OPTIONS),"\n";
    }
    elseif (0) { // @phpstan-ignore-line // test de toutes les cartes de current 
      foreach (new \DirectoryIterator("$PF_PATH/current") as $entry) {
        if (substr($entry, -3) <> '.7z') continue;
        $md = self::getFrom7z("$PF_PATH/current/$entry");
        echo Yaml::dump(["getMapVersionFrom7z($entry)"=> $md]);
      }
    }
    elseif (1) { // Test de ttes les cartes de archives
      foreach (new \DirectoryIterator("$PF_PATH/archives") as $archive) {
        if (in_array($archive, ['.','..','.DS_Store'])) continue;
        foreach (new \DirectoryIterator("$PF_PATH/archives/$archive") as $entry) {
          if (substr($entry, -3) <> '.7z') continue;
          $md = self::getFrom7z("$PF_PATH/archives/$archive/$entry");
          echo Yaml::dump(["getMapVersionFrom7z($archive/$entry)"=> $md]);
        }
      }
    }
  }
};

if ((php_sapi_name() == 'cli') && ($argv[0]=='mapmetadata.inc.php')) {
  //echo "argc=$argc\n"; die();
  if ($argc == 1) {
    echo "usage: php $argv[0] ({fichier7z} [{mdName}])|(TEST)\n";
    die();
  }
  elseif ($argv[1] == 'TEST') { // Test sur certaines cartes 
    if (!($PF_PATH = getenv('SHOMGT3_PORTFOLIO_PATH')))
      throw new \Exception("Variables d'env. SHOMGT3_PORTFOLIO_PATH non définie");
    $PF_PATH = '/var/www/html/shomgeotiff';
    MapMetadata::test($PF_PATH);
    die();
  }
  elseif ($argc == 2) {
    try {
      echo Yaml::dump(MapMetadata::getFrom7z($argv[1]));
    }
    catch (\Exception $e) {
      die("Erreur: ".$e->getMessage()."\n");
    }
    die();
  }
  elseif ($argc == 3) {
    echo Yaml::dump(MapMetadata::getFrom7z($argv[1], $argv[2]));
    die();
  }
}
