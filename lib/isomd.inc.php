<?php
/** Récupération de MD ISO d'un GéoTiff
 *
 * journal:
 * - 22/5/2022:
 *   - utilisation EnVar
 * - 3/5/2022:
 *   - correction bug
 *   - changement du séparateur des milliers en '_' car 1) moins confusant que '.' et 2) utilisé par Php et Yaml
 *   - utilisation de la variable d'environnement SHOMGT3_MAPS_DIR_PATH
 * @package shomgt\lib
 */
require_once __DIR__.'/envvar.inc.php';
require_once __DIR__.'/addusforthousand.inc.php';

/** Récupération de MD ISO d'un GéoTiff
 *
 * Récupère dans le fichier XML ISO 19139 d'un GéoTiff les 4 champs de métadonnées suivants:
 *   mdDate: date de modification des métadonnées
 *   edition: édition de la carte, example: Edition n° 3 - 2016
 *   lastUpdate: entier indiquant la dernière correction prise en compte dans la carte
 *   scaleDenominator: dénominateur de l'échelle avec '_' comme séparateur des milliers, example: 50_300
 */
class IsoMd {
  const ErrorFileNotFound = 'IsoMd::ErrorFileNotFound';
  const NoMatchForMdDate = 'IsoMd::NoMatchForMdDate';
  const NoMatchForEdition = 'IsoMd::NoMatchForEdition';
  
  /** retourne un dict. des informations
   * @return array<string, string> */
  static function read(string $gtname): array {
    $mapNum = substr($gtname, 0, 4);
    if (!($xmlmd = @file_get_contents(EnvVar::val('SHOMGT3_MAPS_DIR_PATH')."/$mapNum/CARTO_GEOTIFF_$gtname.xml")))
      throw new SExcept("Fichier de MD non trouvé pour gtname=$gtname", self::ErrorFileNotFound);
    
    $pattern = '!<gmd:dateStamp>\s*<gco:DateTime[^>]*>([^<]*)</gco:DateTime>\s*</gmd:dateStamp>!';
    if (!preg_match($pattern, $xmlmd, $matches)) {
      echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
      throw new SExcept("dateStamp non trouvé pour $gtname", self::NoMatchForMdDate);
    }
    $md['mdDate'] = $matches[1];
    
    $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
    if (!preg_match($pattern, $xmlmd, $matches)) {
      echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
      throw new SExcept("edition non trouvé pour $gtname", self::NoMatchForEdition);
    }
    $md['edition'] = $matches[1];
    $md['lastUpdate'] = '';
    
    // ex: Edition n° 4 - 2015 - Dernière correction : 12
    if (preg_match('!^(.* - \d+) - [^\d]*(\d+)!', $md['edition'], $matches)) { 
      $md['edition'] = $matches[1];
      $md['lastUpdate'] = $matches[2];
    }
    // ex: Publication 1984 - Dernière correction : 101
    elseif (preg_match('!^(.* \d+) - [^\d]*(\d+)!', $md['edition'], $matches)) {
      $md['edition'] = $matches[1];
      $md['lastUpdate'] = $matches[2];
    }
    
    $pattern = '!<gmd:equivalentScale>\s*<gmd:MD_RepresentativeFraction>\s*<gmd:denominator>\s*<gco:Integer>(\d*)'
      .'</gco:Integer>\s*</gmd:denominator>\s*</gmd:MD_RepresentativeFraction>\s*</gmd:equivalentScale>!';
    if (!preg_match($pattern, $xmlmd, $matches)) {
      echo "<b>CARTO_GEOTIFF_$gtname.xml</b><br>",str_replace(['<'],['{'],$xmlmd);
      throw new SExcept("edition non trouvé pour $gtname", self::NoMatchForEdition);
    }
    //echo "$matches[1]<br>\n";
    $md['scaleDenominator'] = addUndescoreForThousand(intval($matches[1]));
    
    return $md;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire

print_r(IsoMd::read('6822_pal300'));