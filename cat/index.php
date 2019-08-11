<?php
/*PhpDoc:
name: index.php
title: index.php - traitements divers
journal: |
  10/4/2019:
    ajout liste les cartes ShomGt absentes du catalogue Shom
includes:
  - lib.inc.php
  - ../vendor/autoload.php
  - mapcat.inc.php
  - ../lib/gegeom.inc.php
  - map.inc.php
*/
use Symfony\Component\Yaml\Yaml;
require_once 'lib.inc.php';

if (!isset($_GET['action'])) {
  echo "<!DOCTYPE HTML><html><head><title>catalogue</title><meta charset='UTF-8'></head><body>\n";
  echo "<b>catalogue des cartes Shom - Actions proposées:</b><ul>\n";
  echo "<li><a href='?action=showMapCat'>Liste des cartes dans leur version actuelle</a>\n";
  echo "<li><a href='?action=showMapCatHistory'>Liste de l'historique des cartes</a>\n";
  echo "<li><a href='map.php'>Carte du catalogue par tranche d'échelles</a>\n";
  echo "<li><a href='?action=stats'>Statistiques du catalogue</a>\n";
  echo "<li><a href='?action=shomgtObsolete'>Cartes Shomgt à actualiser</a>\n";
  echo "<li><a href='?action=shomgtObsolete2'>Cartes Shomgt absentes du catalogue a priori à remplacer</a>\n";
  echo "<li><a href='?action=shomgtMissing'>Cartes manquantes dans Shomgt</a>\n";
  echo "<li><a href='geojson.php'>Liste des cartes Shom comme FeatureCollection GeoJSON</a>\n";
  //echo "<li><a href='?action=shomgtTable'>Liste des cartes ShomGt sous la forme d'une table</a>\n";
  echo "<li><a href='mapcat.php'>Liste des cartes Shom</a>\n";
  echo "<li><a href='?action=mapPerScale'>Nbre de cartes Shom par échelle</a>\n";
  echo "</ul>\n";
  die();
}

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/mapcat.inc.php';

