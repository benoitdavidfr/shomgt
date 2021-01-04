<?php
/*PhpDoc:
name: histo.php
title: master / histo.php - listing de l'historique des cartes dans incoming
functions:
classes:
doc: |
  Fabrique l'historique des cartes livrées sous la forme
    [mapid => [mdDate => ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin] | "Suppression de la carte"]]
  Affichage Yaml et enregistrement dans histo.pser
  Si histo.pser existe alors il est affiché.
journal: |
  31/12/2020:
    - refonte
includes: [../lib/SevenZipArchive.php]
*/

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/SevenZipArchive.php';

use Symfony\Component\Yaml\Yaml;

// noms de répertoires de incoming à exclure de l'historique
define('EXCLUDED_DELIVNAMES',
  ['.','..','.DS_Store', '201707cartesAEM','201911cartesAEM','cartesAEM','20201226TEST-arriere','20201226TEST-avant']
);

date_default_timezone_set('Europe/Paris');

/*PhpDoc: classes
name: SevenZipMap
title: "class SevenZipMap extends SevenZipArchive - une carte Shom zippée"
methods:
doc: |
*/
class SevenZipMap extends SevenZipArchive {
  /*PhpDoc: methods
  name: mdiso19139
  title: "function mdiso19139(string $gtname): array - récupère des éléments des MD ISO19139 du GéoTIFF"
  doc: |
    Prend en paramètre $gtname est la clé du géotiff dans shomgt.yaml
    Retourne un array ayant comme propriétés
      - dateStamp - date de mise à jour des métadonnées
      - edition - édition de la carte, ex 'Edition n° 4 - 2015', 'Publication 1984'
      - lastUpdate - dernière corection sous la forme d'un entier
    retourne une exception si le fichier est absent
  */
  const INCOMING = __DIR__.'/../../../shomgeotiff/incoming';
  
  function mdiso19139(): array {
    $filepath = null;
    foreach ($this as $entry) { // cherche une entrée ayant comme .xml comme suffixe
      //print_r($entry);
      if (preg_match('!\.xml$!', $entry['Name'])) {
        $filepath = $entry['Name'];
        break;
      }
    }
    if (!$filepath)
      throw new Exception("Erreur, aucun fichier xml trouvé dans CarteZip::mdiso19139()");
    $this->extractTo('.', $filepath);
    $xmlmd = file_get_contents($filepath);
    
    $pattern = '!<gmd:dateStamp>\s*<gco:DateTime[^>]*>([^<]*)</gco:DateTime>\s*</gmd:dateStamp>!';
    if (!preg_match($pattern, $xmlmd, $matches))
      throw new Exception("Erreur, dateStamp non trouvée dans CarteZip::mdiso19139()");
    $md['dateStamp'] = $matches[1];

    $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
    if (!preg_match($pattern, $xmlmd, $matches))
      throw new Exception("Erreur, edition non trouvée dans CarteZip::mdiso19139()");
    $edition = $matches[1];
    if (preg_match('!^(.* - \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Edition n° 4 - 2015 - Dernière correction : 12
      $md += ['edition'=> $matches[1], 'lastUpdate'=> intval($matches[2])];
    elseif (preg_match('!^(.* \d+) - [^\d]*(\d+)!', $edition, $matches)) // ex: Publication 1984 - Dernière correction : 101
      $md += ['edition'=> $matches[1], 'lastUpdate'=> intval($matches[2])];
    else
      $md += ['edition'=> $edition];
  
    //echo "filepath=$filepath\n";
    unlink($filepath);
    rmdir(dirname($filepath));
    return $md;
  }
};

echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>histo</title></head><body><pre>\n";
if (!($histo = @file_get_contents(__DIR__.'/histo.pser'))) {
  $histo = []; // [mapid => [mdDate => ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin] | "Suppression de la carte"]]
  foreach (new DirectoryIterator(SevenZipMap::INCOMING) as $delivFileInfo) { // $delivFileInfo correspond à une livraison
    $delivName = $delivFileInfo->getFilename();
    if (in_array($delivName, EXCLUDED_DELIVNAMES))
      continue;
    echo "$delivName<br>\n";
    foreach (new DirectoryIterator(SevenZipMap::INCOMING."/$delivName") as $mapzFileInfo) { // $mapzFileInfo -> une carte zippée
      $mapzName = $mapzFileInfo->getFilename();
      if (!preg_match('!^(\d+).7z$!', $mapzName, $matches))
        continue;
      $mapnum = $matches[1];
      $mapz = new SevenZipMap(SevenZipMap::INCOMING."/$delivName/$mapzName");
      $mdiso19139 = $mapz->mdiso19139();
      $histo["FR$mapnum"][$mdiso19139['dateStamp']] = [
        'edition'=> $mdiso19139['edition'],
        'lastUpdate'=> $mdiso19139['lastUpdate'],
        'path' => "incoming/$delivName/$mapzName",
      ];
      ksort($histo["FR$mapnum"]);
    }
    if (is_file(SevenZipMap::INCOMING."/$delivName/index.yaml")) {
      $yaml = Yaml::parseFile(SevenZipMap::INCOMING."/$delivName/index.yaml");
      if (isset($yaml['toDelete'])) {
        foreach (array_keys($yaml['toDelete']) as $mapToDelete) {
          $update = substr($delivName, 0, 4).'-'.substr($delivName, 4, 2).'-'.substr($delivName, 6);
          $histo[$mapToDelete][$update] = "Suppression de la carte";
        }
      }
    }
  }
  ksort($histo);
  file_put_contents(__DIR__.'/histo.pser', serialize($histo));
}
else {
  $histo = unserialize($histo);
}
echo "title: historique des cartes détenues dans incoming classées par carte et par date des MD\n";
echo Yaml::dump(['histo'=> $histo], 5, 2);
