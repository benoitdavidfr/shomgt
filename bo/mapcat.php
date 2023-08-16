<?php
/*PhpDoc:
title: bo/mapcat.php - gestion du catalogue MapCat et confrontation des données de localisation de MapCat avec celles du GAN
classes:
doc: |
  L'objectif est d'une part de vérifier les contraintes sur MapCat et, d'autre part, d'identifier les écarts entre mapcat
  et le GAN pour
    - s'assurer que mapcat est correct
    - marquer dans mapcat dans le champ badGan l'écart

  Le traitement dans le GAN des excroissances de cartes est hétérogène.
  Parfois l'extension spatiale du GAN les intègre et parfois elle ne les intègre pas.
journal: |
  13/8/2023:
    - restructuration dans le cadre du BO v4 et ajout de la vérification des contraintes
  24/4/2023:
    - prise en compte dans CmpMapCat::scale() de la possibilité que scaleDenominator ne soit pas défini
    - prise en compte dans CmpMapCat::cmpGans() que la carte soit définie dans MapCat et absente du GAN
  3/8/2022:
    - corrections listée par PhpStan level 6
  2/7/2022:
    - reprise après correction des GAN par le Shom à la suite de mon message
    - ajout comparaison des échelles
  24/6/2022:
    - migration 
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../mapcat/mapcat.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../dashboard/gan.inc.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE html>\n<html><head><title>bo/mapcat@$_SERVER[HTTP_HOST]</title></head><body>\n";

// retourne la liste des images géoréférencées de la carte sous la forme [{id} => $info]
/** @return array<string, array<string, mixed>> */
function geoImagesOfMap(string $mapNum, MapCat $mapCat): array { 
  $spatials = $mapCat->spatial ? [$mapNum => $mapCat->asArray()] : [];
  //echo "<pre>insetMaps = "; print_r($this->insetMaps); echo "</pre>\n";
  foreach($mapCat->insetMaps ?? [] as $i => $insetMap) {
    $spatials["$mapNum/inset$i"] = $insetMap;
  }
  return $spatials;
}

function cmpGans(): void { // comparaison MapCat / GAN
  echo "<table border=1><th>mapid</th><th>badGan</th><th>inset</th>",
    "<th>cat'scale</th><th>gan'scale</th><th>ok?</th>",
    "<th>cat'SW</th><th>gan'SW</th><th>ok?</th>",
    "<th>x</th><th>cat'NE</th><th>gan'NE</th><th>ok?</th>\n";
  foreach (MapCat::mapNums(['current']) as $mapNum) {
    $mapCat = MapCat::get($mapNum);
    //echo "<pre>"; print_r($map); echo "</pre>";
    if (!($gan = Gan::$gans[substr($mapNum, 2)] ?? null)) { // carte définie dans MapCat et absente du GAN
      echo "<tr><td>$mapNum</td><td>",$mapCat->badGan ?? '',"</td><td></td>";
      echo "<td>",$mapCat->scale(),"</td><td colspan=9>Absente du GAN</td></tr>\n";
      continue;
    }
    //echo "<pre>gan="; print_r($gan); echo "</pre>";
    //echo "<pre>map="; print_r($map); echo "</pre>";
    if ($mapCat->spatial && $gan->spatial()) {
      $ganspatial = [
        'SW' => str_replace('—', '-', $gan->spatial()['SW']),
        'NE' => str_replace('—', '-', $gan->spatial()['NE']),
      ];
      $mapspatial = $mapCat->spatial;
      //echo "<pre>"; print_r($map); echo "</pre>";
      if ($mapCat->badGan || ($mapCat->scale() <> $gan->scale())
          || ($mapspatial['SW'] <> $ganspatial['SW']) || ($mapspatial['NE'] <> $ganspatial['NE'])) {
        echo "<tr><td>$mapNum</td><td>",$mapCat->badGan ?? '',"</td><td></td>";
        echo "<td>",$mapCat->scale(),"</td><td>",$gan->scale(),"</td>",
          "<td>",($mapCat->scale() == $gan->scale()) ? 'ok' : '<b>KO</b>',"</td>\n";
        echo "<td>$mapspatial[SW]</td><td>$ganspatial[SW]</td>",
          "<td>",($mapspatial['SW'] == $ganspatial['SW']) ? 'ok' : '<b>KO</b',"</td>";
        echo "<td></td><td>$mapspatial[NE]</td><td>$ganspatial[NE]</td>",
          "<td>",($mapspatial['NE'] == $ganspatial['NE']) ? 'ok' : '<b>KO</b',"</td>";
        echo "</tr>\n";
      }
    }
    foreach ($mapCat->insetMaps ?? [] as $i => $insetMap) {
      try {
        $ganpart = Gan::$gans[substr($mapNum, 2)]->inSet(GBox::fromGeoDMd($insetMap['spatial']));
        $ganpartspatial = [
          'SW' => str_replace('—', '-', $ganpart->spatial()['SW']),
          'NE' => str_replace('—', '-', $ganpart->spatial()['NE']),
        ];
        if (($ganpart->scale() <> $mapCat->insetScale($i))
           || ($ganpartspatial['SW'] <> $insetMap['spatial']['SW'])
           || ($ganpartspatial['NE'] <> $insetMap['spatial']['NE'])) {
          echo "<tr><td>$mapNum/$i</td><td>",$mapCat->badGan ?? '',"</td><td>$insetMap[title]</td>";
          //echo "<td><pre>"; print_r($insetMap); echo "</pre></td>";
          echo "<td>",$mapCat->insetScale($i),"</td><td>",$ganpart->scale(),"</td>",
            "<td>",($ganpart->scale() == $mapCat->insetScale($i)) ? 'ok' : '<b>KO</b>',"</td>";
          echo "<td>",$insetMap['spatial']['SW'],"\n";
          //echo "<td><pre>"; print_r($ganpart); echo "</pre></td>";
          echo "<td>$ganpartspatial[SW]</td>",
            "<td>",$ganpartspatial['SW'] == $insetMap['spatial']['SW'] ? 'ok' : '<b>KO</b>',"</td>";
          echo "<td></td><td>",$insetMap['spatial']['NE'],"\n";
          echo "<td>$ganpartspatial[NE]</td>",
            "<td>",$ganpartspatial['NE'] == $insetMap['spatial']['NE'] ? 'ok' : '<b>KO</b>',"</td>";
          echo "</tr>\n";
        }
      }
      catch (SExcept $e) {
      }
    }
  }
  echo "</table>\n";
}

