<?php
/*PhpDoc:
name: histo.php
title: cat2 / histo.php - listing de l'historique des cartes dans incoming
functions:
classes:
doc: |
includes: [../lib/SevenZipArchive.php]
*/

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/SevenZipArchive.php';

use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('Europe/Paris');

function readfiles(string $dir, bool $recursive=false) { // lecture du nom, du type et de la date de modif des fichiers d'un rép.
  /*PhpDoc: functions
  name: readfiles
  title: function readfiles($dir, $recursive=false) - Lecture des fichiers locaux du répertoire $dir
  doc: |
    Le système d'exploitation utilise ISO 8859-1, toutes les données sont gérées en UTF-8
    Si recursive est true alors renvoie l'arbre
  */
  if (!$dh = opendir(utf8_decode($dir)))
    die("Ouverture de $dir impossible");
  $files = [];
  while (($filename = readdir($dh)) !== false) {
    if (in_array($filename, ['.','..']))
      continue;
    $filetype = filetype(utf8_decode($dir).'/'.$filename);
    $file = [
      'name'=>utf8_encode($filename),
      'type'=>$filetype, 
      'mdate'=>date ("Y-m-d H:i:s", filemtime(utf8_decode($dir).'/'.$filename))
    ];
    if (($filetype=='dir') && $recursive)
      $file['content'] = readfiles($dir.'/'.utf8_encode($filename), $recursive);
    $files[$file['name']] = $file;
  }
  closedir($dh);
  return $files;
}

/*PhpDoc: classes
name: CarteZip
title: "class CarteZip extends SevenZipArchive - une carte Shom zippée"
methods:
doc: |
*/
class CarteZip extends SevenZipArchive {
  /*PhpDoc: methods
  name: mdiso19139
  title: "function mdiso19139(string $gtname): array - récupère des éléments des MD ISO19139 du GéoTIFF"
  doc: |
    Prend en paramètre $gtname est la clé du géotiff dans shomgt.yaml
    Retourne un array ayant comme propriétés
      - mdDate - date de mise à jour des métadonnées
      - édition - édition de la carte, ex: Edition n° 4 - 2015, Publication 1984
      - dernièreCorrection - dernière correction indiquée dans les MD , un entier transmis comme string
    retourne [] si le fichier est absent
  */
  function mdiso19139(): array {
    $filepath = null;
    foreach ($this as $entry) {
      //print_r($entry);
      if (preg_match('!\.xml$!', $entry['Name'])) {
        $filepath = $entry['Name'];
        break;
      }
    }
    if (!$filepath)
      throw new Exception("Erreur, aucun fichier xml trouvé dans $filepath");
    $this->extractTo('.', $filepath);
    $xmlmd = file_get_contents($filepath);
    
    $pattern = '!<gmd:dateStamp>\s*<gco:DateTime[^>]*>([^<]*)</gco:DateTime>\s*</gmd:dateStamp>!';
    if (!preg_match($pattern, $xmlmd, $matches))
      throw new Exception("Erreur, mdDate non trouvée dans $filepath");
    $md['mdDate'] = $matches[1];

    $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
    if (!preg_match($pattern, $xmlmd, $matches))
      throw new Exception("Erreur, edition non trouvée dans $filepath");
    $edition = $matches[1];
    $md += ['edition'=> $edition];
  
    //echo "filepath=$filepath\n";
    unlink($filepath);
    rmdir(dirname($filepath));
    return $md;
  }
};

$incoming = realpath(__DIR__.'/../../../shomgeotiff/incoming');

echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>histo</title></head><body><pre>\n";
//echo Yaml::dump(readfiles($incoming));
$histo = []; // [mapid => [mddate => ['title'=> title, 'editionEtCorrection'=> editionEtCorrection, 'chemin'=> chemin]]]

foreach (readfiles($incoming) as $livr) { // $livr correspond à une livraison
  if (in_array($livr['name'], ['.DS_Store', '201707cartesAEM','201911cartesAEM'])) continue;
  foreach (readfiles("$incoming/$livr[name]") as $mapz) { // $mapz correspond à une carte zippée
    if (in_array($mapz['name'], [''])) continue;
    if (preg_match('!^(\d+).7z$!', $mapz['name'], $matches)) {
      $mapnum = $matches[1];
      
      $carteZip = new CarteZip("$incoming/$livr[name]/$mapz[name]");
      
      // Test integrity of archive:
      //print "Archive $incoming/$dir0[name]/$mapz[name] is ". ($archive->test() ? 'OK' : 'broken') . "\n";

      /*	# Show number of contained files:
      *	print $archive->count() . " file(s) in archive\n";
      *
      *	# Show info about the first contained file:
      *	$entry = $archive->get(0);
      *	print 'First file name: ' . $entry['Name'] . "\n";
      */
      // Iterate over all the contained files in archive, and dump all their info:
      /*foreach ($archive as $entry) {
        print_r($entry);
      }*/
      /*
      *	# Extract a single contained file by name into the current directory:
      *	$archive->extractTo('.', 'test.txt');
      *
      *	# Extract all contained files:
      *	$archive->extractTo('.');
      */
      $mdiso19139 = $carteZip->mdiso19139();
      $histo["FR$mapnum"][$mdiso19139['mdDate']] = [
        'edition'=> $mdiso19139['edition'],
        'chemin' => "incoming/$livr[name]/$mapz[name]",
      ];
      ksort($histo["FR$mapnum"]);
    }
  }
}
ksort($histo);
echo "title: historique des cartes détenues dans incoming classé par carte et par date des MD\n";
echo Yaml::dump(['cartes'=> $histo], 5, 2);
