<?php
// bo/mapmetadata.inc.php - déf. de la classe MapMetadata et de la fonction ganWeek2iso()

require_once __DIR__.'/../sgserver/SevenZipArchive.php';

function ganWeek2iso(string $ganWeek): string { // traduit une semaine GAN en date ISO 
  $date = new DateTimeImmutable();
  // public DateTimeImmutable::setISODate(int $year, int $week, int $dayOfWeek = 1): DateTimeImmutable
  $newDate = $date->setISODate((int)('20'.substr($ganWeek,0,2)), (int)substr($ganWeek, 2), 3);
  return $newDate->format('Y-m-d');
}

class MapMetadata { // construit les MD d'une carte à partir des MD ISO dans le 7z 
  // La date de revision n'est pas celle de la correction mais ed l'édition de la carte
  /** @return array<string, string> */
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

  /** @return array<string, string> */
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
    if (0) { // @phpstan-ignore-line // Test sur une carte 
      $md = self::getFrom7z("$PF_PATH/current/7107.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (1) { // @phpstan-ignore-line // Test d'une carte spéciale 
      $md = self::getFrom7z("$PF_PATH/current/7330.7z");
      echo "getMapVersionFrom7z()-> ",json_encode($md, JSON_OPTIONS),"\n";
    }
    elseif (0) { // @phpstan-ignore-line // test de toutes les cartes de current 
      foreach (new DirectoryIterator("$PF_PATH/current") as $entry) {
        if (substr($entry, -3) <> '.7z') continue;
        $md = self::getFrom7z("$PF_PATH/current/$entry");
        echo "getMapVersionFrom7z($entry)-> ",json_encode($md, JSON_OPTIONS),"\n";
      }
    }
    elseif (0) { // @phpstan-ignore-line // Test de ttes les cartes de archives
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