switch($_GET['action'] ?? null) {
  case null: {
    echo "<h2>Gestion du catalogue MapCat</h2><h3>Menu</h3><ul>\n";
    echo "<li><a href='?action=check'>Vérifie les contraintes sur MapCat</a></li>\n";
    echo "<li><a href='?action=cmpGan'>confrontation des données de localisation de MapCat avec celles du GAN</a></li>\n";
    die();
  }
  case 'check': {
    $mapCat = Yaml::parseFile(__DIR__.'/../mapcat/mapcat.yaml');
    
    { // Vérifie qu'aucun no de carte apparait dans plusieurs sections
      $maps = [];
      foreach (MapCat::ALL_KINDS as $kind) {
        foreach (MapCat::mapNums([$kind]) as $mapNum) {
          $maps[$mapNum][$kind] = MapCat::get($mapNum, [$kind]);
        }
      }
      $found = false;
      foreach ($maps as $mapNum => $kindMap) {
        if (count($kindMap) > 1) {
          echo '<pre>',Yaml::dump([$mapNum => $kindMap]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Aucun no de carte apparait dans plusieurs sections<br>\n";
    }

    { // vérifie que toute carte current et obsolete dont l'image principale n'est pas géoréférencée a des cartouches
      // cad que (scaleDenominator && spatial) || insetMaps toujours vrai
      $found = false;
      foreach (MapCat::mapNums() as $mapNum) {
        $mapCat = MapCat::get($mapNum);
        if (!$mapCat->insetMaps && (!$mapCat->scaleDenominator || !$mapCat->spatial)) {
          echo '<pre>',Yaml::dump([$mapNum => $mapCat]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Toute carte current et obsolete dont l'image principale n'est pas géoréférencée a des cartouches<br>\n";
    }

    { // Vérifie que Le mapsFrance de toute carte de maps est <> unknown
      $found = false;
      foreach (MapCat::mapNums() as $mapNum) {
        $mapCat = MapCat::get($mapNum);
        if ($mapCat->mapsFrance == 'unknown') {
          echo '<pre>',Yaml::dump([$mapNum => $mapCat]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Le mapsFrance de toute carte current et obsolete est <> unknown<br>\n";
    }
    
    { // Vérifie les contraintes sur le champ spatial et que les exceptions sont bien indiquées
      $bad = false;
      foreach (MapCat::mapNums() as $mapNum) {
        $mapCat = MapCat::get($mapNum);
        foreach(geoImagesOfMap($mapNum, $mapCat) as $id => $info) {
          $spatial = new Spatial($info['spatial']);
          if ($error = $spatial->isBad()) {
            echo '<pre>',Yaml::dump([$error => [$mapNum => $info]], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
            $bad = true;
          }
        }
      }
      if (!$bad) {
        echo "Tous les champs spatial respectent leurs contraintes, à savoir:<br>\n";
        echo '<pre>',
             Yaml::dump(['Spatial::CONSTRAINTS'=> Spatial::CONSTRAINTS, 'Spatial::EXCEPTIONS'=> Spatial::EXCEPTIONS], 4),
             "</pre>\n";
      }
    }
    break;
  }
  case 'cmpGan': {
    GanStatic::loadFromPser(); // charge les GANs sepuis le fichier gans.pser du dashboard
    //echo '<pre>gans='; print_r(Gan::$gans);
    cmpGans();
    break;
  }
}
