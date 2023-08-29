<?php
namespace mapcat;
{/*PhpDoc:
title: mapcat/index.php - gestion du catalogue MapCat et confrontation des données de localisation de MapCat avec celles du GAN
classes:
doc: |
  L'objectif est d'une part de vérifier les contraintes sur MapCat et, d'autre part, d'identifier les écarts entre mapcat
  et le GAN pour
    - s'assurer que mapcat est correct
    - marquer dans mapcat dans le champ badGan l'écart

  Le traitement dans le GAN des excroissances de cartes est hétérogène.
  Parfois l'extension spatiale du GAN les intègre et parfois elle ne les intègre pas.

  Le script est aussi utilisé pour mettre à jour ou insérer un enregistrement MapCat depuis bo/addmaps
journal: |
  27-28/8/2023:
    - évolution du schéma
  22-25/8/2023:
    - ajout mise en base de MapCat + mise à jour/saisie d'un enregistrement
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
*/}
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../bo/login.inc.php';
require_once __DIR__.'/../shomft/frzee.inc.php';
require_once __DIR__.'/../lib/gebox.inc.php';
require_once __DIR__.'/../lib/mysql.inc.php';
require_once __DIR__.'/../dashboard/gan.inc.php';
require_once __DIR__.'/mapcat.inc.php';

use Symfony\Component\Yaml\Yaml;


if (!\bo\callingThisFile(__FILE__)) return; // retourne si le fichier est inclus

switch ($_SERVER['PATH_INFO'] ?? null) { // interface API JSON
  case null: break;
  case '/all': {
    $mapCats = [];
    foreach (MapCat::mapNums() as $mapNum) {
      $mapCats[$mapNum] = MapCat::get($mapNum)->asArray();
    }
    header('Content-type: application/json; charset="utf-8"');
    die(json_encode($mapCats));
  }
  default: {
    if (preg_match('!^/(\d{4})$!', $_SERVER['PATH_INFO'], $matches)) {
      $mapNum = $matches[1];
      $mapCat = MapCat::get($mapNum);
      header('Content-type: application/json; charset="utf-8"');
      die(json_encode($mapCat->asArray()));
    }
    echo "PATH_INFO='$_SERVER[PATH_INFO]' non compris<br>\n";
  }
}

echo "<!DOCTYPE html>\n<html><head><title>bo/mapcat@$_SERVER[HTTP_HOST]</title></head><body>\n";

/** retourne la liste des images géoréférencées de la carte sous la forme [{id} => $info]
 * @return array<string, array<string, mixed>> */
function geoImagesOfMap(string $mapNum, MapCatItem $mapCat): array {
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
  foreach (MapCat::mapNums() as $mapNum) {
    $mapCat = MapCat::get($mapNum);
    if ($mapCat->obsolete) continue; // on ne compare pas les cartes obsolètes
    //echo "<pre>"; print_r($map); echo "</pre>";
    if (!($gan = \dashboard\Gan::$gans[$mapNum] ?? null)) { // carte définie dans MapCat et absente du GAN
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
        $ganpart = \dashboard\Gan::$gans[$mapNum]->inSet(new \gegeom\GBox($insetMap['spatial']));
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
      catch (\SExcept $e) {
      }
    }
  }
  echo "</table>\n";
}


if (!($user = \bo\Login::loggedIn())) {
  die("Erreur, ce sript nécessite d'être logué\n");
}


echo '<pre>',Yaml::dump(['$_POST'=> $_POST, '$_GET'=> $_GET]),"</pre>\n";

