<?php
/*PhpDoc:
name: updtslave.php
title: updtslave.php - installe dans le portefeuille de shomgt une livraison issue du maitre
doc: |
  script à appeler en ligne de commande
  si le catalogue du fil est plus récent que celui stocké, le télécharge
  puis télécharge les cartes zippées plus récentes que les éventuelles cartes existantes
  puis appelle updt.php
  et enfin efface les éventuelles cartes périmées
journal: |
  3/1/2021:
    création
*/
require_once __DIR__.'/../lib/xmltoarrayparser.inc.php';
require_once __DIR__.'/mdiso19139.inc.php';

header('Content-type: text/plain; charset="utf8"');

$atomfeedUrl = "http://localhost/geoapi/shomgt/master/atomfeed.php";

// Définit le fuseau horaire par défaut à utiliser.
date_default_timezone_set('UTC');

// classe regroupant les infos de mise à jour 
class UpdtSlave {
  public array $catalog=[]; // [href, updated]
  public array $todelete=[]; // [mapid => title]
  public array $todadd=[]; // [mapid => [href, updated]]

  function __construct(string $url) {
    $xml = file_get_contents($url);

    //var_dump($xml);
    //echo "$xml\n"; die();
    $domObj = new xmlToArrayParser($xml);
    $atomfeed = $domObj->array;

    if($domObj->parse_error)
      die($domObj->get_xml_error());

    foreach ($atomfeed['feed']['entry'] as $entry) {
      if (isset($entry['link']['attrib'])) { // 1 seul lien => suppression
        //echo '$entry='; print_r($entry);
        $mapid = substr($entry['link']['attrib']['href'], -6);
        $this->todelete[$mapid] = $entry['title'];
        //echo "$entry[title]\n";
      }
      else {
        foreach ($entry['link'] as $link) {
          //print_r($link);
          if ($link['attrib']['type'] == 'text/vnd.yaml') {
            //echo "Catalogue ",$link['attrib']['href'],"\n";
            $this->catalog = [
              'href'=> $link['attrib']['href'],
              'updated'=> $entry['updated'],
            ];
          }
          elseif ($link['attrib']['type'] == 'application/x-7z-compressed') {
            //echo '$entry='; print_r($entry);
            $mapid = substr($entry['id'], -6);
            //echo "Ajout ",$link['attrib']['href'],"\n";
            $this->toadd[$mapid] = [
              'href'=> $link['attrib']['href'],
              'updated'=> $entry['updated'],
            ];
          }
        }
      }
    }
  }
  
  // retrouve les MD ISO de la carte dans le portefeuille existant
  static function mdiso19139ById(string $mapid): array {
    $num = substr($mapid, 2);
    if ($mdiso19139 = mdiso19139("$num/${num}_pal300"))
      return $mdiso19139;
    elseif ($mdiso19139 = mdiso19139("$num/${num}_1_gtw"))
      return $mdiso19139;
    elseif ($mdiso19139 = mdiso19139("$num/${num}_A_gtw"))
      return $mdiso19139;
    else
      return [];
  }
  
  // indique si la carte doit être mise à jour
  function updateMap(string $mapid): bool {
    $mdiso19139 = self::mdiso19139ById($mapid); // les MD ISO de current ou []
    //print_r($mdiso19139);
    $mdDate = $mdiso19139 ? substr($mdiso19139['mdDate'], 0, 10) : null; // la date des MD ou null
    $updated = substr($this->toadd[$mapid]['updated'], 0, 10); // la partie date
    return (!$mdDate || ($updated > $mdDate));
  }
  
};


/*function dwnld(string $from, string $to): void {
  $hfrom = fopen($from, 'r');
  $hto = fopen($to, 'w');
  while ($buff = fread($hfrom, 1024)) {
    fwrite($hto, $buff);
  }
}*/

$updtSlave = new UpdtSlave($atomfeedUrl);
//print_r($atomfeed);

if (!file_exists(__DIR__.'/../cat2/mapcat.yaml')
  || ($updtSlave->catalog['updated'] > date('Y-m-d\TH:i:s\Z', filemtime(__DIR__.'/../cat2/mapcat.yaml')))) {
  echo "echo 'Mise à jour du catalogue'\n";
}

$shomgeotiff = __DIR__.'/../../../shomgeotiff';
if (!file_exists("$shomgeotiff/incoming"))
  mkdir("$shomgeotiff/incoming");
if (!file_exists("$shomgeotiff/incoming/slave"))
  mkdir("$shomgeotiff/incoming/slave");
if (!file_exists("$shomgeotiff/current"))
  mkdir("$shomgeotiff/current");
foreach ($updtSlave->toadd as $mapid => $newMap) {
  if ($updtSlave->updateMap($mapid)) {
    echo "echo 'Mise à jour de la carte $mapid'\n";
    $mapnum = substr($mapid, 2);
    echo "wget $newMap[href] -O $shomgeotiff/incoming/slave/$mapnum.7z\n";
  }
  else {
    echo "echo 'La carte $mapid est à jour'\n";
  }
}