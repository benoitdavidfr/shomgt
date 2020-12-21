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
includes: ['../lib/gegeom.inc.php', wfs.php, mapcat.php]
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../lib/gegeom.inc.php';
require_once __DIR__.'/wfs.php';
require_once __DIR__.'/mapcat.php';

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

if ($action == 'harvestWfs') {
  //print_r(Wfs::dl());
  foreach (Wfs::dl() as $id => $feature)
    echo Yaml::dump([$id => $feature->asArray()], 2, 2);
  die();
}

if (in_array($action, ['compCat', 'obsolete'])) {
  $wfsItems = Wfs::items();

  /*if ($action == 'obsolete') {
    MapCat::$maps[$_GET['id']]->setObsolete("Carte absente du flux WFS le ".date('d/m/Y'));
  }*/
  
  $nb = 0;
  foreach ($wfsItems as $id => $wfsItem)
    if ($wfsItem->properties['mapsFrance'] && !MapCat::maps($id)) $nb++;
  echo "<h2>$nb nouvelles cartes dans le WFS et absentes de MapCat</h2>\n";
  foreach ($wfsItems as $id => $wfsItem) {
    if ($wfsItem->properties['mapsFrance'] && !MapCat::maps($id)) {
      //echo Yaml::dump([$id => $map], 2, 2);
      echo Yaml::dump([$id => $wfsItem->asArray()], 2, 2);
    }
  }
  
  $nb = 0;
  foreach (MapCat::maps() as $id => $map)
    if (!$map->obsolete() && !isset($wfsItems[$id])) $nb++;
  echo "<h2>$nb cartes de MapCat sont absentes du WFS et sont a priori obsolètes</h2>\n";
  foreach (MapCat::maps() as $id => $map)
    if (!$map->obsolete() && !isset($wfsItems[$id]))
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
  foreach (MapCat::maps() as $id => $map)
    if (!$map->obsolete())
      $mapCatIds[] = $id;

  echo "<h2>Ecarts entre MapCat (barré) et WFS (en gras)</h2>\n";
  $ids = array_intersect(array_keys($wfsItems), $mapCatIds);
  foreach ($ids as $id) {
    $wfsProp = $wfsItems[$id]->properties;
    $mapcat = MapCat::maps($id);
    if ($wfsProp['title'] <> $mapcat['title'])
      echo "$id: <s>$mapcat[title]</s> <b>$wfsProp[title]</b>\n";
    if ($wfsProp['scaleDenominator'] <> ($mapcat['scaleDenominator'] ?? ''))
      echo "$id: <s>",$mapcat['scaleDenominator'] ?? 'absent',"</s> <b>$wfsProp[scaleDenominator]</b>\n";
  }
  MapCat::close();
  die();
}

