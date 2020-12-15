<?php
/*PhpDoc:
name: gestion.php
title: cat2/gestion.php - gestion du catalogue, cad indépendamenet des corrections des cartes
doc: |
  Dans une premier temps, le WFS est moissonné.
  Puis, le contenu du WFS est confronté au contenu du catalogue.
  Les cartes présentes dans le WFS et absentes du catalogue doivent a priori être ajoutées au catalogue.
  Pour cela copier les enregistrements dans le catalogue.
  Les cartes présentes dans le catalogue et absentes du WFS doivent être rendues obsolètes dans le catalogue.
  Pour cela cliquer sur l'enregistrement ce qui le marque comme obsolète dans le catalogue.
journal: |
  14/12/2020:
    création
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/wfsserver.inc.php';
require_once __DIR__.'/wfsjson.inc.php';
require_once __DIR__.'/mapcat.inc.php';

use Symfony\Component\Yaml\Yaml;


if (php_sapi_name() <> 'cli') {
  echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>gestion</title></head><body><pre>\n";
  if (!isset($_GET['action'])) {
    echo "</pre>gestion.php - Actions proposées:<ul>\n";
    echo "<li><a href='?action=harvestWfs'>moisson du WFS et affichage du contenu du WFS</a>\n";
    echo "<li><a href='?action=compCat'>comparaison du WFS avec le catalogue</a>\n";
    echo "</ul>\n";
    die();
  }
  else
    $action = $_GET['action'];
}

// formatte un entier positif en ajoutant les séparateurs de milliers
function ajouteSepMilliers(int $val): string {
  if ($val < 1e3)
    return $val;
  else
    return sprintf('%s.%03d', ajouteSepMilliers($val/1e3), $val % 1e3);
}
if (0) { // Test unitaire
  foreach ([12, 3456, 123456, 1234567, 12345678, 500000, 2e7, 1e10] as $val)
    echo "$val -> ",ajouteSepMilliers($val),"\n";
  die();
}

// lecture du wfs Shom des fantomes des cartes GeoTiff
// retour d'un ensemble de features chacun identifié par un id de la forme "FR{num}"
function wfsdl(): array {
  //printf("time-filemtime=%.2f heures<br>\n",(time()-filemtime(__DIR__.'/wfsdl.pser'))/60/60);
  // Le fichier wfsdl.pser est automatiquement mis à jour toutes les 12 heures
  if (is_file(__DIR__.'/wfsdl.pser') && (time() - filemtime(__DIR__.'/wfsdl.pser') < 12*60*60))
    return unserialize(file_get_contents(__DIR__.'/wfsdl.pser'));
  
  //try {
    $typenames = [
      'CARTES_MARINES_GRILLE:grille_geotiff_30', // cartes echelle > 1/30K
      'CARTES_MARINES_GRILLE:grille_geotiff_30_300', // cartes aux échelles entre 1/30K et 1/300K
      'CARTES_MARINES_GRILLE:grille_geotiff_300_800', // cartes aux échelles entre 1/300K et 1/800K
      'CARTES_MARINES_GRILLE:grille_geotiff_800', // carte échelle < 1/800K
    ];

    $yaml = Yaml::parseFile(__DIR__.'/shomwfs.yaml');
    $shomwfs = new WfsServerJson($yaml, 'shomwfs');

    $wfs = [];
    foreach ($typenames as $typename) {
      $numberMatched = $shomwfs->getNumberMatched($typename);
      $count = 100;
      for ($startindex = 0; $startindex < $numberMatched; $startindex += $count) {
        $fc = $shomwfs->getFeatureAsArray($typename, [], -1, '', $count, $startindex);
        foreach ($fc['features'] as $feature) {
          $bbox = Geometry::fromGeoJSON($feature['geometry'])->bbox()->asArray();
          $num = $feature['properties']['carte_id'];
          $id = 'FR'.$num;
          $wfs[$id] = [
            'type'=> 'Feature',
            'id'=> $id,
            'bbox'=> array_merge($bbox['min'], $bbox['max']),
            'properties'=> [
              'num'=> intval($num),
              'title'=> substr($feature['properties']['name'], strpos($feature['properties']['name'], '-')+2),
              'scaleDenominator'=> ajouteSepMilliers($feature['properties']['scale']),
            ],
            'geometry'=> $feature['geometry'],
          ];
          //echo "id=$id\n";
        }
      }
    }
    //echo '<pre>',json_encode($maps, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    echo count($wfs)," cartes téléchargées du WFS du Shom<br>\n";
    
    foreach (Yaml::parseFile(__DIR__.'/mapcatspec.yaml')['cartesAjoutéesAuServiceWfs'] as $mapid => $map) {
      echo "Ajout carte $mapid $map[title]\n";
      $bbox = $map['outerBBoxLonLatDd'];
      $wfs[$mapid] = [
        'type'=> 'Feature',
        'id'=> $mapid,
        'bbox'=> $bbox,
        'properties'=> [
          'num'=> intval(substr($mapid, 2)),
          'title'=> $map['title'],
          'scaleDenominator'=> $map['scaleDenominator'],
        ],
        'geometry' => [
          'type'=> 'Polygon',
          'coordinates'=> [[
            [$bbox[0], $bbox[1]], // SW
            [$bbox[0], $bbox[3]], // NW
            [$bbox[2], $bbox[3]], // NE
            [$bbox[2], $bbox[1]], // SE
            [$bbox[0], $bbox[1]], // SW
          ]],
        ],
      ];
    }
    
    //echo Yaml::dump($wfs, 5, 2);
    ksort($wfs);
    file_put_contents(__DIR__.'/wfsdl.pser', serialize($wfs));
    return $wfs;
    /*}
  catch (Exception $e) {
    if (is_file(__DIR__.'/wfsdl.pser'))
      return unserialize(file_get_contents(__DIR__.'/wfsdl.pser'));
    else
      throw new Exception("Ereur: impossible de créer wfsdl.pser");
  }*/
}

