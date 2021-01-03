<?php
/*PhpDoc:
name: atomfeed.php
title: master / atomfeed.php - flux Atom de l'historique des cartes
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
  3/1/2021:
    - ajout du téléchargement du catalogue des cartes
  1/1/2021:
    - limitation aux cartes courantes, ajout des cartes effacées, création d'un pser
  31/12/2020:
    - création - version lisible avec Feedbro
includes: [../lib/genatom.inc.php]
*/
//require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/genatom.inc.php';

//use Symfony\Component\Yaml\Yaml;

define('TEST', 'version test incoming/20170613');
// Définit le fuseau horaire par défaut à utiliser. Disponible depuis PHP 5.1
date_default_timezone_set('UTC');

if (!is_file(__DIR__.'/atomfeed.pser')) {
  if (!($histo = @file_get_contents(__DIR__.'/histo.pser')))
    die("Erreur fichier histo.pser inexistant");
  $histo = unserialize($histo);

  // construction de la liste des cartes concernées indexée par les id des cartes
  $histo2 = []; // [date => [mapid => ['edition'=> edition, 'lastUpdate'=> lastUpdate, 'path'=> chemin] | "Suppression de la carte"]]]
  if (TEST == 'version test incoming/20170613') { // version test incoming/20170613/
    foreach ($histo as $mapid => $histoMapid) {
      foreach ($histoMapid as $mdDate => $map) {
        if (is_array($map) && preg_match('!^incoming/20170613/!', $map['path']))
          $histo2[$mdDate][$mapid] = $map;
      }
    }
  }
  else {
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
    }
  }
  krsort($histo2);
  //file_put_contents(__DIR__.'/atomfeed.pser', serialize($histo2));
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
    'title'=> "Liste des cartes GéoTiff du Shom à jour et des cartes obsolètes".(TEST ? ' ('.TEST.')' : ''),
    'author'=> ['name'=> "Benoit DAVID", 'email'=> 'contact@geoapi.fr'],
    'uri'=> $script_path,
    'links'=> [],
  ];

  $entries = [[
    'title'=> "Catalogue des cartes",
    'uri'=> "$script_path/mapCatalog",
    'updated'=> date('Y-m-d\TH:i:s\Z', filemtime(__DIR__.'/../cat2/mapcat.yaml')),
    'links'=> [
      ['href'=> "$script_path/entry/mapcat.yaml", 'rel'=> 'alternate', 'type'=>'text/html' ],
      ['href'=> "$script_path/dwnld/mapcat.yaml", 'rel'=> 'alternate', 'type'=>'text/vnd.yaml' ],
    ]
  ]];
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
elseif ($_SERVER['PATH_INFO'] == '/entry/mapcat.yaml') { // affichage de l'entrée mapCatalog
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>atom</title></head><body>\n";
  echo "<table border=1>\n";
  echo "<tr><td><i>Nom</i></td><td>catalogue</td></tr>\n";
  echo "<tr><td><i>Date de mise à jour</i></td><td>",
    date('Y-m-d\TH:i:s\Z', filemtime(__DIR__.'/../cat2/mapcat.yaml')),"</td></tr>\n";
  $url = "$script_path/dwnld/mapcat.yaml";
  echo "<tr><td><i>téléchargement</i></td><td><a href='$url'>$url</a></td></tr>\n";
  echo "</table>\n";
}
elseif ($_SERVER['PATH_INFO'] == '/dwnld/mapcat.yaml') { // téléchargement mapCatalog
  $filepath = __DIR__.'/../cat2/mapcat.yaml';
  header('Content-type: text/vnd.yaml');
  readfile($filepath);
  die();
}
elseif (preg_match('!^/entry/([^/]+)/([^/]+)$!', $_SERVER['PATH_INFO'], $matches)) { // affichage de l'entrée
  $mdDate = $matches[1];
  $mapid = $matches[2];
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>atom</title></head><body>\n";
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