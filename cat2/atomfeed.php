<?php
/*PhpDoc:
name: atomfeed.php
title: cat2 / atomfeed.php - flux Atom de l'historique des cartes
functions:
classes:
doc: |
  Chaque entrée est soit l'ajout d'une nouvelle version de carte soit la suppression d'une carte.
  Dans le premier cas un <link> ayant type="application/x-7z-compressed" donne l'url de téléchargement
  Dans le second cas ce lien n'existe pas.
  L'id de la carte peut être trouvé dans le lien text/html.

  Le fichier atomfeed.pser doit être effacé lorsque l'historique change.

  Lisible sous Feedbro.
  Feed inutilisable pour un humain mais peut être utilisé par un client pour identifier les cartes à télécharger.
  A faire:
    - voir le contrôle d'accès !!!
journal: |
  1/1/2021:
    - limitation aux cartes courantes, ajout des cartes effacées, création d'un pser
  31/12/2020:
    - création - version lisible avec Feedbro
includes: [../lib/genatom.inc.php]
*/
//require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/genatom.inc.php';

//use Symfony\Component\Yaml\Yaml;

if (!is_file(__DIR__.'/atomfeed.pser')) {
  if (!($histo = @file_get_contents(__DIR__.'/histo.pser')))
    die("Erreur fichier histo.pser inexistant");
  $histo = unserialize($histo);

  // construction de la liste des cartes concernées indexée par les id des cartes
  $histo2 = []; // [date => [mapid => ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin] | "Suppression de la carte"]]]
  foreach ($histo as $mapid => $histoMapid) {
    $mapnum = substr($mapid, 2);
    $nb = count($histoMapid);
    if (array_values($histoMapid)[$nb-1] == 'Suppression de la carte') {
      $mdDate = array_keys($histoMapid)[$nb-1].'T12:00:00Z';
      $histo2[$mdDate][$mapid] = 'Suppression de la carte';
    }
    elseif (file_exists(__DIR__."/../../../shomgeotiff/current/$mapnum")) {
      $mdDate = array_keys($histoMapid)[$nb-1];
      $mdDate = str_replace(['+01:00','+02:00'], 'T12:00:00Z', $mdDate);
      $histo2[$mdDate][$mapid] = array_values($histoMapid)[$nb-1];
    }
    else {
      //echo __DIR__."/../../../shomgeotiff/current/$mapnum absent\n";
    }
  }
  krsort($histo2);
  file_put_contents(__DIR__.'/atomfeed.pser', serialize($histo2));
}
else {
  $histo2 = unserialize(@file_get_contents(__DIR__.'/atomfeed.pser'));
}

//echo "<pre>"; print_r($histo2); die();
//echo "<pre>"; print_r($_SERVER); die();
$request_scheme = $_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
$script_path = "$request_scheme://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

if (!isset($_SERVER['PATH_INFO'])) { // le feed
  $feed = [
    'title'=> "Historique des livraisons des cartes GéoTiff Shom ",
    'author'=> ['name'=> "Benoit DAVID", 'email'=> 'contact@geoapi.fr'],
    'uri'=> $script_path,
    'links'=> [],
  ];

  $entries = [];
  foreach ($histo2 as $mdDate => $maps) {
    $updated = str_replace(['+01:00','+02:00'], 'T12:00:00Z', $mdDate);
    foreach ($maps as $mapid => $map) {
      if ($map == 'Suppression de la carte') {
        $entries[] = [
          'title'=> "Suppression $mapid",
          'uri'=> "$script_path/$mdDate/$mapid",
          'updated'=> $updated,
          'links'=> [
            [ 'href'=> "$script_path/entry/$mdDate/$mapid", 'rel'=> 'alternate', 'type'=>'text/html' ],
          ],
          'categories'=> [],
        ];
      }
      else {
        $entries[] = [
          'title'=> "Ajout $mapid, $map[edition], dernière correction $map[lastUpdate]",
          'uri'=> "$script_path/$mdDate/$mapid",
          'updated'=> $updated,
          'links'=> [
            [ 'href'=> "$script_path/entry/$mdDate/$mapid", 'rel'=> 'alternate', 'type'=>'text/html' ],
            [ 'href'=> "$script_path/dwnld/$map[path]", 'rel'=> 'alternate', 'type'=>'application/x-7z-compressed' ],
          ],
          'categories'=> [],
        ];
      }
    }
  }
  gen_atom_feed($feed, $entries);
  die();
}
elseif (preg_match('!^/entry/([^/]+)/([^/]+)$!', $_SERVER['PATH_INFO'], $matches)) { // affichage de l'entrée
  $mdDate = $matches[1];
  $mapid = $matches[2];
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>histo</title></head><body>\n";
  echo "<table border=1>\n";
  echo "<tr><td><i>Id carte</i></td><td>$mapid</td></tr>\n";
  $map = $histo2[$mdDate][$mapid];
  if (is_array($map)) {
    echo "<tr><td><i>Date de la carte</i></td><td>$mdDate</td></tr>\n";
    echo "<tr><td><i>édition</i></td><td>$map[edition], dernière correction $map[lastUpdate]</td></tr>\n";
    $url = "$script_path/dwnld/$map[path]";
    echo "<tr><td><i>téléchargement</i></td><td><a href='$url'>$url</a></td></tr>\n";
  }
  else
    echo "<tr><td><i>Date de suppression</i></td><td>$mdDate</td></tr>\n";
  echo "</table>\n";
}
elseif (preg_match('!^/dwnld/incoming/(.*)$!', $_SERVER['PATH_INFO'], $matches)) { // téléchargement
  $filepath = __DIR__."/../../../shomgeotiff/incoming/$matches[1]";
  header('Content-type: application/x-7z-compressed');
  readfile($filepath);
  die();
}
else {
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>histo</title></head><body><pre>\n";
  echo "Erreur, no match pour $_SERVER[PATH_INFO]\n";
}