if ($action == 'harvestWfs') {
  echo Yaml::dump(wfsdl(), 2, 2);
  die();
}

if (in_array($action, ['compCat', 'obsolete'])) {
  MapCat::init();
  $wfs = wfsdl();

  if ($action == 'obsolete') {
    MapCat::$maps[$_GET['id']]->setObsolete("Carte absente du flux WFS le ".date('d/m/Y'));
  }
  
  $nb = 0;
  foreach ($wfs as $id => $map)
    if (!isset(MapCat::$maps[$id])) $nb++;
  echo "<h2>$nb nouvelles cartes dans le WFS et absentes de MapCat</h2>\n";
  foreach ($wfs as $id => $map) {
    if (!isset(MapCat::$maps[$id])) {
      //echo Yaml::dump([$id => $map], 2, 2);
      echo Yaml::dump([
        $id => [
          'title'=> $map['properties']['title'],
          'scaleDenominator'=> $map['properties']['scaleDenominator'],
          'bboxLonLatFromWfs'=> $map['bbox'],
          'noteCatalogue'=> "Nouvelle carte détectée dans le flux WFS le ".date('d/m/Y'),
        ]
      ], 2, 2);
    }
  }
  
  $nb = 0;
  foreach (MapCat::$maps as $id => $map)
    if (!$map->obsolete() && !isset($wfs[$id])) $nb++;
  echo "<h2>$nb cartes de MapCat sont absentes du WFS et sont a priori obsolètes</h2>\n";
  foreach (MapCat::$maps as $id => $map)
    if (!$map->obsolete() && !isset($wfs[$id]))
      //echo "<a href='?action=obsolete&amp;id=$id'>",Yaml::dump([$id => $map->asArray()], 2, 2),"</a>\n";
      echo "<table><tr>",
          "<td><form action=''>",
            "<input type='hidden' name='action' value='obsolete'>",
            "<input type='hidden' name='id' value='$id'>",
            "<input type='submit' value='Obsolete'>",
          "</form></td>",
          "<td>",Yaml::dump([$id => $map->asArray()], 2, 2),"</td>",
          "</tr></table>\n";
  
  $mapCatIds = [];
  foreach (MapCat::$maps as $id => $map)
    if (!$map->obsolete())
      $mapCatIds[] = $id;

  echo "<h2>Ecarts entre MapCat (barré) et WFS (en gras)</h2>\n";
  $ids = array_intersect(array_keys($wfs), $mapCatIds);
  foreach ($ids as $id) {
    $mapwfs = $wfs[$id]['properties'];
    $mapcat = MapCat::$maps[$id]->asArray();
    if ($mapwfs['title'] <> $mapcat['title'])
      echo "$id: <s>$mapcat[title]</s> <b>$mapwfs[title]</b>\n";
    if ($mapwfs['scaleDenominator'] <> ($mapcat['scaleDenominator'] ?? ''))
      echo "$id: <s>",$mapcat['scaleDenominator'] ?? 'absent',"</s> <b>$mapwfs[scaleDenominator]</b>\n";
  }
  MapCat::close();
  die();
}

