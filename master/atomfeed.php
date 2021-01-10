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
journal: |
  8/1/2021:
    - ajout possibilité de login avec un login et un mot de passe
  7/1/2021:
    - ajout caractéristiques des cartes et zones couvertes
  3/1/2021:
    - ajout du téléchargement du catalogue des cartes
  1/1/2021:
    - limitation aux cartes courantes, ajout des cartes effacées, création d'un pser
  31/12/2020:
    - création - version lisible avec Feedbro
includes: [../lib/accesscntrl.inc.php, ../lib/store.inc.php, ../lib/genatom.inc.php]
*/
require_once __DIR__.'/../lib/accesscntrl.inc.php';
require_once __DIR__.'/../lib/store.inc.php';
require_once __DIR__.'/../lib/genatom.inc.php';


// Accès possible soit sans login/passwd soit par envoi en POST d'un login/passwd
if (!isset($_POST['login']) || !isset($_POST['password'])) {
  if (!Access::cntrl()) {
    header('Access-Control-Allow-Origin: *');
    header('HTTP/1.1 403 Forbidden');
    die("Accès interdit");
  }
}
elseif (!Access::cntrl("$_POST[login]:$_POST[password]")) {
  header('Access-Control-Allow-Origin: *');
  header('HTTP/1.1 403 Forbidden');
  die("Accès interdit");
}
  

// définition de configs pour tester updtslave.php
//define('TEST', 'version test incoming/20170613');
define('TEST', '');

// étiquette associée au code ISO des zones
define ('TERMS', [
  'FR'=> "France",
  'FX'=> "France métropolitaine",
  'GP'=> "Guadeloupe",
  'MQ'=> "Martinique",
  'GF'=> "Guyane",
  'RE'=> "La Réunion",
  'YT'=> "Mayotte",
  'PM'=> "Saint-Pierre-et-Miquelon",
  'BL'=> "Saint-Barthélémy",
  'MF'=> "Saint-Martin",
  'TF'=> "Terres australes et antarctiques françaises",
  'PF'=> "Polynésie française",
  'WF'=> "Wallis-et-Futuna",
  'NC'=> "Nouvelle-Calédonie",
  'CP'=> "Île Clipperton",
]
);

// Définit le fuseau horaire par défaut à utiliser.
date_default_timezone_set('UTC');

if (is_file(__DIR__.'/atomfeed.pser')) {
  $histo2 = unserialize(@file_get_contents(__DIR__.'/atomfeed.pser'));
}
else {
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
      $nb = count($histoMapid['histo']);
      if (array_values($histoMapid['histo'])[$nb-1] == 'Suppression de la carte') {
        $mdDate = array_keys($histoMapid['histo'])[$nb-1]; // .'T12:00:00Z'
        $histo2[$mdDate][$mapid] = 'Suppression de la carte';
      }
      elseif (CurrentGeoTiff::mapExists($mapnum)) {
        $mdDate = array_keys($histoMapid['histo'])[$nb-1];
        $mdDate = str_replace(['+01:00','+02:00'], '', $mdDate);
        $histo2[$mdDate][$mapid] =
          (isset($histoMapid['title']) ? ['title'=> $histoMapid['title']] : [])
          + (isset($histoMapid['scaleDenominator']) ? ['scaleDenominator'=> $histoMapid['scaleDenominator']] : [])
          + (isset($histoMapid['mapsFrance']) ? ['mapsFrance'=> $histoMapid['mapsFrance']] : [])
          + (isset($histoMapid['georss:polygon']) ? ['georss:polygon'=> $histoMapid['georss:polygon']] : [])
          + array_values($histoMapid['histo'])[$nb-1];
      }
    }
  }
  krsort($histo2);
  //file_put_contents(__DIR__.'/atomfeed.pser', serialize($histo2));
}

//echo "<pre>histo2="; print_r($histo2); die();
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
    $updated = $mdDate.'T12:00:00Z';
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
        $categories = [];
        foreach ($map['mapsFrance'] as $iso) {
          $categories[] = ['term'=> "https://id.georef.eu/dc-spatial/$iso", 'label'=> TERMS[$iso]];
        }
        $path = str_replace('incoming/','',$map['path']);
        $entries[] = [
          'title'=> "Ajout $mapid, $map[edition], dernière correction $map[lastUpdate]",
          'uri'=> "$script_path/$mdDate/$mapid",
          'updated'=> $updated,
          'links'=> [
            [ 'href'=> "$script_path/entry/$mdDate/$mapid", 'rel'=> 'alternate', 'type'=>'text/html' ],
            [ 'href'=> "$script_path/dwnld/$path.7z", 'rel'=> 'alternate', 'type'=>'application/x-7z-compressed' ],
          ],
          'summary'=> "$mapid - $map[title]"
            .(isset($map['scaleDenominator']) ? "<br>1 : $map[scaleDenominator]" : '')
            ."<br>$map[edition], dernière correction $map[lastUpdate]",
          'categories'=> $categories,
          'georss:polygon'=> $map['georss:polygon'],
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
  header('Access-Control-Allow-Origin: *');
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
    echo "<tr><td><i>titre</i></td><td>$map[title]</td></tr>\n";
    echo "<tr><td><i>échelle</i></td><td>1 : $map[scaleDenominator]</td></tr>\n";
    echo "<tr><td><i>zones</i></td><td>",implode(', ', $map['mapsFrance']),"</td></tr>\n";
    echo "<tr><td><i>date</i></td><td>$mdDate</td></tr>\n";
    echo "<tr><td><i>édition</i></td><td>$map[edition], dernière correction $map[lastUpdate]</td></tr>\n";
    $url = "$script_path/dwnld/$map[path]";
    echo "<tr><td><i>téléchargement</i></td><td><a href='$url'>$url</a></td></tr>\n";
  }
  else
    echo "<tr><td><i>Date de suppression</i></td><td>$mdDate</td></tr>\n";
  echo "</table>\n";
}
elseif (preg_match('!^/dwnld/([^.]+)\.7z$!', $_SERVER['PATH_INFO'], $matches)) { // téléchargement
  (new SevenZipMap($matches[1]))->readfile();
  die();
}
else {
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>histo</title></head><body><pre>\n";
  echo "Erreur, no match pour $_SERVER[PATH_INFO]\n";
}