// affichage en JSON le contenu du catalogue
if ($_GET['action']=='showMapCat') {
  try {
    MapCat::load();
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(
            MapCat::allMostRecentAsArray(),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
    die("\n");
  }
  catch(Exception $e) {
    echo $e->getMessage();
  }
  die();
}

// affichage du document des GAN en JSON
if ($_GET['action']=='showMapCatHistory') {
  try {
    MapCat::load();
    header('Content-type: application/json; charset="utf-8"');
    echo json_encode(
            MapCat::allAsArray(),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
    die("\n");
  }
  catch(Exception $e) {
    echo $e->getMessage();
  }
  die();
}

if ($_GET['action']=='stats') {
  echo "<!DOCTYPE HTML><html><head><title>catalogue</title><meta charset='UTF-8'></head><body>\n";
  echo count(MapCat::getAll())," cartes dans le catalogue<br>\n";

  $maps = [];
  $nbgeotiffs = -1; // pour prendre en compte 0101bis/0101_pal300
  $shomgt = Yaml::parse(file_get_contents(__DIR__.'/../ws/shomgt.yaml'), Yaml::PARSE_DATETIME);
  //echo '<pre>shomgt='; print_r($shomgt); echo "</pre>\n";
  foreach ($shomgt as $key => $layer) {
    if ((substr($key,0,2)<>'gt') || !$layer)
      continue;
    //echo "<pre>$key layer="; print_r($layer); echo "</pre>\n";
    $nbgeotiffs += count($layer);
    foreach ($shomgt[$key] as $name => $map) {
      if (in_array($name, ['0101bis/0101_pal300']))
        continue;
      if (!preg_match('!^([0-9]+)/!', $name, $matches))
        die("nom $name non reconnu");
      $maps[$matches[1]] = 1;
    }
  }
  $nbmaps = count($maps);
  echo "$nbmaps cartes dans Shomgt (dont 4 cartes AEM + 1 carte Manche-grid)<br>\n";
  echo "et $nbgeotiffs géoTiffs (cartouche ou zone principale)<br>\n";
  die();
}

// récupère l'info d'édition de la carte de shomgt dans les métadonnées du GéoTIFF
function shomgtedition(string $num, string $key): string {
  $xml = file_get_contents("../../../shomgeotiff/current/$num/CARTO_GEOTIFF_${num}_${key}.xml");
  $pattern = '!<gmd:edition>\s*<gco:CharacterString>([^<]*)</gco:CharacterString>\s*</gmd:edition>!';
  if (preg_match($pattern, $xml, $matches))
    return $matches[1];
  
  echo "<b>$num/CARTO_GEOTIFF_${num}_${key}.xml</b><br>",
       str_replace(['<'],['{'],$xml);
  die();
}
 
// liste les cartes ShomGt périmées
if ($_GET['action']=='shomgtObsolete') {
  echo "<!DOCTYPE HTML><html><head><title>catalogue</title><meta charset='UTF-8'></head><body>\n";
  $shomgtObsoleteComment = [
    '6497'=> "L'édition 2018 de cette carte n'existe pas en numérique",
    '7095'=> "L'édition de cette carte dans ShomGt est erronée",
  ];
  echo "<h2>Liste des cartes de shomgt à actualiser</h2>\n";
  try {
    $mapcat = MapCat::allMostRecentAsArray();
    $updated = isset($mapcat['modified']) ? $mapcat['modified'] : $mapcat['created'];
    echo "Catalogue actualisé le $updated<br>\n";
    $gans = $mapcat['maps'];
  }
  catch(Exception $e) {
    die("Erreur, il faut d'abord <a href='gan.php?action=harvest'>moissonner les GANs</a>"
      ." puis <a href='gan.php?action=store'>les analyser et les enregistrer.<br>\n");
  }
  $shomgt = Yaml::parseFile(__DIR__.'/../ws/shomgt.yaml', Yaml::PARSE_DATETIME);
  //$shomgt = Yaml::parse(file_get_contents(__DIR__.'/../shomgt/shomgt20181130.yaml'), Yaml::PARSE_DATETIME);
  //print_r($yaml);
  $nbre = 0;
  $gtmaps = []; // [ {num} => [ {pal300|\d_gtw} => map ] ]
  foreach ($shomgt as $key => $val) {
    if ((substr($key,0,2)=='gt') && $shomgt[$key]) {
      foreach ($shomgt[$key] as $name => $map) {
        if (in_array($name, [
          '0101bis/0101_pal300',
          '7330/7330_2016_Mercator_WGS84',
          '7344/7344_2016',
          '7360/7360_3016_Mercator_WGS84',
          '8502/8502_ReuniZMSOI_Mercator_wgs84',
          '8101/Manche-grid_8101XG_MC_WGS84',
        ]))
          continue;
        if (preg_match('!^([0-9]+)/[0-9]+_(pal300|(\d+|[A-Z])_gtw)$!', $name, $matches))
          $gtmaps[$matches[1]][$matches[2]] = $map;
        else
          die("nom $name non reconnu");
      }
    }
  }
  ksort($gtmaps);
  echo "<table border=1><th>titre</th><th>gan</th><th>shomgt</th><th>comment</th>\n";
  foreach ($gtmaps as $num => $gtmap) {
    if (isset($gtmap['pal300']))
      $key = 'pal300';
    elseif (isset($gtmap['1_gtw']))
      $key = '1_gtw';
    elseif (isset($gtmap['A_gtw']))
      $key = 'A_gtw';
    else
      die("ni pal300 ni 1_gtw  ni A_gtw pour $num");
    $geotif = $gtmap[$key];
    $gan = isset($gans["FR$num"]) ? $gans["FR$num"] : null;
    $ganEdition = $gan ? $gan['issued'] : '';
    if ($ganEdition == 'notValid')
      $ganYear = '';
    elseif (preg_match('!^(Édition n° \d+ -|Publication) (\d+)$!', $ganEdition, $matches))
      $ganYear = $matches[2];
    else
      die("</table>\nNo match on ganEdition $ganEdition");
    $shomgtEdition = shomgtedition($num, $key);
    if (!preg_match('!^(Edition n°\s*\d+ -|Publication) (\d+) - !', $shomgtEdition, $matches))
      die("</table>\nNo match on shomgtEdition $shomgtEdition");
    $shomgtYear = $matches[2];
    //$b = ($ganYear == $shomgtYear) ? ['',''] : ['<b>','</b>'];
    if ($ganYear <> $shomgtYear)
      echo "<tr><td>$geotif[title]</td>",
           "<td>$ganEdition</td>",
           "<td>$shomgtEdition</td>",
           "<td>",isset($shomgtObsoleteComment[$num]) ? $shomgtObsoleteComment[$num] : '',"</td>",
           "</tr>\n";
  }
  die("</table>\nFIN OK<br>\n");
}

// liste les cartes ShomGt absentes du catalogue Shom
if ($_GET['action']=='shomgtObsolete2') {
  $shomgt = Yaml::parseFile(__DIR__.'/../ws/shomgt.yaml', Yaml::PARSE_DATETIME);
  $nbre = 0;
  $mapcat = MapCat::allMostRecentAsArray();
  $updated = isset($mapcat['modified']) ? $mapcat['modified'] : $mapcat['created'];
  echo "Catalogue actualisé le $updated<br>\n";
  $gans = $mapcat['maps'];

  foreach ($shomgt as $key => $val) {
    if ((substr($key,0,2)=='gt') && $shomgt[$key]) {
      foreach ($shomgt[$key] as $name => $map) {
        if (!preg_match('!^([0-9]+)/[0-9]+_pal300$!', $name, $matches))
          continue;
        $mapnum = $matches[1];
        if (!isset($gans["FR$mapnum"])) {
          echo "carte $mapnum PAS dans gans<br>\n";
        }
      }
    }
  }
  
  die("FIN OK<br>\n");
}

// fabrication de la liste des id des cartes de shomgt comme clés de la forme "FR{num}"
function shomgtMapNums(): array {
  $shomgt = []; // liste des id des cartes de shomgt 
  //$yaml = Yaml::parse(file_get_contents(__DIR__.'/../shomgt/shomgt20181130.yaml'), Yaml::PARSE_DATETIME);
  $yaml = Yaml::parse(file_get_contents(__DIR__.'/../ws/shomgt.yaml'), Yaml::PARSE_DATETIME);
  foreach ($yaml as $key => $layer) {
    if ((substr($key,0,2)<>'gt') || !$layer) continue;
    foreach ($layer as $gtname => $geotiff) {
      if (in_array($gtname, ['0101bis/0101_pal300']))
        continue;
      elseif (preg_match('!^([0-9]+)/!', $gtname, $matches))
        $shomgt["FR$matches[1]"] = 1;
      else
        die("nom $name non reconnu");
    }
  }
  return $shomgt;
}

// liste les cartes Shomgt manquantes
if ($_GET['action']=='shomgtMissing') {
  require_once __DIR__.'/../lib/gegeom.inc.php';

  // cartes à exlure après analyse avec justification
  $outOfPortfolio = [
    'FR0101Q'=> "carte des fuseaux horaires inutile",
    'FR4174'=> "côte Est de Madagascar, intersecte peu la Réunion",
    'FR5438'=> "carte ancienne, apporte peu par rapport au planisphère",
    'FR6963'=> "intersecte pas assez la Corse",
    'FR6963'=> "intersecte pas assez la Corse",
    'FR6967'=> "n'intersecte pas assez la métropole",
    'FR7037'=> "n'intersecte pas assez la métropole",
    'FR7165'=> "n'intersecte pas une zone française",
    'FR7678'=> "la faible intersection avec la France est couverte par FR7677 à la même échelle",
    'FR7799'=> "n'intersecte pas réellement une zone française",
    'FR9999'=> "carte inutile",
  ];
  
  echo "<!DOCTYPE HTML><html><head><title>catalogue</title><meta charset='UTF-8'></head><body>\n";
  echo "<h2>Cartes du flux WFS intersectant la France et absentes de Shomgt</h2>\n";
  $shomgtids = shomgtMapNums(); // liste des id des cartes de shomgt
  
  $francegeojson = json_decode(file_get_contents(__DIR__.'/france.geojson'), true);
  $france = []; // tableau libellé -> Polygon
  foreach ($francegeojson['features'] as $feature) {
    $france[$feature['properties']['label']] = Geometry::fromGeoJSON($feature['geometry']);
  }
  
  $nbmaps = 0;
  echo "<table>";
  foreach (wfsdl() as $id => $feature) {
    if (isset($shomgtids[$id]))
      continue;
    $frZones = [];
    $fgeom = Geometry::fromGeoJSON($feature['geometry']);
    foreach ($france as $label => $frgeom) {
      if ($fgeom->inters($frgeom))
        $frZones[] = $label;
    }
    if (!$frZones)
      continue;
    $href = "?action=shomgtMissingMap&amp;fid=$id";
    $prop = $feature['properties'];
    if (isset($outOfPortfolio[$id])) {
      echo "<tr><td colspan=2><a href='$href'><s>$prop[name] (1:$prop[scale])</s></a></td></tr>\n",
           '<tr><td>',str_repeat('&nbsp;', 4 ),'</td><td>',$outOfPortfolio[$id],"</td></tr>\n";
    }
    else {
      echo "<tr><td colspan=2><a href='$href'>$prop[name] (1:$prop[scale])</a></td></tr>\n",
           '<tr><td>',str_repeat('&nbsp;', 4 ),'</td><td>',implode(', ',$frZones),"\n</td></tr>\n";
      $nbmaps++;
    }
  }
  echo "</table>$nbmaps cartes identifiées<br>\n";
  die();
}

// Affichage de la carte Leaflet pour visualiser l'emprise de la carte fid, utilisé par shomgtMissing
if ($_GET['action']=='shomgtMissingMap') {
  require_once __DIR__.'/map.inc.php';
  $map = new Map(
    [
      'title'=> "carte Shom GT $_GET[fid]",
      'bases'=> [
        'shomgt'=> [
          'title'=> "Cartes Shom GT",
          'type'=> 'TileLayer',
          'url'=> 'https://geoapi.fr/shomgt/tile.php/gtpyr/{z}/{x}/{y}.png',
          'options'=> ['format'=>'image/jpeg','minZoom'=> 0,'maxZoom'=> 21,'detectRetina'=> true,'attribution'=>'ign'],
        ],
        'cartes'=> [
          'title'=> "Cartes IGN",
          'type'=> 'TileLayer',
          'url'=> 'http://igngp.geoapi.fr/tile.php/cartes/{z}/{x}/{y}.jpg',
          'options'=> ['format'=>'image/jpeg','minZoom'=> 0,'maxZoom'=> 21,'detectRetina'=> true,'attribution'=>'ign'],
        ],
        'whiteimg'=> [
          'title'=> "Fond blanc",
          'type'=> 'TileLayer',
          'url'=> 'http://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
          'options'=> [ 'format'=> 'image/jpeg', 'minZoom'=> 0, 'maxZoom'=> 21, 'detectRetina'=> true ],
        ],
      ],
      'overlays'=>[
        'france'=> [
          'title'=> "France",
          'type'=> 'UGeoJSONLayer',
          'endpoint'=> 'http://localhost/geoapi/shomgtgan/france.geojson',
          'once'=> true,
        ],
        'carteGT'=> [
          'title'=> "carte $_GET[fid]",
          'type'=> 'UGeoJSONLayer',
          'endpoint'=> "http://localhost/geoapi/shomgtgan/?action=shomgtMissingGJ&fid=$_GET[fid]",
          'style'=> ['color'=> 'orange'],
        ],
      ],
      'defaultLayers'=>['shomgt','france','carteGT'],
    ],
    'geodata/map');
  // le feature correspondant à la carte
  $feature = wfsdl()[$_GET['fid']];
  // la geométrie du feature correspondant à la carte
  $geom = $feature['geometry'];
  // les coordonnées du premier (ou seul) polygone
  $polCoord = ($geom['type']=='Polygon') ? $geom['coordinates'] : $geom['coordinates'][0];
  // le centre du premier polygone, je pourrais chercher un centre plus adapté !
  $center = [ ($polCoord[0][0][1] + $polCoord[0][2][1])/2, ($polCoord[0][0][0] + $polCoord[0][2][0])/2 ];
  $extent = max(abs($polCoord[0][0][1] - $polCoord[0][2][1]), abs($polCoord[0][0][0] - $polCoord[0][2][0]));
  $zoom = round(8 - log($extent, 2));
  //echo "extent=$extent; zoom=$zoom"; die();
  $map->display('geodata/map', $center, $zoom); 
}

// génération du GeoJSON de la carte fid, utilisé par shomgtMissingMap
if ($_GET['action']=='shomgtMissingGJ') {
  header('Content-type: application/json; charset="utf8"');
  echo json_encode(['type'=> 'FeatureCollection', 'features'=> [wfsdl()[$_GET['fid']]]],
                   JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
  die();
}

/*if ($_GET['action']=='shomgtTable') {
  echo "<!DOCTYPE HTML><html><head><title>catalogue</title><meta charset='UTF-8'></head><body>\n";
  $maps = shomgtMapNums();
  ksort($maps);
  //echo "<pre>"; print_r(array_keys($maps));
  foreach (array_keys($maps) as $num)
    echo "$num<br>\n";
  die();
}*/
  
if ($_GET['action']=='mapPerScale') {
  echo "<h2>Nbre de cartes Shom par échelle</h2>\n";
  $scaleDs = [];
  foreach (MapCat::allMostRecentAsArray()['maps'] as $map) {
    $scaleD = isset($map['scaleDenominator']) ? $map['scaleDenominator'] : $map['boxes'][0]['scaleDenominator'];
    $scaleD = str_replace('.','',$scaleD);
    if (!isset($scaleDs[$scaleD]))
      $scaleDs[$scaleD] = 1;
    else
      $scaleDs[$scaleD]++;
  }
  ksort($scaleDs);
  echo "<table border=1>\n";
  foreach ($scaleDs as $s => $c) 
    echo "<tr><td>$s</td><td>$c</td></tr>\n";
  echo "</table>\n";
  die();
}

die("Erreur aucune action"); 