switch ($action = $_POST['action'] ?? $_GET['action'] ?? null) {
  case null: {
    echo "<h2>Gestion du catalogue MapCat</h2><h3>Menu</h3><ul>\n";
    echo "<li><a href='../bo/index.php'>Retour au menu du BO</a></li>\n";
    echo "<li><a href='?action=check'>Vérifie les contraintes sur MapCat</a></li>\n";
    echo "<li><a href='?action=cmpGan'>Confronte les données de localisation de MapCat avec celles du GAN</a></li>\n";
    echo "<li><a href='?action=compareMapCats'>Compare le contenu de la table mapcat avec la version en Yaml</a></li>\n";
    echo "<li><a href='?action=createMapCatTable'>Crée la table mapcat et charge le catalogue</a></li>\n";
    echo "<li><a href='?action=showMapCatTable'>Affiche le contenu de la table mapcat</a></li>\n";
    echo "<li><a href='?action=updateMapCatTable'>Met à jour la table mapcat</a></li>\n";
    echo "<li><a href='?action=testValidatesAgainstSchema'>testValidatesAgainstSchema</a></li>\n";
    die();
  }
  case 'check': { // Vérifie les contraintes sur MapCat
    { // Vérifie qu'aucun no de carte apparait dans plusieurs sections
      $maps = [];
      foreach (MapCatItem::ALL_KINDS as $kind) {
        foreach (MapCatFromFile::mapNums([$kind]) as $mapNum) {
          $maps[$mapNum][$kind] = MapCatFromFile::get($mapNum, [$kind]);
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

    { // vérifie que toute carte current dont l'image principale n'est pas géoréférencée a des cartouches
      // cad que (scaleDenominator && spatial) || insetMaps toujours vrai
      $found = false;
      foreach (MapCatFromFile::mapNums() as $mapNum) {
        $mapCat = MapCatFromFile::get($mapNum);
        if (!$mapCat->insetMaps && (!$mapCat->scaleDenominator || !$mapCat->spatial)) {
          echo '<pre>',Yaml::dump([$mapNum => $mapCat]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Toute carte selected dont l'image principale n'est pas géoréférencée a des cartouches<br>\n";
    }

    { // Vérifie que Le mapsFrance de toute carte de maps est <> unknown
      $found = false;
      foreach (MapCatFromFile::mapNums() as $mapNum) {
        $mapCat = MapCatFromFile::get($mapNum);
        if ($mapCat->mapsFrance == 'unknown') {
          echo '<pre>',Yaml::dump([$mapNum => $mapCat]),"</pre>\n";
          $found = true;
        }
      }
      if (!$found)
        echo "Le mapsFrance de toute carte current est <> unknown<br>\n";
    }
    
    { // Vérifie les contraintes sur le champ spatial et que les exceptions sont bien indiquées
      $bad = false;
      foreach (MapCatFromFile::mapNums() as $mapNum) {
        //echo "mapNum=$mapNum<br>\n";
        $mapCat = MapCatFromFile::get($mapNum);
       // echo '<pre>',Yaml::dump([$mapNum => $mapCat->asArray()], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
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
  case 'cmpGan': { // Confronte les données de localisation de MapCat avec celles du GAN
    \dashboard\GanStatic::loadFromPser(); // charge les GANs sepuis le fichier gans.pser du dashboard
    //echo '<pre>gans='; print_r(Gan::$gans);
    cmpGans();
    break;
  }
  case 'compareMapCats': { // Compare le contenu de la table mapcat avec la version en Yaml
    $mapNumsFromFile = MapCatFromFile::mapNums();
    //echo Yaml::dump(['$mapNumsFromFile'=> $mapNumsFromFile]),"</p>";
    $mapNumsInBase = MapCat::mapNums();
    //echo Yaml::dump(['$mapNumsInBase'=> $mapNumsInBase]),"</p>";
    echo Yaml::dump(['mapNums du fichier qui ne sont pas en base'=> array_diff($mapNumsFromFile, $mapNumsInBase)]),"</p>";
    echo Yaml::dump(['mapNums en base qui ne sont pas dans le fichier'=> array_diff($mapNumsInBase, $mapNumsFromFile)]),"</p>";
    $diff = false;
    foreach ($mapNumsFromFile as $mapNum) {
      $mapCatFromFile = MapCatFromFile::get($mapNum);
      $mapCatInBase = MapCat::get($mapNum);
      if ($mapCatInBase <> $mapCatFromFile) {
        $diff = true;
        echo '<pre>',Yaml::dump(
          [ $mapNum => [
            'file'=> $mapCatFromFile->asArray(),
            'base'=> $mapCatInBase->asArray()]
          ], 6, 2),"</pre>\n";
       }
       if ($mapCatInBase <> $mapCatFromFile) {
         echo "<table border=1><tr><td>$mapNum</td><td>";
         $mapCatFromFile->diff('file', 'base', $mapCatInBase);
         echo "</td></tr></table>\n";
       }
    }
    if (!$diff)
      echo "Tous les enregistrements sont identiques dans le fichier et en base<br>\n";
    break;
  }
  case 'createMapCatTable': { // crée et peuple la table mapcat à partir du fichier mapcat.yaml
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    \MySql::query('drop table if exists mapcat');
    $query = MapCatDef::createTableSql('mapcat', MapCatDef::MAPCAT_TABLE_SCHEMA);
    //echo "<pre>query=$query</pre>\n";
    \MySql::query($query);

    //MySql::query('delete from mapcat');
    foreach (MapCatFromFile::mapNums() as $mapNum) {
      //echo "mapNum = $mapNum<br>\n";
      $mapCat = MapCatFromFile::get($mapNum);
      //$title = MySql::$mysqli->real_escape_string($mapCat->title);
      $jdoc = $mapCat->asArray();
      $jdoc = \MySql::$mysqli->real_escape_string(json_encode($jdoc));
      $query = "insert into mapcat(mapnum, jdoc, updatedt) "
        ."values('FR$mapNum', '$jdoc', now())";
      //echo "<pre>query=$query</pre>\n";
      \MySql::query($query);
    }
    break;
  }
  case 'showMapCatTable': { // affiche le contenu de la table mapcat
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    $mapCat = [];
    foreach (\MySql::query("select * from mapcat order by id") as $tuple) {
      $tuple['jdoc'] = json_decode($tuple['jdoc'], true);
      $mapCat[$tuple['mapnum']] = $tuple;
    }
    echo "<pre>\n";
    foreach ($mapCat as $mapNum => $mapCatEntry) {
      echo \bo\YamlDump([$mapCatEntry], 5);
    }
    echo "</pre>\n";
    break;
  }
  case 'updateMapCatTable': { // affiche les entrées de MapCat pour en sélectionner une pour mise à jour
    $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
      or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
    \MySql::open($LOG_MYSQL_URI);
    $mapCat = [];
    $sql = 'select id, mapnum, json_extract(jdoc, "$.title") title from mapcat order by id';
    foreach (\MySql::query($sql) as $tuple) {
      $mapCat[$tuple['mapnum']] = ['id'=> $tuple['id'], 'title'=> "$tuple[mapnum] - ".substr($tuple['title'], 1, -1)];
    }
    ksort($mapCat);
    foreach ($mapCat as $tuple) {
      echo "<a href='?action=updateMapCatId&amp;id=$tuple[id]&amp;return=mapcat'>$tuple[title]</a><br>\n";
    }
    break;
  }
  /*case 'updateMapCatId': { // affiche le formulaire de mise à jour d'une entrée de mapcat et effectue la mise à jour en base
    if (isset($_POST['yaml'])) { // Retour d'une saisie d'une description
      $yaml = $_POST['yaml'];
      $valid = MapCatDef::validatesAgainstSchema($yaml);
      if (!isset($valid['errors'])) { // description conforme, l'enregistrement est créé en base
        $LOG_MYSQL_URI = getenv('SHOMGT3_LOG_MYSQL_URI')
          or die("Erreur, variable d'environnement SHOMGT3_LOG_MYSQL_URI non définie");
        \MySql::open($LOG_MYSQL_URI);
        $jdocRes = \MySql::$mysqli->real_escape_string(json_encode($valid['validDoc']));
        $query = "insert into mapcat(mapnum, jdoc, updatedt, user) "
                            ."values('$_POST[mapnum]', '$jdocRes', now(), '$user')";
        echo "<pre>query=$query</pre>\n";
        \MySql::query($query);
        echo "maj carte $_POST[mapnum] ok<br>\n";
        switch ($return = $_POST['return'] ?? $_GET['return'] ?? null) {
          case 'mapcat': { echo "<a href='index.php'>Retour</a><br>\n"; break; }
          case 'addmaps': { echo "<a href='../bo/addmaps.php'>Retour</a><br>\n"; break; }
          default: die("valeur de return '$return' non prévue");
        }
        break;
      }
      else { // description non conforme
        echo "<b>Erreur, la description fournie n'est pas conforme au schéma JSON:</b>\n";
        echo '<pre>',Yaml::dump($valid),"</pre>";
        $mapcat = [
          'mapnum'=> $_POST['mapnum'],
          'yaml'=> $yaml,
        ];
      }
    }
    else { // Premier affichage du formulaire
      if (isset($_GET['id']))
        $mapcat = \MySql::getTuples("select mapnum, jdoc from mapcat where id=$_GET[id]")[0];
      elseif (isset($_GET['mapnum'])) {
        $mapcat = \MySql::getTuples("select mapnum, jdoc from mapcat where mapnum='FR$_GET[mapnum]' order by id desc")[0];
        if (!$mapcat)
          die("Erreur FR$_GET[mapnum] n'existe pas dans la table mapcat");
      }
      else
        die("Appel de ".__FILE__."incorrect");
      $mapcat['yaml'] = \bo\YamlDump(json_decode($mapcat['jdoc'], true), 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
    // Fabrique le formulaire à partir de la variable $mapcat
    echo "<b>Mise à jour de la description dans le catalogue MapCat de la carte $mapcat[mapnum]:</b></p>\n";
    $hiddenValues = [
      'action'=> 'updateMapCatId',
      'mapnum'=> $mapcat['mapnum'],
      'return'=> $_GET['return'] ?? 'mapcat'
    ];
    echo \bo\Html::textArea('yaml', $mapcat['yaml'], 18, 120, 'maj', $hiddenValues, '', 'post');
    $mapNumSsFr = substr($mapcat['mapnum'], 2);
    echo "</p><a href='https://gan.shom.fr/diffusion/qr/gan/$mapNumSsFr' target='_blank'>",
          "Affichage du GAN de cette carte</a><br>";
    echo "<a href='?action=showMapCatScheme' target='_blank'>",
          "Affichage du schéma JSON à respecter pour cette description</a><br>";
    switch ($return = $_POST['return'] ?? $_GET['return'] ?? null) {
      case 'mapcat': { echo "<a href='index.php'>Retour</a><br>\n"; break; }
      case 'addmaps': { echo "<a href='../bo/addmaps.php'>Retour</a><br>\n"; break; }
      default: die("valeur de return '$return' non prévue");
    }
    break;
  }*/
  /*case 'insertMapCat': {
    echo "<b>Ajout de la description dans le catalogue MapCat de la carte $_GET[mapnum] selon le modèle ci-dessous:</b></p>\n";
    $hiddenValues = [
      'action'=> 'updateMapCatId',
      'mapnum'=> "FR$_GET[mapnum]",
      'return'=> $_GET['return'] ?? 'mapcat'
    ];
    echo \bo\Html::textArea('yaml', MapCatDef::DOC_MODEL_IN_YAML, 18, 120, 'ajout', $hiddenValues, '', 'post');
    
    echo "</p><a href='https://gan.shom.fr/diffusion/qr/gan/$_GET[mapnum]' target='_blank'>",
          "Affichage du GAN de cette carte</a><br>";
    echo "<a href='?action=showMapCatScheme' target='_blank'>",
          "Affichage du schéma JSON à respecter pour cette description</a><br>";
    
    switch ($return = $_POST['return'] ?? $_GET['return'] ?? null) {
      case 'mapcat': { echo "<a href='index.php'>Retour</a><br>\n"; break; }
      case 'addmaps': { echo "<a href='../bo/addmaps.php'>Retour</a><br>\n"; break; }
      default: die("valeur de return '$return' non prévue");
    }
    break;
    
  }*/
  case 'testValidatesAgainstSchema': {
    MapCatDef::testValidatesAgainstSchema();
    echo "<a href='index.php'>Retour</a><br>\n";
    break;
  }
  default: {
    echo "action '$action' inconnue<br>\n";
    break;
  }
}
